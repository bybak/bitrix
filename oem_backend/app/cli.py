import argparse
import json
from pathlib import Path

from app.db import close_pool, open_pool
from app.remotors_v3.constants import ROOT_ARIB_CODES
from app.remotors_v3.details_crawl import crawl_details, seed_crawl_items
from app.remotors_v3.details_parse import parse_details
from app.remotors_v3.import_structure import import_structure
from app.remotors_v3.snapshot import scan_to_snapshot
from app.remotors_v3.verify import verify_v3


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

    imp = subparsers.add_parser("import-remotors-structure", help="Bulk import snapshot structure into PostgreSQL")
    imp.add_argument("--snapshot", default="storage/remotors-snapshot-v3.db")
    imp.add_argument("--no-resume", action="store_true")

    seed = subparsers.add_parser("seed-remotors-crawl", help="Seed crawl sidecar from PostgreSQL assemblies")
    seed.add_argument("--sidecar", default="storage/remotors-details-crawl.db")

    crawl = subparsers.add_parser("crawl-remotors-details", help="Crawl GetDetails HTML or diagram images")
    crawl.add_argument("--phase", choices=["html", "images"], required=True)
    crawl.add_argument("--sidecar", default="storage/remotors-details-crawl.db")
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

    parse_cmd = subparsers.add_parser("parse-remotors-details", help="Offline parse saved HTML into PostgreSQL")
    parse_cmd.add_argument("--sidecar", default="storage/remotors-details-crawl.db")
    parse_cmd.add_argument("--limit", type=int, default=None)
    parse_cmd.add_argument("--force", action="store_true")
    parse_cmd.add_argument("--worker-id", type=int, default=0, help="Worker index 0..workers-1 for parallel parse")
    parse_cmd.add_argument("--workers", type=int, default=1, help="Number of parallel parse workers")
    parse_cmd.add_argument(
        "--concurrency",
        type=int,
        default=1,
        help="Parallel parse batches per worker (default 1; try 2-4)",
    )

    verify = subparsers.add_parser("verify-remotors-v3", help="Verify snapshot vs PostgreSQL counts")
    verify.add_argument("--snapshot", default="storage/remotors-snapshot-v3.db")

    args = parser.parse_args()
    if args.command == "parse-remotors-details":
        pool_size = max(2, int(args.concurrency) + 1)
        open_pool(min_size=1, max_size=pool_size)
    elif args.command == "crawl-remotors-details":
        open_pool(min_size=1, max_size=1)
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
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "import-remotors-structure":
            if not Path(args.snapshot).is_file():
                raise SystemExit(f"snapshot not found: {args.snapshot}")
            result = import_structure(snapshot_path=args.snapshot, resume=not args.no_resume)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "seed-remotors-crawl":
            count = seed_crawl_items(sidecar_path=args.sidecar)
            print(json.dumps({"seeded": count, "sidecar": args.sidecar}, ensure_ascii=False, indent=2))
        elif args.command == "crawl-remotors-details":
            result = crawl_details(
                phase=args.phase,
                sidecar_path=args.sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "parse-remotors-details":
            result = parse_details(
                sidecar_path=args.sidecar,
                limit=args.limit,
                force=args.force,
                worker_id=args.worker_id,
                workers=args.workers,
                concurrency=args.concurrency,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        elif args.command == "verify-remotors-v3":
            result = verify_v3(snapshot_path=args.snapshot)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if not result["ok"]:
                raise SystemExit(1)
    finally:
        close_pool()


if __name__ == "__main__":
    main()
