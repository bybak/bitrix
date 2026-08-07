from __future__ import annotations

ROOT_ARIB = "YMH-US"

# Public site now uses Imperva-fronted gateway; old POC host resolves to RFC1918 and is unreachable.
API_BASE = "https://api.yamaha-motor.com/legacy/api"
SITE_BASE = "https://www.yamaha-motor.com"
AUTH_PATH = "/v1.0.0/auth/"

# Yamaha WAF returns 403 for custom/bot User-Agent strings.
HTTP_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)

JSON_STORAGE_ROOT = "storage/yamaha-json"
ILLUST_SUBDIR = "yamaha"
DEFAULT_SNAPSHOT_PATH = "storage/yamaha-snapshot-us-v1.db"

BULK_BATCH_SIZE = 500
SNAPSHOT_FLUSH_SIZE = 200
PROGRESS_INTERVAL_SEC = 2.0
SNAPSHOT_SCHEMA_VERSION = "yamaha-us-v1"
SNAPSHOT_API_RETRIES = 8

# Product slugs from yamaha-motor.com/parts/*
US_PRODUCT_TYPES: tuple[dict[str, str], ...] = (
    {"slug": "atv", "name": "ATV"},
    {"slug": "boat", "name": "Boat"},
    {"slug": "motorcycle", "name": "Motorcycle"},
    {"slug": "scooter", "name": "Scooter"},
    {"slug": "side-by-side", "name": "Side-by-Side"},
    {"slug": "snowmobile", "name": "Snowmobile"},
    {"slug": "outboard", "name": "Outboard Motor"},
    {"slug": "waverunner", "name": "WaveRunner"},
)

OUTBOARD_SLUG = "outboard"
DEFAULT_TEST_PRODUCT_SLUG = "atv"

# Comma-separated list for CLI help (--product-slug all = every category below).
US_PRODUCT_SLUG_LIST = ", ".join(row["slug"] for row in US_PRODUCT_TYPES)

MAX_IMAGE_CRAWL_CONCURRENCY = 8
