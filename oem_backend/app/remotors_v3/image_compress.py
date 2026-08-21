"""Compress a downloaded diagram before saving (same logic as scripts/compress_images_tree.py).

Defaults match OEM image crawl: --quality 70 --png-quality 1 --png-colors 3.
Original bytes are never kept on disk — only the compressed file is written.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import uuid
from pathlib import Path

from PIL import Image, ImageOps

# Crawl defaults (same as CLI flags on compress_images_tree.py).
DEFAULT_JPEG_QUALITY = 70
DEFAULT_PNG_QUALITY = 1
DEFAULT_PNG_COLORS = 3
DEFAULT_PNGQUANT_SPEED = 4


def _which(name: str) -> str | None:
    return shutil.which(name)


def _png_lossy_params(quality: int, colors_override: int | None) -> tuple[int, int, int]:
    quality = max(1, min(99, int(quality)))
    t = quality / 99.0
    colors = max(2, min(256, int(round(2 + (t**1.65) * 254))))
    if colors_override is not None:
        colors = max(2, min(256, int(colors_override)))
    if quality <= 5 or colors <= 8:
        qmin, qmax = 0, 100
    else:
        qmax = quality
        qmin = max(0, quality - 20)
    return qmin, qmax, colors


def _compress_png_lossy(
    src: Path,
    dst: Path,
    *,
    quality: int,
    colors_override: int | None,
    pngquant_speed: int = DEFAULT_PNGQUANT_SPEED,
) -> bool:
    qmin, qmax, colors = _png_lossy_params(quality, colors_override)
    speed = max(1, min(11, int(pngquant_speed)))

    pngquant = _which("pngquant")
    if pngquant:
        proc = subprocess.run(
            [
                pngquant,
                f"--quality={qmin}-{qmax}",
                f"--colors={colors}",
                f"--speed={speed}",
                "--force",
                "--output",
                str(dst),
                "--",
                str(src),
            ],
            check=False,
            capture_output=True,
        )
        if proc.returncode in {0, 99} and dst.is_file() and dst.stat().st_size > 0:
            return True
        if dst.exists():
            dst.unlink(missing_ok=True)
        proc = subprocess.run(
            [
                pngquant,
                f"--colors={colors}",
                f"--speed={speed}",
                "--force",
                "--output",
                str(dst),
                "--",
                str(src),
            ],
            check=False,
            capture_output=True,
        )
        if proc.returncode == 0 and dst.is_file() and dst.stat().st_size > 0:
            return True
        if dst.exists() and dst.stat().st_size == 0:
            dst.unlink(missing_ok=True)

    # Fallback: Pillow palette quantize.
    # RGBA only supports FASTOCTREE / LIBIMAGEQUANT (MEDIANCUT raises ValueError).
    try:
        with Image.open(src) as img:
            img.load()
            img = ImageOps.exif_transpose(img)
            if img.mode in {"LA", "PA"}:
                img = img.convert("RGBA")
            elif img.mode not in {"RGB", "RGBA", "P"}:
                img = img.convert("RGBA" if "A" in img.getbands() else "RGB")
            has_alpha = img.mode == "RGBA" or (
                img.mode == "P" and "transparency" in img.info
            )
            dither = Image.Dither.NONE if colors <= 16 else Image.Dither.FLOYDSTEINBERG
            if has_alpha:
                base = img.convert("RGBA")
                method = Image.Quantize.FASTOCTREE
            else:
                base = img.convert("RGB")
                method = Image.Quantize.MEDIANCUT
            quantized = base.quantize(colors=colors, method=method, dither=dither)
            dst.parent.mkdir(parents=True, exist_ok=True)
            quantized.save(dst, format="PNG", optimize=True, compress_level=6)
            return dst.is_file() and dst.stat().st_size > 0
    except Exception:
        return False


def _save_jpeg(img: Image.Image, dst: Path, *, quality: int) -> None:
    if img.mode in {"RGBA", "LA"}:
        background = Image.new("RGB", img.size, (255, 255, 255))
        rgba = img.convert("RGBA")
        background.paste(rgba, mask=rgba.split()[-1])
        img = background
    elif img.mode == "P":
        img = img.convert("RGB")
    elif img.mode != "RGB":
        img = img.convert("RGB")
    dst.parent.mkdir(parents=True, exist_ok=True)
    img.save(dst, format="JPEG", quality=quality, optimize=True, progressive=True)


def compress_image_bytes(
    content: bytes,
    dest: Path,
    *,
    ext: str,
    jpeg_quality: int = DEFAULT_JPEG_QUALITY,
    png_quality: int = DEFAULT_PNG_QUALITY,
    png_colors: int = DEFAULT_PNG_COLORS,
    pngquant_speed: int = DEFAULT_PNGQUANT_SPEED,
) -> bytes:
    """Compress downloaded image bytes and write only the compressed file to dest.

    Returns the compressed file bytes (for checksum). Raises on failure.
    """
    suffix = (ext or dest.suffix or ".png").lower()
    if not suffix.startswith("."):
        suffix = f".{suffix}"
    if suffix == ".jpeg":
        suffix = ".jpg"

    dest = dest.with_suffix(suffix)

    with tempfile.TemporaryDirectory(prefix="oem-img-") as tmpdir:
        tmp_root = Path(tmpdir)
        src = tmp_root / f"src{suffix}"
        out = tmp_root / f"out{suffix}"
        src.write_bytes(content)

        if suffix == ".png":
            ok = _compress_png_lossy(
                src,
                out,
                quality=png_quality,
                colors_override=png_colors,
                pngquant_speed=pngquant_speed,
            )
            if not ok or not out.is_file() or out.stat().st_size <= 0:
                # Surface format hint for debugging bad downloads / Pillow limits.
                try:
                    with Image.open(src) as probe:
                        hint = f" mode={probe.mode} size={probe.size[0]}x{probe.size[1]}"
                except Exception as probe_exc:
                    hint = f" open_failed={probe_exc}"
                raise RuntimeError(f"PNG compress failed (pngquant/Pillow){hint}")
        elif suffix in {".jpg", ".jpeg"}:
            with Image.open(src) as img:
                img.load()
                img = ImageOps.exif_transpose(img)
                _save_jpeg(img, out, quality=jpeg_quality)
        else:
            # Unexpected diagram type: still force a re-encode via Pillow when possible.
            with Image.open(src) as img:
                img.load()
                img = ImageOps.exif_transpose(img)
                if suffix == ".webp":
                    out.parent.mkdir(parents=True, exist_ok=True)
                    img.save(out, format="WEBP", quality=jpeg_quality, method=6)
                else:
                    # Normalize to PNG compressed.
                    dest = dest.with_suffix(".png")
                    out = tmp_root / "out.png"
                    src_png = tmp_root / "src.png"
                    img.save(src_png, format="PNG")
                    ok = _compress_png_lossy(
                        src_png,
                        out,
                        quality=png_quality,
                        colors_override=png_colors,
                        pngquant_speed=pngquant_speed,
                    )
                    if not ok:
                        raise RuntimeError(f"compress failed for {suffix}")

        compressed = out.read_bytes()
        if not compressed:
            raise RuntimeError("compressed image is empty")
        dest.parent.mkdir(parents=True, exist_ok=True)
        tmp_dest = dest.parent / f".{dest.name}.{os.getpid()}.{uuid.uuid4().hex[:8]}.tmp"
        tmp_dest.write_bytes(compressed)
        try:
            os.replace(tmp_dest, dest)
        except FileNotFoundError:
            dest.write_bytes(compressed)
            tmp_dest.unlink(missing_ok=True)
        return compressed


def compress_image_file(
    src: Path,
    dest: Path,
    *,
    ext: str,
    jpeg_quality: int = DEFAULT_JPEG_QUALITY,
    png_quality: int = DEFAULT_PNG_QUALITY,
    png_colors: int = DEFAULT_PNG_COLORS,
    pngquant_speed: int = DEFAULT_PNGQUANT_SPEED,
) -> bytes:
    """Compress an on-disk download. PNG uses pngquant only (no Pillow) to avoid OOM."""
    suffix = (ext or dest.suffix or ".png").lower()
    if not suffix.startswith("."):
        suffix = f".{suffix}"
    if suffix == ".jpeg":
        suffix = ".jpg"
    dest = dest.with_suffix(suffix)
    out = src.with_name(f"out{suffix}")
    if suffix == ".png":
        ok = _compress_png_lossy(
            src,
            out,
            quality=png_quality,
            colors_override=png_colors,
            pngquant_speed=pngquant_speed,
        )
        if not ok or not out.is_file() or out.stat().st_size <= 0:
            raise RuntimeError("PNG compress failed (pngquant)")
    elif suffix in {".jpg", ".jpeg"}:
        with Image.open(src) as img:
            img.load()
            img = ImageOps.exif_transpose(img)
            _save_jpeg(img, out, quality=jpeg_quality)
    else:
        raise RuntimeError(f"unsupported image type {suffix}")
    compressed = out.read_bytes()
    if not compressed:
        raise RuntimeError("compressed image is empty")
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp_dest = dest.parent / f".{dest.name}.{os.getpid()}.{uuid.uuid4().hex[:8]}.tmp"
    tmp_dest.write_bytes(compressed)
    try:
        os.replace(tmp_dest, dest)
    except FileNotFoundError:
        dest.write_bytes(compressed)
        tmp_dest.unlink(missing_ok=True)
    return compressed


__all__ = [
    "DEFAULT_JPEG_QUALITY",
    "DEFAULT_PNG_COLORS",
    "DEFAULT_PNG_QUALITY",
    "compress_image_bytes",
    "compress_image_file",
]
