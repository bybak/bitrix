from __future__ import annotations

import html
import json
import re
from typing import Any

from .constants import ROOT_ARIB


def clean_label(value: str | None) -> str:
    text = html.unescape(str(value or "")).strip()
    return re.sub(r"\s+", " ", text)


def key_to_str(key: tuple[Any, ...] | list[Any]) -> str:
    return json.dumps(list(key), ensure_ascii=False, separators=(",", ":"))


def variant_key(
    *,
    product_slug: str,
    top_id: str,
    model_id: str,
    category_id: str | None = None,
) -> str:
    parts: list[Any] = [ROOT_ARIB, product_slug, top_id]
    if category_id:
        parts.append(category_id)
    parts.append(model_id)
    return key_to_str(parts)


def assembly_key(*, model_id: str, diagram_id: str, image_id: str | int) -> str:
    return f"{ROOT_ARIB}:{model_id}:{diagram_id}:{image_id}"


def nav_path(
    *,
    product_name: str,
    top_name: str,
    model_name: str | None = None,
    category_name: str | None = None,
) -> list[str]:
    path = [product_name, top_name]
    if category_name:
        path.append(category_name)
    if model_name:
        path.append(model_name)
    return path


def parse_year(name: str | None) -> int | None:
    if not name:
        return None
    match = re.search(r"(19|20)\d{2}", str(name))
    if match:
        return int(match.group(0))
    return None
