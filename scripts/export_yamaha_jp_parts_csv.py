#!/usr/bin/env python3
"""Export Yamaha YMH-JP parts CSV: Артикул;Наименование;Бренд;Цена

Артикул  — full_part_number (MegaZip), иначе part_number
Наименование — name_ru, иначе name
Бренд — Yamaha
Цена — price_rub (склад Япония), пусто если нет
"""

from __future__ import annotations

import argparse
import csv
import os
import re
from pathlib import Path

try:
    import psycopg
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg — run via scripts/export_yamaha_jp_parts_csv.sh") from exc


ROOT_ARIB = "YMH-JP"
BRAND = "Yamaha"
PART_NUMBER_OK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.\-/]*$")


def _default_dsn() -> str:
    return (
        os.environ.get("OEM_YAMAHA_DATABASE_DSN")
        or "postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog"
    )


def _format_price(value) -> str:
    if value is None:
        return ""
    try:
        n = float(value)
    except (TypeError, ValueError):
        return str(value).strip()
    if n == int(n):
        return str(int(n))
    return f"{n:.2f}".rstrip("0").rstrip(".")


def _is_plausible(pn: str) -> bool:
    t = (pn or "").strip()
    return bool(t) and bool(PART_NUMBER_OK_RE.match(t)) and len(t) >= 5


def export_csv(
    conn: psycopg.Connection,
    *,
    out_path: Path,
    delimiter: str,
    only_with_price: bool,
) -> int:
    sql = """
        SELECT
          part_number,
          full_part_number,
          name,
          name_ru,
          price_rub
        FROM oem_parts
        WHERE root_arib = %s
    """
    params: list = [ROOT_ARIB]
    if only_with_price:
        sql += " AND price_rub IS NOT NULL"
    # Prefer priced + named rows first so dedupe keeps the best one
    sql += """
        ORDER BY
          (price_rub IS NULL),
          (name_ru IS NULL OR BTRIM(name_ru) = ''),
          COALESCE(full_part_number, part_number),
          part_number
    """

    out_path.parent.mkdir(parents=True, exist_ok=True)
    rows_written = 0
    skipped = 0
    seen_articles: set[str] = set()
    with conn.cursor() as cur, out_path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.writer(fh, delimiter=delimiter, quoting=csv.QUOTE_MINIMAL)
        writer.writerow(["Артикул", "Наименование", "Бренд", "Цена"])
        cur.execute(sql, params)
        for row in cur:
            article = (row["full_part_number"] or row["part_number"] or "").strip()
            if not _is_plausible(article) and not _is_plausible(row["part_number"] or ""):
                skipped += 1
                continue
            if not _is_plausible(article):
                article = (row["part_number"] or "").strip()
            key = article.upper()
            if key in seen_articles:
                skipped += 1
                continue
            seen_articles.add(key)
            name = (row["name_ru"] or row["name"] or "").strip()
            writer.writerow([article, name, BRAND, _format_price(row["price_rub"])])
            rows_written += 1
    print(f"skipped_junk_or_dup={skipped}", flush=True)
    return rows_written


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Export Yamaha JP parts CSV")
    parser.add_argument("--dsn", default=_default_dsn())
    parser.add_argument("--out-dir", default="/app/storage/exports")
    parser.add_argument("--delimiter", default=";")
    parser.add_argument(
        "--only-with-price",
        action="store_true",
        help="Export only rows with price_rub (default: all YMH-JP parts)",
    )
    parser.add_argument(
        "--out-name",
        default=None,
        help="Output filename (default: oem_parts_Yamaha_YMH-JP.csv)",
    )
    args = parser.parse_args(argv)

    out_dir = Path(args.out_dir)
    out_name = args.out_name or "oem_parts_Yamaha_YMH-JP.csv"
    out_name = re.sub(r"[^\w.\-]+", "_", out_name)
    out_path = out_dir / out_name

    print(
        f"export Yamaha {ROOT_ARIB} only_with_price={args.only_with_price} → {out_path}",
        flush=True,
    )
    with psycopg.connect(args.dsn, row_factory=dict_row) as conn:
        n = export_csv(
            conn,
            out_path=out_path,
            delimiter=args.delimiter,
            only_with_price=args.only_with_price,
        )
    print(f"OK Yamaha ({ROOT_ARIB}): {n} rows → {out_path}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
