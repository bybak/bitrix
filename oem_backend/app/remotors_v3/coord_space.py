"""PartStream hotspot coords use HTML origWidth space, not the PNG pixel size.

coord_width  = origWidth from saved GetDetails HTML
coord_height = origWidth * png_height / png_width  (preserve aspect)
"""

from __future__ import annotations

import re
import struct
from pathlib import Path

ORIG_WIDTH_RE = re.compile(r'origWidth="(\d+(?:\.\d+)?)"')


def png_dimensions(path: Path) -> tuple[int, int] | None:
    if not path.is_file():
        return None
    with path.open("rb") as handle:
        header = handle.read(24)
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    width, height = struct.unpack(">II", header[16:24])
    if width <= 0 or height <= 0:
        return None
    return int(width), int(height)


def read_orig_width_from_html(html: str) -> float | None:
    match = ORIG_WIDTH_RE.search(html or "")
    if not match:
        return None
    value = float(match.group(1))
    return value if value > 0 else None


def read_orig_width(path: Path) -> float | None:
    if not path.is_file():
        return None
    return read_orig_width_from_html(path.read_text(errors="ignore"))


def compute_coord_space(
    *,
    orig_width: float,
    image_width: int,
    image_height: int,
) -> tuple[float, float]:
    if orig_width <= 0 or image_width <= 0 or image_height <= 0:
        raise ValueError("invalid dimensions for coord space")
    coord_height = orig_width * image_height / image_width
    return round(float(orig_width), 4), round(float(coord_height), 4)
