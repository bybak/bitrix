from __future__ import annotations

import hashlib
import json
import sqlite3
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

import psycopg
from psycopg.rows import dict_row

from app.config import get_settings
from app.remotors_v3.client import extract_diagram_url, fetch_details_html, is_retryable_http_error, make_client
from app.remotors_v3.constants import BULK_BATCH_SIZE, PROGRESS_INTERVAL_SEC
from app.remotors_v3.progress import ProgressReporter


_thread_local = threading.local()


def _thread_http_client() -> Any:
    client = getattr(_thread_local, "client", None)
    if client is None:
        client = make_client()
        _thread_local.client = client
    return client


@contextmanager
def _pg_crawl_conn() -> Iterator[psycopg.Connection]:
    """One short-lived PG connection per operation (safe for many parallel workers)."""
    conn = psycopg.connect(get_settings().database_dsn, row_factory=dict_row, autocommit=False)
    try:
        with conn.cursor() as cur:
            cur.execute("SET max_parallel_workers_per_gather TO 0")
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def _sidecar_path(sidecar: str | None) -> Path:
    return Path(sidecar or "storage/remotors-details-crawl.db")


def _connect_sidecar(path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    return conn


def _ensure_pg_crawl_schema() -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute("ALTER TABLE oem_details_pages ADD COLUMN IF NOT EXISTS image_url TEXT")


def _reset_stale_running(phase: str) -> int:
    col = "html_status" if phase == "html" else "image_status"
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE oem_details_pages
                SET {col} = 'pending', updated_at = now()
                WHERE {col} = 'running'
                """
            )
            reset = cur.rowcount
    return int(reset or 0)


def _claim_batch_pg(*, phase: str, force: bool, batch_size: int = 1) -> list[dict[str, Any]]:
    batch_size = max(1, int(batch_size))
    if phase == "html":
        statuses = ("pending", "error", "running", "ok") if force else ("pending", "running")
        select_sql = """
            SELECT
              dp.id AS details_page_id,
              dp.assembly_id,
              a.root_arib,
              a.slug,
              a.assembly_key,
              dp.html_path,
              dp.html_status,
              dp.html_hash,
              dp.image_path,
              dp.image_status,
              dp.image_url
            FROM oem_details_pages dp
            JOIN oem_assemblies a ON a.id = dp.assembly_id
            WHERE dp.html_status = ANY(%s)
              AND a.slug IS NOT NULL AND a.slug <> ''
            ORDER BY a.id
            LIMIT %s
            FOR UPDATE OF dp SKIP LOCKED
        """
        running_value = "running"
        status_col = "html_status"
    else:
        statuses = ("pending", "error", "running", "ok") if force else ("pending", "running")
        select_sql = """
            SELECT
              dp.id AS details_page_id,
              dp.assembly_id,
              a.root_arib,
              a.slug,
              a.assembly_key,
              dp.html_path,
              dp.html_status,
              dp.html_hash,
              dp.image_path,
              dp.image_status,
              dp.image_url
            FROM oem_details_pages dp
            JOIN oem_assemblies a ON a.id = dp.assembly_id
            WHERE dp.image_status = ANY(%s)
              AND dp.html_status = 'ok'
              AND a.slug IS NOT NULL AND a.slug <> ''
            ORDER BY a.id
            LIMIT %s
            FOR UPDATE OF dp SKIP LOCKED
        """
        running_value = "running"
        status_col = "image_status"

    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(select_sql, (list(statuses), batch_size))
            rows = cur.fetchall()
            if not rows:
                return []
            for row in rows:
                cur.execute(
                    f"""
                    UPDATE oem_details_pages
                    SET {status_col} = %s, updated_at = now()
                    WHERE id = %s
                    """,
                    (running_value, row["details_page_id"]),
                )
        return [dict(row) for row in rows]


def seed_crawl_items(*, sidecar_path: str) -> int:
    """Rebuild sidecar snapshot from PostgreSQL (single-process export, not used during crawl)."""
    path = _sidecar_path(sidecar_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        path.unlink()
    conn = _connect_sidecar(path)
    try:
        conn.executescript(
            """
            CREATE TABLE crawl_items (
              assembly_id INTEGER PRIMARY KEY,
              variant_key TEXT NOT NULL,
              assembly_key TEXT NOT NULL,
              root_arib TEXT NOT NULL,
              slug TEXT NOT NULL,
              title TEXT,
              path_json TEXT,
              html_status TEXT NOT NULL DEFAULT 'pending',
              html_path TEXT,
              html_hash TEXT,
              image_status TEXT NOT NULL DEFAULT 'pending',
              image_path TEXT,
              image_url TEXT,
              parse_status TEXT NOT NULL DEFAULT 'pending',
              error_message TEXT,
              updated_at TEXT NOT NULL
            );
            """
        )
        with _pg_crawl_conn() as pg:
            with pg.cursor() as cur:
                cur.execute(
                    """
                    SELECT
                      a.id AS assembly_id,
                      v.variant_key,
                      a.assembly_key,
                      a.root_arib,
                      a.slug,
                      a.title,
                      a.path_json,
                      dp.html_status,
                      dp.html_path,
                      dp.html_hash,
                      dp.image_status,
                      dp.image_path,
                      dp.image_url,
                      dp.parse_status,
                      dp.error_message
                    FROM oem_assemblies a
                    JOIN oem_variants v ON v.id = a.variant_id
                    JOIN oem_details_pages dp ON dp.assembly_id = a.id
                    WHERE a.slug IS NOT NULL AND a.slug <> ''
                    ORDER BY a.id
                    """
                )
                rows = cur.fetchall()

        now = datetime.now(timezone.utc).isoformat()
        batch: list[tuple[Any, ...]] = []
        for row in rows:
            batch.append(
                (
                    int(row["assembly_id"]),
                    row["variant_key"],
                    row["assembly_key"],
                    row["root_arib"],
                    row["slug"],
                    row["title"],
                    json.dumps(row["path_json"], ensure_ascii=False),
                    row["html_status"],
                    row["html_path"],
                    row["html_hash"],
                    row["image_status"],
                    row["image_path"],
                    row["image_url"],
                    row["parse_status"],
                    row["error_message"],
                    now,
                )
            )
            if len(batch) >= BULK_BATCH_SIZE:
                conn.executemany(
                    """
                    INSERT INTO crawl_items(
                      assembly_id, variant_key, assembly_key, root_arib, slug, title, path_json,
                      html_status, html_path, html_hash, image_status, image_path, image_url,
                      parse_status, error_message, updated_at
                    ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    batch,
                )
                conn.commit()
                batch.clear()
        if batch:
            conn.executemany(
                """
                INSERT INTO crawl_items(
                  assembly_id, variant_key, assembly_key, root_arib, slug, title, path_json,
                  html_status, html_path, html_hash, image_status, image_path, image_url,
                  parse_status, error_message, updated_at
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                batch,
            )
            conn.commit()
        count = conn.execute("SELECT COUNT(*) FROM crawl_items").fetchone()[0]
        return int(count)
    finally:
        conn.close()


def _html_storage_path(root_arib: str, assembly_id: int) -> Path:
    settings = get_settings()
    return Path(settings.asset_root).parent / "remotors-html" / root_arib / f"{assembly_id}.html"


def _image_storage_path(root_arib: str, assembly_id: int, ext: str = ".png") -> Path:
    settings = get_settings()
    return Path(settings.asset_root) / "remotors" / root_arib / str(assembly_id) / f"diagram{ext}"


def _mark_html_ok(assembly_id: int, html_path: str, html_hash: str, image_url: str | None) -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  html_path = %s,
                  html_hash = %s,
                  html_status = 'ok',
                  image_url = %s,
                  error_message = NULL,
                  fetched_at = now(),
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (html_path, html_hash, image_url, assembly_id),
            )
        conn.commit()


def _mark_html_error(assembly_id: int, error: str) -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  html_status = 'error',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _reset_html_pending(assembly_id: int, error: str) -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  html_status = 'pending',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _mark_image_ok(assembly_id: int, rel_path: str, original_url: str, content: bytes) -> None:
    checksum = hashlib.sha256(content).hexdigest()
    public_url = f"{get_settings().public_asset_base_url.rstrip('/')}/{rel_path}"
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO oem_diagrams(assembly_id, original_url, local_path, public_url, checksum_sha256, mime_type)
                VALUES (%s, %s, %s, %s, %s, %s)
                ON CONFLICT (assembly_id) DO UPDATE SET
                  original_url = EXCLUDED.original_url,
                  local_path = EXCLUDED.local_path,
                  public_url = EXCLUDED.public_url,
                  checksum_sha256 = EXCLUDED.checksum_sha256,
                  mime_type = EXCLUDED.mime_type,
                  updated_at = now()
                """,
                (assembly_id, original_url, rel_path, public_url, checksum, "image/png"),
            )
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  image_path = %s,
                  image_status = 'ok',
                  error_message = NULL,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (rel_path, assembly_id),
            )
        conn.commit()


def _mark_image_error(assembly_id: int, error: str) -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  image_status = 'error',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _reset_image_pending(assembly_id: int, error: str) -> None:
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  image_status = 'pending',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _process_html_row(row: dict[str, Any], *, force: bool, progress: ProgressReporter, stats: dict[str, int], stats_lock: threading.Lock) -> None:
    assembly_id = int(row["assembly_id"])
    root_arib = row["root_arib"]
    slug = row["slug"]
    if (
        not force
        and row["html_status"] == "ok"
        and row["html_path"]
        and Path(row["html_path"]).is_file()
    ):
        with stats_lock:
            stats["skipped"] += 1
        _mark_html_ok(assembly_id, row["html_path"], row.get("html_hash") or "", row.get("image_url"))
        progress.advance(f"skip html exists assembly={assembly_id}")
        return
    try:
        html = fetch_details_html(_thread_http_client(), slug)
        html_path = _html_storage_path(root_arib, assembly_id)
        html_path.parent.mkdir(parents=True, exist_ok=True)
        html_path.write_text(html, encoding="utf-8")
        html_hash = hashlib.sha256(html.encode("utf-8")).hexdigest()
        _mark_html_ok(assembly_id, str(html_path), html_hash, None)
        with stats_lock:
            stats["ok"] += 1
        progress.advance(f"html ok assembly={assembly_id}")
    except Exception as exc:
        if is_retryable_http_error(exc):
            _reset_html_pending(assembly_id, str(exc))
            progress.advance(f"RETRY html assembly={assembly_id}: {exc}")
        else:
            with stats_lock:
                stats["errors"] += 1
            _mark_html_error(assembly_id, str(exc))
            progress.advance(f"ERROR html assembly={assembly_id}: {exc}")


def _process_image_row(row: dict[str, Any], *, force: bool, progress: ProgressReporter, stats: dict[str, int], stats_lock: threading.Lock) -> None:
    assembly_id = int(row["assembly_id"])
    root_arib = row["root_arib"]
    if (
        not force
        and row["image_status"] == "ok"
        and row["image_path"]
        and (Path(get_settings().asset_root) / row["image_path"]).is_file()
    ):
        with stats_lock:
            stats["skipped"] += 1
        progress.advance(f"skip image exists assembly={assembly_id}")
        return
    image_url = row.get("image_url")
    html_path = row.get("html_path")
    if not image_url and html_path and Path(html_path).is_file():
        image_url = extract_diagram_url(Path(html_path).read_text(encoding="utf-8"))
    if not image_url:
        with stats_lock:
            stats["errors"] += 1
        _mark_image_error(assembly_id, "no image url")
        progress.advance(f"ERROR no image url assembly={assembly_id}")
        return
    try:
        client = _thread_http_client()
        response = client.get(image_url, headers={"User-Agent": "MotorForceOEMBot/0.1"})
        response.raise_for_status()
        content = response.content
        ext = ".png" if "png" in (response.headers.get("content-type") or "") else ".jpg"
        image_path = _image_storage_path(root_arib, assembly_id, ext)
        image_path.parent.mkdir(parents=True, exist_ok=True)
        image_path.write_bytes(content)
        rel_path = str(image_path.relative_to(Path(get_settings().asset_root)))
        _mark_image_ok(assembly_id, rel_path, image_url, content)
        with stats_lock:
            stats["ok"] += 1
        progress.advance(f"image ok assembly={assembly_id}")
    except Exception as exc:
        if is_retryable_http_error(exc):
            _reset_image_pending(assembly_id, str(exc))
            progress.advance(f"RETRY image assembly={assembly_id}: {exc}")
        else:
            with stats_lock:
                stats["errors"] += 1
            _mark_image_error(assembly_id, str(exc))
            progress.advance(f"ERROR image assembly={assembly_id}: {exc}")


def crawl_details(
    *,
    phase: str,
    sidecar_path: str,
    limit: int | None = None,
    force: bool = False,
    worker_id: int = 0,
    workers: int = 1,
    concurrency: int = 1,
) -> dict[str, Any]:
    if phase not in {"html", "images"}:
        raise ValueError("phase must be html or images")
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    concurrency = max(1, int(concurrency))

    _ensure_pg_crawl_schema()
    if worker_id == 0:
        reset = _reset_stale_running(phase)
        if reset:
            print(f"[crawl-{phase}] reset stale running={reset}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = f"crawl-{phase}" if workers == 1 else f"crawl-{phase}-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"
    progress = ProgressReporter(total=0, label=worker_label)
    progress.enable_thread_safe()
    stats = {"ok": 0, "errors": 0, "skipped": 0}
    stats_lock = threading.Lock()
    last_tick = time.monotonic()
    processed = 0
    process_row = _process_html_row if phase == "html" else _process_image_row

    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        while True:
            if limit and processed >= limit:
                break
            batch_size = concurrency if not limit else min(concurrency, limit - processed)
            rows = _claim_batch_pg(phase=phase, force=force, batch_size=batch_size)
            if not rows:
                break
            processed += len(rows)
            futures = [
                executor.submit(process_row, row, force=force, progress=progress, stats=stats, stats_lock=stats_lock)
                for row in rows
            ]
            for future in futures:
                future.result()

            if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
                with stats_lock:
                    progress.tick(f"ok={stats['ok']} errors={stats['errors']}")
                last_tick = time.monotonic()

    progress.finish(f"crawl-{phase} stats={stats}")
    return stats
