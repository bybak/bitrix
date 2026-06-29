from collections.abc import Iterator
from contextlib import contextmanager

from psycopg.rows import dict_row
from psycopg_pool import ConnectionPool

from app.config import get_settings

_pool: ConnectionPool | None = None


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


def close_pool() -> None:
    global _pool
    if _pool is not None:
        _pool.close()
        _pool = None


@contextmanager
def get_conn() -> Iterator:
    if _pool is None:
        raise RuntimeError("database pool is not open — call open_pool() first")
    with _pool.connection() as conn:
        yield conn
