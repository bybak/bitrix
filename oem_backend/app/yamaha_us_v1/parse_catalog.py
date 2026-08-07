from __future__ import annotations

import json
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from typing import Any

import psycopg.errors
from psycopg_pool import PoolTimeout

from app.db import get_yamaha_conn
from app.normalization import normalize_text
from app.yamaha_v1.constants import BULK_BATCH_SIZE, PROGRESS_INTERVAL_SEC
from app.yamaha_v1.progress import ProgressReporter

from .constants import ROOT_ARIB
from .parse_responses import parse_diagram_response


def _resolve_json_path(path: str) -> Path:
    candidate = Path(path)
    if candidate.is_file():
        return candidate
    from_app = Path("/app") / path
    if from_app.is_file():
        return from_app
    return candidate


def _is_pool_timeout(exc: BaseException) -> bool:
    if isinstance(exc, PoolTimeout):
        return True
    msg = str(exc).lower()
    return "couldn't get a connection" in msg or "pool timeout" in msg


def _is_deadlock(exc: BaseException) -> bool:
    if isinstance(exc, psycopg.errors.DeadlockDetected):
        return True
    return "deadlock detected" in str(exc).lower()


def _is_retryable_parse_error(exc: BaseException) -> bool:
    return _is_pool_timeout(exc) or _is_deadlock(exc)


def _count_pending(*, force: bool) -> int:
    statuses = ("pending", "error", "running", "ok") if force else ("pending", "running", "error")
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS c
                FROM oem_details_pages dp
                JOIN oem_assemblies a ON a.id = dp.assembly_id
                WHERE a.root_arib = %s
                  AND dp.html_status = 'ok'
                  AND dp.html_path IS NOT NULL
                  AND dp.parse_status = ANY(%s)
                """,
                (ROOT_ARIB, list(statuses)),
            )
            return int(cur.fetchone()["c"])


def _reset_stale_running() -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages dp
                SET parse_status = 'pending', updated_at = now()
                FROM oem_assemblies a
                WHERE a.id = dp.assembly_id
                  AND a.root_arib = %s
                  AND dp.parse_status = 'running'
                """,
                (ROOT_ARIB,),
            )
            reset = cur.rowcount
        conn.commit()
    return int(reset or 0)


def _claim_batch(*, force: bool, batch_size: int) -> list[dict[str, Any]]:
    statuses = ("pending", "error", "running", "ok") if force else ("pending", "running", "error")
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  dp.id AS details_page_id,
                  dp.assembly_id,
                  a.root_arib,
                  a.assembly_key,
                  dp.html_path,
                  dp.parse_status,
                  d.original_url AS image_url,
                  d.width AS diagram_width,
                  d.height AS diagram_height,
                  d.coord_width,
                  d.coord_height
                FROM oem_details_pages dp
                JOIN oem_assemblies a ON a.id = dp.assembly_id
                LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
                WHERE a.root_arib = %s
                  AND dp.html_status = 'ok'
                  AND dp.html_path IS NOT NULL
                  AND dp.parse_status = ANY(%s)
                ORDER BY a.id
                LIMIT %s
                FOR UPDATE OF dp SKIP LOCKED
                """,
                (ROOT_ARIB, list(statuses), batch_size),
            )
            rows = cur.fetchall()
            if not rows:
                conn.commit()
                return []
            for row in rows:
                cur.execute(
                    """
                    UPDATE oem_details_pages
                    SET parse_status = 'running', updated_at = now()
                    WHERE id = %s
                    """,
                    (row["details_page_id"],),
                )
        conn.commit()
        return [dict(row) for row in rows]


def _reset_parse_pending(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET parse_status = 'pending',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _bulk_write_assembly_content(
    *,
    assembly_id: int,
    root_arib: str,
    parsed: dict[str, Any],
) -> tuple[int, int]:
    parts_data = parsed.get("parts") or []
    hotspots_data = parsed.get("hotspots") or []

    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "DELETE FROM oem_diagram_hotspots WHERE diagram_id IN (SELECT id FROM oem_diagrams WHERE assembly_id=%s)",
                (assembly_id,),
            )
            cur.execute("DELETE FROM oem_assembly_parts WHERE assembly_id=%s", (assembly_id,))

            part_batch: list[tuple[Any, ...]] = []
            for part in parts_data:
                normalized = normalize_text(part["part_number"]).upper()
                part_batch.append((root_arib, part["part_number"], normalized, part.get("name")))
            part_batch.sort(key=lambda row: row[2])

            for chunk_start in range(0, max(len(part_batch), 1), BULK_BATCH_SIZE):
                chunk = part_batch[chunk_start : chunk_start + BULK_BATCH_SIZE]
                if not chunk:
                    break
                cur.executemany(
                    """
                    INSERT INTO oem_parts(root_arib, part_number, normalized_part_number, name)
                    VALUES (%s, %s, %s, %s)
                    ON CONFLICT (root_arib, normalized_part_number) DO UPDATE SET
                      part_number = EXCLUDED.part_number,
                      name = COALESCE(EXCLUDED.name, oem_parts.name),
                      updated_at = now()
                    """,
                    chunk,
                )

            part_id_by_number: dict[str, int] = {}
            if part_batch:
                numbers = [normalize_text(p["part_number"]).upper() for p in parts_data]
                cur.execute(
                    """
                    SELECT id, normalized_part_number FROM oem_parts
                    WHERE root_arib = %s AND normalized_part_number = ANY(%s)
                    """,
                    (root_arib, numbers),
                )
                for part_row in cur.fetchall():
                    part_id_by_number[part_row["normalized_part_number"]] = int(part_row["id"])

            ap_batch: list[tuple[Any, ...]] = []
            for part in parts_data:
                normalized = normalize_text(part["part_number"]).upper()
                part_id = part_id_by_number.get(normalized)
                if not part_id:
                    continue
                ap_batch.append(
                    (
                        assembly_id,
                        part_id,
                        part.get("ref"),
                        part.get("quantity"),
                        "original",
                        part["source_row_id"],
                        json.dumps(part.get("raw_payload") or {}, ensure_ascii=False),
                    )
                )

            for chunk_start in range(0, max(len(ap_batch), 1), BULK_BATCH_SIZE):
                chunk = ap_batch[chunk_start : chunk_start + BULK_BATCH_SIZE]
                if not chunk:
                    break
                cur.executemany(
                    """
                    INSERT INTO oem_assembly_parts(
                      assembly_id, part_id, ref, quantity, row_kind, source_row_id, raw_payload
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb)
                    ON CONFLICT (assembly_id, source_row_id) DO UPDATE SET
                      ref = EXCLUDED.ref,
                      quantity = EXCLUDED.quantity,
                      raw_payload = EXCLUDED.raw_payload
                    """,
                    chunk,
                )

            cur.execute("SELECT id, ref FROM oem_assembly_parts WHERE assembly_id = %s", (assembly_id,))
            ref_to_ap_id: dict[str, int] = {}
            for ap_row in cur.fetchall():
                if ap_row["ref"]:
                    ref_to_ap_id[str(ap_row["ref"])] = int(ap_row["id"])

            cur.execute("SELECT id FROM oem_diagrams WHERE assembly_id = %s", (assembly_id,))
            diagram_row = cur.fetchone()
            diagram_id = int(diagram_row["id"]) if diagram_row else None

            hs_batch: list[tuple[Any, ...]] = []
            if diagram_id:
                for hotspot in hotspots_data:
                    ref = hotspot.get("ref") or ""
                    hs_batch.append(
                        (
                            diagram_id,
                            ref_to_ap_id.get(ref),
                            "rect",
                            hotspot.get("raw_coords"),
                            hotspot.get("x"),
                            hotspot.get("y"),
                            hotspot.get("width"),
                            hotspot.get("height"),
                            ref or None,
                            json.dumps(hotspot.get("raw_payload") or {}, ensure_ascii=False),
                        )
                    )

            for chunk_start in range(0, max(len(hs_batch), 1), BULK_BATCH_SIZE):
                chunk = hs_batch[chunk_start : chunk_start + BULK_BATCH_SIZE]
                if not chunk:
                    break
                cur.executemany(
                    """
                    INSERT INTO oem_diagram_hotspots(
                      diagram_id, assembly_part_id, shape, raw_coords, x, y, width, height, ref, raw_payload
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb)
                    """,
                    chunk,
                )

        conn.commit()

    return len(parts_data), len(hs_batch)


def _mark_parsed(assembly_id: int) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET parse_status = 'ok', parsed_at = now(), error_message = NULL, updated_at = now()
                WHERE assembly_id = %s
                """,
                (assembly_id,),
            )
        conn.commit()


def _mark_parse_error(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET parse_status = 'error', error_message = %s, updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _process_parse_row(
    row: dict[str, Any],
    *,
    force: bool,
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> None:
    assembly_id = int(row["assembly_id"])
    if not force and row["parse_status"] == "ok":
        with stats_lock:
            stats["skipped"] += 1
        _mark_parsed(assembly_id)
        progress.advance(f"skip assembly={assembly_id}")
        return

    json_path = row["html_path"]
    if not json_path:
        with stats_lock:
            stats["errors"] += 1
        _mark_parse_error(assembly_id, "json path missing")
        progress.advance(f"ERROR assembly={assembly_id} no path")
        return

    try:
        blob = json.loads(_resolve_json_path(json_path).read_text(encoding="utf-8"))
        response = blob.get("response") or blob
        request = blob.get("request") or {}
        image_id = request.get("image_id")
        parsed = parse_diagram_response(
            response,
            image_id=image_id or "",
            coord_width=row.get("coord_width") or row.get("diagram_width"),
            coord_height=row.get("coord_height") or row.get("diagram_height"),
        )
        last_exc: BaseException | None = None
        for attempt in range(4):
            try:
                part_rows, hotspot_rows = _bulk_write_assembly_content(
                    assembly_id=assembly_id,
                    root_arib=row["root_arib"],
                    parsed=parsed,
                )
                with stats_lock:
                    stats["parts"] += part_rows
                    stats["hotspots"] += hotspot_rows
                    stats["ok"] += 1
                _mark_parsed(assembly_id)
                progress.advance(f"parsed assembly={assembly_id} parts={part_rows}")
                return
            except Exception as exc:
                last_exc = exc
                if attempt < 3 and _is_retryable_parse_error(exc):
                    time.sleep(0.05 * (2**attempt))
                    continue
                raise last_exc
    except Exception as exc:
        if _is_retryable_parse_error(exc):
            _reset_parse_pending(assembly_id, str(exc))
            progress.advance(f"RETRY parse assembly={assembly_id}")
            return
        with stats_lock:
            stats["errors"] += 1
        _mark_parse_error(assembly_id, str(exc))
        progress.advance(f"ERROR parse assembly={assembly_id}")


def parse_details(
    *,
    limit: int | None = None,
    force: bool = False,
    concurrency: int = 16,
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
            print(f"[yamaha-us-parse] reset stale running={reset}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = "yamaha-us-parse" if workers == 1 else f"yamaha-us-parse-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"

    pending = _count_pending(force=force)
    progress = ProgressReporter(total=pending if not limit else min(pending, limit), label=worker_label)
    progress.enable_thread_safe()
    stats = {"ok": 0, "errors": 0, "parts": 0, "hotspots": 0, "skipped": 0}
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
            if workers > 1:
                rows = [row for row in rows if int(row["assembly_id"]) % workers == worker_id]
                if not rows:
                    break
            processed += len(rows)
            futures = [
                executor.submit(
                    _process_parse_row,
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
                    progress.tick(f"ok={stats['ok']} parts={stats['parts']} hotspots={stats['hotspots']}")
                last_tick = time.monotonic()

    progress.finish(f"parse stats={stats}")
    return {
        "processed": processed,
        "stats": stats,
        "worker_id": worker_id,
        "workers": workers,
        "concurrency": concurrency,
    }
