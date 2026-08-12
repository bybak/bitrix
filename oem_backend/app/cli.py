import argparse
import json
from pathlib import Path

from app.db import close_pool, open_pool, open_registry_pool, open_yamaha_pool
from app.db_migrate import apply_migrations
from app.registry.catalog_router import close_catalog_pools, open_catalog_pools
from app.remotors_v3.backfill_diagram_coords import backfill_diagram_coords
from app.remotors_v3.constants import ROOT_ARIB_CODES
from app.remotors_v3.details_crawl import crawl_details, seed_crawl_items
from app.remotors_v3.details_parse import parse_details
from app.remotors_v3.import_structure import import_structure
from app.remotors_v3.snapshot import scan_to_snapshot
from app.remotors_v3.verify import verify_v3
from app.yamaha_v1.constants import (
    DEFAULT_FULL_DISPLACEMENT_TYPE,
    DEFAULT_FULL_PRODUCT_ID,
    DEFAULT_TEST_DISPLACEMENT_TYPE,
    DEFAULT_TEST_PRODUCT_ID,
    ROOT_ARIB_CODES as YAMAHA_ROOTS,
)
from app.yamaha_v1.crawl import crawl_details as yamaha_crawl_details
from app.yamaha_v1.import_structure import import_structure as yamaha_import_structure
from app.yamaha_v1.parse_catalog import parse_details as yamaha_parse_details
from app.yamaha_v1.snapshot import scan_to_snapshot as yamaha_scan_to_snapshot
from app.yamaha_v1.test_probe import probe_chain as yamaha_probe_chain
from app.yamaha_us_v1.constants import DEFAULT_SNAPSHOT_PATH as YAMAHA_US_SNAPSHOT_DEFAULT
from app.yamaha_us_v1.constants import DEFAULT_TEST_PRODUCT_SLUG, US_PRODUCT_SLUG_LIST
from app.yamaha_us_v1.crawl import (
    crawl_details as yamaha_us_crawl_details,
    crawl_image_details as yamaha_us_crawl_image_details,
    crawl_json_details as yamaha_us_crawl_json_details,
    reset_crawl_errors as yamaha_us_reset_crawl_errors,
)
from app.yamaha_us_v1.parse_catalog import parse_details as yamaha_us_parse_details
from app.yamaha_us_v1.snapshot import retry_snapshot_errors as yamaha_us_retry_snapshot_errors
from app.yamaha_us_v1.snapshot import scan_to_snapshot as yamaha_us_scan_to_snapshot
from app.yamaha_us_v1.walk import walk_catalog as yamaha_us_walk_catalog
from app.yamaha_us_v1.test_probe import probe_chain as yamaha_us_probe_chain
from app.yamaha_ps_v1.constants import (
    BRAND_CODES as YAMAHA_PS_BRAND_CODES,
    DEFAULT_HTML_CONCURRENCY as YAMAHA_PS_HTML_CONCURRENCY,
    DEFAULT_IMAGE_CONCURRENCY as YAMAHA_PS_IMAGE_CONCURRENCY,
    DEFAULT_PARSE_CONCURRENCY as YAMAHA_PS_PARSE_CONCURRENCY,
    DEFAULT_SIDECAR_PATH as YAMAHA_PS_SIDECAR_DEFAULT,
    DEFAULT_SNAPSHOT_DELAY_MS as YAMAHA_PS_SNAPSHOT_DELAY_MS,
    DEFAULT_SNAPSHOT_JITTER_MS as YAMAHA_PS_SNAPSHOT_JITTER_MS,
    DEFAULT_SNAPSHOT_PATH as YAMAHA_PS_SNAPSHOT_DEFAULT,
)
from app.yamaha_ps_v1.details_crawl import crawl_details as yamaha_ps_crawl_details
from app.yamaha_ps_v1.details_crawl import seed_crawl_items as yamaha_ps_seed_crawl_items
from app.yamaha_ps_v1.details_parse import parse_details as yamaha_ps_parse_details
from app.yamaha_ps_v1.import_structure import import_structure as yamaha_ps_import_structure
from app.yamaha_ps_v1.purge import reset_pipeline as yamaha_ps_reset_pipeline
from app.yamaha_ps_v1.snapshot import scan_to_snapshot as yamaha_ps_scan_to_snapshot


def import_pilot(source: str) -> list[dict]:
    from app.importers import ari, megazip

    results: list[dict] = []
    if source in {"all", "megazip"}:
        results.append(megazip.import_sample())
    if source in {"all", "ari"}:
        results.append(ari.import_sample())
    return results


def main() -> None:
    parser = argparse.ArgumentParser(description="OEM Schemas Catalog CLI (Remotors v3)")
    subparsers = parser.add_subparsers(dest="command", required=True)

    pilot = subparsers.add_parser("import-pilot", help="Import pilot sample data")
    pilot.add_argument("--source", choices=["all", "megazip", "ari"], default="all")

    snapshot = subparsers.add_parser("snapshot-remotors-v3", help="Scan Remotors API tree into SQLite snapshot")
    snapshot.add_argument("--snapshot", default="storage/remotors-snapshot-v3.db")
    snapshot.add_argument("--roots", default=",".join(ROOT_ARIB_CODES), help="Comma-separated root ARI codes")
    snapshot.add_argument("--resume", action="store_true")
    snapshot.add_argument("--limit-assemblies", type=int, default=None)
    snapshot.add_argument(
        "--concurrency",
        type=int,
        default=1,
        help="Parallel GetAssembly workers (default 1; try 8-16 for ARC/ARC_CDN)",
    )

    imp = subparsers.add_parser("import-remotors-structure", help="Bulk import snapshot structure into PostgreSQL")
    imp.add_argument("--snapshot", default="storage/remotors-snapshot-v3.db")
    imp.add_argument("--no-resume", action="store_true")

    seed = subparsers.add_parser("seed-remotors-crawl", help="Seed crawl sidecar from PostgreSQL assemblies")
    seed.add_argument("--sidecar", default=None, help="Default: storage/{remotors|arctic}-details-crawl.db")
    seed.add_argument("--db-code", choices=["remotors", "arctic"], default="remotors")

    crawl = subparsers.add_parser("crawl-remotors-details", help="Crawl GetDetails HTML or diagram images")
    crawl.add_argument("--phase", choices=["html", "images"], required=True)
    crawl.add_argument("--sidecar", default=None, help="Default: storage/{remotors|arctic}-details-crawl.db")
    crawl.add_argument("--limit", type=int, default=None)
    crawl.add_argument("--force", action="store_true")
    crawl.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1 for parallel crawl")
    crawl.add_argument("--workers", type=int, default=1, help="Split pending items by assembly_id %% workers")
    crawl.add_argument(
        "--concurrency",
        type=int,
        default=1,
        help="Parallel HTTP requests per worker (default 1; try 4-8 for faster html crawl)",
    )
    crawl.add_argument("--db-code", choices=["remotors", "arctic"], default="remotors")

    parse_cmd = subparsers.add_parser("parse-remotors-details", help="Offline parse saved HTML into PostgreSQL")
    parse_cmd.add_argument("--sidecar", default=None, help="Legacy; progress is in PostgreSQL")
    parse_cmd.add_argument("--limit", type=int, default=None)
    parse_cmd.add_argument("--force", action="store_true")
    parse_cmd.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1 for parallel parse")
    parse_cmd.add_argument("--workers", type=int, default=1, help="Number of parallel parse workers")
    parse_cmd.add_argument(
        "--concurrency",
        type=int,
        default=8,
        help="Parallel parse jobs (default 8; capped at 24). Bottleneck is PG writes, not HTML I/O.",
    )
    parse_cmd.add_argument("--db-code", choices=["remotors", "arctic"], default="remotors")

    coords_cmd = subparsers.add_parser(
        "backfill-diagram-coords",
        help="Read saved HTML origWidth and store diagram coordinate space (hotspot scaling)",
    )
    coords_cmd.add_argument("--limit", type=int, default=None)
    coords_cmd.add_argument("--force", action="store_true")
    coords_cmd.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1")
    coords_cmd.add_argument("--workers", type=int, default=1, help="Split diagrams by assembly_id %% workers")
    coords_cmd.add_argument("--db-code", choices=["remotors", "arctic"], default="remotors")

    verify = subparsers.add_parser("verify-remotors-v3", help="Verify snapshot vs PostgreSQL counts")
    verify.add_argument("--snapshot", default="storage/remotors-snapshot-v3.db")

    subparsers.add_parser("migrate", help="Apply Remotors PostgreSQL migrations (oem_db)")

    subparsers.add_parser("migrate-yamaha", help="Apply Yamaha PostgreSQL migrations (yamaha_db)")

    subparsers.add_parser("migrate-registry", help="Apply registry PostgreSQL migrations (oem_registry_db)")

    yamaha_probe = subparsers.add_parser("test-yamaha-probe", help="Probe Yamaha YPEC API chain (no DB writes except optional)")
    yamaha_probe.add_argument("--root", choices=list(YAMAHA_ROOTS), default="YMH-JP")
    yamaha_probe.add_argument("--product-id", default=DEFAULT_TEST_PRODUCT_ID)
    yamaha_probe.add_argument("--displacement-type", default=DEFAULT_TEST_DISPLACEMENT_TYPE)
    yamaha_probe.add_argument("--model-name", default="FZR400RR")
    yamaha_probe.add_argument("--model-year", default="1990")
    yamaha_probe.add_argument("--fig-no", default="04")

    yamaha_snap = subparsers.add_parser("test-yamaha-snapshot", help="Limited Yamaha snapshot into SQLite (test only)")
    yamaha_snap.add_argument("--snapshot", default="storage/yamaha-snapshot-test.db")
    yamaha_snap.add_argument("--roots", default=",".join(YAMAHA_ROOTS))
    yamaha_snap.add_argument("--product-id", default=DEFAULT_TEST_PRODUCT_ID)
    yamaha_snap.add_argument("--displacement-type", default=DEFAULT_TEST_DISPLACEMENT_TYPE)
    yamaha_snap.add_argument("--limit-models", type=int, default=1)
    yamaha_snap.add_argument("--limit-figs", type=int, default=2)
    yamaha_snap.add_argument("--no-resume", action="store_true", help="Restart scan from scratch for regions not yet marked complete")

    yamaha_snap_full = subparsers.add_parser("snapshot-yamaha-v1", help="Yamaha API tree into SQLite snapshot (resumable)")
    yamaha_snap_full.add_argument("--snapshot", default="storage/yamaha-snapshot-v1.db")
    yamaha_snap_full.add_argument("--roots", default=",".join(YAMAHA_ROOTS))
    yamaha_snap_full.add_argument("--product-id", default=DEFAULT_FULL_PRODUCT_ID, help="Product id or 'all' (JP: 5 allowed types)")
    yamaha_snap_full.add_argument("--displacement-type", default=DEFAULT_FULL_DISPLACEMENT_TYPE, help="Displacement type or 'all'")
    yamaha_snap_full.add_argument("--limit-models", type=int, default=0, help="0 = all models")
    yamaha_snap_full.add_argument("--limit-figs", type=int, default=0, help="0 = all figures per variant")
    yamaha_snap_full.add_argument("--no-resume", action="store_true")

    yamaha_imp = subparsers.add_parser("test-yamaha-import", help="Import limited Yamaha snapshot into PostgreSQL")
    yamaha_imp.add_argument("--snapshot", default="storage/yamaha-snapshot-test.db")
    yamaha_imp.add_argument("--no-resume", action="store_true")

    yamaha_imp_full = subparsers.add_parser("import-yamaha-structure", help="Bulk import Yamaha snapshot into PostgreSQL")
    yamaha_imp_full.add_argument("--snapshot", default="storage/yamaha-snapshot-v1.db")
    yamaha_imp_full.add_argument("--no-resume", action="store_true")

    yamaha_crawl = subparsers.add_parser("test-yamaha-crawl", help="Fetch catalog_text JSON + diagram PNG for a few assemblies")
    yamaha_crawl.add_argument("--limit", type=int, default=2)
    yamaha_crawl.add_argument("--force", action="store_true")
    yamaha_crawl.add_argument("--concurrency", "--batch-size", type=int, default=4, dest="concurrency")
    yamaha_crawl.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1")
    yamaha_crawl.add_argument("--workers", type=int, default=1, help="Number of parallel crawl processes")

    yamaha_crawl_full = subparsers.add_parser("crawl-yamaha-details", help="Crawl Yamaha catalog_text JSON + diagram images (resumable)")
    yamaha_crawl_full.add_argument("--limit", type=int, default=None)
    yamaha_crawl_full.add_argument("--force", action="store_true")
    yamaha_crawl_full.add_argument(
        "--concurrency",
        "--batch-size",
        type=int,
        default=8,
        dest="concurrency",
        help="Parallel HTTP fetches per process (default 8)",
    )
    yamaha_crawl_full.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1")
    yamaha_crawl_full.add_argument(
        "--workers",
        type=int,
        default=1,
        help="Number of parallel crawl processes (each claims rows via SKIP LOCKED)",
    )

    yamaha_parse = subparsers.add_parser("test-yamaha-parse", help="Parse saved Yamaha JSON into parts/hotspots")
    yamaha_parse.add_argument("--limit", type=int, default=2)
    yamaha_parse.add_argument("--force", action="store_true")
    yamaha_parse.add_argument("--concurrency", "--batch-size", type=int, default=4, dest="concurrency")
    yamaha_parse.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1")
    yamaha_parse.add_argument("--workers", type=int, default=1, help="Number of parallel parse processes")

    yamaha_parse_full = subparsers.add_parser("parse-yamaha-details", help="Parse saved Yamaha JSON into PostgreSQL (resumable)")
    yamaha_parse_full.add_argument("--limit", type=int, default=None)
    yamaha_parse_full.add_argument("--force", action="store_true")
    yamaha_parse_full.add_argument(
        "--concurrency",
        "--batch-size",
        type=int,
        default=16,
        dest="concurrency",
        help="Parallel parse jobs per process (default 16)",
    )
    yamaha_parse_full.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1")
    yamaha_parse_full.add_argument(
        "--workers",
        type=int,
        default=1,
        help="Number of parallel parse processes (each claims rows via SKIP LOCKED)",
    )

    # --- Yamaha USA via PartStream (YAM + YAMMR → YMH-US) ---
    yamaha_ps_snap = subparsers.add_parser(
        "snapshot-yamaha-ps",
        help="PartStream GetAssembly tree → SQLite (YAM PowerSport / YAMMR Marine → YMH-US)",
    )
    yamaha_ps_snap.add_argument("--snapshot", default=YAMAHA_PS_SNAPSHOT_DEFAULT)
    yamaha_ps_snap.add_argument(
        "--brand",
        default="all",
        help=f"YAM, YAMMR, or all (default). Brands: {', '.join(YAMAHA_PS_BRAND_CODES)}",
    )
    yamaha_ps_snap.add_argument("--resume", action="store_true", help="Continue; skip completed brands")
    yamaha_ps_snap.add_argument("--limit-assemblies", type=int, default=None)
    yamaha_ps_snap.add_argument(
        "--delay-ms",
        type=int,
        default=YAMAHA_PS_SNAPSHOT_DELAY_MS,
        help=f"Base delay between GetAssembly calls (default {YAMAHA_PS_SNAPSHOT_DELAY_MS})",
    )
    yamaha_ps_snap.add_argument(
        "--jitter-ms",
        type=int,
        default=YAMAHA_PS_SNAPSHOT_JITTER_MS,
        help=f"Random jitter added to delay (default {YAMAHA_PS_SNAPSHOT_JITTER_MS})",
    )

    yamaha_ps_imp = subparsers.add_parser(
        "import-yamaha-ps-structure",
        help="Import PartStream snapshot structure into yamaha PostgreSQL (YMH-US only)",
    )
    yamaha_ps_imp.add_argument("--snapshot", default=YAMAHA_PS_SNAPSHOT_DEFAULT)
    yamaha_ps_imp.add_argument("--no-resume", action="store_true", help="Purge YMH-US then full import")

    yamaha_ps_seed = subparsers.add_parser(
        "seed-yamaha-ps-crawl",
        help="Seed PartStream crawl sidecar from YMH-US assemblies in PostgreSQL",
    )
    yamaha_ps_seed.add_argument("--sidecar", default=YAMAHA_PS_SIDECAR_DEFAULT)

    yamaha_ps_crawl = subparsers.add_parser(
        "crawl-yamaha-ps-details",
        help="Crawl PartStream GetDetails HTML or diagram PNGs (YMH-US only)",
    )
    yamaha_ps_crawl.add_argument("--phase", choices=["html", "images"], required=True)
    yamaha_ps_crawl.add_argument("--sidecar", default=YAMAHA_PS_SIDECAR_DEFAULT)
    yamaha_ps_crawl.add_argument("--limit", type=int, default=None)
    yamaha_ps_crawl.add_argument("--force", action="store_true")
    yamaha_ps_crawl.add_argument("--worker-id", type=int, default=0)
    yamaha_ps_crawl.add_argument("--workers", type=int, default=1)
    yamaha_ps_crawl.add_argument(
        "--concurrency",
        type=int,
        default=None,
        help=(
            f"Parallel HTTP (default html={YAMAHA_PS_HTML_CONCURRENCY}, "
            f"images={YAMAHA_PS_IMAGE_CONCURRENCY}; no hard cap)"
        ),
    )

    yamaha_ps_parse = subparsers.add_parser(
        "parse-yamaha-ps-details",
        help="Parse saved PartStream HTML into parts/hotspots (YMH-US only)",
    )
    yamaha_ps_parse.add_argument("--sidecar", default=YAMAHA_PS_SIDECAR_DEFAULT)
    yamaha_ps_parse.add_argument("--limit", type=int, default=None)
    yamaha_ps_parse.add_argument("--force", action="store_true")
    yamaha_ps_parse.add_argument("--worker-id", type=int, default=0)
    yamaha_ps_parse.add_argument("--workers", type=int, default=1)
    yamaha_ps_parse.add_argument(
        "--concurrency",
        type=int,
        default=YAMAHA_PS_PARSE_CONCURRENCY,
        help=f"Parallel parse jobs (default {YAMAHA_PS_PARSE_CONCURRENCY})",
    )

    yamaha_ps_reset = subparsers.add_parser(
        "reset-yamaha-ps",
        help="Full YMH-US reset: purge PostgreSQL + PartStream/legacy local assets (JP/EU untouched)",
    )
    yamaha_ps_reset.add_argument("--snapshot", default=YAMAHA_PS_SNAPSHOT_DEFAULT)
    yamaha_ps_reset.add_argument("--keep-pg", action="store_true", help="Do not purge YMH-US from PostgreSQL")
    yamaha_ps_reset.add_argument("--keep-snapshot", action="store_true", help="Keep SQLite snapshot/sidecar")
    yamaha_ps_reset.add_argument("--keep-html", action="store_true", help="Keep saved GetDetails HTML")
    yamaha_ps_reset.add_argument("--keep-images", action="store_true", help="Keep diagram PNGs")
    yamaha_ps_reset.add_argument(
        "--keep-legacy",
        action="store_true",
        help="Keep legacy yamaha-motor.com US artifacts",
    )

    yamaha_us_probe = subparsers.add_parser(
        "test-yamaha-us-probe",
        help="DEPRECATED: yamaha-motor.com probe. Prefer PartStream (snapshot-yamaha-ps).",
    )
    yamaha_us_probe.add_argument("--product-slug", default=DEFAULT_TEST_PRODUCT_SLUG)
    yamaha_us_probe.add_argument("--limit-models", type=int, default=1)

    yamaha_us_snap_test = subparsers.add_parser(
        "test-yamaha-us-snapshot",
        help="DEPRECATED: yamaha-motor.com snapshot test. Prefer snapshot-yamaha-ps.",
    )
    yamaha_us_snap_test.add_argument("--snapshot", default="storage/yamaha-snapshot-us-test.db")
    yamaha_us_snap_test.add_argument("--product-slug", default=DEFAULT_TEST_PRODUCT_SLUG)
    yamaha_us_snap_test.add_argument("--limit-models", type=int, default=1)
    yamaha_us_snap_test.add_argument("--limit-diagrams", type=int, default=2)
    yamaha_us_snap_test.add_argument("--no-resume", action="store_true")

    yamaha_us_snap_full = subparsers.add_parser(
        "snapshot-yamaha-us-v1",
        help="DEPRECATED: yamaha-motor.com snapshot. Prefer snapshot-yamaha-ps (PartStream).",
    )
    yamaha_us_snap_full.add_argument("--snapshot", default=YAMAHA_US_SNAPSHOT_DEFAULT)
    yamaha_us_snap_full.add_argument(
        "--product-slug",
        default="all",
        help=f"Product slug or 'all' for every category: {US_PRODUCT_SLUG_LIST}",
    )
    yamaha_us_snap_full.add_argument("--limit-models", type=int, default=0, help="0 = all; N = total models per product (not per year)")
    yamaha_us_snap_full.add_argument("--limit-diagrams", type=int, default=0, help="0 = all; N = diagrams per model (TITLE PAGE skipped)")
    yamaha_us_snap_full.add_argument("--no-resume", action="store_true")

    yamaha_us_snap_retry = subparsers.add_parser(
        "retry-yamaha-us-snapshot-errors",
        help="DEPRECATED: yamaha-motor.com snapshot retry. Prefer PartStream pipeline.",
    )
    yamaha_us_snap_retry.add_argument("--snapshot", default=YAMAHA_US_SNAPSHOT_DEFAULT)

    yamaha_us_imp = subparsers.add_parser(
        "import-yamaha-us-structure",
        help="DEPRECATED: prefer import-yamaha-ps-structure (PartStream → YMH-US).",
    )
    yamaha_us_imp.add_argument("--snapshot", default=YAMAHA_US_SNAPSHOT_DEFAULT)
    yamaha_us_imp.add_argument("--no-resume", action="store_true")

    yamaha_us_walk = subparsers.add_parser(
        "walk-yamaha-us",
        help="DEPRECATED: yamaha-motor.com walk. Prefer scripts/oem-yamaha-ps-pipeline.sh.",
    )
    yamaha_us_walk.add_argument(
        "--product-slug",
        default="all",
        help=f"Product slug or 'all': {US_PRODUCT_SLUG_LIST}",
    )
    yamaha_us_walk.add_argument(
        "--concurrency",
        type=int,
        default=100,
        help="Worker threads for diagram JSON fetch",
    )
    yamaha_us_walk.add_argument(
        "--api-concurrency",
        type=int,
        default=None,
        help="Max in-flight diagram API calls (default: 25)",
    )
    yamaha_us_walk.add_argument("--force", action="store_true", help="Re-fetch JSON even if html_status=ok")
    yamaha_us_walk.add_argument("--no-resume", action="store_true", help="Clear walk checkpoints and rescan")
    yamaha_us_walk.add_argument(
        "--no-obsolete",
        action="store_true",
        help="Do not mark missing branches as obsolete",
    )
    yamaha_us_walk.add_argument("--limit-models", type=int, default=0, help="0 = all; N = total models per product")
    yamaha_us_walk.add_argument("--limit-diagrams", type=int, default=0, help="0 = all; N = diagrams per model")

    yamaha_us_crawl = subparsers.add_parser(
        "test-yamaha-us-crawl",
        help="DEPRECATED: yamaha-motor.com crawl test. Prefer crawl-yamaha-ps-details.",
    )
    yamaha_us_crawl.add_argument("--limit", type=int, default=2)
    yamaha_us_crawl.add_argument("--force", action="store_true")
    yamaha_us_crawl.add_argument("--concurrency", type=int, default=4)

    yamaha_us_crawl_full = subparsers.add_parser(
        "crawl-yamaha-us-details",
        help="DEPRECATED: yamaha-motor.com crawl. Prefer crawl-yamaha-ps-details.",
    )
    yamaha_us_crawl_full.add_argument("--limit", type=int, default=None)
    yamaha_us_crawl_full.add_argument("--force", action="store_true")
    yamaha_us_crawl_full.add_argument("--concurrency", type=int, default=8)
    yamaha_us_crawl_full.add_argument("--worker-id", type=int, default=0)
    yamaha_us_crawl_full.add_argument("--workers", type=int, default=1)
    yamaha_us_crawl_full.add_argument(
        "--phase",
        choices=["both", "json", "images"],
        default="both",
        help="Crawl stage: json only, images only, or both (default)",
    )

    yamaha_us_crawl_json = subparsers.add_parser(
        "crawl-yamaha-us-json",
        help="DEPRECATED: yamaha-motor.com JSON crawl. Prefer crawl-yamaha-ps-details --phase html.",
    )
    yamaha_us_crawl_json.add_argument("--limit", type=int, default=None)
    yamaha_us_crawl_json.add_argument("--force", action="store_true")
    yamaha_us_crawl_json.add_argument(
        "--concurrency",
        type=int,
        default=100,
        help="Worker threads (DB/file pipeline)",
    )
    yamaha_us_crawl_json.add_argument(
        "--api-concurrency",
        type=int,
        default=None,
        help="Max in-flight Yamaha diagram API calls per process (default: 25)",
    )
    yamaha_us_crawl_json.add_argument(
        "--claim-order",
        choices=["asc", "desc", "random"],
        default="desc",
        help="Pending queue order (desc skips broken low-id HTTP 500 block first)",
    )
    yamaha_us_crawl_json.add_argument("--worker-id", type=int, default=0)
    yamaha_us_crawl_json.add_argument("--workers", type=int, default=1)

    yamaha_us_crawl_images = subparsers.add_parser(
        "crawl-yamaha-us-images",
        help="DEPRECATED: yamaha-motor.com PNG crawl. Prefer crawl-yamaha-ps-details --phase images.",
    )
    yamaha_us_crawl_images.add_argument("--limit", type=int, default=None)
    yamaha_us_crawl_images.add_argument("--force", action="store_true")
    yamaha_us_crawl_images.add_argument("--concurrency", type=int, default=4)
    yamaha_us_crawl_images.add_argument("--worker-id", type=int, default=0)
    yamaha_us_crawl_images.add_argument("--workers", type=int, default=1)

    yamaha_us_reset = subparsers.add_parser(
        "reset-yamaha-us-crawl-errors",
        help="DEPRECATED: yamaha-motor.com crawl error reset.",
    )
    yamaha_us_reset.add_argument(
        "--phase",
        choices=["all", "json", "images", "both"],
        default="all",
        help="Which crawl stage to reset (default: all)",
    )
    yamaha_us_reset.add_argument(
        "--include-permanent",
        action="store_true",
        help="Reset all JSON errors incl. HTTP 404/500 (use before second fast pass)",
    )

    yamaha_us_pipeline_reset = subparsers.add_parser(
        "reset-yamaha-us",
        help="DEPRECATED alias: use reset-yamaha-ps (purges YMH-US + legacy motor.com assets).",
    )
    yamaha_us_pipeline_reset.add_argument("--snapshot", default=YAMAHA_PS_SNAPSHOT_DEFAULT)
    yamaha_us_pipeline_reset.add_argument(
        "--keep-pg",
        action="store_true",
        help="Do not purge YMH-US from PostgreSQL",
    )
    yamaha_us_pipeline_reset.add_argument(
        "--keep-snapshot",
        action="store_true",
        help="Keep SQLite snapshot file",
    )
    yamaha_us_pipeline_reset.add_argument(
        "--keep-json",
        action="store_true",
        help="Keep HTML/legacy JSON artifacts for YMH-US",
    )
    yamaha_us_pipeline_reset.add_argument(
        "--keep-images",
        action="store_true",
        help="Keep downloaded PNG diagrams",
    )

    yamaha_us_parse = subparsers.add_parser(
        "test-yamaha-us-parse",
        help="DEPRECATED: yamaha-motor.com parse test. Prefer parse-yamaha-ps-details.",
    )
    yamaha_us_parse.add_argument("--limit", type=int, default=2)
    yamaha_us_parse.add_argument("--force", action="store_true")
    yamaha_us_parse.add_argument("--concurrency", type=int, default=4)

    yamaha_us_parse_full = subparsers.add_parser(
        "parse-yamaha-us-details",
        help="DEPRECATED: yamaha-motor.com parse. Prefer parse-yamaha-ps-details.",
    )
    yamaha_us_parse_full.add_argument("--limit", type=int, default=None)
    yamaha_us_parse_full.add_argument("--force", action="store_true")
    yamaha_us_parse_full.add_argument("--concurrency", type=int, default=16)
    yamaha_us_parse_full.add_argument("--worker-id", type=int, default=0)
    yamaha_us_parse_full.add_argument("--workers", type=int, default=1)

    args = parser.parse_args()

    YAMAHA_PS_DB_COMMANDS = {
        "import-yamaha-ps-structure",
        "seed-yamaha-ps-crawl",
        "crawl-yamaha-ps-details",
        "parse-yamaha-ps-details",
        "reset-yamaha-ps",
    }
    YAMAHA_US_DB_COMMANDS = {
        "import-yamaha-us-structure",
        "walk-yamaha-us",
        "test-yamaha-us-crawl",
        "crawl-yamaha-us-details",
        "crawl-yamaha-us-json",
        "crawl-yamaha-us-images",
        "reset-yamaha-us-crawl-errors",
        "reset-yamaha-us",
        "test-yamaha-us-parse",
        "parse-yamaha-us-details",
    }
    YAMAHA_DB_COMMANDS = {
        "migrate-yamaha",
        "test-yamaha-import",
        "import-yamaha-structure",
        "test-yamaha-crawl",
        "crawl-yamaha-details",
        "test-yamaha-parse",
        "parse-yamaha-details",
        *YAMAHA_PS_DB_COMMANDS,
        *YAMAHA_US_DB_COMMANDS,
    }
    NO_DB_COMMANDS = {
        "test-yamaha-probe",
        "test-yamaha-snapshot",
        "snapshot-yamaha-v1",
        "snapshot-yamaha-ps",
        "test-yamaha-us-probe",
        "test-yamaha-us-snapshot",
        "snapshot-yamaha-us-v1",
        "retry-yamaha-us-snapshot-errors",
    }

    if args.command in NO_DB_COMMANDS:
        pass
    elif args.command in YAMAHA_DB_COMMANDS:
        pool_size = 1
        if args.command in {"test-yamaha-crawl", "crawl-yamaha-details", "test-yamaha-parse", "parse-yamaha-details"}:
            pool_size = max(2, int(getattr(args, "concurrency", 1)) + 4)
        elif args.command in {
            "crawl-yamaha-ps-details",
            "parse-yamaha-ps-details",
        }:
            conc = getattr(args, "concurrency", None)
            if conc is None:
                conc = (
                    YAMAHA_PS_HTML_CONCURRENCY
                    if getattr(args, "phase", None) == "html"
                    else YAMAHA_PS_IMAGE_CONCURRENCY
                    if getattr(args, "phase", None) == "images"
                    else YAMAHA_PS_PARSE_CONCURRENCY
                )
            pool_size = max(2, int(conc) + 2)
        elif args.command in {
            "test-yamaha-us-crawl",
            "crawl-yamaha-us-details",
            "crawl-yamaha-us-json",
            "crawl-yamaha-us-images",
            "walk-yamaha-us",
            "test-yamaha-us-parse",
            "parse-yamaha-us-details",
        }:
            pool_size = max(2, int(getattr(args, "concurrency", 1)))
        open_yamaha_pool(min_size=1, max_size=pool_size)
    elif args.command == "parse-remotors-details":
        pool_size = max(2, int(args.concurrency) + 1)
        open_pool(min_size=1, max_size=pool_size)
    elif args.command == "crawl-remotors-details":
        open_pool(min_size=1, max_size=1)
    elif args.command == "migrate":
        open_pool(min_size=1, max_size=1)
    elif args.command == "migrate-yamaha":
        open_yamaha_pool(min_size=1, max_size=1)
    elif args.command == "migrate-registry":
        open_registry_pool(min_size=1, max_size=1)
    elif args.command == "import-remotors-structure":
        open_registry_pool(min_size=1, max_size=2)
        open_catalog_pools(min_size=1, max_size=4)
    else:
        open_pool()
    try:
        if args.command == "import-pilot":
            print(json.dumps(import_pilot(args.source), ensure_ascii=False, indent=2))
        elif args.command == "snapshot-remotors-v3":
            roots = [code.strip().upper() for code in args.roots.split(",") if code.strip()]
            result = scan_to_snapshot(
                snapshot_path=args.snapshot,
                arib_codes=roots,
                resume=args.resume,
                limit_assemblies=args.limit_assemblies,
                concurrency=args.concurrency,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "import-remotors-structure":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "seed-remotors-crawl":
            from app.remotors_v3.catalog_context import default_sidecar_path, set_catalog_db

            set_catalog_db(args.db_code)
            sidecar = args.sidecar or default_sidecar_path()
            count = seed_crawl_items(sidecar_path=sidecar, db_code=args.db_code)
            print(
                json.dumps(
                    {"seeded": count, "sidecar": sidecar, "db_code": args.db_code},
                    ensure_ascii=False,
                    indent=2,
                )
            )
        elif args.command == "crawl-remotors-details":
            from app.remotors_v3.catalog_context import default_sidecar_path, set_catalog_db

            set_catalog_db(args.db_code)
            sidecar = args.sidecar or default_sidecar_path()
            result = crawl_details(
                phase=args.phase,
                sidecar_path=sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
                db_code=args.db_code,
            )
            result = {**result, "db_code": args.db_code, "sidecar": sidecar}
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "parse-remotors-details":
            from app.remotors_v3.catalog_context import default_sidecar_path, set_catalog_db

            set_catalog_db(args.db_code)
            sidecar = args.sidecar or default_sidecar_path()
            result = parse_details(
                sidecar_path=sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
                db_code=args.db_code,
            )
            result = {**result, "db_code": args.db_code}
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "backfill-diagram-coords":
            result = backfill_diagram_coords(
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                db_code=args.db_code,
            )
            result = {**result, "db_code": args.db_code}
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "verify-remotors-v3":
            result = verify_v3(snapshot_path=args.snapshot)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if not result["ok"]:
                raise SystemExit(1)
        elif args.command == "migrate":
            print(json.dumps({"target": "remotors", "applied": apply_migrations(target="remotors")}, ensure_ascii=False, indent=2))
        elif args.command == "migrate-yamaha":
            print(json.dumps({"target": "yamaha", "applied": apply_migrations(target="yamaha")}, ensure_ascii=False, indent=2))
        elif args.command == "migrate-registry":
            print(json.dumps({"target": "registry", "applied": apply_migrations(target="registry")}, ensure_ascii=False, indent=2))
        elif args.command == "test-yamaha-probe":
            result = yamaha_probe_chain(
                root_arib=args.root,
                product_id=args.product_id,
                displacement_type=args.displacement_type,
                model_name=args.model_name,
                model_year=args.model_year,
                fig_no=args.fig_no,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if not result.get("ok"):
                raise SystemExit(1)
        elif args.command == "test-yamaha-snapshot":
            roots = [code.strip().upper() for code in args.roots.split(",") if code.strip()]
            result = yamaha_scan_to_snapshot(
                snapshot_path=args.snapshot,
                regions=roots,
                product_id=args.product_id,
                displacement_type=args.displacement_type,
                limit_models=args.limit_models,
                limit_figs_per_variant=args.limit_figs,
                resume=not args.no_resume,
                reset=args.no_resume,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "snapshot-yamaha-v1":
            roots = [code.strip().upper() for code in args.roots.split(",") if code.strip()]
            result = yamaha_scan_to_snapshot(
                snapshot_path=args.snapshot,
                regions=roots,
                product_id=args.product_id,
                displacement_type=args.displacement_type,
                limit_models=args.limit_models,
                limit_figs_per_variant=args.limit_figs,
                resume=not args.no_resume,
                reset=args.no_resume,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "test-yamaha-import":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = yamaha_import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "import-yamaha-structure":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = yamaha_import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "test-yamaha-crawl":
            result = yamaha_crawl_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "crawl-yamaha-details":
            result = yamaha_crawl_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "test-yamaha-parse":
            result = yamaha_parse_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "parse-yamaha-details":
            result = yamaha_parse_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "snapshot-yamaha-ps":
            result = yamaha_ps_scan_to_snapshot(
                snapshot_path=args.snapshot,
                brand=args.brand,
                resume=args.resume,
                limit_assemblies=args.limit_assemblies,
                delay_ms=args.delay_ms,
                jitter_ms=args.jitter_ms,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "import-yamaha-ps-structure":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = yamaha_ps_import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "seed-yamaha-ps-crawl":
            count = yamaha_ps_seed_crawl_items(sidecar_path=args.sidecar)
            print(json.dumps({"seeded": count, "sidecar": args.sidecar}, ensure_ascii=False, indent=2))
        elif args.command == "crawl-yamaha-ps-details":
            result = yamaha_ps_crawl_details(
                phase=args.phase,
                sidecar_path=args.sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "parse-yamaha-ps-details":
            result = yamaha_ps_parse_details(
                sidecar_path=args.sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "reset-yamaha-ps":
            result = yamaha_ps_reset_pipeline(
                snapshot_path=args.snapshot,
                purge_pg=not args.keep_pg,
                delete_snapshot=not args.keep_snapshot,
                delete_html=not args.keep_html,
                delete_images=not args.keep_images,
                delete_legacy=not args.keep_legacy,
                log=lambda message: print(message, flush=True),
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "test-yamaha-us-probe":
            result = yamaha_us_probe_chain(
                product_slug=args.product_slug,
                limit_models=args.limit_models,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if not result.get("ok"):
                raise SystemExit(1)
        elif args.command in {"test-yamaha-us-snapshot", "snapshot-yamaha-us-v1"}:
            limit_models = args.limit_models if args.limit_models > 0 else None
            limit_diagrams = args.limit_diagrams if args.limit_diagrams > 0 else None
            result = yamaha_us_scan_to_snapshot(
                snapshot_path=args.snapshot,
                product_slug=args.product_slug,
                limit_models=limit_models,
                limit_diagrams_per_model=limit_diagrams,
                resume=not args.no_resume,
                reset=args.no_resume,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "retry-yamaha-us-snapshot-errors":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = yamaha_us_retry_snapshot_errors(snapshot_path=args.snapshot)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if result.get("stats", {}).get("remaining_errors", 0) > 0:
                raise SystemExit(1)
        elif args.command == "import-yamaha-us-structure":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = yamaha_import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "walk-yamaha-us":
            result = yamaha_us_walk_catalog(
                product_slug=args.product_slug,
                concurrency=args.concurrency,
                api_concurrency=args.api_concurrency,
                force=args.force,
                resume=not args.no_resume,
                mark_obsolete=not args.no_obsolete,
                limit_models=args.limit_models or None,
                limit_diagrams=args.limit_diagrams or None,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "reset-yamaha-us-crawl-errors":
            result = yamaha_us_reset_crawl_errors(
                phase=args.phase,
                include_permanent=args.include_permanent,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "reset-yamaha-us":
            # Prefer PartStream reset (also wipes legacy motor.com US artifacts).
            result = yamaha_ps_reset_pipeline(
                snapshot_path=args.snapshot,
                purge_pg=not args.keep_pg,
                delete_snapshot=not args.keep_snapshot,
                delete_html=not args.keep_json,
                delete_images=not args.keep_images,
                delete_legacy=True,
                log=lambda message: print(message, flush=True),
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "crawl-yamaha-us-json":
            result = yamaha_us_crawl_json_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                api_concurrency=args.api_concurrency,
                claim_order=args.claim_order,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "crawl-yamaha-us-images":
            result = yamaha_us_crawl_image_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=args.worker_id,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command in {"test-yamaha-us-crawl", "crawl-yamaha-us-details"}:
            result = yamaha_us_crawl_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=getattr(args, "worker_id", 0),
                workers=getattr(args, "workers", 1),
                phase=getattr(args, "phase", "both"),
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command in {"test-yamaha-us-parse", "parse-yamaha-us-details"}:
            result = yamaha_us_parse_details(
                limit=args.limit,
                force=args.force,
                concurrency=args.concurrency,
                worker_id=getattr(args, "worker_id", 0),
                workers=getattr(args, "workers", 1),
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
    finally:
        close_catalog_pools()
        close_pool()


if __name__ == "__main__":
    main()
