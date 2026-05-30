from __future__ import annotations

import sqlite3
import time
from pathlib import Path
from typing import Any


def connect(db_path: Path) -> sqlite3.Connection:
	"""Подключение к SQLite; при сбое WAL (iCloud/сеть/том) — режим DELETE."""
	db_path = Path(db_path).expanduser()
	if db_path.exists() and db_path.is_dir():
		raise RuntimeError(
			f"Путь БД {db_path} указывает на каталог. Удалите папку или задайте другой state_db в config.yaml."
		)
	db_path.parent.mkdir(parents=True, exist_ok=True)
	probe = db_path.parent / ".pilotmoto_db_write_probe"
	try:
		probe.write_bytes(b"ok")
		probe.unlink()
	except OSError as e:
		raise RuntimeError(
			f"Нет записи в каталог {db_path.parent}: {e}. "
			"Проверьте права, свободное место, не синхронизируется ли папка через iCloud/OneDrive. "
			"Задайте в config.yaml state_db на локальный путь, например: "
			"/tmp/pilotmoto_state.sqlite3"
		) from e

	try:
		conn = sqlite3.connect(str(db_path), timeout=120.0)
	except sqlite3.OperationalError as e:
		raise RuntimeError(
			f"Не удалось открыть SQLite {db_path}: {e}. "
			"Удалите повреждённый файл и *.sqlite3-wal / *.sqlite3-shm или смените state_db в config.yaml."
		) from e
	conn.row_factory = sqlite3.Row
	try:
		conn.execute("PRAGMA journal_mode=WAL")
	except sqlite3.OperationalError:
		conn.execute("PRAGMA journal_mode=DELETE")
	try:
		conn.execute("PRAGMA synchronous=NORMAL")
	except sqlite3.OperationalError:
		conn.execute("PRAGMA synchronous=FULL")
	return conn


def init_schema(conn: sqlite3.Connection) -> None:
	try:
		conn.executescript(
		"""
		CREATE TABLE IF NOT EXISTS meta (
			k TEXT PRIMARY KEY,
			v TEXT
		);

		CREATE TABLE IF NOT EXISTS category_meta (
			category_url TEXT PRIMARY KEY,
			pagen_param INTEGER,
			max_page INTEGER NOT NULL DEFAULT 1,
			updated_at REAL NOT NULL
		);

		CREATE TABLE IF NOT EXISTS category_pages (
			category_url TEXT NOT NULL,
			page_num INTEGER NOT NULL,
			product_count INTEGER NOT NULL DEFAULT 0,
			fetched_at REAL NOT NULL,
			PRIMARY KEY (category_url, page_num)
		);

		CREATE TABLE IF NOT EXISTS products (
			product_url TEXT PRIMARY KEY,
			bx_id INTEGER,
			name TEXT,
			manufacturer TEXT,
			article TEXT,
			price_raw TEXT,
			price_kzt REAL,
			stock_qty INTEGER,
			stock_label TEXT,
			stock_stack TEXT,
			category_url TEXT,
			updated_at REAL NOT NULL,
			detail_done INTEGER NOT NULL DEFAULT 0
		);

		CREATE INDEX IF NOT EXISTS idx_products_bx ON products(bx_id);
		CREATE INDEX IF NOT EXISTS idx_products_detail ON products(detail_done);
		"""
		)
		conn.commit()
		_migrate_schema(conn)
	except sqlite3.OperationalError as e:
		raise RuntimeError(
			f"SQLite не смог создать схему (disk I/O): {e}. "
			"Удалите файлы data/state.sqlite3, data/state.sqlite3-wal, data/state.sqlite3-shm "
			"или укажите в config.yaml state_db, например: /tmp/pilotmoto_state.sqlite3"
		) from e


def _migrate_schema(conn: sqlite3.Connection) -> None:
	"""Добавить колонки в старые БД."""
	cols = {r[1] for r in conn.execute("PRAGMA table_info(products)").fetchall()}
	if "detail_done" not in cols:
		conn.execute("ALTER TABLE products ADD COLUMN detail_done INTEGER NOT NULL DEFAULT 0")
	if "price_kzt" not in cols:
		conn.execute("ALTER TABLE products ADD COLUMN price_kzt REAL")
	if "stock_qty" not in cols:
		conn.execute("ALTER TABLE products ADD COLUMN stock_qty INTEGER")
	if "stock_label" not in cols:
		conn.execute("ALTER TABLE products ADD COLUMN stock_label TEXT")
	if "stock_stack" not in cols:
		conn.execute("ALTER TABLE products ADD COLUMN stock_stack TEXT")
	conn.commit()


def meta_get(conn: sqlite3.Connection, k: str, default: str | None = None) -> str | None:
	r = conn.execute("SELECT v FROM meta WHERE k=?", (k,)).fetchone()
	return (r[0] if r else default)


def meta_set(conn: sqlite3.Connection, k: str, v: str) -> None:
	conn.execute(
		"INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",
		(k, v),
	)
	conn.commit()


def is_page_done(conn: sqlite3.Connection, category_url: str, page_num: int) -> bool:
	"""Страница считается обработанной только если на ней нашли хотя бы один товар."""
	r = conn.execute(
		"SELECT product_count FROM category_pages WHERE category_url=? AND page_num=? LIMIT 1",
		(category_url, page_num),
	).fetchone()
	return r is not None and int(r[0]) > 0


def mark_page_done(
	conn: sqlite3.Connection, category_url: str, page_num: int, product_count: int
) -> None:
	now = time.time()
	conn.execute(
		"""
		INSERT INTO category_pages(category_url, page_num, product_count, fetched_at)
		VALUES(?,?,?,?)
		ON CONFLICT(category_url, page_num) DO UPDATE SET
			product_count=excluded.product_count,
			fetched_at=excluded.fetched_at
		""",
		(category_url, page_num, product_count, now),
	)
	conn.commit()


def upsert_product(
	conn: sqlite3.Connection,
	*,
	product_url: str,
	bx_id: int | None,
	name: str,
	manufacturer: str,
	article: str,
	price_raw: str,
	category_url: str,
	price_kzt: float | None = None,
	stock_qty: int | None = None,
	stock_label: str = "",
	detail_done: int = 1,
) -> None:
	"""Листинг: detail_done=0, если дальше планируется обход карточек для остатков."""
	now = time.time()
	conn.execute(
		"""
		INSERT INTO products(
			product_url, bx_id, name, manufacturer, article, price_raw, price_kzt,
			stock_qty, stock_label, stock_stack, category_url, updated_at, detail_done
		)
		VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
		ON CONFLICT(product_url) DO UPDATE SET
			bx_id=excluded.bx_id,
			name=excluded.name,
			manufacturer=excluded.manufacturer,
			article=excluded.article,
			price_raw=excluded.price_raw,
			price_kzt=excluded.price_kzt,
			stock_qty=COALESCE(excluded.stock_qty, products.stock_qty),
			stock_label=CASE
				WHEN NULLIF(excluded.stock_label, '') IS NOT NULL THEN excluded.stock_label
				ELSE products.stock_label
			END,
			category_url=excluded.category_url,
			updated_at=excluded.updated_at,
			detail_done=excluded.detail_done
		""",
		(
			product_url,
			bx_id,
			name,
			manufacturer,
			article,
			price_raw,
			price_kzt,
			stock_qty,
			stock_label,
			"",
			category_url,
			now,
			detail_done,
		),
	)


def product_urls_pending_detail(conn: sqlite3.Connection) -> list[str]:
	r = conn.execute(
		"SELECT product_url FROM products WHERE detail_done=0 ORDER BY product_url"
	).fetchall()
	return [row[0] for row in r]


def apply_product_detail(
	conn: sqlite3.Connection,
	*,
	product_url: str,
	manufacturer: str,
	article: str,
	price_kzt: float | None,
	stock_qty: int | None,
	stock_label: str,
	stock_stack: str,
) -> None:
	now = time.time()
	conn.execute(
		"""
		UPDATE products SET
			manufacturer=?,
			article=COALESCE(NULLIF(?, ''), article),
			price_kzt=?,
			stock_qty=?,
			stock_label=?,
			stock_stack=?,
			detail_done=1,
			updated_at=?
		WHERE product_url=?
		""",
		(manufacturer, article, price_kzt, stock_qty, stock_label, stock_stack, now, product_url),
	)


def commit_products(conn: sqlite3.Connection) -> None:
	conn.commit()


def get_category_meta(conn: sqlite3.Connection, category_url: str) -> tuple[int | None, int] | None:
	r = conn.execute(
		"SELECT pagen_param, max_page FROM category_meta WHERE category_url=?",
		(category_url,),
	).fetchone()
	if not r:
		return None
	pp, mp = r[0], int(r[1])
	return (int(pp) if pp is not None else None, mp)


def set_category_meta(
	conn: sqlite3.Connection, category_url: str, pagen_param: int | None, max_page: int
) -> None:
	now = time.time()
	conn.execute(
		"""
		INSERT INTO category_meta(category_url, pagen_param, max_page, updated_at)
		VALUES(?,?,?,?)
		ON CONFLICT(category_url) DO UPDATE SET
			pagen_param=excluded.pagen_param,
			max_page=excluded.max_page,
			updated_at=excluded.updated_at
		""",
		(category_url, pagen_param, max_page, now),
	)
	conn.commit()


def stats(conn: sqlite3.Connection) -> dict[str, Any]:
	out: dict[str, Any] = {}
	out["products"] = conn.execute("SELECT COUNT(*) FROM products").fetchone()[0]
	out["products_detail_done"] = conn.execute(
		"SELECT COUNT(*) FROM products WHERE detail_done=1"
	).fetchone()[0]
	out["category_pages"] = conn.execute("SELECT COUNT(*) FROM category_pages").fetchone()[0]
	out["categories_touched"] = conn.execute(
		"SELECT COUNT(DISTINCT category_url) FROM category_pages"
	).fetchone()[0]
	return out
