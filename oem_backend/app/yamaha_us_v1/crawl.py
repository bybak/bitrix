from __future__ import annotations

import hashlib
import json
import threading
import time
import urllib.error
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Literal

from psycopg_pool import PoolTimeout

from app.config import get_settings
from app.db import get_yamaha_conn
from app.yamaha_v1.parse_responses import png_dimensions
from app.yamaha_v1.progress import ProgressReporter

from .client import YamahaUsApiError, configure_diagram_api_concurrency, fetch_diagram, fetch_image_png_full
from .constants import ILLUST_SUBDIR, JSON_STORAGE_ROOT, MAX_IMAGE_CRAWL_CONCURRENCY, ROOT_ARIB

CrawlPhase = Literal["json", "images", "both"]
ClaimOrder = Literal["asc", "desc", "random"]

JSON_CRAWL_CLAIM_BATCH_MAX = 500


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


def _is_transient_api_error(exc: BaseException) -> bool:
    if isinstance(exc, YamahaUsApiError):
        return exc.status in {429, 500, 502, 503, 504}
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
            "temporary failure in name resolution",
            "network error",
        )
    )


def _is_transient_json_error(exc: BaseException) -> bool:
    if isinstance(exc, YamahaUsApiError):
        return exc.status in {403, 429, 500, 502, 503, 504}
    if isinstance(exc, urllib.error.HTTPError):
        return exc.code in {403, 429, 500, 502, 503, 504}
    return _is_transient_network_error(exc)


def _is_transient_network_error(exc: BaseException) -> bool:
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
            "temporary failure in name resolution",
            "network error",
        )
    )


def _is_transient_image_error(exc: BaseException) -> bool:
    if isinstance(exc, urllib.error.HTTPError):
        return exc.code in {403, 429, 500, 502, 503, 504}
    msg = str(exc).lower()
    if "http error 403" in msg or "http error 429" in msg:
        return True
    if "incomplete read" in msg or "incapsula" in msg:
        return True
    return _is_transient_api_error(exc)


def _json_claim_extra_sql(*, force: bool) -> str:
    if force:
        return ""
    return " AND COALESCE(dp.error_message, '') = ''"


def _finalize_deferred_json_pending() -> int:
    """Old RETRY rows (pending + error_message) -> error; no auto-retry pass."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages dp
                SET html_status = 'error',
                    updated_at = now()
                FROM oem_assemblies a
                WHERE a.id = dp.assembly_id
                  AND a.root_arib = %s
                  AND dp.html_status = 'pending'
                  AND COALESCE(dp.error_message, '') <> ''
                """,
                (ROOT_ARIB,),
            )
            count = cur.rowcount
        conn.commit()
        return int(count or 0)


def _json_pending_statuses(*, force: bool) -> list[str]:
    if force:
        return ["pending", "error", "running", "ok"]
    # Stale `running` rows are reset at startup; claiming them here caused duplicate work.
    return ["pending"]


def _image_pending_statuses(*, force: bool) -> list[str]:
    if force:
        return ["pending", "error", "running", "ok"]
    return ["pending"]


def _both_pending_statuses(*, force: bool) -> tuple[list[str], list[str]]:
    if force:
        statuses = ["pending", "error", "running", "ok"]
        return statuses, statuses
    return ["pending", "running"], ["pending", "running"]


def _claim_order_sql(*, phase: CrawlPhase, claim_order: ClaimOrder = "asc") -> str:
    if phase in {"json", "images"}:
        if claim_order == "desc":
            return "a.id DESC"
        if claim_order == "random":
            return "random()"
        return "a.id"
    return """
                  CASE
                    WHEN dp.html_status = 'pending' AND COALESCE(dp.error_message, '') = '' THEN 0
                    WHEN dp.html_status = 'ok' AND dp.image_status = 'pending' THEN 1
                    WHEN dp.html_status = 'running' OR dp.image_status = 'running' THEN 2
                    WHEN dp.html_status = 'pending' AND COALESCE(dp.error_message, '') <> '' THEN 3
                    WHEN dp.html_status = 'error' THEN 4
                    ELSE 5
                  END,
                  a.id
                """


def reset_crawl_errors(
    *,
    phase: CrawlPhase | Literal["all"] = "all",
    include_permanent: bool = False,
) -> dict[str, int]:
    """Reset error/running rows back to pending for retry."""
    permanent_filter = ""
    if not include_permanent:
        permanent_filter = """
                      AND COALESCE(dp.error_message, '') NOT LIKE '%%HTTP 500%%'
                      AND COALESCE(dp.error_message, '') NOT LIKE '%%HTTP 404%%'
        """

    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            reset_json = 0
            reset_images = 0
            reset_running = 0

            if phase in {"json", "both", "all"}:
                cur.execute(
                    f"""
                    UPDATE oem_details_pages dp
                    SET html_status = 'pending',
                        error_message = NULL,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND dp.html_status = 'error'
                      {permanent_filter}
                    """,
                    (ROOT_ARIB,),
                )
                reset_json = cur.rowcount

            if phase in {"images", "both", "all"}:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET image_status = 'pending',
                        error_message = NULL,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND dp.html_status = 'ok'
                      AND dp.image_status = 'error'
                    """,
                    (ROOT_ARIB,),
                )
                reset_images = cur.rowcount

            if phase == "json":
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = 'pending',
                        error_message = NULL,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND dp.html_status = 'running'
                    """,
                    (ROOT_ARIB,),
                )
                reset_running = cur.rowcount
            elif phase == "images":
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET image_status = 'pending',
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND dp.html_status = 'ok'
                      AND dp.image_status = 'running'
                    """,
                    (ROOT_ARIB,),
                )
                reset_running = cur.rowcount
            else:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = CASE
                          WHEN dp.html_status = 'running' AND dp.error_message IS NOT NULL THEN 'error'
                          WHEN dp.html_status = 'running' THEN 'pending'
                          ELSE dp.html_status
                        END,
                        image_status = CASE
                          WHEN dp.image_status = 'running' AND dp.error_message IS NOT NULL THEN 'error'
                          WHEN dp.image_status = 'running' THEN 'pending'
                          ELSE dp.image_status
                        END,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND (dp.html_status = 'running' OR dp.image_status = 'running')
                    """,
                    (ROOT_ARIB,),
                )
                reset_running = cur.rowcount

        conn.commit()

    return {
        "reset_json_errors": int(reset_json or 0),
        "reset_image_errors": int(reset_images or 0),
        "reset_running": int(reset_running or 0),
        "phase": phase,
    }


def _count_pending(*, phase: CrawlPhase, force: bool) -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if phase == "json":
                statuses = _json_pending_statuses(force=force)
                extra = _json_claim_extra_sql(force=force)
                cur.execute(
                    f"""
                    SELECT COUNT(*) AS c
                    FROM oem_details_pages dp
                    JOIN oem_assemblies a ON a.id = dp.assembly_id
                    WHERE a.root_arib = %s
                      AND dp.html_status = ANY(%s)
                      {extra}
                    """,
                    (ROOT_ARIB, statuses),
                )
            elif phase == "images":
                statuses = _image_pending_statuses(force=force)
                cur.execute(
                    """
                    SELECT COUNT(*) AS c
                    FROM oem_details_pages dp
                    JOIN oem_assemblies a ON a.id = dp.assembly_id
                    WHERE a.root_arib = %s
                      AND dp.html_status = 'ok'
                      AND dp.image_status = ANY(%s)
                    """,
                    (ROOT_ARIB, statuses),
                )
            else:
                html_statuses, image_statuses = _both_pending_statuses(force=force)
                cur.execute(
                    """
                    SELECT COUNT(*) AS c
                    FROM oem_details_pages dp
                    JOIN oem_assemblies a ON a.id = dp.assembly_id
                    WHERE a.root_arib = %s
                      AND (
                        dp.html_status = ANY(%s)
                        OR (dp.html_status = 'ok' AND dp.image_status = ANY(%s))
                      )
                    """,
                    (ROOT_ARIB, html_statuses, image_statuses),
                )
            return int(cur.fetchone()["c"])


def _reset_stale_running(*, phase: CrawlPhase) -> int:
    result = reset_crawl_errors(phase=phase)
    return int(result["reset_running"])


def _claim_batch(
    *,
    phase: CrawlPhase,
    force: bool,
    batch_size: int,
    claim_order: ClaimOrder = "asc",
) -> list[dict[str, Any]]:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if phase == "json":
                statuses = _json_pending_statuses(force=force)
                extra = _json_claim_extra_sql(force=force)
                cur.execute(
                    f"""
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
                    WHERE a.root_arib = %s
                      AND dp.html_status = ANY(%s)
                      {extra}
                    ORDER BY
                    """
                    + _claim_order_sql(phase=phase, claim_order=claim_order)
                    + """
                    LIMIT %s
                    FOR UPDATE OF dp SKIP LOCKED
                    """,
                    (ROOT_ARIB, statuses, batch_size),
                )
            elif phase == "images":
                statuses = _image_pending_statuses(force=force)
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
                    WHERE a.root_arib = %s
                      AND dp.html_status = 'ok'
                      AND dp.image_status = ANY(%s)
                    ORDER BY
                    """
                    + _claim_order_sql(phase=phase, claim_order=claim_order)
                    + """
                    LIMIT %s
                    FOR UPDATE OF dp SKIP LOCKED
                    """,
                    (ROOT_ARIB, statuses, batch_size),
                )
            else:
                html_statuses, image_statuses = _both_pending_statuses(force=force)
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
                    WHERE a.root_arib = %s
                      AND (
                        dp.html_status = ANY(%s)
                        OR (dp.html_status = 'ok' AND dp.image_status = ANY(%s))
                      )
                    ORDER BY
                    """
                    + _claim_order_sql(phase=phase, claim_order=claim_order)
                    + """
                    LIMIT %s
                    FOR UPDATE OF dp SKIP LOCKED
                    """,
                    (ROOT_ARIB, html_statuses, image_statuses, batch_size),
                )

            rows = cur.fetchall()
            if not rows:
                conn.commit()
                return []

            for row in rows:
                if phase == "json":
                    cur.execute(
                        """
                        UPDATE oem_details_pages
                        SET html_status = 'running', updated_at = now()
                        WHERE id = %s
                        """,
                        (row["details_page_id"],),
                    )
                elif phase == "images":
                    cur.execute(
                        """
                        UPDATE oem_details_pages
                        SET image_status = 'running', updated_at = now()
                        WHERE id = %s
                        """,
                        (row["details_page_id"],),
                    )
                else:
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


def _mark_json_ok(*, assembly_id: int, json_path: Path, digest: str) -> None:
    rel = str(json_path).replace("\\", "/")
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'ok',
                    html_path = %s,
                    html_hash = %s,
                    fetched_at = now(),
                    error_message = NULL,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (rel, digest, assembly_id),
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
                    error_message = NULL,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (str(image_path), image_url, assembly_id),
            )
        conn.commit()


def _reset_json_pending(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'pending',
                    error_message = NULL,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (assembly_id,),
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


def _mark_json_error(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'error',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _fail_json_row(
    assembly_id: int,
    message: str,
    *,
    stats: dict[str, int],
    stats_lock: threading.Lock,
    progress: ProgressReporter,
) -> None:
    with stats_lock:
        stats["json_error"] += 1
    try:
        _mark_json_error(assembly_id, message)
    except Exception:
        _reset_json_pending(assembly_id, message)
    progress.advance("", step=1)


def _mark_both_error(assembly_id: int, message: str) -> None:
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


def _payload_ids(payload: dict[str, Any]) -> tuple[str, str | int] | None:
    model_id = str(payload.get("model_id") or "")
    image_id = payload.get("image_id")
    if not model_id or image_id is None:
        return None
    return model_id, image_id


def _download_image(
    *,
    assembly_id: int,
    model_id: str,
    image_id: str | int,
    image_path: Path,
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> None:
    try:
        image_bytes = fetch_image_png_full(model_id=model_id, image_id=image_id)
        image_path.parent.mkdir(parents=True, exist_ok=True)
        image_path.write_bytes(image_bytes)
        width, height = png_dimensions(image_bytes)
        checksum = hashlib.sha256(image_bytes).hexdigest()
        image_url = f"yamaha-motor.com/parts/diagram/{model_id}/{image_id}"
        _mark_image_ok(
            assembly_id=assembly_id,
            image_path=image_path,
            image_url=image_url,
            checksum=checksum,
            width=width,
            height=height,
        )
        with stats_lock:
            stats["image_ok"] += 1
        progress.advance(f"ok image assembly={assembly_id}")
    except Exception as exc:
        if _is_pool_timeout(exc):
            _reset_image_pending(assembly_id, str(exc))
            progress.advance(f"RETRY image assembly={assembly_id}")
            return
        if _is_transient_image_error(exc):
            _reset_image_pending(assembly_id, str(exc))
            progress.advance(f"RETRY image assembly={assembly_id}")
            return
        with stats_lock:
            stats["image_error"] += 1
        _mark_image_error(assembly_id, str(exc))
        progress.advance(f"ERROR image assembly={assembly_id}")


def _fetch_json(
    *,
    assembly_id: int,
    root_arib: str,
    model_id: str,
    image_id: str | int,
    json_path: Path,
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> bool:
    try:
        diagram = fetch_diagram(model_id=model_id, image_id=image_id)
        json_path.parent.mkdir(parents=True, exist_ok=True)
        blob = {
            "fetched_at": datetime.now(timezone.utc).isoformat(),
            "request": {"model_id": model_id, "image_id": image_id},
            "response": diagram,
        }
        payload = json.dumps(blob, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        digest = hashlib.sha256(payload).hexdigest()
        json_path.write_bytes(payload)
        _mark_json_ok(assembly_id=assembly_id, json_path=json_path, digest=digest)
        with stats_lock:
            stats["json_ok"] += 1
        progress.advance(f"ok json assembly={assembly_id}")
        return True
    except Exception as exc:
        _fail_json_row(
            assembly_id,
            f"diagram fetch failed: {exc}",
            stats=stats,
            stats_lock=stats_lock,
            progress=progress,
        )
        return False


def _process_json_row(
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

    if not force and json_path.is_file():
        if row["html_status"] == "ok":
            with stats_lock:
                stats["skipped"] += 1
            progress.advance(f"skip json assembly={assembly_id}")
            return
        try:
            digest = hashlib.sha256(json_path.read_bytes()).hexdigest()
            _mark_json_ok(assembly_id=assembly_id, json_path=json_path, digest=digest)
            with stats_lock:
                stats["json_ok"] += 1
            progress.advance(f"recover json assembly={assembly_id}")
            return
        except Exception as exc:
            _fail_json_row(
                assembly_id,
                f"recover json failed: {exc}",
                stats=stats,
                stats_lock=stats_lock,
                progress=progress,
            )
            return

    ids = _payload_ids(payload)
    if ids is None:
        _fail_json_row(
            assembly_id,
            "missing model_id/image_id in source_payload",
            stats=stats,
            stats_lock=stats_lock,
            progress=progress,
        )
        return

    model_id, image_id = ids
    _fetch_json(
        assembly_id=assembly_id,
        root_arib=root_arib,
        model_id=model_id,
        image_id=image_id,
        json_path=json_path,
        progress=progress,
        stats=stats,
        stats_lock=stats_lock,
    )


def _process_image_row(
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

    if row["html_status"] != "ok" or not json_path.is_file():
        with stats_lock:
            stats["skipped"] += 1
        _reset_image_pending(assembly_id, "json not ready")
        progress.advance(f"skip image assembly={assembly_id} json missing")
        return

    if not force and row["image_status"] == "ok" and image_path.is_file():
        with stats_lock:
            stats["skipped"] += 1
        progress.advance(f"skip image assembly={assembly_id}")
        return

    ids = _payload_ids(payload)
    if ids is None:
        with stats_lock:
            stats["image_error"] += 1
        _mark_image_error(assembly_id, "missing model_id/image_id in source_payload")
        progress.advance(f"ERROR image assembly={assembly_id} missing payload")
        return

    model_id, image_id = ids
    _download_image(
        assembly_id=assembly_id,
        model_id=model_id,
        image_id=image_id,
        image_path=image_path,
        progress=progress,
        stats=stats,
        stats_lock=stats_lock,
    )


def _process_both_row(
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

    ids = _payload_ids(payload)
    if ids is None:
        with stats_lock:
            stats["json_error"] += 1
        _mark_both_error(assembly_id, "missing model_id/image_id in source_payload")
        progress.advance(f"ERROR assembly={assembly_id} missing payload")
        return

    model_id, image_id = ids

    if (
        not force
        and row["html_status"] == "ok"
        and json_path.is_file()
        and row["image_status"] != "ok"
    ):
        _download_image(
            assembly_id=assembly_id,
            model_id=model_id,
            image_id=image_id,
            image_path=image_path,
            progress=progress,
            stats=stats,
            stats_lock=stats_lock,
        )
        return

    if not _fetch_json(
        assembly_id=assembly_id,
        root_arib=root_arib,
        model_id=model_id,
        image_id=image_id,
        json_path=json_path,
        progress=progress,
        stats=stats,
        stats_lock=stats_lock,
    ):
        return

    _download_image(
        assembly_id=assembly_id,
        model_id=model_id,
        image_id=image_id,
        image_path=image_path,
        progress=progress,
        stats=stats,
        stats_lock=stats_lock,
    )


def _run_crawl(
    *,
    phase: CrawlPhase,
    limit: int | None,
    force: bool,
    concurrency: int,
    api_concurrency: int | None = None,
    claim_order: ClaimOrder = "asc",
    worker_id: int = 0,
    workers: int = 1,
    label_prefix: str,
    process_row: Callable[..., None],
) -> dict[str, Any]:
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    requested = max(1, int(concurrency))
    if phase == "images" and requested > MAX_IMAGE_CRAWL_CONCURRENCY:
        print(
            f"[{label_prefix}] concurrency capped {requested} -> {MAX_IMAGE_CRAWL_CONCURRENCY} "
            f"(Incapsula/site limit)",
            flush=True,
        )
        concurrency = MAX_IMAGE_CRAWL_CONCURRENCY
    else:
        concurrency = requested

    api_limit: int | None = None
    if phase == "json":
        api_limit = configure_diagram_api_concurrency(api_concurrency)
        print(
            f"[{label_prefix}] workers={concurrency} api_in_flight_limit={api_limit} "
            f"claim_order={claim_order} turbo",
            flush=True,
        )

    if worker_id == 0:
        reset = _reset_stale_running(phase=phase)
        if reset:
            print(f"[{label_prefix}] reset stale running={reset}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = label_prefix if workers == 1 else f"{label_prefix}-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"

    pending = _count_pending(phase=phase, force=force)
    progress = ProgressReporter(total=pending if not limit else min(pending, limit), label=worker_label)
    progress.enable_thread_safe()
    stats = {
        "json_ok": 0,
        "json_error": 0,
        "image_ok": 0,
        "image_error": 0,
        "skipped": 0,
    }
    stats_lock = threading.Lock()
    processed = 0
    claimed = 0
    last_tick = time.monotonic()
    batch_before = dict(stats)
    completions_since_log = 0

    def _maybe_log_batch() -> None:
        nonlocal batch_before, completions_since_log, last_tick
        with stats_lock:
            batch_ok = stats["json_ok"] + stats["image_ok"] - batch_before["json_ok"] - batch_before["image_ok"]
            batch_err = stats["json_error"] + stats["image_error"] - batch_before["json_error"] - batch_before["image_error"]
            batch_skip = stats["skipped"] - batch_before["skipped"]
        batch_done = batch_ok + batch_err + batch_skip
        if batch_done:
            err_pct = batch_err / batch_done * 100 if batch_done else 0.0
            print(
                f"[{label_prefix}] batch ok={batch_ok} err={batch_err} skip={batch_skip} ({err_pct:.0f}% err)",
                flush=True,
            )
        batch_before = dict(stats)
        completions_since_log = 0
        if time.monotonic() - last_tick >= 5.0:
            with stats_lock:
                progress.tick(
                    f"json={stats['json_ok']} img={stats['image_ok']} "
                    f"err={stats['json_error'] + stats['image_error']}"
                )
            last_tick = time.monotonic()

    def _submit_row(executor: ThreadPoolExecutor, row: dict[str, Any]) -> Any:
        return executor.submit(
            process_row,
            row,
            force=force,
            progress=progress,
            stats=stats,
            stats_lock=stats_lock,
        )

    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        in_flight: dict[Any, dict[str, Any]] = {}
        while True:
            if limit is not None and processed >= limit:
                break

            while len(in_flight) < concurrency:
                if limit is not None and processed >= limit:
                    break
                slots = concurrency - len(in_flight)
                claim_size = min(max(slots, concurrency), JSON_CRAWL_CLAIM_BATCH_MAX)
                if limit is not None:
                    remaining = limit - processed
                    if remaining <= 0:
                        break
                    claim_size = min(claim_size, remaining)
                rows = _claim_batch(
                    phase=phase,
                    force=force,
                    batch_size=claim_size,
                    claim_order=claim_order,
                )
                if not rows:
                    break
                if workers > 1:
                    rows = [row for row in rows if int(row["assembly_id"]) % workers == worker_id]
                    if not rows:
                        break
                if claimed == 0 or (claimed // max(concurrency * 10, 1)) != ((claimed + len(rows)) // max(concurrency * 10, 1)):
                    first_id = rows[0]["assembly_id"]
                    last_id = rows[-1]["assembly_id"]
                    print(
                        f"[{label_prefix}] claimed batch={len(rows)} assemblies={first_id}..{last_id}",
                        flush=True,
                    )
                claimed += len(rows)
                for row in rows:
                    if limit is not None and processed >= limit:
                        break
                    future = _submit_row(executor, row)
                    in_flight[future] = row
                    processed += 1

            if not in_flight:
                break

            done, _ = wait(in_flight.keys(), return_when=FIRST_COMPLETED)
            for future in done:
                future.result()
                in_flight.pop(future, None)
                completions_since_log += 1
                if completions_since_log >= concurrency:
                    _maybe_log_batch()

        if in_flight:
            done, _ = wait(in_flight.keys())
            for future in done:
                future.result()
                in_flight.pop(future, None)
                completions_since_log += 1
        if completions_since_log:
            _maybe_log_batch()

    progress.finish(f"crawl stats={stats}")
    return {
        "processed": processed,
        "stats": stats,
        "worker_id": worker_id,
        "workers": workers,
        "concurrency": concurrency,
        "api_concurrency": api_limit,
        "claim_order": claim_order,
        "phase": phase,
    }


def crawl_json_details(
    *,
    limit: int | None = None,
    force: bool = False,
    concurrency: int = 8,
    api_concurrency: int | None = None,
    claim_order: ClaimOrder = "desc",
    worker_id: int = 0,
    workers: int = 1,
) -> dict[str, Any]:
    return _run_crawl(
        phase="json",
        limit=limit,
        force=force,
        concurrency=concurrency,
        api_concurrency=api_concurrency,
        claim_order=claim_order,
        worker_id=worker_id,
        workers=workers,
        label_prefix="yamaha-us-crawl-json",
        process_row=_process_json_row,
    )


def crawl_image_details(
    *,
    limit: int | None = None,
    force: bool = False,
    concurrency: int = 8,
    worker_id: int = 0,
    workers: int = 1,
) -> dict[str, Any]:
    return _run_crawl(
        phase="images",
        limit=limit,
        force=force,
        concurrency=concurrency,
        worker_id=worker_id,
        workers=workers,
        label_prefix="yamaha-us-crawl-images",
        process_row=_process_image_row,
    )


def crawl_details(
    *,
    limit: int | None = None,
    force: bool = False,
    concurrency: int = 8,
    worker_id: int = 0,
    workers: int = 1,
    phase: CrawlPhase = "both",
) -> dict[str, Any]:
    if phase == "json":
        return crawl_json_details(
            limit=limit,
            force=force,
            concurrency=concurrency,
            worker_id=worker_id,
            workers=workers,
        )
    if phase == "images":
        return crawl_image_details(
            limit=limit,
            force=force,
            concurrency=concurrency,
            worker_id=worker_id,
            workers=workers,
        )
    return _run_crawl(
        phase="both",
        limit=limit,
        force=force,
        concurrency=concurrency,
        worker_id=worker_id,
        workers=workers,
        label_prefix="yamaha-us-crawl",
        process_row=_process_both_row,
    )
