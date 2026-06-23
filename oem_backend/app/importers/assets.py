import hashlib
import os
from pathlib import Path
from urllib.parse import urlparse

import httpx
from PIL import Image

from app.config import get_settings


def _extension_from_content_type(content_type: str | None, fallback_url: str) -> str:
    if content_type:
        if "png" in content_type:
            return ".png"
        if "jpeg" in content_type or "jpg" in content_type:
            return ".jpg"
        if "webp" in content_type:
            return ".webp"
        if "gif" in content_type:
            return ".gif"
    suffix = Path(urlparse(fallback_url).path).suffix
    return suffix if suffix else ".img"


def download_image(url: str, source_code: str, brand: str, source_node_id: int) -> dict:
    settings = get_settings()
    with httpx.Client(timeout=settings.http_timeout, follow_redirects=True) as client:
        response = client.get(url, headers={"User-Agent": "MotorForceOEMBot/0.1"})
        response.raise_for_status()
        content = response.content

    checksum = hashlib.sha256(content).hexdigest()
    ext = _extension_from_content_type(response.headers.get("content-type"), url)
    safe_brand = "".join(ch if ch.isalnum() else "_" for ch in brand).strip("_") or "unknown"
    rel_path = Path(source_code) / safe_brand / str(source_node_id) / f"{checksum}{ext}"
    abs_path = Path(settings.asset_root) / rel_path
    abs_path.parent.mkdir(parents=True, exist_ok=True)
    abs_path.write_bytes(content)

    width = None
    height = None
    try:
        with Image.open(abs_path) as img:
            width, height = img.size
    except Exception:
        pass

    return {
        "local_path": os.fspath(rel_path),
        "checksum_sha256": checksum,
        "width": width,
        "height": height,
        "mime_type": response.headers.get("content-type"),
    }
