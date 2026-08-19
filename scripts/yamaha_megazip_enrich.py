#!/usr/bin/env python3
"""Enrich Yamaha YMH-JP parts from MegaZip search.

Source: https://www.megazip.ru/search?q={part_number}

Rules:
  - only Yamaha brand
  - skip replacements («Замена»)
  - take Japan warehouse price (Склад Япония / data-country-code=JP) in RUB
  - full part number + Russian name from product title
  - unique part numbers once → UPDATE all matching YMH-JP rows

Does NOT overwrite part_number / normalized_part_number / name.

Examples:
  ./scripts/yamaha_megazip_enrich.sh ensure-schema
  ./scripts/yamaha_megazip_enrich.sh status
  ./scripts/yamaha_megazip_enrich.sh fetch --part 95827-10050
  ./scripts/yamaha_megazip_enrich.sh fetch --workers 8
"""

from __future__ import annotations

import argparse
import json
import os
import random
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from dataclasses import dataclass
from html import unescape
from pathlib import Path
from typing import Any
from urllib.parse import urljoin

try:
    import psycopg
    from bs4 import BeautifulSoup
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg + beautifulsoup4 — run via scripts/yamaha_megazip_enrich.sh") from exc


ROOT_ARIB = "YMH-JP"
MEGAZIP_ORIGIN = "https://www.megazip.ru"
SEARCH_URL = f"{MEGAZIP_ORIGIN}/search"
DEFAULT_WORK = Path("/app/storage/yamaha-megazip")
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)

PART_NUMBER_OK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.\-/]*$")
RUB_RE = re.compile(r"([\d\s\u00a0\u202f]+)\s*р", re.I)
TITLE_RE = re.compile(
    r"^\s*(?P<full>[A-Za-z0-9][A-Za-z0-9.\-/]*)\s+"
    r"(?P<name>.+?)\s*,\s*(?P<brand>Yamaha)\s*$",
    re.I,
)
_RETRYABLE_HTTP = {429, 502, 503, 504}


def _dsn() -> str:
    return (
        os.environ.get("OEM_YAMAHA_DATABASE_DSN")
        or "postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog"
    )


def _connect() -> psycopg.Connection:
    return psycopg.connect(_dsn(), row_factory=dict_row)


def _log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def work_paths(work_dir: Path) -> dict[str, Path]:
    work_dir.mkdir(parents=True, exist_ok=True)
    return {
        "results": work_dir / "results.jsonl",
        "errors": work_dir / "errors.jsonl",
        "stats": work_dir / "last_stats.json",
    }


def ensure_schema(conn: psycopg.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            ALTER TABLE oem_parts
              ADD COLUMN IF NOT EXISTS full_part_number VARCHAR(255),
              ADD COLUMN IF NOT EXISTS name_ru TEXT,
              ADD COLUMN IF NOT EXISTS weight_kg NUMERIC(12, 4),
              ADD COLUMN IF NOT EXISTS price_jpy NUMERIC(12, 2),
              ADD COLUMN IF NOT EXISTS price_rub NUMERIC(12, 2),
              ADD COLUMN IF NOT EXISTS megazip_status VARCHAR(32),
              ADD COLUMN IF NOT EXISTS megazip_checked_at TIMESTAMPTZ,
              ADD COLUMN IF NOT EXISTS megazip_payload JSONB
            """
        )
        cur.execute(
            """
            CREATE INDEX IF NOT EXISTS ix_oem_parts_megazip_status
              ON oem_parts (root_arib, megazip_status)
            """
        )
    conn.commit()
    _log("schema: megazip columns ready on oem_parts")


def is_plausible_part_number(pn: str) -> bool:
    t = (pn or "").strip()
    return bool(t) and bool(PART_NUMBER_OK_RE.match(t)) and len(t) >= 5


def _parse_rub(text: str | None) -> float | None:
    if not text:
        return None
    m = RUB_RE.search(text.replace("\xa0", " ").replace("\u202f", " "))
    if not m:
        return None
    raw = re.sub(r"\s+", "", m.group(1))
    try:
        return float(raw)
    except ValueError:
        return None


def _norm_pn(pn: str) -> str:
    return re.sub(r"[^A-Za-z0-9]", "", (pn or "")).upper()


@dataclass
class SearchHit:
    name_link: str
    full_part_number: str
    href: str
    manufacturer: str
    is_replacement: bool
    list_price_rub: float | None


@dataclass
class MegaZipResult:
    query: str
    status: str  # ok | unavailable | not_found | no_yamaha | no_original | no_jp_price | error | rate_limited | skipped
    full_part_number: str | None = None
    name_ru: str | None = None
    brand: str | None = None
    price_rub: float | None = None
    product_url: str | None = None
    error: str | None = None
    payload: dict[str, Any] | None = None


class AdaptiveThrottle:
    def __init__(self, min_interval: float = 0.05) -> None:
        self._lock = threading.Lock()
        self.min_interval = min_interval
        self.interval = min_interval
        self._next_ts = 0.0
        self.strikes = 0
        self.successes = 0

    def wait(self) -> None:
        with self._lock:
            now = time.monotonic()
            wait_for = max(0.0, self._next_ts - now)
            jitter = random.uniform(0, min(0.05, self.interval * 0.3))
            self._next_ts = max(now, self._next_ts) + self.interval
        if wait_for + jitter > 0:
            time.sleep(wait_for + jitter)

    def on_success(self) -> None:
        with self._lock:
            self.successes += 1
            self.strikes = max(0, self.strikes - 1)
            if self.successes % 25 == 0:
                self.interval = max(self.min_interval, self.interval * 0.9)

    def on_rate_limit(self) -> None:
        with self._lock:
            self.strikes += 1
            self.successes = 0
            self.interval = min(2.5, max(0.15, self.interval * 1.5 + 0.05))
            self._next_ts = max(self._next_ts, time.monotonic() + self.interval)

    def snapshot(self) -> dict[str, float | int]:
        with self._lock:
            return {"interval": round(self.interval, 3), "strikes": self.strikes}


def _is_rate_limit_error(exc: BaseException) -> bool:
    if isinstance(exc, urllib.error.HTTPError) and exc.code in _RETRYABLE_HTTP:
        return True
    msg = str(exc).lower()
    return any(
        x in msg
        for x in (
            "503",
            "429",
            "502",
            "504",
            "timed out",
            "timeout",
            "temporarily unavailable",
            "connection reset",
            "remote end closed",
            "incomplete read",
            "unexpected_eof",
            "eof occurred",
        )
    )


def http_get(
    url: str,
    *,
    timeout: float = 30.0,
    retries: int = 5,
    throttle: AdaptiveThrottle | None = None,
) -> tuple[str, str]:
    """Return (html, final_url)."""
    last_err: BaseException | None = None
    for attempt in range(1, retries + 1):
        if throttle is not None:
            throttle.wait()
        try:
            req = urllib.request.Request(
                url,
                headers={
                    "User-Agent": USER_AGENT,
                    "Accept": "text/html,application/xhtml+xml",
                    "Accept-Language": "ru-RU,ru;q=0.9,en;q=0.5",
                    "Referer": f"{MEGAZIP_ORIGIN}/",
                },
            )
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                final_url = resp.geturl()
                raw = resp.read()
                charset = resp.headers.get_content_charset() or "utf-8"
            if throttle is not None:
                throttle.on_success()
            return raw.decode(charset, errors="replace"), final_url
        except Exception as exc:  # noqa: BLE001
            last_err = exc
            if _is_rate_limit_error(exc):
                if throttle is not None:
                    throttle.on_rate_limit()
                time.sleep(min(10.0, (1.35**attempt) + random.uniform(0.1, 0.5)))
                continue
            if attempt < retries:
                time.sleep(0.25 * attempt)
                continue
            break
    assert last_err is not None
    raise last_err


def is_search_results_page(html: str) -> bool:
    """True only for search listing; False when MegaZip redirected to a product card."""
    soup = BeautifulSoup(html, "lxml")
    h1 = soup.select_one("h1")
    if h1:
        text = unescape(h1.get_text(" ", strip=True))
        if "результат" in text.lower():
            return True
        # Product card title: "95817-10050-00 Болт, Yamaha"
        if TITLE_RE.match(text) or re.match(
            r"^[A-Za-z0-9][A-Za-z0-9.\-/]*\s+.+,?\s*Yamaha\b",
            text,
            re.I,
        ):
            return False
    # Listing rows under spare_name
    return bool(soup.select_one("table.s-catalog__items-list-table td.spare_name"))


def parse_search_hits(html: str) -> list[SearchHit]:
    soup = BeautifulSoup(html, "lxml")
    hits: list[SearchHit] = []

    sections = soup.select("h2")
    yamaha_table = None
    for h2 in sections:
        if "yamaha" in h2.get_text(" ", strip=True).lower():
            table = h2.find_next("table", class_=re.compile(r"s-catalog__items-list-table"))
            if table:
                yamaha_table = table
                break
    tables = [yamaha_table] if yamaha_table is not None else soup.select("table.s-catalog__items-list-table")

    for table in tables:
        if table is None:
            continue
        for tr in table.select("tbody tr"):
            name_td = tr.select_one("td.spare_name")
            if not name_td:
                continue
            a = name_td.select_one("a[href]")
            p_num = name_td.select_one("p")
            if not a or not p_num:
                continue
            href = a.get("href") or tr.get("data-href") or ""
            full_pn = p_num.get_text(" ", strip=True)
            name_link = unescape(a.get_text(" ", strip=True))
            manufacturer = ""
            raw_item = tr.get("data-item")
            if raw_item:
                try:
                    item = json.loads(unescape(raw_item))
                    manufacturer = str(item.get("manufacturer") or "")
                    full_pn = str(item.get("number") or full_pn)
                except json.JSONDecodeError:
                    pass
            if not manufacturer:
                if "yamaha" in name_link.lower() or "/yamaha/" in href.lower():
                    manufacturer = "Yamaha"
            info = tr.select_one("td.tar")
            info_txt = info.get_text(" ", strip=True) if info else ""
            is_repl = bool(tr.select_one(".search-result__icon_type_replace")) or (
                "замена" in info_txt.lower()
            )
            list_price = None if is_repl else _parse_rub(info_txt)
            hits.append(
                SearchHit(
                    name_link=name_link,
                    full_part_number=full_pn,
                    href=href,
                    manufacturer=manufacturer,
                    is_replacement=is_repl,
                    list_price_rub=list_price,
                )
            )
    return hits


def pick_original_yamaha(hits: list[SearchHit], query: str) -> SearchHit | None:
    yamaha = [
        h
        for h in hits
        if h.manufacturer.strip().lower() == "yamaha" or "yamaha" in h.name_link.lower()
    ]
    originals = [h for h in yamaha if not h.is_replacement and h.href]
    if not originals:
        return None
    qn = _norm_pn(query)

    def score(h: SearchHit) -> tuple:
        hn = _norm_pn(h.full_part_number)
        return (
            1 if qn and (hn == qn or hn.startswith(qn) or qn.startswith(hn)) else 0,
            1 if h.list_price_rub is not None else 0,
            len(h.full_part_number),
        )

    return max(originals, key=score)


def parse_product_page(html: str) -> dict[str, Any]:
    soup = BeautifulSoup(html, "lxml")
    title = ""
    h1 = soup.select_one("h1")
    if h1:
        title = unescape(h1.get_text(" ", strip=True))
    full_pn = name_ru = brand = None
    m = TITLE_RE.match(title)
    if m:
        full_pn = m.group("full").strip()
        name_ru = m.group("name").strip().rstrip(",")
        brand = m.group("brand").strip()
    else:
        bits = title.split()
        if bits:
            full_pn = bits[0]
        if "yamaha" in title.lower():
            brand = "Yamaha"
            name_ru = re.sub(
                r",?\s*Yamaha\s*$",
                "",
                title[len(full_pn or "") :].strip(),
                flags=re.I,
            ).strip(" ,")

    unavailable = bool(
        re.search(r"запчасть\s+недоступна\s+для\s+заказа", html, re.I)
    )

    jp_price = None
    if not unavailable:
        for inp in soup.select("input.js-item-supplier"):
            code = (inp.get("data-country-code") or "").upper()
            label = ""
            inp_id = inp.get("id")
            if inp_id:
                lab = soup.select_one(f'label[for="{inp_id}"]')
                if lab:
                    label = lab.get_text(" ", strip=True)
            is_jp = code == "JP" or "япони" in label.lower()
            if not is_jp:
                continue
            if inp.get("data-price"):
                try:
                    jp_price = float(str(inp.get("data-price")).replace(" ", "").replace(",", "."))
                except ValueError:
                    jp_price = None
            if jp_price is None:
                price_span = None
                if inp_id:
                    price_span = soup.select_one(f'label[for="{inp_id}"] .price-chooser__price')
                jp_price = _parse_rub(price_span.get_text(" ", strip=True) if price_span else label)
            break

    original = bool(soup.find(string=re.compile(r"оригинальн", re.I)))
    return {
        "title": title,
        "full_part_number": full_pn,
        "name_ru": name_ru,
        "brand": brand,
        "price_rub_jp": jp_price,
        "unavailable": unavailable,
        "looks_original": original,
    }


def result_from_product(
    *,
    query: str,
    html: str,
    product_url: str,
    search_url: str | None = None,
    list_price_rub: float | None = None,
    fallback_full: str | None = None,
    fallback_brand: str | None = None,
) -> MegaZipResult:
    parsed = parse_product_page(html)
    brand = parsed.get("brand") or fallback_brand or ""
    full_pn = parsed.get("full_part_number") or fallback_full
    name_ru = parsed.get("name_ru")

    if brand.strip().lower() != "yamaha":
        return MegaZipResult(
            query=query,
            status="no_yamaha",
            full_part_number=full_pn,
            name_ru=name_ru,
            brand=brand,
            product_url=product_url,
            payload={"title": parsed.get("title"), "search_url": search_url},
        )

    if parsed.get("unavailable"):
        return MegaZipResult(
            query=query,
            status="unavailable",
            full_part_number=full_pn,
            name_ru=name_ru,
            brand=brand,
            price_rub=None,
            product_url=product_url,
            payload={
                "search_url": search_url,
                "title": parsed.get("title"),
                "unavailable": True,
            },
        )

    price = parsed.get("price_rub_jp")
    if price is None:
        price = list_price_rub
    if price is None:
        return MegaZipResult(
            query=query,
            status="no_jp_price",
            full_part_number=full_pn,
            name_ru=name_ru,
            brand=brand,
            product_url=product_url,
            payload={"title": parsed.get("title"), "search_url": search_url},
        )

    return MegaZipResult(
        query=query,
        status="ok",
        full_part_number=full_pn,
        name_ru=name_ru,
        brand=brand,
        price_rub=float(price),
        product_url=product_url,
        payload={
            "search_url": search_url,
            "title": parsed.get("title"),
            "list_price_rub": list_price_rub,
            "jp_price_rub": parsed.get("price_rub_jp"),
            "looks_original": parsed.get("looks_original"),
        },
    )


def fetch_megazip(part_number: str, *, throttle: AdaptiveThrottle | None = None) -> MegaZipResult:
    q = part_number.strip()
    if not q:
        return MegaZipResult(query=q, status="error", error="empty part number")

    search_url = f"{SEARCH_URL}?{urllib.parse.urlencode({'q': q})}"
    try:
        html, final_url = http_get(search_url, throttle=throttle)
    except Exception as exc:  # noqa: BLE001
        status = "rate_limited" if _is_rate_limit_error(exc) else "error"
        return MegaZipResult(query=q, status=status, error=f"{type(exc).__name__}: {exc}")

    # MegaZip often redirects a unique hit straight to the product card
    if not is_search_results_page(html):
        return result_from_product(
            query=q,
            html=html,
            product_url=final_url,
            search_url=search_url,
        )

    hits = parse_search_hits(html)
    if not hits:
        return MegaZipResult(query=q, status="not_found", payload={"search_url": search_url, "final_url": final_url})

    yamaha_hits = [
        h for h in hits if h.manufacturer.strip().lower() == "yamaha" or "yamaha" in h.name_link.lower()
    ]
    if not yamaha_hits:
        return MegaZipResult(
            query=q,
            status="no_yamaha",
            payload={"search_url": search_url, "hits": len(hits)},
        )

    chosen = pick_original_yamaha(hits, q)
    if chosen is None:
        return MegaZipResult(
            query=q,
            status="no_original",
            payload={
                "search_url": search_url,
                "yamaha_hits": len(yamaha_hits),
                "replacements_only": True,
            },
        )

    product_url = urljoin(MEGAZIP_ORIGIN, chosen.href)
    try:
        prod_html, product_final = http_get(product_url, throttle=throttle)
    except Exception as exc:  # noqa: BLE001
        status = "rate_limited" if _is_rate_limit_error(exc) else "error"
        return MegaZipResult(
            query=q,
            status=status,
            full_part_number=chosen.full_part_number,
            product_url=product_url,
            error=f"{type(exc).__name__}: {exc}",
        )

    return result_from_product(
        query=q,
        html=prod_html,
        product_url=product_final or product_url,
        search_url=search_url,
        list_price_rub=chosen.list_price_rub,
        fallback_full=chosen.full_part_number,
        fallback_brand=chosen.manufacturer or "Yamaha",
    )


def append_jsonl(path: Path, row: dict[str, Any], *, lock: threading.Lock | None = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    line = json.dumps(row, ensure_ascii=False) + "\n"
    if lock is None:
        with path.open("a", encoding="utf-8") as fh:
            fh.write(line)
        return
    with lock:
        with path.open("a", encoding="utf-8") as fh:
            fh.write(line)


def apply_result_to_db(conn: psycopg.Connection, result: MegaZipResult) -> int:
    payload = {
        "parser": "v2",
        "query": result.query,
        "status": result.status,
        "brand": result.brand,
        "product_url": result.product_url,
        "error": result.error,
        "payload": result.payload,
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE oem_parts
            SET
              full_part_number = COALESCE(%s, full_part_number),
              name_ru = COALESCE(%s, name_ru),
              price_rub = COALESCE(%s, price_rub),
              megazip_status = %s,
              megazip_checked_at = now(),
              megazip_payload = %s::jsonb,
              updated_at = now()
            WHERE root_arib = %s
              AND (
                part_number = %s
                OR normalized_part_number = %s
              )
            """,
            (
                result.full_part_number,
                result.name_ru,
                result.price_rub,
                result.status,
                json.dumps(payload, ensure_ascii=False),
                ROOT_ARIB,
                result.query,
                result.query,
            ),
        )
        n = int(cur.rowcount)
    conn.commit()
    return n


def list_unique_pending(
    conn: psycopg.Connection,
    *,
    force: bool,
    retry_errors: bool,
    limit: int | None,
    only_parts: list[str] | None = None,
) -> list[str]:
    if only_parts:
        cleaned: list[str] = []
        seen: set[str] = set()
        for pn in only_parts:
            pn = pn.strip()
            if not pn or pn in seen:
                continue
            seen.add(pn)
            cleaned.append(pn)
        return cleaned[:limit] if limit else cleaned

    clauses = ["root_arib = %s", "part_number IS NOT NULL", "BTRIM(part_number) <> ''"]
    params: list[Any] = [ROOT_ARIB]
    if force:
        pass
    elif retry_errors:
        clauses.append(
            "(megazip_status IS NULL OR megazip_status IN "
            "('error', 'rate_limited', 'not_found', 'no_yamaha', 'no_original', 'no_jp_price'))"
        )
    else:
        clauses.append("(megazip_status IS NULL OR megazip_status = 'rate_limited')")

    sql = f"""
        SELECT DISTINCT part_number
        FROM oem_parts
        WHERE {' AND '.join(clauses)}
        ORDER BY part_number
    """
    if limit and limit > 0:
        sql += " LIMIT %s"
        params.append(limit)

    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = [r["part_number"] for r in cur.fetchall()]
    return [pn for pn in rows if is_plausible_part_number(pn)]


def _reset_transient_errors(conn: psycopg.Connection) -> int:
    with conn.cursor() as cur:
        # Re-queue false not_found (search→product redirects) + rate limits
        cur.execute(
            """
            UPDATE oem_parts
            SET megazip_status = NULL,
                megazip_checked_at = NULL,
                updated_at = now()
            WHERE root_arib = %s
              AND (
                megazip_status = 'rate_limited'
                OR (
                  megazip_status = 'not_found'
                  AND COALESCE(megazip_payload->>'parser', '') IS DISTINCT FROM 'v2'
                )
                OR (
                  megazip_status = 'error'
                  AND (
                    COALESCE(megazip_payload->>'error', '') ILIKE '%%503%%'
                    OR COALESCE(megazip_payload->>'error', '') ILIKE '%%429%%'
                    OR COALESCE(megazip_payload->>'error', '') ILIKE '%%timeout%%'
                    OR COALESCE(megazip_payload->>'error', '') ILIKE '%%timed out%%'
                    OR COALESCE(megazip_payload->>'error', '') ILIKE '%%IncompleteRead%%'
                    OR COALESCE(megazip_payload->>'error', '') ILIKE '%%SSL%%'
                  )
                )
              )
            """,
            (ROOT_ARIB,),
        )
        n = int(cur.rowcount)
    conn.commit()
    return n


def _mark_skipped_junk(conn: psycopg.Connection, dry_run: bool) -> int:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT DISTINCT part_number
            FROM oem_parts
            WHERE root_arib = %s
              AND megazip_status IS NULL
              AND part_number IS NOT NULL
              AND BTRIM(part_number) <> ''
            """,
            (ROOT_ARIB,),
        )
        junk = [r["part_number"] for r in cur.fetchall() if not is_plausible_part_number(r["part_number"])]
    if not junk or dry_run:
        return len(junk)
    n = 0
    for pn in junk:
        n += apply_result_to_db(
            conn,
            MegaZipResult(query=pn, status="skipped", error="implausible part number"),
        )
    return n


def cmd_status(work_dir: Path) -> None:
    with _connect() as conn:
        ensure_schema(conn)
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  COUNT(*) AS parts,
                  COUNT(DISTINCT part_number) AS unique_pn,
                  COUNT(*) FILTER (WHERE megazip_status IS NULL) AS pending,
                  COUNT(*) FILTER (WHERE megazip_status = 'ok') AS ok,
                  COUNT(*) FILTER (WHERE megazip_status = 'unavailable') AS unavailable,
                  COUNT(*) FILTER (WHERE megazip_status = 'not_found') AS not_found,
                  COUNT(*) FILTER (WHERE megazip_status = 'no_yamaha') AS no_yamaha,
                  COUNT(*) FILTER (WHERE megazip_status = 'no_original') AS no_original,
                  COUNT(*) FILTER (WHERE megazip_status = 'no_jp_price') AS no_jp_price,
                  COUNT(*) FILTER (WHERE megazip_status = 'error') AS error,
                  COUNT(*) FILTER (WHERE megazip_status = 'rate_limited') AS rate_limited,
                  COUNT(*) FILTER (WHERE megazip_status = 'skipped') AS skipped,
                  COUNT(*) FILTER (WHERE price_rub IS NOT NULL) AS with_price_rub,
                  COUNT(*) FILTER (WHERE name_ru IS NOT NULL) AS with_name_ru,
                  COUNT(*) FILTER (WHERE full_part_number IS NOT NULL) AS with_full_pn
                FROM oem_parts
                WHERE root_arib = %s
                """,
                (ROOT_ARIB,),
            )
            row = dict(cur.fetchone())
    print(json.dumps(row, ensure_ascii=False, indent=2), flush=True)
    paths = work_paths(work_dir)
    if paths["results"].is_file():
        _log(f"cache: {paths['results']} ({paths['results'].stat().st_size} bytes)")


def cmd_fetch(
    work_dir: Path,
    *,
    delay: float,
    limit: int | None,
    force: bool,
    retry_errors: bool,
    progress_every: int,
    dry_run: bool,
    workers: int,
    only_parts: list[str] | None = None,
) -> None:
    paths = work_paths(work_dir)
    with _connect() as conn:
        ensure_schema(conn)
        if not only_parts and not force:
            reset_n = _reset_transient_errors(conn)
            if reset_n:
                _log(f"fetch: re-queued transient errors rows={reset_n}")
            skipped = _mark_skipped_junk(conn, dry_run=dry_run)
            if skipped:
                _log(f"fetch: marked skipped junk ≈{skipped}")

        numbers = list_unique_pending(
            conn,
            force=force,
            retry_errors=retry_errors,
            limit=limit,
            only_parts=only_parts,
        )

    total = len(numbers)
    workers = max(1, workers)
    throttle = AdaptiveThrottle(min_interval=max(0.0, delay) if delay > 0 else 0.06)
    _log(
        f"fetch start root={ROOT_ARIB} unique={total} workers={workers} "
        f"throttle_min={throttle.min_interval}s source=megazip"
    )
    if total == 0:
        _log("fetch: nothing to do")
        return

    stats = {
        "ok": 0,
        "unavailable": 0,
        "not_found": 0,
        "no_yamaha": 0,
        "no_original": 0,
        "no_jp_price": 0,
        "error": 0,
        "rate_limited": 0,
        "skipped": 0,
        "db_rows": 0,
        "with_price": 0,
        "with_name_ru": 0,
        "with_full": 0,
    }
    stats_lock = threading.Lock()
    file_lock = threading.Lock()
    t0 = time.time()
    done = 0
    last_log_t = t0
    last_pn = ""

    def _maybe_log(force_log: bool = False) -> None:
        nonlocal last_log_t
        now = time.time()
        with stats_lock:
            i = done
            snap = dict(stats)
            pn = last_pn
        if not force_log and i not in (1, total) and i % max(1, progress_every) != 0 and (now - last_log_t) < 2.0:
            return
        last_log_t = now
        elapsed = max(now - t0, 0.001)
        rate = i / elapsed
        eta = (total - i) / rate if rate > 0 else 0.0
        _log(
            f"progress {i}/{total} ({100.0 * i / total:.1f}%) "
            f"ok={snap['ok']} unavailable={snap['unavailable']} "
            f"not_found={snap['not_found']} no_original={snap['no_original']} "
            f"no_jp={snap['no_jp_price']} rate_limited={snap['rate_limited']} "
            f"error={snap['error']} price={snap['with_price']} "
            f"name_ru={snap['with_name_ru']} full={snap['with_full']} "
            f"db_rows={snap['db_rows']} rate={rate:.2f}/s eta={eta / 60:.1f}min "
            f"workers={workers} throttle={throttle.snapshot()} last={pn}"
        )

    def _worker(pn: str) -> tuple[str, MegaZipResult, int]:
        result = fetch_megazip(pn, throttle=throttle)
        db_rows = 0
        if not dry_run:
            with _connect() as conn:
                db_rows = apply_result_to_db(conn, result)
        return pn, result, db_rows

    def _handle_future(fut: Any, pn_fallback: str) -> None:
        nonlocal done, last_pn
        pn = pn_fallback
        try:
            pn, result, db_rows = fut.result()
        except Exception as exc:  # noqa: BLE001
            result = MegaZipResult(query=pn, status="error", error=f"worker: {exc}")
            db_rows = 0
            if not dry_run:
                try:
                    with _connect() as conn:
                        db_rows = apply_result_to_db(conn, result)
                except Exception:
                    pass

        row = {
            "ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "query": result.query,
            "status": result.status,
            "full_part_number": result.full_part_number,
            "name_ru": result.name_ru,
            "brand": result.brand,
            "price_rub": result.price_rub,
            "product_url": result.product_url,
            "error": result.error,
        }
        append_jsonl(paths["results"], row, lock=file_lock)
        if result.status in {"error", "rate_limited"}:
            append_jsonl(paths["errors"], row, lock=file_lock)

        with stats_lock:
            done += 1
            stats[result.status] = stats.get(result.status, 0) + 1
            stats["db_rows"] += db_rows
            if result.price_rub is not None:
                stats["with_price"] += 1
            if result.name_ru:
                stats["with_name_ru"] += 1
            if result.full_part_number:
                stats["with_full"] += 1
            last_pn = pn
            finished = done

        if dry_run and finished <= 5:
            _log(
                f"dry-run {pn} → {result.status} full={result.full_part_number} "
                f"name_ru={result.name_ru} price_rub={result.price_rub}"
            )
        _maybe_log(force_log=(finished == total))

    in_flight: dict[Any, str] = {}
    numbers_iter = iter(numbers)
    max_inflight = workers * 4

    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="megazip") as pool:
        def _fill() -> None:
            while len(in_flight) < max_inflight:
                try:
                    pn = next(numbers_iter)
                except StopIteration:
                    return
                in_flight[pool.submit(_worker, pn)] = pn

        _fill()
        while in_flight:
            completed, _ = wait(in_flight.keys(), return_when=FIRST_COMPLETED)
            for fut in completed:
                pn = in_flight.pop(fut)
                _handle_future(fut, pn)
            _fill()

    elapsed = round(time.time() - t0, 1)
    out = {"elapsed_sec": elapsed, "total": total, "workers": workers, **stats}
    paths["stats"].write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    _log(f"fetch done in {elapsed}s ({elapsed / 60:.1f}min)")
    print(json.dumps(out, ensure_ascii=False, indent=2), flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Yamaha YMH-JP MegaZip enrichment")
    parser.add_argument("command", choices=["ensure-schema", "status", "fetch"])
    parser.add_argument("--work-dir", default=str(DEFAULT_WORK))
    parser.add_argument("--delay", type=float, default=0.0)
    parser.add_argument("--workers", type=int, default=8)
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument("--force", action="store_true")
    parser.add_argument("--retry-errors", action="store_true")
    parser.add_argument("--progress-every", type=int, default=25)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--part", action="append", default=None)
    args = parser.parse_args(argv)

    work_dir = Path(args.work_dir)
    if args.command == "ensure-schema":
        with _connect() as conn:
            ensure_schema(conn)
    elif args.command == "status":
        cmd_status(work_dir)
    elif args.command == "fetch":
        cmd_fetch(
            work_dir,
            delay=max(0.0, args.delay),
            limit=args.limit,
            force=args.force,
            retry_errors=args.retry_errors,
            progress_every=max(1, args.progress_every),
            dry_run=args.dry_run,
            workers=max(1, args.workers),
            only_parts=args.part,
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
