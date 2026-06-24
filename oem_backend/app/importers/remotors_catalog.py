"""Shared Remotors/ARI catalog classification (crawler + DB fix scripts)."""

import re
from typing import Any

DEFAULT_BRANDS = ["HUM", "KTM", "LNX", "BRP"]
DEPRECATED_ARIB_CODES = frozenset({"BRP_SEA", "BRP_SKI"})
HIDDEN_CANONICAL_BRANDS = frozenset({"brp", "brp_sea", "brp_ski"})

BRAND_NAMES = {
    "HUM": "Husqvarna",
    "KTM": "KTM",
    "LNX": "Lynx",
    "BRP_SEA": "Sea-Doo",
    "BRP_SKI": "Ski-Doo",
    "BRP": "BRP",
}

BRAND_VEHICLE_TYPE_HINTS = {
    "LNX": "snowmobile",
    "BRP_SEA": "jetski",
    "BRP_SKI": "snowmobile",
}

# BRP umbrella — exact Remotors browse categories (see remotors.fi BRP catalog).
BRP_CATEGORY_RULES: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"can-am\s*3[-\s]?wheel", re.I), "Can-Am", "motorcycle"),
    (re.compile(r"can-am\s*motorcycle", re.I), "Can-Am", "motorcycle"),
    (re.compile(r"can-am\s*atv", re.I), "Can-Am", "atv"),
    (re.compile(r"can-am\s*sxs", re.I), "Can-Am", "ssv"),
    (re.compile(r"sea-doo\s*boat", re.I), "Sea-Doo", "jetski"),
    (re.compile(r"sea-doo\s*pontoon", re.I), "Sea-Doo", "jetski"),
    (re.compile(r"sea-doo\s*watercraft", re.I), "Sea-Doo", "jetski"),
    (re.compile(r"^ski[-\s]?doo$", re.I), "Ski-Doo", "snowmobile"),
    (re.compile(r"ski[-\s]?doo", re.I), "Ski-Doo", "snowmobile"),
    (re.compile(r"sea-doo", re.I), "Sea-Doo", "jetski"),
    (re.compile(r"can-am", re.I), "Can-Am", "atv"),
]

# Product-line heuristics when ARI slug lacks explicit "Can-Am ATV" folder names.
BRP_PRODUCT_LINE_RULES: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"\b(ryker|spyder|can-am\s*on-road)\b", re.I), "Can-Am", "motorcycle"),
    (re.compile(r"\b(maverick|commander|defender|traxter)\b", re.I), "Can-Am", "ssv"),
    (re.compile(r"\b(outlander|renegade|ds\s*\d+|ds\d+|can-am\s*atv)\b", re.I), "Can-Am", "atv"),
    (re.compile(r"\b(mxz|summit|expedition|skandic|grand touring|backcountry|tundra)\b", re.I), "Ski-Doo", "snowmobile"),
    (re.compile(r"\b(spark|gtx|rxt|wake|fish pro|sea-doo|switch|explorer)\b", re.I), "Sea-Doo", "jetski"),
]

SNOWMOBILE_BRAND_CODES = frozenset({"LNX", "BRP_SKI"})


def _clean_text(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def path_from_slug(slug: str | None) -> list[str]:
    if not slug:
        return []
    parts = [p for p in slug.strip("/").split("/") if p]
    return [_clean_text(part.replace("_", " ")) for part in parts]


def brp_category_from_text(text: str) -> tuple[str, str] | None:
    for pattern, brand_name, vehicle_type in BRP_CATEGORY_RULES:
        if pattern.search(text):
            return brand_name, vehicle_type
    for pattern, brand_name, vehicle_type in BRP_PRODUCT_LINE_RULES:
        if pattern.search(text):
            return brand_name, vehicle_type
    return None


def brp_category_from_path(path: list[str]) -> tuple[str, str] | None:
    joined = " / ".join(path)
    match = brp_category_from_text(joined)
    if match:
        return match
    for item in path:
        match = brp_category_from_text(item)
        if match:
            return match
    return None


def _has_word_atv(text: str) -> bool:
    return bool(re.search(r"\batv\b", text, re.I))


def _path_suggests_snowmobile(path: list[str]) -> bool:
    joined = " ".join(path).lower()
    return any(token in joined for token in ("ski-doo", "ski doo", "snowmobile", "skandic", "lynx", "expedition", "summit", "renegade"))


def _path_suggests_jetski(path: list[str]) -> bool:
    joined = " ".join(path).lower()
    return any(token in joined for token in ("sea-doo", "sea doo", "watercraft", "spark", "gtx", "rxt"))


def vehicle_type_for(arib: str, path: list[str]) -> str:
    if arib in BRAND_VEHICLE_TYPE_HINTS:
        return BRAND_VEHICLE_TYPE_HINTS[arib]

    if arib == "BRP":
        brp = brp_category_from_path(path)
        if brp:
            return brp[1]

    joined = " ".join(path).lower()
    if "sxs" in joined or "side-by-side" in joined:
        return "ssv"

    if arib in SNOWMOBILE_BRAND_CODES or _path_suggests_snowmobile(path):
        return "snowmobile"

    if _path_suggests_jetski(path):
        return "jetski"

    if _has_word_atv(joined):
        return "atv"

    return "motorcycle"


def canonical_brand_and_type(arib: str, path: list[str], *, extra_text: str = "") -> tuple[str, str]:
    merged = " / ".join(path)
    if extra_text:
        merged = f"{extra_text} / {merged}" if merged else extra_text

    if arib in {"BRP", "BRP_SEA", "BRP_SKI"}:
        brp = brp_category_from_text(merged)
        if brp:
            return brp

    if arib == "BRP":
        brp = brp_category_from_path(path)
        if brp:
            return brp

    brand_name = BRAND_NAMES.get(arib, arib)
    if arib == "BRP_SEA":
        brand_name = "Sea-Doo"
    elif arib == "BRP_SKI":
        brand_name = "Ski-Doo"

    return brand_name, vehicle_type_for(arib, path)


def classify_source(
    arib: str | None,
    path: list[str] | None = None,
    slug: str | None = None,
    *,
    extra_text: str = "",
) -> tuple[str, str]:
    code = (arib or "").strip().upper()
    merged_path = list(path or [])
    if slug:
        slug_path = path_from_slug(slug)
        if slug_path and (not merged_path or len(slug_path) > len(merged_path)):
            merged_path = slug_path
    return canonical_brand_and_type(code or "BRP", merged_path, extra_text=extra_text)


def crawl_arib_for_brand(normalized_brand: str | None, raw_arib: str | None = None) -> str:
    """Map canonical/hidden brands to ARI crawl code. Never BRP_SEA/BRP_SKI."""
    mapped = _brand_to_arib(normalized_brand or "")
    if mapped:
        return mapped
    code = (raw_arib or "").strip().upper()
    if code in DEPRECATED_ARIB_CODES:
        return "BRP"
    if code in {"HUM", "KTM", "LNX", "BRP"}:
        return code
    return "BRP"


def _brand_to_arib(normalized_brand: str) -> str:
    mapping = {
        "husqvarna": "HUM",
        "ktm": "KTM",
        "lynx": "LNX",
        "can-am": "BRP",
        "sea-doo": "BRP",
        "ski-doo": "BRP",
        "brp": "BRP",
        "brp_sea": "BRP",
        "brp_ski": "BRP",
    }
    return mapping.get(normalized_brand, "")


def assembly_external_id(*, arib: str, aria: str | None, slug: str | None, path: list[str] | None = None) -> str:
    if slug:
        return f"{arib}:{slug}"
    if path:
        return f"{arib}:{aria or 'no-aria'}:{'/'.join(path)}"
    return f"{arib}:{aria or 'no-aria'}"


def filter_brand_codes(codes: list[str], *, explicit: bool) -> list[str]:
    normalized = [code.strip().upper() for code in codes if code.strip()]
    if explicit:
        return normalized
    return [code for code in normalized if code not in DEPRECATED_ARIB_CODES]


def variant_section_label(section: str | None) -> str | None:
    if not section:
        return None
    mapping = {"chassis": "Шасси", "engine": "Двигатель"}
    return mapping.get(section.lower(), section)
