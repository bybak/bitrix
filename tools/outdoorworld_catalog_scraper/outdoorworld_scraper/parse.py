from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import Any
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

def _soup(html: str) -> BeautifulSoup:
	try:
		return BeautifulSoup(html, "lxml")
	except Exception:
		return BeautifulSoup(html, "html.parser")


@dataclass
class ListingProduct:
	bx_id: int | None
	product_url: str
	name: str
	manufacturer: str
	article: str
	price_raw: str
	stock_label: str
	stock_stack: str
	# опционально data-quan с плитки (атрибут у .i_buy_succes), см. listing_prefer_data_quan
	data_quan: int | None = None


@dataclass
class ProductDetail:
	"""Карточка /product/.../: бренд, артикул, цена KZT из JSON-LD, остаток (текст/класс)."""

	manufacturer: str
	article: str
	price_kzt: float | None
	availability: str | None
	stock_label: str
	stock_stack: str


def normalize_catalog_url(base: str, href: str) -> str | None:
	if not href or href.startswith("#"):
		return None
	u = urljoin(base, href)
	p = urlparse(u)
	if p.netloc and p.netloc != urlparse(base).netloc:
		return None
	path = p.path or ""
	if not path.startswith("/catalog/"):
		return None
	if path.count("/") < 2:
		return None
	# нормализуем trailing slash
	if not path.endswith("/"):
		path += "/"
	return f"{urlparse(base).scheme}://{urlparse(base).netloc}{path}"


def collect_category_urls_from_html(base: str, html: str) -> list[str]:
	soup = _soup(html)
	out: list[str] = []
	for a in soup.select('a[href^="/catalog/"]'):
		href = a.get("href") or ""
		u = normalize_catalog_url(base, href)
		if u:
			out.append(u)
	return sorted(set(out))


def is_excluded_category(url: str, excluded_prefixes: list[str], excluded_exact: list[str]) -> bool:
	path = urlparse(url).path
	if not path.endswith("/"):
		path += "/"
	if path in excluded_exact:
		return True
	for pref in excluded_prefixes:
		if path.startswith(pref.rstrip("/") + "/") or path == pref:
			return True
	return False


def _parse_bx_id(div_id: str) -> int | None:
	# bx_3966226736_172245
	m = re.search(r"_(\d+)\s*$", div_id or "")
	if not m:
		return None
	return int(m.group(1))


def _parse_listing_item_brand_article(div) -> tuple[str, str]:
	"""Блок .i_info_block: пары .i_dp_name / .i_dp_val (Артикул, Бренд)."""
	brand = ""
	article = ""
	for block in div.select(".i_info_block .i_dp_props"):
		name_el = block.select_one(".i_dp_name")
		val_el = block.select_one(".i_dp_val")
		if not name_el or not val_el:
			continue
		k = name_el.get_text(" ", strip=True).rstrip(":")
		v = val_el.get_text(" ", strip=True)
		if k == "Бренд" and v:
			brand = v
		if k == "Артикул" and v:
			article = v.strip()
	return brand, article


def _parse_listing_item_stock(div) -> tuple[str, str]:
	"""
	Остаток на плитке: div.i_quantity внутри .i_item (подпись .i_quan_text, класс *_stack на span.i_quan_sl).
	"""
	el = div.select_one("div.i_quantity")
	if not el:
		return "", ""
	span_sl = el.select_one("span.i_quan_sl")
	stack = ""
	if span_sl:
		for c in span_sl.get("class") or []:
			if isinstance(c, str) and c.endswith("_stack"):
				stack = c
				break
	qt = el.select_one("span.i_quan_text")
	if qt:
		label = qt.get_text(" ", strip=True)
	else:
		inner = el.select_one("span.i_quan_tx")
		label = inner.get_text(" ", strip=True) if inner else el.get_text(" ", strip=True)
	return label, stack


def _listing_data_quan(div) -> int | None:
	el = div.select_one(".i_buy_succes[data-quan], .jq_buy_succes[data-quan]")
	if not el:
		return None
	raw = el.get("data-quan") or el.attrs.get("data-quan")
	if raw is None or str(raw).strip() == "":
		return None
	try:
		n = int(str(raw).strip())
		return n if n >= 0 else None
	except ValueError:
		return None


def split_name_article(raw: str) -> tuple[str, str]:
	s = (raw or "").replace("\xa0", " ").strip()
	if "Артикул:" in s:
		a, b = s.split("Артикул:", 1)
		return a.strip(), b.strip()
	if "артикул:" in s.lower():
		idx = s.lower().index("артикул:")
		a = s[:idx]
		b = s[idx + len("артикул:") :]
		return a.strip(), b.strip()
	return s, ""


def parse_listing_page(html: str, category_url: str) -> tuple[list[ListingProduct], int | None]:
	"""Товары с плитки .i_item: цена .i_price, бренд/артикул .i_info_block, остаток .i_quantity, имя без строки «Артикул:»."""
	soup = _soup(html)
	out: list[ListingProduct] = []
	base = f"{urlparse(category_url).scheme}://{urlparse(category_url).netloc}"
	for div in soup.select(".i_item.jq_item"):
		div_id = div.get("id") or ""
		bx = _parse_bx_id(div_id)
		a = div.select_one("a.i_item_name")
		if not a:
			continue
		href = (a.get("href") or "").strip()
		if not href.startswith("/product/"):
			continue
		purl = urljoin(base, href)
		if not purl.endswith("/"):
			purl += "/"
		name_sp = a.select_one("span:not(.i_article_item)")
		if name_sp:
			name = name_sp.get_text(" ", strip=True)
		else:
			name = ""
		manufacturer, article = _parse_listing_item_brand_article(div)
		if not article:
			art_line = a.select_one("span.i_article_item")
			if art_line:
				m = re.search(
					r"Артикул\s*:\s*(\S+)", art_line.get_text(" ", strip=True), re.I
				)
				if m:
					article = m.group(1).strip()
		if not name:
			raw_name = a.get_text(" ", strip=True)
			name, _ = split_name_article(raw_name)
		if not article:
			_, article = split_name_article(a.get_text(" ", strip=True))
		pr_el = div.select_one(".i_price")
		price_raw = pr_el.get_text(" ", strip=True) if pr_el else ""
		sl, stk = _parse_listing_item_stock(div)
		dq = _listing_data_quan(div)
		out.append(
			ListingProduct(
				bx_id=bx,
				product_url=purl,
				name=name,
				manufacturer=manufacturer,
				article=article,
				price_raw=price_raw,
				stock_label=sl,
				stock_stack=stk,
				data_quan=dq,
			)
		)

	pagen_param = None
	for a in soup.select('a[href*="PAGEN_"]'):
		h = a.get("href") or ""
		m = re.search(r"PAGEN_(\d+)=", h)
		if m:
			pagen_param = int(m.group(1))
			break
	# fallback: из текущей страницы в canonical
	can = soup.select_one('link[rel="canonical"]')
	if can and can.get("href") and pagen_param is None:
		m = re.search(r"PAGEN_(\d+)=", can["href"])
		if m:
			pagen_param = int(m.group(1))
	return out, pagen_param


def parse_product_detail_brand_article(html: str) -> tuple[str, str]:
	"""
	Блок «Характеристики» на карточке товара: строки .i_cele_property (в т.ч. в скрытом jq_cele_property_hide).
	«Бренд» → производитель для CSV; «Артикул» → артикул с детальной (точнее витрины).
	"""
	d = parse_product_detail(html)
	return d.manufacturer, d.article


def _parse_ld_json_product_offers(html: str) -> tuple[float | None, str | None]:
	"""schema.org Product: offers.price (KZT), offers.availability (URL)."""
	soup = _soup(html)
	for script in soup.find_all("script", attrs={"type": "application/ld+json"}):
		raw = (script.string or "").strip()
		if not raw:
			continue
		try:
			data = json.loads(raw)
		except json.JSONDecodeError:
			continue
		if not isinstance(data, dict) or data.get("@type") != "Product":
			continue
		off = data.get("offers")
		if isinstance(off, list) and off:
			off = off[0]
		if not isinstance(off, dict):
			continue
		price = off.get("price")
		pk: float | None
		try:
			pk = float(price) if price is not None and str(price).strip() != "" else None
		except (TypeError, ValueError):
			pk = None
		avail = off.get("availability")
		av = avail if isinstance(avail, str) else None
		return pk, av
	return None, None


def _parse_main_product_stock(html: str) -> tuple[str, str]:
	"""
	Остаток на карточке: основной блок div.i_quantity без i_quan_sl (не «подобные товары»).
	Текст («Очень мало», «Нет в наличии») и класс уровня (*_stack) на span.i_quan_sl.
	"""
	soup = _soup(html)
	el = soup.select_one("div.i_quantity:not(.i_quan_sl)")
	if not el:
		return "", ""
	span_sl = el.select_one("span.i_quan_sl")
	stack = ""
	if span_sl:
		for c in span_sl.get("class") or []:
			if isinstance(c, str) and c.endswith("_stack"):
				stack = c
				break
	inner = el.select_one("span.i_quan_tx")
	label = inner.get_text(" ", strip=True) if inner else el.get_text(" ", strip=True)
	return label, stack


def parse_product_detail(html: str) -> ProductDetail:
	brand = ""
	article = ""
	soup = _soup(html)
	for block in soup.select(".i_cele_property_block"):
		for row in block.select(".i_cele_property"):
			cols = row.select(".i_cele_property_col")
			if len(cols) < 2:
				continue
			label = cols[0].get_text(" ", strip=True)
			value = cols[1].get_text(" ", strip=True)
			if label == "Бренд" and value:
				brand = value
			if label == "Артикул" and value:
				article = value
	pk, av = _parse_ld_json_product_offers(html)
	sl, stk = _parse_main_product_stock(html)
	return ProductDetail(
		manufacturer=brand,
		article=article,
		price_kzt=pk,
		availability=av,
		stock_label=sl,
		stock_stack=stk,
	)


def resolve_stock_qty(
	stock_label: str,
	stock_stack: str,
	availability: str | None,
	cfg: dict[str, Any],
) -> int | None:
	"""
	Числовой остаток для stock_and_price CSV: на сайте нет точного количества в разметке;
	берём цифры из текста, маппинг из config.yaml, иначе schema.org InStock/OutOfStock.
	"""
	label = (stock_label or "").replace("\xa0", " ").strip()
	text_map = cfg.get("stock_text_map") or {}
	if label in text_map:
		v = text_map[label]
		return int(v) if v is not None else None

	m = re.search(r"(?<!\d)(\d{1,7})(?!\d)", label)
	if m:
		return int(m.group(1))

	stack_map = cfg.get("stock_stack_map") or {}
	if stock_stack and stock_stack in stack_map:
		v = stack_map[stock_stack]
		return int(v) if v is not None else None

	if availability:
		if "OutOfStock" in availability or "Discontinued" in availability:
			return 0
		if "InStock" in availability or "LimitedAvailability" in availability or "PreOrder" in availability:
			d = cfg.get("stock_default_instock")
			if d is not None:
				return int(d)

	return None


def max_pagination_page(html: str, pagen_param: int | None) -> int:
	if pagen_param is None:
		return 1
	soup = _soup(html)
	max_n = 1
	for a in soup.select("a[href]"):
		h = a.get("href") or ""
		m = re.search(rf"PAGEN_{pagen_param}=(\d+)", h)
		if m:
			max_n = max(max_n, int(m.group(1)))
	return max_n
