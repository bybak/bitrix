"""Which catalog Postgres + storage tree remotors_v3 crawl/parse should use.

Uses a process-global (not ContextVar): ThreadPoolExecutor workers must see the
same --db-code as the main thread. ContextVar defaults back to \"remotors\" in
workers and silently wrote html_ok into oem_db while claiming rows from arctic_db.
"""

from __future__ import annotations

import threading

from app.config import get_settings

_LOCK = threading.Lock()
_CATALOG_DB = "remotors"

SUPPORTED = ("remotors", "arctic")


def set_catalog_db(db_code: str) -> None:
    global _CATALOG_DB
    code = (db_code or "remotors").strip().lower()
    if code not in SUPPORTED:
        raise ValueError(f"unsupported catalog db {db_code!r}; expected one of {SUPPORTED}")
    with _LOCK:
        _CATALOG_DB = code


def get_catalog_db() -> str:
    with _LOCK:
        return _CATALOG_DB


def catalog_dsn() -> str:
    settings = get_settings()
    code = get_catalog_db()
    if code == "arctic":
        return settings.arctic_database_dsn
    return settings.database_dsn


def html_storage_root_name() -> str:
    return "arctic-html" if get_catalog_db() == "arctic" else "remotors-html"


def diagram_storage_root_name() -> str:
    return "arctic" if get_catalog_db() == "arctic" else "remotors"


def default_sidecar_path() -> str:
    return (
        "storage/arctic-details-crawl.db"
        if get_catalog_db() == "arctic"
        else "storage/remotors-details-crawl.db"
    )
