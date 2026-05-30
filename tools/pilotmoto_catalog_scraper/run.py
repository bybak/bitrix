#!/usr/bin/env python3
"""CLI: парсинг pilotmoto.ru и экспорт CSV."""

from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from pilotmoto_scraper.config import load_config, resolve_db_path, resolve_output_csv, resolve_output_csv_alley
from pilotmoto_scraper import db as dbmod
from pilotmoto_scraper.crawl import run_crawl, run_detail_enrichment
from pilotmoto_scraper.export_csv import (
	_site_currency,
	export_products_csv_alley,
	export_products_csv_legacy,
	resolve_rub_per_kzt,
)


def main() -> int:
	ap = argparse.ArgumentParser(description="Парсер каталога pilotmoto.ru → SQLite + CSV")
	ap.add_argument("--config", "-c", default=None, help="Путь к config.yaml")
	ap.add_argument(
		"--export-only",
		action="store_true",
		help="Только выгрузить CSV из существующей БД без парсинга",
	)
	ap.add_argument("--no-progress", action="store_true", help="Отключить tqdm")
	ap.add_argument(
		"--max-categories",
		type=int,
		default=0,
		help="Ограничить число категорий (для теста; 0 = без ограничения)",
	)
	ap.add_argument(
		"--skip-detail",
		action="store_true",
		help="Не ходить в карточки товаров (данные только с листинга; по умолчанию так же, см. detail_enrichment в config)",
	)
	ap.add_argument(
		"--with-detail",
		action="store_true",
		help="Дополнительно обойти каждую карточку (устарело: основные поля уже на листинге)",
	)
	ap.add_argument(
		"--max-detail-products",
		type=int,
		default=0,
		help="Ограничить число карточек для обогащения (тест; 0 = все с detail_done=0)",
	)
	ap.add_argument(
		"--both-formats",
		action="store_true",
		help="Дополнительно сохранить eternal_price (легаси-формат). По умолчанию только stock_and_price.",
	)
	args = ap.parse_args()

	cfg = load_config(args.config)
	db_path = resolve_db_path(cfg)
	out_csv = resolve_output_csv(cfg)
	out_alley = resolve_output_csv_alley(cfg)

	try:
		conn = dbmod.connect(db_path)
		dbmod.init_schema(conn)
	except RuntimeError as e:
		print(f"[pilotmoto] Ошибка БД: {e}", file=sys.stderr)
		return 1

	want_detail = bool(cfg.get("detail_enrichment", False))
	if args.with_detail:
		want_detail = True
	if args.skip_detail:
		want_detail = False

	if not args.export_only:
		asyncio.run(
			run_crawl(
				cfg,
				conn,
				progress=not args.no_progress,
				max_categories=args.max_categories or None,
				leave_detail_pending=want_detail,
			)
		)
		if want_detail:
			asyncio.run(
				run_detail_enrichment(
					cfg,
					conn,
					progress=not args.no_progress,
					max_products=args.max_detail_products or None,
				)
			)

	enc = str(cfg.get("export_encoding", "utf-8-sig"))
	rpk = resolve_rub_per_kzt(cfg)
	if rpk is None and _site_currency(cfg) != "RUB":
		print(
			"[pilotmoto] Внимание: курс KZT→RUB из Bitrix недоступен. Задайте fallback_rub_per_kzt в config.yaml "
			"или site_currency: RUB для цен уже в рублях.\n",
			flush=True,
		)
	n = export_products_csv_alley(conn, out_alley, cfg, encoding=enc)
	msg_extra = ""
	if args.both_formats:
		n_leg = export_products_csv_legacy(conn, out_csv, cfg=cfg, encoding=enc)
		msg_extra = f", eternal_price строк: {n_leg}"
	st = dbmod.stats(conn)
	out_lines = (
		f"Готово. Товаров в БД: {st['products']}, с детальной карточкой: {st['products_detail_done']}, "
		f"страниц каталога: {st['category_pages']}, строк в stock_and_price: {n}{msg_extra}\n"
		f"stock_and_price: {out_alley}\n"
	)
	if args.both_formats:
		out_lines += f"eternal_price: {out_csv}\n"
	out_lines += f"БД: {db_path}"
	if rpk is not None:
		out_lines += f"\nмножитель цены→₽ (rub_per_unit): {rpk}"
	print(out_lines, flush=True)
	conn.close()
	return 0


if __name__ == "__main__":
	raise SystemExit(main())
