from __future__ import annotations

import threading
from collections.abc import Iterator
from contextlib import contextmanager
from typing import Any

from psycopg.rows import dict_row
from psycopg_pool import ConnectionPool

from app.config import get_settings
from app.registry.repository import load_routing_snapshot

_catalog_pools: dict[str, ConnectionPool] = {}
_routing_lock = threading.Lock()
_routing_cache: dict[str, Any] | None = None


def refresh_routing_cache() -> dict[str, Any]:
    global _routing_cache
    snapshot = load_routing_snapshot()
    with _routing_lock:
        _routing_cache = snapshot
    return snapshot


def get_routing_cache() -> dict[str, Any]:
    global _routing_cache
    if _routing_cache is None:
        return refresh_routing_cache()
    return _routing_cache


def catalog_db_code_for_root(root_arib: str) -> str:
    cache = get_routing_cache()
    code = cache["root_to_db"].get(root_arib.upper())
    if code:
        return code
    # Registry may change while the API process is running (migrate-registry, is_active).
    cache = refresh_routing_cache()
    code = cache["root_to_db"].get(root_arib.upper())
    if not code:
        raise KeyError(f"unknown catalog root: {root_arib}")
    return code


def catalog_db_code_for_brand(brand_code: str) -> str:
    cache = get_routing_cache()
    code = cache["brand_to_db"].get(brand_code.lower())
    if not code:
        raise KeyError(f"unknown brand: {brand_code}")
    return code


def _default_dsn_for_code(db_code: str) -> str:
    settings = get_settings()
    defaults = {
        "remotors": settings.database_dsn,
        "yamaha": settings.yamaha_database_dsn,
        "arctic": settings.arctic_database_dsn,
        "polaris": settings.polaris_database_dsn,
    }
    if db_code not in defaults:
        raise KeyError(f"no default DSN for catalog database: {db_code}")
    return defaults[db_code]


def connection_dsn_for_db_code(db_code: str) -> str:
    cache = get_routing_cache()
    db = cache["databases"].get(db_code)
    if db is not None and db.connection_dsn:
        return db.connection_dsn
    return _default_dsn_for_code(db_code)


def open_catalog_pools(*, min_size: int = 2, max_size: int = 20) -> list[str]:
    """Open connection pools for every active catalog DB from registry."""
    global _catalog_pools
    snapshot = refresh_routing_cache()
    opened: list[str] = []
    for code in snapshot["databases"]:
        dsn = connection_dsn_for_db_code(code)
        existing = _catalog_pools.get(code)
        if existing is not None and not existing.closed:
            existing.close()
        pool = ConnectionPool(
            conninfo=dsn,
            kwargs={"row_factory": dict_row},
            min_size=min_size,
            max_size=max_size,
            open=False,
        )
        pool.open(wait=True)
        _catalog_pools[code] = pool
        opened.append(code)
    return opened


def close_catalog_pools() -> None:
    global _catalog_pools
    for pool in _catalog_pools.values():
        pool.close()
    _catalog_pools = {}


def list_catalog_db_codes() -> list[str]:
    return list(get_routing_cache()["databases"].keys())


def _resolve_root_arib_from_dbs(*, table: str, entity_id: int) -> str | None:
    found: set[str] = set()
    for db_code in list_catalog_db_codes():
        with get_catalog_conn(db_code=db_code) as conn:
            with conn.cursor() as cur:
                cur.execute(f"SELECT root_arib FROM {table} WHERE id = %s", (entity_id,))
                row = cur.fetchone()
                if row:
                    found.add(str(row["root_arib"]))
    if len(found) == 1:
        return found.pop()
    return None


def resolve_root_arib_for_variant(variant_id: int) -> str | None:
    return _resolve_root_arib_from_dbs(table="oem_variants", entity_id=variant_id)


def resolve_root_arib_for_assembly(assembly_id: int) -> str | None:
    return _resolve_root_arib_from_dbs(table="oem_assemblies", entity_id=assembly_id)


def resolve_root_arib_for_nav_node(nav_node_id: int) -> str | None:
    return _resolve_root_arib_from_dbs(table="oem_nav_nodes", entity_id=nav_node_id)


@contextmanager
def get_catalog_conn(*, db_code: str) -> Iterator:
    pool = _catalog_pools.get(db_code)
    if pool is None or pool.closed:
        dsn = connection_dsn_for_db_code(db_code)
        pool = ConnectionPool(
            conninfo=dsn,
            kwargs={"row_factory": dict_row},
            min_size=1,
            max_size=4,
            open=False,
        )
        pool.open(wait=True)
        _catalog_pools[db_code] = pool
    with pool.connection() as conn:
        yield conn


@contextmanager
def get_catalog_conn_for_root(*, root_arib: str) -> Iterator:
    with get_catalog_conn(db_code=catalog_db_code_for_root(root_arib)) as conn:
        yield conn


@contextmanager
def get_catalog_conn_for_brand(*, brand_code: str) -> Iterator:
    with get_catalog_conn(db_code=catalog_db_code_for_brand(brand_code)) as conn:
        yield conn
