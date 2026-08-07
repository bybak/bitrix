#!/usr/bin/env python3
"""Temporary probe: find baseCode values that return HTTP 200."""

from __future__ import annotations

import argparse
import json
import string
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

URL = "https://parts.yamaha-motor.co.jp/ypec_b2c/services/html5/model_name_list/"

DEFAULT_BODY = {
    "productId": "14",
    "displacementType": "7",
    "langId": "25",
}

HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json, text/plain, */*",
    "User-Agent": "yamaha-basecode-probe/1.0",
}


def probe_base_code(base_code: str, timeout: float) -> tuple[str, int]:
    payload = json.dumps({**DEFAULT_BODY, "baseCode": base_code}).encode("utf-8")
    req = urllib.request.Request(URL, data=payload, headers=HEADERS, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return base_code, resp.status
    except urllib.error.HTTPError as exc:
        return base_code, exc.code
    except urllib.error.URLError:
        return base_code, -1


def build_alpha_numeric_codes(
    letters: str,
    start_num: int,
    end_num: int,
    width: int,
) -> list[str]:
    codes: list[str] = []
    for letter in letters:
        for num in range(start_num, end_num + 1):
            codes.append(f"{letter}{num:0{width}d}")
    return codes


def build_single_prefix_codes(prefix: str, start: int, end: int, width: int) -> list[str]:
    if prefix:
        return [f"{prefix}{i:0{width}d}" for i in range(start, end + 1)]
    return [f"{i:0{width}d}" for i in range(start, end + 1)]


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Probe Yamaha baseCode values (default: A000..Z999)."
    )
    parser.add_argument(
        "--letters",
        type=str,
        default="A-Z",
        help='Letter range, e.g. "A-Z" or "A,C,E" (default: A-Z)',
    )
    parser.add_argument("--start", type=int, default=0, help="Start number, inclusive (default: 0)")
    parser.add_argument("--end", type=int, default=999, help="End number, inclusive (default: 999)")
    parser.add_argument("--width", type=int, default=3, help="Digits width (default: 3)")
    parser.add_argument(
        "--prefix",
        type=str,
        default="",
        help='Single fixed prefix mode, e.g. "U" → U000..U999 (disables A-Z scan)',
    )
    parser.add_argument(
        "--numeric-only",
        action="store_true",
        help="Numeric codes only, e.g. 0000..9999 with --width 4",
    )
    parser.add_argument("--workers", type=int, default=20, help="Parallel workers (default: 20)")
    parser.add_argument("--timeout", type=float, default=15.0, help="Request timeout seconds")
    args = parser.parse_args()

    max_num = 10 ** args.width - 1
    if args.start < 0 or args.end > max_num or args.start > args.end:
        print(f"Range must be within 0..{max_num:0{args.width}d}", file=sys.stderr)
        return 1

    if args.prefix or args.numeric_only:
        prefix = "" if args.numeric_only else args.prefix
        codes = build_single_prefix_codes(prefix, args.start, args.end, args.width)
    else:
        letters = parse_letters(args.letters)
        if not letters:
            print("No letters to scan", file=sys.stderr)
            return 1
        codes = build_alpha_numeric_codes(letters, args.start, args.end, args.width)

    ok_codes: list[str] = []
    started = time.monotonic()
    done = 0
    total = len(codes)

    print(f"Probing {total} baseCode values ({codes[0]}..{codes[-1]}) with {args.workers} workers...")

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as pool:
        futures = {pool.submit(probe_base_code, code, args.timeout): code for code in codes}
        for future in as_completed(futures):
            base_code, status = future.result()
            done += 1
            if status == 200:
                ok_codes.append(base_code)
                print(f"[200] {base_code}", flush=True)
            if done % 500 == 0 or done == total:
                elapsed = time.monotonic() - started
                print(f"... progress {done}/{total} ({elapsed:.1f}s)", flush=True)

    ok_codes.sort()
    elapsed = time.monotonic() - started

    print("\n=== baseCode with HTTP 200 ===")
    if ok_codes:
        for code in ok_codes:
            print(code)
    else:
        print("(none)")

    print(f"\nTotal 200: {len(ok_codes)} / {total}, elapsed {elapsed:.1f}s")
    print(json.dumps(ok_codes, ensure_ascii=False))
    return 0


def parse_letters(spec: str) -> str:
    spec = spec.strip().upper()
    if spec == "A-Z":
        return string.ascii_uppercase
    if "-" in spec and "," not in spec:
        left, right = spec.split("-", 1)
        if len(left) == 1 and len(right) == 1:
            start = string.ascii_uppercase.index(left)
            end = string.ascii_uppercase.index(right)
            if start <= end:
                return string.ascii_uppercase[start : end + 1]
    letters = []
    for part in spec.split(","):
        part = part.strip()
        if len(part) == 1 and part in string.ascii_uppercase:
            letters.append(part)
    return "".join(letters)


if __name__ == "__main__":
    raise SystemExit(main())
