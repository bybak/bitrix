#!/usr/bin/env python3
"""
Enrich the imported catalog CSV with explicit brand columns extracted from "Краткий текст".

Adds columns:
- "Бренд"        (raw extracted value, best-effort)
- "Бренд (норм)" (normalized value for indexing/dedup)

Example:
  python3 tools/enrich_csv_with_brand.py \
    --in "/Users/andrey/cursor_projects/bitrix/www/Каталог 22-10-2025_17-25-15.csv" \
    --out "/Users/andrey/cursor_projects/bitrix/www/Каталог 22-10-2025_17-25-15.with_brand.csv" \
    --in-encoding cp1251 --out-encoding cp1251
"""

from __future__ import annotations

import argparse
import csv
import html as html_lib
import io
import os
import re
import sys
import time
from pathlib import Path


CSV_DELIM = ";"
COL_PREVIEW = "Краткий текст"
COL_BRAND = "Бренд"
COL_BRAND_NORM = "Бренд (норм)"


LABELS = [
    "производитель",
    "изготовитель",
    "бренд",
    "марка",
    "фирма",
    "manufacturer",
    "brand",
    "make",
    "vendor",
    "company",
]


def log(msg: str) -> None:
    ts = time.strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", file=sys.stderr, flush=True)


def normalize_brand(s: str) -> str:
    s = (s or "").strip().upper()
    s = s.replace("Ё", "Е")
    s = re.sub(r"[^A-ZА-Я0-9]+", "", s, flags=re.U)
    return s


def _textify_preview(preview_html: str) -> str:
    s = preview_html or ""
    if not s:
        return ""
    # Keep separators
    s = re.sub(r"<br\\s*/?>", "\n", s, flags=re.I)
    s = html_lib.unescape(s)
    s = re.sub(r"<[^>]+>", " ", s)
    s = s.replace("\xa0", " ").replace("\t", " ")
    s = re.sub(r"\s+", " ", s, flags=re.U).strip()
    return s


def extract_brand_from_preview(preview_html: str) -> str:
    s = _textify_preview(preview_html)
    if not s:
        return ""

    label_re = "|".join(re.escape(x) for x in LABELS)
    # Try "Label: VALUE" or "Label - VALUE" patterns
    m = re.search(rf"(?:^|[;,\.\(\)\[\]\s])(?:{label_re})\s*[:\-—=]?\s*([^;,\.\n\r]{{1,80}})", s, flags=re.I | re.U)
    if not m:
        return ""
    brand = (m.group(1) or "").strip()

    # Clean common trailing fragments
    brand = re.sub(r"\s*\(.*$", "", brand, flags=re.U).strip()
    brand = brand.strip(" \t\r\n\"'`")
    # If it's a long phrase, keep first token-ish chunk
    brand = (re.split(r"\s{2,}|\s\|\s|\s/\s", brand, maxsplit=1, flags=re.U)[0] or "").strip()
    return brand


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="inp", required=True, help="Input CSV path")
    ap.add_argument("--out", dest="out", required=True, help="Output CSV path")
    ap.add_argument("--in-encoding", default="cp1251")
    ap.add_argument("--out-encoding", default="")
    ap.add_argument("--overwrite", action="store_true", help="Allow overwriting output file")
    ap.add_argument("--progress-every", type=int, default=50000)
    ap.add_argument("--unknown-label", default="Unknown brand", help="Brand value when extraction fails")
    ap.add_argument("--brand-unk", default="", help="If set, use this for empty brand_norm (override)")
    args = ap.parse_args()

    inp = Path(args.inp).expanduser().resolve()
    out = Path(args.out).expanduser().resolve()
    if not inp.is_file():
        raise SystemExit(f"Input CSV not found: {inp}")
    if out.exists() and not args.overwrite:
        raise SystemExit(f"Output exists (use --overwrite): {out}")
    out.parent.mkdir(parents=True, exist_ok=True)

    out_enc = args.out_encoding.strip() or args.in_encoding.strip() or "cp1251"

    size = inp.stat().st_size
    log(f"start: in={inp} out={out} in_enc={args.in_encoding} out_enc={out_enc}")

    rows = 0
    with_brand = 0
    # Use binary streams + TextIOWrapper so we can reliably call tell() on the underlying
    # binary file for progress (TextIOWrapper.tell() may be disabled after iteration).
    with open(inp, "rb") as fin_b, open(out, "wb") as fout_b:
        fin = io.TextIOWrapper(fin_b, encoding=args.in_encoding, errors="replace", newline="")
        fout = io.TextIOWrapper(fout_b, encoding=out_enc, errors="replace", newline="")

        r = csv.reader(fin, delimiter=CSV_DELIM)
        w = csv.writer(fout, delimiter=CSV_DELIM, quotechar='"', quoting=csv.QUOTE_MINIMAL)

        headers = next(r, None)
        if not headers:
            raise SystemExit("Empty CSV (no header)")
        headers = [h.strip() for h in headers]

        if COL_PREVIEW not in headers:
            raise SystemExit(f"Missing required column: {COL_PREVIEW}")

        # ensure columns exist
        if COL_BRAND not in headers:
            headers.append(COL_BRAND)
        if COL_BRAND_NORM not in headers:
            headers.append(COL_BRAND_NORM)
        w.writerow(headers)

        idx_preview = headers.index(COL_PREVIEW)
        idx_brand = headers.index(COL_BRAND)
        idx_brand_norm = headers.index(COL_BRAND_NORM)

        for row in r:
            rows += 1
            # pad/merge tail to match headers length
            if len(row) < len(headers):
                row = row + [""] * (len(headers) - len(row))
            elif len(row) > len(headers):
                head = row[: len(headers) - 1]
                tail = row[len(headers) - 1 :]
                row = head + [";".join(tail)]

            preview = row[idx_preview] if idx_preview < len(row) else ""

            brand = (row[idx_brand] if idx_brand < len(row) else "").strip()
            brand_norm = (row[idx_brand_norm] if idx_brand_norm < len(row) else "").strip()

            if not brand and not brand_norm:
                brand = extract_brand_from_preview(preview)
                brand_norm = normalize_brand(brand)
                if brand or brand_norm:
                    with_brand += 1
                else:
                    # Extraction failed → write explicit unknown brand
                    brand = args.unknown_label
                    brand_norm = normalize_brand(brand)

            if not brand_norm and args.brand_unk:
                brand_norm = args.brand_unk
            if not brand:
                brand = args.unknown_label
            if not brand_norm:
                brand_norm = normalize_brand(brand)

            row[idx_brand] = brand
            row[idx_brand_norm] = brand_norm

            w.writerow(row[: len(headers)])

            if args.progress_every > 0 and (rows % args.progress_every) == 0:
                done_bytes = fin_b.tell()
                pct = (done_bytes / size * 100.0) if size > 0 else 0.0
                log(f"progress: {pct:6.2f}% rows={rows} extracted={with_brand}")

        fout.flush()

    log(f"done: rows={rows} extracted={with_brand}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

