#!/usr/bin/env python3
"""
Sync product (and optionally section) images from motor-force.ru to local Bitrix, driven by the imported CSV.

Why CSV-driven:
- CSV import deduplicates rows by normalized article and keeps the "best" row.
- Other rows become redirect/duplicate elements; their images should be attached to the canonical product.

This script:
- reads the original CSV
- groups rows by normalized article (same normalization/scoring logic as mf_import_catalog_csv.php)
- exports local catalog from /tools/mf_catalog_export.php to map MF_ARTICLE_NORM -> local canonical CODE
- for each article group, tries to fetch motor-force product pages by CSV slug(s) and aggregates images
- downloads images into /upload/mf_sync/products/<canonical_code>/...
- posts updates to /tools/mf_catalog_update_images.php (PREVIEW/DETAIL + MORE_PHOTO)

Run example:
  python3 tools/sync_motor_force_images_to_bitrix_from_csv.py \
    --csv "/Users/andrey/cursor_projects/bitrix/www/Каталог 22-10-2025_17-25-15.csv" \
    --local-base http://localhost \
    --iblock-id 4 \
    --docroot "/Users/andrey/cursor_projects/bitrix/www/www" \
    --concurrency-pages 20 \
    --concurrency-images 40
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import json
import os
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional
from urllib.parse import quote_plus, urljoin, urlparse

import aiohttp
from bs4 import BeautifulSoup  # type: ignore


CSV_DELIM = ";"
CSV_COL_ID = "id"
CSV_COL_ARTICLE = "Артикул *"
CSV_COL_NAME = "Название товара *"
CSV_COL_SECTION = "Раздел товара *"
CSV_COL_SLUG = "ЧПУ страницы (slug)"
CSV_COL_BRAND = "Бренд"
CSV_COL_BRAND_NORM = "Бренд (норм)"
CSV_COL_PRICE = "Стоимость товара *"
CSV_COL_ACTIVE = "Показывать на сайте *"
CSV_COL_AVAIL = "Товар в наличии"
CSV_COL_PREVIEW = "Краткий текст"
CSV_COL_DETAIL = "Текст полностью"
CSV_COL_TITLE = "Заголовок страницы (title)"
CSV_COL_DESC = "Описание страницы (description)"
CSV_COL_KEYS = "Ключевые слова страницы (keywords)"


REMOTE_BASE_DEFAULT = "https://motor-force.ru"
REMOTE_PRODUCT_TMPL_DEFAULT = "https://motor-force.ru/products/{slug}"
REMOTE_SEARCH_PATH = "/products/search"


def log(msg: str) -> None:
    ts = time.strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", file=sys.stderr, flush=True)

def pct(done: int, total: int) -> float:
    if total <= 0:
        return 0.0
    p = (float(done) / float(total)) * 100.0
    if p < 0:
        p = 0.0
    if p > 100:
        p = 100.0
    return p


def to_utf8(s: str) -> str:
    return s if isinstance(s, str) else str(s or "")


def norm_article(s: str) -> str:
    s = (s or "").strip().upper()
    s = re.sub(r"[^A-Z0-9]+", "", s)
    return s


def norm_brand(s: str) -> str:
    s = (s or "").strip().upper()
    s = s.replace("Ё", "Е")
    s = re.sub(r"[^A-ZА-Я0-9]+", "", s, flags=re.U)
    return s


def make_uniq_key(article_norm: str, brand_norm: str) -> str:
    a = (article_norm or "").strip()
    b = (brand_norm or "").strip() or "UNKNOWNBRAND"
    return f"{a}_{b}"


def format_article_human(s: str) -> str:
    s = (s or "").strip().upper()
    s = re.sub(r"[^A-Z0-9]+", "-", s)
    s = re.sub(r"-+", "-", s).strip("-")
    return s


def slugify_fallback(s: str) -> str:
    # Fallback only; CSV normally has slug already.
    s = (s or "").strip().lower()
    s = s.replace("ё", "е")
    s = re.sub(r"\s+", "-", s)
    s = re.sub(r"[^a-z0-9\-]+", "", s)
    s = re.sub(r"-+", "-", s).strip("-")
    return s


def score_row(row: dict[str, str]) -> tuple[int, int]:
    score = 0
    detail = (row.get(CSV_COL_DETAIL) or "")
    preview = (row.get(CSV_COL_PREVIEW) or "")
    title = (row.get(CSV_COL_TITLE) or "")
    desc = (row.get(CSV_COL_DESC) or "")
    keys = (row.get(CSV_COL_KEYS) or "")
    slug = (row.get(CSV_COL_SLUG) or "")
    section = (row.get(CSV_COL_SECTION) or "")

    price_raw = (row.get(CSV_COL_PRICE) or "0").replace(",", ".")
    try:
        price = float(price_raw)
    except Exception:
        price = 0.0

    active = str(row.get(CSV_COL_ACTIVE) or "0").strip() == "1"
    avail = str(row.get(CSV_COL_AVAIL) or "0").strip() == "1"

    if detail.strip():
        score += 50
    if preview.strip():
        score += 20
    if title.strip():
        score += 10
    if desc.strip():
        score += 10
    if keys.strip():
        score += 5
    if price > 0:
        score += 10
    if slug.strip():
        score += 5
    if section.strip():
        score += 5
    if active:
        score += 2
    if avail:
        score += 2

    detail_len = len(re.sub(r"<[^>]+>", "", detail))
    return score, detail_len


def extract_brand_from_preview(preview_html: str) -> str:
    s = preview_html or ""
    if not s:
        return ""
    s = re.sub(r"<br\\s*/?>", "\n", s, flags=re.I)
    s = re.sub(r"<[^>]+>", " ", s)
    s = s.replace("\xa0", " ").replace("\t", " ")
    s = re.sub(r"\s+", " ", s, flags=re.U).strip()
    if not s:
        return ""
    labels = [
        "производитель",
        "изготовитель",
        "бренд",
        "марка",
        "фирма",
        "manufacturer",
        "brand",
        "make",
        "vendor",
        "company",
    ]
    label_re = "|".join(re.escape(x) for x in labels)
    m = re.search(
        rf"(?:^|[;,\.\(\)\[\]\s])(?:{label_re})\s*[:\\-—=]?\s*([^;,\.\n\r]{{1,80}})",
        s,
        flags=re.I | re.U,
    )
    if not m:
        return ""
    brand = (m.group(1) or "").strip()
    brand = re.sub(r"\s*\(.*$", "", brand, flags=re.U).strip()
    brand = brand.strip(" \t\r\n\"'`")
    brand = (re.split(r"\s{2,}|\s\|\s|\s/\s", brand, maxsplit=1, flags=re.U)[0] or "").strip()
    return brand


def get_brand_from_row(row: dict[str, str]) -> tuple[str, str]:
    brand = (row.get(CSV_COL_BRAND) or "").strip()
    brand_norm = (row.get(CSV_COL_BRAND_NORM) or "").strip()
    if not brand and not brand_norm:
        brand = extract_brand_from_preview(row.get(CSV_COL_PREVIEW) or "")
        brand_norm = norm_brand(brand)
    if brand_norm:
        brand_norm = norm_brand(brand_norm)
    if not brand:
        brand = "Unknown brand"
    if not brand_norm:
        brand_norm = norm_brand(brand)
    return brand, brand_norm


def abs_url(base: str, u: str) -> str:
    u = (u or "").strip()
    if not u:
        return ""
    if u.startswith("//"):
        return "https:" + u
    return urljoin(base, u)


def is_http_url(u: str) -> bool:
    try:
        p = urlparse(u)
        return p.scheme in ("http", "https")
    except Exception:
        return False


def ext_from_url(u: str) -> str:
    path = urlparse(u).path.lower()
    _, ext = os.path.splitext(path)
    if ext in (".jpg", ".jpeg", ".png", ".webp"):
        return ext
    return ".jpg"

def find_existing_image_rel(docroot: Path, rel_base_no_ext: str) -> Optional[str]:
    """
    If an image file already exists on disk with any supported extension,
    return its relative path (with leading '/').
    rel_base_no_ext example: '/upload/mf_sync/sections/1525'
    """
    exts = (".jpg", ".jpeg", ".png", ".webp")
    base = rel_base_no_ext if rel_base_no_ext.startswith("/") else ("/" + rel_base_no_ext)
    for ext in exts:
        rel = base + ext
        abs_path = docroot / rel.lstrip("/")
        try:
            if abs_path.is_file() and abs_path.stat().st_size > 0:
                return rel
        except Exception:
            continue
    return None

def scan_numbered_images(folder_abs: Path) -> list[str]:
    """
    Return numbered image filenames like 0001.png, 0002.jpg sorted ascending.
    """
    if not folder_abs.is_dir():
        return []
    rx = re.compile(r"^\d{4}\.(?:jpg|jpeg|png|webp)$", re.IGNORECASE)
    try:
        names = [n for n in os.listdir(folder_abs) if rx.match(n)]
    except Exception:
        return []
    names.sort()
    return names

def is_logo_like_url(u: str) -> bool:
    try:
        path = (urlparse(u).path or "").lower()
    except Exception:
        return False
    return "/logo/" in path or path.endswith("/logo") or "/logos/" in path

def siteapi_asset_key(u: str) -> str:
    """
    motor-force images live on i.siteapi.org with transformations in the path.
    Many different URLs can point to the same underlying image asset. We de-dup by
    the asset id after '/img/' (or '/logo/').
    """
    try:
        path = (urlparse(u).path or "")
    except Exception:
        return u
    p = path.lower()
    for marker in ("/img/", "/logo/"):
        idx = p.rfind(marker)
        if idx >= 0:
            tail = path[idx + len(marker) :].strip("/")
            # strip extension if present
            tail = re.sub(r"\.(png|jpe?g|webp)$", "", tail, flags=re.IGNORECASE)
            if tail:
                return marker.strip("/") + ":" + tail.lower()
    return "url:" + p


def siteapi_url_score(u: str) -> int:
    """
    Prefer larger representations when we have multiple URLs for same asset.
    Very rough heuristic based on common 'fit-in/WxH' fragments.
    """
    s = (u or "").lower()
    score = 0
    # common sizes on motor-force pages
    if "fit-in/1024x768" in s:
        score += 1000
    if "fit-in/400x" in s or "/fit-in/400/" in s:
        score += 300
    if "312x240" in s:
        score -= 200
    if "format(png)" in s:
        score += 10
    return score
def extract_remote_article(html: str) -> str:
    # Motor-Force product pages have plain text "Артикул: XXX"
    m = re.search(r"Артикул:\s*([A-Za-z0-9\-_\.]+)", html or "", flags=re.IGNORECASE)
    return (m.group(1) if m else "").strip()


def search_url(remote_base: str, text: str) -> str:
    return remote_base.rstrip("/") + REMOTE_SEARCH_PATH + "?text=" + quote_plus(text)


def parse_search_results_products(html: str, base_url: str) -> list[str]:
    """
    Extract product URLs from /products/search results page.
    We intentionally ignore category URLs.
    """
    soup = BeautifulSoup(html, "lxml")
    urls: list[str] = []
    for a in soup.select("a[href]"):
        href = (a.get("href") or "").strip()
        if not href:
            continue
        u = abs_url(base_url, href)
        p = urlparse(u)
        path = p.path.rstrip("/")
        if not path.startswith("/products/"):
            continue
        if "/products/category/" in path:
            continue
        # accept /products/<slug>
        if re.search(r"^/products/[^/]+$", path):
            urls.append(u)
    # de-dup preserve order
    seen = set()
    out: list[str] = []
    for u in urls:
        if u in seen:
            continue
        seen.add(u)
        out.append(u)
    return out


def norm_name(s: str) -> str:
    s = (s or "").strip().lower()
    s = s.replace("ё", "е")
    s = re.sub(r"\s+", " ", s)
    s = re.sub(r"[\"'`’]+", "", s)
    s = re.sub(r"[^a-zа-я0-9 _-]+", "", s)
    s = s.replace("_", " ").replace("-", " ")
    s = re.sub(r"\s+", " ", s).strip()
    return s


def best_src_from_picture(picture: BeautifulSoup, base: str) -> Optional[str]:
    sources = picture.select("source[srcset]")
    candidates: list[str] = []
    for s in sources:
        srcset = (s.get("srcset") or "").strip()
        if not srcset:
            continue
        parts = [p.strip() for p in srcset.split(",") if p.strip()]
        two_x = None
        one_x = None
        for part in parts:
            bits = part.split()
            if not bits:
                continue
            u = abs_url(base, bits[0])
            if len(bits) >= 2 and bits[1].strip() == "2x":
                two_x = u
            elif len(bits) >= 2 and bits[1].strip() == "1x":
                one_x = u
        if two_x:
            candidates.append(two_x)
        elif one_x:
            candidates.append(one_x)
    if candidates:
        return candidates[0]
    img = picture.select_one("img")
    if not img:
        return None
    for attr in ("data-src", "src"):
        v = img.get(attr)
        if isinstance(v, str) and v.strip():
            u = abs_url(base, v.strip())
            if is_http_url(u):
                return u
    return None


@dataclass(frozen=True)
class LocalSection:
    id: int
    name: str
    code: str
    parent_id: int
    depth: int
    section_page_url: str


@dataclass(frozen=True)
class LocalElement:
    id: int
    name: str
    code: str
    xml_id: str
    section_id: int
    article: str
    article_norm: str
    brand_norm: str
    uniq_key: str
    detail_page_url: str


@dataclass(frozen=True)
class RemoteCategory:
    name: str
    href: str
    image: Optional[str]


def parse_remote_categories(html: str, remote_base: str) -> list[RemoteCategory]:
    soup = BeautifulSoup(html, "lxml")
    out: list[RemoteCategory] = []
    for item in soup.select(".category-item"):
        link = item.select_one(".category-item__link a") or item.select_one("a[href*='/products/category/']")
        if not link:
            continue
        name = (link.get_text(" ", strip=True) or "").strip()
        href = abs_url(remote_base, (link.get("href") or "").strip())
        if not name or not href:
            continue
        picture = item.select_one("picture")
        img = best_src_from_picture(picture, remote_base) if picture else None
        out.append(RemoteCategory(name=name, href=href, image=img))
    # de-dup by href
    seen = set()
    res: list[RemoteCategory] = []
    for c in out:
        if c.href in seen:
            continue
        seen.add(c.href)
        res.append(c)
    return res


def extract_motor_force_images(html: str, base_url: str) -> list[str]:
    """
    Motor-force product images are served from i.siteapi.org.
    We intentionally only take that CDN to avoid picking header/footer logos.
    """
    soup = BeautifulSoup(html, "lxml")
    urls: list[str] = []

    # Prefer og:image if present and CDN-backed
    og = soup.select_one('meta[property="og:image"], meta[name="og:image"]')
    if og and og.get("content"):
        u = abs_url(base_url, str(og.get("content")).strip())
        if "i.siteapi.org" in u:
            urls.append(u)

    for a in soup.select("a[href]"):
        href = (a.get("href") or "").strip()
        if not href:
            continue
        u = abs_url(base_url, href)
        if is_http_url(u) and "i.siteapi.org" in u:
            urls.append(u)

    for img in soup.select("img"):
        for attr in ("data-src", "src", "data-original"):
            v = img.get(attr)
            if isinstance(v, str) and v.strip():
                u = abs_url(base_url, v.strip())
                if is_http_url(u) and "i.siteapi.org" in u:
                    urls.append(u)

    # Dedup keep order
    # First: keep only non-logo urls
    urls = [u for u in urls if not is_logo_like_url(u)]

    # Second: de-dup by underlying asset key (same photo with different sizes/crops)
    order: list[str] = []
    best_by_key: dict[str, str] = {}
    best_score_by_key: dict[str, int] = {}
    seen_keys: set[str] = set()

    for u in urls:
        key = siteapi_asset_key(u)
        sc = siteapi_url_score(u)
        if key not in seen_keys:
            seen_keys.add(key)
            order.append(key)
            best_by_key[key] = u
            best_score_by_key[key] = sc
            continue
        if sc > best_score_by_key.get(key, -10**9):
            best_by_key[key] = u
            best_score_by_key[key] = sc

    out: list[str] = []
    for key in order:
        u = best_by_key.get(key)
        if u:
            out.append(u)
    return out


async def fetch_text(session: aiohttp.ClientSession, url: str, sem: asyncio.Semaphore, timeout_s: int) -> Optional[str]:
    async with sem:
        try:
            async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
                if r.status == 404:
                    return None
                r.raise_for_status()
                return await r.text(errors="ignore")
        except Exception:
            return None


async def download(session: aiohttp.ClientSession, url: str, out_path: Path, sem: asyncio.Semaphore, timeout_s: int) -> bool:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        if out_path.is_file() and out_path.stat().st_size > 0:
            return True  # resume: already downloaded
    except Exception:
        pass
    async with sem:
        try:
            async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
                r.raise_for_status()
                data = await r.read()
        except Exception:
            return False
    if not data:
        return False
    out_path.write_bytes(data)
    return True


def read_csv_stream(csv_path: str, encoding: str) -> tuple[list[str], Any]:
    f = open(csv_path, "r", encoding=encoding, errors="replace", newline="")
    reader = csv.reader(f, delimiter=CSV_DELIM)
    headers = next(reader, None)
    if not headers:
        raise RuntimeError("Не удалось прочитать заголовок CSV")
    headers = [h.strip() for h in headers]
    return headers, reader


def dict_from_row(headers: list[str], row: list[str]) -> dict[str, str]:
    # Safe assoc like in PHP: pad or merge tail
    if len(row) < len(headers):
        row = row + [""] * (len(headers) - len(row))
    elif len(row) > len(headers):
        head = row[: len(headers) - 1]
        tail = row[len(headers) - 1 :]
        row = head + [";".join(tail)]
    d = {headers[i]: (row[i] if i < len(row) else "") for i in range(len(headers))}
    return {k: (v or "") for k, v in d.items()}


async def main_async(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", required=True, help="Path to imported CSV (same as mf_import_catalog_csv.php)")
    ap.add_argument("--encoding", default="cp1251", help="CSV encoding (cp1251 by default)")
    ap.add_argument("--local-base", default="http://localhost")
    ap.add_argument("--iblock-id", type=int, default=4)
    ap.add_argument("--docroot", default=str(Path(__file__).resolve().parents[1] / "www"))
    ap.add_argument("--out-rel", default="/upload/mf_sync")
    ap.add_argument("--remote-base", default=REMOTE_BASE_DEFAULT)
    ap.add_argument("--remote-product-template", default=REMOTE_PRODUCT_TMPL_DEFAULT)
    ap.add_argument("--concurrency-pages", type=int, default=20)
    ap.add_argument("--concurrency-images", type=int, default=40)
    ap.add_argument("--timeout", type=int, default=40)
    ap.add_argument("--cookie-remote", default="")
    ap.add_argument("--sync-sections", action="store_true", help="Also update section images by crawling /products tree")
    ap.add_argument("--limit-articles", type=int, default=0, help="Debug: limit unique article groups to process")
    ap.add_argument("--progress-every", type=int, default=20, help="Log progress every N article groups")
    ap.add_argument("--only-article", default="", help="Debug: process only one article (any format)")
    ap.add_argument("--only-existing", action="store_true", help="Resume mode: only post items with already-downloaded images")
    ap.add_argument("--post-batch", type=int, default=200, help="How many elements to send per POST batch")
    ap.add_argument("--post-timeout", type=int, default=0, help="Timeout seconds for POST to Bitrix (0=use --timeout)")
    args = ap.parse_args(argv)

    csv_path = str(Path(args.csv).expanduser().resolve())
    if not os.path.isfile(csv_path):
        raise SystemExit(f"CSV не найден: {csv_path}")

    local_export_url = f"{args.local_base.rstrip('/')}/tools/mf_catalog_export.php?iblock_id={args.iblock_id}"
    local_update_url = f"{args.local_base.rstrip('/')}/tools/mf_catalog_update_images.php"

    docroot = Path(args.docroot).resolve()
    out_abs = docroot / args.out_rel.lstrip("/")
    out_abs.mkdir(parents=True, exist_ok=True)

    log(f"start: csv={csv_path} enc={args.encoding} iblock_id={args.iblock_id}")
    log(f"local: {args.local_base} docroot={docroot}")
    log(f"out: {args.out_rel} (abs: {out_abs})")
    log(f"remote: base={args.remote_base} tmpl={args.remote_product_template}")
    log(f"concurrency: pages={args.concurrency_pages} images={args.concurrency_images} timeout={args.timeout}s")

    headers = {
        "User-Agent": "Mozilla/5.0 (compatible; MFBitrixCSVImageSync/1.0)",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    }
    remote_headers = dict(headers)
    if args.cookie_remote.strip():
        remote_headers["Cookie"] = args.cookie_remote.strip()

    sem_pages = asyncio.Semaphore(max(1, args.concurrency_pages))
    sem_imgs = asyncio.Semaphore(max(1, args.concurrency_images))
    timeout_s = int(args.timeout)
    post_timeout_s = int(args.post_timeout) if int(args.post_timeout) > 0 else timeout_s

    connector = aiohttp.TCPConnector(limit=0, ssl=False)
    async with aiohttp.ClientSession(connector=connector, headers=headers) as local_sess, aiohttp.ClientSession(
        connector=connector, headers=remote_headers
    ) as remote_sess:
        # Stage 1: export local catalog (to map uniq_key -> local canonical code)
        log("stage1: export local catalog")
        async with local_sess.get(local_export_url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
            r.raise_for_status()
            local_data = await r.json()
        local_sections = [LocalSection(**s) for s in local_data.get("sections", [])]
        local_elements = [LocalElement(**e) for e in local_data.get("elements", [])]
        by_uniq: dict[str, LocalElement] = {}
        for e in local_elements:
            k = (e.uniq_key or "").strip()
            if not k:
                k = make_uniq_key((e.article_norm or "").strip(), (e.brand_norm or "").strip())
            if k and k not in by_uniq:
                by_uniq[k] = e
        log(f"local: sections={len(local_sections)} elements={len(local_elements)} uniq_indexed={len(by_uniq)}")

        # Stage 2: CSV pass1 - choose best row per uniq key (article_norm + brand_norm) + write minimal temp
        log("stage2: CSV pass1 (choose best per uniq key)")
        required_cols = {
            CSV_COL_ID,
            CSV_COL_ARTICLE,
            CSV_COL_NAME,
            CSV_COL_SECTION,
            CSV_COL_SLUG,
            CSV_COL_PRICE,
            CSV_COL_ACTIVE,
            CSV_COL_AVAIL,
            CSV_COL_PREVIEW,
            CSV_COL_DETAIL,
        }

        best: dict[str, tuple[int, int, str, str, str]] = {}  # uniq -> (score, detail_len, slug, article_norm, brand_norm)
        # Keep slugs only for keys that actually have duplicates to save memory.
        first_slug_by_key: dict[str, str] = {}
        extra_slugs_by_key: dict[str, list[str]] = {}
        total_rows = 0
        no_article = 0
        with open(csv_path, "r", encoding=args.encoding, errors="replace", newline="") as f:
            reader = csv.reader(f, delimiter=CSV_DELIM)
            hdr = next(reader, None)
            if not hdr:
                raise RuntimeError("Не удалось прочитать заголовок CSV")
            hdr = [h.strip() for h in hdr]
            missing = [c for c in required_cols if c not in hdr]
            if missing:
                raise RuntimeError("В CSV нет обязательных колонок: " + ", ".join(missing))

            for row in reader:
                total_rows += 1
                d = dict_from_row(hdr, row)
                art_raw = d.get(CSV_COL_ARTICLE) or ""
                a_norm = norm_article(art_raw)
                if not a_norm:
                    no_article += 1
                    continue
                _, b_norm = get_brand_from_row(d)
                uniq = make_uniq_key(a_norm, b_norm)
                name = d.get(CSV_COL_NAME) or ""
                slug = (d.get(CSV_COL_SLUG) or "").strip()
                if not slug:
                    slug = slugify_fallback(name) or "item"

                # Track duplicate slugs without allocating lists for all keys.
                first = first_slug_by_key.get(uniq)
                if first is None:
                    first_slug_by_key[uniq] = slug
                else:
                    lst = extra_slugs_by_key.get(uniq)
                    if lst is None:
                        extra_slugs_by_key[uniq] = [first, slug]
                    else:
                        lst.append(slug)

                sc, dl = score_row(d)
                prev = best.get(uniq)
                if prev is None or sc > prev[0] or (sc == prev[0] and dl > prev[1]):
                    best[uniq] = (sc, dl, slug, a_norm, b_norm)

                if total_rows % 50000 == 0:
                    log(f"csv: rows={total_rows} unique_keys={len(best)}")

        log(
            f"csv: pass1 done rows={total_rows} no_article={no_article} "
            f"unique_keys={len(best)} dup_keys={len(extra_slugs_by_key)}"
        )

        # optional limit
        keys = list(best.keys())
        if args.only_article.strip():
            only_a = norm_article(args.only_article.strip())
            keys = [k for k, v in best.items() if v[3] == only_a]
            best = {k: best[k] for k in keys}
            log(f"csv: only_article={args.only_article.strip()} -> article_norm={only_a} keys={len(keys)}")
        if args.limit_articles and args.limit_articles > 0:
            keys = keys[: args.limit_articles]
            best = {k: best[k] for k in keys}
            log(f"csv: limit_articles={args.limit_articles} -> unique_keys={len(best)}")

        # Stage 3: build canonical_slug -> slugs list for that uniq key
        log("stage3: build slug groups")
        canon_slug_by_key = {k: best[k][2] for k in best.keys()}
        # Only keep lists for duplicate keys; for others we'll use canonical slug only.
        slugs_by_key: dict[str, list[str]] = extra_slugs_by_key
        log("csv: slug groups ready")

        # Stage 4: sections (optional) - crawl /products tree to collect subcategory images by NAME
        section_updates: list[dict[str, Any]] = []
        if args.sync_sections:
            log("stage4: crawl remote categories tree for section images")
            sec_by_norm_name = {norm_name(s.name): s for s in local_sections}
            visited: set[str] = set()
            queue: list[str] = [urljoin(args.remote_base.rstrip("/") + "/", "products")]
            sec_pic_by_id: dict[int, str] = {}
            pages = 0
            total_local_sections = len(local_sections)
            skipped_section_files = 0
            while queue:
                u = queue.pop(0)
                if u in visited:
                    continue
                visited.add(u)
                html = await fetch_text(remote_sess, u, sem_pages, timeout_s)
                pages += 1
                if not html:
                    continue
                cats = parse_remote_categories(html, args.remote_base)
                for c in cats:
                    if c.href and c.href not in visited:
                        queue.append(c.href)
                    ls = sec_by_norm_name.get(norm_name(c.name))
                    if not ls or not c.image or ls.id in sec_pic_by_id:
                        continue
                    existing = find_existing_image_rel(docroot, f"{args.out_rel.rstrip('/')}/sections/{ls.id}")
                    if existing:
                        sec_pic_by_id[ls.id] = existing
                        skipped_section_files += 1
                    else:
                        ext = ext_from_url(c.image)
                        rel = f"{args.out_rel.rstrip('/')}/sections/{ls.id}{ext}"
                        ok = await download(remote_sess, c.image, docroot / rel.lstrip("/"), sem_imgs, timeout_s)
                        if ok:
                            sec_pic_by_id[ls.id] = rel
                if pages % 25 == 0:
                    est_total = pages + len(queue)
                    log(
                        f"sections: pages={pct(pages, est_total):6.2f}% "
                        f"matched={pct(len(sec_pic_by_id), total_local_sections):6.2f}% "
                        f"pages={pages} matched_cnt={len(sec_pic_by_id)}/{total_local_sections} "
                        f"skipped_files={skipped_section_files} queue={len(queue)}"
                    )
            for sid, rel in sec_pic_by_id.items():
                section_updates.append({"id": sid, "picture": rel})
            log(
                f"sections: done pages={pages} matched_cnt={len(section_updates)}/{total_local_sections}"
            )

        # Stage 5: products - scrape & aggregate images for duplicates, attach to local canonical code by MF_UNIQ_KEY
        log("stage5: products (aggregate duplicates -> canonical)")
        element_updates: list[dict[str, Any]] = []
        processed = 0
        matched = 0
        pages_ok = 0
        pages_miss = 0
        images_ok = 0
        skipped_product_dirs = 0
        skipped_image_files = 0

        progress_every = max(1, int(args.progress_every))
        t0 = time.time()
        last_log = t0

        async def process_norm(uniq_key: str) -> Optional[dict[str, Any]]:
            nonlocal pages_ok, pages_miss, images_ok
            nonlocal skipped_product_dirs, skipped_image_files
            local_el = by_uniq.get(uniq_key)
            if not local_el:
                return None
            canon_code = local_el.code

            slugs = slugs_by_key.get(uniq_key) or []
            canon_slug = canon_slug_by_key.get(uniq_key) or ""
            if canon_slug and (not slugs or slugs[0] != canon_slug):
                slugs = [canon_slug] + slugs
            # de-dup slugs
            sseen: set[str] = set()
            uniq_slugs: list[str] = []
            for s in slugs:
                s = (s or "").strip()
                if not s or s in sseen:
                    continue
                sseen.add(s)
                uniq_slugs.append(s)
            if not uniq_slugs:
                return None

            a_norm = uniq_key.split("_", 1)[0].strip() or (local_el.article_norm or "").strip()

            # Resume: if we already have numbered images on disk, reuse them without network.
            folder_rel = f"{args.out_rel.rstrip('/')}/products/{canon_code}"
            folder_abs = docroot / folder_rel.lstrip("/")
            existing_names = scan_numbered_images(folder_abs)
            if existing_names:
                skipped_product_dirs += 1
                preview_rel = f"{folder_rel}/{existing_names[0]}"
                detail_rel = preview_rel
                more_rels = [f"{folder_rel}/{n}" for n in existing_names[1:]]
                return {"code": canon_code, "preview": preview_rel, "detail": detail_rel, "more_photos": more_rels}
            if args.only_existing:
                return None

            # 1) Try Motor-Force search by article norm first (most reliable)
            # Build candidate pages. We prefer direct slug URLs (CSV duplicates)
            direct_candidates = [args.remote_product_template.format(slug=slug) for slug in uniq_slugs[:10]]

            search_candidates: list[str] = []
            for q in (a_norm, format_article_human(a_norm)):
                if not q:
                    continue
                s_url = search_url(args.remote_base, q)
                s_html = await fetch_text(remote_sess, s_url, sem_pages, timeout_s)
                if s_html:
                    search_candidates.extend(parse_search_results_products(s_html, args.remote_base))

            candidates: list[str] = []
            candidates.extend(direct_candidates)
            candidates.extend(search_candidates)

            # de-dup candidates
            cseen: set[str] = set()
            cand2: list[str] = []
            for u in candidates:
                if u in cseen:
                    continue
                cseen.add(u)
                cand2.append(u)

            def candidate_score(url: str, html: str, imgs: list[str]) -> int:
                score = 0
                if imgs:
                    score += 10
                a = norm_article(extract_remote_article(html))
                if a and a == a_norm:
                    score += 100
                # slug match (direct URL from CSV) is a strong signal
                slug = urlparse(url).path.rstrip("/").split("/")[-1]
                if slug in uniq_slugs:
                    score += 50
                return score

            best_pages: list[tuple[int, str, str, list[str]]] = []  # (score,url,html,imgs)
            for url in cand2[:25]:
                html = await fetch_text(remote_sess, url, sem_pages, timeout_s)
                if not html:
                    pages_miss += 1
                    continue
                pages_ok += 1
                imgs = extract_motor_force_images(html, url)
                if not imgs:
                    continue
                sc = candidate_score(url, html, imgs)
                if sc > 0:
                    best_pages.append((sc, url, html, imgs))

            best_pages.sort(key=lambda x: x[0], reverse=True)
            good_pages: list[tuple[str, str, list[str]]] = []
            for sc, url, html, imgs in best_pages[:2]:
                good_pages.append((url, html, imgs))

            if not good_pages:
                return None

            img_seen: set[str] = set()
            preview_rel = ""
            detail_rel = ""
            more_rels: list[str] = []
            seq = 0

            # folder_rel defined above (resume check)

            # Aggregate images from selected pages
            for url, html, imgs in good_pages:

                # ensure folder exists only when we actually have something to download
                (docroot / folder_rel.lstrip("/")).mkdir(parents=True, exist_ok=True)

                if not preview_rel:
                    first = imgs[0]
                    ext1 = ext_from_url(first)
                    seq = max(seq, 0) + 1
                    first_rel = f"{folder_rel}/{seq:04d}{ext1}"
                    first_abs = docroot / first_rel.lstrip("/")
                    already = first_abs.is_file()
                    ok = await download(remote_sess, first, first_abs, sem_imgs, timeout_s)
                    if ok:
                        img_seen.add(first)
                        images_ok += 1
                        if already:
                            skipped_image_files += 1
                        # Use the same numbered file for both preview and detail (no duplicate files).
                        preview_rel = first_rel
                        detail_rel = first_rel
                    else:
                        preview_rel = ""
                        detail_rel = ""

                for u in imgs[1:25]:
                    if u in img_seen:
                        continue
                    img_seen.add(u)
                    seq += 1
                    ext = ext_from_url(u)
                    rel = f"{folder_rel}/{seq:04d}{ext}"
                    abs_p = docroot / rel.lstrip("/")
                    already = abs_p.is_file()
                    ok = await download(remote_sess, u, abs_p, sem_imgs, timeout_s)
                    if ok:
                        more_rels.append(rel)
                        images_ok += 1
                        if already:
                            skipped_image_files += 1

            if not preview_rel:
                return None
            return {"code": canon_code, "preview": preview_rel, "detail": detail_rel, "more_photos": more_rels}

        # bounded in-flight tasks (avoid creating 300k tasks at once)
        max_inflight = max(10, args.concurrency_pages * 5)
        inflight: set[asyncio.Task[Optional[dict[str, Any]]]] = set()
        norms_iter = iter(keys)

        def log_progress(force: bool = False) -> None:
            nonlocal last_log
            now = time.time()
            if not force and (now - last_log) < 3:
                return
            last_log = now
            elapsed = int(now - t0)
            total = len(keys)
            log(
                f"products: {pct(processed, total):6.2f}% processed={processed}/{total} matched={matched} "
                f"pages_ok={pages_ok} pages_miss={pages_miss} images_ok={images_ok} "
                f"skipped_dirs={skipped_product_dirs} skipped_files={skipped_image_files} elapsed={elapsed}s"
            )

        while True:
            while len(inflight) < max_inflight:
                try:
                    n = next(norms_iter)
                except StopIteration:
                    break
                inflight.add(asyncio.create_task(process_norm(n)))

            if not inflight:
                break

            done, inflight = await asyncio.wait(inflight, return_when=asyncio.FIRST_COMPLETED)
            for task in done:
                processed += 1
                upd = task.result()
                if upd:
                    element_updates.append(upd)
                    matched += 1
                if processed % progress_every == 0:
                    log_progress(force=True)
            log_progress(force=False)

        log_progress(force=True)
        log(f"products: done processed_articles={processed} matched={matched}")

        # Stage 6: post update (batched to avoid timeouts)
        log("stage6: post update to local Bitrix")
        batch_size = max(1, int(args.post_batch))

        async def post_one_batch(sections_part: list[dict[str, Any]], elements_part: list[dict[str, Any]]) -> dict[str, Any]:
            payload = {"iblock_id": args.iblock_id, "sections": sections_part, "elements": elements_part}
            async with local_sess.post(
                local_update_url,
                data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
                headers={"Content-Type": "application/json; charset=utf-8"},
                timeout=aiohttp.ClientTimeout(total=post_timeout_s),
            ) as r:
                r.raise_for_status()
                return await r.json()

        total_el = len(element_updates)
        total_batches = (total_el + batch_size - 1) // batch_size
        agg_updated = {"sections": 0, "elements": 0, "more_photos": 0}
        agg_errors: list[Any] = []
        has_more_photo = None

        # Post sections only once (first batch) to reduce duplicate work.
        for bi in range(total_batches if total_batches > 0 else 1):
            start = bi * batch_size
            end = min(start + batch_size, total_el)
            part = element_updates[start:end] if total_el > 0 else []
            sec_part = section_updates if bi == 0 else []
            log(f"post: batch {bi+1}/{max(total_batches,1)} elements={len(part)} sections={len(sec_part)} timeout={post_timeout_s}s")
            resp = await post_one_batch(sec_part, part)
            upd = resp.get("updated") or {}
            for k in ("sections", "elements", "more_photos"):
                agg_updated[k] += int(upd.get(k) or 0)
            errs = resp.get("errors") or []
            if isinstance(errs, list) and errs:
                agg_errors.extend(errs)
            if has_more_photo is None:
                has_more_photo = resp.get("has_more_photo")

        log("done: update summary below")
        print(
            json.dumps(
                {"ok": True, "updated": agg_updated, "errors": agg_errors[:50], "errors_total": len(agg_errors), "has_more_photo": has_more_photo},
                ensure_ascii=False,
            ),
            flush=True,
        )

    return 0


def tempfile_dir() -> str:
    # Avoid importing tempfile (keeps startup tiny); use system temp dir.
    return os.environ.get("TMPDIR") or os.environ.get("TMP") or os.environ.get("TEMP") or "/tmp"


def main() -> int:
    try:
        return asyncio.run(main_async(sys.argv[1:]))
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())

