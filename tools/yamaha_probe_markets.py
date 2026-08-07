#!/usr/bin/env python3
"""Probe Yamaha baseCode values via product_list and show market (destination)."""

from __future__ import annotations

import argparse
import json
import string
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

API_URL = "https://parts.yamaha-motor.co.jp/ypec_b2c/services/html5/product_list/"
LANG_ID = "25"

HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json, text/plain, */*",
    "User-Agent": "yamaha-market-probe/1.0",
}


def probe_base_code(base_code: str, timeout: float) -> dict:
    payload = json.dumps({"baseCode": base_code, "langId": LANG_ID}).encode("utf-8")
    req = urllib.request.Request(API_URL, data=payload, headers=HEADERS, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            if resp.status != 200:
                return {"base_code": base_code, "status": resp.status, "ok": False}
            data = json.loads(raw)
            ctx = data.get("userContext") or {}
            products = len(data.get("productDataCollection") or [])
            destination = (ctx.get("destination") or "").strip()
            currency = (ctx.get("currencyCode") or "").strip()
            ok = products > 0 and bool(destination or currency)
            return {
                "base_code": base_code,
                "status": 200,
                "ok": ok,
                "destination": destination,
                "currency": currency,
                "dest_group": ctx.get("destGroupCode") or "",
                "products": products,
            }
    except urllib.error.HTTPError as exc:
        return {"base_code": base_code, "status": exc.code, "ok": False}
    except (urllib.error.URLError, json.JSONDecodeError, TimeoutError):
        return {"base_code": base_code, "status": -1, "ok": False}


def build_codes(args: argparse.Namespace) -> list[str]:
    if args.codes:
        return list(dict.fromkeys(args.codes))

    if args.us_scan:
        codes: list[str] = []
        for prefix in ("US", "USA", "U"):
            for i in range(args.start, args.end + 1):
                width = max(args.width, len(str(args.end)))
                codes.append(f"{prefix}{i:0{width}d}" if prefix != "U" else f"U{i:03d}")
        # dedupe while keeping order
        return list(dict.fromkeys(codes))

    if args.prefix or args.numeric_only:
        prefix = "" if args.numeric_only else args.prefix
        return [f"{prefix}{i:0{args.width}d}" for i in range(args.start, args.end + 1)]

    letters = string.ascii_uppercase if args.letters == "A-Z" else args.letters
    codes = []
    for letter in letters:
        for num in range(args.start, args.end + 1):
            codes.append(f"{letter}{num:0{args.width}d}")
    return codes


def main() -> int:
    parser = argparse.ArgumentParser(description="Probe Yamaha product_list baseCode markets.")
    parser.add_argument("--codes", action="append", default=[], help="Explicit baseCode (repeatable)")
    parser.add_argument("--prefix", default="", help='Fixed prefix, e.g. "US"')
    parser.add_argument("--us-scan", action="store_true", help="Scan US/USA/U### patterns")
    parser.add_argument("--numeric-only", action="store_true")
    parser.add_argument("--letters", default="A-Z")
    parser.add_argument("--start", type=int, default=0)
    parser.add_argument("--end", type=int, default=999)
    parser.add_argument("--width", type=int, default=3)
    parser.add_argument("--workers", type=int, default=20)
    parser.add_argument("--timeout", type=float, default=15.0)
    parser.add_argument("--filter-dest", default="", help="Only print if destination contains this")
    args = parser.parse_args()

    codes = build_codes(args)
    if not codes:
        print("Nothing to probe", file=sys.stderr)
        return 1

    hits: list[dict] = []
    started = time.monotonic()
    print(f"Probing {len(codes)} baseCode(s) via product_list ...", flush=True)

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futures = {pool.submit(probe_base_code, code, args.timeout): code for code in codes}
        done = 0
        for future in as_completed(futures):
            result = future.result()
            done += 1
            if result.get("ok"):
                dest = result.get("destination") or "?"
                if args.filter_dest and args.filter_dest.upper() not in dest.upper():
                    continue
                hits.append(result)
                print(
                    f"[200] {result['base_code']} dest={dest} "
                    f"currency={result.get('currency')} products={result.get('products')}",
                    flush=True,
                )
            if done % 500 == 0 or done == len(codes):
                print(f"... {done}/{len(codes)}", flush=True)

    hits.sort(key=lambda x: x["base_code"])
    elapsed = time.monotonic() - started
    print("\n=== Valid baseCode ===")
    for h in hits:
        print(
            f"{h['base_code']}\t{h.get('destination')}\t{h.get('currency')}\tproducts={h.get('products')}"
        )
    print(f"\nTotal: {len(hits)} / {len(codes)}, elapsed {elapsed:.1f}s")
    print(json.dumps([h["base_code"] for h in hits], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
