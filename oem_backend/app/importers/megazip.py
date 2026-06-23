import json
import re
from dataclasses import dataclass
from urllib.parse import urlparse

import httpx
from bs4 import BeautifulSoup

from app.config import get_settings
from app.importers.assets import download_image
from app.importers import writer


MEGAZIP_SAMPLE_URL = (
    "https://www.megazip.ru/zapchasti-dlya-motocyklov/yamaha/"
    "fz10-mtn1000-31042/fz10-mtn1000-46593/mtn1000-839279/"
    "karter-dvigatelya-15735609"
)


@dataclass
class MegazipVariantMeta:
    year: int | None = None
    color_code: str | None = None
    model_code: str | None = None
    region: str | None = None
    region_code: str | None = None
    engine_cc: int | None = None
    color_name: str | None = None
    market_name: str | None = None


def _fetch(url: str) -> str:
    settings = get_settings()
    with httpx.Client(timeout=settings.http_timeout, follow_redirects=True) as client:
        response = client.get(url, headers={"User-Agent": "MotorForceOEMBot/0.1"})
        response.raise_for_status()
        return response.text


def _clean_text(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def _parse_variant_meta(text: str) -> MegazipVariantMeta:
    meta = MegazipVariantMeta()
    if m := re.search(r"Год\s+(\d{4})", text):
        meta.year = int(m.group(1))
    if m := re.search(r"Цвет\s+([A-Z0-9]+)", text):
        meta.color_code = m.group(1)
    if m := re.search(r"Код модели\s+([A-Z0-9]+)", text):
        meta.model_code = m.group(1)
    if m := re.search(r"Регион продаж\s+(.+?)\s*\(([^)]+)\)", text):
        meta.region = _clean_text(m.group(1))
        meta.region_code = _clean_text(m.group(2))
    if m := re.search(r"Объем двигателя\s+(\d+)", text):
        meta.engine_cc = int(m.group(1))
    if m := re.search(r"Вариант окраса\s+(.+?)\s+Модель\s+", text):
        meta.color_name = _clean_text(m.group(1))
    if m := re.search(r"Модель\s+([^\n]+)", text):
        meta.market_name = _clean_text(m.group(1))
    return meta


def _extract_breadcrumbs(soup: BeautifulSoup) -> list[tuple[str, str]]:
    items: list[tuple[str, str]] = []
    for a in soup.select("a[href*='/zapchasti-dlya']"):
        text = _clean_text(a.get_text(" "))
        href = a.get("href") or ""
        if text and href:
            items.append((text, href))
    deduped: list[tuple[str, str]] = []
    seen: set[tuple[str, str]] = set()
    for item in items:
        if item not in seen:
            seen.add(item)
            deduped.append(item)
    return deduped


def _parse_rect_coords(coords: str) -> tuple[float, float, float, float]:
    values = [float(part.strip()) for part in coords.split(",") if part.strip()]
    if len(values) != 4:
        return 0, 0, 0, 0
    x1, y1, x2, y2 = values
    return x1, y1, max(0, x2 - x1), max(0, y2 - y1)


def import_sample() -> dict:
    html = _fetch(MEGAZIP_SAMPLE_URL)
    soup = BeautifulSoup(html, "lxml")
    body_text = soup.get_text("\n")
    meta = _parse_variant_meta(body_text)
    breadcrumbs = _extract_breadcrumbs(soup)

    brand_id = writer.ensure_brand("Yamaha")
    writer.ensure_brand_alias(brand_id, "megazip", "Yamaha")
    model_id = writer.ensure_model_family("motorcycle", brand_id, meta.market_name or "MT-10")
    writer.ensure_model_alias(model_id, "megazip", "FZ10/ MTN1000", reviewed=False)
    writer.ensure_model_alias(model_id, "megazip", "MTN1000", reviewed=False)
    if meta.market_name:
        writer.ensure_model_alias(model_id, "megazip", meta.market_name, reviewed=True)

    variant_id = writer.ensure_variant(
        model_id,
        year_from=meta.year,
        year_to=meta.year,
        model_code=meta.model_code,
        region=meta.region,
        region_code=meta.region_code,
        color_code=meta.color_code,
        color_name=meta.color_name,
        engine_cc=meta.engine_cc,
        market_name=meta.market_name,
        source_designation="MTN1000",
    )

    parent_node_id: int | None = None
    for title, href in breadcrumbs:
        if "/zapchasti-dlya-avtomobilej" in href:
            continue
        path = urlparse(href).path
        node_type = "breadcrumb"
        if path == "/zapchasti-dlya-motocyklov":
            node_type = "vehicle_type"
        elif path.endswith("/yamaha"):
            node_type = "brand"
        elif path.endswith("/fz10-mtn1000-31042/fz10-mtn1000-46593"):
            node_type = "model_family"
        elif path.endswith("/mtn1000-839279"):
            node_type = "variant"
        parent_node_id = writer.ensure_source_node(
            source_code="megazip",
            node_type=node_type,
            title=title,
            external_id=f"megazip:{path}",
            parent_id=parent_node_id,
            source_url=f"https://www.megazip.ru{path}",
            url_path=path,
            raw_payload=html if node_type == "variant" else None,
        )
        if node_type == "variant":
            writer.link_source_node(parent_node_id, "vehicle_variant", variant_id)

    assembly_title = "Картер двигателя"
    assembly_path = urlparse(MEGAZIP_SAMPLE_URL).path
    assembly_node_id = writer.ensure_source_node(
        source_code="megazip",
        node_type="assembly",
        title=assembly_title,
        external_id="megazip:assembly:15735609",
        parent_id=parent_node_id,
        source_url=MEGAZIP_SAMPLE_URL,
        url_path=assembly_path,
        raw_payload=html,
    )
    assembly_id = writer.ensure_assembly(variant_id, assembly_title, assembly_node_id)
    writer.link_source_node(assembly_node_id, "assembly", assembly_id)

    image = soup.select_one("#items_list_image")
    image_url = image.get("src") if image else None
    if image_url and image_url.startswith("//"):
        image_url = "https:" + image_url
    asset = {}
    if image_url:
        try:
            asset = download_image(image_url, "megazip", "Yamaha", assembly_node_id)
        except Exception as exc:
            asset = {"download_error": str(exc)}
    diagram_id = writer.ensure_diagram(
        assembly_id,
        source_node_id=assembly_node_id,
        original_url=image_url,
        local_path=asset.get("local_path"),
        source_image_id="173df35b7f0dc148836d577fa29adaca",
        width=asset.get("width"),
        height=asset.get("height"),
        checksum_sha256=asset.get("checksum_sha256"),
    )

    parts_by_items_list: dict[str, int] = {}
    original_by_original_item: dict[str, int] = {}
    imported_parts = 0
    replacements = 0

    for row in soup.select(".js-items-list-item[data-item]"):
        raw = row.get("data-item")
        if not raw:
            continue
        item = json.loads(raw)
        part_id = writer.ensure_part(
            manufacturer=item.get("manufacturer") or "Yamaha",
            part_number=item.get("number") or "",
            name=item.get("name"),
            brand_id=brand_id,
        )
        row_kind = "replacement" if item.get("item_id") != item.get("original_item_id") else "original"
        assembly_part_id = writer.add_assembly_part(
            assembly_id=assembly_id,
            part_id=part_id,
            ref=item.get("ref"),
            quantity=float(item["quantity"]) if str(item.get("quantity", "")).replace(".", "", 1).isdigit() else None,
            row_kind=row_kind,
            source_node_id=assembly_node_id,
            source_row_id=item.get("item_id"),
            source_items_list_id=item.get("itemslist_id"),
            raw_payload=item,
        )
        imported_parts += 1
        if row_kind == "original":
            original_by_original_item[item.get("original_item_id")] = part_id
            parts_by_items_list[item.get("itemslist_id")] = assembly_part_id
        else:
            original_part_id = original_by_original_item.get(item.get("original_item_id"))
            if original_part_id:
                writer.add_part_relation(original_part_id, part_id, "replacement", "megazip", item)
                replacements += 1

    hotspots = 0
    for area in soup.select("map area[data-items-list-id]"):
        coords = area.get("coords") or ""
        items_list_id = area.get("data-items-list-id")
        x, y, width, height = _parse_rect_coords(coords)
        writer.add_hotspot(
            diagram_id=diagram_id,
            assembly_part_id=parts_by_items_list.get(items_list_id),
            shape=area.get("shape") or "rect",
            raw_coords=coords,
            x=x,
            y=y,
            width=width,
            height=height,
            source_items_list_id=items_list_id,
            raw_payload={"attrs": dict(area.attrs)},
        )
        hotspots += 1

    return {
        "source": "megazip",
        "url": MEGAZIP_SAMPLE_URL,
        "variant_id": variant_id,
        "assembly_id": assembly_id,
        "diagram_id": diagram_id,
        "parts": imported_parts,
        "hotspots": hotspots,
        "replacements": replacements,
        "image_download": "ok" if asset.get("local_path") else asset.get("download_error"),
    }
