"""Selective Remotors sync from compare gaps (snapshot-backed, no API tree re-crawl)."""

from __future__ import annotations

import json
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TextIO

from app.db_migrate import apply_migrations
from app.importers.progress import ProgressReporter
from app.importers.remotors import (
    AriNode,
    _client,
    _import_assembly_detail,
    import_assembly_image,
)
from app.importers.remotors_snapshot import assembly_node_for_gap, gap_item_to_ari_node, open_snapshot

DEFAULT_WORKERS = 5


def _log_line(message: str, *, stream: TextIO = sys.stdout) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, file=stream, flush=True)


def _load_gaps(gaps_path: str) -> dict[str, Any]:
    data = json.loads(Path(gaps_path).read_text(encoding="utf-8"))
    if "missing_variants" in data:
        return data
    if "_full" in data:
        return data["_full"]
    raise ValueError(f"Invalid gaps file (expected .sync.json): {gaps_path}")


def _merge_detail(counters: dict[str, int], detail: dict[str, int]) -> None:
    for key in ("assemblies_direct", "assemblies", "parts", "hotspots", "skipped", "diagrams", "errors"):
        if key in detail:
            target = "assemblies_direct" if key == "assemblies" else key
            if target in counters:
                counters[target] += detail[key]


def _import_task(
    task: tuple[int, AriNode],
    *,
    force: bool,
    download_images: bool,
    progress: ProgressReporter,
) -> dict[str, int]:
    index, node = task
    with _client() as client:
        return _import_assembly_detail(
            client,
            node,
            progress,
            download_images=download_images,
            force=force,
        )


def _run_assembly_jobs(
    tasks: list[tuple[int, AriNode]],
    *,
    force: bool,
    download_images: bool,
    workers: int,
    progress: ProgressReporter,
    counters: dict[str, int],
    error_prefix: str,
) -> None:
    if not tasks:
        return

    workers = max(1, min(int(workers), len(tasks)))
    if workers > 1:
        progress.enable_thread_safe()

    if workers == 1:
        for index, node in tasks:
            try:
                detail = _import_task((index, node), force=force, download_images=download_images, progress=progress)
                _merge_detail(counters, detail)
            except Exception as exc:
                counters["errors"] += 1
                progress.advance(
                    f"ERROR {error_prefix} {index} {node.arib} / {' / '.join(node.path)}: {exc}"
                )
        return

    counter_lock = threading.Lock()

    def _worker(task: tuple[int, AriNode]) -> None:
        index, node = task
        try:
            detail = _import_task(task, force=force, download_images=download_images, progress=progress)
            with counter_lock:
                _merge_detail(counters, detail)
        except Exception as exc:
            with counter_lock:
                counters["errors"] += 1
            progress.advance(
                f"ERROR {error_prefix} {index} {node.arib} / {' / '.join(node.path)}: {exc}"
            )

    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="remotors_sync") as pool:
        futures = [pool.submit(_worker, task) for task in tasks]
        for future in as_completed(futures):
            future.result()


ImageTask = tuple[int, AriNode, int | None]


def _import_image_task(
    task: ImageTask,
    *,
    force: bool,
    progress: ProgressReporter,
) -> dict[str, int]:
    index, node, assembly_id = task
    with _client() as client:
        return import_assembly_image(
            client,
            node,
            progress,
            assembly_id=assembly_id,
            force=force,
        )


def _run_image_jobs(
    tasks: list[ImageTask],
    *,
    force: bool,
    workers: int,
    progress: ProgressReporter,
    counters: dict[str, int],
) -> None:
    if not tasks:
        return

    workers = max(1, min(int(workers), len(tasks)))
    if workers > 1:
        progress.enable_thread_safe()

    if workers == 1:
        for task in tasks:
            try:
                detail = _import_image_task(task, force=force, progress=progress)
                _merge_detail(counters, detail)
            except Exception as exc:
                index, node, _assembly_id = task
                counters["errors"] += 1
                progress.advance(
                    f"ERROR image {index} {node.arib} / {' / '.join(node.path)}: {exc}"
                )
        return

    counter_lock = threading.Lock()

    def _worker(task: ImageTask) -> None:
        index, node, _assembly_id = task
        try:
            detail = _import_image_task(task, force=force, progress=progress)
            with counter_lock:
                _merge_detail(counters, detail)
        except Exception as exc:
            with counter_lock:
                counters["errors"] += 1
            progress.advance(
                f"ERROR image {index} {node.arib} / {' / '.join(node.path)}: {exc}"
            )

    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="remotors_sync_img") as pool:
        futures = [pool.submit(_worker, task) for task in tasks]
        for future in as_completed(futures):
            future.result()


def sync_structure(
    *,
    gaps_path: str,
    snapshot_path: str | None = None,
    limit_variants: int | None = None,
    limit_assemblies: int | None = None,
    force: bool = False,
    workers: int = DEFAULT_WORKERS,
) -> dict[str, Any]:
    """Import missing assemblies from snapshot (GetDetails only, no images, no tree crawl)."""
    if limit_variants is not None:
        _log_line(f"WARNING limit_variants={limit_variants} ignored (tree crawl removed; use limit_assemblies)")

    migrated = apply_migrations()
    if migrated:
        _log_line(f"applied db migrations: {', '.join(migrated)}")

    gaps = _load_gaps(gaps_path)
    snapshot_path = snapshot_path or gaps.get("snapshot_path")
    assembly_items = list(gaps.get("missing_assemblies", []))

    if limit_assemblies is not None:
        assembly_items = assembly_items[:limit_assemblies]

    _log_line(
        "sync structure start "
        f"direct_assemblies={len(assembly_items)} workers={workers} "
        f"download_images=false force={force}"
    )

    counters = {
        "assemblies_direct": 0,
        "parts": 0,
        "hotspots": 0,
        "skipped": 0,
        "errors": 0,
    }
    started = time.time()
    progress = ProgressReporter(total=max(len(assembly_items), 1), label="remotors_sync")

    if not assembly_items:
        progress.finish("structure sync finished (nothing to import)")
        return {
            "phase": "structure",
            "download_images": False,
            "duration_seconds": 0,
            "assemblies_planned": 0,
            "workers": workers,
            "counters": counters,
        }

    if not snapshot_path:
        _log_line("WARNING no snapshot_path in gaps — using slug/path from gaps file only")
        snapshot_file = None
    else:
        snapshot_file = Path(snapshot_path)
        if not snapshot_file.is_file():
            _log_line(f"WARNING snapshot not found: {snapshot_path} — using slug/path from gaps file only")
            snapshot_file = None

    conn = open_snapshot(snapshot_file) if snapshot_file else None
    tasks: list[tuple[int, AriNode]] = []
    try:
        for index, item in enumerate(assembly_items, start=1):
            node = assembly_node_for_gap(conn, item)
            if not node or not node.slug:
                counters["errors"] += 1
                progress.advance(
                    f"ERROR missing slug/path {index}/{len(assembly_items)} "
                    f"{item.get('assembly_key', '')}"
                )
                continue
            tasks.append((index, node))
    finally:
        if conn is not None:
            conn.close()

    progress.set_stage("missing assemblies", len(assembly_items))
    _log_line(
        f"sync direct assemblies start count={len(tasks)} queued "
        f"snapshot={snapshot_path if snapshot_file else 'none'} workers={workers}"
    )
    _run_assembly_jobs(
        tasks,
        force=force,
        download_images=False,
        workers=workers,
        progress=progress,
        counters=counters,
        error_prefix="assembly",
    )

    progress.finish("structure sync finished")
    _log_line(
        "sync structure done "
        f"assemblies={counters['assemblies_direct']} "
        f"parts={counters['parts']} skipped={counters['skipped']} errors={counters['errors']} "
        f"workers={workers} elapsed={round(time.time() - started, 1)}s"
    )
    return {
        "phase": "structure",
        "download_images": False,
        "duration_seconds": round(time.time() - started, 1),
        "assemblies_planned": len(assembly_items),
        "workers": workers,
        "counters": counters,
    }


def sync_images(
    *,
    gaps_path: str,
    snapshot_path: str | None = None,
    limit: int | None = None,
    force: bool = False,
    workers: int = DEFAULT_WORKERS,
) -> dict[str, Any]:
    """Download diagram PNGs for assemblies listed in missing_images."""
    apply_migrations()

    gaps = _load_gaps(gaps_path)
    snapshot_path = snapshot_path or gaps.get("snapshot_path")
    items = list(gaps.get("missing_images", []))
    if limit is not None:
        items = items[:limit]

    _log_line(f"sync images start count={len(items)} workers={workers} force={force}")

    counters = {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 0}
    started = time.time()
    progress = ProgressReporter(
        total=max(len(items), 1),
        label="remotors_sync_images",
        min_interval_sec=5.0,
    )

    tasks: list[ImageTask] = []
    progress.set_stage("resolve", len(items))
    for index, item in enumerate(items, start=1):
        node = gap_item_to_ari_node(item)
        if not node or not node.slug:
            counters["errors"] += 1
            progress.advance(
                f"ERROR image {index}/{len(items)} missing slug/path "
                f"{item.get('assembly_key', '')}"
            )
            continue
        assembly_id = item.get("assembly_id")
        tasks.append((index, node, int(assembly_id) if assembly_id else None))
        if index == 1 or index % 500 == 0 or index == len(items):
            progress.advance(f"resolved {index}/{len(items)}")

    progress.set_stage("download", len(tasks))
    _run_image_jobs(
        tasks,
        force=force,
        workers=workers,
        progress=progress,
        counters=counters,
    )

    progress.finish("image sync finished")
    _log_line(
        "sync images done "
        f"diagrams={counters['diagrams']} errors={counters['errors']} "
        f"workers={workers} elapsed={round(time.time() - started, 1)}s"
    )
    return {
        "phase": "images",
        "download_images": True,
        "duration_seconds": round(time.time() - started, 1),
        "planned": len(items),
        "workers": workers,
        "counters": counters,
    }


def render_sync_script(gaps_path: str, output: str, *, snapshot_path: str | None = None) -> str:
    """Generate bash script for batched sync."""
    gaps = _load_gaps(gaps_path)
    assemblies = gaps.get("missing_assemblies", [])
    images = gaps.get("missing_images", [])
    thin = gaps.get("thin_variants", [])
    missing_variants = gaps.get("missing_variants", [])
    snapshot = snapshot_path or gaps.get("snapshot_path") or ""
    snapshot_arg = ""
    if snapshot:
        snap_in_container = snapshot if snapshot.startswith("/app/") else f"/app/{snapshot.lstrip('/')}"
        snapshot_arg = f' --snapshot "{snap_in_container}"'

    lines = [
        "#!/bin/bash",
        "set -euo pipefail",
        "# Auto-generated Remotors gap sync. Review before running.",
        "",
        'COMPOSE="docker compose"',
        'if [ -f docker-compose.prod.yml ]; then',
        '  COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"',
        "fi",
        "",
    ]
    gaps_in_container = gaps_path
    if not gaps_in_container.startswith("/app/"):
        gaps_in_container = f"/app/{gaps_path.lstrip('/')}"
    state_in_container = gaps_in_container.replace(".sync.json", ".repair-state.json")
    lines.extend(
        [
            f'GAPS="{gaps_in_container}"',
            f'STATE="{state_in_container}"',
            'WORKERS="${REMOTORS_SYNC_WORKERS:-5}"',
            "",
            "# Phase 0: dedupe duplicate variants/assemblies in PostgreSQL",
            'echo "=== Dedupe variants + assemblies + contents ==="',
            "$COMPOSE exec -T oem_backend python -m app.cli fix-remotors-duplicate-variants --apply",
            "$COMPOSE exec -T oem_backend python -m app.cli fix-remotors-duplicate-assemblies --apply",
            "$COMPOSE exec -T oem_backend python -m app.cli fix-remotors-duplicate-assembly-contents --apply",
            "",
            "# Phase 0b: fix arib prefix mismatches (HUM/KTM) + backfill keys",
            'echo "=== Normalize assembly keys ==="',
            "$COMPOSE exec -T oem_backend python -m app.cli fix-remotors-assembly-arib-from-brand --apply",
            "$COMPOSE exec -T oem_backend python -m app.cli backfill-remotors-assembly-keys --apply",
            "",
            f"# Phase 1: global align from snapshot ({len(thin)} thin variants — relink all catalog_assemblies)",
            'echo "=== Align assembly→variant linkage from snapshot (single-threaded) ==="',
            "$COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \\",
            f'  --gaps "$GAPS" --phase align{snapshot_arg}',
            "",
            "# Phase 2: structure (assemblies missing locally, no images)",
            f'echo "=== Sync structure: {len(assemblies)} assemblies, workers=$WORKERS ==="',
            "$COMPOSE exec -T oem_backend python -m app.cli sync-remotors-gaps \\",
            '  --gaps "$GAPS" --phase structure --workers "$WORKERS"',
            "",
            f"# Phase 3: repair variants absent locally ({len(missing_variants)} missing_variants only)",
            'echo "=== Repair missing variants (structure only, resumable) ==="',
            "$COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \\",
            '  --gaps "$GAPS" --phase repair-missing --state "$STATE" --no-images',
            "",
            "# Phase 4: align again after imports",
            'echo "=== Align again ==="',
            "$COMPOSE exec -T -e PYTHONUNBUFFERED=1 oem_backend python -u -m app.cli sync-remotors-gaps \\",
            f'  --gaps "$GAPS" --phase align{snapshot_arg}',
            "",
            "# Phase 5: structure pass for newly imported assemblies",
            'echo "=== Sync structure (second pass) ==="',
            "$COMPOSE exec -T oem_backend python -m app.cli sync-remotors-gaps \\",
            '  --gaps "$GAPS" --phase structure --workers "$WORKERS"',
            "",
            "# Phase 6: diagram images",
            f'echo "=== Sync images: {len(images)} assemblies, workers=$WORKERS ==="',
            "$COMPOSE exec -T oem_backend python -m app.cli sync-remotors-gaps \\",
            '  --gaps "$GAPS" --phase images --workers "$WORKERS"',
            "",
            "# Phase 7: verify",
            "bash scripts/oem-remotors-pipeline.sh compare",
            "",
        ]
    )
    content = "\n".join(lines) + "\n"
    Path(output).write_text(content, encoding="utf-8")
    return content
