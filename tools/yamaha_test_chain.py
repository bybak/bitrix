#!/usr/bin/env python3
"""Standalone Yamaha YPEC API chain test (stdlib only, no PostgreSQL)."""

from __future__ import annotations

import argparse
import json
import sys
import urllib.error
import urllib.request

API_BASE = "https://parts.yamaha-motor.co.jp/ypec_b2c/services/html5"
LANG_ID = "25"
CATALOG_LANG_ID = "01"

REGIONS = {
    "YMH-JP": "J001",
    "YMH-EU": "7329",
}


def post(endpoint: str, payload: dict) -> dict:
    url = f"{API_BASE}/{endpoint.strip('/')}/"
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "yamaha-test-chain/1.0",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def user_context_fields(ctx: dict) -> dict:
    return {
        "userGroupCode": ctx.get("userGroupCode") or "BTOC",
        "destination": ctx.get("destination") or "",
        "destGroupCode": ctx.get("destGroupCode") or "",
        "domOvsId": ctx.get("domOvsId") or "1",
    }


def model_list_context(ctx: dict) -> dict:
    return {
        **user_context_fields(ctx),
        "useProdCategory": bool(ctx.get("useProdCategory", False)),
        "greyModelSign": bool(ctx.get("greyModelSign", False)),
    }


def probe(
    *,
    root: str,
    product_id: str,
    displacement_type: str,
    model_name: str | None,
    model_year: str | None,
    fig_no: str | None,
) -> dict:
    base_code = REGIONS[root]
    result: dict = {"root": root, "base_code": base_code, "steps": {}}

    product_payload = post("product_list", {"baseCode": base_code, "langId": LANG_ID})
    ctx = product_payload.get("userContext") or {}
    result["steps"]["product_list"] = {
        "destination": ctx.get("destination"),
        "currency": ctx.get("currencyCode"),
        "products": len(product_payload.get("productDataCollection") or []),
    }

    models_payload = post(
        "model_name_list",
        {
            "productId": product_id,
            "displacementType": displacement_type,
            "baseCode": base_code,
            "langId": LANG_ID,
        },
    )
    models = models_payload.get("modelNameDataCollection") or []
    result["steps"]["model_name_list"] = {"count": len(models)}

    chosen = next((m for m in models if (m.get("modelName") or "") == (model_name or "")), models[0] if models else None)
    if not chosen:
        result["error"] = "no models"
        return result

    name = (chosen.get("modelName") or "").strip()
    nick = (chosen.get("nickname") or "").strip()

    years_payload = post(
        "model_year_list",
        {
            "productId": product_id,
            "modelName": name,
            "nickname": nick,
            "baseCode": base_code,
            "langId": LANG_ID,
            **user_context_fields(ctx),
        },
    )
    years = [y for y in (years_payload.get("modelYearDataCollection") or []) if str(y.get("modelYear") or "") not in ("", "ALL")]
    result["steps"]["model_year_list"] = {"count": len(years), "sample": years[:3]}

    year = model_year or (str(years[0]["modelYear"]) if years else None)
    if not year:
        result["error"] = "no years"
        return result

    variants_payload = post(
        "model_list",
        {
            "productId": product_id,
            "calledCode": "1",
            "modelName": name,
            "nickname": nick,
            "modelYear": year,
            "modelNameOb": None,
            "modelTypeCode": None,
            "productNo": None,
            "colorType": None,
            "vinNo": None,
            "prefixNoFromScreen": None,
            "serialNoFromScreen": None,
            "baseCode": base_code,
            "langId": LANG_ID,
            **model_list_context(ctx),
        },
    )
    variants = variants_payload.get("modelDataCollection") or []
    result["steps"]["model_list"] = {"count": len(variants)}
    if not variants:
        result["error"] = "no variants"
        return result

    variant = variants[0]
    catalog_payload = {
        "productId": product_id,
        "modelBaseCode": variant.get("modelBaseCode") or "",
        "modelTypeCode": variant["modelTypeCode"],
        "modelYear": variant["modelYear"],
        "productNo": variant["productNo"],
        "colorType": variant["colorType"],
        "modelName": variant["modelName"],
        "prodCategory": variant["prodCategory"],
        "calledCode": variant.get("calledCode") or "1",
        "vinNoSearch": "false",
        "catalogLangId": CATALOG_LANG_ID,
        "baseCode": base_code,
        "langId": LANG_ID,
        "userGroupCode": ctx.get("userGroupCode") or "BTOC",
        "greyModelSign": bool(ctx.get("greyModelSign", False)),
    }
    index_payload = post("catalog_index", catalog_payload)
    figs = index_payload.get("figDataCollection") or []
    catalog_no = str(index_payload.get("catalogNo") or "")
    result["steps"]["catalog_index"] = {"catalog_no": catalog_no, "fig_count": len(figs)}

    fig = next((f for f in figs if str(f.get("figNo")) == str(fig_no)), figs[0] if figs else None)
    if not fig:
        result["error"] = "no figs"
        return result

    text_payload = {
        "productId": product_id,
        "modelBaseCode": variant.get("modelBaseCode") or "",
        "modelTypeCode": variant["modelTypeCode"],
        "modelYear": variant["modelYear"],
        "productNo": variant["productNo"],
        "colorType": variant["colorType"],
        "modelName": variant["modelName"],
        "vinNoSearch": "false",
        "baseCode": base_code,
        "langId": LANG_ID,
        "userGroupCode": ctx.get("userGroupCode") or "BTOC",
        "figNo": str(fig["figNo"]),
        "figBranchNo": str(fig["figBranchNo"]),
        "catalogNo": catalog_no,
        "illustNo": fig["illustNo"],
        "catalogLangId": CATALOG_LANG_ID,
        "domOvsId": ctx.get("domOvsId") or "1",
        "cataPBaseCode": ctx.get("cataPBaseCode") or "",
        "currencyCode": ctx.get("currencyCode") or "",
    }
    text_payload = post("catalog_text", text_payload)
    parts = text_payload.get("partsDataCollection") or []
    hotspots = text_payload.get("hotspotoDataCollection") or []
    result["steps"]["catalog_text"] = {
        "fig_no": fig.get("figNo"),
        "fig_name": fig.get("figName"),
        "parts": len(parts),
        "hotspots": len(hotspots),
        "sample_part": parts[0] if parts else None,
        "sample_hotspot": hotspots[0] if hotspots else None,
    }
    result["ok"] = True
    return result


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", choices=list(REGIONS.keys()), default="YMH-JP")
    parser.add_argument("--product-id", default="10")
    parser.add_argument("--displacement-type", default="4")
    parser.add_argument("--model-name", default="FZR400RR")
    parser.add_argument("--model-year", default="1990")
    parser.add_argument("--fig-no", default="04")
    args = parser.parse_args()

    try:
        result = probe(
            root=args.root,
            product_id=args.product_id,
            displacement_type=args.displacement_type,
            model_name=args.model_name,
            model_year=args.model_year,
            fig_no=args.fig_no,
        )
    except urllib.error.HTTPError as exc:
        print(json.dumps({"ok": False, "http_status": exc.code, "body": exc.read().decode()[:400]}, ensure_ascii=False, indent=2))
        raise SystemExit(1) from exc

    print(json.dumps(result, ensure_ascii=False, indent=2))
    if not result.get("ok"):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
