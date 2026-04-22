from __future__ import annotations

import csv
import json
import re
import sqlite3
import subprocess
from pathlib import Path
from typing import Any


def _site_currency(cfg: dict[str, Any] | None) -> str:
	if not cfg:
		return "KZT"
	return str(cfg.get("site_currency", "KZT")).upper()


def _resolve_kzt_php_script(cfg: dict[str, Any]) -> Path | None:
	custom = cfg.get("bitrix_kzt_script")
	if custom:
		p = Path(str(custom)).expanduser()
		return p if p.is_file() else None
	ws = Path(__file__).resolve().parent.parent.parent.parent
	cli = ws / "www" / "bitrix" / "php_interface" / "mf_kzt_to_rub_cli.php"
	if cli.is_file():
		return cli
	legacy = Path(__file__).resolve().parent.parent / "mf_kzt_to_rub.php"
	if legacy.is_file():
		return legacy
	return None


def _rub_per_kzt_from_json_output(out: str) -> float | None:
	data = None
	for line in reversed(out.strip().splitlines()):
		line = line.strip()
		if line.startswith("{"):
			try:
				data = json.loads(line)
			except json.JSONDecodeError:
				continue
			break
	if not isinstance(data, dict) or data.get("error"):
		return None
	rpk = data.get("rub_per_kzt")
	if isinstance(rpk, (int, float)) and float(rpk) > 0:
		return float(rpk)
	rub = data.get("rub")
	kzt = data.get("kzt")
	if isinstance(rub, (int, float)) and isinstance(kzt, (int, float)) and float(kzt) > 0:
		return float(rub) / float(kzt)
	return None


def _rub_per_kzt_from_php(cfg: dict[str, Any]) -> float | None:
	kzt_probe = float(cfg.get("kzt_rate_probe_amount", 1_000_000.0))

	if cfg.get("bitrix_kzt_use_docker"):
		compose_dir = cfg.get("bitrix_docker_compose_dir")
		if compose_dir:
			cwd = Path(str(compose_dir)).expanduser()
		else:
			cwd = Path(__file__).resolve().parent.parent.parent.parent
		service = str(cfg.get("bitrix_docker_service", "php"))
		script_in_container = str(
			cfg.get("bitrix_kzt_script_container", "/var/www/html/bitrix/php_interface/mf_kzt_to_rub_cli.php")
		)
		cmd = ["docker", "compose", "exec", "-T", service, "php", script_in_container, str(kzt_probe)]
		try:
			r = subprocess.run(
				cmd,
				cwd=str(cwd),
				capture_output=True,
				text=True,
				timeout=90,
				check=False,
			)
		except (OSError, subprocess.TimeoutExpired):
			return None
		out = (r.stdout or "") + "\n" + (r.stderr or "")
		return _rub_per_kzt_from_json_output(out)

	php_bin = str(cfg.get("bitrix_php_binary", "php"))
	script = _resolve_kzt_php_script(cfg)
	if script is None:
		return None
	try:
		r = subprocess.run(
			[php_bin, str(script), str(kzt_probe)],
			capture_output=True,
			text=True,
			timeout=60,
			check=False,
		)
	except (OSError, subprocess.TimeoutExpired):
		return None
	out = (r.stdout or "") + "\n" + (r.stderr or "")
	return _rub_per_kzt_from_json_output(out)


def resolve_rub_per_kzt(cfg: dict[str, Any]) -> float | None:
	"""Для site_currency=RUB: 1 ₽ за 1 единицу в колонке price_kzt (там хранится цена в ₽). Иначе — курс KZT→RUB из Bitrix."""
	if _site_currency(cfg) == "RUB":
		return 1.0
	r = _rub_per_kzt_from_php(cfg)
	if r is not None:
		return r
	fb = cfg.get("fallback_rub_per_kzt")
	if fb is not None:
		try:
			x = float(fb)
			if x > 0:
				return x
		except (TypeError, ValueError):
			pass
	return None


def parse_kzt_from_price_raw(raw: str) -> float | None:
	"""Число из строки витрины (тенге, рубли — только цифры). Если не угадали — None."""
	if not raw or len(raw) > 800:
		return None
	s = raw.replace("\xa0", " ")
	if re.search(r"нет\s*цены", s, re.I):
		return None
	cands = re.findall(r"\d[\d\s]{0,14}\d|\d{2,15}", s)
	best: int | None = None
	for c in cands:
		try:
			n = int(re.sub(r"\D", "", c))
		except ValueError:
			continue
		if n <= 0 or n >= 10**12:
			continue
		if best is None or n > best:
			best = n
	return float(best) if best is not None else None


def effective_kzt_amount(pkzt: float | None, price_raw: str) -> float | None:
	"""Сумма в валюте сайта: с карточки (price_kzt) или из строки списка."""
	if pkzt is not None:
		try:
			v = float(pkzt)
			if v > 0:
				return v
		except (TypeError, ValueError):
			pass
	return parse_kzt_from_price_raw(price_raw or "")


def _format_price_rub_cell(pkzt: float, rub_per_kzt: float) -> str:
	rub = float(pkzt) * rub_per_kzt
	if rub <= 0:
		return ""
	if rub == int(rub):
		return str(int(rub))
	return f"{rub:.2f}".rstrip("0").rstrip(".")


def export_products_csv_legacy(
	conn: sqlite3.Connection,
	out_path: Path,
	cfg: dict[str, Any] | None = None,
	encoding: str = "utf-8-sig",
) -> int:
	"""Формат mf_external_price_upload: Производитель;Артикул;Наименование;Цена."""
	out_path.parent.mkdir(parents=True, exist_ok=True)
	rub_per_kzt = resolve_rub_per_kzt(cfg) if cfg else None
	rows = conn.execute(
		"SELECT manufacturer, article, name, price_raw, price_kzt FROM products ORDER BY product_url"
	).fetchall()
	with out_path.open("w", encoding=encoding, newline="") as f:
		w = csv.writer(f, delimiter=";", lineterminator="\n")
		w.writerow(["Производитель", "Артикул", "Наименование", "Цена"])
		for m, a, n, praw, pkzt in rows:
			praw = praw or ""
			kzt_amt = effective_kzt_amount(pkzt, praw)
			if kzt_amt is not None and rub_per_kzt is not None:
				cell = _format_price_rub_cell(kzt_amt, rub_per_kzt)
				price_cell = cell if cell else praw.strip()
			else:
				price_cell = praw.strip()
			w.writerow(
				[
					(m or "").strip(),
					(a or "").strip(),
					(n or "").strip(),
					price_cell,
				]
			)
	return len(rows)


def export_products_csv(
	conn: sqlite3.Connection,
	out_path: Path,
	cfg: dict[str, Any] | None = None,
	encoding: str = "utf-8-sig",
) -> int:
	return export_products_csv_legacy(conn, out_path, cfg=cfg, encoding=encoding)


def export_products_csv_alley(
	conn: sqlite3.Connection,
	out_path: Path,
	cfg: dict[str, Any],
	encoding: str = "utf-8-sig",
) -> int:
	"""Бренд;Артикул;Остаток;Цена → stock_and_price."""
	out_path.parent.mkdir(parents=True, exist_ok=True)
	rub_per_kzt = resolve_rub_per_kzt(cfg)
	rows = conn.execute(
		"SELECT manufacturer, article, stock_qty, price_kzt, price_raw FROM products ORDER BY product_url"
	).fetchall()
	n = 0
	with out_path.open("w", encoding=encoding, newline="") as f:
		w = csv.writer(f, delimiter=";", lineterminator="\n")
		w.writerow(["Бренд", "Артикул", "Остаток", "Цена"])
		for brand, art, stq, pkzt, praw in rows:
			ost: str
			if stq is None:
				ost = ""
			else:
				ost = str(int(stq))
			price_cell = ""
			kzt_amt = effective_kzt_amount(pkzt, praw or "")
			if kzt_amt is not None and rub_per_kzt is not None:
				price_cell = _format_price_rub_cell(kzt_amt, rub_per_kzt)
			w.writerow(
				[
					(brand or "").strip(),
					(art or "").strip(),
					ost,
					price_cell,
				]
			)
			n += 1
	return n
