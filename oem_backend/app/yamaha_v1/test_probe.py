from __future__ import annotations

from typing import Any

from app.yamaha_v1.client import (
    catalog_index,
    catalog_text,
    model_list,
    model_name_list,
    model_year_list,
    product_list,
)
from app.yamaha_v1.constants import DEFAULT_LANG_ID, DEFAULT_TEST_DISPLACEMENT_TYPE, DEFAULT_TEST_PRODUCT_ID, REGION_CONFIG
from app.yamaha_v1.context import (
    build_catalog_index_payload,
    build_catalog_text_payload,
    build_model_list_payload,
    build_model_year_payload,
    enrich_model_variant,
)
from app.yamaha_v1.keys import model_display_name


def probe_chain(
    *,
    root_arib: str = "YMH-JP",
    product_id: str = DEFAULT_TEST_PRODUCT_ID,
    displacement_type: str = DEFAULT_TEST_DISPLACEMENT_TYPE,
    model_name: str | None = "FZR400RR",
    model_year: str | None = "1990",
    fig_no: str | None = "04",
    lang_id: str = DEFAULT_LANG_ID,
) -> dict[str, Any]:
    cfg = REGION_CONFIG[root_arib]
    base_code = cfg["base_code"]
    result: dict[str, Any] = {"root_arib": root_arib, "base_code": base_code, "steps": {}}

    product_payload = product_list(base_code=base_code, lang_id=lang_id)
    user_context = product_payload.get("userContext") or {}
    result["steps"]["product_list"] = {
        "destination": user_context.get("destination"),
        "currency": user_context.get("currencyCode"),
        "products": len(product_payload.get("productDataCollection") or []),
        "models_prefetched": len(product_payload.get("modelNameDataCollection") or []),
        "displacements": len(product_payload.get("displacementDataCollection") or []),
    }

    models_payload = model_name_list(
        base_code=base_code,
        product_id=product_id,
        displacement_type=displacement_type,
        lang_id=lang_id,
    )
    models = models_payload.get("modelNameDataCollection") or []
    result["steps"]["model_name_list"] = {"count": len(models), "sample": models[:3]}

    chosen = next((m for m in models if (m.get("modelName") or "") == (model_name or "")), models[0] if models else None)
    if not chosen:
        result["error"] = "no models found"
        return result

    chosen_name = (chosen.get("modelName") or "").strip()
    chosen_nick = (chosen.get("nickname") or "").strip()
    result["selected_model"] = model_display_name(chosen)

    year_payload = build_model_year_payload(
        base_code=base_code,
        lang_id=lang_id,
        user_context=user_context,
        product_id=product_id,
        model_name=chosen_name,
        nickname=chosen_nick,
    )
    years_payload = model_year_list(payload=year_payload)
    years = [y for y in (years_payload.get("modelYearDataCollection") or []) if str(y.get("modelYear") or "") not in ("", "ALL")]
    result["steps"]["model_year_list"] = {"count": len(years), "sample": years[:5]}

    resolved_year = model_year or (str(years[0]["modelYear"]) if years else None)
    if not resolved_year:
        result["error"] = "no model years found"
        return result

    list_payload = build_model_list_payload(
        base_code=base_code,
        lang_id=lang_id,
        user_context=user_context,
        product_id=product_id,
        model_name=chosen_name,
        nickname=chosen_nick,
        model_year=resolved_year,
    )
    variants_payload = model_list(payload=list_payload)
    variants = variants_payload.get("modelDataCollection") or []
    result["steps"]["model_list"] = {"count": len(variants), "sample": variants[:2]}
    if not variants:
        result["error"] = "no color variants found"
        return result

    model_variant = enrich_model_variant(variants[0], product_id=product_id)
    catalog_payload = build_catalog_index_payload(
        base_code=base_code,
        lang_id=lang_id,
        user_context=user_context,
        model_variant=model_variant,
    )
    index_payload = catalog_index(payload=catalog_payload)
    figs = index_payload.get("figDataCollection") or []
    catalog_no = str(index_payload.get("catalogNo") or "")
    result["steps"]["catalog_index"] = {
        "catalog_no": catalog_no,
        "fig_count": len(figs),
        "sample_figs": figs[:3],
    }

    fig = next((f for f in figs if str(f.get("figNo")) == str(fig_no)), figs[0] if figs else None)
    if not fig:
        result["error"] = "no figures found"
        return result

    text_payload = build_catalog_text_payload(
        catalog_index_payload=catalog_payload,
        user_context=user_context,
        catalog_no=catalog_no,
        fig=fig,
    )
    text_response = catalog_text(payload=text_payload)
    parts = text_response.get("partsDataCollection") or []
    hotspots = text_response.get("hotspotoDataCollection") or text_response.get("hotspotDataCollection") or []
    result["steps"]["catalog_text"] = {
        "fig_no": fig.get("figNo"),
        "fig_name": fig.get("figName"),
        "illust_no": fig.get("illustNo"),
        "parts_count": len(parts),
        "hotspots_count": len(hotspots),
        "sample_part": parts[0] if parts else None,
        "sample_hotspot": hotspots[0] if hotspots else None,
    }
    result["ok"] = True
    return result
