from __future__ import annotations

import shutil
from pathlib import Path
from typing import Any, Callable

from app.config import get_settings
from app.db import get_yamaha_conn
from app.yamaha_v1.import_structure import _purge_root_structure

from .constants import (
    DEFAULT_SIDECAR_PATH,
    DEFAULT_SNAPSHOT_PATH,
    HTML_SUBDIR,
    ILLUST_SUBDIR,
    ROOT_ARIB,
)

# Legacy yamaha-motor.com artifacts also wiped on full reset.
LEGACY_US_SNAPSHOT = "storage/yamaha-snapshot-us-v1.db"
LEGACY_US_JSON = "storage/yamaha-json/YMH-US"
LEGACY_US_IMAGES_SUBDIR = "yamaha"  # asset_root/yamaha/YMH-US
LEGACY_WALK_PHASE = "yamaha-us-walk"


def _emit(log: Callable[[str], None] | None, message: str) -> None:
    if log is not None:
        log(message)
    else:
        print(f"[yamaha-ps-reset] {message}", flush=True)


def _delete_path(path: Path, *, log: Callable[[str], None] | None) -> bool:
    if not path.exists():
        _emit(log, f"skip missing {path}")
        return False
    if path.is_dir():
        shutil.rmtree(path)
    else:
        path.unlink()
    _emit(log, f"deleted {path}")
    return True


def purge_catalog(*, log: Callable[[str], None] | None = None) -> dict[str, Any]:
    """Remove all YMH-US rows from yamaha PostgreSQL (JP/EU untouched)."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            _purge_root_structure(cur, root_arib=ROOT_ARIB, log=lambda m: _emit(log, m))
            cur.execute(
                "DELETE FROM oem_crawl_checkpoints WHERE phase = %s OR item_key LIKE %s",
                (LEGACY_WALK_PHASE, f"{ROOT_ARIB}%"),
            )
            checkpoints = int(cur.rowcount or 0)
            _emit(log, f"purge checkpoints deleted={checkpoints}")
        conn.commit()
    return {"root_arib": ROOT_ARIB, "purged": True, "checkpoints_deleted": checkpoints}


def reset_local_assets(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
    delete_snapshot: bool = True,
    delete_html: bool = True,
    delete_images: bool = True,
    delete_legacy: bool = True,
    log: Callable[[str], None] | None = None,
) -> dict[str, Any]:
    stats = {
        "snapshot_deleted": False,
        "sidecar_deleted": False,
        "html_deleted": False,
        "images_deleted": False,
        "legacy_deleted": [],
    }
    if delete_snapshot:
        snap = Path(snapshot_path)
        stats["snapshot_deleted"] = _delete_path(snap, log=log)
        for suffix in ("-wal", "-shm", "-journal"):
            _delete_path(Path(f"{snap}{suffix}"), log=log)
        # Work copy lives on container overlay (/tmp), not bind mount.
        work = Path("/tmp/oem-yamaha-ps") / snap.name
        _delete_path(work, log=log)
        for suffix in ("-wal", "-shm", "-journal"):
            _delete_path(Path(f"{work}{suffix}"), log=log)
        stats["sidecar_deleted"] = _delete_path(Path(DEFAULT_SIDECAR_PATH), log=log)
    asset_root = Path(get_settings().asset_root)
    storage_parent = asset_root.parent
    if delete_html:
        stats["html_deleted"] = _delete_path(storage_parent / HTML_SUBDIR / ROOT_ARIB, log=log)
    if delete_images:
        stats["images_deleted"] = _delete_path(asset_root / ILLUST_SUBDIR / ROOT_ARIB, log=log)
    if delete_legacy:
        for rel in (LEGACY_US_SNAPSHOT, LEGACY_US_JSON):
            if _delete_path(Path(rel), log=log):
                stats["legacy_deleted"].append(rel)
        legacy_img = asset_root / LEGACY_US_IMAGES_SUBDIR / ROOT_ARIB
        if _delete_path(legacy_img, log=log):
            stats["legacy_deleted"].append(str(legacy_img))
    return stats


def reset_pipeline(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
    purge_pg: bool = True,
    delete_snapshot: bool = True,
    delete_html: bool = True,
    delete_images: bool = True,
    delete_legacy: bool = True,
    log: Callable[[str], None] | None = None,
) -> dict[str, Any]:
    """Full YMH-US reset for PartStream pipeline (+ legacy motor.com artifacts)."""
    result: dict[str, Any] = {"root_arib": ROOT_ARIB}
    if purge_pg:
        result["catalog"] = purge_catalog(log=log)
    result["assets"] = reset_local_assets(
        snapshot_path=snapshot_path,
        delete_snapshot=delete_snapshot,
        delete_html=delete_html,
        delete_images=delete_images,
        delete_legacy=delete_legacy,
        log=log,
    )
    return result
