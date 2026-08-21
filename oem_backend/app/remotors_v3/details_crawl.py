from __future__ import annotations

import fcntl
import hashlib
import json
import os
import signal
import sqlite3
import tempfile
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

import httpx
import psycopg
from psycopg.rows import dict_row

from app.config import get_settings
from app.remotors_v3.catalog_context import (
    catalog_dsn,
    default_sidecar_path,
    diagram_storage_root_name,
    html_storage_root_name,
    set_catalog_db,
)
from app.remotors_v3.client import (
    extract_diagram_url,
    fetch_details_html,
    is_placeholder_diagram_url,
    is_retryable_http_error,
    make_client,
)
from app.remotors_v3.constants import BULK_BATCH_SIZE, PROGRESS_INTERVAL_SEC
from app.remotors_v3.image_compress import compress_image_file
from app.remotors_v3.progress import ProgressReporter


_thread_local = threading.local()

# Docker Desktop now has ~48GiB. Cap is a safety net, not a throttle.
# Locks MUST live in container /tmp (flock on ./storage is tiny on Docker Desktop).
_IMAGE_SLOT_COUNT = 48
_IMAGE_MAX_BYTES = 20 * 1024 * 1024
_IMAGE_PNGQUANT_SPEED = 11


@contextmanager
def _image_work_slot() -> Iterator[None]:
    slot_dir = Path("/tmp/oem-image-crawl-slots")
    slot_dir.mkdir(parents=True, exist_ok=True)
    fd: int | None = None
    while fd is None:
        for index in range(_IMAGE_SLOT_COUNT):
            candidate = os.open(slot_dir / f"{index}.lock", os.O_CREAT | os.O_RDWR, 0o644)
            try:
                fcntl.flock(candidate, fcntl.LOCK_EX | fcntl.LOCK_NB)
                fd = candidate
                break
            except BlockingIOError:
                os.close(candidate)
        if fd is None:
            time.sleep(0.05)
    try:
        yield
    finally:
        try:
            fcntl.flock(fd, fcntl.LOCK_UN)
        except OSError:
            pass
        os.close(fd)


def _thread_http_client() -> Any:
    client = getattr(_thread_local, "client", None)
    if client is None:
        client = make_client()
        _thread_local.client = client
    return client


@contextmanager
def _pg_crawl_conn() -> Iterator[psycopg.Connection]:
    """One short-lived PG connection per operation (safe for many parallel workers)."""
    conn = psycopg.connect(catalog_dsn(), row_factory=dict_row, autocommit=False)
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
    return Path(sidecar or default_sidecar_path())


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
    """Claim next crawl rows.

    Never claim status=ok (infinite re-download loop with --force).
    Never claim status=error by default — permanent failures (no image / NoImage.gif)
    would otherwise starve pending rows because claim is ORDER BY assembly id.
    --force requeues errors once at crawl start; process_row force re-downloads files.
    """
    batch_size = max(1, int(batch_size))
    _ = force
    statuses = ("pending",)
    if phase == "html":
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


def seed_crawl_items(*, sidecar_path: str, db_code: str = "remotors") -> int:
    """Rebuild sidecar snapshot from PostgreSQL (single-process export, not used during crawl)."""
    set_catalog_db(db_code)
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
    return (
        Path(settings.asset_root).parent
        / html_storage_root_name()
        / root_arib
        / f"{assembly_id}.html"
    )


def _image_storage_path(root_arib: str, assembly_id: int, ext: str = ".png") -> Path:
    settings = get_settings()
    return (
        Path(settings.asset_root)
        / diagram_storage_root_name()
        / root_arib
        / str(assembly_id)
        / f"diagram{ext}"
    )


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


def _mark_image_ok(
    assembly_id: int,
    rel_path: str,
    original_url: str,
    content: bytes,
    *,
    mime_type: str = "image/png",
) -> None:
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
                (assembly_id, original_url, rel_path, public_url, checksum, mime_type),
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


def _mark_image_skipped(assembly_id: int, reason: str, *, image_url: str | None = None) -> None:
    """No real diagram (missing URL / PartStream NoImage.*) — done, do not retry."""
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  image_url = COALESCE(%s, image_url),
                  image_path = NULL,
                  image_status = 'ok',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (image_url, reason[:2000], assembly_id),
            )
        conn.commit()


def _reset_errors_to_pending(phase: str) -> int:
    col = "html_status" if phase == "html" else "image_status"
    with _pg_crawl_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                UPDATE oem_details_pages
                SET {col} = 'pending', updated_at = now()
                WHERE {col} = 'error'
                """
            )
            reset = cur.rowcount
    return int(reset or 0)


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
        image_url = extract_diagram_url(html)
        _mark_html_ok(assembly_id, str(html_path), html_hash, image_url)
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
    if not force and row["image_status"] == "ok":
        # Real file on disk, or intentional skip (no diagram / NoImage placeholder).
        if row["image_path"] and (Path(get_settings().asset_root) / row["image_path"]).is_file():
            with stats_lock:
                stats["skipped"] += 1
            progress.advance(f"skip image exists assembly={assembly_id}")
            return
        if not row["image_path"]:
            with stats_lock:
                stats["skipped"] += 1
            progress.advance(f"skip no-image assembly={assembly_id}")
            return
    image_url = row.get("image_url")
    html_path = row.get("html_path")
    if not image_url and html_path and Path(html_path).is_file():
        image_url = extract_diagram_url(Path(html_path).read_text(encoding="utf-8"))
    if not image_url:
        _mark_image_skipped(assembly_id, "no image url")
        with stats_lock:
            stats["skipped"] += 1
        progress.advance(f"skip no image url assembly={assembly_id}")
        return
    if is_placeholder_diagram_url(image_url):
        _mark_image_skipped(assembly_id, "no-image placeholder", image_url=image_url)
        with stats_lock:
            stats["skipped"] += 1
        progress.advance(f"skip no-image placeholder assembly={assembly_id}")
        return
    try:
        with _image_work_slot():
            client = _thread_http_client()
            with tempfile.TemporaryDirectory(prefix="oem-dl-") as tmpdir:
                raw_path = Path(tmpdir) / "src.bin"
                with client.stream("GET", image_url, headers={"User-Agent": "MotorForceOEMBot/0.1"}) as response:
                    response.raise_for_status()
                    final_url = str(response.url) if response.url else image_url
                    if is_placeholder_diagram_url(final_url):
                        _mark_image_skipped(assembly_id, "no-image placeholder", image_url=final_url)
                        with stats_lock:
                            stats["skipped"] += 1
                        progress.advance(f"skip no-image placeholder assembly={assembly_id}")
                        return
                    written = 0
                    ctype = (response.headers.get("content-type") or "").lower()
                    with raw_path.open("wb") as fh:
                        for chunk in response.iter_bytes(64 * 1024):
                            written += len(chunk)
                            if written > _IMAGE_MAX_BYTES:
                                raise RuntimeError(f"image too large ({written} bytes)")
                            fh.write(chunk)
                if written <= 0:
                    raise RuntimeError("empty image body")
                ext = ".png" if "png" in ctype or not ctype else ".jpg"
                image_path = _image_storage_path(root_arib, assembly_id, ext)
                compressed = compress_image_file(
                    raw_path,
                    image_path,
                    ext=ext,
                    pngquant_speed=_IMAGE_PNGQUANT_SPEED,
                )
            rel_path = str(image_path.relative_to(Path(get_settings().asset_root)))
            mime_type = "image/png" if image_path.suffix.lower() == ".png" else "image/jpeg"
            _mark_image_ok(assembly_id, rel_path, image_url, compressed, mime_type=mime_type)
        with stats_lock:
            stats["ok"] += 1
            progress.advance(f"image ok assembly={assembly_id}")
    except Exception as exc:
        err = " ".join(str(exc).split())
        status = getattr(getattr(exc, "response", None), "status_code", None)
        # 404 on NoImage.gif/Max (and similar) is not a real failure — do not retry.
        if is_placeholder_diagram_url(image_url) or "noimage" in err.lower():
            _mark_image_skipped(assembly_id, "no-image placeholder", image_url=image_url)
            with stats_lock:
                stats["skipped"] += 1
            progress.advance(f"skip no-image placeholder assembly={assembly_id}")
            return
        if isinstance(exc, httpx.HTTPStatusError) and status in {400, 403, 404}:
            _mark_image_skipped(assembly_id, f"cdn http {status}", image_url=image_url)
            with stats_lock:
                stats["skipped"] += 1
            progress.advance(f"skip cdn http {status} assembly={assembly_id}")
            return
        if is_retryable_http_error(exc):
            _reset_image_pending(assembly_id, err)
            progress.advance(f"RETRY image assembly={assembly_id}: {err}")
        else:
            with stats_lock:
                stats["errors"] += 1
            _mark_image_error(assembly_id, err)
            progress.advance(f"ERROR image assembly={assembly_id}: {err}")


def crawl_details(
    *,
    phase: str,
    sidecar_path: str,
    limit: int | None = None,
    force: bool = False,
    worker_id: int = 0,
    workers: int = 1,
    concurrency: int = 1,
    db_code: str = "remotors",
) -> dict[str, Any]:
    if phase not in {"html", "images"}:
        raise ValueError("phase must be html or images")
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    concurrency = max(1, int(concurrency))
    set_catalog_db(db_code)
    try:
        signal.signal(signal.SIGHUP, signal.SIG_IGN)
    except (ValueError, OSError):
        pass

    _ensure_pg_crawl_schema()
    if worker_id == 0:
        reset = _reset_stale_running(phase)
        if reset:
            print(f"[crawl-{phase}] reset stale running={reset}", flush=True)
        if force:
            requeued = _reset_errors_to_pending(phase)
            if requeued:
                print(f"[crawl-{phase}] --force requeued errors→pending={requeued}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = f"crawl-{phase}" if workers == 1 else f"crawl-{phase}-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"
    log_path = Path(get_settings().asset_root).parent / f"{db_code}-crawl-{phase}-w{worker_id}.log"
    progress = ProgressReporter(total=0, label=worker_label, log_path=log_path)
    progress.enable_thread_safe()
    print(f"[crawl-{phase}] log={log_path}", flush=True)
    stats = {"ok": 0, "errors": 0, "skipped": 0}
    stats_lock = threading.Lock()
    processed = 0
    process_row = _process_html_row if phase == "html" else _process_image_row
    stop_hb = threading.Event()

    def _heartbeat() -> None:
        while not stop_hb.wait(PROGRESS_INTERVAL_SEC):
            with stats_lock:
                progress.tick(
                    f"alive ok={stats['ok']} errors={stats['errors']} skipped={stats['skipped']}",
                    force=True,
                )

    hb = threading.Thread(target=_heartbeat, name=f"{worker_label}-hb", daemon=True)
    hb.start()

    try:
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
                    try:
                        future.result()
                    except Exception as exc:
                        err = " ".join(str(exc).split())
                        print(f"[{worker_label}] task failed: {err}", flush=True)
    finally:
        stop_hb.set()

    progress.finish(f"crawl-{phase} stats={stats}")
    return stats
