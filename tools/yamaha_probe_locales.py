#!/usr/bin/env python3
"""Probe Yamaha YPEC parts-search locales (lang_COUNTRY in URL path)."""

from __future__ import annotations

import argparse
import itertools
import json
import re
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

BASE_URL = (
    "https://ypec-sss.yamaha-motor.co.jp/ypec/ypec/b2c/html5/app/{locale}/parts-search/index.html"
)

ACCESS_DENIED = re.compile(r"<Code>\s*AccessDenied\s*</Code>", re.I)
VALID_HINT = re.compile(r"Parts Catalogue|parts-search|YAMAHA", re.I)

DEFAULT_LANGS = (
    "en",
    "fr",
    "es",
    "de",
    "it",
    "pt",
    "nl",
    "sv",
    "no",
    "da",
    "fi",
    "pl",
    "cs",
    "hu",
    "ro",
    "ru",
    "ja",
    "ko",
    "zh",
    "th",
    "tr",
    "ar",
)

# ISO 3166-1 alpha-2, North America first for quick scans.
DEFAULT_COUNTRIES = (
    "US",
    "CA",
    "MX",
    "GL",
    "BM",
    "PM",
    "GB",
    "IE",
    "FR",
    "DE",
    "IT",
    "ES",
    "PT",
    "NL",
    "BE",
    "CH",
    "AT",
    "SE",
    "NO",
    "DK",
    "FI",
    "PL",
    "CZ",
    "HU",
    "RO",
    "RU",
    "UA",
    "JP",
    "AU",
    "NZ",
    "SG",
    "HK",
    "TW",
    "KR",
    "IN",
    "TH",
    "MY",
    "PH",
    "ID",
    "VN",
    "BR",
    "AR",
    "CL",
    "CO",
    "PE",
    "ZA",
    "AE",
    "SA",
    "TR",
    "GR",
)

NORTH_AMERICA = {"US", "CA", "MX", "GL", "BM", "PM"}

HEADERS = {
    "User-Agent": "yamaha-locale-probe/1.0",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}


def probe_locale(locale: str, timeout: float) -> dict:
    url = BASE_URL.format(locale=locale)
    req = urllib.request.Request(url, headers=HEADERS, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read(8192).decode("utf-8", errors="replace")
            ctype = resp.headers.get("Content-Type", "")
            ok = resp.status == 200 and not ACCESS_DENIED.search(body) and VALID_HINT.search(body)
            return {
                "locale": locale,
                "status": resp.status,
                "valid": ok,
                "content_type": ctype,
                "snippet": body[:160].replace("\n", " "),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read(8192).decode("utf-8", errors="replace")
        denied = bool(ACCESS_DENIED.search(body))
        return {
            "locale": locale,
            "status": exc.code,
            "valid": False,
            "access_denied": denied,
            "content_type": exc.headers.get("Content-Type", ""),
            "snippet": body[:160].replace("\n", " "),
        }
    except urllib.error.URLError as exc:
        return {
            "locale": locale,
            "status": -1,
            "valid": False,
            "error": str(exc.reason),
            "snippet": "",
        }


def build_locales(
    *,
    langs: str,
    countries: str,
    extra: list[str],
    north_america_only: bool,
    include_short: bool,
) -> list[str]:
    if extra:
        base = list(extra)
    else:
        lang_list = [x.strip() for x in langs.split(",") if x.strip()]
        country_list = [x.strip().upper() for x in countries.split(",") if x.strip()]
        if north_america_only:
            country_list = [c for c in country_list if c in NORTH_AMERICA]
        base = [f"{lang}_{country}" for lang, country in itertools.product(lang_list, country_list)]

    if include_short:
        short = [
            "ja", "en", "fr", "de", "es", "it", "pt", "nl", "ru", "ko", "zh",
            "us", "ca", "mx", "gb", "eu", "au", "br",
        ]
        base = short + base

    seen: set[str] = set()
    out: list[str] = []
    for loc in base:
        if loc not in seen:
            seen.add(loc)
            out.append(loc)
    return out


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Find valid Yamaha parts-search locales (lang_COUNTRY in URL)."
    )
    parser.add_argument(
        "--locale",
        action="append",
        default=[],
        help="Explicit locale(s), e.g. en_US (repeatable). Disables cartesian scan.",
    )
    parser.add_argument(
        "--langs",
        default=",".join(DEFAULT_LANGS),
        help=f"Comma-separated language codes (default: {len(DEFAULT_LANGS)} langs)",
    )
    parser.add_argument(
        "--countries",
        default=",".join(DEFAULT_COUNTRIES),
        help=f"Comma-separated country codes (default: {len(DEFAULT_COUNTRIES)} countries)",
    )
    parser.add_argument(
        "--north-america-only",
        action="store_true",
        help="Only scan US/CA/MX/GL/BM/PM country codes from --countries list",
    )
    parser.add_argument(
        "--include-short",
        action="store_true",
        help='Also probe short paths like "ja", "us", "ca" (no _COUNTRY suffix)',
    )
    parser.add_argument("--workers", type=int, default=16)
    parser.add_argument("--timeout", type=float, default=15.0)
    parser.add_argument("--json", action="store_true", help="Print JSON summary at end")
    args = parser.parse_args()

    locales = build_locales(
        langs=args.langs,
        countries=args.countries,
        extra=args.locale,
        north_america_only=args.north_america_only,
        include_short=args.include_short,
    )
    if not locales:
        print("Nothing to probe", file=sys.stderr)
        return 1

    print(f"Probing {len(locales)} locale(s) ...", flush=True)
    started = time.monotonic()
    results: list[dict] = []

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futures = {pool.submit(probe_locale, loc, args.timeout): loc for loc in locales}
        done = 0
        for future in as_completed(futures):
            result = future.result()
            results.append(result)
            done += 1
            loc = result["locale"]
            if result.get("valid"):
                flag = "NA" if loc.split("_", 1)[-1] in NORTH_AMERICA else "  "
                print(f"[OK ] [{flag}] {loc} status={result['status']}", flush=True)
            elif result.get("access_denied"):
                print(f"[DEN]      {loc}", flush=True)
            elif done <= 5 or done == len(locales):
                print(
                    f"[---]      {loc} status={result.get('status')} "
                    f"{result.get('error') or result.get('snippet', '')[:60]}",
                    flush=True,
                )

    valid = sorted(r["locale"] for r in results if r.get("valid"))
    na_valid = sorted(loc for loc in valid if loc.split("_", 1)[-1] in NORTH_AMERICA)
    elapsed = time.monotonic() - started

    print("\n=== Valid locales ===")
    if valid:
        for loc in valid:
            mark = " <- North America" if loc.split("_", 1)[-1] in NORTH_AMERICA else ""
            print(f"{loc}{mark}")
    else:
        print("(none)")

    print(f"\nTotal valid: {len(valid)} / {len(locales)}, NA valid: {len(na_valid)}, elapsed {elapsed:.1f}s")
    if args.json:
        print(json.dumps({"valid": valid, "north_america": na_valid, "results": results}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
