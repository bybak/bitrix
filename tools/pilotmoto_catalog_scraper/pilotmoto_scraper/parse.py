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
class ListingVariantOffer:
	"""Один SKU из модалки «размеры/цвета» или «количество зубьев» (отдельный артикул/URL)."""
	product_url: str
	article: str
	stock_qty: int | None
	stock_label: str
	price_raw: str
	sku_id: str
	variant_label: str


@dataclass
class ListingProduct:
	bx_id: int | None
	product_url: str
	name: str
	manufacturer: str
	article: str  # сегмент URL после /item/ (например 93311-32261-00)
	price_raw: str
	stock_mob_raw: str  # колонка «Наличие»: p.for_list.hidden_mob
	# AJAX «наличие в магазинах» / «размеры и цвета» с листинга (без захода в карточку)
	stock_modal_gid: str | None = None
	stock_modal_id: str | None = None
	# Кнопка getColorSizeTable — несколько SKU; остаток брать из modal_color_size (сумма вариантов)
	stock_has_variants: bool = False
	# Несколько SKU (зубья/размеры): отдельные строки вместо одной карточки-хаба
	variant_offers: list[ListingVariantOffer] | None = None


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


def _normalize_article_slug(slug: str) -> str:
	"""Сегмент URL с буквами (hf-111) — в верхнем регистре, как «Артикул» на карточке."""
	slug = (slug or "").strip()
	if not slug or not re.search(r"[A-Za-zА-Яа-я]", slug):
		return slug
	return slug.upper()


def resolve_listing_article(
	article_override: str,
	slug: str,
	article_from_title: str = "",
) -> str:
	"""
	Артикул для CSV/матчинга с Bitrix.
	На листинге .card-articul часто «Арт.: 5414» (внутренний id), а в URL /item/hff6012/ — реальный артикул.
	"""
	article_override = (article_override or "").strip()
	slug = (slug or "").strip()
	article_from_title = (article_from_title or "").strip()

	# card-articul «Арт.: …» на листинге — часто внутренний id (5414, 1685541307),
	# реальный артикул — в сегменте URL /item/…/ (hff6012 или 112088).
	if slug and article_override.isdigit() and slug != article_override:
		return _normalize_article_slug(slug)

	if article_override:
		return article_override
	if slug:
		return _normalize_article_slug(slug)
	return article_from_title


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


def _canonical_product_url(base: str, href: str) -> str | None:
	href = (href or "").strip()
	if "/item/" not in href:
		return None
	purl = urljoin(base, href)
	parsed = urlparse(purl)
	path = parsed.path or ""
	while "//" in path:
		path = path.replace("//", "/")
	if not path.endswith("/"):
		path += "/"
	return f"{parsed.scheme}://{parsed.netloc}{path}"


def _brand_from_card_specifics(card) -> str:
	for block in card.select(".card-specifics .spec-wrapper"):
		spans = block.find_all("span")
		if len(spans) < 2:
			continue
		label = spans[0].get_text(" ", strip=True).rstrip(":").strip()
		if label.lower() == "бренд":
			return spans[1].get_text(" ", strip=True)
	return ""


def _article_from_card(card) -> str:
	art_el = card.select_one(".card-articul")
	if not art_el:
		return ""
	text = art_el.get_text(" ", strip=True).replace("\xa0", " ")
	m = re.match(r"^арт\.?\s*:?\s*(.+)$", text, re.I)
	return (m.group(1).strip() if m else text.strip())


def _stock_hint_from_card(card) -> str:
	"""Текстовый остаток с листинга (старая вёрстка hidden_mob или блок «Наличие» на плитке)."""
	mob = card.select_one("p.for_list.hidden_mob")
	if mob:
		return mob.get_text(" ", strip=True)
	for block in card.select(".card-specifics .spec-wrapper"):
		spans = block.find_all("span")
		if len(spans) < 2:
			continue
		label = spans[0].get_text(" ", strip=True).rstrip(":").strip()
		if label.lower() == "наличие":
			return spans[1].get_text(" ", strip=True)
	text = card.get_text(" ", strip=True).replace("\xa0", " ")
	if re.search(r"нет\s+в\s+наличии", text, re.I):
		return "Нет в наличии"
	return ""


def _stock_modal_ids_from_card(card) -> tuple[str | None, str | None, bool]:
	"""
	gid/id для ajax.php load_modal с листинга:
	- getColorSizeTable(N) — варианты (размер/цвет): gid=N, id из .card-favorite (SKU для modal_available);
	- одиночный SKU (add_basket без getColorSizeTable) → gid=id из .card-favorite.
	"""
	fav = card.select_one(".card-favorite")
	fav_id = (fav.get("data-id") or "").strip() if fav else ""
	btn = card.select_one('button[onclick*="getColorSizeTable"]')
	if btn:
		m = re.search(r"getColorSizeTable\((\d+)\)", btn.get("onclick") or "")
		if m:
			return m.group(1), fav_id, True
	if fav_id:
		return fav_id, fav_id, False
	return None, None, False


def listing_modal_page_code(html: str) -> str:
	el = _soup(html).select_one("#blockItemsNd")
	if el and el.get("data-page"):
		return str(el["data-page"]).strip()
	return "002"


def listing_stock_modal_ajax_url(
	base_url: str,
	gid: str,
	id_: str,
	page: str,
	*,
	kind: str = "modal_available",
) -> str:
	base = (base_url or "").rstrip("/")
	ckmod = "modal_available" if kind == "modal_available" else "modal_color_size"
	q = urlencode(
		(
			("mode", "load_modal"),
			("ckmod", ckmod),
			("gid", gid),
			("id", id_ or ""),
			("page", page),
			("ld", "0"),
		)
	)
	return f"{base}/ajax.php?{q}"


def _listing_product_from_card(
	base: str,
	href: str,
	name: str,
	price_raw: str,
	manufacturer: str,
	stock_mob_raw: str,
	*,
	article_override: str = "",
) -> ListingProduct | None:
	purl = _canonical_product_url(base, href)
	if not purl:
		return None
	slug = article_slug_from_item_href(href)
	name_clean, article_from_title = split_name_article(name)
	article = resolve_listing_article(article_override, slug, article_from_title)
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
	Листинг pilotmoto.ru:
	- новая вёрстка: div.card-nd (card-title, card-articul, card-price, card-specifics);
	- старая: div.block_with_img (h3.title, p.price, p.for_list.*).
	Артикул — из card-articul или сегмент URL после /item/. Дубликаты по product_url отбрасываются.
	"""
	soup = _soup(html)
	out: list[ListingProduct] = []
	seen_product: set[str] = set()
	base = f"{urlparse(category_url).scheme}://{urlparse(category_url).netloc}"

	for card in soup.select("div.card-nd"):
		a_title = card.select_one(".card-title a[href*='/item/']")
		a_img = card.select_one("a.card-image-wrapper[href*='/item/']")
		a = a_title or a_img
		if not a:
			continue
		href = (a.get("href") or "").strip()
		raw_name = a_title.get_text(" ", strip=True) if a_title else ""
		pr_el = card.select_one(".card-price .price-new, .card-price-for-list .price-new, .card-price .price-new")
		price_raw = pr_el.get_text(" ", strip=True) if pr_el else ""
		manufacturer = _brand_from_card_specifics(card)
		stock_mob = _stock_hint_from_card(card)
		modal_gid, modal_id, has_variants = _stock_modal_ids_from_card(card)
		lp = _listing_product_from_card(
			base,
			href,
			raw_name,
			price_raw,
			manufacturer,
			stock_mob,
			article_override=_article_from_card(card),
		)
		if lp:
			lp.stock_modal_gid = modal_gid
			lp.stock_modal_id = modal_id
			lp.stock_has_variants = has_variants
		if lp and lp.product_url not in seen_product:
			seen_product.add(lp.product_url)
			out.append(lp)

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
		for a in soup.select(
			'.card-title a[href*="/item/"], h3.title a[href*="/item/"]'
		):
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


def _parse_availability_label_from_soup(soup: BeautifulSoup) -> str:
	"""«10+», «Нет в наличии» — без текста кнопок и блока «Количество зубьев»."""
	wrap = soup.select_one(".product-sizes-wrapper")
	if wrap:
		for sp in wrap.find_all("span"):
			if re.search(r"наличие", sp.get_text(" ", strip=True), re.I):
				parent = sp.parent
				if parent:
					m = re.search(r"Наличие\s*:\s*(.+?)$", parent.get_text(" ", strip=True), re.I)
					if m:
						return m.group(1).strip()
	for block in soup.select(".product-specifics .spec-wrapper"):
		spans = block.find_all("span")
		if len(spans) < 2:
			continue
		label = spans[0].get_text(" ", strip=True).rstrip(":").strip()
		if label.lower() == "наличие":
			return spans[1].get_text(" ", strip=True).strip()
	t = soup.get_text(" ", strip=True)
	m = re.search(r"Наличие:\s*([^\n]+?)(?:Размер|Цена|Другие|Количество)", t)
	if m:
		return m.group(1).strip()
	return ""


def _parse_stock_from_detail(html: str) -> tuple[str, str]:
	return _parse_availability_label_from_soup(_soup(html)), ""


def _variant_url_for_label(url_by_label: dict[str, str], variant_label: str) -> str | None:
	"""
	Сопоставление подписи из модалки (50, 320; Правый) со ссылкой на карточке.
	Составные подписи разбираем по «;» — иначе «320» из диаметра перебивает «Правый».
	"""
	label = (variant_label or "").strip()
	if not label:
		return None
	if label in url_by_label:
		return url_by_label[label]
	for k, v in url_by_label.items():
		if k.strip() == label:
			return v
	parts = [p.strip() for p in re.split(r"[;,]", label) if p.strip()]
	for part in parts:
		if part in url_by_label:
			return url_by_label[part]
		for k, v in url_by_label.items():
			if k.strip() == part:
				return v
	if re.fullmatch(r"\d+", label):
		for k, v in url_by_label.items():
			if k.strip() == label:
				return v
	return None


def parse_product_variant_url_by_label(
	html: str,
	base: str,
	hub_url: str = "",
) -> dict[str, str]:
	"""
	Ссылки на отдельные карточки SKU с блоков параметров на хаб-странице
	(зубья, диаметр, расположение и т.п.).
	Ключ — подпись варианта (например «50», «Правый»), значение — canonical product URL.
	Ссылка на сам хаб (тот же /item/…) не включается.
	"""
	soup = _soup(html)
	hub_canon = _canonical_product_url(base, hub_url) if hub_url else ""
	hub_slug = article_slug_from_item_href(hub_url) if hub_url else ""
	out: dict[str, str] = {}
	for param in soup.select(
		".product-apars-wrapper .product-param, .product-params-nd .product-param"
	):
		a = param.select_one('a[href*="/item/"]')
		if not a:
			continue
		label = (a.get("title") or "").strip() or param.get_text(" ", strip=True)
		if not label:
			continue
		href = (a.get("href") or "").strip()
		url = _canonical_product_url(base, href)
		if not url:
			continue
		if hub_canon and url.rstrip("/") == hub_canon.rstrip("/"):
			continue
		slug = article_slug_from_item_href(url)
		if hub_slug and slug == hub_slug:
			continue
		out[label] = url
	return out


def parse_color_size_modal_variants(
	modal_html: str,
	cfg: dict[str, Any],
) -> list[dict[str, Any]]:
	"""
	Строки из ответа modal_color_size: каждый .cs-item — отдельный SKU (data-id, зубья/размер).
	"""
	soup = _soup(modal_html)
	out: list[dict[str, Any]] = []
	for item in soup.select(".cs-item"):
		sku_id = (item.get("data-id") or "").strip()
		if not sku_id:
			continue
		label = ""
		hd = item.select_one(".hidden_desc")
		if hd:
			label = hd.get_text(" ", strip=True)
		qnt_el = item.select_one("p.cs-qnt")
		stock_label = qnt_el.get_text(" ", strip=True).replace("\xa0", " ") if qnt_el else ""
		inp = item.select_one('input[name^="gcnt"]')
		price_raw = ""
		if inp and inp.get("data-pr"):
			price_raw = str(inp.get("data-pr")).strip() + " ₽"
		stock_qty = resolve_stock_qty(stock_label, "", None, cfg)
		if stock_qty is None and "red" in (item.get("class") or []):
			stock_qty = 0
		out.append({
			"sku_id": sku_id,
			"variant_label": label,
			"stock_label": stock_label,
			"stock_qty": stock_qty,
			"price_raw": price_raw,
		})
	return out


def build_listing_variant_offers(
	hub: ListingProduct,
	modal_html: str,
	product_page_html: str,
	base: str,
	cfg: dict[str, Any],
) -> list[ListingVariantOffer] | None:
	"""
	Если в модалке несколько SKU — отдельные предложения с URL/остатком.
	Артикул и URL — только из ссылок на хаб-странице (сегмент /item/…/).
	Варианты без отдельной ссылки на хабе пропускаются; синтетические артикулы не создаём.
	"""
	raw_variants = parse_color_size_modal_variants(modal_html, cfg)
	if len(raw_variants) <= 1:
		return None

	url_by_label = parse_product_variant_url_by_label(
		product_page_html, base, hub.product_url
	)
	if not url_by_label:
		return None

	hub_slug = article_slug_from_item_href(hub.product_url)
	offers: list[ListingVariantOffer] = []
	for v in raw_variants:
		label = (v.get("variant_label") or "").strip()
		sku_id = (v.get("sku_id") or "").strip()
		purl = _variant_url_for_label(url_by_label, label)
		if not purl:
			continue
		article = article_slug_from_item_href(purl)
		if not article or article == hub_slug:
			continue
		price_raw = (v.get("price_raw") or "").strip() or hub.price_raw
		offers.append(
			ListingVariantOffer(
				product_url=purl,
				article=article,
				stock_qty=v.get("stock_qty"),
				stock_label=(v.get("stock_label") or "").strip(),
				price_raw=price_raw,
				sku_id=sku_id,
				variant_label=label,
			)
		)
	return offers if offers else None


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
	if not article:
		for block in soup.select(".product-specifics .spec-wrapper"):
			spans = block.find_all("span")
			if len(spans) < 2:
				continue
			lbl = spans[0].get_text(" ", strip=True).rstrip(":").strip().lower()
			if lbl == "артикул":
				article = spans[1].get_text(" ", strip=True)
				break
	if not article:
		canon = soup.select_one('link[rel="canonical"]')
		if canon:
			article = article_slug_from_item_href(canon.get("href") or "")

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


def product_has_color_size_variants(html: str) -> bool:
	"""На карточке или в листинге есть выбор размера/цвета (getColorSizeTable)."""
	if re.search(r"getColorSizeTable\s*\(\s*\d+\s*\)", html):
		return True
	el = _soup(html).select_one("#modal_color_size")
	return bool(el and (el.get("data-gid") or "").strip())


def parse_modal_color_size_params(html: str) -> dict[str, str] | None:
	"""Параметры для ajax.php?ckmod=modal_color_size (из #modal_color_size на странице)."""
	soup = _soup(html)
	el = soup.select_one("#modal_color_size")
	if not el:
		return None
	gid = (el.get("data-gid") or "").strip()
	if not gid:
		return None
	page = (el.get("data-page") or "").strip()
	if not page:
		av = soup.select_one("#modal_available")
		if av and (av.get("data-page") or "").strip():
			page = str(av.get("data-page")).strip()
	if not page:
		block = soup.select_one("#blockItemsNd")
		if block and (block.get("data-page") or "").strip():
			page = str(block.get("data-page")).strip()
	if not page:
		page = "002"
	ld = (el.get("data-ld") or "0").strip() or "0"
	return {"gid": gid, "id": "", "page": page, "ld": ld}


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


def _qty_from_green_span_text(t: str) -> int | None:
	"""Число из span.green: «2 шт.», «2 &nbspшт.» (битый entity на pilotmoto), «10+»."""
	t = (t or "").replace("\xa0", " ")
	t = re.sub(r"&nbsp;?", " ", t, flags=re.I)
	t = re.sub(r"\s+", " ", t).strip()
	m = re.search(r"(\d+)\s*шт", t, re.I)
	if m:
		return int(m.group(1))
	m = re.search(r"(\d+)\s*\+", t)
	if m:
		return int(m.group(1))
	m = re.match(r"^(\d+)$", t)
	if m:
		return int(m.group(1))
	return None


def sum_stock_from_modal_available_html(html: str) -> int | None:
	"""Сумма «N шт.» из модалки «Проверить наличие в магазинах» (ответ load_modal)."""
	soup = _soup(html)
	ul = soup.select_one("ul#shops, ul.modal__available__ul")
	if not ul:
		return None
	total = 0
	found_green = False
	for span in ul.select("span.green"):
		q = _qty_from_green_span_text(span.get_text(" ", strip=True))
		if q is not None:
			total += q
			found_green = True
	if found_green:
		return total
	if ul.select("span.red"):
		return 0
	return None


def sum_stock_from_color_size_modal(html: str) -> int | None:
	"""Сумма по p.cs-qnt в модалке «Размеры и цвета» (запасной вариант, без max=1000000)."""
	soup = _soup(html)
	total = 0
	found = False
	for p in soup.select("p.cs-qnt"):
		t = p.get_text(" ", strip=True).replace("\xa0", " ")
		m = re.search(r"(\d+)\s*шт", t, re.I)
		if m:
			total += int(m.group(1))
			found = True
		elif re.search(r"10\s*\+", t):
			total += 10
			found = True
	return total if found else None


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

	m = re.match(r"^(\d+)\s*\+\s*$", label)
	if m:
		return int(m.group(1))

	m = re.search(r"(\d+)\s*\+", label)
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
