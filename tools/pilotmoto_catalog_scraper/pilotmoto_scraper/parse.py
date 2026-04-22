from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import Any
from urllib.parse import parse_qsl, urlencode, urljoin, urlparse, urlunparse

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
	article: str  # сегмент URL после /item/ (например 93311-32261-00)
	price_raw: str
	stock_mob_raw: str  # колонка «Наличие»: p.for_list.hidden_mob


@dataclass
class ProductDetail:
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
	while "//" in path:
		path = path.replace("//", "/")
	if not path.startswith("/catalog/"):
		return None
	if "/item/" in path:
		return None
	# Корень /catalog/ — витрина разделов, не листинг товаров
	segments = [p for p in path.split("/") if p]
	if len(segments) < 2 or segments[0] != "catalog":
		return None
	if not path.endswith("/"):
		path += "/"
	return canonical_catalog_url(f"{urlparse(base).scheme}://{urlparse(base).netloc}{path}")


def canonical_catalog_url(url: str) -> str:
	"""Без query/fragment; слэш в конце пути; для ключей БД и обхода."""
	u = (url or "").strip()
	if not u:
		return u
	p = urlparse(u)
	if not p.scheme or not p.netloc:
		return u
	path = p.path or ""
	while "//" in path:
		path = path.replace("//", "/")
	if not path.endswith("/"):
		path += "/"
	return f"{p.scheme}://{p.netloc}{path}"


def listing_page_url(category_url: str, page_num: int) -> str:
	"""
	Сортировка и вид: sort=1&view_type=1 и page_num для стр. 2+.
	qflt=0 — «все» по наличию (не только «в наличии»); в форме сайта это radio value=0.
	"""
	cat = canonical_catalog_url(category_url)
	parsed = urlparse(cat)
	q = dict(parse_qsl(parsed.query, keep_blank_values=True))
	q["sort"] = "1"
	q["view_type"] = "1"
	q["qflt"] = "0"
	if page_num <= 1:
		q.pop("page_num", None)
	else:
		q["page_num"] = str(page_num)
	new_q = urlencode(sorted(q.items()))
	return urlunparse((parsed.scheme, parsed.netloc, parsed.path, parsed.params, new_q, parsed.fragment))


def collect_category_urls_from_html(base: str, html: str) -> list[str]:
	soup = _soup(html)
	out: set[str] = set()
	for a in soup.find_all("a", href=True):
		href = (a.get("href") or "").strip()
		if "/catalog/" not in href:
			continue
		u = normalize_catalog_url(base, href)
		if u:
			out.add(canonical_catalog_url(u))
	for m in re.finditer(
		r'(?:href|data-href)\s*=\s*["\']([^"\']*?/catalog/[^"\']*)["\']',
		html,
		re.I,
	):
		u = normalize_catalog_url(base, m.group(1).strip())
		if u:
			out.add(canonical_catalog_url(u))
	for m in re.finditer(r'((?:https?://[^/]+)?/catalog/[a-zA-Z0-9_\-./]+/?)', html):
		s = m.group(1)
		if "/item/" in s:
			continue
		u = normalize_catalog_url(base, s)
		if u:
			out.add(canonical_catalog_url(u))
	return sorted(out)


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


def _parse_item_id_from_url(href: str) -> int | None:
	m = re.search(r"/item/([^/]+)/?", href)
	if not m:
		return None
	s = m.group(1)
	if s.isdigit():
		try:
			return int(s)
		except ValueError:
			return None
	return None


def article_slug_from_item_href(href: str) -> str:
	"""Сегмент пути после /item/ — артикул в БД (часто буквенно-цифровой, не только Bitrix id)."""
	m = re.search(r"/item/([^/]+)/?", href)
	return (m.group(1).strip() if m else "") or ""


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


def parse_max_page_pilotmoto(html: str) -> int:
	"""
	Число страниц листинга: максимум из скрытого #pagescnt и всех ссылок с page_num=.
	На сайте иногда #pagescnt расходится с фактической пагинацией (например «… 103» в интерфейсе).
	"""
	soup = _soup(html)
	candidates: list[int] = []
	el = soup.select_one("#pagescnt")
	if el and el.get("value"):
		try:
			candidates.append(int(str(el["value"]).strip()))
		except ValueError:
			pass
	max_from_href = 1
	for a in soup.select("a[href*='page_num=']"):
		h = a.get("href") or ""
		m = re.search(r"page_num=(\d+)", h)
		if m:
			max_from_href = max(max_from_href, int(m.group(1)))
	candidates.append(max_from_href)
	return max(candidates) if candidates else 1


def _listing_product_from_card(
	base: str,
	href: str,
	name: str,
	price_raw: str,
	manufacturer: str,
	stock_mob_raw: str,
) -> ListingProduct | None:
	href = (href or "").strip()
	if "/item/" not in href:
		return None
	purl = urljoin(base, href)
	if not purl.endswith("/"):
		purl += "/"
	slug = article_slug_from_item_href(href)
	name_clean, article_from_title = split_name_article(name)
	# На листинге название без «Артикул:» — приоритет артикула из URL
	article = slug or article_from_title
	return ListingProduct(
		bx_id=_parse_item_id_from_url(href),
		product_url=purl,
		name=name_clean,
		manufacturer=(manufacturer or "").strip(),
		article=article,
		price_raw=price_raw or "",
		stock_mob_raw=(stock_mob_raw or "").replace("\xa0", " ").strip(),
	)


def parse_listing_page(html: str, category_url: str) -> tuple[list[ListingProduct], int | None]:
	"""
	Товары в div.block_with_img (odd/even): бренд p.for_list.hidden_tab, остаток p.for_list.hidden_mob,
	цена p.price, название h3.title a, ссылка на товар — как в заголовке, так и в обложке (тот же /item/…/ ).
	Артикул — сегмент URL после /item/. Дубликаты по product_url отбрасываются.
	"""
	soup = _soup(html)
	out: list[ListingProduct] = []
	seen_product: set[str] = set()
	base = f"{urlparse(category_url).scheme}://{urlparse(category_url).netloc}"

	for div in soup.select("div.block_with_img"):
		a_title = div.select_one("h3.title a[href*='/item/']")
		a_item = div.select_one("a[href*='/item/']")
		a = a_title or a_item
		if not a:
			continue
		href = (a.get("href") or "").strip()
		if a_title:
			raw_name = a_title.get_text(" ", strip=True)
		else:
			raw_name = ""
			t3 = div.select_one("h3.title")
			if t3:
				raw_name = t3.get_text(" ", strip=True)
		pr_el = div.select_one("p.price")
		price_raw = pr_el.get_text(" ", strip=True) if pr_el else ""
		brand_el = div.select_one("p.for_list.hidden_tab")
		manufacturer = brand_el.get_text(" ", strip=True) if brand_el else ""
		mob_el = div.select_one("p.for_list.hidden_mob")
		stock_mob = mob_el.get_text(" ", strip=True) if mob_el else ""
		lp = _listing_product_from_card(
			base, href, raw_name, price_raw, manufacturer, stock_mob
		)
		if lp and lp.product_url not in seen_product:
			seen_product.add(lp.product_url)
			out.append(lp)

	if not out:
		for a in soup.select('h3.title a[href*="/item/"]'):
			href = (a.get("href") or "").strip()
			raw_name = a.get_text(" ", strip=True)
			lp = _listing_product_from_card(base, href, raw_name, "", "", "")
			if lp and lp.product_url not in seen_product:
				seen_product.add(lp.product_url)
				out.append(lp)

	return out, None


def _parse_ld_json_product_offers(html: str) -> tuple[float | None, str | None]:
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
		try:
			pk = float(price) if price is not None and str(price).strip() != "" else None
		except (TypeError, ValueError):
			pk = None
		avail = off.get("availability")
		av = avail if isinstance(avail, str) else None
		return pk, av
	return None, None


def _parse_price_rub_from_detail(html: str) -> float | None:
	soup = _soup(html)
	el = soup.select_one(".product-price-new, .product-price .product-price-new")
	if not el:
		el = soup.select_one(".product-price")
	if not el:
		return None
	t = el.get_text(" ", strip=True)
	digits = re.sub(r"[^\d]", "", t.replace("\xa0", " "))
	if not digits:
		return None
	try:
		v = int(digits)
		return float(v) if v > 0 else None
	except ValueError:
		return None


def _parse_stock_from_detail(html: str) -> tuple[str, str]:
	soup = _soup(html)
	label = ""
	for block in soup.select(".product-specifics .spec-wrapper"):
		t = block.get_text(" ", strip=True)
		if re.search(r"Наличие\s*:", t, re.I):
			m = re.search(r"Наличие\s*:\s*(.+)$", t, re.I)
			if m:
				label = m.group(1).strip()
				break
	if not label:
		for block in soup.select(".product-sizes-wrapper, .product-block-wrapper-bottom"):
			t = block.get_text(" ", strip=True)
			m = re.search(r"Наличие:\s*([^<\n]+?)(?:\s{2,}|$)", t)
			if m:
				label = m.group(1).strip()
				break
	if not label:
		t = soup.get_text(" ", strip=True)
		m = re.search(r"Наличие:\s*([^\n]+?)(?:Размер|Цена|Другие)", t)
		if m:
			label = m.group(1).strip()
	return label, ""


def parse_product_detail_brand_article(html: str) -> tuple[str, str]:
	d = parse_product_detail(html)
	return d.manufacturer, d.article


def parse_product_detail(html: str) -> ProductDetail:
	brand = ""
	article = ""
	soup = _soup(html)
	for block in soup.select(".product-specifics .spec-wrapper"):
		spans = block.find_all("span")
		if len(spans) < 2:
			continue
		label = spans[0].get_text(" ", strip=True).rstrip(":").strip()
		value = spans[1].get_text(" ", strip=True)
		if label == "Бренд" and value:
			brand = value
		if label == "Артикул" and value:
			article = value

	if not article:
		art = soup.select_one(".product-articul .articul-data span")
		if art:
			article = art.get_text(" ", strip=True)

	pk, av = _parse_ld_json_product_offers(html)
	if pk is None:
		pk = _parse_price_rub_from_detail(html)
	sl, stk = _parse_stock_from_detail(html)
	return ProductDetail(
		manufacturer=brand,
		article=article,
		price_kzt=pk,
		availability=av,
		stock_label=sl,
		stock_stack=stk,
	)


def parse_modal_available_params(html: str) -> dict[str, str] | None:
	"""Параметры для ajax.php?mode=load_modal&ckmod=modal_available (из #modal_available)."""
	soup = _soup(html)
	el = soup.select_one("#modal_available")
	if not el:
		return None
	gid = (el.get("data-gid") or "").strip()
	id_ = (el.get("data-id") or "").strip()
	page = (el.get("data-page") or "").strip()
	if not gid or not id_ or not page:
		return None
	ld = (el.get("data-ld") or "0").strip() or "0"
	return {"gid": gid, "id": id_, "page": page, "ld": ld}


def modal_available_ajax_url(base_url: str, params: dict[str, str]) -> str:
	base = (base_url or "").rstrip("/")
	q = urlencode(
		(
			("mode", "load_modal"),
			("ckmod", "modal_available"),
			("gid", params["gid"]),
			("id", params["id"]),
			("page", params["page"]),
			("ld", params.get("ld", "0")),
		)
	)
	return f"{base}/ajax.php?{q}"


def sum_stock_from_modal_available_html(html: str) -> int | None:
	"""Сумма «N шт.» из модалки «Проверить наличие в магазинах» (ответ load_modal)."""
	soup = _soup(html)
	ul = soup.select_one("ul#shops, ul.modal__available__ul")
	if not ul:
		return None
	total = 0
	for span in ul.select("span.green"):
		t = span.get_text(" ", strip=True).replace("\xa0", " ")
		m = re.search(r"(\d+)\s*шт", t, re.I)
		if m:
			total += int(m.group(1))
	return total


def resolve_stock_qty(
	stock_label: str,
	stock_stack: str,
	availability: str | None,
	cfg: dict[str, Any],
) -> int | None:
	label = (stock_label or "").replace("\xa0", " ").strip()
	text_map = cfg.get("stock_text_map") or {}
	if label in text_map:
		v = text_map[label]
		return int(v) if v is not None else None

	m = re.search(r"(\d+)\s*шт", label, re.I)
	if m:
		return int(m.group(1))

	m = re.search(r"(\d+)\s*\+", label)
	if m:
		return int(m.group(1))

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
		return parse_max_page_pilotmoto(html)
	soup = _soup(html)
	max_n = 1
	for a in soup.select("a[href]"):
		h = a.get("href") or ""
		m = re.search(rf"PAGEN_{pagen_param}=(\d+)", h)
		if m:
			max_n = max(max_n, int(m.group(1)))
	return max_n
