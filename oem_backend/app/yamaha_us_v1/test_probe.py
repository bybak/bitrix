from __future__ import annotations

from typing import Any

from .client import (
    browse_diagrams,
    browse_model_detail,
    browse_models,
    browse_years,
    fetch_diagram,
    get_access_token,
)
from .constants import DEFAULT_TEST_PRODUCT_SLUG, OUTBOARD_SLUG, ROOT_ARIB
from .keys import clean_label


def probe_chain(
    *,
    product_slug: str = DEFAULT_TEST_PRODUCT_SLUG,
    limit_models: int = 1,
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "root_arib": ROOT_ARIB,
        "product_slug": product_slug,
        "ok": False,
        "steps": {},
    }

    try:
        token = get_access_token()
        result["steps"]["auth"] = {"token_prefix": token[:24]}
    except Exception as exc:
        result["steps"]["auth"] = {"error": str(exc)}
        return result

    try:
        years = browse_years(product_slug=product_slug)
        result["steps"]["years"] = {
            "count": len(years),
            "sample": years[:3],
        }
        if not years:
            return result
        top = years[0]
        top_id = str(top["id"])
    except Exception as exc:
        result["steps"]["years"] = {"error": str(exc)}
        return result

    category_id: str | None = None
    if product_slug == OUTBOARD_SLUG:
        from .client import browse_categories

        try:
            categories = browse_categories(top_id=top_id)
            result["steps"]["categories"] = {
                "top_id": top_id,
                "count": len(categories),
                "sample": categories[:3],
            }
            if categories:
                category_id = str(categories[0]["id"])
        except Exception as exc:
            result["steps"]["categories"] = {"error": str(exc)}
            return result

    try:
        models = browse_models(
            product_slug=product_slug,
            top_id=top_id,
            category_id=category_id,
        )
        result["steps"]["models"] = {
            "count": len(models),
            "sample": models[:limit_models],
        }
        if not models:
            result["ok"] = True
            return result
        model = models[0]
        model_id = str(model["id"])
    except Exception as exc:
        result["steps"]["models"] = {"error": str(exc)}
        return result

    try:
        detail = browse_model_detail(product_slug=product_slug, top_id=top_id, model_id=model_id)
        result["steps"]["model_detail"] = detail
    except Exception as exc:
        result["steps"]["model_detail"] = {"error": str(exc)}
        return result

    try:
        diagrams = browse_diagrams(product_slug=product_slug, top_id=top_id, model_id=model_id)
        result["steps"]["diagrams"] = {
            "count": len(diagrams),
            "sample": diagrams[:3],
        }
        if not diagrams:
            result["ok"] = True
            return result

        diagram_row = None
        image_id = None
        for candidate in diagrams:
            ids = candidate.get("availableImageIds") or []
            if not ids:
                continue
            diagram_row = candidate
            image_id = ids[0]
            name = str(candidate.get("name") or "").upper()
            if "TITLE PAGE" not in name:
                break

        if diagram_row is None or image_id is None:
            result["ok"] = True
            return result
        diagram_id = str(diagram_row["id"])
    except Exception as exc:
        result["steps"]["diagrams"] = {"error": str(exc)}
        return result

    try:
        diagram = fetch_diagram(model_id=model_id, image_id=image_id)
        result["steps"]["diagram"] = {
            "diagram_id": diagram_id,
            "image_id": image_id,
            "page_name": diagram.get("pageName"),
            "model_name": clean_label(diagram.get("modelName")),
            "parts": len(diagram.get("items") or []),
            "callouts": len((diagram.get("callouts") or {}).get("positions") or []),
        }
        result["ok"] = True
    except Exception as exc:
        result["steps"]["diagram"] = {"error": str(exc)}

    return result
