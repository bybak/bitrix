from __future__ import annotations

from typing import Any

# YMH-JP/EU typical hotspot ~21px on 661px-wide diagrams.
_REF_MIN_SIDE = 661.0
_REF_HOTSPOT_SIZE = 21.0
_MIN_HOTSPOT_SIZE = 48.0


def _hotspot_size(*, coord_width: float, coord_height: float) -> float:
    ref = max(min(coord_width, coord_height), 1.0)
    return max(_MIN_HOTSPOT_SIZE, ref * (_REF_HOTSPOT_SIZE / _REF_MIN_SIDE))


def _resolve_coord_space(
    *,
    coord_width: float | None,
    coord_height: float | None,
    callouts: dict[str, Any],
) -> tuple[float, float]:
    if coord_width and coord_height and coord_width > 0 and coord_height > 0:
        return float(coord_width), float(coord_height)
    positions = callouts.get("positions") or []
    if positions:
        max_x = max(float(row.get("x") or 0) for row in positions)
        max_y = max(float(row.get("y") or 0) for row in positions)
        return max_x + 200.0, max_y + 200.0
    return _REF_MIN_SIDE, 913.0


def parse_diagram_response(
    data: dict[str, Any],
    *,
    image_id: str | int,
    coord_width: float | None = None,
    coord_height: float | None = None,
) -> dict[str, Any]:
    parts: list[dict[str, Any]] = []
    for row in data.get("items") or []:
        part_no = str(row.get("displayPartNumber") or row.get("formattedPartNumber") or row.get("partNumber") or "").strip()
        if not part_no:
            continue
        ref = str(row.get("label") or "").strip()
        qty_raw = row.get("qty")
        try:
            quantity = float(qty_raw) if qty_raw not in (None, "") else None
        except (TypeError, ValueError):
            quantity = None
        piid = row.get("piid")
        source_row_id = f"{ref}:{part_no}:{piid or ''}"
        parts.append(
            {
                "ref": ref,
                "part_number": part_no,
                "name": str(row.get("name") or "").strip() or None,
                "quantity": quantity,
                "source_row_id": source_row_id,
                "raw_payload": row,
            }
        )

    hotspots: list[dict[str, Any]] = []
    callouts = data.get("callouts") or {}
    cw, ch = _resolve_coord_space(
        coord_width=coord_width,
        coord_height=coord_height,
        callouts=callouts,
    )
    hotspot_size = _hotspot_size(coord_width=cw, coord_height=ch)
    half = hotspot_size / 2.0
    for row in callouts.get("positions") or []:
        ref = str(row.get("label") or "").strip()
        x = float(row.get("x") or 0)
        y = float(row.get("y") or 0)
        hotspots.append(
            {
                "ref": ref,
                "raw_coords": f"{x - half};{y - half};{x + half};{y + half}",
                "x": x - half,
                "y": y - half,
                "width": hotspot_size,
                "height": hotspot_size,
                "raw_payload": row,
            }
        )

    return {
        "parts": parts,
        "hotspots": hotspots,
        "image_url": f"us-api:image/{image_id}",
        "page_name": data.get("pageName"),
        "model_name": data.get("modelName"),
        "notes": [],
    }
