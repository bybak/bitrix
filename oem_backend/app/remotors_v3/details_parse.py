from __future__ import annotations

import json
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from typing import Any

from psycopg_pool import PoolTimeout

from app.db import get_conn
from app.normalization import normalize_text
from app.remotors_v3.constants import BULK_BATCH_SIZE, PROGRESS_INTERVAL_SEC
from app.remotors_v3.parse_html import parse_details_html
from app.remotors_v3.progress import ProgressReporter


def _claim_batch_parse(*, force: bool, batch_size: int = 1) -> list[dict[str, Any]]:
    batch_size = max(1, int(batch_size))
    statuses = ("pending", "error", "running", "ok") if force else ("pending", "running")
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  dp.id AS details_page_id,
                  dp.assembly_id,
                  a.root_arib,
                  a.assembly_key,
                  dp.html_path
                FROM oem_details_pages dp
                JOIN oem_assemblies a ON a.id = dp.assembly_id
                WHERE dp.html_status = 'ok'
                  AND dp.html_path IS NOT NULL
                  AND dp.parse_status = ANY(%s)
                ORDER BY a.id
                LIMIT %s
                FOR UPDATE OF dp SKIP LOCKED
                """,
                (list(statuses), batch_size),
            )
            rows = cur.fetchall()
            if not rows:
                return []
            for row in rows:
                cur.execute(
                    """
                    UPDATE oem_details_pages SET parse_status = 'running', updated_at = now()
                    WHERE id = %s
                    """,
                    (row["details_page_id"],),
                )
        conn.commit()
        return [dict(row) for row in rows]


def _reset_stale_running_parse() -> int:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET parse_status = 'pending', updated_at = now()
                WHERE parse_status = 'running'
                """
            )
            reset = cur.rowcount
        conn.commit()
    return int(reset or 0)


def _count_pending_parse(*, force: bool) -> int:
    statuses = ("pending", "error", "running", "ok") if force else ("pending", "running")
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT COUNT(*) AS c
                FROM oem_details_pages dp
                WHERE dp.html_status = 'ok'
                  AND dp.html_path IS NOT NULL
                  AND dp.parse_status = ANY(%s)
                """,
                (list(statuses),),
            )
            return int(cur.fetchone()["c"])


def _process_parse_row(
    row: dict[str, Any],
    *,
    progress: ProgressReporter,
    stats: dict[str, int],
    stats_lock: threading.Lock,
) -> None:
    assembly_id = int(row["assembly_id"])
    root_arib = row["root_arib"]
    asm_key = row["assembly_key"]
    html_path = Path(row["html_path"])

    if not html_path.is_file():
        with stats_lock:
            stats["errors"] += 1
        _mark_parse_error(assembly_id, "html file missing")
        progress.advance(f"ERROR missing html assembly={assembly_id}")
        return

    try:
        parsed = parse_details_html(html_path.read_text(encoding="utf-8"))
        part_rows, hotspot_rows = _bulk_write_assembly_content(
            assembly_id=assembly_id,
            root_arib=root_arib,
            asm_key=asm_key,
            parsed=parsed,
        )
        with stats_lock:
            stats["parts"] += part_rows
            stats["hotspots"] += hotspot_rows
            stats["ok"] += 1
        _mark_parsed_pg(assembly_id)
        progress.advance(f"parsed assembly={assembly_id} parts={part_rows}")
    except Exception as exc:
        if _is_pool_timeout(exc):
            _reset_parse_pending(assembly_id, str(exc))
            progress.advance(f"RETRY parse assembly={assembly_id}: {exc}")
        else:
            with stats_lock:
                stats["errors"] += 1
            _mark_parse_error(assembly_id, str(exc))
            progress.advance(f"ERROR parse assembly={assembly_id}: {exc}")


def parse_details(
    *,
    sidecar_path: str,
    limit: int | None = None,
    force: bool = False,
    worker_id: int = 0,
    workers: int = 1,
    concurrency: int = 1,
) -> dict[str, Any]:
    _ = sidecar_path  # legacy flag; progress lives in PostgreSQL
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    concurrency = max(1, int(concurrency))

    if worker_id == 0:
        reset = _reset_stale_running_parse()
        if reset:
            print(f"[parse-details] reset stale running={reset}", flush=True)
    elif workers > 1:
        time.sleep(worker_id * 2)

    worker_label = "parse-details" if workers == 1 else f"parse-details-w{worker_id}/{workers}"
    if concurrency > 1:
        worker_label = f"{worker_label}x{concurrency}"
    progress = ProgressReporter(total=0, label=worker_label)
    progress.enable_thread_safe()
    stats = {"ok": 0, "errors": 0, "parts": 0, "hotspots": 0}
    stats_lock = threading.Lock()
    last_tick = time.monotonic()
    processed = 0

    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        while True:
            if limit and processed >= limit:
                break
            batch_size = concurrency if not limit else min(concurrency, limit - processed)
            rows = _claim_batch_parse(force=force, batch_size=batch_size)
            if not rows:
                break
            processed += len(rows)
            futures = [
                executor.submit(_process_parse_row, row, progress=progress, stats=stats, stats_lock=stats_lock)
                for row in rows
            ]
            for future in futures:
                future.result()

            if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
                with stats_lock:
                    progress.tick(f"ok={stats['ok']} parts={stats['parts']}")
                last_tick = time.monotonic()

    progress.finish(f"parse stats={stats}")
    return stats


def _is_pool_timeout(exc: BaseException) -> bool:
    if isinstance(exc, PoolTimeout):
        return True
    msg = str(exc).lower()
    return "couldn't get a connection" in msg or "pool timeout" in msg


def _reset_parse_pending(assembly_id: int, error: str) -> None:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  parse_status = 'pending',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _mark_parse_error(assembly_id: int, error: str) -> None:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  parse_status = 'error',
                  error_message = %s,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (error[:2000], assembly_id),
            )
        conn.commit()


def _mark_parsed_pg(assembly_id: int) -> None:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages SET
                  parse_status = 'ok',
                  parsed_at = now(),
                  error_message = NULL,
                  updated_at = now()
                WHERE assembly_id = %s
                """,
                (assembly_id,),
            )
        conn.commit()


def _bulk_write_assembly_content(
    *,
    assembly_id: int,
    root_arib: str,
    asm_key: str,
    parsed: dict[str, Any],
) -> tuple[int, int]:
    parts_data = parsed.get("parts") or []
    hotspots_data = parsed.get("hotspots") or []

    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "DELETE FROM oem_diagram_hotspots WHERE diagram_id IN (SELECT id FROM oem_diagrams WHERE assembly_id=%s)",
                (assembly_id,),
            )
            cur.execute("DELETE FROM oem_assembly_parts WHERE assembly_id=%s", (assembly_id,))

            part_id_by_number: dict[str, int] = {}
            part_batch: list[tuple[Any, ...]] = []
            for part in parts_data:
                normalized = normalize_text(part["part_number"]).upper()
                part_batch.append((root_arib, part["part_number"], normalized, part.get("name")))
            for chunk_start in range(0, max(len(part_batch), 1), BULK_BATCH_SIZE):
                chunk = part_batch[chunk_start : chunk_start + BULK_BATCH_SIZE]
                if not chunk:
                    break
                cur.executemany(
                    """
                    INSERT INTO oem_parts(root_arib, part_number, normalized_part_number, name)
                    VALUES (%s, %s, %s, %s)
                    ON CONFLICT (root_arib, normalized_part_number) DO UPDATE SET
                      name = COALESCE(EXCLUDED.name, oem_parts.name),
                      updated_at = now()
                    """,
                    chunk,
                )
            if part_batch:
                numbers = [normalize_text(p["part_number"]).upper() for p in parts_data]
                cur.execute(
                    """
                    SELECT id, normalized_part_number FROM oem_parts
                    WHERE root_arib = %s AND normalized_part_number = ANY(%s)
                    """,
                    (root_arib, numbers),
                )
                for row in cur.fetchall():
                    part_id_by_number[row["normalized_part_number"]] = int(row["id"])

            ap_batch: list[tuple[Any, ...]] = []
            for part in parts_data:
                normalized = normalize_text(part["part_number"]).upper()
                part_id = part_id_by_number.get(normalized)
                if not part_id:
                    continue
                source_row_id = f"{asm_key}:ref:{part.get('ref') or ''}:{normalized}"
                ap_batch.append(
                    (
                        assembly_id,
                        part_id,
                        part.get("ref"),
                        part.get("quantity"),
                        "original",
                        source_row_id,
                        json.dumps({"price_text": part.get("price_text")}, ensure_ascii=False),
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
            for row in cur.fetchall():
                if row["ref"]:
                    ref_to_ap_id[str(row["ref"])] = int(row["id"])

            cur.execute("SELECT id FROM oem_diagrams WHERE assembly_id = %s", (assembly_id,))
            diagram_row = cur.fetchone()
            if diagram_row:
                diagram_id = int(diagram_row["id"])
            else:
                cur.execute("INSERT INTO oem_diagrams(assembly_id) VALUES (%s) RETURNING id", (assembly_id,))
                diagram_id = int(cur.fetchone()["id"])

            hs_batch: list[tuple[Any, ...]] = []
            for hs in hotspots_data:
                ref = hs.get("ref") or ""
                hs_batch.append(
                    (
                        diagram_id,
                        ref_to_ap_id.get(ref),
                        "rect",
                        hs.get("raw_coords"),
                        hs.get("x"),
                        hs.get("y"),
                        hs.get("width"),
                        hs.get("height"),
                        ref or None,
                        json.dumps(hs.get("raw_payload") or {}, ensure_ascii=False),
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
    return len(parts_data), len(hotspots_data)
