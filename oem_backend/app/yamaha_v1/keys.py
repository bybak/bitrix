from __future__ import annotations

import json
from typing import Any

from app.normalization import normalize_text


def key_to_str(key: tuple[Any, ...] | list[Any]) -> str:
    return json.dumps(list(key), ensure_ascii=False, separators=(",", ":"))


def product_label(product_id: str, product_name: str | None = None) -> str:
    return product_name or f"Product {product_id}"


def displacement_label(displacement: str | None, displacement_type: str) -> str:
    if displacement:
        return displacement
    return displacement_type


def model_display_name(model: dict[str, Any]) -> str:
    nickname = (model.get("nickname") or "").strip()
    model_name = (model.get("modelName") or "").strip()
    disp = (model.get("dispModelName") or "").strip()
    if disp:
        return disp
    if nickname and model_name:
        return f"{nickname} - {model_name}"
    return model_name or nickname or "Unknown model"


def variant_title(model_variant: dict[str, Any]) -> str:
    parts = [
        model_variant.get("modelName") or "",
        model_variant.get("modelYear") or "",
        model_variant.get("colorName") or model_variant.get("colorType") or "",
    ]
    title = " · ".join(str(p).strip() for p in parts if str(p).strip())
    return title or model_display_name(model_variant)


def variant_key(
    *,
    root_arib: str,
    product_id: str,
    displacement_type: str,
    model_name: str,
    nickname: str,
    model_year: str,
    model_type_code: str,
    product_no: str,
    color_type: str,
) -> str:
    return key_to_str(
        [
            root_arib,
            product_id,
            displacement_type,
            normalize_text(model_name),
            normalize_text(nickname),
            model_year,
            model_type_code,
            product_no,
            color_type,
        ]
    )


def assembly_key(
    *,
    root_arib: str,
    catalog_no: str,
    fig_no: str,
    fig_branch_no: str,
    illust_no: str,
) -> str:
    return f"{root_arib}:{catalog_no}:{fig_no}:{fig_branch_no}:{illust_no}"


def variant_path(
    *,
    product_name: str,
    displacement: str,
    model_name: str,
    model_year: str,
    color_name: str | None,
    region_label: str | None = None,
) -> list[str]:
    """Catalog path under a region (region is chosen in UI, not stored in path)."""
    _ = region_label
    path = [product_name, displacement, model_name, model_year]
    if color_name:
        path.append(str(color_name))
    return path


def catalog_nav_path(*, product_name: str, displacement: str | None = None, model_name: str | None = None, model_year: str | None = None) -> list[str]:
    path = [product_name]
    if displacement is not None:
        path.append(displacement)
    if model_name is not None:
        path.append(model_name)
    if model_year is not None:
        path.append(str(model_year))
    return path


def assembly_path(variant_path: list[str], fig_name: str, fig_no: str) -> list[str]:
    return [*variant_path, f"{fig_no} · {fig_name}"]
