from __future__ import annotations

ROOT_ARIB_CODES: tuple[str, ...] = ("HUM", "KTM", "LNX", "BRP")

ROOT_DISPLAY_NAMES: dict[str, str] = {
    "HUM": "Husqvarna",
    "KTM": "KTM",
    "LNX": "Lynx",
    "BRP": "BRP",
}

SNAPSHOT_SCHEMA_VERSION = 3
SOURCE_CODE = "remotors_ari"
PROGRESS_INTERVAL_SEC = 5.0
BULK_BATCH_SIZE = 2000
