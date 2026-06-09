#!/usr/bin/env python3
"""Диагностика остатка одного SKU — как в крауле (листинг + ajax)."""

from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parent))

from pilotmoto_scraper.config import load_config
from pilotmoto_scraper.crawl import _fetch_modal_stock_qty, _merge_stock_qty
from pilotmoto_scraper.parse import (
	listing_modal_page_code,
	listing_page_url,
	listing_stock_modal_ajax_url,
	parse_listing_page,
	parse_product_detail,
	resolve_stock_qty,
	sum_stock_from_color_size_modal,
	sum_stock_from_modal_available_html,
)


async def main() -> int:
	ap = argparse.ArgumentParser()
	ap.add_argument("--article", default="HFF6012")
	ap.add_argument(
		"--category",
		default="https://pilotmoto.ru/catalog/zapchasti-i-tyuning/filtry/vozdushnyj-filtr-dorozhnyj/",
	)
	ap.add_argument("--config", "-c", default=None)
	args = ap.parse_args()

	cfg = load_config(args.config)
	headers = {
		"User-Agent": cfg.get("user_agent", "Mozilla/5.0"),
		"Accept-Language": "ru-RU,ru;q=0.9",
	}
	sem = asyncio.Semaphore(1)
	cat = listing_page_url(args.category.rstrip("/") + "/", 1)

	async with httpx.AsyncClient(
		headers=headers, timeout=45, follow_redirects=True
	) as client:
		print("=== listing ===")
		list_html = (await client.get(cat)).text
		page_code = listing_modal_page_code(list_html)
		prods, _ = parse_listing_page(list_html, cat)
		p = next((x for x in prods if x.article == args.article), None)
		if not p:
			print(f"article {args.article!r} not on page 1 of {cat}")
			return 1
		print("product_url", p.product_url)
		print("gid/id/variants", p.stock_modal_gid, p.stock_modal_id, p.stock_has_variants)
		print("listing stock_mob_raw", repr(p.stock_mob_raw))
		print("page_code", page_code)

		print("\n=== modal_available (raw) ===")
		u_av = listing_stock_modal_ajax_url(
			cfg["base_url"].rstrip("/"),
			p.stock_modal_gid or "",
			p.stock_modal_id or "",
			page_code,
			kind="modal_available",
		)
		html_av = (await client.get(u_av)).text
		print("url", u_av)
		print("len", len(html_av), "greens", html_av.count('span class="green"'), "reds", html_av.count('span class="red"'))
		sq_av = sum_stock_from_modal_available_html(html_av)
		print("sum_stock_from_modal_available_html", sq_av)
		if "span.green" in html_av or 'class="green"' in html_av:
			from bs4 import BeautifulSoup

			for span in BeautifulSoup(html_av, "html.parser").select("span.green"):
				print("  green text", repr(span.get_text(" ", strip=True)))

		print("\n=== modal_color_size (raw) ===")
		u_cs = listing_stock_modal_ajax_url(
			cfg["base_url"].rstrip("/"),
			p.stock_modal_gid or "",
			p.stock_modal_id or "",
			page_code,
			kind="modal_color_size",
		)
		html_cs = (await client.get(u_cs)).text
		print("len", len(html_cs))
		print("sum_stock_from_color_size_modal", sum_stock_from_color_size_modal(html_cs))

		print("\n=== crawl merge (_fetch_modal_stock_qty) ===")
		sq_modal = await _fetch_modal_stock_qty(
			client,
			cfg["base_url"].rstrip("/"),
			cfg,
			p.stock_modal_gid or "",
			p.stock_modal_id or "",
			page_code,
			p.stock_has_variants,
			delay=0.05,
			sem=sem,
			max_retries=5,
			retry_delay_sec=0.5,
		)
		text_sq = resolve_stock_qty(p.stock_mob_raw, "", None, cfg)
		print("listing text_sq", text_sq)
		print("modal merged", sq_modal)
		print("_merge_stock_qty", _merge_stock_qty(text_sq, sq_modal))

		print("\n=== product detail ===")
		d_html = (await client.get(p.product_url)).text
		d = parse_product_detail(d_html)
		sq_det = resolve_stock_qty(d.stock_label, d.stock_stack, d.availability, cfg)
		print("detail stock_label", repr(d.stock_label), "=>", sq_det)

	return 0


if __name__ == "__main__":
	raise SystemExit(asyncio.run(main()))
