"""Resumable variant repair from compare gaps (thin + missing variants)."""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TextIO

from app.db_migrate import apply_migrations
from app.db import get_conn
from app.importers.progress import ProgressReporter
from app.importers.remotors import AriNode, _client, _walk_catalog_tree

REPAIR_STATE_VERSION = 1

# Align tuning (override via env when running oem-remotors-pipeline.sh align).
_ALIGN_SNAPSHOT_FETCH = int(os.environ.get("REMOTORS_ALIGN_FETCH", "5000"))
_ALIGN_SCAN_BATCH = int(os.environ.get("REMOTORS_ALIGN_SCAN_BATCH", "10000"))
_ALIGN_COMMIT_EVERY = int(os.environ.get("REMOTORS_ALIGN_COMMIT_EVERY", "200"))
_ALIGN_PROGRESS_EVERY = int(os.environ.get("REMOTORS_ALIGN_PROGRESS_EVERY", "1000"))


def _log_line(message: str, *, stream: TextIO = sys.stdout) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, file=stream, flush=True)


def repair_key(item: dict[str, Any]) -> str:
    variant_id = item.get("variant_id")
    if variant_id is not None:
        return f"variant:{int(variant_id)}"
    arib = item.get("repair_arib") or ""
    aria = item.get("repair_aria") or ""
    return f"root:{arib}:{aria}"


def gap_item_to_repair_root(item: dict[str, Any]) -> AriNode | None:
    arib = item.get("repair_arib")
    aria = item.get("repair_aria")
    if not arib or not aria:
        return None
    path = list(item.get("repair_path") or [])
    title = item.get("repair_title") or (path[-1] if path else "")
    if not path:
        path = [title]
    return AriNode(
        title=title,
        arib=str(arib),
        aria=str(aria),
        rel="",
        slug=item.get("repair_slug"),
        parent_id=None,
        depth=max(len(path) - 1, 0),
        path=path,
    )


def collect_repair_items(
    gaps: dict[str, Any],
    *,
    variant_ids: set[int] | None = None,
    buckets: tuple[str, ...] = ("thin_variants", "missing_variants"),
) -> list[dict[str, Any]]:
    """Merge gap buckets; dedupe by repair root."""
    items: list[dict[str, Any]] = []
    seen: set[str] = set()
    for bucket in buckets:
        for row in gaps.get(bucket, []):
            if variant_ids is not None:
                row_variant_id = row.get("variant_id")
                if row_variant_id is None or int(row_variant_id) not in variant_ids:
                    continue
            key = repair_key(row)
            if key in seen:
                continue
            seen.add(key)
            items.append(dict(row))
    items.sort(
        key=lambda row: (
            -int(row.get("missing_assemblies") or row.get("remote_assemblies") or 0),
            row.get("brand_name") or "",
            row.get("model_name") or "",
        )
    )
    return items


def _variant_assembly_count(variant_id: int) -> int:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) AS cnt FROM oem_assemblies WHERE vehicle_variant_id = %s",
                (variant_id,),
            )
            row = cur.fetchone()
    return int(row["cnt"] if row else 0)


def _load_state(state_path: Path) -> dict[str, Any]:
    if not state_path.is_file():
        return {
            "version": REPAIR_STATE_VERSION,
            "completed_keys": [],
            "failed": {},
        }
    data = json.loads(state_path.read_text(encoding="utf-8"))
    data.setdefault("completed_keys", [])
    data.setdefault("failed", {})
    return data


def _save_state(state_path: Path, state: dict[str, Any]) -> None:
    state_path.parent.mkdir(parents=True, exist_ok=True)
    state["updated_at"] = datetime.now(timezone.utc).isoformat()
    tmp = state_path.with_suffix(state_path.suffix + ".tmp")
    tmp.write_text(json.dumps(state, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    tmp.replace(state_path)


def _should_skip_item(
    item: dict[str, Any],
    *,
    completed_keys: set[str],
    skip_complete: bool,
    force: bool,
) -> str | None:
    key = repair_key(item)
    if key in completed_keys and not force:
        return "checkpoint"
    variant_id = item.get("variant_id")
    if not skip_complete or force or not variant_id:
        return None
    missing_keys = item.get("missing_assembly_tokens")
    if missing_keys is None:
        missing_keys = item.get("missing_assembly_keys")
    if missing_keys is not None:
        if int(missing_keys) <= 0:
            return "already_complete"
        return None
    remote = item.get("remote_assemblies")
    if remote is None:
        local = _variant_assembly_count(int(variant_id))
        remote = local + int(item.get("missing_assemblies") or 0)
    local = _variant_assembly_count(int(variant_id))
    if local >= int(remote):
        return "already_complete"
    return None


def repair_from_gaps(
    *,
    gaps_path: str,
    state_path: str | None = None,
    limit: int | None = None,
    variant_ids: list[int] | None = None,
    download_images: bool = False,
    force: bool = False,
    skip_complete: bool = True,
    buckets: tuple[str, ...] = ("thin_variants", "missing_variants"),
) -> dict[str, Any]:
    """Re-crawl variant subtrees listed in compare .sync.json (resumable)."""
    migrated = apply_migrations()
    if migrated:
        _log_line(f"applied db migrations: {', '.join(migrated)}")

    gaps_file = Path(gaps_path)
    gaps = json.loads(gaps_file.read_text(encoding="utf-8"))
    if "thin_variants" not in gaps and "_full" in gaps:
        gaps = gaps["_full"]

    items = collect_repair_items(
        gaps,
        variant_ids=set(variant_ids) if variant_ids else None,
        buckets=buckets,
    )
    if limit is not None:
        items = items[: int(limit)]

    state_file = Path(state_path) if state_path else gaps_file.with_suffix(".repair-state.json")
    state = _load_state(state_file)
    state["gaps_path"] = str(gaps_path)
    state["gaps_mtime"] = gaps_file.stat().st_mtime
    completed_keys = set(state.get("completed_keys") or [])

    counters = {
        "planned": len(items),
        "repaired": 0,
        "skipped_checkpoint": 0,
        "skipped_complete": 0,
        "errors": 0,
        "assemblies": 0,
        "diagrams": 0,
        "parts": 0,
        "hotspots": 0,
        "skipped_assemblies": 0,
        "source_nodes": 0,
    }
    started = time.time()
    progress = ProgressReporter(
        total=max(len(items), 1),
        label="remotors_repair",
        min_interval_sec=5.0,
    )
    progress.set_stage("variant repair", len(items))

    _log_line(
        "repair start "
        f"items={len(items)} state={state_file} "
        f"download_images={download_images} force={force} skip_complete={skip_complete}"
    )

    for index, item in enumerate(items, start=1):
        key = repair_key(item)
        skip_reason = _should_skip_item(
            item,
            completed_keys=completed_keys,
            skip_complete=skip_complete,
            force=force,
        )
        if skip_reason == "checkpoint":
            counters["skipped_checkpoint"] += 1
            progress.advance(f"skip checkpoint {index}/{len(items)} {key}")
            continue
        if skip_reason == "already_complete":
            counters["skipped_complete"] += 1
            if key not in completed_keys:
                completed_keys.add(key)
                state["completed_keys"] = sorted(completed_keys)
                _save_state(state_file, state)
            progress.advance(f"skip complete {index}/{len(items)} {item.get('model_name', key)}")
            continue

        root = gap_item_to_repair_root(item)
        if not root:
            counters["errors"] += 1
            state["failed"][key] = "missing repair_arib/repair_aria"
            _save_state(state_file, state)
            progress.advance(f"ERROR missing repair root {index}/{len(items)} {key}")
            continue

        label = item.get("model_name") or root.title
        progress.advance(f"repair {index}/{len(items)} variant_id={item.get('variant_id')} {label}")

        variant_counters = {
            "source_nodes": 0,
            "assemblies": 0,
            "diagrams": 0,
            "parts": 0,
            "hotspots": 0,
            "skipped": 0,
            "errors": 0,
        }
        try:
            with _client() as client:
                _walk_catalog_tree(
                    client,
                    [root],
                    progress=progress,
                    counters=variant_counters,
                    allowed_years=None,
                    max_models=None,
                    max_assemblies=None,
                    download_images=download_images,
                    force=force,
                )
        except Exception as exc:
            counters["errors"] += 1
            state["failed"][key] = str(exc)
            _save_state(state_file, state)
            progress.advance(f"ERROR repair {index}/{len(items)} {label}: {exc}")
            continue

        counters["repaired"] += 1
        for field in ("assemblies", "diagrams", "parts", "hotspots", "source_nodes"):
            counters[field] += variant_counters.get(field, 0)
        counters["skipped_assemblies"] += variant_counters.get("skipped", 0)

        completed_keys.add(key)
        state["completed_keys"] = sorted(completed_keys)
        state["failed"].pop(key, None)
        _save_state(state_file, state)

    progress.finish("variant repair finished")
    elapsed = round(time.time() - started, 1)
    _log_line(
        "repair done "
        f"repaired={counters['repaired']} "
        f"skipped_checkpoint={counters['skipped_checkpoint']} "
        f"skipped_complete={counters['skipped_complete']} "
        f"errors={counters['errors']} assemblies={counters['assemblies']} "
        f"elapsed={elapsed}s state={state_file}"
    )
    return {
        "phase": "repair",
        "gaps_path": gaps_path,
        "state_path": str(state_file),
        "duration_seconds": elapsed,
        "counters": counters,
    }


def _build_local_variant_key_index() -> dict[str, int]:
    """Map snapshot variant_key -> local oem_vehicle_variants.id."""
    from app.importers.remotors_catalog import HIDDEN_CANONICAL_BRANDS, snapshot_variant_key

    hidden = tuple(sorted(HIDDEN_CANONICAL_BRANDS))
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  vv.id AS variant_id,
                  vt.code AS vehicle_type,
                  vv.market_name,
                  vv.source_designation,
                  vv.year_from,
                  vv.variant_section
                FROM oem_vehicle_variants vv
                JOIN oem_model_families mf ON mf.id = vv.model_family_id
                JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
                JOIN oem_brands b ON b.id = mf.brand_id
                WHERE b.normalized_name <> ALL(%s)
                """,
                (list(hidden),),
            )
            rows = list(cur.fetchall())

    index: dict[str, int] = {}
    for row in rows:
        key = snapshot_variant_key(
            vehicle_type=row["vehicle_type"],
            market_name=row.get("market_name"),
            source_designation=row.get("source_designation"),
            year_from=row["year_from"],
            variant_section=row.get("variant_section"),
        )
        index[key] = int(row["variant_id"])
    return index


def _snapshot_assembly_row_count(snapshot_path: str, *, limit: int | None = None) -> int:
    from app.importers.remotors_snapshot import open_snapshot

    conn = open_snapshot(snapshot_path)
    try:
        total = int(conn.execute("SELECT COUNT(*) FROM catalog_assemblies").fetchone()[0])
    finally:
        conn.close()
    if limit is not None:
        return min(total, int(limit))
    return total


def _iter_snapshot_assemblies(
    snapshot_path: str,
    *,
    limit: int | None = None,
    fetch_size: int = _ALIGN_SNAPSHOT_FETCH,
):
    from app.importers.remotors_snapshot import open_snapshot

    conn = open_snapshot(snapshot_path)
    try:
        cursor = conn.execute(
            """
            SELECT assembly_key, variant_key, arib, aria, slug, title
            FROM catalog_assemblies
            ORDER BY variant_key, aria
            """
        )
        yielded = 0
        while True:
            chunk = cursor.fetchmany(fetch_size)
            if not chunk:
                break
            for row in chunk:
                yield dict(row)
                yielded += 1
                if limit is not None and yielded >= int(limit):
                    return
    finally:
        conn.close()


def align_catalog_from_snapshot(
    *,
    snapshot_path: str,
    dry_run: bool = True,
    limit: int | None = None,
) -> dict[str, Any]:
    """Ensure every local variant owns complete assembly rows from the snapshot.

    Remotors reuses the same aria across many variants (shared diagrams). Moving one
    oem_assemblies row between variants cannot work — each variant needs its own row,
    cloned from a complete donor assembly for that aria.
    """
    from app.importers import writer
    from app.importers.remotors import SOURCE_CODE

    snapshot_file = Path(snapshot_path)
    if not snapshot_file.is_file():
        raise FileNotFoundError(f"snapshot not found: {snapshot_path}")

    source_id = writer.source_id(SOURCE_CODE)
    counters = {
        "snapshot_assemblies": 0,
        "already_linked": 0,
        "cloned": 0,
        "filled": 0,
        "incomplete_donor": 0,
        "missing_local_assembly": 0,
        "skipped_no_aria": 0,
        "skipped_no_local_variant": 0,
        "errors": 0,
        "commit_batches": 0,
    }
    started = time.time()
    mode = "dry-run" if dry_run else "apply"
    _log_line(
        f"align start {mode} snapshot={snapshot_path} "
        f"scan_batch={_ALIGN_SCAN_BATCH} commit_every={_ALIGN_COMMIT_EVERY} "
        f"progress_every={_ALIGN_PROGRESS_EVERY}"
    )

    _log_line("align preload: snapshot row count")
    total_rows = _snapshot_assembly_row_count(str(snapshot_file), limit=limit)
    counters["snapshot_assemblies"] = total_rows
    _log_line(f"align preload: snapshot rows={total_rows}")

    _log_line("align preload: local variant_key index")
    variant_index = _build_local_variant_key_index()
    _log_line(f"align preload: variant keys={len(variant_index)}")

    _log_line("align preload: aria donor lookup from PostgreSQL")
    lookup = _build_assembly_aria_lookup(source_id=source_id)
    _log_line(f"align preload: aria lookup rows={len(lookup)}")

    progress = ProgressReporter(
        total=max(total_rows, 1),
        label="remotors_align",
        min_interval_sec=3.0,
    )
    progress.set_stage("catalog align", total_rows)
    linked_cache: set[tuple[int, int]] = set()

    def _process_row(row: dict[str, Any]) -> str:
        variant_key = str(row["variant_key"])
        target_variant_id = variant_index.get(variant_key)
        if target_variant_id is None:
            counters["skipped_no_local_variant"] += 1
            return "skip"

        aria = str(row.get("aria") or "").strip()
        if not aria:
            counters["skipped_no_aria"] += 1
            return "skip"

        info = lookup.get(aria)
        if not info or not info.get("assembly_id"):
            counters["missing_local_assembly"] += 1
            return "skip"
        if not info.get("is_complete"):
            counters["incomplete_donor"] += 1
            return "skip"

        source_node_id = info.get("source_node_id")
        cache_key = (target_variant_id, int(source_node_id or 0))
        if cache_key in linked_cache:
            counters["already_linked"] += 1
            return "exists"

        if dry_run:
            counters["cloned"] += 1
            linked_cache.add(cache_key)
            return "cloned"

        _aid, action = writer.ensure_assembly_linked_on_variant(
            target_variant_id,
            row["title"],
            source_node_id,
            donor_assembly_id=int(info["assembly_id"]),
        )
        linked_cache.add(cache_key)
        if action == "exists":
            counters["already_linked"] += 1
        elif action == "filled":
            counters["filled"] += 1
        else:
            counters["cloned"] += 1
        return action

    row_iter = _iter_snapshot_assemblies(str(snapshot_file), limit=limit)
    scan_index = 0
    last_reported = 0

    def _maybe_report() -> None:
        nonlocal last_reported
        if scan_index == 0:
            return
        if scan_index != total_rows and scan_index != 1 and scan_index % _ALIGN_PROGRESS_EVERY != 0:
            return
        step = scan_index - last_reported
        last_reported = scan_index
        if dry_run:
            msg = (
                f"align {scan_index}/{total_rows} would_clone={counters['cloned']} "
                f"already={counters['already_linked']}"
            )
        else:
            msg = (
                f"align {scan_index}/{total_rows} filled={counters['filled']} "
                f"cloned={counters['cloned']} already={counters['already_linked']} "
                f"commits={counters['commit_batches']}"
            )
        progress.advance(msg, step=step)

    if dry_run:
        for row in row_iter:
            scan_index += 1
            try:
                _process_row(row)
            except Exception:
                counters["errors"] += 1
            _maybe_report()
    else:
        while True:
            with writer.batch_conn():
                batch_scans = 0
                batch_writes = 0
                while batch_scans < _ALIGN_SCAN_BATCH and batch_writes < _ALIGN_COMMIT_EVERY:
                    try:
                        row = next(row_iter)
                    except StopIteration:
                        break
                    scan_index += 1
                    batch_scans += 1
                    try:
                        action = _process_row(row)
                    except Exception:
                        counters["errors"] += 1
                        action = "error"
                    if action in ("filled", "cloned"):
                        batch_writes += 1
                    _maybe_report()
                if batch_writes:
                    counters["commit_batches"] += 1
                    _log_line(
                        f"align commit batch={counters['commit_batches']} "
                        f"scan={scan_index}/{total_rows} writes_in_batch={batch_writes}"
                    )
            if batch_scans == 0:
                break

    if last_reported < scan_index:
        progress.advance(
            f"align {scan_index}/{total_rows} final",
            step=scan_index - last_reported,
        )

    progress.finish("catalog align finished")
    elapsed = round(time.time() - started, 1)
    _log_line(
        f"align done {mode} snapshot_rows={counters['snapshot_assemblies']} "
        f"cloned={counters['cloned']} filled={counters['filled']} "
        f"already_linked={counters['already_linked']} "
        f"commit_batches={counters['commit_batches']} "
        f"incomplete_donor={counters['incomplete_donor']} "
        f"missing_local={counters['missing_local_assembly']} "
        f"no_variant={counters['skipped_no_local_variant']} errors={counters['errors']} "
        f"elapsed={elapsed}s"
    )
    return {
        "phase": "align",
        "dry_run": dry_run,
        "snapshot_path": snapshot_path,
        "duration_seconds": elapsed,
        "counters": counters,
    }


def _build_assembly_aria_lookup(*, source_id: int) -> dict[str, dict[str, Any]]:
    """Map aria -> best complete donor assembly (shared across many Remotors variants)."""
    rows = []
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                WITH ranked AS (
                  SELECT
                    sn.aria,
                    sn.id AS source_node_id,
                    a.id AS assembly_id,
                    a.vehicle_variant_id,
                    COUNT(DISTINCT d.id) AS diagram_count,
                    COUNT(DISTINCT ap.id) AS part_count,
                    ROW_NUMBER() OVER (
                      PARTITION BY sn.aria
                      ORDER BY COUNT(ap.id) DESC, COUNT(d.id) DESC, a.id ASC
                    ) AS rn
                  FROM oem_source_nodes sn
                  JOIN oem_assemblies a ON a.source_node_id = sn.id
                  LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
                  LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
                  WHERE sn.source_id = %s
                    AND sn.node_type = 'assembly'
                    AND sn.aria IS NOT NULL
                    AND sn.aria <> ''
                  GROUP BY sn.aria, sn.id, a.id, a.vehicle_variant_id
                )
                SELECT aria, source_node_id, assembly_id, vehicle_variant_id,
                       diagram_count, part_count
                FROM ranked
                WHERE rn = 1
                """,
                (source_id,),
            )
            rows = list(cur.fetchall())
    lookup: dict[str, dict[str, Any]] = {}
    for row in rows:
        aria = str(row["aria"])
        assembly_id = int(row["assembly_id"] or 0)
        lookup[aria] = {
            "source_node_id": int(row["source_node_id"] or 0) or None,
            "assembly_id": assembly_id or None,
            "vehicle_variant_id": int(row["vehicle_variant_id"] or 0) or None,
            "is_complete": bool(
                assembly_id and int(row["diagram_count"] or 0) and int(row["part_count"] or 0)
            ),
        }
    return lookup


def _load_snapshot_assemblies_by_variant(
    conn: sqlite3.Connection,
    variant_keys: list[str],
    *,
    batch_size: int = 500,
) -> dict[str, list[dict[str, Any]]]:
    grouped: dict[str, list[dict[str, Any]]] = {key: [] for key in variant_keys}
    for offset in range(0, len(variant_keys), batch_size):
        chunk = variant_keys[offset : offset + batch_size]
        placeholders = ",".join("?" for _ in chunk)
        query = (
            "SELECT variant_key, assembly_key, arib, aria, slug, title "
            f"FROM catalog_assemblies WHERE variant_key IN ({placeholders})"
        )
        for row in conn.execute(query, chunk).fetchall():
            grouped[str(row["variant_key"])].append(dict(row))
    return grouped


def _relink_single_variant(
    item: dict[str, Any],
    rows: list[dict[str, Any]],
    lookup: dict[str, dict[str, Any]],
    *,
    dry_run: bool,
) -> dict[str, int]:
    from app.importers import writer

    variant_id = int(item["variant_id"])
    counters = {"moved": 0, "cloned": 0, "filled": 0, "already_linked": 0, "incomplete": 0}
    needs_move = False
    linked: set[int] = set()
    for row in rows:
        aria = str(row.get("aria") or "")
        info = lookup.get(aria)
        if not info or not info["is_complete"]:
            counters["incomplete"] += 1
            continue
        source_node_id = info.get("source_node_id")
        if source_node_id in linked:
            counters["already_linked"] += 1
            continue
        if dry_run:
            counters["cloned"] += 1
            needs_move = True
            continue
        _aid, action = writer.ensure_assembly_linked_on_variant(
            variant_id,
            row["title"],
            source_node_id,
            donor_assembly_id=int(info["assembly_id"]),
        )
        linked.add(int(source_node_id or 0))
        if action == "exists":
            counters["already_linked"] += 1
        elif action == "filled":
            counters["filled"] += 1
            needs_move = True
        else:
            counters["cloned"] += 1
            needs_move = True
    counters["moved"] = counters["cloned"] + counters["filled"]
    counters["skipped_variant"] = 0 if needs_move else 1
    return counters


def relink_variants_from_gaps(
    *,
    gaps_path: str,
    snapshot_path: str | None = None,
    limit_variants: int | None = None,
    variant_ids: list[int] | None = None,
    dry_run: bool = True,
    workers: int = 1,
) -> dict[str, Any]:
    """Move mis-linked assemblies onto the variant_id snapshot assigns them to."""
    from app.importers.remotors_snapshot import open_snapshot
    from app.importers.remotors import SOURCE_CODE
    from app.importers import writer

    gaps_file = Path(gaps_path)
    gaps = json.loads(gaps_file.read_text(encoding="utf-8"))
    if "thin_variants" not in gaps and "_full" in gaps:
        gaps = gaps["_full"]

    items: list[dict[str, Any]] = []
    seen: set[int] = set()
    for bucket in ("thin_variants",):
        for row in gaps.get(bucket, []):
            variant_id = row.get("variant_id")
            variant_key = row.get("variant_key")
            if variant_id is None or not variant_key:
                continue
            variant_id = int(variant_id)
            if variant_ids is not None and variant_id not in variant_ids:
                continue
            if variant_id in seen:
                continue
            seen.add(variant_id)
            items.append(dict(row))

    if limit_variants is not None:
        items = items[: int(limit_variants)]

    snapshot_path = snapshot_path or gaps.get("snapshot_path")
    if not snapshot_path:
        raise ValueError("snapshot_path missing from gaps file")
    snapshot_file = Path(snapshot_path)
    if not snapshot_file.is_file():
        raise FileNotFoundError(f"snapshot not found: {snapshot_path}")

    counters = {
        "variants_planned": len(items),
        "variants_processed": 0,
        "variants_skipped_ok": 0,
        "assemblies_planned": 0,
        "already_linked": 0,
        "moved": 0,
        "incomplete": 0,
        "errors": 0,
    }
    workers = max(1, int(workers))
    started = time.time()
    progress = ProgressReporter(total=max(len(items), 1), label="remotors_relink", min_interval_sec=5.0)
    if workers > 1:
        progress.enable_thread_safe()
    progress.set_stage("variant relink", len(items))
    _log_line(
        f"relink start variants={len(items)} snapshot={snapshot_path} "
        f"dry_run={dry_run} workers={workers}"
    )

    from app.importers import writer

    source_id = writer.source_id(SOURCE_CODE)
    _log_line("relink preload: building aria lookup from PostgreSQL")
    lookup = _build_assembly_aria_lookup(source_id=source_id)
    _log_line(f"relink preload: aria lookup ready rows={len(lookup)}")

    variant_keys = [str(item["variant_key"]) for item in items]
    conn = open_snapshot(str(snapshot_file))
    try:
        _log_line(f"relink preload: loading snapshot assemblies for {len(variant_keys)} variants")
        assemblies_by_variant = _load_snapshot_assemblies_by_variant(conn, variant_keys)
    finally:
        conn.close()

    counter_lock = threading.Lock()

    def _merge_result(result: dict[str, int]) -> None:
        with counter_lock:
            if result.get("skipped_variant"):
                counters["variants_skipped_ok"] += 1
            else:
                counters["variants_processed"] += 1
            counters["already_linked"] += result["already_linked"]
            counters["moved"] += result["moved"]
            counters["incomplete"] += result["incomplete"]

    def _run_item(index: int, item: dict[str, Any]) -> tuple[int, dict[str, Any], dict[str, int] | None, str | None]:
        variant_id = int(item["variant_id"])
        variant_key = str(item["variant_key"])
        label = item.get("model_name") or variant_key
        rows = assemblies_by_variant.get(variant_key, [])
        try:
            if dry_run:
                result = _relink_single_variant(item, rows, lookup, dry_run=True)
            else:
                with writer.batch_conn():
                    result = _relink_single_variant(item, rows, lookup, dry_run=False)
            return index, item, result, None
        except Exception as exc:
            return index, item, None, str(exc)

    if workers == 1:
        for index, item in enumerate(items, start=1):
            variant_id = int(item["variant_id"])
            label = (item.get("model_name") or item["variant_key"])[:60]
            rows = assemblies_by_variant.get(str(item["variant_key"]), [])
            counters["assemblies_planned"] += len(rows)
            _index, _item, result, error = _run_item(index, item)
            if error:
                counters["errors"] += 1
                progress.advance(f"ERROR relink {index}/{len(items)} variant_id={variant_id} {label}: {error}")
                continue
            assert result is not None
            _merge_result(result)
            if result.get("skipped_variant"):
                progress.advance(f"skip ok {index}/{len(items)} variant_id={variant_id} assemblies={len(rows)}")
            else:
                progress.advance(
                    f"relink {index}/{len(items)} variant_id={variant_id} "
                    f"moved={result['moved']} snapshot_assemblies={len(rows)} {label}"
                )
    else:
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="remotors_relink") as pool:
            futures = {
                pool.submit(_run_item, index, item): (index, item)
                for index, item in enumerate(items, start=1)
            }
            for future in as_completed(futures):
                index, item = futures[future]
                variant_id = int(item["variant_id"])
                label = (item.get("model_name") or item["variant_key"])[:60]
                rows = assemblies_by_variant.get(str(item["variant_key"]), [])
                with counter_lock:
                    counters["assemblies_planned"] += len(rows)
                _index, _item, result, error = future.result()
                if error:
                    with counter_lock:
                        counters["errors"] += 1
                    progress.advance(
                        f"ERROR relink {index}/{len(items)} variant_id={variant_id} {label}: {error}"
                    )
                    continue
                assert result is not None
                _merge_result(result)
                if result.get("skipped_variant"):
                    progress.advance(
                        f"skip ok {index}/{len(items)} variant_id={variant_id} assemblies={len(rows)}"
                    )
                else:
                    progress.advance(
                        f"relink {index}/{len(items)} variant_id={variant_id} "
                        f"moved={result['moved']} snapshot_assemblies={len(rows)} {label}"
                    )

    progress.finish("variant relink finished")
    elapsed = round(time.time() - started, 1)
    mode = "dry-run" if dry_run else "apply"
    _log_line(
        f"relink done {mode} variants={counters['variants_processed']} "
        f"skipped_ok={counters['variants_skipped_ok']} "
        f"moved={counters['moved']} already_linked={counters['already_linked']} "
        f"incomplete={counters['incomplete']} errors={counters['errors']} "
        f"workers={workers} elapsed={elapsed}s"
    )
    return {
        "phase": "relink",
        "dry_run": dry_run,
        "gaps_path": gaps_path,
        "snapshot_path": snapshot_path,
        "duration_seconds": elapsed,
        "workers": workers,
        "counters": counters,
    }
