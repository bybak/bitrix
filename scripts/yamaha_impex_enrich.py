#!/usr/bin/env python3
"""Enrich Yamaha YMH-JP parts from IMPEX Japan part-search.

Fetches unique part numbers → price (JPY), weight (kg), full catalog number,
then writes results into oem_parts additive columns (does not overwrite
part_number / normalized_part_number / name).

Pipeline:
  ensure-schema  — ADD COLUMN IF NOT EXISTS …
  status         — coverage counters
  fetch          — unique PN → Impex → UPDATE all matching rows
  apply-cache    — re-apply JSONL cache without HTTP

Examples:
  ./scripts/yamaha_impex_enrich.sh ensure-schema
  ./scripts/yamaha_impex_enrich.sh status
  ./scripts/yamaha_impex_enrich.sh fetch --limit 20
  ./scripts/yamaha_impex_enrich.sh fetch --delay 0.4
  ./scripts/yamaha_impex_enrich.sh fetch --retry-errors
"""

from __future__ import annotations

import argparse
import json
import os
import random
import re
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any

try:
    import psycopg
    from bs4 import BeautifulSoup
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg + beautifulsoup4 — run via scripts/yamaha_impex_enrich.sh") from exc


ROOT_ARIB = "YMH-JP"
IMPEX_URL = "https://www.impex-jp.com/zip/part-search.html"
DEFAULT_WORK = Path("/app/storage/yamaha-impex")
USER_AGENT = (
    "Mozilla/5.0 (compatible; MotorForceOEM/1.0; +https://motor-force.ru) "
    "AppleWebKit/537.36 Chrome/120.0.0.0"
)

YEN_RE = re.compile(r"(\d+(?:[.,]\d+)?)\s*¥")
WEIGHT_RE = re.compile(r"([\d]+(?:[.,]\d+)?)\s*кг", re.I)
UNKNOWN_WEIGHT = re.compile(r"неизвест|unknown|n/?a|—|-|–", re.I)
# Skip OCR/junk keys that are not real Yamaha catalog numbers
PART_NUMBER_OK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.\-/]*$")


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
              ADD COLUMN IF NOT EXISTS weight_kg NUMERIC(12, 4),
              ADD COLUMN IF NOT EXISTS price_jpy NUMERIC(12, 2),
              ADD COLUMN IF NOT EXISTS impex_status VARCHAR(32),
              ADD COLUMN IF NOT EXISTS impex_checked_at TIMESTAMPTZ,
              ADD COLUMN IF NOT EXISTS impex_payload JSONB
            """
        )
        cur.execute(
            """
            CREATE INDEX IF NOT EXISTS ix_oem_parts_impex_status
              ON oem_parts (root_arib, impex_status)
            """
        )
    conn.commit()
    _log("schema: impex columns ready on oem_parts")


@dataclass
class ImpexHit:
    brand: str
    catalog_number: str
    name_jp: str
    name_en: str
    name_ru: str
    weight_kg: float | None
    price_jpy: float | None


@dataclass
class ImpexResult:
    query: str
    status: str  # ok | not_found | no_yamaha | error | rate_limited | skipped
    full_part_number: str | None = None
    weight_kg: float | None = None
    price_jpy: float | None = None
    hits: int = 0
    error: str | None = None
    picked: dict[str, Any] | None = None


class AdaptiveThrottle:
    """Shared limiter: backs off on 503/429, speeds up on success."""

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
            if self.successes % 20 == 0:
                self.interval = max(self.min_interval, self.interval * 0.85)

    def on_rate_limit(self) -> None:
        with self._lock:
            self.strikes += 1
            self.successes = 0
            self.interval = min(3.0, max(0.2, self.interval * 1.7 + 0.1))
            self._next_ts = max(self._next_ts, time.monotonic() + self.interval)

    def snapshot(self) -> dict[str, float | int]:
        with self._lock:
            return {"interval": round(self.interval, 3), "strikes": self.strikes}


_RETRYABLE_HTTP = {429, 502, 503, 504}


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


def _parse_yen(text: str) -> float | None:
    if not text:
        return None
    normalized = (
        text.replace("\xa0", " ")
        .replace("\u202f", " ")
        .replace("￥", "¥")  # fullwidth yen → ¥
    )
    m = YEN_RE.search(normalized)
    if not m:
        return None
    raw = m.group(1).replace(",", ".")
    try:
        return float(raw)
    except ValueError:
        return None


def is_plausible_part_number(pn: str) -> bool:
    t = (pn or "").strip()
    return bool(t) and bool(PART_NUMBER_OK_RE.match(t)) and len(t) >= 5


def _parse_weight_kg(text: str) -> float | None:
    if not text or UNKNOWN_WEIGHT.search(text.strip()):
        return None
    m = WEIGHT_RE.search(text.replace("\xa0", " "))
    if not m:
        return None
    raw = m.group(1).replace(",", ".")
    try:
        return float(raw)
    except ValueError:
        return None


def parse_impex_html(html: str) -> list[ImpexHit]:
    soup = BeautifulSoup(html, "lxml")
    table = soup.select_one("table.parts-search-result-table")
    if not table:
        return []
    hits: list[ImpexHit] = []
    for tr in table.select("tbody tr"):
        tds = tr.find_all("td")
        if len(tds) < 7:
            continue
        brand = tds[0].get_text(" ", strip=True)
        catalog = tds[1].get_text(" ", strip=True)
        name_jp = tds[2].get_text(" ", strip=True)
        name_en = tds[3].get_text(" ", strip=True)
        name_ru = tds[4].get_text(" ", strip=True)
        weight_txt = tds[5].get_text(" ", strip=True)
        price_txt = tds[6].get_text(" ", strip=True)
        if not catalog:
            continue
        hits.append(
            ImpexHit(
                brand=brand,
                catalog_number=catalog,
                name_jp=name_jp,
                name_en=name_en,
                name_ru=name_ru,
                weight_kg=_parse_weight_kg(weight_txt),
                price_jpy=_parse_yen(price_txt),
            )
        )
    return hits


def _fullness(catalog: str, query: str) -> tuple[int, int, int, int]:
    """Higher = better full part number candidate."""
    q = query.strip().upper()
    c = catalog.strip().upper()
    return (
        1 if c.startswith(q.rstrip("-")) or q in c else 0,
        c.count("-"),
        len(c),
        1 if c != q else 0,
    )


def pick_best(hits: list[ImpexHit], query: str) -> ImpexResult:
    if not hits:
        return ImpexResult(query=query, status="not_found", hits=0)

    yamaha = [h for h in hits if h.brand.strip().upper() == "YAMAHA"]
    pool = yamaha or hits
    status = "ok" if yamaha else "no_yamaha"

    best_full = max(pool, key=lambda h: _fullness(h.catalog_number, query))
    full_pn = best_full.catalog_number.strip() or None

    weight = next((h.weight_kg for h in pool if h.weight_kg is not None), None)

    # Prefer price from row with weight, then fullest catalog number
    ranked = sorted(
        pool,
        key=lambda h: (
            h.weight_kg is not None,
            *_fullness(h.catalog_number, query),
            h.price_jpy is not None,
        ),
        reverse=True,
    )
    price = next((h.price_jpy for h in ranked if h.price_jpy is not None), None)

    # If we got neither price nor any useful field — still mark found
    if price is None and weight is None and not full_pn:
        return ImpexResult(query=query, status="not_found", hits=len(hits))

    return ImpexResult(
        query=query,
        status=status,
        full_part_number=full_pn,
        weight_kg=weight,
        price_jpy=price,
        hits=len(hits),
        picked={
            "brand_filter": "YAMAHA" if yamaha else "any",
            "candidates": [asdict(h) for h in pool],
            "chosen_full": full_pn,
            "chosen_weight_kg": weight,
            "chosen_price_jpy": price,
        },
    )


def fetch_impex(
    part_number: str,
    *,
    timeout: float = 30.0,
    retries: int = 6,
    throttle: AdaptiveThrottle | None = None,
) -> ImpexResult:
    q = part_number.strip()
    if not q:
        return ImpexResult(query=q, status="error", error="empty part number")

    url = f"{IMPEX_URL}?part={urllib.parse.quote(q)}"
    last_err: str | None = None
    saw_rate_limit = False
    for attempt in range(1, retries + 1):
        if throttle is not None:
            throttle.wait()
        try:
            req = urllib.request.Request(
                url,
                headers={
                    "User-Agent": USER_AGENT,
                    "Accept": "text/html,application/xhtml+xml",
                    "Accept-Language": "ru,en;q=0.9",
                    "Referer": "https://www.impex-jp.com/zip/part-search.html",
                },
            )
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read()
                charset = resp.headers.get_content_charset() or "utf-8"
            html = raw.decode(charset, errors="replace")
            hits = parse_impex_html(html)
            if throttle is not None:
                throttle.on_success()
            return pick_best(hits, q)
        except Exception as exc:  # noqa: BLE001 — network noise, retry
            last_err = f"{type(exc).__name__}: {exc}"
            if _is_rate_limit_error(exc):
                saw_rate_limit = True
                if throttle is not None:
                    throttle.on_rate_limit()
                time.sleep(min(12.0, (1.4**attempt) + random.uniform(0.1, 0.6)))
                continue
            if attempt < retries:
                time.sleep(0.3 * attempt)
                continue
            break
    status = "rate_limited" if saw_rate_limit else "error"
    return ImpexResult(query=q, status=status, error=last_err)


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


def apply_result_to_db(conn: psycopg.Connection, result: ImpexResult) -> int:
    """Update all YMH-JP rows with this part_number / normalized key."""
    # Keep payload small — full candidate dump slows high-concurrency writes
    payload = {
        "query": result.query,
        "status": result.status,
        "hits": result.hits,
        "error": result.error,
        "full_part_number": result.full_part_number,
        "weight_kg": result.weight_kg,
        "price_jpy": result.price_jpy,
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE oem_parts
            SET
              full_part_number = COALESCE(%s, full_part_number),
              weight_kg = COALESCE(%s, weight_kg),
              price_jpy = COALESCE(%s, price_jpy),
              impex_status = %s,
              impex_checked_at = now(),
              impex_payload = %s::jsonb,
              updated_at = now()
            WHERE root_arib = %s
              AND (
                part_number = %s
                OR normalized_part_number = %s
              )
            """,
            (
                result.full_part_number,
                result.weight_kg,
                result.price_jpy,
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
        cleaned = []
        seen: set[str] = set()
        for pn in only_parts:
            pn = pn.strip()
            if not pn or pn in seen:
                continue
            seen.add(pn)
            cleaned.append(pn)
        return cleaned[: limit or None] if limit else cleaned

    clauses = ["root_arib = %s", "part_number IS NOT NULL", "BTRIM(part_number) <> ''"]
    params: list[Any] = [ROOT_ARIB]
    if force:
        pass
    elif retry_errors:
        clauses.append(
            "(impex_status IS NULL OR impex_status IN "
            "('error', 'rate_limited', 'not_found', 'no_yamaha'))"
        )
    else:
        # Always retry transient Impex bans/timeouts
        clauses.append("(impex_status IS NULL OR impex_status = 'rate_limited')")

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
    # Drop obvious junk (* , dots-only, etc.) — mark skipped in DB without HTTP
    return [pn for pn in rows if is_plausible_part_number(pn)]


def cmd_status(work_dir: Path) -> None:
    with _connect() as conn:
        ensure_schema(conn)
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  COUNT(*) AS parts,
                  COUNT(DISTINCT part_number) AS unique_pn,
                  COUNT(*) FILTER (WHERE impex_status IS NULL) AS pending,
                  COUNT(*) FILTER (WHERE impex_status = 'ok') AS ok,
                  COUNT(*) FILTER (WHERE impex_status = 'not_found') AS not_found,
                  COUNT(*) FILTER (WHERE impex_status = 'no_yamaha') AS no_yamaha,
                  COUNT(*) FILTER (WHERE impex_status = 'error') AS error,
                  COUNT(*) FILTER (WHERE impex_status = 'rate_limited') AS rate_limited,
                  COUNT(*) FILTER (WHERE impex_status = 'skipped') AS skipped,
                  COUNT(*) FILTER (WHERE price_jpy IS NOT NULL) AS with_price,
                  COUNT(*) FILTER (WHERE weight_kg IS NOT NULL) AS with_weight,
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


def _reset_transient_errors(conn: psycopg.Connection) -> int:
    """Re-queue rows that failed due to Impex 503/429/timeouts."""
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE oem_parts
            SET impex_status = NULL,
                impex_checked_at = NULL,
                updated_at = now()
            WHERE root_arib = %s
              AND (
                impex_status = 'rate_limited'
                OR (
                  impex_status = 'error'
                  AND (
                    COALESCE(impex_payload->>'error', '') ILIKE '%%503%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%429%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%502%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%504%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%timed out%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%timeout%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%temporarily unavailable%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%IncompleteRead%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%UNEXPECTED_EOF%%'
                    OR COALESCE(impex_payload->>'error', '') ILIKE '%%SSL%%'
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
    """Mark non-plausible part numbers without hitting Impex."""
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT DISTINCT part_number
            FROM oem_parts
            WHERE root_arib = %s
              AND impex_status IS NULL
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
        result = ImpexResult(query=pn, status="skipped", error="implausible part number")
        n += apply_result_to_db(conn, result)
    return n


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
                _log(f"fetch: re-queued transient 503/timeout errors rows={reset_n}")
            skipped = _mark_skipped_junk(conn, dry_run=dry_run)
            if skipped:
                _log(f"fetch: marked skipped junk part numbers ≈{skipped} rows")

        numbers = list_unique_pending(
            conn,
            force=force,
            retry_errors=retry_errors,
            limit=limit,
            only_parts=only_parts,
        )

    total = len(numbers)
    workers = max(1, min(workers, 16))  # hard cap — Impex returns 503 above ~8–12
    if workers > 12:
        _log(f"fetch: workers capped advice — using {workers}; prefer 8–12 to avoid 503")
    throttle = AdaptiveThrottle(min_interval=max(0.0, delay) if delay > 0 else 0.08)
    _log(
        f"fetch start root={ROOT_ARIB} unique={total} workers={workers} "
        f"throttle_min={throttle.min_interval}s force={force} "
        f"retry_errors={retry_errors} dry_run={dry_run}"
    )
    if total == 0:
        _log("fetch: nothing to do")
        return

    stats = {
        "ok": 0,
        "not_found": 0,
        "no_yamaha": 0,
        "error": 0,
        "rate_limited": 0,
        "skipped": 0,
        "db_rows": 0,
        "with_price": 0,
        "with_weight": 0,
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
            f"ok={snap['ok']} not_found={snap['not_found']} "
            f"rate_limited={snap['rate_limited']} error={snap['error']} "
            f"price={snap['with_price']} weight={snap['with_weight']} "
            f"full={snap['with_full']} db_rows={snap['db_rows']} "
            f"rate={rate:.2f}/s eta={eta / 60:.1f}min "
            f"workers={workers} throttle={throttle.snapshot()} last={pn}"
        )

    def _worker(pn: str) -> tuple[str, ImpexResult, int]:
        result = fetch_impex(pn, throttle=throttle)
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
            result = ImpexResult(query=pn, status="error", error=f"worker: {exc}")
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
            "weight_kg": result.weight_kg,
            "price_jpy": result.price_jpy,
            "hits": result.hits,
            "error": result.error,
        }
        append_jsonl(paths["results"], row, lock=file_lock)
        if result.status == "error":
            append_jsonl(paths["errors"], row, lock=file_lock)

        with stats_lock:
            done += 1
            stats[result.status] = stats.get(result.status, 0) + 1
            stats["db_rows"] += db_rows
            if result.price_jpy is not None:
                stats["with_price"] += 1
            if result.weight_kg is not None:
                stats["with_weight"] += 1
            if result.full_part_number:
                stats["with_full"] += 1
            last_pn = pn
            finished = done

        if dry_run and finished <= 5:
            _log(
                f"dry-run {pn} → status={result.status} "
                f"full={result.full_part_number} "
                f"weight={result.weight_kg} price={result.price_jpy}"
            )
        _maybe_log(force_log=(finished == total))

    # Bounded in-flight queue — don't submit 134k futures at once
    in_flight: dict[Any, str] = {}
    numbers_iter = iter(numbers)
    max_inflight = workers * 4

    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="impex") as pool:
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


def cmd_apply_cache(work_dir: Path, *, force: bool) -> None:
    """Re-apply results.jsonl into DB (no HTTP)."""
    paths = work_paths(work_dir)
    if not paths["results"].is_file():
        raise SystemExit(f"No cache: {paths['results']}")

    applied = 0
    skipped = 0
    t0 = time.time()
    with _connect() as conn:
        ensure_schema(conn)
        with paths["results"].open(encoding="utf-8") as fh:
            lines = fh.readlines()
        total = len(lines)
        _log(f"apply-cache start rows={total} force={force}")
        # last occurrence wins for a query
        latest: dict[str, dict[str, Any]] = {}
        for line in lines:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            q = row.get("query")
            if q:
                latest[q] = row

        items = list(latest.items())
        for i, (query, row) in enumerate(items, start=1):
            if not force:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        SELECT 1 FROM oem_parts
                        WHERE root_arib = %s AND part_number = %s
                          AND impex_status = 'ok' AND price_jpy IS NOT NULL
                        LIMIT 1
                        """,
                        (ROOT_ARIB, query),
                    )
                    if cur.fetchone():
                        skipped += 1
                        continue
            result = ImpexResult(
                query=query,
                status=str(row.get("status") or "error"),
                full_part_number=row.get("full_part_number"),
                weight_kg=row.get("weight_kg"),
                price_jpy=row.get("price_jpy"),
                hits=int(row.get("hits") or 0),
                error=row.get("error"),
                picked={"from_cache": True},
            )
            applied += apply_result_to_db(conn, result)
            if i == 1 or i == len(items) or i % 500 == 0:
                _log(f"apply-cache {i}/{len(items)} applied_rows={applied} skipped={skipped}")

    _log(f"apply-cache done in {time.time() - t0:.1f}s applied_rows={applied} skipped={skipped}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Yamaha YMH-JP Impex enrichment")
    parser.add_argument(
        "command",
        choices=["ensure-schema", "status", "fetch", "apply-cache"],
    )
    parser.add_argument("--work-dir", default=str(DEFAULT_WORK))
    parser.add_argument(
        "--delay",
        type=float,
        default=0.0,
        help="Optional pause inside each worker before HTTP (default: 0)",
    )
    parser.add_argument(
        "--workers",
        type=int,
        default=8,
        help="Parallel workers (default: 8, hard-capped at 16; 64 causes Impex 503)",
    )
    parser.add_argument("--limit", type=int, default=None, help="Max unique part numbers")
    parser.add_argument("--force", action="store_true", help="Re-fetch even if already checked")
    parser.add_argument(
        "--retry-errors",
        action="store_true",
        help="Also retry error/not_found/no_yamaha (ignored with --force)",
    )
    parser.add_argument(
        "--progress-every",
        type=int,
        default=50,
        help="Log every N completed items (also logs at least every 2s)",
    )
    parser.add_argument("--dry-run", action="store_true", help="HTTP only, no DB writes")
    parser.add_argument(
        "--part",
        action="append",
        default=None,
        help="Fetch only this part number (repeatable). Bypasses pending filter.",
    )
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
    elif args.command == "apply-cache":
        cmd_apply_cache(work_dir, force=args.force)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
