#!/usr/bin/env python3
"""
Sync category & product images from motor-force.ru into local Bitrix catalog.

Flow:
1) GET local catalog structure from /tools/mf_catalog_export.php (sections + elements).
2) Scrape remote categories from https://motor-force.ru/products (category-item cards).
3) Map local sections to remote categories by normalized NAME.
4) Scrape remote product listing pages (per category) and build map remote_slug -> image urls.
5) Map local elements by CODE (slug) to remote products by slug; fallback by normalized NAME.
6) Download images in parallel into Bitrix docroot under /upload/mf_sync/...
7) POST mapping to /tools/mf_catalog_update_images.php to update PICTURE / PREVIEW/DETAIL/MORE_PHOTO.

Run:
  python3 tools/sync_motor_force_images_to_bitrix.py --local-base http://localhost --iblock-id 4
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional
from urllib.parse import urljoin, urlparse

import aiohttp
from bs4 import BeautifulSoup  # type: ignore


REMOTE_BASE = "https://motor-force.ru"
REMOTE_PRODUCTS = "https://motor-force.ru/products"

def log(msg: str) -> None:
    ts = time.strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", file=sys.stderr, flush=True)


def norm_name(s: str) -> str:
    s = (s or "").strip().lower()
    s = s.replace("ё", "е")
    s = re.sub(r"\s+", " ", s)
    s = re.sub(r"[\"'`’]+", "", s)
    s = re.sub(r"[^a-zа-я0-9 _-]+", "", s)
    s = s.replace("_", " ").replace("-", " ")
    s = re.sub(r"\s+", " ", s).strip()
    return s


def is_http_url(u: str) -> bool:
    try:
        p = urlparse(u)
        return p.scheme in ("http", "https")
    except Exception:
        return False


def abs_url(base: str, u: str) -> str:
    u = (u or "").strip()
    if not u:
        return ""
    if u.startswith("//"):
        return "https:" + u
    return urljoin(base, u)


def best_src_from_picture(picture: BeautifulSoup, base: str) -> Optional[str]:
    # Prefer 2x candidates from srcset
    sources = picture.select("source[srcset]")
    candidates: list[str] = []
    for s in sources:
        srcset = (s.get("srcset") or "").strip()
        if not srcset:
            continue
        # parse "url 1x, url 2x"
        parts = [p.strip() for p in srcset.split(",") if p.strip()]
        # try pick 2x first
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


@dataclass
class LocalSection:
    id: int
    name: str
    code: str
    parent_id: int
    depth: int
    section_page_url: str


@dataclass
class LocalElement:
    id: int
    name: str
    code: str
    xml_id: str
    section_id: int
    article: str
    detail_page_url: str


@dataclass
class RemoteCategory:
    name: str
    href: str
    image: Optional[str]


@dataclass
class RemoteProduct:
    name: str
    href: str
    slug: str
    preview_image: Optional[str]


def parse_remote_categories(html: str) -> list[RemoteCategory]:
    soup = BeautifulSoup(html, "lxml")
    out: list[RemoteCategory] = []
    for item in soup.select(".category-item"):
        link = item.select_one(".category-item__link a") or item.select_one("a[href*='/products/category/']")
        if not link:
            continue
        name = (link.get_text(" ", strip=True) or "").strip()
        href = abs_url(REMOTE_BASE, (link.get("href") or "").strip())
        if not name or not href:
            continue
        picture = item.select_one("picture")
        img = best_src_from_picture(picture, REMOTE_BASE) if picture else None
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


def parse_remote_products_from_category(html: str, base_url: str) -> list[RemoteProduct]:
    soup = BeautifulSoup(html, "lxml")
    products: list[RemoteProduct] = []

    def add_product(a_tag, scope) -> None:
        href_raw = (a_tag.get("href") or "").strip()
        if not href_raw:
            return
        href = abs_url(base_url, href_raw)
        if "/products/category/" in href:
            return
        if not re.search(r"/products/[^/]+/?$", href):
            return
        slug = urlparse(href).path.rstrip("/").split("/")[-1]
        if not slug:
            return
        name = (a_tag.get_text(" ", strip=True) or "").strip()
        if not name:
            # try title attribute
            name = (a_tag.get("title") or "").strip()
        pic = None
        picture = scope.select_one("picture") if scope else None
        if picture:
            pic = best_src_from_picture(picture, base_url)
        if not pic:
            img = scope.select_one("img") if scope else None
            if img:
                for attr in ("data-src", "src"):
                    v = img.get(attr)
                    if isinstance(v, str) and v.strip():
                        pic = abs_url(base_url, v.strip())
                        break
        products.append(RemoteProduct(name=name, href=href, slug=slug, preview_image=pic))

    # Try common product card containers
    for card in soup.select(".product-item, .catalog-item, .product, .card"):
        a = card.select_one("a[href*='/products/']")
        if a:
            add_product(a, card)

    if not products:
        # Fallback: scan anchors
        for a in soup.select("a[href*='/products/']"):
            add_product(a, a.parent)

    # de-dup by slug
    seen = set()
    res: list[RemoteProduct] = []
    for p in products:
        if p.slug in seen:
            continue
        seen.add(p.slug)
        res.append(p)
    return res


def extract_remote_gallery_images(html: str, base_url: str) -> list[str]:
    soup = BeautifulSoup(html, "lxml")
    urls: list[str] = []

    # Common: fancybox/zoom links
    for a in soup.select("a[href]"):
        href = (a.get("href") or "").strip()
        if not href:
            continue
        u = abs_url(base_url, href)
        if not is_http_url(u):
            continue
        if "i.siteapi.org" in u or u.lower().endswith((".jpg", ".jpeg", ".png", ".webp")):
            urls.append(u)

    for img in soup.select("img"):
        for attr in ("data-src", "src"):
            v = img.get(attr)
            if isinstance(v, str) and v.strip():
                u = abs_url(base_url, v.strip())
                if is_http_url(u) and ("i.siteapi.org" in u or u.lower().endswith((".jpg", ".jpeg", ".png", ".webp"))):
                    urls.append(u)

    # De-dup keep order
    seen = set()
    out: list[str] = []
    for u in urls:
        if u in seen:
            continue
        seen.add(u)
        out.append(u)
    return out


def ext_from_url(u: str) -> str:
    path = urlparse(u).path.lower()
    _, ext = os.path.splitext(path)
    if ext in (".jpg", ".jpeg", ".png", ".webp"):
        return ext
    return ".jpg"


async def fetch(session: aiohttp.ClientSession, url: str, sem: asyncio.Semaphore, timeout_s: int) -> str:
    async with sem:
        async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
            r.raise_for_status()
            return await r.text(errors="ignore")


async def download(session: aiohttp.ClientSession, url: str, out_path: Path, sem: asyncio.Semaphore, timeout_s: int) -> None:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    async with sem:
        async with session.get(url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
            r.raise_for_status()
            data = await r.read()
    out_path.write_bytes(data)


async def main_async(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--local-base", default="http://localhost", help="Base URL of local site (Bitrix)")
    ap.add_argument("--iblock-id", type=int, default=4)
    ap.add_argument("--docroot", default=str(Path(__file__).resolve().parents[1] / "www"), help="Bitrix document root (workspace/www/www)")
    ap.add_argument("--out-rel", default="/upload/mf_sync", help="Relative dir under docroot to save images")
    ap.add_argument("--concurrency-pages", type=int, default=20)
    ap.add_argument("--concurrency-images", type=int, default=40)
    ap.add_argument("--timeout", type=int, default=40)
    ap.add_argument("--cookie-remote", default="", help="Cookie header for remote site if needed")
    ap.add_argument("--limit-sections", type=int, default=0)
    ap.add_argument("--limit-elements", type=int, default=0)
    args = ap.parse_args(argv)

    local_export_url = f"{args.local_base.rstrip('/')}/tools/mf_catalog_export.php?iblock_id={args.iblock_id}"
    local_update_url = f"{args.local_base.rstrip('/')}/tools/mf_catalog_update_images.php"

    docroot = Path(args.docroot).resolve()
    out_abs = docroot / args.out_rel.lstrip("/")
    out_abs.mkdir(parents=True, exist_ok=True)

    log(f"start: iblock_id={args.iblock_id} local={args.local_base} docroot={docroot}")
    log(f"out: {args.out_rel} (abs: {out_abs})")
    log(f"remote: {REMOTE_PRODUCTS}")
    log(f"concurrency: pages={args.concurrency_pages} images={args.concurrency_images} timeout={args.timeout}s")

    headers = {
        "User-Agent": "Mozilla/5.0 (compatible; MFBitrixImageSync/1.0)",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    }
    remote_headers = dict(headers)
    if args.cookie_remote.strip():
        remote_headers["Cookie"] = args.cookie_remote.strip()

    sem_pages = asyncio.Semaphore(max(1, args.concurrency_pages))
    sem_imgs = asyncio.Semaphore(max(1, args.concurrency_images))
    timeout_s = int(args.timeout)

    connector = aiohttp.TCPConnector(limit=0, ssl=False)
    async with aiohttp.ClientSession(connector=connector, headers=headers) as local_sess, aiohttp.ClientSession(
        connector=connector, headers=remote_headers
    ) as remote_sess:
        # 1) export local catalog
        log("stage1: export local catalog")
        async with local_sess.get(local_export_url, timeout=aiohttp.ClientTimeout(total=timeout_s)) as r:
            r.raise_for_status()
            local_data = await r.json()

        sections = [LocalSection(**s) for s in local_data.get("sections", [])]
        elements = [LocalElement(**e) for e in local_data.get("elements", [])]
        if args.limit_sections and args.limit_sections > 0:
            sections = sections[: args.limit_sections]
        if args.limit_elements and args.limit_elements > 0:
            elements = elements[: args.limit_elements]

        log(f"local: sections={len(sections)} elements={len(elements)}")

        sec_by_norm = {norm_name(s.name): s for s in sections}
        el_by_code = {e.code: e for e in elements if e.code}
        el_by_norm_name = {}
        for e in elements:
            nn = norm_name(e.name)
            el_by_norm_name.setdefault(nn, []).append(e)

        # 2) scrape remote categories
        log("stage2: scrape remote categories list")
        remote_products_html = await fetch(remote_sess, REMOTE_PRODUCTS, sem_pages, timeout_s)
        remote_categories = parse_remote_categories(remote_products_html)
        log(f"remote: categories_found={len(remote_categories)}")

        # 3) map sections -> remote categories (by name)
        section_updates: list[dict[str, Any]] = []
        category_pages: list[str] = []
        dl_cat_tasks: list[asyncio.Task[tuple[Optional[dict[str, Any]], Optional[str]]]] = []

        async def dl_one_category(ls: LocalSection, rc: RemoteCategory) -> tuple[Optional[dict[str, Any]], Optional[str]]:
            if not rc.image:
                return None, None
            ext = ext_from_url(rc.image)
            rel = f"{args.out_rel.rstrip('/')}/sections/{ls.id}{ext}"
            try:
                await download(remote_sess, rc.image, docroot / rel.lstrip("/"), sem_imgs, timeout_s)
                return {"id": ls.id, "picture": rel}, rc.href
            except Exception:
                return None, rc.href

        log("stage3: match & download category images")
        for rc in remote_categories:
            ls = sec_by_norm.get(norm_name(rc.name))
            if not ls or not rc.image:
                continue
            dl_cat_tasks.append(asyncio.create_task(dl_one_category(ls, rc)))

        matched_sections = 0
        for i, fut in enumerate(asyncio.as_completed(dl_cat_tasks), start=1):
            upd, href = await fut
            if upd:
                section_updates.append(upd)
                matched_sections += 1
            if href:
                category_pages.append(href)
            if i % 25 == 0:
                log(f"categories: processed={i}/{len(dl_cat_tasks)} matched={matched_sections}")

        # de-dup category pages
        seen_pages = set()
        category_pages = [u for u in category_pages if not (u in seen_pages or seen_pages.add(u))]
        log(f"categories: matched={len(section_updates)} pages_to_scan={len(category_pages)}")

        # 4) scrape remote product listings per category (build slug map)
        log("stage4: scrape category pages -> build remote products map")
        remote_prod_by_slug: dict[str, RemoteProduct] = {}
        cat_fetch_tasks: list[asyncio.Task[tuple[str, Optional[str]]]] = []

        async def fetch_cat(url: str) -> tuple[str, Optional[str]]:
            try:
                return url, await fetch(remote_sess, url, sem_pages, timeout_s)
            except Exception:
                return url, None

        for url in category_pages:
            cat_fetch_tasks.append(asyncio.create_task(fetch_cat(url)))

        for i, fut in enumerate(asyncio.as_completed(cat_fetch_tasks), start=1):
            url, html = await fut
            if html:
                for rp in parse_remote_products_from_category(html, url):
                    remote_prod_by_slug.setdefault(rp.slug, rp)
            if i % 10 == 0:
                log(f"category pages: processed={i}/{len(cat_fetch_tasks)} remote_products={len(remote_prod_by_slug)}")

        log(f"remote: products_indexed={len(remote_prod_by_slug)}")

        # 5) map local elements -> remote products
        log("stage5: match products, download images, prepare update payload")
        element_updates: list[dict[str, Any]] = []

        async def process_element(e: LocalElement) -> Optional[dict[str, Any]]:
            rp = remote_prod_by_slug.get(e.code)
            if not rp:
                # fallback by name
                nn = norm_name(e.name)
                # find any remote with same norm name
                for candidate in remote_prod_by_slug.values():
                    if norm_name(candidate.name) == nn:
                        rp = candidate
                        break
            if not rp:
                return None

            # fetch product page and extract gallery
            try:
                html = await fetch(remote_sess, rp.href, sem_pages, timeout_s)
            except Exception:
                return None
            imgs = extract_remote_gallery_images(html, rp.href)
            if rp.preview_image:
                imgs = [rp.preview_image] + imgs
            # dedup
            seen = set()
            uniq: list[str] = []
            for u in imgs:
                if u in seen:
                    continue
                seen.add(u)
                uniq.append(u)
            if not uniq:
                return None

            folder_rel = f"{args.out_rel.rstrip('/')}/products/{e.code}"
            folder_abs = docroot / folder_rel.lstrip("/")
            folder_abs.mkdir(parents=True, exist_ok=True)

            # download first as preview/detail, rest as more
            first = uniq[0]
            ext1 = ext_from_url(first)
            prev_rel = f"{folder_rel}/preview{ext1}"
            det_rel = f"{folder_rel}/detail{ext1}"
            await download(remote_sess, first, docroot / prev_rel.lstrip("/"), sem_imgs, timeout_s)
            # reuse same file for detail by copying bytes
            (docroot / det_rel.lstrip("/")).write_bytes((docroot / prev_rel.lstrip("/")).read_bytes())

            more_rels: list[str] = []
            # limit more photos to sane number
            for i, u in enumerate(uniq[1:15], start=2):
                ext = ext_from_url(u)
                rel = f"{folder_rel}/{i:02d}{ext}"
                try:
                    await download(remote_sess, u, docroot / rel.lstrip("/"), sem_imgs, timeout_s)
                    more_rels.append(rel)
                except Exception:
                    continue

            return {
                "code": e.code,
                "preview": prev_rel,
                "detail": det_rel,
                "more_photos": more_rels,
                "_stats": {"more_photos": len(more_rels)},
            }

        # process elements concurrently
        tasks = [asyncio.create_task(process_element(e)) for e in elements]
        processed = 0
        matched = 0
        total_more = 0
        for fut in asyncio.as_completed(tasks):
            u = await fut
            processed += 1
            if u:
                matched += 1
                total_more += int((u.get("_stats") or {}).get("more_photos") or 0)
                u.pop("_stats", None)
                element_updates.append(u)
            if processed % 100 == 0:
                log(f"products: processed={processed}/{len(tasks)} matched={matched} more_photos={total_more}")

        log(f"products: matched={len(element_updates)} (from local {len(elements)})")

        # 6) POST update to local Bitrix
        log("stage6: post update to local Bitrix")
        payload = {
            "iblock_id": args.iblock_id,
            "sections": section_updates,
            "elements": element_updates,
        }
        async with local_sess.post(
            local_update_url,
            data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            headers={"Content-Type": "application/json; charset=utf-8"},
            timeout=aiohttp.ClientTimeout(total=timeout_s),
        ) as r:
            r.raise_for_status()
            resp = await r.text()
        log("done: update response below")
        print(resp, flush=True)

    return 0


def main() -> int:
    try:
        return asyncio.run(main_async(os.sys.argv[1:]))
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())

