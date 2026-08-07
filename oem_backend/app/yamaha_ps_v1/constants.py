from __future__ import annotations

from typing import Any

# Catalog region in yamaha DB / registry (peer of YMH-JP / YMH-EU).
ROOT_ARIB = "YMH-US"

STREAM_ENDPOINT = "https://partstream.arinet.com"
GET_ASSEMBLY_URL = f"{STREAM_ENDPOINT}/Parts/GetAssembly"
GET_DETAILS_URL = f"{STREAM_ENDPOINT}/Parts/GetDetails"

# PartStream brands under США → Yamaha PowerSport / Yamaha Marine.
BRAND_CONFIG: dict[str, dict[str, Any]] = {
    "YAM": {
        "partstream_arib": "YAM",
        "label": "Yamaha PowerSport",
        "appkey": "lKCIzRNMxtbQA7q0ZL4j",
        "ariv": "http://streamsdemo.arinet.com/Power",
        "aril": "en-US",
    },
    "YAMMR": {
        "partstream_arib": "YAMMR",
        "label": "Yamaha Marine",
        "appkey": "ubrieA6M3wPgfUnLkdCz",
        "ariv": "http://streamsdemo.arinet.com/Marine",
        "aril": "en-US",
    },
}

BRAND_CODES: tuple[str, ...] = tuple(BRAND_CONFIG.keys())
LABEL_TO_BRAND: dict[str, str] = {
    cfg["label"]: code for code, cfg in BRAND_CONFIG.items()
}

SNAPSHOT_SCHEMA_VERSION = "yamaha-ps-v1"
SOURCE_CODE = "yamaha_partstream"
DEFAULT_SNAPSHOT_PATH = "storage/yamaha-ps-snapshot-v1.db"
DEFAULT_SIDECAR_PATH = "storage/yamaha-ps-details-crawl.db"
HTML_SUBDIR = "yamaha-ps-html"
ILLUST_SUBDIR = "yamaha-ps"

BULK_BATCH_SIZE = 2000
# Smaller SQLite flushes — Docker Desktop bind mounts on macOS choke on large WAL/txns.
SNAPSHOT_FLUSH_SIZE = 200
PROGRESS_INTERVAL_SEC = 5.0

# Polite defaults: fast enough, low WAF profile.
DEFAULT_SNAPSHOT_DELAY_MS = 200
DEFAULT_SNAPSHOT_JITTER_MS = 150
DEFAULT_HTML_CONCURRENCY = 4
DEFAULT_IMAGE_CONCURRENCY = 2
DEFAULT_PARSE_CONCURRENCY = 8
# Stop crawl/snapshot if this many consecutive failures (WAF / outage).
CIRCUIT_BREAKER_ERRORS = 25

HTTP_USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)
