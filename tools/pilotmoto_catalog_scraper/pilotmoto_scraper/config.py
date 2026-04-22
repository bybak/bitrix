from __future__ import annotations

import os
from pathlib import Path
from typing import Any

import yaml


def load_config(path: str | Path | None = None) -> dict[str, Any]:
	default_cfg = Path(__file__).resolve().parent.parent / "config.yaml"
	if path:
		p = Path(path).expanduser()
	else:
		env = (os.environ.get("PILOTMOTO_CONFIG") or "").strip()
		p = Path(env).expanduser() if env else default_cfg
	raw = yaml.safe_load(p.read_text(encoding="utf-8"))
	if not isinstance(raw, dict):
		raise ValueError("config.yaml must be a mapping")
	return raw


def data_dir(cfg: dict[str, Any]) -> Path:
	root = Path(__file__).resolve().parent.parent
	d = root / "data"
	d.mkdir(parents=True, exist_ok=True)
	return d


def resolve_db_path(cfg: dict[str, Any]) -> Path:
	p = Path(cfg.get("state_db", "data/state.sqlite3"))
	if not p.is_absolute():
		p = Path(__file__).resolve().parent.parent / p
	p.parent.mkdir(parents=True, exist_ok=True)
	return p


def resolve_output_csv(cfg: dict[str, Any]) -> Path:
	p = Path(cfg.get("output_csv", "data/eternal_price.csv"))
	if not p.is_absolute():
		p = Path(__file__).resolve().parent.parent / p
	p.parent.mkdir(parents=True, exist_ok=True)
	return p


def resolve_output_csv_alley(cfg: dict[str, Any]) -> Path:
	p = Path(cfg.get("output_csv_alley", "data/stock_and_price.csv"))
	if not p.is_absolute():
		p = Path(__file__).resolve().parent.parent / p
	p.parent.mkdir(parents=True, exist_ok=True)
	return p
