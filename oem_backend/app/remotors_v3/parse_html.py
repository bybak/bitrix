from __future__ import annotations

from typing import Any

from bs4 import BeautifulSoup

from app.remotors_v3.client import clean_text
from app.remotors_v3.coord_space import read_orig_width_from_html


def parse_hotspot_coords(raw: str | None) -> tuple[float, float, float, float]:
    values = [float(part) for part in (raw or "").split(";") if part.strip()]
    if len(values) != 4:
        return 0.0, 0.0, 0.0, 0.0
    x1, y1, x2, y2 = values
    return x1, y1, max(0.0, x2 - x1), max(0.0, y2 - y1)


def parse_quantity(row: Any) -> float | None:
    qty_input = row.select_one("input[id^='ariparts_qty']")
    value = qty_input.get("value") if qty_input else None
    try:
        return float(value) if value not in (None, "") else None
    except ValueError:
        return None


def parse_details_html(html: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "lxml")
    parts: list[dict[str, Any]] = []
    for row in soup.select("tr.ariPartInfo"):
        ref = clean_text(row.select_one(".ariPLTag").get_text(" ") if row.select_one(".ariPLTag") else "")
        number = clean_text(row.select_one(".ariPLSku").get_text(" ") if row.select_one(".ariPLSku") else "")
        name = clean_text(row.select_one(".ariPLDesc").get_text(" ") if row.select_one(".ariPLDesc") else "")
        price_text = clean_text(row.select_one(".ariPLPrice").get_text(" ") if row.select_one(".ariPLPrice") else "")
        quantity = parse_quantity(row)
        if not number:
            continue
        parts.append(
            {
                "ref": ref,
                "part_number": number,
                "name": name,
                "price_text": price_text,
                "quantity": quantity,
            }
        )

    hotspots: list[dict[str, Any]] = []
    for hotspot in soup.select(".ariHotSpot"):
        ref = hotspot.get("tag")
        raw_coords = hotspot.get("coords")
        x, y, width, height = parse_hotspot_coords(raw_coords)
        hotspots.append(
            {
                "ref": ref,
                "raw_coords": raw_coords,
                "x": x,
                "y": y,
                "width": width,
                "height": height,
                "raw_payload": {"attrs": dict(hotspot.attrs)},
            }
        )

    image = soup.select_one("#ariparts_image")
    image_url = image.get("src") if image else None
    # Hotspot coords are in PartStream origWidth space (often larger than Max PNG).
    orig_width = read_orig_width_from_html(html)
    return {
        "parts": parts,
        "hotspots": hotspots,
        "image_url": image_url,
        "orig_width": orig_width,
    }
