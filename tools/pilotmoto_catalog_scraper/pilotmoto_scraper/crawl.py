from __future__ import annotations

import asyncio
import random
import time
from collections import deque
from typing import Any
from urllib.parse import urlparse

import httpx
from tqdm import tqdm

from pilotmoto_scraper import db as dbmod
from pilotmoto_scraper.export_csv import parse_kzt_from_price_raw
from pilotmoto_scraper.parse import (
	ListingProduct,
	build_listing_variant_offers,
	canonical_catalog_url,
	collect_category_urls_from_html,
	is_excluded_category,
	listing_modal_page_code,
	listing_page_url,
	listing_stock_modal_ajax_url,
	parse_color_size_modal_variants,
	parse_listing_page,
	parse_max_page_pilotmoto,
	parse_modal_available_params,
	parse_modal_color_size_params,
	parse_product_detail,
	product_has_color_size_variants,
	resolve_stock_qty,
	sum_stock_from_color_size_modal,
	sum_stock_from_modal_available_html,
)

_TRANSIENT_HTTP_ERRORS = (
	httpx.RemoteProtocolError,
	httpx.ReadError,
	httpx.WriteError,
	httpx.ConnectError,
	httpx.ReadTimeout,
	httpx.ConnectTimeout,
	httpx.PoolTimeout,
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
	for attempt in range(max(1, max_retries)):
		try:
			async with sem:
				await asyncio.sleep(delay + random.uniform(0, 0.15))
				r = await client.get(url, follow_redirects=True)
				r.raise_for_status()
				return r.text
		except _TRANSIENT_HTTP_ERRORS:
			if attempt >= max_retries - 1:
				raise
			backoff = retry_delay_sec * (2**attempt) + random.uniform(0, 0.25)
			await asyncio.sleep(backoff)


async def _discover_all_category_urls(
	client: httpx.AsyncClient,
	base: str,
	seed: list[str],
	excluded_prefixes: list[str],
	excluded_exact: list[str],
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
	progress: bool,
) -> list[str]:
	"""Фаза 1: BFS по всем ссылкам /catalog/.../ пока очередь не пуста (без записи товаров)."""
	queue: deque[str] = deque(canonical_catalog_url(u) for u in seed)
	seen: set[str] = set(queue)
	t_disc = tqdm(disable=not progress, unit="url", desc="Обход категорий (BFS)")
	while queue:
		cat = queue.popleft()
		t_disc.update(1)
		t_disc.set_postfix_str(urlparse(cat).path.strip("/").split("/")[-1][:28])
		html = await fetch_text(
			client,
			listing_page_url(cat, 1),
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
		)
		for u in collect_category_urls_from_html(base, html):
			if is_excluded_category(u, excluded_prefixes, excluded_exact):
				continue
			cu = canonical_catalog_url(u)
			if cu not in seen:
				seen.add(cu)
				queue.append(cu)
		t_disc.set_postfix_str(f"{len(seen)} URL")
	t_disc.close()
	return sorted(seen)


def _detail_flag_after_listing(leave_detail_pending: bool) -> int:
	return 0 if leave_detail_pending else 1


async def _fetch_modal_stock_qty(
	client: httpx.AsyncClient,
	base: str,
	cfg: dict[str, Any],
	gid: str,
	id_: str,
	page_code: str,
	has_variants: bool,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
) -> int | None:
	"""
	Остаток через ajax load_modal.
	Товары с getColorSizeTable: сумма по modal_color_size (все размеры/цвета);
	modal_available даёт только один SKU и занижает остаток.
	Одиночный SKU: сначала modal_available; при 0 — modal_color_size (обход блокировки DC IP).
	"""
	color_fallback = bool(cfg.get("listing_stock_color_fallback", True))

	async def _load(kind: str) -> int | None:
		url = listing_stock_modal_ajax_url(
			base, gid, id_, page_code, kind=kind
		)
		html = await fetch_text(
			client,
			url,
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
		)
		if kind == "modal_color_size":
			return sum_stock_from_color_size_modal(html)
		return sum_stock_from_modal_available_html(html)

	try:
		if has_variants:
			sq_cs = await _load("modal_color_size")
			if sq_cs is not None:
				return sq_cs
			return await _load("modal_available")

		if not color_fallback:
			return await _load("modal_available")

		sq_av, sq_cs = await asyncio.gather(
			_load("modal_available"),
			_load("modal_color_size"),
		)
		if sq_av is not None and sq_av > 0:
			return sq_av
		if sq_cs is not None and sq_cs > 0:
			return sq_cs
		return sq_av if sq_av is not None else sq_cs
	except _TRANSIENT_HTTP_ERRORS:
		return None
	except httpx.HTTPStatusError:
		return None


async def _fetch_listing_stock_qty(
	client: httpx.AsyncClient,
	base: str,
	cfg: dict[str, Any],
	p: ListingProduct,
	page_code: str,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
) -> int | None:
	if not p.stock_modal_gid:
		return None
	return await _fetch_modal_stock_qty(
		client,
		base,
		cfg,
		p.stock_modal_gid,
		p.stock_modal_id or "",
		page_code,
		p.stock_has_variants,
		delay=delay,
		sem=sem,
		max_retries=max_retries,
		retry_delay_sec=retry_delay_sec,
	)


async def _fetch_modal_color_size_html(
	client: httpx.AsyncClient,
	base: str,
	gid: str,
	id_: str,
	page_code: str,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
) -> str:
	url = listing_stock_modal_ajax_url(
		base, gid, id_, page_code, kind="modal_color_size"
	)
	return await fetch_text(
		client,
		url,
		delay=delay,
		sem=sem,
		max_retries=max_retries,
		retry_delay_sec=retry_delay_sec,
	)


def _apply_modal_variant_stock(p: ListingProduct, raw: list[dict[str, Any]]) -> None:
	if len(raw) != 1:
		return
	v = raw[0]
	sl = (v.get("stock_label") or "").strip()
	sq = v.get("stock_qty")
	if sl:
		p.stock_mob_raw = sl
	elif sq is not None:
		p.stock_mob_raw = f"{sq} шт."


async def _enrich_listing_variant_hub(
	client: httpx.AsyncClient,
	base: str,
	cfg: dict[str, Any],
	p: ListingProduct,
	page_code: str,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
) -> None:
	if not p.stock_modal_gid:
		return
	try:
		modal_html = await _fetch_modal_color_size_html(
			client,
			base,
			p.stock_modal_gid,
			p.stock_modal_id or "",
			page_code,
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
		)
	except (_TRANSIENT_HTTP_ERRORS, httpx.HTTPStatusError):
		return

	raw = parse_color_size_modal_variants(modal_html, cfg)
	if len(raw) <= 1:
		_apply_modal_variant_stock(p, raw)
		return

	try:
		page_html = await fetch_text(
			client,
			p.product_url,
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
		)
	except (_TRANSIENT_HTTP_ERRORS, httpx.HTTPStatusError):
		page_html = ""

	offers = build_listing_variant_offers(p, modal_html, page_html, base, cfg)
	if offers:
		p.variant_offers = offers


async def _enrich_listing_stocks_via_modal(
	client: httpx.AsyncClient,
	base: str,
	cfg: dict[str, Any],
	prods: list[ListingProduct],
	page_code: str,
	*,
	delay: float,
	sem: asyncio.Semaphore,
	max_retries: int,
	retry_delay_sec: float,
) -> None:
	expand_variants = bool(cfg.get("listing_variant_expansion", True))
	variant_hubs = [
		p
		for p in prods
		if p.stock_modal_gid and p.stock_has_variants and expand_variants
	]
	simple = [
		p
		for p in prods
		if p.stock_modal_gid and p not in variant_hubs
	]

	if variant_hubs:
		await asyncio.gather(
			*[
				_enrich_listing_variant_hub(
					client,
					base,
					cfg,
					p,
					page_code,
					delay=delay,
					sem=sem,
					max_retries=max_retries,
					retry_delay_sec=retry_delay_sec,
				)
				for p in variant_hubs
			]
		)

	if not simple:
		return
	results = await asyncio.gather(
		*[
			_fetch_listing_stock_qty(
				client,
				base,
				cfg,
				p,
				page_code,
				delay=delay,
				sem=sem,
				max_retries=max_retries,
				retry_delay_sec=retry_delay_sec,
			)
			for p in simple
		]
	)
	for p, sq in zip(simple, results):
		if sq is not None:
			p.stock_mob_raw = f"{sq} шт."


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
	enrich_concurrency = int(cfg.get("listing_enrichment_concurrency", 0))
	if enrich_concurrency <= 0:
		enrich_concurrency = max(concurrency * 2, 12)
	enrich_sem = asyncio.Semaphore(enrich_concurrency)
	listing_delay = float(cfg.get("listing_request_delay_sec", delay))
	http2 = bool(cfg.get("http2", False))
	max_retries = int(cfg.get("http_max_retries", 5))
	retry_delay_sec = float(cfg.get("http_retry_delay_sec", 0.5))
	dd_listing = _detail_flag_after_listing(leave_detail_pending)
	listing_stock_modal = bool(cfg.get("listing_stock_modal_enabled", True))

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
		seed = sorted(
			{
				canonical_catalog_url(u)
				for u in all_cats
				if not is_excluded_category(u, excluded_prefixes, excluded_exact)
			}
		)
		if max_categories is not None and max_categories > 0:
			seed = seed[:max_categories]
		if progress:
			print(
				f"[pilotmoto] Фаза 1: полный BFS по ссылкам с {catalog_index} (стартовых URL: {len(seed)}).",
				flush=True,
			)

		all_categories = await _discover_all_category_urls(
			client,
			base,
			seed,
			excluded_prefixes,
			excluded_exact,
			delay=delay,
			sem=sem,
			max_retries=max_retries,
			retry_delay_sec=retry_delay_sec,
			progress=progress,
		)
		dbmod.meta_set(conn, "discovered_category_urls", str(len(all_categories)))
		if progress:
			print(
				f"[pilotmoto] Фаза 2: листинг по {len(all_categories)} URL (всегда sort=1&view_type=1&qflt=0; при стр.≥2 — page_num). "
				"В HTML формы «в наличии» часто помечено checked — это не значит, что парсер теряет qflt на стр. 2+. "
				"Дубликаты по product_url схлопываются.",
				flush=True,
			)

		t_cat = tqdm(all_categories, desc="Листинг категорий", disable=not progress, unit="cat")

		for cat_url in t_cat:
			short = urlparse(cat_url).path.strip("/").split("/")[-1][:24]
			t_cat.set_postfix_str(short)

			url_p1 = listing_page_url(cat_url, 1)
			html_first = await fetch_text(
				client,
				url_p1,
				delay=delay,
				sem=sem,
				max_retries=max_retries,
				retry_delay_sec=retry_delay_sec,
			)
			mx_html = parse_max_page_pilotmoto(html_first)
			meta = dbmod.get_category_meta(conn, cat_url)
			if meta is None:
				max_pg = max(1, mx_html)
				dbmod.set_category_meta(conn, cat_url, None, max_pg)
			else:
				_, old_max = meta
				max_pg = max(old_max, mx_html)
				if max_pg != old_max:
					dbmod.set_category_meta(conn, cat_url, None, max_pg)

			for pn in range(1, max_pg + 1):
				if progress:
					t_cat.set_postfix_str(f"{short} стр {pn}/{max_pg}")
					t_cat.refresh()
				if dbmod.is_page_done(conn, cat_url, pn):
					continue
				if pn == 1:
					html = html_first
				else:
					html = await fetch_text(
						client,
						listing_page_url(cat_url, pn),
						delay=delay,
						sem=sem,
						max_retries=max_retries,
						retry_delay_sec=retry_delay_sec,
					)
				prods, _pagen_param = parse_listing_page(html, cat_url)
				if listing_stock_modal and prods:
					page_code = listing_modal_page_code(html)
					await _enrich_listing_stocks_via_modal(
						client,
						base,
						cfg,
						prods,
						page_code,
						delay=listing_delay,
						sem=enrich_sem,
						max_retries=max_retries,
						retry_delay_sec=retry_delay_sec,
					)
				for p in prods:
					if p.variant_offers:
						for vo in p.variant_offers:
							pk_v = parse_kzt_from_price_raw(vo.price_raw)
							sl_v = vo.stock_label or (
								f"{vo.stock_qty} шт." if vo.stock_qty is not None else ""
							)
							sq_v = (
								vo.stock_qty
								if vo.stock_qty is not None
								else resolve_stock_qty(sl_v, "", None, cfg)
							)
							dbmod.upsert_product(
								conn,
								product_url=vo.product_url,
								bx_id=p.bx_id,
								name=p.name,
								manufacturer=p.manufacturer,
								article=vo.article,
								price_raw=vo.price_raw,
								category_url=cat_url,
								price_kzt=pk_v,
								stock_qty=sq_v,
								stock_label=sl_v,
								detail_done=dd_listing,
							)
						continue
					pk = parse_kzt_from_price_raw(p.price_raw)
					sq = resolve_stock_qty(p.stock_mob_raw, "", None, cfg)
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
						stock_label=p.stock_mob_raw,
						detail_done=dd_listing,
					)
				dbmod.mark_page_done(conn, cat_url, pn, len(prods))
				dbmod.commit_products(conn)
				if progress:
					st = dbmod.stats(conn)
					t_cat.set_postfix_str(
						f"{short} стр {pn}/{max_pg} | товаров={st['products']}"
					)
					t_cat.refresh()

			st = dbmod.stats(conn)
			t_cat.set_postfix_str(f"{short} | товаров={st['products']} стр={st['category_pages']}")

		dbmod.meta_set(conn, "last_category_count", str(len(all_categories)))
		dbmod.meta_set(conn, "finished_at", str(time.time()))


async def run_detail_enrichment(
	cfg: dict[str, Any],
	conn: Any,
	*,
	progress: bool = True,
	max_products: int | None = None,
) -> None:
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

	base = cfg["base_url"].rstrip("/")
	stock_modal_sum = bool(cfg.get("stock_modal_sum_enabled", True))

	urls = dbmod.product_urls_pending_detail(conn)
	if max_products is not None and max_products > 0:
		urls = urls[:max_products]
	total = len(urls)
	if progress:
		print(f"[pilotmoto] Карточек к обогащению (Бренд/Артикул/цена/остаток): {total}", flush=True)
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
				if stock_modal_sum:
					has_variants = product_has_color_size_variants(html)
					av_params = parse_modal_available_params(html)
					cs_params = parse_modal_color_size_params(html)
					gid = ""
					id_ = ""
					page_code = "002"
					if av_params:
						gid = av_params["gid"]
						id_ = av_params.get("id") or ""
						page_code = av_params.get("page") or page_code
					elif cs_params:
						gid = cs_params["gid"]
						page_code = cs_params.get("page") or page_code
					if gid:
						try:
							modal_sq = await _fetch_modal_stock_qty(
								client,
								base,
								cfg,
								gid,
								id_,
								page_code,
								has_variants,
								delay=delay,
								sem=sem,
								max_retries=max_retries,
								retry_delay_sec=retry_delay_sec,
							)
							if modal_sq is not None:
								sq = modal_sq
						except Exception:
							pass
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
					t_bar.write(f"[pilotmoto] пропуск (повтор при следующем запуске) {purl}: {e}\n")
				continue
			st = dbmod.stats(conn)
			t_bar.set_postfix_str(f"готово={st['products_detail_done']}/{st['products']}")

	dbmod.meta_set(conn, "detail_enrich_finished_at", str(time.time()))
