from __future__ import annotations

import re
import struct
import time
from pathlib import Path
from typing import Any

from app.config import get_settings
from app.db import get_conn
from app.remotors_v3.constants import PROGRESS_INTERVAL_SEC
from app.remotors_v3.progress import ProgressReporter


ORIG_WIDTH_RE = re.compile(r'origWidth="(\d+(?:\.\d+)?)"')


def _png_dimensions(path: Path) -> tuple[int, int] | None:
    if not path.is_file():
        return None
    with path.open("rb") as handle:
        header = handle.read(24)
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    width, height = struct.unpack(">II", header[16:24])
    if width <= 0 or height <= 0:
        return None
    return int(width), int(height)


def _read_orig_width(path: Path) -> float | None:
    if not path.is_file():
        return None
    match = ORIG_WIDTH_RE.search(path.read_text(errors="ignore"))
    if not match:
        return None
    value = float(match.group(1))
    return value if value > 0 else None


def _count_rows(*, force: bool, worker_id: int, workers: int) -> int:
    where = [
        "d.local_path IS NOT NULL",
        "(a.id %% %s) = %s",
    ]
    params: list[Any] = [workers, worker_id]
    if not force:
        where.append("(d.coord_width IS NULL OR d.coord_height IS NULL)")
    with get_conn() as conn:
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
    with get_conn() as conn:
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


def _update_coord_space(diagram_id: int, coord_width: float, coord_height: float) -> None:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_diagrams
                SET coord_width = %s,
                    coord_height = %s,
                    updated_at = now()
                WHERE id = %s
                """,
                (coord_width, coord_height, diagram_id),
            )
        conn.commit()


def backfill_diagram_coords(
    *,
    limit: int | None = None,
    force: bool = False,
    worker_id: int = 0,
    workers: int = 1,
) -> dict[str, int]:
    if workers < 1:
        raise ValueError("workers must be >= 1")
    if worker_id < 0 or worker_id >= workers:
        raise ValueError(f"worker_id must be 0..{workers - 1}")

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
    stats = {"ok": 0, "missing_html": 0, "missing_orig_width": 0, "missing_png": 0, "errors": 0}
    last_tick = time.monotonic()

    for row in rows:
        assembly_id = int(row["assembly_id"])
        root_arib = str(row["root_arib"])
        html_path = storage_root / "remotors-html" / root_arib / f"{assembly_id}.html"
        image_path = Path(settings.asset_root) / str(row["local_path"])
        try:
            if not html_path.is_file():
                stats["missing_html"] += 1
                progress.advance(f"missing html assembly={assembly_id}")
                continue
            orig_width = _read_orig_width(html_path)
            if not orig_width:
                stats["missing_orig_width"] += 1
                progress.advance(f"missing origWidth assembly={assembly_id}")
                continue
            png_size = _png_dimensions(image_path)
            if not png_size:
                stats["missing_png"] += 1
                progress.advance(f"missing png assembly={assembly_id}")
                continue
            image_width, image_height = png_size
            coord_height = orig_width * image_height / image_width
            _update_coord_space(
                int(row["diagram_id"]),
                round(orig_width, 4),
                round(coord_height, 4),
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
