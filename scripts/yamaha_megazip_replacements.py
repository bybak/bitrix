#!/usr/bin/env python3
"""Collect MegaZip «Замена» links for Yamaha YMH-JP part numbers.

For each unique YMH-JP part number search MegaZip. On a search listing like:

  95827-10050-00  ← marked «Замена»
  95817-10050-00  ← original / orderable

we store: original=95817-10050-00 → replacements include 95827-10050-00

Local SQLite (resumable) + CSV export:
  column1 = original number
  column2 = replacements comma-separated

Examples:
  ./scripts/yamaha_megazip_replacements.sh fetch --part 95827-10050
  ./scripts/yamaha_megazip_replacements.sh fetch --workers 32
  ./scripts/yamaha_megazip_replacements.sh status
  ./scripts/yamaha_megazip_replacements.sh export-csv
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import random
import re
import sqlite3
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

try:
    import psycopg
    from bs4 import BeautifulSoup
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit(
        "Need psycopg + beautifulsoup4 — run via scripts/yamaha_megazip_replacements.sh"
    ) from exc


ROOT_ARIB = "YMH-JP"
MEGAZIP_ORIGIN = "https://www.megazip.ru"
SEARCH_URL = f"{MEGAZIP_ORIGIN}/search"
DEFAULT_WORK = Path("/app/storage/yamaha-megazip-replacements")
USER_AGENT = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
)
PART_NUMBER_OK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.\-/]*$")
# MegaZip search section header must be exactly this brand block
YAMAHA_SECTION_RE = re.compile(r"^\s*Запчасти\s+Yamaha\s*$", re.I)
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


def _log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def is_plausible_part_number(pn: str) -> bool:
    t = (pn or "").strip()
    return bool(t) and bool(PART_NUMBER_OK_RE.match(t)) and len(t) >= 5


def _norm_pn(pn: str) -> str:
    return re.sub(r"[^A-Za-z0-9]", "", (pn or "")).upper()


def work_paths(work_dir: Path) -> dict[str, Path]:
    work_dir.mkdir(parents=True, exist_ok=True)
    return {
        "db": work_dir / "replacements.sqlite",
        "csv": work_dir / "yamaha_jp_replacements.csv",
        "stats": work_dir / "last_stats.json",
    }


# --- SQLite store -----------------------------------------------------------------


def db_connect(path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(path, timeout=60, check_same_thread=False)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS checked (
          part_number TEXT PRIMARY KEY,
          status TEXT NOT NULL,
          originals_json TEXT,
          replacements_json TEXT,
          error TEXT,
          checked_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS edges (
          original TEXT NOT NULL,
          replacement TEXT NOT NULL,
          source_query TEXT,
          PRIMARY KEY (original, replacement)
        );
        CREATE INDEX IF NOT EXISTS ix_edges_original ON edges(original);
        """
    )
    conn.commit()
    return conn


class Store:
    def __init__(self, path: Path) -> None:
        self.path = path
        self._lock = threading.Lock()
        self._conn = db_connect(path)

    def close(self) -> None:
        with self._lock:
            self._conn.close()

    def is_done(self, part_number: str) -> bool:
        with self._lock:
            row = self._conn.execute(
                "SELECT status FROM checked WHERE part_number = ?",
                (part_number,),
            ).fetchone()
        return bool(row) and row["status"] not in ("error", "rate_limited")

    def pending_from(self, numbers: list[str], *, force: bool, retry_errors: bool) -> list[str]:
        out: list[str] = []
        with self._lock:
            for pn in numbers:
                row = self._conn.execute(
                    "SELECT status FROM checked WHERE part_number = ?",
                    (pn,),
                ).fetchone()
                if row is None:
                    out.append(pn)
                    continue
                st = row["status"]
                if force:
                    out.append(pn)
                elif retry_errors and st in ("error", "rate_limited", "not_found"):
                    out.append(pn)
                elif st in ("error", "rate_limited"):
                    out.append(pn)
        return out

    def save_result(
        self,
        *,
        query: str,
        status: str,
        originals: list[str],
        replacements: list[str],
        edges: list[tuple[str, str]],
        error: str | None = None,
    ) -> None:
        now = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        with self._lock:
            self._conn.execute(
                """
                INSERT INTO checked(part_number, status, originals_json, replacements_json, error, checked_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(part_number) DO UPDATE SET
                  status=excluded.status,
                  originals_json=excluded.originals_json,
                  replacements_json=excluded.replacements_json,
                  error=excluded.error,
                  checked_at=excluded.checked_at
                """,
                (
                    query,
                    status,
                    json.dumps(originals, ensure_ascii=False),
                    json.dumps(replacements, ensure_ascii=False),
                    error,
                    now,
                ),
            )
            for original, replacement in edges:
                if not original or not replacement:
                    continue
                if _norm_pn(original) == _norm_pn(replacement):
                    continue
                self._conn.execute(
                    """
                    INSERT OR IGNORE INTO edges(original, replacement, source_query)
                    VALUES (?, ?, ?)
                    """,
                    (original, replacement, query),
                )
            self._conn.commit()

    def stats(self) -> dict[str, Any]:
        with self._lock:
            by_status = {
                r["status"]: r["c"]
                for r in self._conn.execute(
                    "SELECT status, COUNT(*) AS c FROM checked GROUP BY status"
                )
            }
            edges = self._conn.execute("SELECT COUNT(*) AS c FROM edges").fetchone()["c"]
            originals = self._conn.execute(
                "SELECT COUNT(DISTINCT original) AS c FROM edges"
            ).fetchone()["c"]
        return {
            "checked_by_status": by_status,
            "checked_total": sum(by_status.values()),
            "edges": edges,
            "originals_with_replacements": originals,
        }

    def export_csv(self, out_path: Path, delimiter: str = ";") -> int:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with self._lock:
            rows = self._conn.execute(
                """
                SELECT original, GROUP_CONCAT(replacement, ', ') AS repls
                FROM (
                  SELECT original, replacement
                  FROM edges
                  ORDER BY original, replacement
                )
                GROUP BY original
                ORDER BY original
                """
            ).fetchall()
        n = 0
        with out_path.open("w", encoding="utf-8-sig", newline="") as fh:
            writer = csv.writer(fh, delimiter=delimiter, quoting=csv.QUOTE_MINIMAL)
            writer.writerow(["Номер", "Замены"])
            for row in rows:
                writer.writerow([row["original"], row["repls"] or ""])
                n += 1
        return n


# --- HTTP / parse -----------------------------------------------------------------


@dataclass
class SearchHit:
    full_part_number: str
    manufacturer: str
    is_replacement: bool
    name_link: str


class AdaptiveThrottle:
    def __init__(self, min_interval: float = 0.05) -> None:
        self._lock = threading.Lock()
        self.min_interval = min_interval
        self.interval = min_interval
        self._next_ts = 0.0
        self.strikes = 0

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
            self.strikes = max(0, self.strikes - 1)
            self.interval = max(self.min_interval, self.interval * 0.95)

    def on_rate_limit(self) -> None:
        with self._lock:
            self.strikes += 1
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
    soup = BeautifulSoup(html, "lxml")
    h1 = soup.select_one("h1")
    if h1:
        text = unescape(h1.get_text(" ", strip=True))
        if "результат" in text.lower():
            return True
        if TITLE_RE.match(text) or re.match(
            r"^[A-Za-z0-9][A-Za-z0-9.\-/]*\s+.+,?\s*Yamaha\b",
            text,
            re.I,
        ):
            return False
    return bool(soup.select_one("table.s-catalog__items-list-table td.spare_name"))


def find_yamaha_parts_table(soup: BeautifulSoup):
    """Return catalog table only under heading «Запчасти Yamaha»."""
    for h2 in soup.select("h2"):
        title = unescape(h2.get_text(" ", strip=True))
        if not YAMAHA_SECTION_RE.match(title):
            continue
        table = h2.find_next("table", class_=re.compile(r"s-catalog__items-list-table"))
        if table is not None:
            return table
    return None


def parse_search_hits(html: str) -> list[SearchHit]:
    """Parse rows only from «Запчасти Yamaha» table. Empty if section missing."""
    soup = BeautifulSoup(html, "lxml")
    hits: list[SearchHit] = []
    table = find_yamaha_parts_table(soup)
    if table is None:
        return hits

    for tr in table.select("tbody tr"):
        name_td = tr.select_one("td.spare_name")
        if not name_td:
            continue
        a = name_td.select_one("a[href]")
        p_num = name_td.select_one("p")
        if not p_num:
            continue
        href = (a.get("href") if a else None) or tr.get("data-href") or ""
        full_pn = p_num.get_text(" ", strip=True)
        name_link = unescape(a.get_text(" ", strip=True)) if a else ""
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
        # Extra safety: skip non-Yamaha rows even inside the section
        if manufacturer and manufacturer.strip().lower() != "yamaha":
            continue
        if not manufacturer and "yamaha" not in name_link.lower() and "/yamaha/" not in href.lower():
            continue
        info = tr.select_one("td.tar")
        info_txt = info.get_text(" ", strip=True) if info else ""
        is_repl = bool(tr.select_one(".search-result__icon_type_replace")) or (
            "замена" in info_txt.lower()
        )
        if not full_pn:
            continue
        hits.append(
            SearchHit(
                full_part_number=full_pn.strip(),
                manufacturer=manufacturer or "Yamaha",
                is_replacement=is_repl,
                name_link=name_link,
            )
        )
    return hits


@dataclass
class FetchOutcome:
    query: str
    status: str
    originals: list[str]
    replacements: list[str]
    edges: list[tuple[str, str]]
    error: str | None = None


def extract_replacement_edges(query: str, html: str) -> FetchOutcome:
    """From search HTML: originals (no «Замена») ← replacements («Замена»).

    If MegaZip opens a product card directly (no search listing) → no replacements,
    status=product_page (skip).
    Only the «Запчасти Yamaha» block is used.
    """
    if not is_search_results_page(html):
        # Redirect straight to product card → no «Замена» list, skip
        return FetchOutcome(
            query=query,
            status="product_page",
            originals=[],
            replacements=[],
            edges=[],
        )

    hits = parse_search_hits(html)
    if not hits:
        # Search page but no «Запчасти Yamaha» section (other brands only / empty)
        return FetchOutcome(
            query=query,
            status="no_yamaha",
            originals=[],
            replacements=[],
            edges=[],
        )

    originals = [h.full_part_number for h in hits if not h.is_replacement]
    replacements = [h.full_part_number for h in hits if h.is_replacement]

    def uniq(items: list[str]) -> list[str]:
        seen: set[str] = set()
        out: list[str] = []
        for x in items:
            k = _norm_pn(x)
            if not k or k in seen:
                continue
            seen.add(k)
            out.append(x)
        return out

    originals = uniq(originals)
    replacements = uniq(replacements)

    if not replacements:
        return FetchOutcome(
            query=query,
            status="no_replacement",
            originals=originals,
            replacements=[],
            edges=[],
        )
    if not originals:
        return FetchOutcome(
            query=query,
            status="replacement_only",
            originals=[],
            replacements=replacements,
            edges=[],
        )

    edges = [(o, r) for o in originals for r in replacements]
    return FetchOutcome(
        query=query,
        status="ok",
        originals=originals,
        replacements=replacements,
        edges=edges,
    )


def fetch_one(part_number: str, *, throttle: AdaptiveThrottle | None = None) -> FetchOutcome:
    q = part_number.strip()
    if not q:
        return FetchOutcome(query=q, status="error", originals=[], replacements=[], edges=[], error="empty")
    url = f"{SEARCH_URL}?{urllib.parse.urlencode({'q': q})}"
    try:
        html, _final = http_get(url, throttle=throttle)
    except Exception as exc:  # noqa: BLE001
        status = "rate_limited" if _is_rate_limit_error(exc) else "error"
        return FetchOutcome(
            query=q,
            status=status,
            originals=[],
            replacements=[],
            edges=[],
            error=f"{type(exc).__name__}: {exc}",
        )
    return extract_replacement_edges(q, html)


# --- CLI commands -----------------------------------------------------------------


def list_unique_parts(*, limit: int | None, only_parts: list[str] | None) -> list[str]:
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

    with psycopg.connect(_dsn(), row_factory=dict_row) as conn, conn.cursor() as cur:
        sql = """
            SELECT DISTINCT part_number
            FROM oem_parts
            WHERE root_arib = %s
              AND part_number IS NOT NULL
              AND BTRIM(part_number) <> ''
            ORDER BY part_number
        """
        params: list[Any] = [ROOT_ARIB]
        if limit and limit > 0:
            sql += " LIMIT %s"
            params.append(limit)
        cur.execute(sql, params)
        rows = [r["part_number"] for r in cur.fetchall()]
    return [pn for pn in rows if is_plausible_part_number(pn)]


def cmd_status(work_dir: Path) -> None:
    paths = work_paths(work_dir)
    if not paths["db"].is_file():
        _log("no sqlite yet — run fetch first")
        print(json.dumps({"checked_total": 0}, indent=2), flush=True)
        return
    store = Store(paths["db"])
    try:
        print(json.dumps(store.stats(), ensure_ascii=False, indent=2), flush=True)
    finally:
        store.close()


def cmd_export_csv(work_dir: Path, *, delimiter: str) -> None:
    paths = work_paths(work_dir)
    if not paths["db"].is_file():
        raise SystemExit(f"No DB: {paths['db']} — run fetch first")
    store = Store(paths["db"])
    try:
        n = store.export_csv(paths["csv"], delimiter=delimiter)
        _log(f"export-csv rows={n} → {paths['csv']}")
    finally:
        store.close()


def cmd_fetch(
    work_dir: Path,
    *,
    workers: int,
    delay: float,
    limit: int | None,
    force: bool,
    retry_errors: bool,
    progress_every: int,
    only_parts: list[str] | None,
) -> None:
    paths = work_paths(work_dir)
    store = Store(paths["db"])
    throttle = AdaptiveThrottle(min_interval=max(0.0, delay) if delay > 0 else 0.05)
    workers = max(1, workers)

    all_numbers = list_unique_parts(limit=None if only_parts else limit, only_parts=only_parts)
    if only_parts and limit:
        all_numbers = all_numbers[:limit]
    numbers = store.pending_from(all_numbers, force=force, retry_errors=retry_errors)
    total = len(numbers)
    _log(
        f"fetch start unique_pending={total} catalog={len(all_numbers)} "
        f"workers={workers} throttle_min={throttle.min_interval}s"
    )
    if total == 0:
        _log("fetch: nothing to do")
        store.close()
        return

    stats = {
        "ok": 0,
        "no_replacement": 0,
        "product_page": 0,
        "no_yamaha": 0,
        "replacement_only": 0,
        "not_found": 0,
        "error": 0,
        "rate_limited": 0,
        "edges_added": 0,
    }
    stats_lock = threading.Lock()
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
            f"ok={snap['ok']} no_repl={snap['no_replacement']} "
            f"product_page={snap['product_page']} repl_only={snap['replacement_only']} "
            f"no_yamaha={snap['no_yamaha']} error={snap['error']} "
            f"rate_limited={snap['rate_limited']} edges={snap['edges_added']} "
            f"rate={rate:.2f}/s eta={eta / 60:.1f}min "
            f"workers={workers} throttle={throttle.snapshot()} last={pn}"
        )

    def _worker(pn: str) -> FetchOutcome:
        return fetch_one(pn, throttle=throttle)

    def _handle(fut: Any, pn_fallback: str) -> None:
        nonlocal done, last_pn
        pn = pn_fallback
        try:
            outcome = fut.result()
        except Exception as exc:  # noqa: BLE001
            outcome = FetchOutcome(
                query=pn,
                status="error",
                originals=[],
                replacements=[],
                edges=[],
                error=f"worker: {exc}",
            )
        store.save_result(
            query=outcome.query,
            status=outcome.status,
            originals=outcome.originals,
            replacements=outcome.replacements,
            edges=outcome.edges,
            error=outcome.error,
        )
        with stats_lock:
            done += 1
            stats[outcome.status] = stats.get(outcome.status, 0) + 1
            stats["edges_added"] += len(outcome.edges)
            last_pn = pn
            finished = done
        _maybe_log(force_log=(finished == total))

    in_flight: dict[Any, str] = {}
    it = iter(numbers)
    max_inflight = workers * 4

    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="mz-repl") as pool:
        def _fill() -> None:
            while len(in_flight) < max_inflight:
                try:
                    pn = next(it)
                except StopIteration:
                    return
                in_flight[pool.submit(_worker, pn)] = pn

        _fill()
        while in_flight:
            completed, _ = wait(in_flight.keys(), return_when=FIRST_COMPLETED)
            for fut in completed:
                pn = in_flight.pop(fut)
                _handle(fut, pn)
            _fill()

    elapsed = round(time.time() - t0, 1)
    out = {"elapsed_sec": elapsed, "total": total, "workers": workers, **stats, **store.stats()}
    paths["stats"].write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    n_csv = store.export_csv(paths["csv"])
    store.close()
    _log(f"fetch done in {elapsed}s ({elapsed / 60:.1f}min); csv_rows={n_csv} → {paths['csv']}")
    print(json.dumps(out, ensure_ascii=False, indent=2), flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Yamaha JP MegaZip replacements collector")
    parser.add_argument("command", choices=["fetch", "status", "export-csv"])
    parser.add_argument("--work-dir", default=str(DEFAULT_WORK))
    parser.add_argument("--workers", type=int, default=32)
    parser.add_argument("--delay", type=float, default=0.0)
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument("--force", action="store_true")
    parser.add_argument("--retry-errors", action="store_true")
    parser.add_argument("--progress-every", type=int, default=25)
    parser.add_argument("--part", action="append", default=None)
    parser.add_argument("--delimiter", default=";")
    args = parser.parse_args(argv)

    work_dir = Path(args.work_dir)
    if args.command == "status":
        cmd_status(work_dir)
    elif args.command == "export-csv":
        cmd_export_csv(work_dir, delimiter=args.delimiter)
    elif args.command == "fetch":
        cmd_fetch(
            work_dir,
            workers=max(1, args.workers),
            delay=max(0.0, args.delay),
            limit=args.limit,
            force=args.force,
            retry_errors=args.retry_errors,
            progress_every=max(1, args.progress_every),
            only_parts=args.part,
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
