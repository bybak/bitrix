from collections.abc import Iterator
from contextlib import contextmanager
from typing import Literal

from psycopg.rows import dict_row
from psycopg_pool import ConnectionPool

from app.config import get_settings

_pool: ConnectionPool | None = None
_yamaha_pool: ConnectionPool | None = None
_registry_pool: ConnectionPool | None = None

DbTarget = Literal["remotors", "yamaha"]


def open_pool(*, min_size: int = 2, max_size: int = 20) -> None:
    global _pool
    if _pool is not None and not _pool.closed:
        _pool.close()
    _pool = ConnectionPool(
        conninfo=get_settings().database_dsn,
        kwargs={"row_factory": dict_row},
        min_size=min_size,
        max_size=max_size,
        open=False,
    )
    _pool.open(wait=True)


def open_yamaha_pool(*, min_size: int = 2, max_size: int = 20) -> None:
    global _yamaha_pool
    if _yamaha_pool is not None and not _yamaha_pool.closed:
        _yamaha_pool.close()
    _yamaha_pool = ConnectionPool(
        conninfo=get_settings().yamaha_database_dsn,
        kwargs={"row_factory": dict_row},
        min_size=min_size,
        max_size=max_size,
        open=False,
    )
    _yamaha_pool.open(wait=True)


def open_registry_pool(*, min_size: int = 1, max_size: int = 5) -> None:
    global _registry_pool
    if _registry_pool is not None and not _registry_pool.closed:
        _registry_pool.close()
    _registry_pool = ConnectionPool(
        conninfo=get_settings().registry_database_dsn,
        kwargs={"row_factory": dict_row},
        min_size=min_size,
        max_size=max_size,
        open=False,
    )
    _registry_pool.open(wait=True)


def close_pool() -> None:
    global _pool, _yamaha_pool, _registry_pool
    if _pool is not None:
        _pool.close()
        _pool = None
    if _yamaha_pool is not None:
        _yamaha_pool.close()
        _yamaha_pool = None
    if _registry_pool is not None:
        _registry_pool.close()
        _registry_pool = None


@contextmanager
def get_conn() -> Iterator:
    if _pool is None:
        raise RuntimeError("database pool is not open — call open_pool() first")
    with _pool.connection() as conn:
        yield conn


@contextmanager
def get_yamaha_conn() -> Iterator:
    if _yamaha_pool is None:
        raise RuntimeError("yamaha database pool is not open — call open_yamaha_pool() first")
    with _yamaha_pool.connection() as conn:
        yield conn


@contextmanager
def get_registry_conn() -> Iterator:
    if _registry_pool is None:
        raise RuntimeError("registry pool is not open — call open_registry_pool() first")
    with _registry_pool.connection() as conn:
        yield conn


@contextmanager
def get_conn_for(target: DbTarget) -> Iterator:
    if target == "yamaha":
        with get_yamaha_conn() as conn:
            yield conn
        return
    with get_conn() as conn:
        yield conn
