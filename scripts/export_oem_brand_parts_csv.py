#!/usr/bin/env python3
"""Export OEM parts CSV: Артикул;Наименование;Бренд;Цена

Default: Husqvarna (HUM) + KTM from remotors oem_db.
Price is taken from oem_assembly_parts.raw_payload.price_text (prefers € amounts).
"""

from __future__ import annotations

import argparse
import csv
import os
import re
import sys
from pathlib import Path

try:
    import psycopg
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg — run via scripts/export_oem_brand_parts_csv.sh") from exc


DEFAULT_BRANDS: dict[str, str] = {
    "HUM": "Husqvarna",
    "KTM": "KTM",
}

PRICE_EUR_RE = re.compile(r"€|\d")


def _default_dsn() -> str:
    return (
        os.environ.get("OEM_DATABASE_DSN")
        or "postgresql://oem_user:oem_password@oem_db:5432/oem_catalog"
    )


def _pick_price(prices: list[str]) -> str:
    cleaned = [p.strip() for p in prices if p and str(p).strip()]
    if not cleaned:
        return ""
    for price in cleaned:
        if "call us" in price.lower():
            continue
        if PRICE_EUR_RE.search(price):
            return price
    for price in cleaned:
        if "call us" not in price.lower():
            return price
    return cleaned[0]


def export_brand(
    conn: psycopg.Connection,
    *,
    root_arib: str,
    brand_name: str,
    out_path: Path,
    delimiter: str,
) -> int:
    sql = """
        SELECT
          p.part_number,
          COALESCE(p.name, '') AS name,
          array_agg(DISTINCT NULLIF(BTRIM(ap.raw_payload->>'price_text'), ''))
            FILTER (WHERE NULLIF(BTRIM(ap.raw_payload->>'price_text'), '') IS NOT NULL)
            AS prices
        FROM oem_parts p
        LEFT JOIN oem_assembly_parts ap ON ap.part_id = p.id
        WHERE p.root_arib = %s
        GROUP BY p.id, p.part_number, p.name
        ORDER BY p.part_number
    """
    out_path.parent.mkdir(parents=True, exist_ok=True)
    rows_written = 0
    with conn.cursor() as cur, out_path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.writer(fh, delimiter=delimiter, quoting=csv.QUOTE_MINIMAL)
        writer.writerow(["Артикул", "Наименование", "Бренд", "Цена"])
        cur.execute(sql, (root_arib,))
        for row in cur:
            prices = [p for p in (row["prices"] or []) if p]
            writer.writerow(
                [
                    row["part_number"] or "",
                    row["name"] or "",
                    brand_name,
                    _pick_price(prices),
                ]
            )
            rows_written += 1
    return rows_written


def parse_brands(raw: str | None) -> dict[str, str]:
    if not raw:
        return dict(DEFAULT_BRANDS)
    mapping: dict[str, str] = {}
    for chunk in raw.split(","):
        chunk = chunk.strip()
        if not chunk:
            continue
        if "=" in chunk:
            code, name = chunk.split("=", 1)
            mapping[code.strip().upper()] = name.strip()
        else:
            code = chunk.upper()
            mapping[code] = DEFAULT_BRANDS.get(code, code)
    return mapping


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Export OEM parts CSV by brand")
    parser.add_argument("--dsn", default=_default_dsn())
    parser.add_argument(
        "--brands",
        default=None,
        help="HUM=Husqvarna,KTM=KTM (default: both)",
    )
    parser.add_argument("--out-dir", default="/app/storage/exports")
    parser.add_argument(
        "--delimiter",
        default=";",
        help="CSV delimiter (default ';' for Excel). Use ':' if needed.",
    )
    args = parser.parse_args(argv)

    brands = parse_brands(args.brands)
    out_dir = Path(args.out_dir)
    print(f"brands={brands} out={out_dir}", flush=True)

    with psycopg.connect(args.dsn, row_factory=dict_row) as conn:
        for root_arib, brand_name in brands.items():
            safe = re.sub(r"[^\w\-]+", "_", brand_name, flags=re.UNICODE)
            out_path = out_dir / f"oem_parts_{safe}_{root_arib}.csv"
            n = export_brand(
                conn,
                root_arib=root_arib,
                brand_name=brand_name,
                out_path=out_path,
                delimiter=args.delimiter,
            )
            print(f"OK {brand_name} ({root_arib}): {n} rows → {out_path}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
