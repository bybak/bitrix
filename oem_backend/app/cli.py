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
        choices=["under", "variant", "year"],
        default="under",
        help="under=variants with <=N assemblies (default); variant=relative thin; year=by ARI+year",
    )
    plan.add_argument("--limit", type=int, default=10000, help="Max variants in plan")
    plan.add_argument(
        "--max-assemblies",
        type=int,
        default=15,
        help="For mode=under: include variants with at most this many assemblies",
    )

    backfill = subparsers.add_parser(
        "backfill-remotors-assembly-keys",
        help="Set oem_source_nodes.external_id to arib:aria for assembly rows",
    )
    backfill.add_argument("--apply", action="store_true", help="Apply updates (default is dry-run)")

    fix_arib = subparsers.add_parser(
        "fix-remotors-assembly-arib-from-brand",
        help="Correct assembly source_node.arib from variant brand (HUM/KTM/BRP mismatches)",
    )
    fix_arib.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")

    subparsers.add_parser(
        "ensure-oem-source-node-indexes",
        help="Create source_node_id FK indexes (CONCURRENTLY, run once before backfill-keys)",
    )

    recrawl_variants = subparsers.add_parser(
        "recrawl-remotors-variants",
        help="Re-crawl specific vehicle variants that have too few assemblies",
    )
    recrawl_variants.add_argument("--variant-ids", default=None, help="Comma-separated oem_vehicle_variants.id values")
    recrawl_variants.add_argument("--from-diagnose", action="store_true", help="Use thin_variants from latest diagnose query")
    recrawl_variants.add_argument(
        "--under-crawled",
        action="store_true",
        help="Use variants with <= max-assemblies (broader than --from-diagnose)",
    )
    recrawl_variants.add_argument("--limit", type=int, default=500, help="Max variants when using --from-diagnose")
    recrawl_variants.add_argument(
        "--max-assemblies",
        type=int,
        default=15,
        help="With --under-crawled: max assembly count to qualify",
    )
    recrawl_variants.add_argument("--force", action="store_true", help="Re-import assemblies even if marked complete")
    recrawl_variants.add_argument("--no-images", action="store_true", help="Skip diagram image downloads")

    fix_dup_variants = subparsers.add_parser(
        "fix-remotors-duplicate-variants",
        help="Merge duplicate vehicle variants (same UI identity) and dedupe assemblies",
    )
    fix_dup_variants.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")

    fix_dup_assemblies = subparsers.add_parser(
        "fix-remotors-duplicate-assemblies",
        help="Merge duplicate assemblies within the same vehicle variant",
    )
    fix_dup_assemblies.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")
    fix_dup_assemblies.add_argument("--limit", type=int, default=100000, help="Max duplicate groups to process")

    fix_dup_contents = subparsers.add_parser(
        "fix-remotors-duplicate-assembly-contents",
        help="Dedupe duplicated parts, diagrams, and hotspots inside assemblies",
    )
    fix_dup_contents.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")
    fix_dup_contents.add_argument(
        "--assembly-ids",
        default=None,
        help="Comma-separated assembly ids (default: whole catalog)",
    )

    relink_variants = subparsers.add_parser(
        "relink-remotors-variant-assemblies",
        help="Move mis-linked assemblies onto the variant snapshot assigns (fixes thin variants)",
    )
    relink_variants.add_argument(
        "--gaps",
        required=True,
        help="Gap file from compare-remotors-snapshot (.sync.json)",
    )
    relink_variants.add_argument("--snapshot", default=None, help="Override snapshot path (default: from gaps file)")
    relink_variants.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")
    relink_variants.add_argument("--limit-variants", type=int, default=None, help="Max thin variants to process")
    relink_variants.add_argument(
        "--workers",
        type=int,
        default=1,
        help="Parallel variant workers (default: 1; prefer align-remotors-catalog-from-snapshot)",
    )
    relink_variants.add_argument(
        "--variant-ids",
        default=None,
        help="Comma-separated variant ids (default: all thin variants in gaps)",
    )

    align_catalog = subparsers.add_parser(
        "align-remotors-catalog-from-snapshot",
        help="Globally relink assemblies to snapshot variant_key (batched commits, bulk clone)",
    )
    align_catalog.add_argument(
        "--snapshot",
        required=True,
        help="SQLite snapshot from snapshot-remotors-catalog",
    )
    align_catalog.add_argument("--apply", action="store_true", help="Apply changes (default is dry-run)")
    align_catalog.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Max snapshot assembly rows to process (debug)",
    )
    align_catalog.epilog = (
        "Performance env: REMOTORS_ALIGN_COMMIT_EVERY, REMOTORS_ALIGN_SCAN_BATCH, "
        "REMOTORS_ALIGN_PROGRESS_EVERY, REMOTORS_ALIGN_FETCH"
    )

    rebuild = subparsers.add_parser(
        "rebuild-remotors-catalog-from-snapshot",
        help="Export parsed contents, rebuild catalog from SQLite snapshot, restore parts/diagrams",
    )
    rebuild.add_argument(
        "--snapshot",
        default="storage/remotors-snapshot-20260624_1606.db",
        help="SQLite snapshot path",
    )
    rebuild.add_argument(
        "--phase",
        choices=["export", "truncate", "schema", "import", "restore", "cleanup", "structure", "all"],
        default="all",
        help="Run one phase or all (default: all)",
    )
    rebuild.add_argument(
        "--skip-export",
        action="store_true",
        help="Skip export when backup tables already exist (structure/restore phases)",
    )

    audit = subparsers.add_parser(
        "audit-remotors-catalog",
        help="Read-only compare local OEM DB vs Remotors API tree (no crawl/import)",
    )
    audit.add_argument("--output", default="storage/remotors-audit.json", help="JSON report path")
    audit.add_argument("--log", default="storage/remotors-audit.log", help="Progress log path")
    audit.add_argument("--sample-limit", type=int, default=30, help="Max missing/extra assembly ids per brand in report")

    snapshot = subparsers.add_parser(
        "snapshot-remotors-catalog",
        help="Scan Remotors API tree into SQLite snapshot (read-only, ~1.5h)",
    )
    snapshot.add_argument(
        "--snapshot",
        default="storage/remotors-snapshot.db",
        help="SQLite snapshot path",
    )
    snapshot.add_argument("--log", default="storage/remotors-snapshot.log", help="Progress log path")
    snapshot.add_argument(
        "--resume",
        action="store_true",
        help="Continue existing snapshot: skip completed ARI codes, retry pending errors",
    )
    snapshot.add_argument(
        "--finalize-only",
        action="store_true",
        help="Only rebuild catalog_models aggregates (after scan crash at finalize)",
    )
    snapshot.add_argument(
        "--retry-node-file",
        default=None,
        help="JSON file with AriNode list to retry (for missed API errors)",
    )

    compare = subparsers.add_parser(
        "compare-remotors-snapshot",
        help="Compare SQLite snapshot vs local PostgreSQL (seconds, no API)",
    )
    compare.add_argument("--snapshot", required=True, help="SQLite snapshot from snapshot-remotors-catalog")
    compare.add_argument("--output", default="storage/remotors-gaps.json", help="Human-readable gap report")
    compare.add_argument("--sample-limit", type=int, default=50, help="Max sample rows per gap type in report")
    compare.add_argument(
        "--render-sync-script",
        default="storage/remotors-sync-gaps.sh",
        help="Also write bash script to run sync phases",
    )

    sync_gaps = subparsers.add_parser(
        "sync-remotors-gaps",
        help="Import missing assemblies from compare .sync.json (snapshot-backed, no tree crawl)",
    )
    sync_gaps.add_argument(
        "--gaps",
        required=True,
        help="Gap file from compare-remotors-snapshot (.sync.json)",
    )
    sync_gaps.add_argument("--snapshot", default=None, help="Override snapshot path (default: from gaps file)")
    sync_gaps.add_argument(
        "--phase",
        choices=["structure", "images", "repair", "repair-missing", "relink", "align"],
        default="structure",
        help="structure=import missing assemblies; align=global relink from snapshot; "
        "repair-missing=recrawl variants absent locally; repair=recrawl thin+missing; "
        "relink=thin variants only; images=download PNGs",
    )
    sync_gaps.add_argument(
        "--force",
        action="store_true",
        help="Re-import even if marked complete (default: skip complete assemblies)",
    )
    sync_gaps.add_argument(
        "--limit-assemblies",
        type=int,
        default=None,
        help="Max assemblies to import (structure phase)",
    )
    sync_gaps.add_argument("--limit-images", type=int, default=None, help="Max images (images phase)")
    sync_gaps.add_argument(
        "--workers",
        type=int,
        default=5,
        help="Parallel import workers (default: 5)",
    )
    sync_gaps.add_argument(
        "--state",
        default=None,
        help="Checkpoint JSON for repair phase resume (default: gaps file + .repair-state.json)",
    )
    sync_gaps.add_argument(
        "--limit-variants",
        type=int,
        default=None,
        help="Max variants to repair (repair phase only)",
    )
    sync_gaps.add_argument(
        "--variant-ids",
        default=None,
        help="Comma-separated variant ids to repair (repair phase only)",
    )
    sync_gaps.add_argument(
        "--no-images",
        action="store_true",
        help="Repair phase: skip diagram PNG downloads (faster first pass)",
    )
    sync_gaps.add_argument(
        "--no-skip-complete",
        action="store_true",
        help="Repair phase: do not skip variants that already match remote assembly count",
    )

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
            script = catalog_fix.render_recrawl_commands(
                mode=args.mode,
                limit=args.limit,
                max_assemblies=args.max_assemblies,
            )
            output = Path(args.output)
            output.parent.mkdir(parents=True, exist_ok=True)
            output.write_text(script, encoding="utf-8")
            print(f"Wrote {output} ({script.count(chr(10))} lines)")
        if args.command == "backfill-remotors-assembly-keys":
            print(
                json.dumps(
                    catalog_fix.backfill_assembly_external_ids(dry_run=not args.apply),
                    ensure_ascii=False,
                    indent=2,
                )
            )
        if args.command == "fix-remotors-assembly-arib-from-brand":
            print(
                json.dumps(
                    catalog_fix.fix_assembly_arib_from_variant_brand(dry_run=not args.apply),
                    ensure_ascii=False,
                    indent=2,
                )
            )
        if args.command == "ensure-oem-source-node-indexes":
            print(
                json.dumps(
                    catalog_fix.ensure_source_node_fk_indexes(),
                    ensure_ascii=False,
                    indent=2,
                )
            )
        if args.command == "recrawl-remotors-variants":
            if args.under_crawled:
                variant_ids = [
                    int(row["variant_id"])
                    for row in catalog_fix.list_under_crawled_variants(
                        max_assemblies=args.max_assemblies,
                        limit=args.limit,
                    )
                    if row.get("variant_id")
                ]
            elif args.from_diagnose:
                variant_ids = [
                    int(row["variant_id"])
                    for row in catalog_fix.list_thin_variants(limit=args.limit)
                    if row.get("variant_id")
                ]
            elif args.variant_ids:
                variant_ids = [int(item.strip()) for item in args.variant_ids.split(",") if item.strip()]
            else:
                raise SystemExit("Provide --variant-ids, --from-diagnose, or --under-crawled")
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
        if args.command == "fix-remotors-duplicate-assemblies":
            result = catalog_fix.apply_duplicate_assembly_fixes(
                dry_run=not args.apply,
                limit=args.limit,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if result.get("message"):
                print(result["message"], flush=True)
        if args.command == "fix-remotors-duplicate-assembly-contents":
            assembly_ids = None
            if args.assembly_ids:
                assembly_ids = [int(item.strip()) for item in args.assembly_ids.split(",") if item.strip()]
            result = catalog_fix.apply_duplicate_assembly_contents_fixes(
                dry_run=not args.apply,
                assembly_ids=assembly_ids,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
            if result.get("message"):
                print(result["message"], flush=True)
        if args.command == "relink-remotors-variant-assemblies":
            from app.importers import remotors_repair

            relink_variant_ids = None
            if args.variant_ids:
                relink_variant_ids = [
                    int(item.strip()) for item in args.variant_ids.split(",") if item.strip()
                ]
            result = remotors_repair.relink_variants_from_gaps(
                gaps_path=args.gaps,
                snapshot_path=args.snapshot,
                limit_variants=args.limit_variants,
                variant_ids=relink_variant_ids,
                dry_run=not args.apply,
                workers=args.workers,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        if args.command == "align-remotors-catalog-from-snapshot":
            from app.importers import remotors_repair

            result = remotors_repair.align_catalog_from_snapshot(
                snapshot_path=args.snapshot,
                dry_run=not args.apply,
                limit=args.limit,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        if args.command == "rebuild-remotors-catalog-from-snapshot":
            from app.importers import remotors_rebuild

            result = remotors_rebuild.run_rebuild(
                snapshot_path=args.snapshot,
                phase=args.phase,
                skip_export=args.skip_export,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        if args.command == "audit-remotors-catalog":
            from app.importers import remotors_audit

            result = remotors_audit.run_audit(
                output=args.output,
                log_path=args.log,
                sample_limit=args.sample_limit,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        if args.command == "snapshot-remotors-catalog":
            from app.importers import remotors_snapshot

            result = remotors_snapshot.run_snapshot_catalog(
                snapshot_path=args.snapshot,
                log_path=args.log,
                resume=args.resume,
                finalize_only=args.finalize_only,
                retry_node_file=args.retry_node_file,
            )
            print(json.dumps(result, ensure_ascii=False, indent=2))
        if args.command == "compare-remotors-snapshot":
            from app.importers import remotors_compare, remotors_sync

            result = remotors_compare.compare_snapshot(
                snapshot_path=args.snapshot,
                output=args.output,
                sample_limit=args.sample_limit,
            )
            if args.render_sync_script:
                remotors_sync.render_sync_script(
                    str(Path(args.output).with_suffix(".sync.json")),
                    args.render_sync_script,
                )
                result["sync_script"] = args.render_sync_script
            print(json.dumps({k: v for k, v in result.items() if k != "_full"}, ensure_ascii=False, indent=2))
        if args.command == "sync-remotors-gaps":
            from app.importers import remotors_repair, remotors_sync

            if args.phase == "structure":
                result = remotors_sync.sync_structure(
                    gaps_path=args.gaps,
                    snapshot_path=args.snapshot,
                    limit_assemblies=args.limit_assemblies,
                    force=args.force,
                    workers=args.workers,
                )
            elif args.phase == "repair":
                repair_variant_ids = None
                if args.variant_ids:
                    repair_variant_ids = [
                        int(item.strip()) for item in args.variant_ids.split(",") if item.strip()
                    ]
                result = remotors_repair.repair_from_gaps(
                    gaps_path=args.gaps,
                    state_path=args.state,
                    limit=args.limit_variants,
                    variant_ids=repair_variant_ids,
                    download_images=not args.no_images,
                    force=args.force,
                    skip_complete=not args.no_skip_complete,
                )
            elif args.phase == "repair-missing":
                repair_variant_ids = None
                if args.variant_ids:
                    repair_variant_ids = [
                        int(item.strip()) for item in args.variant_ids.split(",") if item.strip()
                    ]
                result = remotors_repair.repair_from_gaps(
                    gaps_path=args.gaps,
                    state_path=args.state,
                    limit=args.limit_variants,
                    variant_ids=repair_variant_ids,
                    download_images=not args.no_images,
                    force=args.force,
                    skip_complete=not args.no_skip_complete,
                    buckets=("missing_variants",),
                )
            elif args.phase == "align":
                gaps = json.loads(Path(args.gaps).read_text(encoding="utf-8"))
                snapshot_path = args.snapshot or gaps.get("snapshot_path")
                if not snapshot_path:
                    raise SystemExit("snapshot_path missing: pass --snapshot or use gaps file with snapshot_path")
                result = remotors_repair.align_catalog_from_snapshot(
                    snapshot_path=snapshot_path,
                    dry_run=False,
                    limit=args.limit_assemblies,
                )
            elif args.phase == "relink":
                relink_variant_ids = None
                if args.variant_ids:
                    relink_variant_ids = [
                        int(item.strip()) for item in args.variant_ids.split(",") if item.strip()
                    ]
                result = remotors_repair.relink_variants_from_gaps(
                    gaps_path=args.gaps,
                    snapshot_path=args.snapshot,
                    limit_variants=args.limit_variants,
                    variant_ids=relink_variant_ids,
                    dry_run=False,
                    workers=args.workers,
                )
            else:
                result = remotors_sync.sync_images(
                    gaps_path=args.gaps,
                    snapshot_path=args.snapshot,
                    limit=args.limit_images,
                    force=args.force,
                    workers=args.workers,
                )
            print(json.dumps(result, ensure_ascii=False, indent=2))
    finally:
        close_pool()


if __name__ == "__main__":
    main()
