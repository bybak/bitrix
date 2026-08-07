from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from app.yamaha_v1.client import (
    catalog_index,
    model_list,
    model_name_list,
    model_year_list,
    product_list,
)
from app.yamaha_v1.constants import (
    DEFAULT_LANG_ID,
    DEFAULT_TEST_DISPLACEMENT_TYPE,
    DEFAULT_TEST_PRODUCT_ID,
    REGION_CONFIG,
)
from app.yamaha_v1.context import (
    build_catalog_index_payload,
    build_catalog_text_payload,
    build_model_list_payload,
    build_model_year_payload,
    enrich_model_variant,
)
from app.yamaha_v1.keys import assembly_key, assembly_path, catalog_nav_path, model_display_name, variant_key, variant_path, variant_title
from app.yamaha_v1.product_selection import select_displacements_for_product, select_products_for_scan
from app.yamaha_v1.progress import ProgressReporter
from app.yamaha_v1.snapshot_store import YamahaSnapshotStore, open_snapshot


def _parse_year(value: str | None) -> int | None:
    if value and str(value).isdigit():
        return int(value)
    return None


def _apply_limit(items: list[Any], limit: int) -> list[Any]:
    if limit <= 0:
        return items
    return items[:limit]


def _reserve_work(progress: ProgressReporter, amount: int = 1, *, stage: bool = True) -> None:
    if amount <= 0:
        return
    progress.add_total(amount)
    if stage:
        progress.add_stage_total(amount)


def _complete_work(progress: ProgressReporter, message: str, *, step: int = 1) -> None:
    progress.advance(message, step=step)


def _scan_product_displacement(
    *,
    store: YamahaSnapshotStore,
    progress: ProgressReporter,
    root_arib: str,
    base_code: str,
    region_label: str,
    user_context: dict[str, Any],
    product_id: str,
    product_name: str,
    displacement_type: str,
    displacement_label: str,
    limit_models: int,
    limit_figs_per_variant: int,
    lang_id: str,
    resume: bool,
    region_stats: dict[str, int],
    stats: dict[str, Any],
) -> None:
    product_path = catalog_nav_path(product_name=product_name)
    disp_path = catalog_nav_path(product_name=product_name, displacement=displacement_label)
    store.add_nav_node(root_arib=root_arib, rel="product", title=product_name, depth=0, path=product_path)
    store.add_nav_node(
        root_arib=root_arib,
        rel="displacement",
        title=displacement_label,
        depth=1,
        path=disp_path,
    )

    progress.set_stage(f"{root_arib} {product_name}/{displacement_label}", 0)
    _reserve_work(progress, 1)

    try:
        models_payload = model_name_list(
            base_code=base_code,
            product_id=product_id,
            displacement_type=displacement_type,
            lang_id=lang_id,
        )
        store.record_api(
            root_arib=root_arib,
            endpoint="model_name_list",
            request={
                "productId": product_id,
                "displacementType": displacement_type,
                "baseCode": base_code,
                "langId": lang_id,
            },
            response=models_payload,
            task_key=f"{root_arib}:model_name_list:{product_id}:{displacement_type}",
        )
        _complete_work(progress, f"{root_arib} model_name_list {product_name}/{displacement_label}")
    except Exception as exc:
        store.log_error(
            root_arib=root_arib,
            stage="model_name_list",
            context={"product_id": product_id, "displacement_type": displacement_type},
            error=str(exc),
        )
        region_stats["errors"] += 1
        stats["errors"] += 1
        _complete_work(progress, f"ERROR {root_arib} model_name_list {product_name}/{displacement_label}: {exc}")
        return

    models = _apply_limit(models_payload.get("modelNameDataCollection") or [], limit_models)
    _reserve_work(progress, len(models))

    for model in models:
        model_name = (model.get("modelName") or "").strip()
        nickname = (model.get("nickname") or "").strip()
        display = model_display_name(model)

        year_payload = build_model_year_payload(
            base_code=base_code,
            lang_id=lang_id,
            user_context=user_context,
            product_id=product_id,
            model_name=model_name,
            nickname=nickname,
        )
        try:
            years_payload = model_year_list(payload=year_payload)
            store.record_api(
                root_arib=root_arib,
                endpoint="model_year_list",
                request=year_payload,
                response=years_payload,
                task_key=f"{root_arib}:years:{product_id}:{displacement_type}:{model_name}:{nickname}",
            )
            _complete_work(progress, f"{root_arib} model_year_list {display}")
        except Exception as exc:
            store.log_error(root_arib=root_arib, stage="model_year_list", context=year_payload, error=str(exc))
            region_stats["errors"] += 1
            stats["errors"] += 1
            _complete_work(progress, f"ERROR {root_arib} model_year_list {display}: {exc}")
            continue

        years = [
            y
            for y in (years_payload.get("modelYearDataCollection") or [])
            if str(y.get("modelYear") or "") not in ("", "ALL")
        ]
        if not years:
            continue

        _reserve_work(progress, len(years))

        model_path = catalog_nav_path(
            product_name=product_name,
            displacement=displacement_label,
            model_name=display,
        )
        store.add_nav_node(
            root_arib=root_arib,
            rel="model",
            title=display,
            depth=2,
            path=model_path,
        )

        for year_row in years:
            model_year = str(year_row["modelYear"])
            year_path = catalog_nav_path(
                product_name=product_name,
                displacement=displacement_label,
                model_name=display,
                model_year=model_year,
            )
            store.add_nav_node(
                root_arib=root_arib,
                rel="year",
                title=model_year,
                depth=3,
                path=year_path,
            )
            list_payload = build_model_list_payload(
                base_code=base_code,
                lang_id=lang_id,
                user_context=user_context,
                product_id=product_id,
                model_name=model_name,
                nickname=nickname,
                model_year=model_year,
            )
            try:
                variants_payload = model_list(payload=list_payload)
                store.record_api(
                    root_arib=root_arib,
                    endpoint="model_list",
                    request=list_payload,
                    response=variants_payload,
                    task_key=f"{root_arib}:model_list:{product_id}:{model_name}:{model_year}",
                )
                _complete_work(progress, f"{root_arib} model_list {display} {model_year}")
            except Exception as exc:
                store.log_error(root_arib=root_arib, stage="model_list", context=list_payload, error=str(exc))
                region_stats["errors"] += 1
                stats["errors"] += 1
                _complete_work(progress, f"ERROR {root_arib} model_list {display} {model_year}: {exc}")
                continue

            model_variants = variants_payload.get("modelDataCollection") or []
            _reserve_work(progress, len(model_variants))
            for model_variant_raw in model_variants:
                model_variant = enrich_model_variant(model_variant_raw, product_id=product_id)
                vpath = variant_path(
                    product_name=product_name,
                    displacement=displacement_label,
                    model_name=display,
                    model_year=model_year,
                    color_name=model_variant.get("colorName"),
                )
                vkey = variant_key(
                    root_arib=root_arib,
                    product_id=product_id,
                    displacement_type=displacement_type,
                    model_name=model_name,
                    nickname=nickname,
                    model_year=model_year,
                    model_type_code=str(model_variant["modelTypeCode"]),
                    product_no=str(model_variant["productNo"]),
                    color_type=str(model_variant["colorType"]),
                )

                catalog_payload = build_catalog_index_payload(
                    base_code=base_code,
                    lang_id=lang_id,
                    user_context=user_context,
                    model_variant=model_variant,
                )
                variant_source = {
                    "region": region_label,
                    "base_code": base_code,
                    "lang_id": lang_id,
                    "product_id": product_id,
                    "displacement_type": displacement_type,
                    "model": model,
                    "model_year": model_year,
                    "model_variant": model_variant,
                    "catalog_index_payload": catalog_payload,
                    "user_context": user_context,
                }

                try:
                    index_payload = catalog_index(payload=catalog_payload)
                    store.record_api(
                        root_arib=root_arib,
                        endpoint="catalog_index",
                        request=catalog_payload,
                        response=index_payload,
                        task_key=vkey,
                    )
                    _complete_work(progress, f"{root_arib} catalog_index {vkey}")
                except Exception as exc:
                    store.log_error(root_arib=root_arib, stage="catalog_index", context=catalog_payload, error=str(exc))
                    region_stats["errors"] += 1
                    stats["errors"] += 1
                    _complete_work(progress, f"ERROR {root_arib} catalog_index {vkey}: {exc}")
                    continue

                catalog_no = str(index_payload.get("catalogNo") or "")
                figs = index_payload.get("figDataCollection") or []
                figs = _apply_limit(figs, limit_figs_per_variant)
                _reserve_work(progress, len(figs))

                assembly_count = 0
                for fig in figs:
                    akey = assembly_key(
                        root_arib=root_arib,
                        catalog_no=catalog_no,
                        fig_no=str(fig["figNo"]),
                        fig_branch_no=str(fig["figBranchNo"]),
                        illust_no=str(fig["illustNo"]),
                    )
                    if resume and store.has_assembly(akey):
                        region_stats["skipped_assemblies"] += 1
                        stats["skipped_assemblies"] += 1
                        _complete_work(progress, f"skip assembly {akey}")
                        continue

                    apath = assembly_path(vpath, str(fig.get("figName") or fig["figNo"]), str(fig["figNo"]))
                    text_payload = build_catalog_text_payload(
                        catalog_index_payload=catalog_payload,
                        user_context=user_context,
                        catalog_no=catalog_no,
                        fig=fig,
                    )
                    store.queue_assembly(
                        {
                            "variant_key": vkey,
                            "assembly_key": akey,
                            "root_arib": root_arib,
                            "title": str(fig.get("figName") or f"Fig {fig['figNo']}"),
                            "path_json": apath,
                            "source_payload": {
                                "catalog_no": catalog_no,
                                "fig": fig,
                                "catalog_index_payload": catalog_payload,
                                "catalog_index_response": index_payload,
                                "catalog_text_payload": text_payload,
                                "user_context": user_context,
                            },
                            "illust_url": fig.get("illustFileURL"),
                        }
                    )
                    assembly_count += 1
                    region_stats["assemblies"] += 1
                    stats["assemblies"] += 1
                    _complete_work(progress, f"assembly {akey}")

                store.upsert_variant(
                    {
                        "variant_key": vkey,
                        "root_arib": root_arib,
                        "model_name": variant_title(model_variant),
                        "source_designation": display,
                        "year_from": _parse_year(model_year),
                        "variant_section": None,
                        "browse_line": product_name,
                        "path_json": vpath,
                        "source_payload": variant_source,
                        "assembly_count": assembly_count,
                    }
                )
                region_stats["variants"] += 1
                stats["variants"] += 1


def scan_to_snapshot(
    *,
    snapshot_path: str,
    regions: list[str] | None = None,
    product_id: str = DEFAULT_TEST_PRODUCT_ID,
    displacement_type: str = DEFAULT_TEST_DISPLACEMENT_TYPE,
    limit_models: int = 1,
    limit_figs_per_variant: int = 2,
    lang_id: str = DEFAULT_LANG_ID,
    resume: bool = True,
    reset: bool = False,
) -> dict[str, Any]:
    regions = regions or list(REGION_CONFIG.keys())
    store = YamahaSnapshotStore(snapshot_path, reset=reset)
    stats = {"variants": 0, "assemblies": 0, "errors": 0, "skipped_variants": 0, "skipped_assemblies": 0, "regions": {}}

    store.set_meta("created_at", datetime.now(timezone.utc).isoformat())
    store.set_meta("scan_params", {
        "regions": regions,
        "product_id": product_id,
        "displacement_type": displacement_type,
        "limit_models": limit_models,
        "limit_figs_per_variant": limit_figs_per_variant,
        "lang_id": lang_id,
    })

    progress = ProgressReporter(total=0, label="yamaha-snapshot")
    completed_regions = store.completed_regions() if resume else set()

    for root_arib in regions:
        if resume and root_arib in completed_regions:
            _reserve_work(progress, 1, stage=False)
            _complete_work(progress, f"skip completed region {root_arib}")
            continue

        cfg = REGION_CONFIG[root_arib]
        base_code = cfg["base_code"]
        region_label = cfg["label"]
        region_stats = {"variants": 0, "assemblies": 0, "errors": 0, "skipped_variants": 0, "skipped_assemblies": 0}

        _reserve_work(progress, 1, stage=False)
        try:
            product_payload = product_list(base_code=base_code, lang_id=lang_id)
            store.record_api(
                root_arib=root_arib,
                endpoint="product_list",
                request={"baseCode": base_code, "langId": lang_id},
                response=product_payload,
                task_key=f"{root_arib}:product_list",
            )
            _complete_work(progress, f"{root_arib} product_list")
        except Exception as exc:
            store.log_error(root_arib=root_arib, stage="product_list", context={"base_code": base_code}, error=str(exc))
            region_stats["errors"] += 1
            stats["errors"] += 1
            _complete_work(progress, f"ERROR {root_arib} product_list: {exc}")
            continue

        user_context = product_payload.get("userContext") or {}
        try:
            product_rows = select_products_for_scan(
                root_arib=root_arib,
                product_payload=product_payload,
                product_id=product_id,
            )
        except ValueError as exc:
            store.log_error(root_arib=root_arib, stage="product_filter", context={"product_id": product_id}, error=str(exc))
            region_stats["errors"] += 1
            stats["errors"] += 1
            continue

        for pid, pname in product_rows:
            disp_rows = select_displacements_for_product(
                product_payload=product_payload,
                product_id=pid,
                displacement_type=displacement_type,
            )
            for disp_type, disp_label in disp_rows:
                _scan_product_displacement(
                    store=store,
                    progress=progress,
                    root_arib=root_arib,
                    base_code=base_code,
                    region_label=region_label,
                    user_context=user_context,
                    product_id=pid,
                    product_name=pname,
                    displacement_type=disp_type,
                    displacement_label=disp_label,
                    limit_models=limit_models,
                    limit_figs_per_variant=limit_figs_per_variant,
                    lang_id=lang_id,
                    resume=resume,
                    region_stats=region_stats,
                    stats=stats,
                )

        store.mark_region_completed(root_arib)
        stats["regions"][root_arib] = region_stats

    store.finalize()
    store.close()
    progress.finish(f"variants={stats['variants']} assemblies={stats['assemblies']} errors={stats['errors']}")
    return {
        "snapshot": snapshot_path,
        "stats": stats,
        "resume": resume,
        "limits": {
            "product_id": product_id,
            "displacement_type": displacement_type,
            "limit_models": limit_models,
            "limit_figs_per_variant": limit_figs_per_variant,
        },
    }


__all__ = ["open_snapshot", "scan_to_snapshot", "YamahaSnapshotStore"]
