from __future__ import annotations

import asyncio
import random
import time
from typing import Any
from urllib.parse import urlencode, urlparse, urlunparse

import httpx
from tqdm import tqdm

from outdoorworld_scraper import db as dbmod
from outdoorworld_scraper.export_csv import parse_kzt_from_price_raw
from outdoorworld_scraper.parse import (
	ListingProduct,
	collect_category_urls_from_html,
	is_excluded_category,
	max_pagination_page,
	parse_listing_page,
	parse_product_detail,
	resolve_stock_qty,
)

# Сетевые сбои, при которых имеет смысл повторить запрос (HTTP/2 у некоторых хостов рвётся с NO_ERROR).
_TRANSIENT_HTTP_ERRORS = (
	httpx.RemoteProtocolError,
	httpx.ReadError,
	httpx.WriteError,
	httpx.ConnectError,
	httpx.ReadTimeout,
	httpx.ConnectTimeout,
	httpx.PoolTimeout,
)


def _page_url(category_url: str, pagen_param: int, page_num: int) -> str:
	parsed = urlparse(category_url)
	q: dict[str, str] = {}
	if parsed.query:
		for part in parsed.query.split("&"):
			if not part or "=" not in part:
				continue
			k, v = part.split("=", 1)
			q[k] = v
	if page_num <= 1:
		q.pop(f"PAGEN_{pagen_param}", None)
	else:
		q[f"PAGEN_{pagen_param}"] = str(page_num)
	new_q = urlencode(sorted(q.items())) if q else ""
	return urlunparse(
		(parsed.scheme, parsed.netloc, parsed.path, parsed.params, new_q, parsed.fragment)
	)


async def fetch_text(
	client: httpx.AsyncClient,
	url: str,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int = 5,
	retry_delay_sec: float = 0.5,
) -> str:
	last: BaseException | None = None
	for attempt in range(max(1, max_retries)):
		try:
			async with sem:
				await asyncio.sleep(delay + random.uniform(0, 0.15))
				r = await client.get(url, follow_redirects=True)
				r.raise_for_status()
				return r.text
		except _TRANSIENT_HTTP_ERRORS as e:
			if attempt >= max_retries - 1:
				raise
			backoff = retry_delay_sec * (2**attempt) + random.uniform(0, 0.25)
			await asyncio.sleep(backoff)


def _detail_flag_after_listing(leave_detail_pending: bool) -> int:
	return 0 if leave_detail_pending else 1


def _stock_qty_from_listing(p: ListingProduct, cfg: dict[str, Any]) -> int | None:
	if bool(cfg.get("listing_prefer_data_quan", True)) and p.data_quan is not None:
		return p.data_quan
	return resolve_stock_qty(p.stock_label, p.stock_stack, None, cfg)


async def run_crawl(
	cfg: dict[str, Any],
	conn: Any,
	*,
	progress: bool = True,
	max_categories: int | None = None,
	leave_detail_pending: bool = False,
) -> None:
	base = cfg["base_url"].rstrip("/")
	catalog_index = base + cfg.get("catalog_path", "/catalog/")
	excluded_prefixes = list(cfg.get("excluded_path_prefixes", []))
	excluded_exact = list(cfg.get("excluded_paths_exact", []))
	delay = float(cfg.get("request_delay_sec", 0.35))
	concurrency = int(cfg.get("max_concurrent_requests", 4))
	timeout = float(cfg.get("timeout_seconds", 45))
	max_conn = int(cfg.get("max_connections", 8))

	headers = {"User-Agent": cfg.get("user_agent", "Mozilla/5.0"), "Accept-Language": "ru-RU,ru;q=0.9"}

	limits = httpx.Limits(max_connections=max_conn, max_keepalive_connections=max_conn)
	sem = asyncio.Semaphore(concurrency)
	http2 = bool(cfg.get("http2", False))
	max_retries = int(cfg.get("http_max_retries", 5))
	retry_delay_sec = float(cfg.get("http_retry_delay_sec", 0.5))

	async with httpx.AsyncClient(
		headers=headers,
		timeout=timeout,
		limits=limits,
		http2=http2,
	) as client:
		idx_html = await fetch_text(
			client,
			catalog_index,
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
		)
		all_cats = collect_category_urls_from_html(base, idx_html)
		categories = sorted(
			{
				u
				for u in all_cats
				if not is_excluded_category(u, excluded_prefixes, excluded_exact)
			}
		)
		if max_categories is not None and max_categories > 0:
			categories = categories[:max_categories]
		dbmod.meta_set(conn, "last_category_count", str(len(categories)))
		if progress:
			print(f"[outdoorworld] Категорий к обходу: {len(categories)}", flush=True)

		dd_listing = _detail_flag_after_listing(leave_detail_pending)

		t_cat = tqdm(categories, desc="Категории", disable=not progress, unit="cat")

		for cat_url in t_cat:
			short = urlparse(cat_url).path.strip("/").split("/")[-1][:24]
			t_cat.set_postfix_str(short)

			meta = dbmod.get_category_meta(conn, cat_url)
			if meta is None:
				html1 = await fetch_text(
					client,
					cat_url,
					delay=delay,
					sem=sem,
					max_retries=max_retries,
					retry_delay_sec=retry_delay_sec,
				)
				prods, pagen_param = parse_listing_page(html1, cat_url)
				pp = pagen_param if pagen_param is not None else 1
				max_pg = max(1, max_pagination_page(html1, pp))
				dbmod.set_category_meta(conn, cat_url, pp, max_pg)
				if not dbmod.is_page_done(conn, cat_url, 1):
					for p in prods:
						pk = parse_kzt_from_price_raw(p.price_raw)
						sq = _stock_qty_from_listing(p, cfg)
						dbmod.upsert_product(
							conn,
							product_url=p.product_url,
							bx_id=p.bx_id,
							name=p.name,
							manufacturer=p.manufacturer,
							article=p.article,
							price_raw=p.price_raw,
							category_url=cat_url,
							price_kzt=pk,
							stock_qty=sq,
							stock_label=p.stock_label,
							stock_stack=p.stock_stack,
							detail_done=dd_listing,
						)
					dbmod.mark_page_done(conn, cat_url, 1, len(prods))
					dbmod.commit_products(conn)
				meta = dbmod.get_category_meta(conn, cat_url)

			assert meta is not None
			pagen_param, max_pg = meta
			pp = pagen_param if pagen_param is not None else 1

			for pn in range(1, max_pg + 1):
				if dbmod.is_page_done(conn, cat_url, pn):
					continue
				url = cat_url if pn == 1 else _page_url(cat_url, pp, pn)
				html = await fetch_text(
					client,
					url,
					delay=delay,
					sem=sem,
					max_retries=max_retries,
					retry_delay_sec=retry_delay_sec,
				)
				prods, _ = parse_listing_page(html, cat_url)
				for p in prods:
					pk = parse_kzt_from_price_raw(p.price_raw)
					sq = _stock_qty_from_listing(p, cfg)
					dbmod.upsert_product(
						conn,
						product_url=p.product_url,
						bx_id=p.bx_id,
						name=p.name,
						manufacturer=p.manufacturer,
						article=p.article,
						price_raw=p.price_raw,
						category_url=cat_url,
						price_kzt=pk,
						stock_qty=sq,
						stock_label=p.stock_label,
						stock_stack=p.stock_stack,
						detail_done=dd_listing,
					)
				dbmod.mark_page_done(conn, cat_url, pn, len(prods))
				dbmod.commit_products(conn)

			st = dbmod.stats(conn)
			t_cat.set_postfix_str(f"{short} | товаров={st['products']} стр={st['category_pages']}")

		dbmod.meta_set(conn, "finished_at", str(time.time()))


async def run_detail_enrichment(
	cfg: dict[str, Any],
	conn: Any,
	*,
	progress: bool = True,
	max_products: int | None = None,
) -> None:
	"""Подгрузка карточек /product/.../ — Бренд и Артикул из «Характеристики»."""
	delay = float(cfg.get("request_delay_sec", 0.35))
	concurrency = int(cfg.get("max_concurrent_requests", 4))
	timeout = float(cfg.get("timeout_seconds", 45))
	max_conn = int(cfg.get("max_connections", 8))
	headers = {"User-Agent": cfg.get("user_agent", "Mozilla/5.0"), "Accept-Language": "ru-RU,ru;q=0.9"}
	limits = httpx.Limits(max_connections=max_conn, max_keepalive_connections=max_conn)
	sem = asyncio.Semaphore(concurrency)
	http2 = bool(cfg.get("http2", False))
	max_retries = int(cfg.get("http_max_retries", 5))
	retry_delay_sec = float(cfg.get("http_retry_delay_sec", 0.5))

	urls = dbmod.product_urls_pending_detail(conn)
	if max_products is not None and max_products > 0:
		urls = urls[:max_products]
	total = len(urls)
	if progress:
		print(f"[outdoorworld] Карточек к обогащению (Бренд/Артикул): {total}", flush=True)
	if total == 0:
		return

	t_bar = tqdm(urls, desc="Карточки товара", disable=not progress, unit="sku")

	async with httpx.AsyncClient(
		headers=headers,
		timeout=timeout,
		limits=limits,
		http2=http2,
	) as client:
		for purl in t_bar:
			try:
				html = await fetch_text(
					client,
					purl,
					delay=delay,
					sem=sem,
					max_retries=max_retries,
					retry_delay_sec=retry_delay_sec,
				)
				d = parse_product_detail(html)
				sq = resolve_stock_qty(d.stock_label, d.stock_stack, d.availability, cfg)
				dbmod.apply_product_detail(
					conn,
					product_url=purl,
					manufacturer=d.manufacturer,
					article=d.article,
					price_kzt=d.price_kzt,
					stock_qty=sq,
					stock_label=d.stock_label,
					stock_stack=d.stock_stack,
				)
				dbmod.commit_products(conn)
			except Exception as e:
				if progress:
					t_bar.write(f"[outdoorworld] пропуск (повтор при следующем запуске) {purl}: {e}\n")
				continue
			st = dbmod.stats(conn)
			t_bar.set_postfix_str(f"готово={st['products_detail_done']}/{st['products']}")

	dbmod.meta_set(conn, "detail_enrich_finished_at", str(time.time()))
