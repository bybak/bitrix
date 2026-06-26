"""Shared Remotors/ARI catalog classification (crawler + DB fix scripts)."""

import re
from typing import Any

from app.normalization import normalize_text

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

# Remotors mis-tags (e.g. Husqvarna NORDEN 901 EXPEDITION → snowmobile via "Expedition" heuristic).
EXCLUDED_CATALOG_BRAND_TYPES: frozenset[tuple[str, str]] = frozenset({("husqvarna", "snowmobile")})


def is_excluded_catalog_brand_type(brand_normalized: str | None, vehicle_type: str | None) -> bool:
    if not brand_normalized or not vehicle_type:
        return False
    return (normalize_text(brand_normalized), normalize_text(vehicle_type)) in EXCLUDED_CATALOG_BRAND_TYPES


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


def normalize_assembly_slug(slug: str | None) -> str | None:
    """Collapse API slug encoding variants that refer to the same assembly node."""
    if not slug:
        return None
    from urllib.parse import unquote

    normalized = unquote(slug)
    normalized = re.sub(r"__(?=-)", "_", normalized)
    normalized = re.sub(r"_+", "_", normalized)
    return normalized


def canonical_assembly_arib(arib: str | None) -> str | None:
    """Map legacy BRP sub-codes to the unified crawl/compare code."""
    if not arib:
        return None
    code = arib.strip().upper()
    if code in DEPRECATED_ARIB_CODES:
        return "BRP"
    return code


def assembly_external_id(*, arib: str, aria: str | None, slug: str | None, path: list[str] | None = None) -> str:
    """Canonical assembly key used by crawler, audit, and import skip checks."""
    canon_arib = canonical_assembly_arib(arib) or arib
    if canon_arib and aria:
        return f"{canon_arib}:{aria}"
    normalized_slug = normalize_assembly_slug(slug)
    if canon_arib and normalized_slug:
        return f"{canon_arib}:{normalized_slug}"
    if path:
        return f"{canon_arib}:{aria or 'no-aria'}:{'/'.join(path)}"
    return f"{canon_arib}:{aria or 'no-aria'}"


def assembly_compare_key(
    *,
    arib: str | None,
    aria: str | None = None,
    slug: str | None = None,
    path: list[str] | None = None,
    external_id: str | None = None,
) -> str | None:
    """Stable key for local DB vs Remotors API diff (prefers arib:aria, then normalized slug)."""
    canon_arib = canonical_assembly_arib(arib)
    aria_value = (aria or "").strip() or None

    if canon_arib and aria_value:
        return f"{canon_arib}:{aria_value}"
    normalized_slug = normalize_assembly_slug(slug)
    if canon_arib and normalized_slug:
        return f"{canon_arib}:{normalized_slug}"
    if external_id and ":" in external_id:
        ext_arib, ext_rest = external_id.split(":", 1)
        canon_ext = canonical_assembly_arib(ext_arib) or ext_arib
        if ext_rest and not ext_rest.startswith("/"):
            return f"{canon_ext}:{ext_rest}"
        if canon_arib:
            normalized_ext = normalize_assembly_slug(ext_rest)
            if normalized_ext:
                return f"{canon_arib}:{normalized_ext}"
    elif external_id:
        return external_id
    if canon_arib and path:
        return assembly_external_id(arib=canon_arib, aria=aria_value, slug=slug, path=path)
    return None


def assembly_match_token(
    *,
    arib: str | None = None,
    aria: str | None = None,
    slug: str | None = None,
    path: list[str] | None = None,
    compare_key: str | None = None,
) -> str | None:
    """Brand-agnostic match token for snapshot vs local diff.

    Remotors reuses the same aria across variants and crawls may store a mismatched
    arib prefix (e.g. KTM:aria on a Husqvarna variant). Prefer aria, then slug.
    """
    aria_value = (aria or "").strip() or None
    if aria_value:
        return f"aria:{aria_value}"
    key = compare_key or assembly_compare_key(arib=arib, aria=aria, slug=slug, path=path)
    if not key or ":" not in key:
        return key
    _arib, rest = key.split(":", 1)
    if rest.startswith("/"):
        normalized = normalize_assembly_slug(rest) or rest
        return f"slug:{normalized}"
    normalized = normalize_assembly_slug(rest) or rest
    return f"slug:{normalized}"


def best_assembly_compare_key(candidates: list[str | None]) -> str | None:
    """Pick the strongest compare key when several source-node aliases exist."""

    def _rank(key: str) -> tuple[int, int]:
        _arib, rest = key.split(":", 1)
        prefers_aria = bool(rest) and not rest.startswith("/")
        return (0 if prefers_aria else 1, len(key))

    keys = [key for key in candidates if key]
    if not keys:
        return None
    return min(keys, key=_rank)


def filter_brand_codes(codes: list[str], *, explicit: bool) -> list[str]:
    normalized = [code.strip().upper() for code in codes if code.strip()]
    if explicit:
        return normalized
    return [code for code in normalized if code not in DEPRECATED_ARIB_CODES]


def snapshot_model_key(*, vehicle_type: str, model_name: str | None) -> str:
    """Model key compatible with Remotors snapshot catalog_models."""
    from app.importers.remotors_snapshot import key_to_str

    return key_to_str((vehicle_type, normalize_text(model_name or "")))


def snapshot_variant_key(
    *,
    vehicle_type: str,
    market_name: str | None,
    source_designation: str | None,
    year_from: int | None,
    variant_section: str | None,
) -> str:
    """Variant key compatible with Remotors snapshot catalog_variants."""
    from app.importers.remotors_snapshot import key_to_str

    model_name = normalize_text(market_name or source_designation or "")
    return key_to_str(
        (
            vehicle_type,
            model_name,
            year_from,
            normalize_text(source_designation or ""),
            normalize_text(variant_section or ""),
        )
    )


def variant_section_label(section: str | None) -> str | None:
    if not section:
        return None
    mapping = {"chassis": "Шасси", "engine": "Двигатель"}
    return mapping.get(section.lower(), section)
