#!/usr/bin/env python3
"""Recursively compress images into a mirrored folder tree (never grow files).

Usage:
  python3 scripts/compress_images_tree.py /path/to/src /path/to/dst --png-quality 85 --skip-existing
  python3 scripts/compress_images_tree.py SRC DST --png-quality 85 --workers 24 --pngquant-speed 6

Requires: Pillow (`pip install Pillow`)
Optional (better PNG lossless): oxipng or optipng in PATH
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path

try:
    from PIL import Image, ImageOps, UnidentifiedImageError
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Pillow не установлен. Установи: pip install Pillow") from exc

IMAGE_SUFFIXES = {
    ".png",
    ".jpg",
    ".jpeg",
    ".webp",
    ".gif",
    ".bmp",
    ".tif",
    ".tiff",
}


@dataclass
class FileResult:
    src: Path
    dst: Path
    ok: bool
    skipped: bool
    kept_original: bool = False
    src_size: int = 0
    dst_size: int = 0
    error: str = ""


def format_bytes(n: int) -> str:
    value = float(n)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if value < 1024 or unit == "TB":
            if unit == "B":
                return f"{int(value)} {unit}"
            return f"{value:.1f} {unit}"
        value /= 1024
    return f"{n} B"


def format_duration(seconds: float) -> str:
    seconds = max(0, int(seconds))
    minutes, sec = divmod(seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours:02d}:{minutes:02d}:{sec:02d}"
    return f"{minutes:02d}:{sec:02d}"


def collect_images(src_root: Path) -> list[Path]:
    files: list[Path] = []
    for path in src_root.rglob("*"):
        if path.is_file() and path.suffix.lower() in IMAGE_SUFFIXES:
            files.append(path)
    files.sort()
    return files


def _which(name: str) -> str | None:
    return shutil.which(name)


def _optimize_png_lossless_external(path: Path, *, level: int = 2) -> bool:
    """Lossless external PNG optimizers. Returns True if a tool ran successfully."""
    oxipng = _which("oxipng")
    if oxipng:
        proc = subprocess.run(
            [oxipng, "-o", str(max(1, min(6, level))), "--strip", "safe", "--quiet", str(path)],
            check=False,
            capture_output=True,
        )
        return proc.returncode == 0

    optipng = _which("optipng")
    if optipng:
        # optipng -o2 is a reasonable speed/size tradeoff; -o7 is very slow.
        opt_level = 2 if level <= 2 else 3
        proc = subprocess.run(
            [optipng, f"-o{opt_level}", "-quiet", str(path)],
            check=False,
            capture_output=True,
        )
        return proc.returncode == 0
    return False


def _png_lossy_params(quality: int, colors_override: int | None) -> tuple[int, int, int]:
    """Map quality 1..99 → (qmin, qmax, colors). Low end is intentionally harsh."""
    quality = max(1, min(99, int(quality)))
    # Non-linear: quality=1 → 2 colors, 50 → ~48, 90 → ~180, 99 → 256.
    t = quality / 99.0
    colors = max(2, min(256, int(round(2 + (t**1.65) * 254))))
    if colors_override is not None:
        colors = max(2, min(256, int(colors_override)))
    # For very low quality ignore pngquant's "quality floor" so --colors can crush hard.
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
    pngquant_speed: int = 4,
) -> bool:
    """Lossy PNG via pngquant (preferred) or Pillow palette quantize. quality 1..99."""
    qmin, qmax, colors = _png_lossy_params(quality, colors_override)
    speed = max(1, min(11, int(pngquant_speed)))

    pngquant = _which("pngquant")
    if pngquant:
        # One pass: colors + quality. speed 1=slow/best, 11=fast. Default 4 ≈ good tradeoff.
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
        # Retry without quality gate if first pass wrote nothing useful.
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
    img.save(
        dst,
        format="JPEG",
        quality=quality,
        optimize=True,
        progressive=True,
    )


def _save_png(img: Image.Image, dst: Path) -> None:
    # Do NOT convert P/LA → RGBA — that often balloons size (3.4MB → 4.2MB+).
    # Keep original mode; only normalize exotic modes Pillow can't write as PNG.
    if img.mode in {"CMYK", "YCbCr", "LAB", "HSV"}:
        img = img.convert("RGB")
    elif img.mode == "PA":
        img = img.convert("RGBA")
    img.save(
        dst,
        format="PNG",
        optimize=True,
        compress_level=9,
    )


def _save_image(img: Image.Image, dst: Path, *, jpeg_quality: int, webp_quality: int) -> None:
    dst.parent.mkdir(parents=True, exist_ok=True)
    suffix = dst.suffix.lower()
    img = ImageOps.exif_transpose(img)

    if suffix in {".jpg", ".jpeg"}:
        _save_jpeg(img, dst, quality=jpeg_quality)
        return

    if suffix == ".webp":
        # Prefer lossless for diagrams; falls back to quality if mode unsupported.
        try:
            img.save(dst, format="WEBP", lossless=True, method=6)
        except OSError:
            img.save(dst, format="WEBP", quality=webp_quality, method=6)
        return

    if suffix == ".gif":
        img.save(dst, format="GIF", optimize=True, save_all=True)
        return

    if suffix in {".tif", ".tiff"}:
        img.save(dst, format="TIFF", compression="tiff_adobe_deflate")
        return

    if suffix == ".bmp":
        img.save(dst, format="BMP")
        return

    _save_png(img, dst)


def _write_best(src: Path, candidate: Path, dst: Path) -> tuple[int, bool]:
    """Write the smaller of candidate vs original to dst. Never grow vs source."""
    src_size = src.stat().st_size
    cand_size = candidate.stat().st_size if candidate.is_file() else src_size + 1
    dst.parent.mkdir(parents=True, exist_ok=True)
    if cand_size < src_size:
        candidate.replace(dst)
        return cand_size, False
    # Keep original bytes — compression lost / grew.
    if candidate.exists():
        candidate.unlink(missing_ok=True)
    shutil.copy2(src, dst)
    return src_size, True


def compress_one(
    src: Path,
    dst: Path,
    *,
    jpeg_quality: int,
    webp_quality: int,
    png_quality: int,
    png_colors: int | None,
    skip_existing: bool,
    pngquant_speed: int = 4,
    png_try_lossless: bool = False,
) -> FileResult:
    src_size = src.stat().st_size
    if skip_existing and dst.is_file() and dst.stat().st_size > 0:
        return FileResult(
            src=src,
            dst=dst,
            ok=True,
            skipped=True,
            src_size=src_size,
            dst_size=dst.stat().st_size,
        )

    tmp = dst.with_suffix(dst.suffix + ".tmp")
    try:
        if tmp.exists():
            tmp.unlink()

        suffix = src.suffix.lower()

        # PNG path — fast by default: one lossy pass (or one lossless), no triple work.
        if suffix == ".png":
            dst.parent.mkdir(parents=True, exist_ok=True)

            if png_quality < 100:
                lossy_tmp = dst.with_suffix(dst.suffix + ".lossy.tmp")
                if lossy_tmp.exists():
                    lossy_tmp.unlink()
                ok_lossy = _compress_png_lossy(
                    src,
                    lossy_tmp,
                    quality=png_quality,
                    colors_override=png_colors,
                    pngquant_speed=pngquant_speed,
                )
                if ok_lossy and lossy_tmp.is_file():
                    # Good enough — skip oxipng/Pillow (they were the main slowdown).
                    if lossy_tmp.stat().st_size < src_size or not png_try_lossless:
                        dst_size, kept = _write_best(src, lossy_tmp, dst)
                        return FileResult(
                            src=src,
                            dst=dst,
                            ok=True,
                            skipped=False,
                            kept_original=kept,
                            src_size=src_size,
                            dst_size=dst_size,
                        )
                elif lossy_tmp.exists():
                    lossy_tmp.unlink(missing_ok=True)

            # Lossless-only path, or fallback when lossy failed.
            shutil.copy2(src, tmp)
            _optimize_png_lossless_external(tmp, level=2)
            dst_size, kept = _write_best(src, tmp, dst)
            return FileResult(
                src=src,
                dst=dst,
                ok=True,
                skipped=False,
                kept_original=kept,
                src_size=src_size,
                dst_size=dst_size,
            )

        with Image.open(src) as img:
            img.load()
            _save_image(img, tmp, jpeg_quality=jpeg_quality, webp_quality=webp_quality)

        dst_size, kept = _write_best(src, tmp, dst)
        return FileResult(
            src=src,
            dst=dst,
            ok=True,
            skipped=False,
            kept_original=kept,
            src_size=src_size,
            dst_size=dst_size,
        )
    except (OSError, UnidentifiedImageError, ValueError) as exc:
        if tmp.exists():
            tmp.unlink(missing_ok=True)
        return FileResult(
            src=src,
            dst=dst,
            ok=False,
            skipped=False,
            src_size=src_size,
            error=str(exc),
        )


def _clamp_quality(value: int, name: str) -> int:
    if not 1 <= int(value) <= 100:
        raise SystemExit(f"ERROR: {name} должен быть в диапазоне 1..100, сейчас {value}")
    return int(value)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Рекурсивно сжимает картинки, сохраняя структуру папок. Никогда не увеличивает файл.",
    )
    parser.add_argument("src", type=Path, help="Исходная папка")
    parser.add_argument("dst", type=Path, help="Новая папка (куда писать сжатые файлы)")
    parser.add_argument(
        "-q",
        "--quality",
        type=int,
        default=85,
        help="Качество lossy 1..100 для JPEG/WebP (default 85). На PNG не влияет — см. --png-quality.",
    )
    parser.add_argument(
        "--png-quality",
        type=int,
        default=100,
        help=(
            "Качество PNG 1..100 (default 100 = только lossless). "
            "1 ≈ 2 цвета (жёстко), 50 ≈ ~48 цветов, 90 ≈ ~180. Лучше с pngquant."
        ),
    )
    parser.add_argument(
        "--png-colors",
        type=int,
        default=None,
        help="Жёсткий лимит палитры PNG 2..256 (перебивает шкалу --png-quality). Для максимального сжатия: --png-colors 8",
    )
    parser.add_argument(
        "--jpeg-quality",
        type=int,
        default=None,
        help="Переопределить качество только для JPEG (default = --quality)",
    )
    parser.add_argument(
        "--webp-quality",
        type=int,
        default=None,
        help="Переопределить качество только для WebP lossy fallback (default = --quality)",
    )
    default_workers = max(4, min(32, (os.cpu_count() or 4) * 2))
    parser.add_argument(
        "--workers",
        type=int,
        default=default_workers,
        help=f"Параллельные потоки (default {default_workers} = 2×CPU, max 32)",
    )
    parser.add_argument(
        "--pngquant-speed",
        type=int,
        default=4,
        help="pngquant --speed 1..11 (1=медленно/лучше, 11=быстро; default 4)",
    )
    parser.add_argument(
        "--png-try-lossless",
        action="store_true",
        help="После lossy ещё гонять oxipng (медленнее, чуть меньше размер)",
    )
    parser.add_argument(
        "--skip-existing",
        action="store_true",
        help="Пропускать уже существующие файлы в dst",
    )
    parser.add_argument(
        "-v",
        "--verbose",
        action="store_true",
        help="Печатать каждый файл (по умолчанию только progress раз в N файлов)",
    )
    parser.add_argument(
        "--progress-every",
        type=int,
        default=200,
        help="Как часто печатать progress без --verbose (default 200)",
    )
    args = parser.parse_args()
    args.quality = _clamp_quality(args.quality, "--quality")
    args.png_quality = _clamp_quality(args.png_quality, "--png-quality")
    if not 1 <= int(args.pngquant_speed) <= 11:
        raise SystemExit("ERROR: --pngquant-speed должен быть 1..11")
    args.pngquant_speed = int(args.pngquant_speed)
    args.workers = max(1, int(args.workers))
    args.progress_every = max(1, int(args.progress_every))
    if args.png_colors is not None:
        if not 2 <= int(args.png_colors) <= 256:
            raise SystemExit("ERROR: --png-colors должен быть в диапазоне 2..256")
        args.png_colors = int(args.png_colors)
        if args.png_quality >= 100:
            # Implicitly enable lossy when colors are forced.
            args.png_quality = 50
    args.jpeg_quality = _clamp_quality(
        args.jpeg_quality if args.jpeg_quality is not None else args.quality,
        "--jpeg-quality",
    )
    args.webp_quality = _clamp_quality(
        args.webp_quality if args.webp_quality is not None else args.quality,
        "--webp-quality",
    )
    return args


def main() -> int:
    args = parse_args()
    src_root = args.src.expanduser().resolve()
    dst_root = args.dst.expanduser().resolve()

    if not src_root.is_dir():
        print(f"ERROR: исходная папка не найдена: {src_root}", file=sys.stderr)
        return 1
    if src_root == dst_root:
        print("ERROR: src и dst не должны совпадать", file=sys.stderr)
        return 1

    tools = []
    if _which("pngquant"):
        tools.append("pngquant")
    if _which("oxipng"):
        tools.append("oxipng")
    if _which("optipng"):
        tools.append("optipng")
    tools_label = ", ".join(tools) if tools else "только Pillow"
    if args.png_quality >= 100:
        png_mode = "lossless"
        png_colors_label = "-"
    else:
        _, _, mapped_colors = _png_lossy_params(args.png_quality, args.png_colors)
        png_mode = "lossy"
        png_colors_label = str(mapped_colors)

    print(f"[compress] src = {src_root}")
    print(f"[compress] dst = {dst_root}")
    print(
        f"[compress] jpeg/webp quality={args.quality} "
        f"(jpeg={args.jpeg_quality}, webp={args.webp_quality}) | "
        f"png_quality={args.png_quality} ({png_mode}, colors≈{png_colors_label}) | "
        f"pngquant_speed={args.pngquant_speed} | "
        f"workers={args.workers} | tools: {tools_label}"
    )
    if args.png_quality < 100 and not _which("pngquant"):
        print(
            "[compress] WARNING: pngquant не найден — lossy PNG пойдёт через Pillow "
            "(хуже и медленнее). Рекомендуется: brew install pngquant",
            flush=True,
        )
    print("[compress] правило: если сжатие не уменьшило файл — копируется оригинал")
    print("[compress] лог: progress каждые "
          f"{args.progress_every} файлов; -v для каждого файла")
    print("[compress] сканирую файлы...")

    images = collect_images(src_root)
    total = len(images)
    if total == 0:
        print("[compress] картинки не найдены")
        return 0

    print(f"[compress] найдено изображений: {total}")
    dst_root.mkdir(parents=True, exist_ok=True)

    started = time.monotonic()
    done = 0
    ok_count = 0
    keep_count = 0
    skip_count = 0
    err_count = 0
    src_bytes = 0
    dst_bytes = 0
    last_progress_at = started

    def job(src_path: Path) -> FileResult:
        rel = src_path.relative_to(src_root)
        dst_path = dst_root / rel
        return compress_one(
            src_path,
            dst_path,
            jpeg_quality=args.jpeg_quality,
            webp_quality=args.webp_quality,
            png_quality=args.png_quality,
            png_colors=args.png_colors,
            skip_existing=args.skip_existing,
            pngquant_speed=args.pngquant_speed,
            png_try_lossless=args.png_try_lossless,
        )

    def emit_progress(*, force: bool = False) -> None:
        nonlocal last_progress_at
        now = time.monotonic()
        if not force and (done % args.progress_every) != 0 and (now - last_progress_at) < 5.0:
            return
        last_progress_at = now
        elapsed = now - started
        rate = done / elapsed if elapsed > 0 else 0
        remain = (total - done) / rate if rate > 0 else 0
        overall_saved = src_bytes - dst_bytes
        overall_pct = (overall_saved / src_bytes * 100.0) if src_bytes else 0.0
        print(
            f"[progress] {done}/{total} ({done / total * 100:.1f}%) | "
            f"ok={ok_count} keep={keep_count} skip={skip_count} err={err_count} | "
            f"size {format_bytes(src_bytes)} → {format_bytes(dst_bytes)} "
            f"({overall_pct:+.1f}%) | "
            f"rate={rate:.1f}/s elapsed={format_duration(elapsed)} "
            f"eta={format_duration(remain)}",
            flush=True,
        )

    # Keep a bounded in-flight window — submitting 100k+ futures at once wastes RAM.
    in_flight_limit = max(args.workers * 8, args.workers)
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        img_iter = iter(images)
        futures: set = set()

        def fill() -> None:
            while len(futures) < in_flight_limit:
                try:
                    path = next(img_iter)
                except StopIteration:
                    return
                futures.add(pool.submit(job, path))

        fill()
        while futures:
            future = next(as_completed(futures))
            futures.remove(future)
            result = future.result()
            done += 1
            src_bytes += result.src_size
            dst_bytes += result.dst_size if result.ok else 0
            rel = result.src.relative_to(src_root)

            if not result.ok:
                err_count += 1
                print(
                    f"[{done}/{total}] ERROR {rel} ({format_bytes(result.src_size)}): {result.error}",
                    flush=True,
                )
            elif result.skipped:
                skip_count += 1
                if args.verbose:
                    print(
                        f"[{done}/{total}] SKIP  {rel} (exists {format_bytes(result.dst_size)})",
                        flush=True,
                    )
            elif result.kept_original:
                keep_count += 1
                ok_count += 1
                if args.verbose:
                    print(
                        f"[{done}/{total}] KEEP  {rel} {format_bytes(result.src_size)}",
                        flush=True,
                    )
            else:
                ok_count += 1
                if args.verbose:
                    saved = result.src_size - result.dst_size
                    pct = (saved / result.src_size * 100.0) if result.src_size else 0.0
                    print(
                        f"[{done}/{total}] OK    {rel} "
                        f"{format_bytes(result.src_size)} → {format_bytes(result.dst_size)} "
                        f"({pct:+.1f}%)",
                        flush=True,
                    )

            emit_progress(force=(done == total))
            fill()

    elapsed = time.monotonic() - started
    saved = src_bytes - dst_bytes
    pct = (saved / src_bytes * 100.0) if src_bytes else 0.0
    print()
    print("[compress] готово")
    print(f"  файлов:      {total}")
    print(f"  сжато:       {ok_count - keep_count}")
    print(f"  оригинал:    {keep_count} (сжатие не дало выигрыша)")
    print(f"  пропущено:   {skip_count}")
    print(f"  ошибок:      {err_count}")
    print(f"  было:        {format_bytes(src_bytes)}")
    print(f"  стало:       {format_bytes(dst_bytes)}")
    print(f"  экономия:    {format_bytes(max(0, saved))} ({pct:+.1f}%)")
    print(f"  время:       {format_duration(elapsed)}")
    print(f"  результат:   {dst_root}")
    if args.png_quality >= 100 and not _which("oxipng") and not _which("optipng"):
        print("  совет:       brew install oxipng  — лучше жмёт PNG без потери качества")
    if args.png_quality < 100 and not _which("pngquant"):
        print("  совет:       brew install pngquant — нормальный lossy PNG по --png-quality")
    return 1 if err_count else 0



if __name__ == "__main__":
    raise SystemExit(main())
