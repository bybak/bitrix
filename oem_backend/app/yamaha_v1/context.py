from __future__ import annotations

from typing import Any

from .constants import DEFAULT_CATALOG_LANG_ID, DEFAULT_LANG_ID


def model_year_context(user_context: dict[str, Any]) -> dict[str, Any]:
    return {
        "userGroupCode": user_context.get("userGroupCode") or "BTOC",
        "destination": user_context.get("destination") or "",
        "destGroupCode": user_context.get("destGroupCode") or "",
        "domOvsId": user_context.get("domOvsId") or "1",
    }


def model_list_context(user_context: dict[str, Any]) -> dict[str, Any]:
    return {
        **model_year_context(user_context),
        "useProdCategory": bool(user_context.get("useProdCategory", False)),
        "greyModelSign": bool(user_context.get("greyModelSign", False)),
    }


def catalog_text_context(user_context: dict[str, Any]) -> dict[str, Any]:
    return {
        "domOvsId": user_context.get("domOvsId") or "1",
        "cataPBaseCode": user_context.get("cataPBaseCode") or "",
        "currencyCode": user_context.get("currencyCode") or "",
        "greyModelSign": bool(user_context.get("greyModelSign", False)),
    }


def build_model_year_payload(
    *,
    base_code: str,
    lang_id: str,
    user_context: dict[str, Any],
    product_id: str,
    model_name: str,
    nickname: str = "",
) -> dict[str, Any]:
    return {
        "productId": product_id,
        "modelName": model_name,
        "nickname": nickname or "",
        "baseCode": base_code,
        "langId": lang_id,
        **model_year_context(user_context),
    }


def build_model_list_payload(
    *,
    base_code: str,
    lang_id: str,
    user_context: dict[str, Any],
    product_id: str,
    model_name: str,
    nickname: str,
    model_year: str,
) -> dict[str, Any]:
    return {
        "productId": product_id,
        "calledCode": "1",
        "modelName": model_name,
        "nickname": nickname or "",
        "modelYear": model_year,
        "modelNameOb": None,
        "modelTypeCode": None,
        "productNo": None,
        "colorType": None,
        "vinNo": None,
        "prefixNoFromScreen": None,
        "serialNoFromScreen": None,
        "baseCode": base_code,
        "langId": lang_id,
        **model_list_context(user_context),
    }


def build_catalog_index_payload(
    *,
    base_code: str,
    lang_id: str,
    user_context: dict[str, Any],
    model_variant: dict[str, Any],
    catalog_lang_id: str = DEFAULT_CATALOG_LANG_ID,
) -> dict[str, Any]:
    return {
        "productId": model_variant["productId"],
        "modelBaseCode": model_variant.get("modelBaseCode") or "",
        "modelTypeCode": model_variant["modelTypeCode"],
        "modelYear": model_variant["modelYear"],
        "productNo": model_variant["productNo"],
        "colorType": model_variant["colorType"],
        "modelName": model_variant["modelName"],
        "prodCategory": model_variant["prodCategory"],
        "calledCode": model_variant.get("calledCode") or "1",
        "vinNoSearch": "false",
        "catalogLangId": catalog_lang_id,
        "baseCode": base_code,
        "langId": lang_id,
        "userGroupCode": user_context.get("userGroupCode") or "BTOC",
        "greyModelSign": bool(user_context.get("greyModelSign", False)),
    }


def build_catalog_text_payload(
    *,
    catalog_index_payload: dict[str, Any],
    user_context: dict[str, Any],
    catalog_no: str,
    fig: dict[str, Any],
    catalog_lang_id: str = DEFAULT_CATALOG_LANG_ID,
) -> dict[str, Any]:
    allowed_keys = {
        "productId",
        "modelBaseCode",
        "modelTypeCode",
        "modelYear",
        "productNo",
        "colorType",
        "modelName",
        "vinNoSearch",
        "baseCode",
        "langId",
        "userGroupCode",
    }
    payload = {key: catalog_index_payload[key] for key in allowed_keys if key in catalog_index_payload}
    payload.update(
        {
            "figNo": str(fig["figNo"]),
            "figBranchNo": str(fig["figBranchNo"]),
            "catalogNo": catalog_no,
            "illustNo": fig["illustNo"],
            "catalogLangId": catalog_lang_id,
            **catalog_text_context(user_context),
        }
    )
    return payload


def enrich_model_variant(model_variant: dict[str, Any], *, product_id: str) -> dict[str, Any]:
    enriched = dict(model_variant)
    enriched.setdefault("productId", product_id)
    enriched.setdefault("calledCode", "1")
    enriched.setdefault("vinNoSearch", False)
    return enriched
