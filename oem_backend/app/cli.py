import argparse
import json
from pathlib import Path

from app.db import close_pool, open_pool
from app.importers import ari, catalog_fix, megazip, remotors


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
        help="Comma-separated ARI brand codes or 'all'. Examples: KTM,HUM,BRP,LNX",
    )
    crawl_remotors.add_argument("--year", type=int, action="append", help="Limit crawl to a year. Can be repeated.")
    crawl_remotors.add_argument("--max-models", type=int, default=None, help="Optional model limit for test runs")
    crawl_remotors.add_argument("--max-assemblies", type=int, default=None, help="Optional safety limit for test runs")
    crawl_remotors.add_argument("--no-images", action="store_true", help="Do not download diagram images locally")
    crawl_remotors.add_argument("--force", action="store_true", help="Re-parse assemblies that already have parts and diagrams")

    diagnose = subparsers.add_parser("diagnose-remotors", help="Report Remotors catalog data issues")
    diagnose.add_argument("--output", default=None, help="Optional path to write JSON report")

    fix = subparsers.add_parser("fix-remotors-catalog", help="Fix brand/type mapping in existing DB")
    fix.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")

    cleanup = subparsers.add_parser("cleanup-remotors-orphans", help="Remove legacy empty OEM rows")
    cleanup.add_argument("--apply", action="store_true", help="Apply deletions (default is dry-run)")

    plan = subparsers.add_parser("plan-recrawl-remotors", help="Build bash commands for gap re-crawl")
    plan.add_argument("--output", default="storage/remotors-recrawl-gaps.sh", help="Output shell script path")
    plan.add_argument(
        "--mode",
        choices=["variant", "year"],
        default="variant",
        help="variant=by thin variant_id (recommended); year=by ARI brand+year",
    )

    recrawl_variants = subparsers.add_parser(
        "recrawl-remotors-variants",
        help="Re-crawl specific vehicle variants that have too few assemblies",
    )
    recrawl_variants.add_argument("--variant-ids", default=None, help="Comma-separated oem_vehicle_variants.id values")
    recrawl_variants.add_argument("--from-diagnose", action="store_true", help="Use thin_variants from latest diagnose query")
    recrawl_variants.add_argument("--limit", type=int, default=500, help="Max variants when using --from-diagnose")
    recrawl_variants.add_argument("--force", action="store_true", help="Re-import assemblies even if marked complete")
    recrawl_variants.add_argument("--no-images", action="store_true", help="Skip diagram image downloads")

    fix_dup_variants = subparsers.add_parser(
        "fix-remotors-duplicate-variants",
        help="Merge duplicate vehicle variants (same UI identity) and dedupe assemblies",
    )
    fix_dup_variants.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")

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
        if args.command == "diagnose-remotors":
            report = catalog_fix.diagnose()
            payload = json.dumps(report, ensure_ascii=False, indent=2, default=str)
            if args.output:
                Path(args.output).write_text(payload + "\n", encoding="utf-8")
                print(f"Wrote {args.output}")
            else:
                print(payload)
        if args.command == "fix-remotors-catalog":
            result = catalog_fix.apply_fixes(dry_run=not args.apply)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if result.get("message"):
                print(result["message"], flush=True)
        if args.command == "cleanup-remotors-orphans":
            print(json.dumps(catalog_fix.cleanup_orphans(dry_run=not args.apply), ensure_ascii=False, indent=2))
        if args.command == "plan-recrawl-remotors":
            script = catalog_fix.render_recrawl_commands(mode=args.mode)
            output = Path(args.output)
            output.parent.mkdir(parents=True, exist_ok=True)
            output.write_text(script, encoding="utf-8")
            print(f"Wrote {output} ({script.count(chr(10))} lines)")
        if args.command == "recrawl-remotors-variants":
            if args.from_diagnose:
                variant_ids = [
                    int(row["variant_id"])
                    for row in catalog_fix.list_thin_variants(limit=args.limit)
                    if row.get("variant_id")
                ]
            elif args.variant_ids:
                variant_ids = [int(item.strip()) for item in args.variant_ids.split(",") if item.strip()]
            else:
                raise SystemExit("Provide --variant-ids or --from-diagnose")
            print(
                json.dumps(
                    remotors.repair_variants(
                        variant_ids=variant_ids,
                        download_images=not args.no_images,
                        force=args.force,
                    ),
                    ensure_ascii=False,
                    indent=2,
                )
            )
        if args.command == "fix-remotors-duplicate-variants":
            result = catalog_fix.apply_duplicate_variant_fixes(dry_run=not args.apply)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if result.get("message"):
                print(result["message"], flush=True)
    finally:
        close_pool()


if __name__ == "__main__":
    main()
