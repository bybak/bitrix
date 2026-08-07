from __future__ import annotations

import hashlib
import json
import threading
import time
import urllib.error
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from psycopg_pool import PoolTimeout

from app.config import get_settings
from app.db import get_yamaha_conn
from app.yamaha_v1.client import catalog_text, fetch_bytes
from app.yamaha_v1.constants import ILLUST_SUBDIR, JSON_STORAGE_ROOT, PROGRESS_INTERVAL_SEC
from app.yamaha_v1.context import build_catalog_text_payload
from app.yamaha_v1.parse_responses import png_dimensions
from app.yamaha_v1.progress import ProgressReporter


def _json_path(root_arib: str, assembly_id: int) -> Path:
    return Path(JSON_STORAGE_ROOT) / root_arib / f"{assembly_id}.json"


def _image_path(root_arib: str, assembly_id: int) -> Path:
    return Path(get_settings().asset_root) / ILLUST_SUBDIR / root_arib / str(assembly_id) / "diagram.png"


def _public_url(local_path: Path) -> str:
    settings = get_settings()
    asset_root = Path(settings.asset_root)
    try:
        rel = local_path.resolve().relative_to(asset_root.resolve())
    except ValueError:
        rel = local_path
    return f"{settings.public_asset_base_url.rstrip('/')}/{rel.as_posix()}"


def _is_pool_timeout(exc: BaseException) -> bool:
    if isinstance(exc, PoolTimeout):
        return True
    msg = str(exc).lower()
    return "couldn't get a connection" in msg or "pool timeout" in msg


def _is_transient_image_error(exc: BaseException) -> bool:
    if isinstance(exc, urllib.error.HTTPError):
        return exc.code in {429, 500, 502, 503, 504}
    msg = str(exc).lower()
    return any(
        token in msg
        for token in (
            "timed out",
            "timeout",
            "connection refused",
            "connection reset",
            "unexpected eof",
            "temporarily unavailable",
            "network error",
        )
    )


def _pending_statuses(*, force: bool) -> tuple[list[str], list[str]]:
    if force:
        statuses = ["pending", "error", "running", "ok"]
        return statuses, statuses
    # Не перекачиваем image_status=error: 403 и прочие постоянные сбои иначе крутятся бесконечно.
    return ["pending", "running", "error"], ["pending", "running"]


def _count_pending(*, force: bool) -> int:
    html_statuses, image_statuses = _pending_statuses(force=force)
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS c
                FROM oem_details_pages dp
                JOIN oem_assemblies a ON a.id = dp.assembly_id
                WHERE dp.html_status = ANY(%s)
                   OR (dp.html_status = 'ok' AND dp.image_status = ANY(%s))
                """,
                (html_statuses, image_statuses),
            )
            return int(cur.fetchone()["c"])


def _reset_stale_running() -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages dp
                SET html_status = 'pending', image_status = 'pending', updated_at = now()
                WHERE dp.html_status = 'running' OR dp.image_status = 'running'
                """
            )
            reset = cur.rowcount
        conn.commit()
    return int(reset or 0)


def _claim_batch(*, force: bool, batch_size: int) -> list[dict[str, Any]]:
    html_statuses, image_statuses = _pending_statuses(force=force)
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  dp.id AS details_page_id,
                  dp.assembly_id,
                  a.root_arib,
                  a.assembly_key,
                  a.source_payload,
                  dp.html_path,
                  dp.html_status,
                  dp.image_path,
                  dp.image_status
                FROM oem_details_pages dp
                JOIN oem_assemblies a ON a.id = dp.assembly_id
                WHERE dp.html_status = ANY(%s)
                   OR (dp.html_status = 'ok' AND dp.image_status = ANY(%s))
                ORDER BY a.id
                LIMIT %s
                FOR UPDATE OF dp SKIP LOCKED
                """,
                (html_statuses, image_statuses, batch_size),
            )
            rows = cur.fetchall()
            if not rows:
                conn.commit()
                return []
            for row in rows:
                cur.execute(
                    """
                    UPDATE oem_details_pages
                    SET html_status = 'running', image_status = 'running', updated_at = now()
                    WHERE id = %s
                    """,
                    (row["details_page_id"],),
                )
        conn.commit()
        return [dict(row) for row in rows]


def _mark_crawl_skipped(assembly_id: int) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'ok',
                    image_status = 'ok',
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (assembly_id,),
            )
        conn.commit()


def _reset_crawl_pending(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'pending',
                    image_status = 'pending',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _download_image_for_row(
    *,
    assembly_id: int,
    root_arib: str,
    fig: dict[str, Any],
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> None:
    image_path = _image_path(root_arib, assembly_id)
    illust_url = fig.get("illustFileURL")
    if not illust_url:
        _mark_image_skipped(assembly_id)
        progress.advance(f"json ok assembly={assembly_id} (no image url)")
        return

    try:
        image_bytes = fetch_bytes(str(illust_url))
        image_path.parent.mkdir(parents=True, exist_ok=True)
        image_path.write_bytes(image_bytes)
        width, height = png_dimensions(image_bytes)
        checksum = hashlib.sha256(image_bytes).hexdigest()
        _mark_image_ok(
            assembly_id=assembly_id,
            image_path=image_path,
            image_url=f"https:{illust_url}" if str(illust_url).startswith("//") else str(illust_url),
            checksum=checksum,
            width=width,
            height=height,
        )
        with stats_lock:
            stats["image_ok"] += 1
        progress.advance(f"ok assembly={assembly_id}")
    except Exception as exc:
        if _is_pool_timeout(exc):
            _reset_crawl_pending(assembly_id, str(exc))
            progress.advance(f"RETRY crawl assembly={assembly_id}")
            return
        if _is_transient_image_error(exc):
            _reset_image_pending(assembly_id, str(exc))
            progress.advance(f"RETRY image assembly={assembly_id}")
            return
        with stats_lock:
            stats["image_error"] += 1
        _mark_image_error(assembly_id, str(exc))
        progress.advance(f"ERROR image assembly={assembly_id}")


def _process_crawl_row(
    row: dict[str, Any],
    *,
    force: bool,
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> None:
    assembly_id = int(row["assembly_id"])
    root_arib = row["root_arib"]
    payload = row["source_payload"] or {}
    json_path = _json_path(root_arib, assembly_id)
    image_path = _image_path(root_arib, assembly_id)

    if (
        not force
        and row["html_status"] == "ok"
        and row["image_status"] == "ok"
        and json_path.is_file()
        and image_path.is_file()
    ):
        with stats_lock:
            stats["skipped"] += 1
        _mark_crawl_skipped(assembly_id)
        progress.advance(f"skip assembly={assembly_id}")
        return

    fig = (payload.get("fig") or {}) if isinstance(payload, dict) else {}

    if (
        not force
        and row["html_status"] == "ok"
        and json_path.is_file()
        and row["image_status"] != "ok"
    ):
        _download_image_for_row(
            assembly_id=assembly_id,
            root_arib=root_arib,
            fig=fig,
            progress=progress,
            stats=stats,
            stats_lock=stats_lock,
        )
        return

    catalog_no = payload.get("catalog_no") if isinstance(payload, dict) else None
    catalog_index_payload = payload.get("catalog_index_payload") if isinstance(payload, dict) else None
    user_context = payload.get("user_context") if isinstance(payload, dict) else {}

    if not (fig and catalog_no and catalog_index_payload):
        with stats_lock:
            stats["json_error"] += 1
        _mark_error(assembly_id, "missing source_payload for catalog crawl")
        progress.advance(f"ERROR assembly={assembly_id} missing payload")
        return

    text_payload = payload.get("catalog_text_payload") if isinstance(payload, dict) else None
    if not text_payload:
        text_payload = build_catalog_text_payload(
            catalog_index_payload=catalog_index_payload,
            user_context=user_context,
            catalog_no=str(catalog_no),
            fig=fig,
        )

    try:
        catalog_text_payload = catalog_text(payload=text_payload)
        json_path.parent.mkdir(parents=True, exist_ok=True)
        blob = {
            "fetched_at": datetime.now(timezone.utc).isoformat(),
            "request": text_payload,
            "response": catalog_text_payload,
        }
        json_path.write_text(json.dumps(blob, ensure_ascii=False, indent=2), encoding="utf-8")
        digest = hashlib.sha256(json_path.read_bytes()).hexdigest()
        _mark_json_ok(
            assembly_id=assembly_id,
            json_path=json_path,
            digest=digest,
            source_payload={**payload, "catalog_text_payload": text_payload, "catalog_text_response": catalog_text_payload},
        )
        with stats_lock:
            stats["json_ok"] += 1
    except Exception as exc:
        if _is_pool_timeout(exc):
            _reset_crawl_pending(assembly_id, str(exc))
            progress.advance(f"RETRY crawl assembly={assembly_id}")
            return
        with stats_lock:
            stats["json_error"] += 1
        _mark_error(assembly_id, f"catalog_text failed: {exc}")
        progress.advance(f"ERROR json assembly={assembly_id}")
        return

    _download_image_for_row(
        assembly_id=assembly_id,
        root_arib=root_arib,
        fig=fig,
        progress=progress,
        stats=stats,
        stats_lock=stats_lock,
    )


def crawl_details(
    *,
    limit: int | None = None,
    force: bool = False,
    concurrency: int = 8,
    worker_id: int = 0,
    workers: int = 1,
) -> dict[str, Any]:
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    concurrency = max(1, int(concurrency))

    if worker_id == 0:
        reset = _reset_stale_running()
        if reset:
            print(f"[yamaha-crawl] reset stale running={reset}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = "yamaha-crawl" if workers == 1 else f"yamaha-crawl-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"

    pending = _count_pending(force=force)
    progress = ProgressReporter(total=pending if not limit else min(pending, limit), label=worker_label)
    progress.enable_thread_safe()
    stats = {"json_ok": 0, "json_error": 0, "image_ok": 0, "image_error": 0, "skipped": 0}
    stats_lock = threading.Lock()
    processed = 0
    last_tick = time.monotonic()

    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        while True:
            if limit is not None and processed >= limit:
                break
            claim_size = concurrency if limit is None else min(concurrency, limit - processed)
            rows = _claim_batch(force=force, batch_size=claim_size)
            if not rows:
                break
            processed += len(rows)
            futures = [
                executor.submit(
                    _process_crawl_row,
                    row,
                    force=force,
                    progress=progress,
                    stats=stats,
                    stats_lock=stats_lock,
                )
                for row in rows
            ]
            for future in futures:
                future.result()

            if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
                with stats_lock:
                    progress.tick(
                        f"json_ok={stats['json_ok']} image_ok={stats['image_ok']} "
                        f"errors={stats['json_error'] + stats['image_error']}"
                    )
                last_tick = time.monotonic()

    progress.finish(f"crawl stats={stats}")
    return {"processed": processed, "stats": stats, "worker_id": worker_id, "workers": workers, "concurrency": concurrency}


def _mark_json_ok(*, assembly_id: int, json_path: Path, digest: str, source_payload: dict[str, Any]) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_path = %s,
                    html_hash = %s,
                    html_status = 'ok',
                    fetched_at = now(),
                    updated_at = now(),
                    error_message = NULL
                WHERE assembly_id = %s
                """,
                (str(json_path), digest, assembly_id),
            )
            cur.execute(
                """
                UPDATE oem_assemblies
                SET source_payload = %s::jsonb,
                    updated_at = now()
                WHERE id = %s
                """,
                (json.dumps(source_payload, ensure_ascii=False), assembly_id),
            )
        conn.commit()


def _mark_image_skipped(assembly_id: int) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET image_status = 'ok',
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (assembly_id,),
            )
        conn.commit()


def _mark_image_ok(
    *,
    assembly_id: int,
    image_path: Path,
    image_url: str,
    checksum: str,
    width: int | None,
    height: int | None,
) -> None:
    public_url = _public_url(image_path)
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO oem_diagrams(
                  assembly_id, original_url, local_path, public_url, width, height,
                  coord_width, coord_height, mime_type, checksum_sha256
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (assembly_id) DO UPDATE SET
                  original_url = EXCLUDED.original_url,
                  local_path = EXCLUDED.local_path,
                  public_url = EXCLUDED.public_url,
                  width = EXCLUDED.width,
                  height = EXCLUDED.height,
                  coord_width = EXCLUDED.coord_width,
                  coord_height = EXCLUDED.coord_height,
                  mime_type = EXCLUDED.mime_type,
                  checksum_sha256 = EXCLUDED.checksum_sha256,
                  updated_at = now()
                """,
                (
                    assembly_id,
                    image_url,
                    str(image_path),
                    public_url,
                    width,
                    height,
                    width,
                    height,
                    "image/png",
                    checksum,
                ),
            )
            cur.execute(
                """
                UPDATE oem_details_pages
                SET image_path = %s,
                    image_url = %s,
                    image_status = 'ok',
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (str(image_path), image_url, assembly_id),
            )
        conn.commit()


def _mark_error(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'error',
                    image_status = 'error',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _reset_image_pending(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET image_status = 'pending',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _mark_image_error(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET image_status = 'error',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()
