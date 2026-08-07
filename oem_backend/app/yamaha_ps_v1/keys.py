from __future__ import annotations

import json
import re
from typing import Any

from app.normalization import normalize_text

from .constants import ROOT_ARIB


def key_to_str(key: tuple[Any, ...] | list[Any]) -> str:
    return json.dumps(list(key), ensure_ascii=False, separators=(",", ":"))


def normalize_assembly_slug(slug: str | None) -> str | None:
    if not slug:
        return None
    value = slug.strip().rstrip("/")
    if value.endswith("/y"):
        value = value[:-2]
    return value or None


def assembly_key(*, root_arib: str, aria: str | None, slug: str | None, path: list[str] | None = None) -> str:
    aria_value = (aria or "").strip() or None
    if aria_value:
        return f"{root_arib}:{aria_value}"
    normalized_slug = normalize_assembly_slug(slug)
    if normalized_slug:
        return f"{root_arib}:{normalized_slug}"
    if path:
        return f"{root_arib}:{aria or 'no-aria'}:{'/'.join(path)}"
    return f"{root_arib}:{aria or 'no-aria'}"


def parse_year(path: list[str]) -> int | None:
    for item in path:
        # "2027 Motorcycle" / plain "2027"
        match = re.search(r"\b(19|20)\d{2}\b", item)
        if match:
            return int(match.group(0))
        if re.fullmatch(r"\d{4}", item):
            return int(item)
    return None


def parse_model_title(path: list[str]) -> str:
    model_title = path[-2] if len(path) >= 2 else (path[-1] if path else "")
    model_title = re.sub(r"\s*-\s*\d{4}\s*$", "", model_title)
    model_title = re.sub(r"\s+(CHASSIS|ENGINE|US ENGINE)\s*$", "", model_title, flags=re.I)
    return re.sub(r"\s+", " ", model_title or "").strip() or "Unknown model"


def parse_variant_section(path: list[str]) -> str | None:
    model_title = path[-2] if len(path) >= 2 else ""
    if re.search(r"\bENGINE\b", model_title, re.I):
        return "engine"
    if re.search(r"\bCHASSIS\b", model_title, re.I):
        return "chassis"
    return None


def variant_key_from_path(root_arib: str, path: list[str]) -> str:
    if not path:
        return key_to_str([root_arib])
    prefix = [normalize_text(part) for part in path[:-1]]
    return key_to_str([root_arib, *prefix])


def variant_fields(root_arib: str, path: list[str]) -> dict[str, Any]:
    variant_path = path[:-1] if len(path) >= 2 else path
    model_name = parse_model_title(path)
    source_designation = path[-2] if len(path) >= 2 else model_name
    # browse_line = PowerSport / Marine top label
    browse_line = variant_path[0] if variant_path else None
    return {
        "root_arib": root_arib or ROOT_ARIB,
        "variant_key": variant_key_from_path(root_arib or ROOT_ARIB, path),
        "model_name": model_name,
        "source_designation": source_designation,
        "year_from": parse_year(path),
        "variant_section": parse_variant_section(path),
        "browse_line": browse_line,
        "path_json": variant_path,
    }
