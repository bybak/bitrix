#!/usr/bin/env python3
"""
Parallel scraper/downloader for original-site images:
- product main image
- product additional gallery images
- category images (best-effort via breadcrumbs/category pages)

Input: CSV exported ранее (например "Каталог 22-10-2025_17-25-15.csv")
We rely on the column "ЧПУ страницы (slug)" to build a product URL.

Usage example:
  python3 tools/scrape_original_images.py \
    --csv "/path/to/Каталог 22-10-2025_17-25-15.csv" \
    --out "./tools/out-images" \
    --product-url-template "https://example.com/products/{slug}/" \
    --concurrency-pages 20 --concurrency-images 40

If the original site requires auth, pass cookies header:
  --cookie "PHPSESSID=...; other=..."

Outputs:
  - out/products/<id>_<article>/... downloaded images
  - out/categories/<safe_name>/... downloaded images
  - out/report.jsonl (one JSON per product)
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import hashlib
import json
import os
import re
import sys
import time
from dataclasses import dataclass
from html import unescape
from pathlib import Path
from typing import Any, Iterable, Optional
from urllib.parse import urljoin, urlparse

import aiohttp
from bs4 import BeautifulSoup  # type: ignore


CSV_COL_ID = "id"
CSV_COL_ARTICLE = "Артикул *"
CSV_COL_NAME = "Название товара *"
CSV_COL_SECTION = "Раздел товара *"
CSV_COL_SLUG = "ЧПУ страницы (slug)"


def _safe_name(s: str, max_len: int = 140) -> str:
    s = unescape(s).strip()
    s = re.sub(r"\s+", " ", s)
    s = re.sub(r"[^\w\-.() ]+", "_", s, flags=re.U)
    s = s.strip(" ._")
    if not s:
        return "item"
    return s[:max_len]


def _url_is_http(url: str) -> bool:
    try:
        p = urlparse(url)
        return p.scheme in ("http", "https")
    except Exception:
        return False


def _pick_img_urls_from_tag(tag) -> list[str]:
    urls: list[str] = []
    for attr in ("src", "data-src", "data-original", "data-lazy", "data-zoom-image"):
        v = tag.get(attr)
        if isinstance(v, str) and v.strip():
            urls.append(v.strip())
    srcset = tag.get("srcset")
    if isinstance(srcset, str) and srcset.strip():
        # pick the largest candidate
        candidates: list[tuple[str, float]] = []
        for part in srcset.split(","):
            part = part.strip()
            if not part:
                continue
            bits = part.split()
            u = bits[0].strip()
            score = 0.0
            if len(bits) > 1:
                m = re.match(r"(\d+(?:\.\d+)?)(w|x)$", bits[1].strip())
                if m:
                    score = float(m.group(1))
            candidates.append((u, score))
        if candidates:
            candidates.sort(key=lambda x: x[1])
            urls.append(candidates[-1][0])
    return urls


def extract_page_images(html: str, base_url: str) -> dict[str, Any]:
    """
    Best-effort extraction:
    - meta[property=og:image]
    - images in likely product gallery containers
    - breadcrumbs links
    """
    soup = BeautifulSoup(html, "lxml")

    def abs_url(u: str) -> str:
        return urljoin(base_url, u)

    og_image = None
    og = soup.select_one('meta[property="og:image"], meta[name="og:image"]')
    if og and og.get("content"):
        og_image = abs_url(str(og["content"]).strip())

    # Candidate containers for product images (common Bitrix/other themes)
    container_selectors = [
        ".product-item-detail-slider-images-container",
        ".product-item-detail-slider-container",
        ".product-item-detail-slider-block",
        ".product-detail",
        ".product",
        ".card",
        ".catalog-element",
        "[data-entity='gallery']",
        "[data-role='gallery']",
        "[data-gallery]",
    ]
    imgs: list[str] = []

    # Collect from containers first
    for sel in container_selectors:
        for img in soup.select(f"{sel} img"):
            for u in _pick_img_urls_from_tag(img):
                imgs.append(abs_url(u))

    # Fallback: all images, but filter out obvious UI icons/spacers
    if not imgs:
        for img in soup.find_all("img"):
            for u in _pick_img_urls_from_tag(img):
                uu = abs_url(u)
                imgs.append(uu)

    def looks_like_photo(u: str) -> bool:
        pu = urlparse(u)
        path = (pu.path or "").lower()
        if any(x in path for x in ("/bitrix/templates/", "/assets/", "/icons/", "/svg/")):
            return False
        if path.endswith((".svg", ".gif")):
            return False
        return True

    imgs = [u for u in imgs if _url_is_http(u) and looks_like_photo(u)]

    # Deduplicate preserving order
    seen = set()
    dedup: list[str] = []
    for u in imgs:
        if u in seen:
            continue
        seen.add(u)
        dedup.append(u)

    # Breadcrumbs links
    crumbs: list[str] = []
    for a in soup.select(".breadcrumb a, .breadcrumbs a, nav.breadcrumb a, [itemprop='itemListElement'] a"):
        href = a.get("href")
        if isinstance(href, str) and href.strip():
            u = abs_url(href.strip())
            if _url_is_http(u):
                crumbs.append(u)
    # De-dup crumbs
    cseen = set()
    c2: list[str] = []
    for u in crumbs:
        if u in cseen:
            continue
        cseen.add(u)
        c2.append(u)

    return {
        "og_image": og_image,
        "images": dedup,
        "breadcrumbs": c2,
    }


def extract_category_image(html: str, base_url: str) -> Optional[str]:
    soup = BeautifulSoup(html, "lxml")
    def abs_url(u: str) -> str:
        return urljoin(base_url, u)

    og = soup.select_one('meta[property="og:image"], meta[name="og:image"]')
    if og and og.get("content"):
        u = abs_url(str(og["content"]).strip())
        if _url_is_http(u):
            return u

    # Try common category image placements
    for sel in (
        ".catalog-section .bx-catalog-section-title img",
        ".catalog-section img",
        ".section-image img",
        ".category-image img",
        ".category__image img",
        "img.section-image",
    ):
        img = soup.select_one(sel)
        if img:
            for u in _pick_img_urls_from_tag(img):
                uu = abs_url(u)
                if _url_is_http(uu):
                    return uu
    return None


@dataclass(frozen=True)
class ProductRow:
    id: str
    article: str
    name: str
    section: str
    slug: str


def log(msg: str) -> None:
    ts = time.strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", file=sys.stderr, flush=True)


async def fetch_text(session: aiohttp.ClientSession, url: str, sem: asyncio.Semaphore, *, timeout_s: int) -> str:
    async with sem:
        async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as resp:
            resp.raise_for_status()
            return await resp.text(errors="ignore")


async def fetch_bytes(session: aiohttp.ClientSession, url: str, sem: asyncio.Semaphore, *, timeout_s: int) -> bytes:
    async with sem:
        async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as resp:
            resp.raise_for_status()
            return await resp.read()


def _ext_from_url(url: str) -> str:
    path = urlparse(url).path
    _, ext = os.path.splitext(path)
    ext = (ext or "").lower()
    if ext in (".jpg", ".jpeg", ".png", ".webp", ".bmp"):
        return ext
    return ".jpg"


async def download_image(
    session: aiohttp.ClientSession,
    url: str,
    out_path: Path,
    sem: asyncio.Semaphore,
    *,
    timeout_s: int,
    overwrite: bool,
) -> dict[str, Any]:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    if out_path.exists() and not overwrite and out_path.stat().st_size > 0:
        return {"url": url, "path": str(out_path), "skipped": True}
    try:
        data = await fetch_bytes(session, url, sem, timeout_s=timeout_s)
        out_path.write_bytes(data)
        return {"url": url, "path": str(out_path), "bytes": len(data)}
    except Exception as e:
        return {"url": url, "error": repr(e)}


def _hash_short(s: str) -> str:
    return hashlib.sha1(s.encode("utf-8", "ignore")).hexdigest()[:10]


async def process_product(
    session: aiohttp.ClientSession,
    row: ProductRow,
    product_url_template: str,
    out_dir: Path,
    sem_pages: asyncio.Semaphore,
    sem_images: asyncio.Semaphore,
    *,
    timeout_s: int,
    overwrite: bool,
    max_images: int,
    fetch_category_images: bool,
) -> dict[str, Any]:
    product_url = product_url_template.format(slug=row.slug, id=row.id, article=row.article)
    result: dict[str, Any] = {
        "id": row.id,
        "article": row.article,
        "name": row.name,
        "slug": row.slug,
        "section": row.section,
        "product_url": product_url,
        "product_images": [],
        "category_images": [],
        "errors": [],
    }

    try:
        html = await fetch_text(session, product_url, sem_pages, timeout_s=timeout_s)
    except Exception as e:
        result["errors"].append({"stage": "fetch_product_page", "error": repr(e)})
        return result

    extracted = extract_page_images(html, product_url)
    img_urls: list[str] = []
    if extracted.get("og_image"):
        img_urls.append(extracted["og_image"])
    img_urls.extend(extracted.get("images", []))

    # Dedup and limit
    seen = set()
    final_imgs: list[str] = []
    for u in img_urls:
        if u in seen:
            continue
        seen.add(u)
        final_imgs.append(u)
        if len(final_imgs) >= max_images:
            break

    prod_folder = out_dir / "products" / f"{_safe_name(row.id)}_{_safe_name(row.article)}"

    dl_tasks = []
    for i, u in enumerate(final_imgs, start=1):
        ext = _ext_from_url(u)
        fname = f"{i:02d}_{_hash_short(u)}{ext}"
        dl_tasks.append(download_image(
            session,
            u,
            prod_folder / fname,
            sem_images,
            timeout_s=timeout_s,
            overwrite=overwrite,
        ))

    if dl_tasks:
        result["product_images"] = await asyncio.gather(*dl_tasks)

    if fetch_category_images:
        cat_tasks = []
        for crumb_url in extracted.get("breadcrumbs", [])[-6:]:
            # treat breadcrumb links as category candidates
            try:
                cat_html = await fetch_text(session, crumb_url, sem_pages, timeout_s=timeout_s)
                cat_img = extract_category_image(cat_html, crumb_url)
                if not cat_img:
                    continue
                ext = _ext_from_url(cat_img)
                cat_folder = out_dir / "categories" / _safe_name(crumb_url)
                fname = f"category_{_hash_short(cat_img)}{ext}"
                cat_tasks.append(download_image(
                    session,
                    cat_img,
                    cat_folder / fname,
                    sem_images,
                    timeout_s=timeout_s,
                    overwrite=overwrite,
                ))
            except Exception:
                continue
        if cat_tasks:
            result["category_images"] = await asyncio.gather(*cat_tasks)

    return result


def iter_products_from_csv(csv_path: Path, *, encoding: str, delimiter: str) -> Iterable[ProductRow]:
    with csv_path.open("r", encoding=encoding, newline="") as f:
        reader = csv.DictReader(f, delimiter=delimiter)
        if not reader.fieldnames:
            raise SystemExit("CSV: не удалось прочитать заголовки")

        # Normalize header names (strip quotes/spaces)
        fieldnames = [fn.strip().strip("\ufeff") for fn in reader.fieldnames]
        reader.fieldnames = fieldnames

        missing = [c for c in (CSV_COL_ID, CSV_COL_ARTICLE, CSV_COL_NAME, CSV_COL_SECTION, CSV_COL_SLUG) if c not in fieldnames]
        if missing:
            raise SystemExit(f"CSV: не найдены колонки: {missing}. Есть: {fieldnames[:40]}")

        for row in reader:
            slug = (row.get(CSV_COL_SLUG) or "").strip()
            if not slug:
                continue
            yield ProductRow(
                id=(row.get(CSV_COL_ID) or "").strip(),
                article=(row.get(CSV_COL_ARTICLE) or "").strip(),
                name=(row.get(CSV_COL_NAME) or "").strip(),
                section=(row.get(CSV_COL_SECTION) or "").strip(),
                slug=slug,
            )


async def amain(argv: list[str]) -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--csv", required=True, help="Путь к исходному CSV каталога")
    p.add_argument("--out", required=True, help="Директория для скачанных файлов")
    p.add_argument("--product-url-template", required=True, help="Шаблон URL товара, напр. https://site.ru/product/{slug}/")
    p.add_argument("--encoding", default="cp1251", help="Кодировка CSV (обычно cp1251)")
    p.add_argument("--delimiter", default=";", help="Разделитель CSV (обычно ;) ")
    p.add_argument("--concurrency-pages", type=int, default=20, help="Параллелизм запросов страниц")
    p.add_argument("--concurrency-images", type=int, default=40, help="Параллелизм скачивания картинок")
    p.add_argument("--timeout", type=int, default=40, help="Таймаут (сек) на запрос")
    p.add_argument("--max-images", type=int, default=30, help="Максимум картинок на товар (og + gallery)")
    p.add_argument("--overwrite", action="store_true", help="Перезаписывать уже скачанные файлы")
    p.add_argument("--cookie", default="", help="Cookie header для доступа (если нужно)")
    p.add_argument("--user-agent", default="Mozilla/5.0 (compatible; MFImageScraper/1.0)", help="User-Agent")
    p.add_argument("--no-category-images", action="store_true", help="Не пытаться качать картинки категорий")
    p.add_argument("--limit", type=int, default=0, help="Ограничить кол-во товаров (для прогона)")
    args = p.parse_args(argv)

    csv_path = Path(args.csv)
    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)
    report_path = out_dir / "report.jsonl"

    log(f"start: csv={csv_path} out={out_dir}")
    log(f"url_template: {args.product_url_template}")
    log(f"concurrency: pages={args.concurrency_pages} images={args.concurrency_images} timeout={args.timeout}s")
    log(f"opts: max_images={args.max_images} overwrite={bool(args.overwrite)} category_images={not bool(args.no_category_images)}")

    sem_pages = asyncio.Semaphore(max(1, args.concurrency_pages))
    sem_images = asyncio.Semaphore(max(1, args.concurrency_images))

    headers = {
        "User-Agent": args.user_agent,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    }
    if args.cookie.strip():
        headers["Cookie"] = args.cookie.strip()

    connector = aiohttp.TCPConnector(limit=0, ssl=False)
    timeout_s = int(args.timeout)

    products = list(iter_products_from_csv(csv_path, encoding=args.encoding, delimiter=args.delimiter))
    if args.limit and args.limit > 0:
        products = products[: args.limit]
    log(f"csv rows (with slug): {len(products)}")

    # Simple resume: read report and skip already processed products with a product_url
    done_urls: set[str] = set()
    if report_path.exists():
        try:
            for line in report_path.read_text("utf-8", errors="ignore").splitlines():
                if not line.strip():
                    continue
                obj = json.loads(line)
                u = obj.get("product_url")
                if isinstance(u, str) and u:
                    done_urls.add(u)
        except Exception:
            pass
    if done_urls and not args.overwrite:
        log(f"resume: already_done={len(done_urls)} (from {report_path})")

    async with aiohttp.ClientSession(headers=headers, connector=connector) as session:
        tasks = []

        async def run_one(row: ProductRow) -> dict[str, Any]:
            return await process_product(
                session,
                row,
                args.product_url_template,
                out_dir,
                sem_pages,
                sem_images,
                timeout_s=timeout_s,
                overwrite=bool(args.overwrite),
                max_images=int(args.max_images),
                fetch_category_images=not bool(args.no_category_images),
            )

        # schedule with bounded concurrency on pages/images semaphores inside
        for row in products:
            url = args.product_url_template.format(slug=row.slug, id=row.id, article=row.article)
            if url in done_urls and not args.overwrite:
                continue
            tasks.append(asyncio.create_task(run_one(row)))

        processed = 0
        ok = 0
        failed = 0
        with report_path.open("a", encoding="utf-8") as rep:
            for fut in asyncio.as_completed(tasks):
                res = await fut
                processed += 1
                if not res.get("errors"):
                    ok += 1
                else:
                    failed += 1
                rep.write(json.dumps(res, ensure_ascii=False) + "\n")
                rep.flush()
                if processed % 50 == 0:
                    log(f"progress: {processed}/{len(tasks)} ok={ok} failed={failed}")

    log(f"done: processed={processed} ok={ok} failed={failed} out={out_dir}")
    return 0


def main() -> int:
    try:
        return asyncio.run(amain(sys.argv[1:]))
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())

