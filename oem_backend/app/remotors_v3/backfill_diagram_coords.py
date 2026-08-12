from __future__ import annotations

import time
from pathlib import Path
from typing import Any

import psycopg
from psycopg.rows import dict_row

from app.config import get_settings
from app.remotors_v3.catalog_context import (
    catalog_dsn,
    html_storage_root_name,
    set_catalog_db,
)
from app.remotors_v3.constants import PROGRESS_INTERVAL_SEC
from app.remotors_v3.coord_space import compute_coord_space, png_dimensions, read_orig_width
from app.remotors_v3.progress import ProgressReporter


def _pg_conn() -> psycopg.Connection:
    return psycopg.connect(catalog_dsn(), row_factory=dict_row, autocommit=False)


def _count_rows(*, force: bool, worker_id: int, workers: int) -> int:
    where = [
        "d.local_path IS NOT NULL",
        "(a.id %% %s) = %s",
    ]
    params: list[Any] = [workers, worker_id]
    if not force:
        where.append("(d.coord_width IS NULL OR d.coord_height IS NULL)")
    with _pg_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT COUNT(*) AS c
                FROM oem_diagrams d
                JOIN oem_assemblies a ON a.id = d.assembly_id
                WHERE {" AND ".join(where)}
                """,
                tuple(params),
            )
            return int(cur.fetchone()["c"])


def _fetch_rows(*, force: bool, worker_id: int, workers: int, limit: int | None) -> list[dict[str, Any]]:
    where = [
        "d.local_path IS NOT NULL",
        "(a.id %% %s) = %s",
    ]
    params: list[Any] = [workers, worker_id]
    if not force:
        where.append("(d.coord_width IS NULL OR d.coord_height IS NULL)")
    if limit is not None:
        params.append(limit)
    with _pg_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT
                  d.id AS diagram_id,
                  d.local_path,
                  a.id AS assembly_id,
                  a.root_arib
                FROM oem_diagrams d
                JOIN oem_assemblies a ON a.id = d.assembly_id
                WHERE {" AND ".join(where)}
                ORDER BY a.id
                { "LIMIT %s" if limit is not None else "" }
                """,
                tuple(params),
            )
            return list(cur.fetchall())


def _update_coord_space(
    diagram_id: int,
    *,
    image_width: int,
    image_height: int,
    coord_width: float,
    coord_height: float,
) -> None:
    with _pg_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_diagrams
                SET width = %s,
                    height = %s,
                    coord_width = %s,
                    coord_height = %s,
                    updated_at = now()
                WHERE id = %s
                """,
                (image_width, image_height, coord_width, coord_height, diagram_id),
            )
        conn.commit()


def backfill_diagram_coords(
    *,
    limit: int | None = None,
    force: bool = False,
    worker_id: int = 0,
    workers: int = 1,
    db_code: str = "remotors",
) -> dict[str, int]:
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")
    set_catalog_db(db_code)

    if worker_id > 0 and workers > 1:
        time.sleep(worker_id)

    total = _count_rows(force=force, worker_id=worker_id, workers=workers)
    if limit is not None:
        total = min(total, limit)
    label = "diagram-coords" if workers == 1 else f"diagram-coords-w{worker_id}/{workers}"
    progress = ProgressReporter(total=total, label=label)
    progress.set_stage("backfill", total)

    rows = _fetch_rows(force=force, worker_id=worker_id, workers=workers, limit=limit)
    settings = get_settings()
    storage_root = Path(settings.asset_root).parent
    html_root = html_storage_root_name()
    stats = {"ok": 0, "missing_html": 0, "missing_orig_width": 0, "missing_png": 0, "errors": 0}
    last_tick = time.monotonic()

    for row in rows:
        assembly_id = int(row["assembly_id"])
        root_arib = str(row["root_arib"])
        html_path = storage_root / html_root / root_arib / f"{assembly_id}.html"
        image_path = Path(settings.asset_root) / str(row["local_path"])
        try:
            if not html_path.is_file():
                stats["missing_html"] += 1
                progress.advance(f"missing html assembly={assembly_id}")
                continue
            orig_width = read_orig_width(html_path)
            if not orig_width:
                stats["missing_orig_width"] += 1
                progress.advance(f"missing origWidth assembly={assembly_id}")
                continue
            png_size = png_dimensions(image_path)
            if not png_size:
                stats["missing_png"] += 1
                progress.advance(f"missing png assembly={assembly_id}")
                continue
            image_width, image_height = png_size
            coord_width, coord_height = compute_coord_space(
                orig_width=orig_width,
                image_width=image_width,
                image_height=image_height,
            )
            _update_coord_space(
                int(row["diagram_id"]),
                image_width=image_width,
                image_height=image_height,
                coord_width=coord_width,
                coord_height=coord_height,
            )
            stats["ok"] += 1
            progress.advance(f"coords assembly={assembly_id} width={orig_width:.0f}")
        except Exception as exc:
            stats["errors"] += 1
            progress.advance(f"ERROR assembly={assembly_id}: {exc}")

        if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
            progress.tick(f"ok={stats['ok']} errors={stats['errors']}")
            last_tick = time.monotonic()

    progress.finish(f"coord backfill stats={stats}")
    return stats
