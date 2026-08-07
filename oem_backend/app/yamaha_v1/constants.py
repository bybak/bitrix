from __future__ import annotations

API_BASE = "https://parts.yamaha-motor.co.jp/ypec_b2c/services/html5"
ASSET_HOST = "https://ypec-sss.yamaha-motor.co.jp"

DEFAULT_LANG_ID = "25"
DEFAULT_CATALOG_LANG_ID = "01"

REGION_CONFIG: dict[str, dict[str, str]] = {
    "YMH-JP": {
        "base_code": "J001",
        "root_arib": "YMH-JP",
        "label": "Япония",
    },
    "YMH-EU": {
        "base_code": "7329",
        "root_arib": "YMH-EU",
        "label": "Европа",
    },
    "YMH-US": {
        "base_code": "",
        "root_arib": "YMH-US",
        "label": "США",
    },
}

ROOT_ARIB_CODES = tuple(REGION_CONFIG.keys())

SNAPSHOT_SCHEMA_VERSION = "yamaha-v1"
BULK_BATCH_SIZE = 500
PROGRESS_INTERVAL_SEC = 5.0
SNAPSHOT_FLUSH_SIZE = 100

JSON_STORAGE_ROOT = "storage/yamaha-json"
ILLUST_SUBDIR = "yamaha"

DEFAULT_TEST_PRODUCT_ID = "10"
DEFAULT_TEST_DISPLACEMENT_TYPE = "4"
DEFAULT_FULL_PRODUCT_ID = "all"
DEFAULT_FULL_DISPLACEMENT_TYPE = "all"
