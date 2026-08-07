from __future__ import annotations

import struct
from typing import Any


def parse_hotspots(catalog_text: dict[str, Any]) -> list[dict[str, Any]]:
    rows = catalog_text.get("hotspotoDataCollection") or catalog_text.get("hotspotDataCollection") or []
    hotspots: list[dict[str, Any]] = []
    for row in rows:
        ref = str(row.get("refNo") or "").strip()
        x1 = float(row.get("startPointX") or 0)
        y1 = float(row.get("startPointY") or 0)
        x2 = float(row.get("endPointX") or 0)
        y2 = float(row.get("endPointY") or 0)
        hotspots.append(
            {
                "ref": ref,
                "raw_coords": f"{x1};{y1};{x2};{y2}",
                "x": x1,
                "y": y1,
                "width": max(0.0, x2 - x1),
                "height": max(0.0, y2 - y1),
                "raw_payload": row,
            }
        )
    return hotspots


def parse_parts(catalog_text: dict[str, Any]) -> list[dict[str, Any]]:
    rows = catalog_text.get("partsDataCollection") or []
    parts: list[dict[str, Any]] = []
    for row in rows:
        part_no = str(row.get("partNo") or "").strip()
        if not part_no:
            continue
        qty_raw = row.get("quantity")
        try:
            quantity = float(qty_raw) if qty_raw not in (None, "") else None
        except (TypeError, ValueError):
            quantity = None
        ref = str(row.get("refNo") or "").strip()
        source_row_id = f"{ref}:{part_no}:{row.get('selectableId') or ''}"
        parts.append(
            {
                "ref": ref,
                "part_number": part_no,
                "name": str(row.get("partName") or "").strip() or None,
                "quantity": quantity,
                "source_row_id": source_row_id,
                "raw_payload": row,
            }
        )
    return parts


def parse_catalog_text(catalog_text: dict[str, Any], *, image_url: str | None = None) -> dict[str, Any]:
    return {
        "parts": parse_parts(catalog_text),
        "hotspots": parse_hotspots(catalog_text),
        "image_url": image_url,
        "notes": catalog_text.get("notesDataCollection") or [],
    }


def png_dimensions(data: bytes) -> tuple[int | None, int | None]:
    if len(data) < 24 or data[:8] != b"\x89PNG\r\n\x1a\n":
        return None, None
    width, height = struct.unpack(">II", data[16:24])
    return int(width), int(height)
