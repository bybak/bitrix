from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from app.yamaha_v1.snapshot_store import YamahaSnapshotStore, open_snapshot
from app.yamaha_v1.progress import ProgressReporter

from .client import (
    browse_categories,
    browse_diagrams,
    browse_model_detail,
    browse_models,
    browse_years,
)
from .constants import (
    DEFAULT_SNAPSHOT_PATH,
    OUTBOARD_SLUG,
    ROOT_ARIB,
    US_PRODUCT_TYPES,
)
from .keys import assembly_key, clean_label, nav_path, parse_year, variant_key


def _reserve(progress: ProgressReporter, n: int = 1, *, stage: bool = True) -> None:
    if n <= 0:
        return
    progress.add_total(n)
    if stage:
        progress.add_stage_total(n)


def _complete(progress: ProgressReporter, msg: str) -> None:
    progress.advance(msg)


def _select_products(product_slug: str | None) -> list[dict[str, str]]:
    if product_slug and product_slug.lower() != "all":
        for row in US_PRODUCT_TYPES:
            if row["slug"] == product_slug:
                return [row]
        raise ValueError(f"unknown US product slug: {product_slug}")
    return list(US_PRODUCT_TYPES)


def _filter_diagrams(
    diagrams: list[dict[str, Any]],
    *,
    limit: int | None,
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for diagram in diagrams:
        diagram_name = clean_label(diagram.get("name") or "")
        if "TITLE PAGE" in diagram_name.upper():
            continue
        if not (diagram.get("availableImageIds") or []):
            continue
        rows.append(diagram)
        if limit is not None and len(rows) >= limit:
            break
    return rows


def _scan_model(
    *,
    store: YamahaSnapshotStore,
    progress: ProgressReporter,
    product: dict[str, str],
    top_row: dict[str, Any],
    category_row: dict[str, Any] | None,
    model_row: dict[str, Any],
    stats: dict[str, int],
    resume: bool,
    limit_diagrams_per_model: int | None,
) -> None:
    product_slug = product["slug"]
    product_name = product["name"]
    top_id = str(top_row["id"])
    top_name = clean_label(top_row.get("name"))
    category_id = str(category_row["id"]) if category_row else None
    category_name = clean_label(category_row.get("name")) if category_row else None
    model_id = str(model_row["id"])
    model_name = clean_label(model_row.get("name"))

    model_nav_path = nav_path(
        product_name=product_name,
        top_name=top_name,
        category_name=category_name,
        model_name=model_name,
    )
    store.add_nav_node(
        root_arib=ROOT_ARIB,
        rel="model",
        title=model_name,
        depth=len(model_nav_path) - 1,
        path=model_nav_path,
    )

    vkey = variant_key(
        product_slug=product_slug,
        top_id=top_id,
        category_id=category_id,
        model_id=model_id,
    )
    if resume and store.has_variant(vkey):
        stats["skipped_variants"] += 1
        _complete(progress, f"skip model {model_name}")
        return

    try:
        detail = browse_model_detail(product_slug=product_slug, top_id=top_id, model_id=model_id)
        diagrams = browse_diagrams(product_slug=product_slug, top_id=top_id, model_id=model_id)
    except Exception as exc:
        store.log_error(
            root_arib=ROOT_ARIB,
            stage="model_diagrams",
            context={
                "product_slug": product_slug,
                "product_name": product_name,
                "top_id": top_id,
                "top_name": top_name,
                "category_id": category_id,
                "category_name": category_name,
                "model_id": model_id,
                "model_name": model_name,
            },
            error=str(exc),
        )
        stats["errors"] += 1
        _complete(progress, f"ERROR model {model_name}: {exc}")
        return

    if limit_diagrams_per_model is not None:
        diagrams = _filter_diagrams(diagrams, limit=limit_diagrams_per_model)
    else:
        diagrams = _filter_diagrams(diagrams, limit=None)

    assembly_count = 0
    for diagram in diagrams:
        diagram_id = str(diagram.get("id") or "")
        diagram_name = clean_label(diagram.get("name")) or diagram_id
        image_ids = diagram.get("availableImageIds") or []
        for image_id in image_ids:
            asm_key = assembly_key(model_id=model_id, diagram_id=diagram_id, image_id=image_id)
            if resume and store.has_assembly(asm_key):
                assembly_count += 1
                continue
            store.queue_assembly(
                {
                    "variant_key": vkey,
                    "assembly_key": asm_key,
                    "root_arib": ROOT_ARIB,
                    "title": diagram_name,
                    "path_json": [*model_nav_path, diagram_name],
                    "source_payload": {
                        "source": "yamaha_us",
                        "product_slug": product_slug,
                        "top_id": top_id,
                        "category_id": category_id,
                        "model_id": model_id,
                        "diagram_id": diagram_id,
                        "image_id": image_id,
                        "catalog_id": detail.get("catalogId"),
                    },
                    "illust_url": f"us-api:image/{image_id}",
                }
            )
            assembly_count += 1
            stats["assemblies"] += 1

    store.upsert_variant(
        {
            "variant_key": vkey,
            "root_arib": ROOT_ARIB,
            "model_name": model_name,
            "source_designation": model_name,
            "year_from": parse_year(top_name),
            "variant_section": category_name,
            "browse_line": product_name,
            "path_json": model_nav_path,
            "source_payload": {
                "source": "yamaha_us",
                "product_slug": product_slug,
                "top_id": top_id,
                "top_name": top_name,
                "category_id": category_id,
                "category_name": category_name,
                "model_id": model_id,
                "model_detail": detail,
            },
            "assembly_count": assembly_count,
        }
    )
    stats["variants"] += 1
    _complete(progress, f"{product_slug} {model_name} assemblies={assembly_count}")


def _scan_models_for_top(
    *,
    store: YamahaSnapshotStore,
    progress: ProgressReporter,
    product: dict[str, str],
    top_row: dict[str, Any],
    category_row: dict[str, Any] | None,
    stats: dict[str, int],
    resume: bool,
    limit_diagrams_per_model: int | None,
    models_left: int | None,
) -> int:
    product_slug = product["slug"]
    top_id = str(top_row["id"])
    category_id = str(category_row["id"]) if category_row else None
    if models_left is not None and models_left <= 0:
        return 0
    try:
        models = browse_models(
            product_slug=product_slug,
            top_id=top_id,
            category_id=category_id,
        )
    except Exception as exc:
        store.log_error(
            root_arib=ROOT_ARIB,
            stage="models",
            context={"product_slug": product_slug, "top_id": top_id, "category_id": category_id},
            error=str(exc),
        )
        stats["errors"] += 1
        return 0

    if models_left is not None:
        models = models[: max(0, models_left)]

    _reserve(progress, len(models))
    scanned = 0
    for model_row in models:
        _scan_model(
            store=store,
            progress=progress,
            product=product,
            top_row=top_row,
            category_row=category_row,
            model_row=model_row,
            stats=stats,
            resume=resume,
            limit_diagrams_per_model=limit_diagrams_per_model,
        )
        scanned += 1
    return scanned


def _scan_product(
    *,
    store: YamahaSnapshotStore,
    progress: ProgressReporter,
    product: dict[str, str],
    stats: dict[str, int],
    resume: bool,
    limit_models: int | None,
    limit_diagrams_per_model: int | None,
) -> None:
    product_slug = product["slug"]
    product_name = product["name"]
    store.add_nav_node(
        root_arib=ROOT_ARIB,
        rel="product",
        title=product_name,
        depth=0,
        path=[product_name],
    )

    _reserve(progress, 1)
    try:
        tops = browse_years(product_slug=product_slug)
    except Exception as exc:
        store.log_error(
            root_arib=ROOT_ARIB,
            stage="years",
            context={"product_slug": product_slug},
            error=str(exc),
        )
        stats["errors"] += 1
        _complete(progress, f"ERROR {product_slug} years: {exc}")
        return
    _complete(progress, f"{product_slug} years={len(tops)}")

    models_left = limit_models
    for top_row in tops:
        if models_left is not None and models_left <= 0:
            break
        top_id = str(top_row["id"])
        top_name = clean_label(top_row.get("name"))
        rel = "category" if product_slug == OUTBOARD_SLUG else "year"
        store.add_nav_node(
            root_arib=ROOT_ARIB,
            rel=rel,
            title=top_name,
            depth=1,
            path=[product_name, top_name],
        )

        if product_slug == OUTBOARD_SLUG:
            try:
                categories = browse_categories(top_id=top_id)
            except Exception as exc:
                store.log_error(
                    root_arib=ROOT_ARIB,
                    stage="categories",
                    context={"product_slug": product_slug, "top_id": top_id},
                    error=str(exc),
                )
                stats["errors"] += 1
                continue

            if categories:
                for category_row in categories:
                    if models_left is not None and models_left <= 0:
                        break
                    category_name = clean_label(category_row.get("name"))
                    store.add_nav_node(
                        root_arib=ROOT_ARIB,
                        rel="subcategory",
                        title=category_name,
                        depth=2,
                        path=[product_name, top_name, category_name],
                    )
                    scanned = _scan_models_for_top(
                        store=store,
                        progress=progress,
                        product=product,
                        top_row=top_row,
                        category_row=category_row,
                        stats=stats,
                        resume=resume,
                        limit_diagrams_per_model=limit_diagrams_per_model,
                        models_left=models_left,
                    )
                    if models_left is not None:
                        models_left -= scanned
            else:
                scanned = _scan_models_for_top(
                    store=store,
                    progress=progress,
                    product=product,
                    top_row=top_row,
                    category_row=None,
                    stats=stats,
                    resume=resume,
                    limit_diagrams_per_model=limit_diagrams_per_model,
                    models_left=models_left,
                )
                if models_left is not None:
                    models_left -= scanned
        else:
            scanned = _scan_models_for_top(
                store=store,
                progress=progress,
                product=product,
                top_row=top_row,
                category_row=None,
                stats=stats,
                resume=resume,
                limit_diagrams_per_model=limit_diagrams_per_model,
                models_left=models_left,
            )
            if models_left is not None:
                models_left -= scanned


def completed_products(store: YamahaSnapshotStore) -> set[str]:
    value = store.get_meta("completed_product_slugs", [])
    return set(value) if isinstance(value, list) else set()


def _pending_errors_for_product(store: YamahaSnapshotStore, product_slug: str) -> int:
    count = 0
    for row in store.list_scan_errors(root_arib=ROOT_ARIB, stage="model_diagrams"):
        context = json.loads(row["context_json"])
        if context.get("product_slug") == product_slug:
            count += 1
    return count


def _maybe_mark_product_completed(store: YamahaSnapshotStore, product_slug: str) -> bool:
    if _pending_errors_for_product(store, product_slug) == 0:
        mark_product_completed(store, product_slug)
        return True
    return False


def mark_product_completed(store: YamahaSnapshotStore, product_slug: str) -> None:
    done = sorted(completed_products(store) | {product_slug})
    store.set_meta("completed_product_slugs", done)


def scan_to_snapshot(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
    product_slug: str = "all",
    limit_models: int | None = None,
    limit_diagrams_per_model: int | None = None,
    resume: bool = True,
    reset: bool = False,
) -> dict[str, Any]:
    products = _select_products(product_slug if product_slug != "all" else None)
    store = YamahaSnapshotStore(snapshot_path, reset=reset)
    stats = {
        "variants": 0,
        "assemblies": 0,
        "errors": 0,
        "skipped_variants": 0,
    }

    store.set_meta("created_at", datetime.now(timezone.utc).isoformat())
    store.set_meta(
        "scan_params",
        {
            "root_arib": ROOT_ARIB,
            "product_slug": product_slug,
            "limit_models": limit_models,
            "limit_diagrams_per_model": limit_diagrams_per_model,
        },
    )

    progress = ProgressReporter(total=0, label="yamaha-us-snapshot")
    done_products = completed_products(store) if resume else set()

    for product in products:
        slug = product["slug"]
        if resume and slug in done_products:
            _reserve(progress, 1, stage=False)
            _complete(progress, f"skip completed product {slug}")
            continue
        _scan_product(
            store=store,
            progress=progress,
            product=product,
            stats=stats,
            resume=resume,
            limit_models=limit_models,
            limit_diagrams_per_model=limit_diagrams_per_model,
        )
        if _maybe_mark_product_completed(store, slug):
            progress.tick(f"marked complete {slug}")
        else:
            pending = _pending_errors_for_product(store, slug)
            progress.tick(f"{slug} has {pending} model errors — not marked complete")
        store.finalize()

    store.finalize()
    store.close()
    progress.finish(
        f"variants={stats['variants']} assemblies={stats['assemblies']} errors={stats['errors']}"
    )
    return {
        "snapshot": snapshot_path,
        "stats": stats,
        "resume": resume,
    }


def _rows_from_error_context(context: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any] | None, dict[str, Any]]:
    product_slug = str(context["product_slug"])
    top_id = str(context["top_id"])
    model_id = str(context["model_id"])
    category_id = context.get("category_id")
    category_id = str(category_id) if category_id else None

    top_name = context.get("top_name")
    if not top_name:
        for row in browse_years(product_slug=product_slug):
            if str(row["id"]) == top_id:
                top_name = clean_label(row.get("name"))
                break
        top_name = top_name or top_id

    category_row: dict[str, Any] | None = None
    if category_id:
        category_name = context.get("category_name")
        if not category_name:
            for row in browse_categories(top_id=top_id):
                if str(row["id"]) == category_id:
                    category_name = clean_label(row.get("name"))
                    break
        category_row = {"id": category_id, "name": category_name or category_id}

    model_name = context.get("model_name")
    if not model_name:
        detail = browse_model_detail(product_slug=product_slug, top_id=top_id, model_id=model_id)
        model_name = clean_label(detail.get("name")) or model_id

    return (
        {"id": top_id, "name": top_name},
        category_row,
        {"id": model_id, "name": model_name},
    )


def retry_snapshot_errors(
    *,
    snapshot_path: str = DEFAULT_SNAPSHOT_PATH,
) -> dict[str, Any]:
    """Re-fetch models that failed during snapshot due to transient network errors."""
    store = YamahaSnapshotStore(snapshot_path, reset=False)
    product_by_slug = {row["slug"]: row for row in US_PRODUCT_TYPES}
    errors = store.list_scan_errors(root_arib=ROOT_ARIB, stage="model_diagrams")
    stats = {
        "pending_errors": len(errors),
        "retried": 0,
        "fixed": 0,
        "still_failed": 0,
        "variants": 0,
        "assemblies": 0,
        "errors": 0,
    }
    touched_products: set[str] = set()

    progress = ProgressReporter(total=max(len(errors), 1), label="yamaha-us-snapshot-retry")
    for err in errors:
        context = json.loads(err["context_json"])
        product_slug = str(context.get("product_slug") or "")
        product = product_by_slug.get(product_slug)
        if not product:
            stats["still_failed"] += 1
            progress.advance(f"skip unknown product {product_slug}")
            continue

        vkey = variant_key(
            product_slug=product_slug,
            top_id=str(context["top_id"]),
            category_id=str(context["category_id"]) if context.get("category_id") else None,
            model_id=str(context["model_id"]),
        )
        if store.has_variant(vkey):
            store.delete_scan_error(int(err["id"]))
            stats["fixed"] += 1
            progress.advance(f"already fixed {context.get('model_name') or context['model_id']}")
            continue

        top_row, category_row, model_row = _rows_from_error_context(context)
        store.delete_scan_error(int(err["id"]))
        stats["retried"] += 1
        touched_products.add(product_slug)

        before_variants = stats["variants"]
        _scan_model(
            store=store,
            progress=progress,
            product=product,
            top_row=top_row,
            category_row=category_row,
            model_row=model_row,
            stats=stats,
            resume=False,
            limit_diagrams_per_model=None,
        )
        if store.has_variant(vkey):
            stats["fixed"] += 1
        else:
            stats["still_failed"] += 1
        if stats["variants"] > before_variants:
            store.finalize()

    for product_slug in touched_products:
        _maybe_mark_product_completed(store, product_slug)

    stats["remaining_errors"] = len(store.list_scan_errors(root_arib=ROOT_ARIB, stage="model_diagrams"))
    store.finalize()
    store.close()
    progress.finish(
        f"fixed={stats['fixed']} still_failed={stats['still_failed']} "
        f"remaining_errors={stats['remaining_errors']}"
    )
    return {
        "snapshot": snapshot_path,
        "stats": stats,
    }


__all__ = ["open_snapshot", "scan_to_snapshot", "retry_snapshot_errors"]
