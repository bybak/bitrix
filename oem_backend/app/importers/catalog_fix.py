"""Fix Remotors catalog data in PostgreSQL without a full re-crawl."""

from __future__ import annotations

import json
import sys
import threading
import time
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any

import psycopg
from psycopg.rows import dict_row

from app.config import get_settings
from app.db import get_conn
from app.importers import writer
from app.importers.remotors_catalog import (
    HIDDEN_CANONICAL_BRANDS,
    classify_source,
    crawl_arib_for_brand,
    path_from_slug,
)
from app.normalization import normalize_text

SOURCE_CODE = "remotors_ari"
BACKFILL_DEDUP_BATCH_SIZE = 2000
CONTENTS_DEDUP_BATCH_SIZE = 5000

_SOURCE_NODE_FK_INDEXES: tuple[tuple[str, str], ...] = (
    ("oem_assemblies", "ix_oem_assemblies_source_node"),
    ("oem_diagrams", "ix_oem_diagrams_source_node"),
    ("oem_assembly_parts", "ix_oem_assembly_parts_source_node"),
    ("oem_raw_snapshots", "ix_oem_raw_snapshots_source_node"),
)


def _log_progress(message: str) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, file=sys.stdout, flush=True)


def _exec_rowcount(query: str, params: tuple[Any, ...] = (), *, conn) -> int:
    with conn.cursor() as cur:
        cur.execute(query, params)
        return int(cur.rowcount or 0)


@contextmanager
def _heartbeat(label: str, *, interval_sec: float = 5.0, prefix: str = "backfill"):
    stop = threading.Event()
    started = time.monotonic()

    def _loop() -> None:
        while not stop.wait(interval_sec):
            elapsed = int(time.monotonic() - started)
            _log_progress(f"{prefix} {label} still running elapsed={elapsed}s")

    thread = threading.Thread(target=_loop, name=f"backfill-heartbeat-{label}", daemon=True)
    thread.start()
    try:
        yield
    finally:
        stop.set()
        thread.join(timeout=1.0)


def _dedup_batch_stats(conn) -> tuple[int, int]:
    row = _fetch_one(
        "SELECT COALESCE(MAX(batch_no), 0) AS max_batch, COUNT(*) AS total FROM tmp_assembly_node_dedup",
        conn=conn,
    ) or {"max_batch": 0, "total": 0}
    return int(row["max_batch"] or 0), int(row["total"] or 0)


def _run_batched_dedup_step(conn, label: str, query_template: str) -> int:
    max_batch, total_dupes = _dedup_batch_stats(conn)
    batch_count = max_batch + 1
    _log_progress(
        f"backfill phase 1/2 {label} start dupes={total_dupes} "
        f"batches={batch_count} batch_size={BACKFILL_DEDUP_BATCH_SIZE}"
    )
    total_affected = 0
    step_started = time.monotonic()
    for batch_no in range(batch_count):
        batch_started = time.monotonic()
        with _heartbeat(f"phase 1/2 {label} batch {batch_no + 1}/{batch_count}"):
            affected = _exec_rowcount(
                query_template + " AND d.batch_no = %s",
                (batch_no,),
                conn=conn,
            )
        total_affected += affected
        dupes_done = min((batch_no + 1) * BACKFILL_DEDUP_BATCH_SIZE, total_dupes)
        pct = (100.0 * dupes_done / total_dupes) if total_dupes else 100.0
        _log_progress(
            f"backfill phase 1/2 {label} batch {batch_no + 1}/{batch_count} "
            f"({pct:.1f}%) rows={affected} "
            f"batch_elapsed={int(time.monotonic() - batch_started)}s "
            f"step_elapsed={int(time.monotonic() - step_started)}s"
        )
    _log_progress(
        f"backfill phase 1/2 {label} done total_rows={total_affected} "
        f"elapsed={int(time.monotonic() - step_started)}s"
    )
    return total_affected


def _index_state(conn, index_name: str) -> str | None:
    row = _fetch_one(
        """
        SELECT i.indisvalid
        FROM pg_class c
        JOIN pg_index i ON i.indexrelid = c.oid
        WHERE c.relname = %s AND c.relkind = 'i'
        """,
        (index_name,),
        conn=conn,
    )
    if row is None:
        return None
    return "valid" if row["indisvalid"] else "invalid"


def _fetch_index_build_status(dsn: str, index_name: str) -> str | None:
    with psycopg.connect(dsn, row_factory=dict_row) as poll_conn:
        row = _fetch_one(
            """
            SELECT
              p.phase,
              p.blocks_done,
              p.blocks_total,
              p.tuples_done,
              p.tuples_total,
              a.pid,
              a.wait_event_type,
              a.wait_event
            FROM pg_stat_progress_create_index p
            JOIN pg_stat_activity a ON a.pid = p.pid
            WHERE a.query ILIKE %s
            LIMIT 1
            """,
            (f"%{index_name}%",),
            conn=poll_conn,
        )
        if not row:
            row = _fetch_one(
                """
                SELECT
                  a.pid,
                  a.wait_event_type,
                  a.wait_event
                FROM pg_stat_activity a
                WHERE a.query ILIKE %s
                  AND a.state = 'active'
                LIMIT 1
                """,
                (f"%{index_name}%",),
                conn=poll_conn,
            )
        if not row:
            return _fetch_index_lock_blockers(poll_conn, index_name)

        if row.get("wait_event_type") == "Lock":
            blockers = _fetch_index_lock_blockers(
                poll_conn,
                index_name,
                pid=int(row["pid"]) if row.get("pid") else None,
            )
            return blockers or (
                f"pid={row.get('pid')} blocked on Lock/{row.get('wait_event') or '?'}"
            )

        phase = row.get("phase")
        if phase:
            blocks_total = int(row.get("blocks_total") or 0)
            blocks_done = int(row.get("blocks_done") or 0)
            if blocks_total:
                pct = 100.0 * blocks_done / blocks_total
                return f"phase={phase} blocks={blocks_done}/{blocks_total} ({pct:.1f}%)"
            tuples_total = int(row.get("tuples_total") or 0)
            tuples_done = int(row.get("tuples_done") or 0)
            if tuples_total:
                pct = 100.0 * tuples_done / tuples_total
                return f"phase={phase} tuples={tuples_done}/{tuples_total} ({pct:.1f}%)"
            return f"phase={phase}"

        return _fetch_index_lock_blockers(
            poll_conn,
            index_name,
            pid=int(row["pid"]) if row.get("pid") else None,
        )


def _fetch_index_lock_blockers(conn, index_name: str, *, pid: int | None = None) -> str | None:
    params: list[Any] = [f"%{index_name}%"]
    pid_filter = ""
    if pid is not None:
        pid_filter = "AND blocked.pid = %s"
        params.append(pid)
    rows = _fetch_all(
        f"""
        SELECT
          blocked.pid AS blocked_pid,
          blocking.pid AS blocking_pid,
          blocking.state AS blocking_state,
          now() - blocking.query_start AS blocking_age,
          left(blocking.query, 100) AS blocking_query
        FROM pg_stat_activity blocked
        JOIN LATERAL unnest(pg_blocking_pids(blocked.pid)) AS bp(pid) ON TRUE
        JOIN pg_stat_activity blocking ON blocking.pid = bp.pid
        WHERE blocked.query ILIKE %s
          {pid_filter}
        LIMIT 3
        """,
        tuple(params),
        conn=conn,
    )
    if not rows:
        stale = _fetch_all(
            """
            SELECT pid, state, now() - query_start AS age, left(query, 100) AS query
            FROM pg_stat_activity
            WHERE datname = current_database()
              AND pid <> pg_backend_pid()
              AND (
                query ILIKE '%tmp_assembly_node_dedup%'
                OR (
                  query ILIKE '%CREATE INDEX%'
                  AND query ILIKE '%source_node%'
                  AND state = 'active'
                  AND pid <> COALESCE(%s, -1)
                )
              )
            ORDER BY query_start
            LIMIT 5
            """,
            (pid,),
            conn=conn,
        )
        if stale:
            parts = [
                f"pid={row['pid']} state={row['state']} age={row['age']} "
                f"query={row['query']!r}"
                for row in stale
            ]
            return "blocked by stale session(s): " + "; ".join(parts)
        return None
    parts = [
        f"blocked by pid={row['blocking_pid']} state={row['blocking_state']} "
        f"age={row['blocking_age']} query={row['blocking_query']!r}"
        for row in rows
    ]
    return "waiting for lock — " + "; ".join(parts)


def _create_index_concurrently(dsn: str, table: str, index_name: str) -> None:
    started = time.monotonic()
    error: list[BaseException] = []
    stop = threading.Event()

    def _progress_loop() -> None:
        while not stop.wait(5.0):
            elapsed = int(time.monotonic() - started)
            detail = _fetch_index_build_status(dsn, index_name)
            msg = detail or "waiting for lock or queue (no build progress yet)"
            _log_progress(f"backfill index {index_name} {msg} elapsed={elapsed}s")

    def _build() -> None:
        try:
            with psycopg.connect(dsn, autocommit=True) as build_conn:
                with build_conn.cursor() as cur:
                    cur.execute(
                        f"CREATE INDEX CONCURRENTLY IF NOT EXISTS {index_name} "
                        f"ON {table}(source_node_id)"
                    )
        except BaseException as exc:
            error.append(exc)

    progress_thread = threading.Thread(
        target=_progress_loop,
        name=f"index-progress-{index_name}",
        daemon=True,
    )
    build_thread = threading.Thread(target=_build, name=f"index-build-{index_name}", daemon=True)
    progress_thread.start()
    build_thread.start()
    build_thread.join()
    stop.set()
    progress_thread.join(timeout=1.0)
    if error:
        raise error[0]


def _drop_invalid_index_concurrently(dsn: str, index_name: str) -> None:
    with psycopg.connect(dsn, autocommit=True) as drop_conn:
        with drop_conn.cursor() as cur:
            cur.execute(f"DROP INDEX CONCURRENTLY IF EXISTS {index_name}")


def ensure_source_node_fk_indexes(*, conn=None) -> dict[str, str]:
    """Create missing source_node_id indexes via CONCURRENTLY (non-blocking, outside transactions)."""
    dsn = get_settings().database_dsn
    results: dict[str, str] = {}
    started = time.monotonic()
    _log_progress("ensure source_node_id indexes start (CONCURRENTLY)")

    def _process_table(table: str, index_name: str, check_conn) -> None:
        state = _index_state(check_conn, index_name)
        if state == "valid":
            results[index_name] = "present"
            _log_progress(f"ensure index {index_name} already present")
            return
        if state == "invalid":
            _log_progress(f"ensure index {index_name} invalid (interrupted build), dropping")
            _drop_invalid_index_concurrently(dsn, index_name)

        count_row = _fetch_one(f"SELECT COUNT(*) AS cnt FROM {table}", conn=check_conn)
        check_conn.commit()
        row_count = int(count_row["cnt"] or 0) if count_row else 0
        _log_progress(f"ensure index {index_name} CONCURRENTLY on {table} rows≈{row_count}")
        step_started = time.monotonic()
        _create_index_concurrently(dsn, table, index_name)
        results[index_name] = "created"
        _log_progress(
            f"ensure index {index_name} ready elapsed={int(time.monotonic() - step_started)}s"
        )

    if conn is not None:
        conn.commit()
        for table, index_name in _SOURCE_NODE_FK_INDEXES:
            _process_table(table, index_name, conn)
    else:
        with get_conn() as check_conn:
            for table, index_name in _SOURCE_NODE_FK_INDEXES:
                _process_table(table, index_name, check_conn)

    _log_progress(
        f"ensure source_node_id indexes done elapsed={int(time.monotonic() - started)}s "
        f"summary={results}"
    )
    return results


def _ensure_source_node_fk_indexes(conn) -> None:
    present = sum(1 for _, name in _SOURCE_NODE_FK_INDEXES if _index_state(conn, name) == "valid")
    if present == len(_SOURCE_NODE_FK_INDEXES):
        _log_progress("backfill phase 1/2 source_node_id indexes already present, skipping")
        return
    _log_progress(
        f"backfill phase 1/2 source_node_id indexes missing "
        f"({len(_SOURCE_NODE_FK_INDEXES) - present}/{len(_SOURCE_NODE_FK_INDEXES)}), "
        "creating CONCURRENTLY outside transaction"
    )
    ensure_source_node_fk_indexes(conn=conn)
    _log_progress("backfill phase 1/2 source_node_id indexes ready")


@dataclass
class FixStats:
    lynx_moved: int = 0
    atv_reclassified: int = 0
    brp_reclassified: int = 0
    model_families_merged: int = 0
    variants_moved: int = 0
    brand_aliases_removed: int = 0
    brands_hidden: int = 0
    parts_reassigned: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "lynx_moved": self.lynx_moved,
            "atv_reclassified": self.atv_reclassified,
            "brp_reclassified": self.brp_reclassified,
            "model_families_merged": self.model_families_merged,
            "variants_moved": self.variants_moved,
            "brand_aliases_removed": self.brand_aliases_removed,
            "brands_hidden": self.brands_hidden,
            "parts_reassigned": self.parts_reassigned,
        }


def _fetch_all(query: str, params: tuple[Any, ...] = (), *, conn=None) -> list[dict[str, Any]]:
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())
    with get_conn() as connection:
        with connection.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())


def _fetch_one(query: str, params: tuple[Any, ...] = (), *, conn=None) -> dict[str, Any] | None:
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()
    with get_conn() as connection:
        with connection.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()


def _source_id() -> int:
    return writer.source_id(SOURCE_CODE)


def diagnose() -> dict[str, Any]:
    sid = _source_id()
    return {
        "lynx_in_motorcycle": _fetch_all(
            """
            SELECT mf.id, mf.name, COUNT(vv.id) AS variant_count
            FROM oem_model_families mf
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            WHERE b.normalized_name = 'lynx' AND vt.code = 'motorcycle'
            GROUP BY mf.id, mf.name
            ORDER BY mf.name
            """
        ),
        "likely_misclassified_atv": _fetch_all(
            """
            SELECT b.name AS brand, mf.id, mf.name, vt.code AS vehicle_type, COUNT(vv.id) AS variants
            FROM oem_model_families mf
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            WHERE vt.code = 'atv'
              AND (
                b.normalized_name IN ('lynx', 'ski-doo')
                OR mf.name ILIKE %s
                OR EXISTS (
                  SELECT 1 FROM oem_vehicle_variants vv2
                  WHERE vv2.model_family_id = mf.id
                    AND vv2.source_designation ~* '(skandic|ski[- ]doo|lynx|snowmobile)'
                )
              )
            GROUP BY b.name, mf.id, mf.name, vt.code
            ORDER BY b.name, mf.name
            """,
            ("%skandic%",),
        ),
        "brp_family_brands": _fetch_all(
            """
            SELECT b.normalized_name, b.name, vt.code, COUNT(DISTINCT mf.id) AS models, COUNT(DISTINCT a.id) AS assemblies
            FROM oem_brands b
            JOIN oem_model_families mf ON mf.brand_id = b.id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
            WHERE b.normalized_name IN ('brp', 'sea-doo', 'ski-doo', 'can-am', 'lynx')
            GROUP BY b.normalized_name, b.name, vt.code
            ORDER BY b.name, vt.code
            """
        ),
        "aria_slug_collisions": _fetch_all(
            """
            SELECT sn.aria, sn.arib, COUNT(*) AS node_count,
                   COUNT(DISTINCT sn.slug) AS slug_count,
                   array_agg(DISTINCT LEFT(COALESCE(sn.slug, ''), 100)) AS sample_slugs
            FROM oem_source_nodes sn
            WHERE sn.source_id = %s
              AND sn.node_type = 'assembly'
              AND sn.aria IS NOT NULL
            GROUP BY sn.aria, sn.arib
            HAVING COUNT(DISTINCT sn.slug) > 1
            ORDER BY node_count DESC
            LIMIT 200
            """,
            (sid,),
        ),
        "thin_variants": list_thin_variants(limit=200),
        "duplicate_variants": list_duplicate_variant_groups(limit=200),
        "recrawl_plan": build_recrawl_plan(limit=200),
    }


def list_thin_variants(*, limit: int = 500, min_reference_assemblies: int = 5, ratio: float = 0.3) -> list[dict[str, Any]]:
    rows = _fetch_all(
        """
        WITH variant_counts AS (
          SELECT
            vv.id AS variant_id,
            vv.model_family_id,
            vv.year_from,
            vv.source_designation,
            vv.variant_section,
            mf.name AS model_name,
            mf.normalized_name AS model_normalized,
            b.name AS brand_name,
            b.normalized_name AS brand_normalized,
            vt.code AS vehicle_type,
            sn.arib,
            COUNT(a.id) AS assembly_count
          FROM oem_vehicle_variants vv
          JOIN oem_model_families mf ON mf.id = vv.model_family_id
          JOIN oem_brands b ON b.id = mf.brand_id
          JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
          LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
          LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
          LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
          GROUP BY vv.id, vv.model_family_id, vv.year_from, vv.source_designation, vv.variant_section,
                   mf.name, mf.normalized_name, b.name, b.normalized_name, vt.code, sn.arib
        ),
        model_stats AS (
          SELECT model_family_id, MAX(assembly_count) AS max_assemblies
          FROM variant_counts
          GROUP BY model_family_id
        )
        SELECT vc.*
        FROM variant_counts vc
        JOIN model_stats ms ON ms.model_family_id = vc.model_family_id
        WHERE ms.max_assemblies >= %s
          AND vc.assembly_count < GREATEST(1, (ms.max_assemblies * %s)::int)
        ORDER BY ms.max_assemblies DESC, vc.assembly_count ASC, vc.brand_name, vc.model_name, vc.year_from
        LIMIT %s
        """,
        (min_reference_assemblies, ratio, limit),
    )
    for row in rows:
        row["crawl_arib"] = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
    return rows


def list_under_crawled_variants(*, max_assemblies: int = 15, limit: int = 10000) -> list[dict[str, Any]]:
    """Variants with fewer assemblies than expected (absolute threshold, good for gap fill)."""
    rows = _fetch_all(
        """
        SELECT
          vv.id AS variant_id,
          vv.model_family_id,
          vv.year_from,
          vv.source_designation,
          vv.variant_section,
          mf.name AS model_name,
          mf.normalized_name AS model_normalized,
          b.name AS brand_name,
          b.normalized_name AS brand_normalized,
          vt.code AS vehicle_type,
          sn.arib,
          COUNT(a.id) AS assembly_count
        FROM oem_vehicle_variants vv
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
        LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
        LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
        WHERE b.normalized_name <> ALL(%s)
        GROUP BY vv.id, vv.model_family_id, vv.year_from, vv.source_designation, vv.variant_section,
                 mf.name, mf.normalized_name, b.name, b.normalized_name, vt.code, sn.arib
        HAVING COUNT(a.id) <= %s
        ORDER BY COUNT(a.id) ASC, b.name, mf.name, vv.year_from
        LIMIT %s
        """,
        (list(HIDDEN_CANONICAL_BRANDS), max_assemblies, limit),
    )
    for row in rows:
        row["crawl_arib"] = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
    return rows


def build_under_crawled_recrawl_plan(*, max_assemblies: int = 15, limit: int = 10000) -> list[dict[str, Any]]:
    """Targeted repair for variants with very few assemblies (broader than list_thin_variants)."""
    under = list_under_crawled_variants(max_assemblies=max_assemblies, limit=limit)
    variant_ids = [int(row["variant_id"]) for row in under if row.get("variant_id")]
    roots = variant_repair_roots(variant_ids)
    by_variant = {root["variant_id"]: root for root in roots}
    plan: list[dict[str, Any]] = []
    for row in under:
        root = by_variant.get(int(row["variant_id"]))
        if not root:
            continue
        plan.append(
            {
                "variant_id": row["variant_id"],
                "model_family_id": row.get("model_family_id"),
                "year_from": row.get("year_from"),
                "brand_name": row.get("brand_name"),
                "model_name": row.get("model_name"),
                "assembly_count": row.get("assembly_count"),
                "arib": root.get("arib"),
                "path": " / ".join(root.get("path") or []),
            }
        )
    return plan


def _exec(query: str, params: tuple[Any, ...] = (), *, conn) -> None:
    with conn.cursor() as cur:
        cur.execute(query, params)


def _merge_source_node_into(*, canonical_id: int, duplicate_id: int, conn) -> None:
    if canonical_id == duplicate_id:
        return
    for query in (
        """
        UPDATE oem_assemblies
        SET source_node_id = %s, updated_at = now()
        WHERE source_node_id = %s
        """,
        """
        UPDATE oem_diagrams
        SET source_node_id = %s, updated_at = now()
        WHERE source_node_id = %s
        """,
        """
        UPDATE oem_assembly_parts
        SET source_node_id = %s, updated_at = now()
        WHERE source_node_id = %s
        """,
        """
        UPDATE oem_raw_snapshots
        SET source_node_id = %s
        WHERE source_node_id = %s
        """,
        """
        UPDATE oem_source_nodes
        SET parent_id = %s, updated_at = now()
        WHERE parent_id = %s
        """,
    ):
        _exec(query, (canonical_id, duplicate_id), conn=conn)
    _exec(
        """
        DELETE FROM oem_source_node_links dup
        WHERE dup.source_node_id = %s
          AND EXISTS (
            SELECT 1
            FROM oem_source_node_links keep
            WHERE keep.source_node_id = %s
              AND keep.entity_type = dup.entity_type
              AND keep.entity_id = dup.entity_id
          )
        """,
        (duplicate_id, canonical_id),
        conn=conn,
    )
    _exec(
        """
        UPDATE oem_source_node_links
        SET source_node_id = %s
        WHERE source_node_id = %s
        """,
        (canonical_id, duplicate_id),
        conn=conn,
    )
    _exec("DELETE FROM oem_source_nodes WHERE id = %s", (duplicate_id,), conn=conn)


def _collision_stats(source_id: int, *, conn=None) -> dict[str, int]:
    canon_arib = "CASE WHEN UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA') THEN 'BRP' ELSE UPPER(sn.arib) END"
    row = _fetch_one(
        f"""
        SELECT
          COUNT(*) AS collision_groups,
          COALESCE(SUM(cnt - 1), 0) AS duplicate_nodes
        FROM (
          SELECT COUNT(*) AS cnt
          FROM oem_source_nodes sn
          WHERE sn.source_id = %s
            AND sn.node_type = 'assembly'
            AND sn.arib IS NOT NULL
            AND sn.aria IS NOT NULL
            AND sn.aria <> ''
          GROUP BY {canon_arib}, sn.aria
          HAVING COUNT(*) > 1
        ) grouped
        """,
        (source_id,),
        conn=conn,
    ) or {"collision_groups": 0, "duplicate_nodes": 0}
    return {
        "collision_groups": int(row["collision_groups"] or 0),
        "duplicate_nodes": int(row["duplicate_nodes"] or 0),
    }


def _bulk_deduplicate_assembly_source_nodes(source_id: int, *, conn) -> dict[str, int]:
    """Merge duplicate assembly source nodes in set-based SQL (not row-by-row)."""
    _ensure_source_node_fk_indexes(conn)
    _log_progress("backfill phase 1/2 building duplicate map (SQL temp table)")
    batch_size = BACKFILL_DEDUP_BATCH_SIZE
    with _heartbeat("phase 1/2 building duplicate map"):
        _exec(
            f"""
            CREATE TEMP TABLE tmp_assembly_node_dedup ON COMMIT PRESERVE ROWS AS
            SELECT
              sub.*,
              ((ROW_NUMBER() OVER (ORDER BY sub.duplicate_id) - 1) / {batch_size})::int AS batch_no
            FROM (
              WITH ranked AS (
                SELECT
                  sn.id,
                  CASE
                    WHEN UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA') THEN 'BRP'
                    ELSE UPPER(sn.arib)
                  END AS canon_arib,
                  sn.aria,
                  ROW_NUMBER() OVER (
                    PARTITION BY
                      CASE
                        WHEN UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA') THEN 'BRP'
                        ELSE UPPER(sn.arib)
                      END,
                      sn.aria
                    ORDER BY
                      CASE
                        WHEN UPPER(sn.arib) = 'BRP' THEN 0
                        WHEN UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA') THEN 1
                        ELSE 2
                      END,
                      sn.id
                  ) AS rn
                FROM oem_source_nodes sn
                WHERE sn.source_id = %s
                  AND sn.node_type = 'assembly'
                  AND sn.arib IS NOT NULL
                  AND sn.aria IS NOT NULL
                  AND sn.aria <> ''
              ),
              canonical AS (
                SELECT id AS canonical_id, canon_arib, aria
                FROM ranked
                WHERE rn = 1
              )
              SELECT
                r.id AS duplicate_id,
                c.canonical_id,
                c.canon_arib,
                c.aria
              FROM ranked r
              JOIN canonical c ON c.canon_arib = r.canon_arib AND c.aria = r.aria
              WHERE r.rn > 1
            ) sub
            """,
            (source_id,),
            conn=conn,
        )
    _exec("CREATE INDEX ON tmp_assembly_node_dedup (duplicate_id)", conn=conn)
    _exec("CREATE INDEX ON tmp_assembly_node_dedup (canonical_id)", conn=conn)
    _exec("CREATE INDEX ON tmp_assembly_node_dedup (batch_no)", conn=conn)

    counts = _fetch_one(
        """
        SELECT
          COUNT(*) AS duplicate_nodes,
          COUNT(DISTINCT (canon_arib, aria)) AS collision_groups
        FROM tmp_assembly_node_dedup
        """,
        conn=conn,
    ) or {"duplicate_nodes": 0, "collision_groups": 0}
    duplicate_nodes = int(counts["duplicate_nodes"] or 0)
    collision_groups = int(counts["collision_groups"] or 0)
    _log_progress(
        f"backfill phase 1/2 duplicate map ready "
        f"groups={collision_groups} duplicate_nodes={duplicate_nodes}"
    )
    if duplicate_nodes == 0:
        return {"collision_groups": 0, "merged_duplicates": 0, "canonical_updates": 0}

    steps = (
        ("oem_assemblies", """
            UPDATE oem_assemblies target
            SET source_node_id = d.canonical_id, updated_at = now()
            FROM tmp_assembly_node_dedup d
            WHERE target.source_node_id = d.duplicate_id
        """),
        ("oem_diagrams", """
            UPDATE oem_diagrams target
            SET source_node_id = d.canonical_id, updated_at = now()
            FROM tmp_assembly_node_dedup d
            WHERE target.source_node_id = d.duplicate_id
        """),
        ("oem_assembly_parts", """
            UPDATE oem_assembly_parts target
            SET source_node_id = d.canonical_id, updated_at = now()
            FROM tmp_assembly_node_dedup d
            WHERE target.source_node_id = d.duplicate_id
        """),
        ("oem_raw_snapshots", """
            UPDATE oem_raw_snapshots target
            SET source_node_id = d.canonical_id
            FROM tmp_assembly_node_dedup d
            WHERE target.source_node_id = d.duplicate_id
        """),
        ("oem_source_nodes.parent_id", """
            UPDATE oem_source_nodes target
            SET parent_id = d.canonical_id, updated_at = now()
            FROM tmp_assembly_node_dedup d
            WHERE target.parent_id = d.duplicate_id
        """),
    )
    for label, query in steps:
        _run_batched_dedup_step(conn, label, query)

    _log_progress("backfill phase 1/2 deduplicating oem_source_node_links...")
    links_started = time.monotonic()
    max_batch, total_dupes = _dedup_batch_stats(conn)
    batch_count = max_batch + 1
    removed_links = 0
    moved_links = 0
    for batch_no in range(batch_count):
        batch_started = time.monotonic()
        with _heartbeat(f"phase 1/2 links batch {batch_no + 1}/{batch_count}"):
            removed_links += _exec_rowcount(
                """
                DELETE FROM oem_source_node_links dup
                USING tmp_assembly_node_dedup d
                WHERE dup.source_node_id = d.duplicate_id
                  AND d.batch_no = %s
                  AND EXISTS (
                    SELECT 1
                    FROM oem_source_node_links keep
                    WHERE keep.source_node_id = d.canonical_id
                      AND keep.entity_type = dup.entity_type
                      AND keep.entity_id = dup.entity_id
                  )
                """,
                (batch_no,),
                conn=conn,
            )
            moved_links += _exec_rowcount(
                """
                UPDATE oem_source_node_links target
                SET source_node_id = d.canonical_id
                FROM tmp_assembly_node_dedup d
                WHERE target.source_node_id = d.duplicate_id
                  AND d.batch_no = %s
                """,
                (batch_no,),
                conn=conn,
            )
        dupes_done = min((batch_no + 1) * BACKFILL_DEDUP_BATCH_SIZE, total_dupes)
        pct = (100.0 * dupes_done / total_dupes) if total_dupes else 100.0
        _log_progress(
            f"backfill phase 1/2 links batch {batch_no + 1}/{batch_count} ({pct:.1f}%) "
            f"removed={removed_links} moved={moved_links} "
            f"batch_elapsed={int(time.monotonic() - batch_started)}s "
            f"step_elapsed={int(time.monotonic() - links_started)}s"
        )
    _log_progress(
        f"backfill phase 1/2 links done removed={removed_links} moved={moved_links} "
        f"elapsed={int(time.monotonic() - links_started)}s"
    )

    _log_progress("backfill phase 1/2 committing FK repoint before duplicate deletes...")
    conn.commit()
    _log_progress("backfill phase 1/2 FK repoint committed")

    deleted_nodes = _run_batched_dedup_step(
        conn,
        "delete duplicate source nodes",
        """
            DELETE FROM oem_source_nodes sn
            USING tmp_assembly_node_dedup d
            WHERE sn.id = d.duplicate_id
        """,
    )

    _log_progress("backfill phase 1/2 normalizing canonical node keys...")
    key_started = time.monotonic()
    canonical_updates = 0
    for batch_no in range(batch_count):
        batch_started = time.monotonic()
        with _heartbeat(f"phase 1/2 canonical keys batch {batch_no + 1}/{batch_count}"):
            batch_rows = _exec_rowcount(
                """
                UPDATE oem_source_nodes sn
                SET
                  arib = d.canon_arib,
                  external_id = d.canon_arib || ':' || d.aria,
                  last_seen_at = NOW(),
                  updated_at = NOW()
                FROM (
                  SELECT DISTINCT ON (canonical_id) canonical_id, canon_arib, aria
                  FROM tmp_assembly_node_dedup
                  WHERE batch_no = %s
                  ORDER BY canonical_id
                ) d
                WHERE sn.id = d.canonical_id
                """,
                (batch_no,),
                conn=conn,
            )
        canonical_updates += batch_rows
        dupes_done = min((batch_no + 1) * BACKFILL_DEDUP_BATCH_SIZE, total_dupes)
        pct = (100.0 * dupes_done / total_dupes) if total_dupes else 100.0
        _log_progress(
            f"backfill phase 1/2 canonical keys batch {batch_no + 1}/{batch_count} ({pct:.1f}%) "
            f"rows={batch_rows} total={canonical_updates} "
            f"batch_elapsed={int(time.monotonic() - batch_started)}s "
            f"step_elapsed={int(time.monotonic() - key_started)}s"
        )
    _log_progress(
        f"backfill phase 1/2 canonical keys done updated={canonical_updates} "
        f"elapsed={int(time.monotonic() - key_started)}s"
    )

    _exec("DROP TABLE IF EXISTS tmp_assembly_node_dedup", conn=conn)

    return {
        "collision_groups": collision_groups,
        "merged_duplicates": duplicate_nodes,
        "canonical_updates": canonical_updates,
    }


def backfill_assembly_external_ids(*, dry_run: bool = True) -> dict[str, Any]:
    """Align oem_source_nodes.external_id with canonical arib:aria (fallback arib:slug)."""
    sid = _source_id()
    started_mono = time.monotonic()
    canon_arib_sql = "CASE WHEN UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA') THEN 'BRP' ELSE UPPER(sn.arib) END"

    stats = _fetch_one(
        f"""
        SELECT
          COUNT(*) AS total,
          COUNT(*) FILTER (
            WHERE external_id IS DISTINCT FROM CASE
              WHEN aria IS NOT NULL AND aria <> '' THEN ({canon_arib_sql.replace('sn.', '')}) || ':' || aria
              WHEN slug IS NOT NULL AND slug <> '' THEN ({canon_arib_sql.replace('sn.', '')}) || ':' || slug
              ELSE external_id
            END
            OR UPPER(COALESCE(arib, '')) IN ('BRP_SKI', 'BRP_SEA')
          ) AS pending
        FROM oem_source_nodes sn
        WHERE source_id = %s
          AND node_type = 'assembly'
          AND arib IS NOT NULL
        """,
        (sid,),
    ) or {"total": 0, "pending": 0}

    collision = _collision_stats(sid)

    _log_progress(
        f"backfill assembly keys start dry_run={dry_run} "
        f"pending={int(stats['pending'] or 0)} collision_groups={collision['collision_groups']} "
        f"duplicate_nodes={collision['duplicate_nodes']} key_format=canonical arib:aria"
    )

    merges = 0
    updated = 0
    if not dry_run:
        with get_conn() as conn:
            dedupe = _bulk_deduplicate_assembly_source_nodes(sid, conn=conn)
            merges = int(dedupe["merged_duplicates"])
            updated = int(dedupe["canonical_updates"])
            conn.commit()
            _log_progress(
                f"backfill phase 1/2 done merged={merges} "
                f"elapsed={int(time.monotonic() - started_mono)}s"
            )

            phase2_started = time.monotonic()
            _log_progress("backfill phase 2/2 bulk update non-colliding assembly keys (aria rows)")
            bulk = _fetch_one(
                f"""
                WITH updated AS (
                  UPDATE oem_source_nodes sn
                  SET arib = {canon_arib_sql},
                      external_id = {canon_arib_sql} || ':' || sn.aria,
                      last_seen_at = NOW(),
                      updated_at = NOW()
                  WHERE sn.source_id = %s
                    AND sn.node_type = 'assembly'
                    AND sn.arib IS NOT NULL
                    AND sn.aria IS NOT NULL
                    AND sn.aria <> ''
                    AND (
                      sn.external_id IS DISTINCT FROM ({canon_arib_sql} || ':' || sn.aria)
                      OR UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA')
                    )
                    AND NOT EXISTS (
                      SELECT 1
                      FROM oem_source_nodes other
                      WHERE other.source_id = sn.source_id
                        AND other.id <> sn.id
                        AND other.aria = sn.aria
                        AND other.aria IS NOT NULL
                        AND other.aria <> ''
                        AND {canon_arib_sql.replace('sn.', 'other.')} = {canon_arib_sql}
                    )
                  RETURNING id
                )
                SELECT COUNT(*) AS cnt FROM updated
                """,
                (sid,),
                conn=conn,
            )
            updated += int(bulk["cnt"] or 0) if bulk else 0
            _log_progress(
                f"backfill phase 2 aria rows updated={int(bulk['cnt'] or 0) if bulk else 0} "
                f"elapsed={int(time.monotonic() - phase2_started)}s"
            )

            slug_started = time.monotonic()
            _log_progress("backfill phase 2/2 bulk update non-colliding assembly keys (slug rows)")
            slug_bulk = _fetch_one(
                f"""
                WITH updated AS (
                  UPDATE oem_source_nodes sn
                  SET arib = {canon_arib_sql},
                      external_id = {canon_arib_sql} || ':' || sn.slug,
                      last_seen_at = NOW(),
                      updated_at = NOW()
                  WHERE sn.source_id = %s
                    AND sn.node_type = 'assembly'
                    AND sn.arib IS NOT NULL
                    AND (sn.aria IS NULL OR sn.aria = '')
                    AND sn.slug IS NOT NULL
                    AND sn.slug <> ''
                    AND (
                      sn.external_id IS DISTINCT FROM ({canon_arib_sql} || ':' || sn.slug)
                      OR UPPER(sn.arib) IN ('BRP_SKI', 'BRP_SEA')
                    )
                    AND NOT EXISTS (
                      SELECT 1
                      FROM oem_source_nodes other
                      WHERE other.source_id = sn.source_id
                        AND other.id <> sn.id
                        AND other.slug = sn.slug
                        AND (other.aria IS NULL OR other.aria = '')
                        AND {canon_arib_sql.replace('sn.', 'other.')} = {canon_arib_sql}
                    )
                  RETURNING id
                )
                SELECT COUNT(*) AS cnt FROM updated
                """,
                (sid,),
                conn=conn,
            )
            updated += int(slug_bulk["cnt"] or 0) if slug_bulk else 0
            _log_progress(
                f"backfill phase 2 slug rows updated={int(slug_bulk['cnt'] or 0) if slug_bulk else 0} "
                f"elapsed={int(time.monotonic() - slug_started)}s"
            )
            _log_progress(f"backfill phase 2 done total_updated={updated} elapsed={int(time.monotonic() - started_mono)}s")
            conn.commit()
    else:
        merges = collision["duplicate_nodes"]
        updated = int(stats["pending"] or 0)

    _log_progress(
        f"backfill assembly keys done dry_run={dry_run} "
        f"merged_duplicates={merges} updated={updated} elapsed={int(time.monotonic() - started_mono)}s"
    )

    return {
        "dry_run": dry_run,
        "pending_updates": int(stats["pending"] or 0),
        "collision_groups": collision["collision_groups"],
        "merged_duplicates": merges,
        "updated": updated,
        "key_format": "canonical arib:aria",
    }


def _repoint_source_node_fks_from_map(
    conn,
    *,
    map_table: str,
    wrong_id_col: str = "wrong_id",
    canonical_id_col: str = "canonical_id",
) -> dict[str, int]:
    """Move FK references from duplicate source nodes onto canonical rows."""
    counts: dict[str, int] = {}
    steps = (
        ("oem_assemblies", f"""
            UPDATE oem_assemblies target
            SET source_node_id = m.{canonical_id_col}, updated_at = now()
            FROM {map_table} m
            WHERE target.source_node_id = m.{wrong_id_col}
              AND m.{canonical_id_col} IS NOT NULL
        """),
        ("oem_diagrams", f"""
            UPDATE oem_diagrams target
            SET source_node_id = m.{canonical_id_col}, updated_at = now()
            FROM {map_table} m
            WHERE target.source_node_id = m.{wrong_id_col}
              AND m.{canonical_id_col} IS NOT NULL
        """),
        ("oem_assembly_parts", f"""
            UPDATE oem_assembly_parts target
            SET source_node_id = m.{canonical_id_col}, updated_at = now()
            FROM {map_table} m
            WHERE target.source_node_id = m.{wrong_id_col}
              AND m.{canonical_id_col} IS NOT NULL
        """),
        ("oem_raw_snapshots", f"""
            UPDATE oem_raw_snapshots target
            SET source_node_id = m.{canonical_id_col}
            FROM {map_table} m
            WHERE target.source_node_id = m.{wrong_id_col}
              AND m.{canonical_id_col} IS NOT NULL
        """),
        ("oem_source_nodes.parent_id", f"""
            UPDATE oem_source_nodes target
            SET parent_id = m.{canonical_id_col}, updated_at = now()
            FROM {map_table} m
            WHERE target.parent_id = m.{wrong_id_col}
              AND m.{canonical_id_col} IS NOT NULL
        """),
    )
    for label, query in steps:
        counts[label] = _exec_rowcount(query, conn=conn)

    counts["source_node_links_removed"] = _exec_rowcount(
        f"""
        DELETE FROM oem_source_node_links dup
        USING {map_table} m
        WHERE dup.source_node_id = m.{wrong_id_col}
          AND m.{canonical_id_col} IS NOT NULL
          AND EXISTS (
            SELECT 1
            FROM oem_source_node_links keep
            WHERE keep.source_node_id = m.{canonical_id_col}
              AND keep.entity_type = dup.entity_type
              AND keep.entity_id = dup.entity_id
          )
        """,
        conn=conn,
    )
    counts["source_node_links_moved"] = _exec_rowcount(
        f"""
        UPDATE oem_source_node_links target
        SET source_node_id = m.{canonical_id_col}
        FROM {map_table} m
        WHERE target.source_node_id = m.{wrong_id_col}
          AND m.{canonical_id_col} IS NOT NULL
        """,
        conn=conn,
    )
    return counts


def fix_assembly_arib_from_variant_brand(*, dry_run: bool = True) -> dict[str, Any]:
    """Set assembly source_node.arib from variant brand (fixes HUM vs KTM key mismatches)."""
    sid = _source_id()
    expected_arib_sql = """
        CASE
          WHEN b.normalized_name = 'husqvarna' THEN 'HUM'
          WHEN b.normalized_name = 'ktm' THEN 'KTM'
          WHEN b.normalized_name = 'lynx' THEN 'LNX'
          WHEN b.normalized_name IN ('can-am', 'sea-doo', 'ski-doo', 'brp') THEN 'BRP'
          WHEN UPPER(COALESCE(sn.arib, '')) IN ('BRP_SKI', 'BRP_SEA') THEN 'BRP'
          ELSE UPPER(COALESCE(sn.arib, ''))
        END
    """
    expected_external_id_sql = f"""
        CASE
          WHEN sn.aria IS NOT NULL AND sn.aria <> '' THEN ({expected_arib_sql}) || ':' || sn.aria
          WHEN sn.slug IS NOT NULL AND sn.slug <> '' THEN ({expected_arib_sql}) || ':' || sn.slug
          ELSE NULL
        END
    """
    mismatch_from = """
        FROM oem_source_nodes sn
        JOIN oem_assemblies a ON a.source_node_id = sn.id
        JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        WHERE sn.source_id = %s
          AND sn.node_type = 'assembly'
          AND UPPER(COALESCE(sn.arib, '')) IS DISTINCT FROM ({expected_arib_sql})
    """.format(expected_arib_sql=expected_arib_sql)

    _log_progress(f"fix assembly arib from brand start dry_run={dry_run}")
    repointed = 0
    deleted = 0
    updated = 0
    repoint_counts: dict[str, int] = {}

    with get_conn() as conn:
        try:
            _exec("DROP TABLE IF EXISTS tmp_arib_fix", conn=conn)
            _exec(
                f"""
                CREATE TEMP TABLE tmp_arib_fix ON COMMIT PRESERVE ROWS AS
                WITH mismatched AS (
                  SELECT DISTINCT
                    sn.id AS wrong_id,
                    ({expected_arib_sql}) AS expected_arib,
                    ({expected_external_id_sql}) AS expected_external_id
                  {mismatch_from}
                )
                SELECT
                  m.wrong_id,
                  m.expected_arib,
                  m.expected_external_id,
                  canon.id AS canonical_id
                FROM mismatched m
                LEFT JOIN oem_source_nodes canon
                  ON canon.source_id = %s
                 AND canon.id <> m.wrong_id
                 AND m.expected_external_id IS NOT NULL
                 AND canon.external_id = m.expected_external_id
                """,
                (sid, sid),
                conn=conn,
            )
            _exec("CREATE INDEX ON tmp_arib_fix (wrong_id)", conn=conn)
            _exec(
                "CREATE INDEX ON tmp_arib_fix (canonical_id) WHERE canonical_id IS NOT NULL",
                conn=conn,
            )

            plan = _fetch_one(
                """
                SELECT
                  COUNT(*) AS pending,
                  COUNT(*) FILTER (WHERE canonical_id IS NOT NULL) AS to_repoint,
                  COUNT(*) FILTER (WHERE canonical_id IS NULL) AS to_update
                FROM tmp_arib_fix
                """,
                conn=conn,
            ) or {"pending": 0, "to_repoint": 0, "to_update": 0}
            pending = int(plan["pending"] or 0)
            to_repoint = int(plan["to_repoint"] or 0)
            to_update = int(plan["to_update"] or 0)
            _log_progress(
                f"fix assembly arib from brand plan pending={pending} "
                f"repoint={to_repoint} direct_update={to_update}"
            )

            if dry_run:
                conn.rollback()
                return {
                    "dry_run": True,
                    "pending": pending,
                    "would_repoint": to_repoint,
                    "would_update": to_update,
                    "updated": to_update,
                }

            if to_repoint:
                _log_progress(
                    f"fix assembly arib from brand repointing {to_repoint} nodes "
                    f"with existing canonical external_id"
                )
                repoint_counts = _repoint_source_node_fks_from_map(conn, map_table="tmp_arib_fix")
                deleted = _exec_rowcount(
                    """
                    DELETE FROM oem_source_nodes sn
                    USING tmp_arib_fix m
                    WHERE sn.id = m.wrong_id
                      AND m.canonical_id IS NOT NULL
                    """,
                    conn=conn,
                )
                repointed = to_repoint

            updated = _exec_rowcount(
                f"""
                UPDATE oem_source_nodes sn
                SET
                  arib = m.expected_arib,
                  external_id = m.expected_external_id,
                  updated_at = NOW()
                FROM tmp_arib_fix m
                WHERE sn.id = m.wrong_id
                  AND m.canonical_id IS NULL
                  AND m.expected_external_id IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1
                    FROM oem_source_nodes other
                    WHERE other.source_id = sn.source_id
                      AND other.external_id = m.expected_external_id
                      AND other.id <> sn.id
                  )
                """,
                conn=conn,
            )

            slug_updated = _exec_rowcount(
                f"""
                UPDATE oem_source_nodes sn
                SET
                  arib = m.expected_arib,
                  updated_at = NOW()
                FROM tmp_arib_fix m
                WHERE sn.id = m.wrong_id
                  AND m.canonical_id IS NULL
                  AND m.expected_external_id IS NULL
                """,
                conn=conn,
            )
            updated += slug_updated

            conn.commit()
        except Exception:
            conn.rollback()
            raise

    _log_progress(
        f"fix assembly arib from brand done dry_run={dry_run} "
        f"repointed={repointed} deleted={deleted} updated={updated}"
    )
    return {
        "dry_run": dry_run,
        "pending": pending,
        "repointed": repointed,
        "deleted_duplicate_nodes": deleted,
        "updated": updated,
        "repoint_fk_counts": repoint_counts,
    }


def build_recrawl_plan(*, limit: int = 500) -> list[dict[str, Any]]:
    thin = list_thin_variants(limit=limit)
    grouped: dict[tuple[str, int | None], dict[str, Any]] = {}
    for row in thin:
        arib = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
        year = row.get("year_from")
        key = (arib, year)
        bucket = grouped.setdefault(
            key,
            {
                "arib": arib,
                "year": year,
                "brand_name": row.get("brand_name"),
                "thin_variant_count": 0,
                "sample_model": row.get("model_name"),
                "sample_assemblies": row.get("assembly_count"),
            },
        )
        bucket["thin_variant_count"] += 1
    return sorted(grouped.values(), key=lambda item: (item["arib"], item["year"] or 0))


def build_variant_recrawl_plan(*, limit: int = 500) -> list[dict[str, Any]]:
    """One repair target per thin variant (deduped by source-node root)."""
    thin = list_thin_variants(limit=limit)
    variant_ids = [int(row["variant_id"]) for row in thin if row.get("variant_id")]
    roots = variant_repair_roots(variant_ids)
    by_variant = {root["variant_id"]: root for root in roots}
    plan: list[dict[str, Any]] = []
    for row in thin:
        root = by_variant.get(int(row["variant_id"]))
        if not root:
            continue
        plan.append(
            {
                "variant_id": row["variant_id"],
                "model_family_id": row.get("model_family_id"),
                "year_from": row.get("year_from"),
                "brand_name": row.get("brand_name"),
                "model_name": row.get("model_name"),
                "assembly_count": row.get("assembly_count"),
                "arib": root.get("arib"),
                "path": " / ".join(root.get("path") or []),
            }
        )
    return plan


def _source_node_chain(source_node_id: int, *, conn=None) -> list[dict[str, Any]]:
    return _fetch_all(
        """
        WITH RECURSIVE chain AS (
          SELECT id, parent_id, title, arib, aria, slug, 0 AS depth
          FROM oem_source_nodes
          WHERE id = %s
          UNION ALL
          SELECT sn.id, sn.parent_id, sn.title, sn.arib, sn.aria, sn.slug, chain.depth + 1
          FROM oem_source_nodes sn
          JOIN chain ON sn.id = chain.parent_id
        )
        SELECT id, parent_id, title, arib, aria, slug, depth
        FROM chain
        ORDER BY depth ASC
        """,
        (source_node_id,),
        conn=conn,
    )


def variant_repair_roots(variant_ids: list[int]) -> list[dict[str, Any]]:
    """Map variant IDs to ARI subtree roots for targeted re-crawl."""
    roots: list[dict[str, Any]] = []
    seen: set[tuple[str, str | None]] = set()
    for variant_id in variant_ids:
        row = _fetch_one(
            """
            SELECT sn.id, sn.arib, sn.aria, sn.slug, sn.title, sn.parent_id,
                   b.normalized_name AS brand_normalized
            FROM oem_vehicle_variants vv
            JOIN oem_model_families mf ON mf.id = vv.model_family_id
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_source_node_links snl
              ON snl.entity_type = 'vehicle_variant'
             AND snl.entity_id = vv.id
            JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
            WHERE vv.id = %s
              AND sn.node_type <> 'assembly'
            ORDER BY LENGTH(COALESCE(sn.slug, '')) DESC, sn.id DESC
            LIMIT 1
            """,
            (variant_id,),
        )
        if not row or not row.get("aria"):
            assembly_parent = _fetch_one(
                """
                SELECT sn.id, sn.arib, sn.aria, sn.slug, sn.title, sn.parent_id
                FROM oem_assemblies a
                JOIN oem_source_nodes sn ON sn.id = a.source_node_id
                WHERE a.vehicle_variant_id = %s
                ORDER BY a.id ASC
                LIMIT 1
                """,
                (variant_id,),
            )
            if assembly_parent and assembly_parent.get("parent_id"):
                row = _fetch_one(
                    """
                    SELECT id, arib, aria, slug, title, parent_id
                    FROM oem_source_nodes
                    WHERE id = %s
                    """,
                    (assembly_parent["parent_id"],),
                )
        if not row or not row.get("aria"):
            continue
        dedupe_key = (row["arib"], row["aria"])
        if dedupe_key in seen:
            continue
        seen.add(dedupe_key)
        chain = _source_node_chain(row["id"])
        if not chain:
            continue
        path = [item["title"] for item in reversed(chain) if item.get("title")]
        crawl_arib = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
        roots.append(
            {
                "variant_id": variant_id,
                "title": row["title"],
                "arib": crawl_arib,
                "legacy_arib": row["arib"],
                "aria": row["aria"],
                "slug": row.get("slug"),
                "parent_id": row["parent_id"],
                "depth": len(chain) - 1,
                "path": path,
            }
        )
    return roots

def _category_titles_for_model_family(model_family_id: int, *, conn=None) -> str:
    """Walk source-node parents — picks up Remotors folders like 'Can-Am ATV'."""
    anchor_sql = """
        WITH RECURSIVE chain AS (
          SELECT id, parent_id, title, depth FROM (
            SELECT sn.id, sn.parent_id, sn.title, 0 AS depth
            FROM oem_source_nodes sn
            JOIN oem_source_node_links snl
              ON snl.source_node_id = sn.id
             AND snl.entity_type = 'vehicle_variant'
            JOIN oem_vehicle_variants vv ON vv.id = snl.entity_id
            WHERE vv.model_family_id = %s
            LIMIT 1
          ) seed
          UNION ALL
          SELECT sn.id, sn.parent_id, sn.title, chain.depth + 1
          FROM oem_source_nodes sn
          JOIN chain ON sn.id = chain.parent_id
        )
        SELECT title FROM chain ORDER BY depth DESC
    """
    rows = _fetch_all(anchor_sql, (model_family_id,), conn=conn)
    if not rows:
        rows = _fetch_all(
            """
            WITH RECURSIVE chain AS (
              SELECT id, parent_id, title, depth FROM (
                SELECT sn.id, sn.parent_id, sn.title, 0 AS depth
                FROM oem_source_nodes sn
                JOIN oem_assemblies a ON a.source_node_id = sn.id
                JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
                WHERE vv.model_family_id = %s
                LIMIT 1
              ) seed
              UNION ALL
              SELECT sn.id, sn.parent_id, sn.title, chain.depth + 1
              FROM oem_source_nodes sn
              JOIN chain ON sn.id = chain.parent_id
            )
            SELECT title FROM chain ORDER BY depth DESC
            """,
            (model_family_id,),
            conn=conn,
        )
    return " / ".join(row["title"] for row in rows if row.get("title"))


def _reassign_model_family_target(
    *,
    source_id: int,
    normalized_name: str,
    target_brand_id: int,
    target_vt_id: int,
    dry_run: bool,
    conn,
) -> tuple[str, int]:
    """Move or merge model_family onto canonical (brand, vehicle_type). Returns action, variants_moved."""
    conflict = _fetch_one(
        """
        SELECT id FROM oem_model_families
        WHERE vehicle_type_id = %s
          AND brand_id = %s
          AND normalized_name = %s
          AND id <> %s
        LIMIT 1
        """,
        (target_vt_id, target_brand_id, normalized_name, source_id),
        conn=conn,
    )
    if conflict:
        if dry_run:
            row = _fetch_one(
                "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
                (source_id,),
                conn=conn,
            )
            return "would_merge", int(row["cnt"] if row else 0)
        moved = _merge_model_family(source_id, conflict["id"], dry_run=dry_run, conn=conn)
        _delete_empty_model_family(source_id, dry_run=dry_run, conn=conn)
        return "merged", moved
    if dry_run:
        return "would_update", 0
    _fetch_one(
        """
        UPDATE oem_model_families
        SET brand_id = %s, vehicle_type_id = %s, updated_at = now()
        WHERE id = %s
        RETURNING id
        """,
        (target_brand_id, target_vt_id, source_id),
        conn=conn,
    )
    return "updated", 0


def _sample_path_for_model_family(model_family_id: int, *, conn=None) -> tuple[str | None, list[str], str]:
    row = _fetch_one(
        """
        SELECT sn.arib, sn.slug, sn.title, mf.name AS model_name,
               (SELECT vv.source_designation FROM oem_vehicle_variants vv
                WHERE vv.model_family_id = mf.id AND vv.source_designation IS NOT NULL
                LIMIT 1) AS source_designation
        FROM oem_model_families mf
        LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
        LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
        LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
        WHERE mf.id = %s
        ORDER BY LENGTH(COALESCE(sn.slug, '')) DESC
        LIMIT 1
        """,
        (model_family_id,),
        conn=conn,
    )
    if not row:
        row = _fetch_one(
            """
            SELECT sn.arib, sn.slug, sn.title, mf.name AS model_name, vv.source_designation
            FROM oem_source_nodes sn
            JOIN oem_assemblies a ON a.source_node_id = sn.id
            JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
            JOIN oem_model_families mf ON mf.id = vv.model_family_id
            WHERE mf.id = %s
              AND sn.slug IS NOT NULL
            ORDER BY LENGTH(sn.slug) DESC
            LIMIT 1
            """,
            (model_family_id,),
            conn=conn,
        )
    category_titles = _category_titles_for_model_family(model_family_id, conn=conn)
    if not row:
        return None, [], category_titles
    slug_path = path_from_slug(row.get("slug"))
    extra = " ".join(
        part
        for part in (category_titles, row.get("model_name"), row.get("source_designation"), row.get("title"))
        if part
    )
    return row.get("arib"), slug_path, extra


def _ensure_target_model_family(
    *,
    brand_name: str,
    vehicle_type: str,
    model_name: str,
    dry_run: bool,
) -> int | None:
    brand_id = writer.ensure_brand(brand_name) if not dry_run else _brand_id_by_name(brand_name)
    if brand_id is None:
        return None
    if dry_run:
        vt_id = _vehicle_type_id(vehicle_type)
        existing = _fetch_one(
            """
            SELECT id FROM oem_model_families
            WHERE vehicle_type_id = %s AND brand_id = %s AND normalized_name = %s
            """,
            (vt_id, brand_id, normalize_text(model_name)),
        )
        return existing["id"] if existing else -1
    return writer.ensure_model_family(vehicle_type, brand_id, model_name)


def _brand_id_by_name(name: str) -> int | None:
    row = _fetch_one("SELECT id FROM oem_brands WHERE normalized_name = %s", (normalize_text(name),))
    return row["id"] if row else None


def _vehicle_type_id(code: str) -> int:
    row = _fetch_one("SELECT id FROM oem_vehicle_types WHERE code = %s", (code,))
    if not row:
        raise RuntimeError(f"Unknown vehicle type: {code}")
    return row["id"]


def _move_variants(source_model_id: int, target_model_id: int, *, dry_run: bool, conn=None) -> int:
    if source_model_id == target_model_id:
        return 0
    if dry_run:
        row = _fetch_one(
            "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
            (source_model_id,),
            conn=conn,
        )
        return int(row["cnt"] if row else 0)
    row = _fetch_one(
        """
        WITH moved AS (
          UPDATE oem_vehicle_variants
          SET model_family_id = %s, updated_at = now()
          WHERE model_family_id = %s
          RETURNING id
        )
        SELECT COUNT(*) AS cnt FROM moved
        """,
        (target_model_id, source_model_id),
        conn=conn,
    )
    return int(row["cnt"] if row else 0)


def _merge_model_family(source_id: int, target_id: int, *, dry_run: bool, conn=None) -> int:
    return _move_variants(source_id, target_id, dry_run=dry_run, conn=conn)


def _delete_empty_model_family(model_family_id: int, *, dry_run: bool, conn=None) -> None:
    row = _fetch_one(
        "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
        (model_family_id,),
        conn=conn,
    )
    if row and int(row["cnt"] or 0) > 0:
        return
    if dry_run:
        return
    _fetch_one("DELETE FROM oem_model_aliases WHERE model_family_id = %s RETURNING id", (model_family_id,), conn=conn)
    _fetch_one("DELETE FROM oem_model_families WHERE id = %s RETURNING id", (model_family_id,), conn=conn)


def delete_model_family_tree(model_family_id: int, *, conn=None) -> dict[str, int]:
    """Delete a model family with all variants, assemblies, parts rows, and diagrams."""
    variants = _fetch_all(
        "SELECT id FROM oem_vehicle_variants WHERE model_family_id = %s",
        (model_family_id,),
        conn=conn,
    )
    stats = {"variants": 0, "assemblies": 0}
    for variant in variants:
        variant_id = int(variant["id"])
        assemblies = _fetch_all(
            "SELECT id FROM oem_assemblies WHERE vehicle_variant_id = %s",
            (variant_id,),
            conn=conn,
        )
        for assembly in assemblies:
            _delete_duplicate_assembly(int(assembly["id"]), dry_run=False, conn=conn)
            stats["assemblies"] += 1
        _exec(
            "DELETE FROM oem_source_node_links WHERE entity_type = 'vehicle_variant' AND entity_id = %s",
            (variant_id,),
            conn=conn,
        )
        _exec("DELETE FROM oem_vehicle_variants WHERE id = %s", (variant_id,), conn=conn)
        stats["variants"] += 1
    _exec("DELETE FROM oem_model_aliases WHERE model_family_id = %s", (model_family_id,), conn=conn)
    _exec("DELETE FROM oem_model_families WHERE id = %s", (model_family_id,), conn=conn)
    return stats


# Deprecated ARI umbrella brands: reassign parts, never DELETE (FK from oem_parts).
HIDDEN_BRAND_PART_TARGETS: dict[str, str] = {
    "brp_sea": "sea-doo",
    "brp_ski": "ski-doo",
}


def _reassign_parts_for_hidden_brands(*, dry_run: bool, conn) -> int:
    total = 0
    for src_name, tgt_name in HIDDEN_BRAND_PART_TARGETS.items():
        if dry_run:
            row = _fetch_one(
                """
                SELECT COUNT(*) AS cnt
                FROM oem_parts p
                JOIN oem_brands src ON src.id = p.brand_id
                WHERE src.normalized_name = %s
                """,
                (src_name,),
                conn=conn,
            )
            total += int(row["cnt"] if row else 0)
            continue
        row = _fetch_one(
            """
            WITH updated AS (
              UPDATE oem_parts p
              SET brand_id = tgt.id, updated_at = now()
              FROM oem_brands src
              JOIN oem_brands tgt ON tgt.normalized_name = %s
              WHERE p.brand_id = src.id
                AND src.normalized_name = %s
              RETURNING p.id
            )
            SELECT COUNT(*) AS cnt FROM updated
            """,
            (tgt_name, src_name),
            conn=conn,
        )
        total += int(row["cnt"] if row else 0)
    return total


def _count_hidden_brands_without_models(*, conn) -> int:
    row = _fetch_one(
        """
        SELECT COUNT(*) AS cnt
        FROM oem_brands b
        WHERE b.normalized_name = ANY(%s)
          AND NOT EXISTS (
            SELECT 1 FROM oem_model_families mf WHERE mf.brand_id = b.id
          )
        """,
        (list(HIDDEN_CANONICAL_BRANDS),),
        conn=conn,
    )
    return int(row["cnt"] if row else 0)


def apply_fixes(*, dry_run: bool = True) -> dict[str, Any]:
    stats = FixStats()
    sid = _source_id()

    with get_conn() as conn:
        try:
            stats = _apply_fixes_body(stats, sid, dry_run=dry_run, conn=conn)
            stats.parts_reassigned = _reassign_parts_for_hidden_brands(dry_run=dry_run, conn=conn)
            stats.brands_hidden = _count_hidden_brands_without_models(conn=conn)
            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    return {"dry_run": dry_run, "stats": stats.as_dict(), "message": _fix_summary(stats, dry_run=dry_run)}


def _fix_summary(stats: FixStats, *, dry_run: bool) -> str:
    mode = "dry-run" if dry_run else "apply"
    changed = (
        stats.lynx_moved
        + stats.atv_reclassified
        + stats.brp_reclassified
        + stats.model_families_merged
        + stats.variants_moved
        + stats.brand_aliases_removed
        + stats.parts_reassigned
    )
    if changed == 0:
        return (
            f"{mode}: nothing to change — catalog already fixed. "
            f"brands_hidden={stats.brands_hidden}: umbrella BRP has no models "
            f"(hidden in UI, row kept for oem_parts FK)."
        )
    return f"{mode}: {changed} change groups; see stats."


def _apply_fixes_body(stats: FixStats, sid: int, *, dry_run: bool, conn) -> FixStats:
    path_cache: dict[int, tuple[str | None, list[str], str]] = {}

    def sample_path(model_family_id: int) -> tuple[str | None, list[str], str]:
        if model_family_id not in path_cache:
            path_cache[model_family_id] = _sample_path_for_model_family(model_family_id, conn=conn)
        return path_cache[model_family_id]

    lynx_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, mf.normalized_name
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE b.normalized_name = 'lynx' AND vt.code = 'motorcycle'
        """,
        conn=conn,
    )
    snowmobile_id = _vehicle_type_id("snowmobile")
    lynx_brand_id = _brand_id_by_name("lynx")
    for row in lynx_rows:
        action, moved = _reassign_model_family_target(
            source_id=row["id"],
            normalized_name=row["normalized_name"],
            target_brand_id=lynx_brand_id,
            target_vt_id=snowmobile_id,
            dry_run=dry_run,
            conn=conn,
        )
        if action in ("merged", "would_merge"):
            stats.model_families_merged += 1
            stats.variants_moved += moved
        if action in ("merged", "would_merge", "would_update", "updated"):
            stats.lynx_moved += 1

    atv_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, b.normalized_name AS brand_normalized
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE vt.code = 'atv'
          AND (
            b.normalized_name IN ('lynx', 'ski-doo')
            OR mf.name ILIKE %s
            OR EXISTS (
              SELECT 1 FROM oem_vehicle_variants vv
              WHERE vv.model_family_id = mf.id
                AND vv.source_designation ~* '(skandic|ski[- ]doo|lynx|snowmobile)'
            )
          )
        """,
        ("%skandic%",),
        conn=conn,
    )
    for row in atv_rows:
        arib, path, extra = sample_path(row["id"])
        _, vehicle_type = classify_source(arib or "LNX", path, extra_text=extra)
        if vehicle_type == "atv":
            vehicle_type = "snowmobile"
        if dry_run:
            stats.atv_reclassified += 1
            continue
        _fetch_one(
            """
            UPDATE oem_model_families
            SET vehicle_type_id = %s, updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (_vehicle_type_id(vehicle_type), row["id"]),
            conn=conn,
        )
        stats.atv_reclassified += 1

    brp_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, mf.normalized_name, b.normalized_name AS brand_normalized, vt.code AS current_vehicle_type
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE b.normalized_name IN ('brp', 'brp_sea', 'brp_ski', 'sea-doo', 'ski-doo', 'can-am')
        ORDER BY CASE WHEN b.normalized_name = 'brp' THEN 0 ELSE 1 END, mf.id
        """,
        conn=conn,
    )
    if brp_rows:
        print(f"[fix-remotors] Checking {len(brp_rows)} BRP-family models...", flush=True)
    for index, row in enumerate(brp_rows, start=1):
        arib, path, extra = sample_path(row["id"])
        if not arib:
            arib = {"sea-doo": "BRP_SEA", "brp_sea": "BRP_SEA", "ski-doo": "BRP_SKI", "brp_ski": "BRP_SKI", "can-am": "BRP", "brp": "BRP"}.get(
                row["brand_normalized"], "BRP"
            )
        brand_name, vehicle_type = classify_source(arib, path, extra_text=f"{row['name']} {extra}")
        if normalize_text(brand_name) == row["brand_normalized"] and vehicle_type == row["current_vehicle_type"]:
            continue
        if stats.brp_reclassified == 0 or stats.brp_reclassified % 100 == 0:
            print(f"[fix-remotors] BRP updates in progress ({stats.brp_reclassified} changed so far)", flush=True)
        target_brand_id = writer.ensure_brand(brand_name) if not dry_run else _brand_id_by_name(brand_name)
        if target_brand_id is None and dry_run:
            target_brand_id = -1
        target_vt_id = _vehicle_type_id(vehicle_type)
        action, moved = _reassign_model_family_target(
            source_id=row["id"],
            normalized_name=row["normalized_name"],
            target_brand_id=target_brand_id,
            target_vt_id=target_vt_id,
            dry_run=dry_run,
            conn=conn,
        )
        if action in ("merged", "would_merge"):
            stats.model_families_merged += 1
            stats.variants_moved += moved
        if action in ("merged", "would_merge", "would_update", "updated"):
            stats.brp_reclassified += 1

    alias_rows = _fetch_all(
        """
        SELECT ba.id, ba.alias
        FROM oem_brand_aliases ba
        WHERE ba.source_id = %s
          AND ba.normalized_alias IN ('brp', 'brp_sea', 'brp_ski')
        """,
        (sid,),
    )
    for row in alias_rows:
        if dry_run:
            stats.brand_aliases_removed += 1
            continue
        _fetch_one("DELETE FROM oem_brand_aliases WHERE id = %s RETURNING id", (row["id"],), conn=conn)
        stats.brand_aliases_removed += 1

    return stats


def cleanup_orphans(*, dry_run: bool = True) -> dict[str, Any]:
    sid = _source_id()
    legacy_nodes = _fetch_all(
        """
        SELECT sn.id, sn.external_id
        FROM oem_source_nodes sn
        WHERE sn.source_id = %s
          AND sn.node_type = 'assembly'
          AND sn.external_id IS NOT NULL
          AND sn.slug IS NOT NULL
          AND sn.external_id = sn.arib || ':' || sn.aria
          AND NOT EXISTS (
            SELECT 1 FROM oem_source_node_links snl
            WHERE snl.source_node_id = sn.id AND snl.entity_type = 'assembly'
          )
        LIMIT 5000
        """,
        (sid,),
    )
    empty_assemblies = _fetch_all(
        """
        SELECT a.id
        FROM oem_assemblies a
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        WHERE d.id IS NULL AND ap.id IS NULL
        LIMIT 5000
        """
    )
    deleted_nodes = 0
    deleted_assemblies = 0
    if not dry_run:
        for row in legacy_nodes:
            _fetch_one("DELETE FROM oem_source_nodes WHERE id = %s RETURNING id", (row["id"],))
            deleted_nodes += 1
        for row in empty_assemblies:
            _fetch_one("DELETE FROM oem_assemblies WHERE id = %s RETURNING id", (row["id"],))
            deleted_assemblies += 1
    return {
        "dry_run": dry_run,
        "legacy_source_nodes": len(legacy_nodes),
        "empty_assemblies": len(empty_assemblies),
        "deleted_nodes": deleted_nodes,
        "deleted_assemblies": deleted_assemblies,
    }


def render_recrawl_commands(
    plan: list[dict[str, Any]] | None = None,
    *,
    mode: str = "under",
    limit: int = 10000,
    max_assemblies: int = 15,
) -> str:
    if mode == "year":
        return _render_year_recrawl_commands(plan or build_recrawl_plan(limit=limit))
    if mode == "variant":
        return _render_variant_recrawl_commands(plan or build_variant_recrawl_plan(limit=limit))
    return _render_variant_recrawl_commands(
        plan or build_under_crawled_recrawl_plan(max_assemblies=max_assemblies, limit=limit)
    )


def _render_year_recrawl_commands(plan: list[dict[str, Any]]) -> str:
    lines = [
        "#!/bin/bash",
        "set -euo pipefail",
        "# Year-level re-crawl (KTM/LNX ok; BRP needs fixed crawler for nested years).",
        "# Uses umbrella BRP only (never BRP_SEA / BRP_SKI). Review before running.",
        "",
    ]
    if not plan:
        lines.append("# No thin variants detected.")
        return "\n".join(lines) + "\n"
    for item in plan:
        arib = item["arib"]
        year = item.get("year")
        if not year:
            continue
        lines.append(
            "docker compose exec -T oem_backend python -m app.cli crawl-remotors "
            f"--brands {arib} --year {year} --confirm-full-crawl --force"
        )
    return "\n".join(lines) + "\n"


def _render_variant_recrawl_commands(plan: list[dict[str, Any]], *, batch_size: int = 20) -> str:
    lines = [
        "#!/bin/bash",
        "set -euo pipefail",
        "# Targeted re-crawl by variant_id (--force re-imports assemblies + diagrams).",
        "# Run in tmux; add --no-images for faster first pass without diagram PNGs.",
        "",
        'COMPOSE="docker compose"',
        'if [ -f docker-compose.prod.yml ]; then',
        '  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"',
        "fi",
        "",
    ]
    if not plan:
        lines.append("# No thin variants detected.")
        return "\n".join(lines) + "\n"
    variant_ids = [str(item["variant_id"]) for item in plan if item.get("variant_id")]
    for index in range(0, len(variant_ids), batch_size):
        batch = ",".join(variant_ids[index : index + batch_size])
        lines.append(
            '$COMPOSE exec -T oem_backend python -m app.cli recrawl-remotors-variants '
            f"--variant-ids {batch} --force"
        )
    return "\n".join(lines) + "\n"


@dataclass
class DuplicateVariantFixStats:
    groups_merged: int = 0
    variants_removed: int = 0
    assemblies_moved: int = 0
    assemblies_merged: int = 0
    source_links_moved: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "groups_merged": self.groups_merged,
            "variants_removed": self.variants_removed,
            "assemblies_moved": self.assemblies_moved,
            "assemblies_merged": self.assemblies_merged,
            "source_links_moved": self.source_links_moved,
        }


def _variant_identity_key(row: dict[str, Any]) -> tuple[Any, ...]:
    """Identity as shown in OEM UI (title + subtitle + section)."""
    return (
        int(row["model_family_id"]),
        row.get("year_from"),
        row.get("year_to"),
        normalize_text(row.get("market_name") or ""),
        normalize_text(row.get("source_designation") or ""),
        normalize_text(row.get("variant_section") or ""),
    )


def list_duplicate_variant_groups(*, limit: int = 500) -> list[dict[str, Any]]:
    rows = _fetch_all(
        """
        SELECT
          vv.id AS variant_id,
          vv.model_family_id,
          vv.year_from,
          vv.year_to,
          vv.market_name,
          vv.source_designation,
          vv.variant_section,
          vv.model_code,
          vv.color_code,
          mf.name AS model_name,
          b.name AS brand_name,
          COUNT(a.id) AS assembly_count
        FROM oem_vehicle_variants vv
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
        GROUP BY
          vv.id, vv.model_family_id, vv.year_from, vv.year_to, vv.market_name,
          vv.source_designation, vv.variant_section, vv.model_code, vv.color_code,
          mf.name, b.name
        ORDER BY mf.name, vv.year_from, vv.id
        """
    )
    buckets: dict[tuple[Any, ...], list[dict[str, Any]]] = {}
    for row in rows:
        buckets.setdefault(_variant_identity_key(row), []).append(row)

    groups: list[dict[str, Any]] = []
    for items in buckets.values():
        if len(items) < 2:
            continue
        items.sort(key=lambda item: (-int(item["assembly_count"]), int(item["variant_id"])))
        groups.append(
            {
                "model_family_id": items[0]["model_family_id"],
                "year_from": items[0]["year_from"],
                "model_name": items[0]["model_name"],
                "brand_name": items[0]["brand_name"],
                "market_name": items[0]["market_name"],
                "source_designation": items[0]["source_designation"],
                "variant_section": items[0]["variant_section"],
                "variant_count": len(items),
                "variants": [
                    {
                        "variant_id": int(item["variant_id"]),
                        "assembly_count": int(item["assembly_count"]),
                    }
                    for item in items
                ],
            }
        )
    groups.sort(key=lambda group: (-group["variant_count"], group["model_name"] or ""))
    return groups[:limit]


def _find_matching_assembly(
    *,
    target_variant_id: int,
    normalized_title: str,
    source_node_id: int | None,
    conn,
) -> dict[str, Any] | None:
    return _fetch_one(
        """
        SELECT a.id
        FROM oem_assemblies a
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE a.vehicle_variant_id = %s
          AND (
            a.normalized_title = %s
            OR (
              %s::bigint IS NOT NULL
              AND a.source_node_id IS NOT NULL
              AND (
                a.source_node_id = %s::bigint
                OR EXISTS (
                  SELECT 1
                  FROM oem_source_nodes sn_src
                  WHERE sn_src.id = %s::bigint
                    AND sn.external_id IS NOT NULL
                    AND sn_src.external_id = sn.external_id
                )
              )
            )
          )
        ORDER BY a.id ASC
        LIMIT 1
        """,
        (target_variant_id, normalized_title, source_node_id, source_node_id, source_node_id),
        conn=conn,
    )


def _reassign_assembly_children(source_asm_id: int, target_asm_id: int, *, conn) -> None:
    """Move children when an assembly row moves to another variant (not a duplicate drop)."""
    _fetch_one(
        """
        UPDATE oem_assembly_parts
        SET assembly_id = %s, updated_at = now()
        WHERE assembly_id = %s
        RETURNING id
        """,
        (target_asm_id, source_asm_id),
        conn=conn,
    )
    _fetch_one(
        """
        UPDATE oem_diagrams
        SET assembly_id = %s, updated_at = now()
        WHERE assembly_id = %s
        RETURNING id
        """,
        (target_asm_id, source_asm_id),
        conn=conn,
    )


def _pick_canonical_assembly_id(assembly_ids: list[int], *, conn) -> int:
    row = _fetch_one(
        """
        SELECT a.id
        FROM oem_assemblies a
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        WHERE a.id = ANY(%s)
        GROUP BY a.id
        ORDER BY COUNT(ap.id) DESC, COUNT(d.id) DESC, a.id ASC
        LIMIT 1
        """,
        (assembly_ids,),
        conn=conn,
    )
    return int(row["id"]) if row else min(assembly_ids)


def _delete_duplicate_assembly(assembly_id: int, *, dry_run: bool, conn) -> None:
    """Drop a duplicate assembly row and all of its own parts/diagrams (do not merge into sibling)."""
    if dry_run:
        return
    _exec(
        """
        DELETE FROM oem_diagram_hotspots
        WHERE diagram_id IN (SELECT id FROM oem_diagrams WHERE assembly_id = %s)
        """,
        (assembly_id,),
        conn=conn,
    )
    _exec(
        """
        DELETE FROM oem_source_price_snapshots
        WHERE assembly_part_id IN (SELECT id FROM oem_assembly_parts WHERE assembly_id = %s)
        """,
        (assembly_id,),
        conn=conn,
    )
    _exec("DELETE FROM oem_assembly_parts WHERE assembly_id = %s", (assembly_id,), conn=conn)
    _exec("DELETE FROM oem_diagrams WHERE assembly_id = %s", (assembly_id,), conn=conn)
    _exec(
        """
        DELETE FROM oem_source_node_links
        WHERE entity_type = 'assembly' AND entity_id = %s
        """,
        (assembly_id,),
        conn=conn,
    )
    _exec("DELETE FROM oem_assemblies WHERE id = %s", (assembly_id,), conn=conn)


def _merge_variant_into(
    source_variant_id: int,
    target_variant_id: int,
    *,
    dry_run: bool,
    conn,
) -> DuplicateVariantFixStats:
    stats = DuplicateVariantFixStats()
    if source_variant_id == target_variant_id:
        return stats

    assemblies = _fetch_all(
        """
        SELECT id, normalized_title, source_node_id
        FROM oem_assemblies
        WHERE vehicle_variant_id = %s
        ORDER BY id
        """,
        (source_variant_id,),
        conn=conn,
    )
    for assembly in assemblies:
        match = _find_matching_assembly(
            target_variant_id=target_variant_id,
            normalized_title=assembly["normalized_title"],
            source_node_id=assembly.get("source_node_id"),
            conn=conn,
        )
        if match:
            if not dry_run:
                _delete_duplicate_assembly(int(assembly["id"]), dry_run=False, conn=conn)
            stats.assemblies_merged += 1
        elif dry_run:
            stats.assemblies_moved += 1
        else:
            _fetch_one(
                """
                UPDATE oem_assemblies
                SET vehicle_variant_id = %s, updated_at = now()
                WHERE id = %s
                RETURNING id
                """,
                (target_variant_id, assembly["id"]),
                conn=conn,
            )
            stats.assemblies_moved += 1

    if dry_run:
        stats.source_links_moved += int(
            (
                _fetch_one(
                    """
                    SELECT COUNT(*) AS cnt
                    FROM oem_source_node_links
                    WHERE entity_type = 'vehicle_variant' AND entity_id = %s
                    """,
                    (source_variant_id,),
                    conn=conn,
                )
                or {}
            ).get("cnt", 0)
        )
        return stats

    moved_links = _fetch_one(
        """
        WITH moved AS (
          UPDATE oem_source_node_links snl
          SET entity_id = %s
          WHERE entity_type = 'vehicle_variant'
            AND entity_id = %s
            AND NOT EXISTS (
              SELECT 1
              FROM oem_source_node_links existing
              WHERE existing.source_node_id = snl.source_node_id
                AND existing.entity_type = 'vehicle_variant'
                AND existing.entity_id = %s
            )
          RETURNING id
        )
        SELECT COUNT(*) AS cnt FROM moved
        """,
        (target_variant_id, source_variant_id, target_variant_id),
        conn=conn,
    )
    stats.source_links_moved += int(moved_links["cnt"] if moved_links else 0)
    _fetch_one(
        "DELETE FROM oem_source_node_links WHERE entity_type = 'vehicle_variant' AND entity_id = %s RETURNING id",
        (source_variant_id,),
        conn=conn,
    )
    _fetch_one("DELETE FROM oem_vehicle_variants WHERE id = %s RETURNING id", (source_variant_id,), conn=conn)
    return stats


def apply_duplicate_variant_fixes(*, dry_run: bool = True, limit: int = 10000) -> dict[str, Any]:
    stats = DuplicateVariantFixStats()
    groups = list_duplicate_variant_groups(limit=limit)
    with get_conn() as conn:
        try:
            for group in groups:
                variant_ids = [item["variant_id"] for item in group["variants"]]
                if len(variant_ids) < 2:
                    continue
                target_id = variant_ids[0]
                for source_id in variant_ids[1:]:
                    merged = _merge_variant_into(source_id, target_id, dry_run=dry_run, conn=conn)
                    stats.assemblies_moved += merged.assemblies_moved
                    stats.assemblies_merged += merged.assemblies_merged
                    stats.source_links_moved += merged.source_links_moved
                    stats.variants_removed += 1
                stats.groups_merged += 1
            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    mode = "dry-run" if dry_run else "apply"
    changed = (
        stats.groups_merged
        + stats.variants_removed
        + stats.assemblies_moved
        + stats.assemblies_merged
    )
    message = (
        f"{mode}: merged {stats.groups_merged} duplicate variant groups, "
        f"removed {stats.variants_removed} variants, "
        f"moved {stats.assemblies_moved} assemblies, "
        f"deduped {stats.assemblies_merged} assemblies."
        if changed
        else f"{mode}: no duplicate variants to merge."
    )
    return {"dry_run": dry_run, "groups_found": len(groups), "stats": stats.as_dict(), "message": message}


@dataclass
class DuplicateAssemblyFixStats:
    title_groups: int = 0
    aria_groups: int = 0
    assemblies_merged: int = 0
    assemblies_removed: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "title_groups": self.title_groups,
            "aria_groups": self.aria_groups,
            "assemblies_merged": self.assemblies_merged,
            "assemblies_removed": self.assemblies_removed,
        }


def list_duplicate_assembly_groups(*, limit: int = 500) -> list[dict[str, Any]]:
    """Assemblies on the same variant with identical normalized_title or source aria."""
    by_title = _fetch_all(
        """
        SELECT
          a.vehicle_variant_id,
          a.normalized_title,
          COUNT(*) AS assembly_count,
          array_agg(a.id ORDER BY a.id) AS assembly_ids,
          MIN(mf.name) AS model_name,
          MIN(b.name) AS brand_name
        FROM oem_assemblies a
        JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        GROUP BY a.vehicle_variant_id, a.normalized_title
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC, MIN(mf.name)
        LIMIT %s
        """,
        (limit,),
    )
    by_aria = _fetch_all(
        """
        SELECT
          a.vehicle_variant_id,
          sn.aria,
          COUNT(*) AS assembly_count,
          array_agg(a.id ORDER BY a.id) AS assembly_ids,
          MIN(mf.name) AS model_name,
          MIN(b.name) AS brand_name
        FROM oem_assemblies a
        JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        WHERE sn.aria IS NOT NULL AND sn.aria <> ''
        GROUP BY a.vehicle_variant_id, sn.aria
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC, MIN(mf.name)
        LIMIT %s
        """,
        (limit,),
    )
    return [
        *[{"kind": "title", **row} for row in by_title],
        *[{"kind": "aria", **row} for row in by_aria],
    ]


def _merge_assembly_into(source_asm_id: int, target_asm_id: int, *, dry_run: bool, conn) -> bool:
    if source_asm_id == target_asm_id:
        return False
    if dry_run:
        return True
    _delete_duplicate_assembly(source_asm_id, dry_run=False, conn=conn)
    return True


def apply_duplicate_assembly_fixes(*, dry_run: bool = True, limit: int = 100000) -> dict[str, Any]:
    """Merge duplicate oem_assemblies rows within the same vehicle variant."""
    stats = DuplicateAssemblyFixStats()
    title_groups = _fetch_all(
        """
        SELECT vehicle_variant_id, normalized_title, array_agg(id ORDER BY id) AS assembly_ids
        FROM oem_assemblies
        GROUP BY vehicle_variant_id, normalized_title
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC
        LIMIT %s
        """,
        (limit,),
    )
    aria_groups = _fetch_all(
        """
        SELECT a.vehicle_variant_id, sn.aria, array_agg(a.id ORDER BY a.id) AS assembly_ids
        FROM oem_assemblies a
        JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE sn.aria IS NOT NULL AND sn.aria <> ''
        GROUP BY a.vehicle_variant_id, sn.aria
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC
        LIMIT %s
        """,
        (limit,),
    )

    with get_conn() as conn:
        try:
            for group in title_groups:
                ids = [int(item) for item in group["assembly_ids"]]
                if len(ids) < 2:
                    continue
                stats.title_groups += 1
                target_id = _pick_canonical_assembly_id(ids, conn=conn)
                for source_id in ids:
                    if source_id == target_id:
                        continue
                    if _merge_assembly_into(source_id, target_id, dry_run=dry_run, conn=conn):
                        stats.assemblies_merged += 1
                        stats.assemblies_removed += 1

            for group in aria_groups:
                ids = [int(item) for item in group["assembly_ids"]]
                if len(ids) < 2:
                    continue
                existing_ids = [
                    int(row["id"])
                    for row in _fetch_all(
                        "SELECT id FROM oem_assemblies WHERE id = ANY(%s)",
                        (ids,),
                        conn=conn,
                    )
                ]
                if len(existing_ids) < 2:
                    continue
                stats.aria_groups += 1
                target_id = _pick_canonical_assembly_id(existing_ids, conn=conn)
                for source_id in existing_ids:
                    if source_id == target_id:
                        continue
                    if _merge_assembly_into(source_id, target_id, dry_run=dry_run, conn=conn):
                        stats.assemblies_merged += 1
                        stats.assemblies_removed += 1

            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    mode = "dry-run" if dry_run else "apply"
    changed = stats.assemblies_removed
    message = (
        f"{mode}: dropped {stats.assemblies_removed} duplicate assemblies "
        f"({stats.title_groups} title groups, {stats.aria_groups} aria groups); "
        f"duplicate rows were deleted without merging their parts."
        if changed
        else f"{mode}: no duplicate assemblies to merge."
    )
    return {
        "dry_run": dry_run,
        "stats": stats.as_dict(),
        "sample_groups": list_duplicate_assembly_groups(limit=20),
        "message": message,
    }


@dataclass
class DuplicateAssemblyContentsFixStats:
    parts_removed: int = 0
    hotspots_removed: int = 0
    diagrams_removed: int = 0
    price_snapshots_removed: int = 0
    hotspots_on_dup_parts_removed: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "parts_removed": self.parts_removed,
            "hotspots_removed": self.hotspots_removed,
            "diagrams_removed": self.diagrams_removed,
            "price_snapshots_removed": self.price_snapshots_removed,
            "hotspots_on_dup_parts_removed": self.hotspots_on_dup_parts_removed,
        }


def _ensure_contents_dedup_indexes(conn) -> None:
    """One-time indexes so duplicate-part cleanup hits rows by id, not full table scans."""
    indexes = (
        (
            "ix_oem_hotspots_assembly_part",
            "CREATE INDEX IF NOT EXISTS ix_oem_hotspots_assembly_part "
            "ON oem_diagram_hotspots(assembly_part_id)",
        ),
        (
            "ix_oem_price_snapshots_assembly_part",
            "CREATE INDEX IF NOT EXISTS ix_oem_price_snapshots_assembly_part "
            "ON oem_source_price_snapshots(assembly_part_id)",
        ),
    )
    for name, ddl in indexes:
        if _index_state(conn, name) == "valid":
            continue
        _log_progress(f"dedupe contents: creating index {name} (one-time, may take a few minutes)")
        with _heartbeat(f"index {name}", prefix="dedupe contents"):
            _exec(ddl, conn=conn)
        _log_progress(f"dedupe contents: index {name} ready")


def _contents_dup_batch_stats(conn, table: str) -> tuple[int, int]:
    row = _fetch_one(
        f"SELECT COALESCE(MAX(batch_no), 0) AS max_batch, COUNT(*) AS total FROM {table}",
        conn=conn,
    ) or {"max_batch": 0, "total": 0}
    return int(row["max_batch"] or 0), int(row["total"] or 0)


def _run_contents_batched_step(
    conn,
    *,
    label: str,
    map_table: str,
    query_template: str,
) -> int:
    max_batch, total_dupes = _contents_dup_batch_stats(conn, map_table)
    if total_dupes == 0:
        _log_progress(f"dedupe contents {label}: nothing to do")
        return 0
    batch_count = max_batch + 1
    _log_progress(
        f"dedupe contents {label} start dupes={total_dupes} "
        f"batches={batch_count} batch_size={CONTENTS_DEDUP_BATCH_SIZE}"
    )
    total_affected = 0
    step_started = time.monotonic()
    for batch_no in range(batch_count):
        batch_started = time.monotonic()
        with _heartbeat(
            f"{label} batch {batch_no + 1}/{batch_count}",
            prefix="dedupe contents",
        ):
            affected = _exec_rowcount(
                query_template + f" AND d.batch_no = %s",
                (batch_no,),
                conn=conn,
            )
        total_affected += affected
        dupes_done = min((batch_no + 1) * CONTENTS_DEDUP_BATCH_SIZE, total_dupes)
        pct = (100.0 * dupes_done / total_dupes) if total_dupes else 100.0
        _log_progress(
            f"dedupe contents {label} batch {batch_no + 1}/{batch_count} "
            f"({pct:.1f}%) rows={affected} "
            f"batch_elapsed={int(time.monotonic() - batch_started)}s "
            f"step_elapsed={int(time.monotonic() - step_started)}s"
        )
    _log_progress(
        f"dedupe contents {label} done total_rows={total_affected} "
        f"elapsed={int(time.monotonic() - step_started)}s"
    )
    return total_affected



def _parts_scope_filter(scoped: bool) -> str:
    if scoped:
        return "AND ap.assembly_id IN (SELECT assembly_id FROM tmp_dedupe_assemblies)"
    return ""


def _diagrams_scope_join(scoped: bool) -> str:
    if scoped:
        return "JOIN tmp_dedupe_assemblies scope ON scope.assembly_id = d.assembly_id"
    return ""


def _hotspots_scope_join(scoped: bool) -> str:
    if scoped:
        return (
            "JOIN oem_diagrams d ON d.id = h.diagram_id "
            "JOIN tmp_dedupe_assemblies scope ON scope.assembly_id = d.assembly_id"
        )
    return ""


def _build_part_duplicate_map(*, conn, scoped: bool) -> int:
    scope_filter = _parts_scope_filter(scoped)
    _exec("DROP TABLE IF EXISTS tmp_part_dup_map", conn=conn)
    _log_progress("dedupe contents: building duplicate part map (GROUP BY, affected rows only)")
    with _heartbeat("part dup map build", prefix="dedupe contents"):
        _exec(
            f"""
            CREATE TEMP TABLE tmp_part_dup_map ON COMMIT PRESERVE ROWS AS
            SELECT
              dup.duplicate_id,
              dup.canonical_id,
              ((ROW_NUMBER() OVER (ORDER BY dup.duplicate_id) - 1) / {CONTENTS_DEDUP_BATCH_SIZE})::int
                AS batch_no
            FROM (
              SELECT ap.id AS duplicate_id, grp.canonical_id
              FROM oem_assembly_parts ap
              JOIN oem_parts p ON p.id = ap.part_id
              JOIN (
                SELECT
                  ap.assembly_id,
                  COALESCE(ap.ref, '') AS ref,
                  p.normalized_part_number,
                  LOWER(BTRIM(COALESCE(p.name, ''))) AS name_norm,
                  ap.row_kind,
                  COALESCE(ap.quantity::text, '') AS qty_text,
                  MIN(ap.id) AS canonical_id
                FROM oem_assembly_parts ap
                JOIN oem_parts p ON p.id = ap.part_id
                WHERE TRUE {scope_filter}
                GROUP BY 1, 2, 3, 4, 5, 6
                HAVING COUNT(*) > 1
              ) grp
                ON ap.assembly_id = grp.assembly_id
               AND COALESCE(ap.ref, '') = grp.ref
               AND p.normalized_part_number IS NOT DISTINCT FROM grp.normalized_part_number
               AND LOWER(BTRIM(COALESCE(p.name, ''))) = grp.name_norm
               AND ap.row_kind = grp.row_kind
               AND COALESCE(ap.quantity::text, '') = grp.qty_text
              WHERE ap.id <> grp.canonical_id
                {scope_filter}
            ) dup
            """,
            conn=conn,
        )
    _exec("CREATE INDEX ON tmp_part_dup_map (duplicate_id)", conn=conn)
    _exec("CREATE INDEX ON tmp_part_dup_map (batch_no)", conn=conn)
    _, total = _contents_dup_batch_stats(conn, "tmp_part_dup_map")
    _log_progress(f"dedupe contents: duplicate part map ready duplicate_parts={total}")
    return total


def _dedupe_assembly_parts(*, conn, dry_run: bool, scoped: bool) -> DuplicateAssemblyContentsFixStats:
    stats = DuplicateAssemblyContentsFixStats()
    duplicate_count = _build_part_duplicate_map(conn=conn, scoped=scoped)
    if duplicate_count == 0:
        return stats
    if dry_run:
        stats.parts_removed = duplicate_count
        return stats

    _log_progress("dedupe assembly parts: delete hotspots on duplicate parts")
    stats.hotspots_on_dup_parts_removed = _run_contents_batched_step(
        conn,
        label="delete hotspots on duplicate parts",
        map_table="tmp_part_dup_map",
        query_template="""
            DELETE FROM oem_diagram_hotspots h
            USING tmp_part_dup_map d
            WHERE h.assembly_part_id = d.duplicate_id
        """,
    )

    _log_progress("dedupe assembly parts: delete price snapshots")
    stats.price_snapshots_removed = _run_contents_batched_step(
        conn,
        label="delete price snapshots",
        map_table="tmp_part_dup_map",
        query_template="""
            DELETE FROM oem_source_price_snapshots s
            USING tmp_part_dup_map d
            WHERE s.assembly_part_id = d.duplicate_id
        """,
    )

    _log_progress("dedupe assembly parts: delete duplicate rows")
    stats.parts_removed = _run_contents_batched_step(
        conn,
        label="delete duplicate parts",
        map_table="tmp_part_dup_map",
        query_template="""
            DELETE FROM oem_assembly_parts ap
            USING tmp_part_dup_map d
            WHERE ap.id = d.duplicate_id
        """,
    )
    return stats


def _build_diagram_duplicate_map(*, conn, scoped: bool) -> int:
    scope_join = _diagrams_scope_join(scoped)
    _exec("DROP TABLE IF EXISTS tmp_diagram_dup_map", conn=conn)
    _log_progress("dedupe contents: building duplicate diagram map")
    with _heartbeat("diagram dup map build", prefix="dedupe contents"):
        _exec(
            f"""
            CREATE TEMP TABLE tmp_diagram_dup_map ON COMMIT PRESERVE ROWS AS
            SELECT
              dup.duplicate_id,
              ((ROW_NUMBER() OVER (ORDER BY dup.duplicate_id) - 1) / {CONTENTS_DEDUP_BATCH_SIZE})::int
                AS batch_no
            FROM (
              SELECT d.id AS duplicate_id
              FROM oem_diagrams d
              {scope_join}
              JOIN (
                SELECT
                  d.assembly_id,
                  COALESCE(d.source_image_id, '') AS source_image_id,
                  COALESCE(d.checksum_sha256, '') AS checksum_sha256,
                  COALESCE(d.original_url, '') AS original_url,
                  COALESCE(d.local_path, '') AS local_path,
                  COALESCE(
                    MIN(d.id) FILTER (
                      WHERE d.local_path IS NOT NULL AND d.local_path <> ''
                    ),
                    MIN(d.id)
                  ) AS canonical_id
                FROM oem_diagrams d
                {scope_join}
                GROUP BY 1, 2, 3, 4, 5
                HAVING COUNT(*) > 1
              ) grp
                ON d.assembly_id = grp.assembly_id
               AND COALESCE(d.source_image_id, '') = grp.source_image_id
               AND COALESCE(d.checksum_sha256, '') = grp.checksum_sha256
               AND COALESCE(d.original_url, '') = grp.original_url
               AND COALESCE(d.local_path, '') = grp.local_path
              WHERE d.id <> grp.canonical_id
            ) dup
            """,
            conn=conn,
        )
    _exec("CREATE INDEX ON tmp_diagram_dup_map (duplicate_id)", conn=conn)
    _exec("CREATE INDEX ON tmp_diagram_dup_map (batch_no)", conn=conn)
    _, total = _contents_dup_batch_stats(conn, "tmp_diagram_dup_map")
    _log_progress(f"dedupe contents: duplicate diagram map ready duplicate_diagrams={total}")
    return total


def _dedupe_diagrams(*, conn, dry_run: bool, scoped: bool) -> int:
    duplicate_count = _build_diagram_duplicate_map(conn=conn, scoped=scoped)
    if duplicate_count == 0:
        return 0
    if dry_run:
        return duplicate_count

    _log_progress("dedupe duplicate diagrams: delete orphaned hotspots")
    _run_contents_batched_step(
        conn,
        label="delete diagram hotspots",
        map_table="tmp_diagram_dup_map",
        query_template="""
            DELETE FROM oem_diagram_hotspots h
            USING tmp_diagram_dup_map d
            WHERE h.diagram_id = d.duplicate_id
        """,
    )

    _log_progress("dedupe duplicate diagrams per assembly")
    return _run_contents_batched_step(
        conn,
        label="delete duplicate diagrams",
        map_table="tmp_diagram_dup_map",
        query_template="""
            DELETE FROM oem_diagrams diag
            USING tmp_diagram_dup_map d
            WHERE diag.id = d.duplicate_id
        """,
    )


def _build_hotspot_duplicate_map(*, conn, scoped: bool) -> int:
    scope_join = _hotspots_scope_join(scoped)
    _exec("DROP TABLE IF EXISTS tmp_hotspot_dup_map", conn=conn)
    _log_progress("dedupe contents: building duplicate hotspot map")
    with _heartbeat("hotspot dup map build", prefix="dedupe contents"):
        _exec(
            f"""
            CREATE TEMP TABLE tmp_hotspot_dup_map ON COMMIT PRESERVE ROWS AS
            SELECT
              dup.duplicate_id,
              ((ROW_NUMBER() OVER (ORDER BY dup.duplicate_id) - 1) / {CONTENTS_DEDUP_BATCH_SIZE})::int
                AS batch_no
            FROM (
              SELECT h.id AS duplicate_id
              FROM oem_diagram_hotspots h
              {scope_join}
              JOIN (
                SELECT
                  h.diagram_id,
                  COALESCE(h.ref, '') AS ref,
                  COALESCE(h.raw_coords, '') AS raw_coords,
                  COALESCE(h.assembly_part_id, 0) AS assembly_part_id,
                  h.shape,
                  COALESCE(h.x::text, '') AS x_text,
                  COALESCE(h.y::text, '') AS y_text,
                  COALESCE(h.width::text, '') AS width_text,
                  COALESCE(h.height::text, '') AS height_text,
                  MIN(h.id) AS canonical_id
                FROM oem_diagram_hotspots h
                {scope_join}
                GROUP BY 1, 2, 3, 4, 5, 6, 7, 8, 9
                HAVING COUNT(*) > 1
              ) grp
                ON h.diagram_id = grp.diagram_id
               AND COALESCE(h.ref, '') = grp.ref
               AND COALESCE(h.raw_coords, '') = grp.raw_coords
               AND COALESCE(h.assembly_part_id, 0) = grp.assembly_part_id
               AND h.shape = grp.shape
               AND COALESCE(h.x::text, '') = grp.x_text
               AND COALESCE(h.y::text, '') = grp.y_text
               AND COALESCE(h.width::text, '') = grp.width_text
               AND COALESCE(h.height::text, '') = grp.height_text
              WHERE h.id <> grp.canonical_id
            ) dup
            """,
            conn=conn,
        )
    _exec("CREATE INDEX ON tmp_hotspot_dup_map (duplicate_id)", conn=conn)
    _exec("CREATE INDEX ON tmp_hotspot_dup_map (batch_no)", conn=conn)
    _, total = _contents_dup_batch_stats(conn, "tmp_hotspot_dup_map")
    _log_progress(f"dedupe contents: duplicate hotspot map ready duplicate_hotspots={total}")
    return total


def _dedupe_hotspots(*, conn, dry_run: bool, scoped: bool) -> int:
    duplicate_count = _build_hotspot_duplicate_map(conn=conn, scoped=scoped)
    if duplicate_count == 0:
        return 0
    if dry_run:
        return duplicate_count

    _log_progress("dedupe duplicate diagram hotspots")
    return _run_contents_batched_step(
        conn,
        label="delete duplicate hotspots",
        map_table="tmp_hotspot_dup_map",
        query_template="""
            DELETE FROM oem_diagram_hotspots h
            USING tmp_hotspot_dup_map d
            WHERE h.id = d.duplicate_id
        """,
    )


def apply_duplicate_assembly_contents_fixes(
    *,
    dry_run: bool = True,
    assembly_ids: list[int] | None = None,
) -> dict[str, Any]:
    """Remove duplicated parts/diagrams/hotspots left inside assemblies (e.g. after bad merge)."""
    stats = DuplicateAssemblyContentsFixStats()

    with get_conn() as conn:
        try:
            _ensure_contents_dedup_indexes(conn)
            conn.commit()

            scoped = bool(assembly_ids)
            if scoped:
                _log_progress(f"dedupe assembly contents scoped to {len(assembly_ids)} assemblies")
                _exec(
                    "CREATE TEMP TABLE tmp_dedupe_assemblies ON COMMIT DROP AS "
                    "SELECT unnest(%s::bigint[]) AS assembly_id",
                    (assembly_ids,),
                    conn=conn,
                )

            part_stats = _dedupe_assembly_parts(conn=conn, dry_run=dry_run, scoped=scoped)
            stats.parts_removed = part_stats.parts_removed
            stats.hotspots_on_dup_parts_removed = part_stats.hotspots_on_dup_parts_removed
            stats.price_snapshots_removed = part_stats.price_snapshots_removed
            stats.diagrams_removed = _dedupe_diagrams(conn=conn, dry_run=dry_run, scoped=scoped)
            stats.hotspots_removed = _dedupe_hotspots(conn=conn, dry_run=dry_run, scoped=scoped)

            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    mode = "dry-run" if dry_run else "apply"
    changed = stats.parts_removed + stats.hotspots_removed + stats.diagrams_removed
    message = (
        f"{mode}: removed {stats.parts_removed} duplicate parts, "
        f"{stats.diagrams_removed} duplicate diagrams, "
        f"{stats.hotspots_removed} duplicate hotspots "
        f"(removed {stats.hotspots_on_dup_parts_removed} hotspots on duplicate parts)."
        if changed
        else f"{mode}: no duplicate assembly contents found."
    )
    return {"dry_run": dry_run, "stats": stats.as_dict(), "message": message}

