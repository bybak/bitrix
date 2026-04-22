#!/usr/bin/env python3
"""CLI: парсинг outdoorworld.kz и экспорт CSV."""

from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

# пакет рядом с run.py
sys.path.insert(0, str(Path(__file__).resolve().parent))

from outdoorworld_scraper.config import load_config, resolve_db_path, resolve_output_csv, resolve_output_csv_alley
from outdoorworld_scraper import db as dbmod
from outdoorworld_scraper.crawl import run_crawl, run_detail_enrichment
from outdoorworld_scraper.export_csv import (
	export_products_csv_alley,
	export_products_csv_legacy,
	resolve_rub_per_kzt,
)


def main() -> int:
	ap = argparse.ArgumentParser(description="Парсер каталога outdoorworld.kz → SQLite + CSV")
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
		help="Не ходить в карточки /product/ (по умолчанию данные с плитки листинга; см. detail_enrichment в config)",
	)
	ap.add_argument(
		"--with-detail",
		action="store_true",
		help="Дополнительно загрузить каждую страницу /product/…/ (обновить поля с карточки)",
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
		help="Дополнительно сохранить eternal_price (Производитель;…;Цена в ₽ по курсу, как stock_and_price, иначе со списка). "
		"По умолчанию только stock_and_price: Бренд;Артикул;Остаток;Цена в ₽ по курсу Bitrix.",
	)
	args = ap.parse_args()

	cfg = load_config(args.config)
	db_path = resolve_db_path(cfg)
	out_csv = resolve_output_csv(cfg)
	out_alley = resolve_output_csv_alley(cfg)

	conn = dbmod.connect(db_path)
	dbmod.init_schema(conn)

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
	if rpk is None:
		print(
			"[outdoorworld] Внимание: курс KZT→RUB из Bitrix недоступен (запустите из окружения с PHP+Bitrix "
			"или задайте fallback_rub_per_kzt в config.yaml). Колонка «Цена» в stock_and_price и eternal_price "
			"будет пустой там, где нет пересчёта; в eternal для таких строк останется цена со списка (price_raw).\n",
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
		out_lines += f"\nкурс rub_per_kzt (из Bitrix/fallback): {rpk}"
	print(out_lines, flush=True)
	conn.close()
	return 0


if __name__ == "__main__":
	raise SystemExit(main())
