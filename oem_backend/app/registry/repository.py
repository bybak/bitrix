from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from app.db import get_registry_conn


@dataclass(frozen=True)
class CatalogDatabase:
    code: str
    name: str
    connection_dsn: str
    parser_type: str


@dataclass(frozen=True)
class Brand:
    code: str
    name: str
    catalog_db_code: str
    sort_order: int


@dataclass(frozen=True)
class BrandRoot:
    brand_code: str
    root_arib: str
    name: str
    sort_order: int
    catalog_db_code: str


def load_routing_snapshot() -> dict[str, Any]:
    with get_registry_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT code, name, connection_dsn, parser_type
                FROM oem_catalog_databases
                WHERE is_active = TRUE
                ORDER BY code
                """
            )
            databases = [dict(row) for row in cur.fetchall()]

            cur.execute(
                """
                SELECT code, name, catalog_db_code, sort_order
                FROM oem_brands
                WHERE is_active = TRUE
                ORDER BY sort_order, name
                """
            )
            brands = [dict(row) for row in cur.fetchall()]

            cur.execute(
                """
                SELECT
                  br.brand_code,
                  br.root_arib,
                  br.name,
                  br.sort_order,
                  br.is_active,
                  b.catalog_db_code
                FROM oem_brand_roots br
                JOIN oem_brands b ON b.code = br.brand_code
                WHERE b.is_active = TRUE
                ORDER BY b.sort_order, br.sort_order, br.name
                """
            )
            all_roots = [dict(row) for row in cur.fetchall()]
            # UI / brand browse: only active roots.
            roots = [row for row in all_roots if row.get("is_active")]

    db_by_code = {row["code"]: CatalogDatabase(**row) for row in databases}
    # Routing includes inactive roots so imports (e.g. ARC_CDN) still resolve to a DB.
    root_to_db: dict[str, str] = {
        str(row["root_arib"]).upper(): row["catalog_db_code"] for row in all_roots
    }
    brand_to_db: dict[str, str] = {row["code"]: row["catalog_db_code"] for row in brands}

    return {
        "databases": db_by_code,
        "brands": brands,
        "roots": roots,
        "root_to_db": root_to_db,
        "brand_to_db": brand_to_db,
    }


def list_brands() -> list[dict[str, Any]]:
    with get_registry_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  b.code,
                  b.name,
                  b.catalog_db_code,
                  b.sort_order,
                  d.name AS catalog_db_name,
                  d.parser_type,
                  COUNT(br.root_arib) AS roots_count
                FROM oem_brands b
                JOIN oem_catalog_databases d ON d.code = b.catalog_db_code
                LEFT JOIN oem_brand_roots br
                  ON br.brand_code = b.code AND br.is_active = TRUE
                WHERE b.is_active = TRUE AND d.is_active = TRUE
                GROUP BY b.code, b.name, b.catalog_db_code, b.sort_order, d.name, d.parser_type
                ORDER BY b.sort_order, b.name
                """
            )
            return [dict(row) for row in cur.fetchall()]


def list_brand_roots(*, brand_code: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = []
    where = ["br.is_active = TRUE", "b.is_active = TRUE"]
    if brand_code:
        where.append("b.code = %s")
        params.append(brand_code.lower())
    with get_registry_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                SELECT
                  b.code AS brand_code,
                  b.name AS brand_name,
                  br.root_arib,
                  br.name,
                  br.sort_order,
                  b.catalog_db_code,
                  d.connection_dsn,
                  d.parser_type
                FROM oem_brand_roots br
                JOIN oem_brands b ON b.code = br.brand_code
                JOIN oem_catalog_databases d ON d.code = b.catalog_db_code
                WHERE {" AND ".join(where)}
                ORDER BY b.sort_order, br.sort_order, br.name
                """,
                tuple(params),
            )
            return [dict(row) for row in cur.fetchall()]
