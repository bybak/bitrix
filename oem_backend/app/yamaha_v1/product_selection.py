from __future__ import annotations

from typing import Any

JP_ALLOWED_PRODUCT_NAMES: tuple[str, ...] = (
    "Motorcycles",
    "ATV & SSV & ROV",
    "Snowmobile",
    "Outboard Motor",
    "Water Vehicle",
)

EU_ALLOWED_PRODUCT_NAMES: tuple[str, ...] = (
    "Motorcycles & Scooters",
    "ATV & SSV & ROV",
    "Snowmobile",
    "Outboard Motor",
    "Water Vehicle",
)

REGION_ALLOWED_PRODUCT_NAMES: dict[str, tuple[str, ...]] = {
    "YMH-JP": JP_ALLOWED_PRODUCT_NAMES,
    "YMH-EU": EU_ALLOWED_PRODUCT_NAMES,
}

ProductRow = tuple[str, str]
DisplacementRow = tuple[str, str]


def _product_name(product: dict[str, Any]) -> str:
    return str(product.get("productIdName") or product.get("productId") or "").strip()


def select_products_for_scan(
    *,
    root_arib: str,
    product_payload: dict[str, Any],
    product_id: str | None,
) -> list[ProductRow]:
    products = product_payload.get("productDataCollection") or []
    by_id = {str(p["productId"]): _product_name(p) for p in products}
    allowed_names = REGION_ALLOWED_PRODUCT_NAMES.get(root_arib)

    if product_id and product_id.lower() != "all":
        name = by_id.get(product_id, f"Product {product_id}")
        if allowed_names is not None and name not in allowed_names:
            allowed = ", ".join(allowed_names)
            raise ValueError(
                f"product {product_id} ({name}) is not allowed for {root_arib}; allowed: {allowed}"
            )
        return [(product_id, name)]

    if allowed_names is not None:
        allowed_set = set(allowed_names)
        rows: list[ProductRow] = []
        for product in products:
            name = _product_name(product)
            if name in allowed_set:
                rows.append((str(product["productId"]), name))
        return rows

    return [(str(product["productId"]), _product_name(product)) for product in products]


def select_displacements_for_product(
    *,
    product_payload: dict[str, Any],
    product_id: str,
    displacement_type: str | None,
) -> list[DisplacementRow]:
    rows = [
        d
        for d in product_payload.get("displacementDataCollection") or []
        if str(d.get("productId")) == product_id and str(d.get("displacementType") or "") != "AL"
    ]
    if displacement_type and displacement_type.lower() != "all":
        label = next(
            (str(d.get("displacement") or displacement_type) for d in rows if str(d.get("displacementType")) == displacement_type),
            displacement_type,
        )
        return [(displacement_type, label)]

    return [
        (str(d["displacementType"]), str(d.get("displacement") or d["displacementType"]))
        for d in rows
    ]
