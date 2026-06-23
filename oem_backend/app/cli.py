import argparse
import json

from app.db import close_pool, open_pool
from app.importers import ari, megazip, remotors


def import_pilot(source: str) -> list[dict]:
    results: list[dict] = []
    if source in {"all", "megazip"}:
        results.append(megazip.import_sample())
    if source in {"all", "ari"}:
        results.append(ari.import_sample())
    return results


def main() -> None:
    parser = argparse.ArgumentParser(description="OEM Schemas Catalog CLI")
    subparsers = parser.add_subparsers(dest="command", required=True)

    pilot = subparsers.add_parser("import-pilot", help="Import pilot sample data")
    pilot.add_argument("--source", choices=["all", "megazip", "ari"], default="all")

    crawl_remotors = subparsers.add_parser("crawl-remotors", help="Crawl full Remotors/ARI catalog")
    crawl_remotors.add_argument(
        "--confirm-full-crawl",
        action="store_true",
        help="Required safety flag. Without it the full crawler will not start.",
    )
    crawl_remotors.add_argument(
        "--brands",
        default="all",
        help="Comma-separated ARI brand codes or 'all'. Examples: KTM,HUM,BRP,BRP_SEA,BRP_SKI,LNX",
    )
    crawl_remotors.add_argument("--year", type=int, action="append", help="Limit crawl to a year. Can be repeated.")
    crawl_remotors.add_argument("--max-models", type=int, default=None, help="Optional model limit for test runs")
    crawl_remotors.add_argument("--max-assemblies", type=int, default=None, help="Optional safety limit for test runs")
    crawl_remotors.add_argument("--no-images", action="store_true", help="Do not download diagram images locally")
    crawl_remotors.add_argument("--force", action="store_true", help="Re-parse assemblies that already have parts and diagrams")

    args = parser.parse_args()
    open_pool()
    try:
        if args.command == "import-pilot":
            print(json.dumps(import_pilot(args.source), ensure_ascii=False, indent=2))
        if args.command == "crawl-remotors":
            if not args.confirm_full_crawl:
                raise SystemExit("Refusing to start full Remotors crawl without --confirm-full-crawl")
            brands = None if args.brands == "all" else [item.strip() for item in args.brands.split(",") if item.strip()]
            print(
                json.dumps(
                    remotors.crawl(
                        brands=brands,
                        years=args.year,
                        max_models=args.max_models,
                        max_assemblies=args.max_assemblies,
                        download_images=not args.no_images,
                        force=args.force,
                    ),
                    ensure_ascii=False,
                    indent=2,
                )
            )
    finally:
        close_pool()


if __name__ == "__main__":
    main()
