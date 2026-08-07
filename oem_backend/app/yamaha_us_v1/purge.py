from __future__ import annotations

import shutil
from pathlib import Path
from typing import Any, Callable

from app.config import get_settings
from app.db import get_yamaha_conn
from app.yamaha_v1.import_structure import _purge_root_structure

from .constants import DEFAULT_SNAPSHOT_PATH, ILLUST_SUBDIR, JSON_STORAGE_ROOT, ROOT_ARIB
from .walk import WALK_PHASE


def _delete_path(path: Path, *, log: Callable[[str], None]) -> bool:
    if not path.exists():
        log(f"skip missing {path}")
        return False
    if path.is_dir():
        shutil.rmtree(path)
    else:
        path.unlink()
    log(f"deleted {path}")
    return True


def purge_catalog(*, log: Callable[[str], None] | None = None) -> dict[str, Any]:
    """Remove all YMH-US rows from PostgreSQL (nav, variants, assemblies, crawl state)."""

    def _emit(message: str) -> None:
        if log is not None:
            log(message)

    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            _purge_root_structure(cur, root_arib=ROOT_ARIB, log=_emit)
            _emit(f"purge {ROOT_ARIB}: walk checkpoints")
            cur.execute(
                "DELETE FROM oem_crawl_checkpoints WHERE phase = %s",
                (WALK_PHASE,),
            )
            checkpoints = int(cur.rowcount or 0)
            _emit(f"purge {ROOT_ARIB}: crawl checkpoints deleted={checkpoints}")
        conn.commit()

    return {"root_arib": ROOT_ARIB, "purged": True, "walk_checkpoints_deleted": checkpoints}


def reset_local_assets(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
    delete_snapshot: bool = False,
    delete_json: bool = False,
    delete_images: bool = False,
    log: Callable[[str], None] | None = None,
) -> dict[str, Any]:
    """Delete local snapshot/JSON/PNG artifacts for YMH-US."""

    def _emit(message: str) -> None:
        if log is not None:
            log(message)

    stats = {
        "snapshot_deleted": False,
        "json_deleted": False,
        "images_deleted": False,
    }

    if delete_snapshot:
        stats["snapshot_deleted"] = _delete_path(Path(snapshot_path), log=_emit)
    if delete_json:
        stats["json_deleted"] = _delete_path(Path(JSON_STORAGE_ROOT) / ROOT_ARIB, log=_emit)
    if delete_images:
        asset_root = Path(get_settings().asset_root)
        stats["images_deleted"] = _delete_path(asset_root / ILLUST_SUBDIR / ROOT_ARIB, log=_emit)

    return stats


def reset_pipeline(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
    purge_pg: bool = True,
    delete_snapshot: bool = True,
    delete_json: bool = True,
    delete_images: bool = True,
    log: Callable[[str], None] | None = None,
) -> dict[str, Any]:
    """Full YMH-US reset: PostgreSQL purge + local snapshot/JSON/PNG wipe."""

    result: dict[str, Any] = {"root_arib": ROOT_ARIB}
    if purge_pg:
        result["catalog"] = purge_catalog(log=log)
    result["assets"] = reset_local_assets(
        snapshot_path=snapshot_path,
        delete_snapshot=delete_snapshot,
        delete_json=delete_json,
        delete_images=delete_images,
        log=log,
    )
    return result
