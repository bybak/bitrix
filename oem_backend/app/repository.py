from typing import Any

from app.db import get_conn
from app.importers.remotors_catalog import HIDDEN_CANONICAL_BRANDS

_HIDDEN_BRANDS_SQL = tuple(sorted(HIDDEN_CANONICAL_BRANDS))


def fetch_all(query: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())


def fetch_one(query: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()


def list_vehicle_types() -> list[dict[str, Any]]:
    return fetch_all(
        """
        SELECT id, code, name, sort_order
        FROM oem_vehicle_types
        ORDER BY sort_order, name
        """
    )


def list_brands(vehicle_type: str | None = None) -> list[dict[str, Any]]:
    hidden = list(_HIDDEN_BRANDS_SQL)
    if vehicle_type:
        return fetch_all(
            """
            SELECT b.id, b.name, COUNT(DISTINCT mf.id) AS model_count
            FROM oem_brands b
            JOIN oem_model_families mf ON mf.brand_id = b.id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            WHERE vt.code = %s
              AND b.normalized_name <> ALL(%s)
            GROUP BY b.id, b.name
            ORDER BY b.name
            """,
            (vehicle_type, hidden),
        )
    return fetch_all(
        """
        SELECT b.id, b.name, COUNT(DISTINCT mf.id) AS model_count
        FROM oem_brands b
        LEFT JOIN oem_model_families mf ON mf.brand_id = b.id
        WHERE b.normalized_name <> ALL(%s)
        GROUP BY b.id, b.name
        ORDER BY b.name
        """,
        (hidden,),
    )


def list_years(vehicle_type: str, brand_id: int) -> list[dict[str, Any]]:
    return fetch_all(
        """
        SELECT
          vv.year_from AS year,
          COUNT(DISTINCT mf.id) AS model_count,
          COUNT(DISTINCT vv.id) AS variant_count
        FROM oem_vehicle_variants vv
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE vt.code = %s
          AND mf.brand_id = %s
          AND vv.year_from IS NOT NULL
        GROUP BY vv.year_from
        ORDER BY vv.year_from DESC
        """,
        (vehicle_type, brand_id),
    )


def list_models(vehicle_type: str, brand_id: int, year: int | None = None, q: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [vehicle_type, brand_id]
    where = ["vt.code = %s", "mf.brand_id = %s"]
    if year:
        where.append("EXISTS (SELECT 1 FROM oem_vehicle_variants vv_year WHERE vv_year.model_family_id = mf.id AND vv_year.year_from <= %s AND (vv_year.year_to IS NULL OR vv_year.year_to >= %s))")
        params.extend([year, year])
    if q:
        where.append("(mf.name ILIKE %s OR EXISTS (SELECT 1 FROM oem_model_aliases ma WHERE ma.model_family_id = mf.id AND ma.alias ILIKE %s))")
        params.extend([f"%{q}%", f"%{q}%"])
    return fetch_all(
        f"""
        SELECT
          mf.id,
          mf.brand_id,
          vt.code AS vehicle_type,
          mf.name,
          COALESCE(array_agg(DISTINCT ma.alias) FILTER (WHERE ma.alias IS NOT NULL), '{{}}') AS aliases,
          COALESCE(array_agg(DISTINCT vv.year_from) FILTER (WHERE vv.year_from IS NOT NULL), '{{}}') AS years,
          COUNT(DISTINCT vv.id) AS variant_count
        FROM oem_model_families mf
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        LEFT JOIN oem_model_aliases ma ON ma.model_family_id = mf.id
        LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
          AND (%s::int IS NULL OR (vv.year_from <= %s AND (vv.year_to IS NULL OR vv.year_to >= %s)))
        WHERE {" AND ".join(where)}
        GROUP BY mf.id, mf.brand_id, vt.code, mf.name
        ORDER BY mf.name
        """,
        (year, year, year, *params),
    )


def list_variants(model_id: int, year: int | None = None, region: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [model_id]
    where = ["vv.model_family_id = %s"]
    if year:
        where.append("(vv.year_from IS NULL OR vv.year_from <= %s) AND (vv.year_to IS NULL OR vv.year_to >= %s)")
        params.extend([year, year])
    if region:
        where.append("vv.region ILIKE %s")
        params.append(f"%{region}%")
    return fetch_all(
        f"""
        SELECT
          vv.*,
          COALESCE(
            json_agg(
              DISTINCT jsonb_build_object(
                'source', s.code,
                'source_node_id', sn.id,
                'url', sn.source_url
              )
            ) FILTER (WHERE sn.id IS NOT NULL),
            '[]'
          ) AS sources
        FROM oem_vehicle_variants vv
        LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
        LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
        LEFT JOIN oem_sources s ON s.id = sn.source_id
        WHERE {" AND ".join(where)}
        GROUP BY vv.id
        ORDER BY vv.year_from DESC NULLS LAST, vv.market_name, vv.source_designation
        """,
        tuple(params),
    )


def list_assemblies(variant_id: int, q: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [variant_id]
    where = ["a.vehicle_variant_id = %s"]
    if q:
        where.append("a.title ILIKE %s")
        params.append(f"%{q}%")
    return fetch_all(
        f"""
        SELECT
          a.id,
          a.vehicle_variant_id,
          a.title,
          a.normalized_title,
          COUNT(DISTINCT d.id) AS diagram_count,
          COUNT(DISTINCT ap.id) AS part_count,
          MIN(d.public_url) AS public_url,
          MIN(d.local_path) AS local_path,
          MIN(d.original_url) AS original_url,
          MIN(d.width) AS image_width,
          MIN(d.height) AS image_height,
          s.code AS source_code,
          sn.id AS source_node_id,
          sn.source_url
        FROM oem_assemblies a
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        LEFT JOIN oem_sources s ON s.id = sn.source_id
        WHERE {" AND ".join(where)}
        GROUP BY a.id, s.code, sn.id, sn.source_url
        ORDER BY a.sort_order, a.title
        """,
        tuple(params),
    )


def get_diagram_payload(assembly_id: int) -> dict[str, Any] | None:
    assembly = fetch_one(
        """
        SELECT a.id, a.title, a.vehicle_variant_id, sn.source_url, s.code AS source_code, sn.id AS source_node_id
        FROM oem_assemblies a
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        LEFT JOIN oem_sources s ON s.id = sn.source_id
        WHERE a.id = %s
        """,
        (assembly_id,),
    )
    if not assembly:
        return None
    diagram = fetch_one(
        """
        SELECT id, public_url, local_path, original_url, width, height
        , mime_type
        FROM oem_diagrams
        WHERE assembly_id = %s
        ORDER BY sort_order, id
        LIMIT 1
        """,
        (assembly_id,),
    )
    hotspots = fetch_all(
        """
        SELECT
          h.id,
          h.assembly_part_id,
          h.shape,
          h.raw_coords,
          h.x,
          h.y,
          h.width,
          h.height,
          COALESCE(h.ref, ap.ref) AS ref,
          h.source_items_list_id
        FROM oem_diagram_hotspots h
        LEFT JOIN oem_assembly_parts ap ON ap.id = h.assembly_part_id
        WHERE h.diagram_id = %s
        ORDER BY h.id
        """,
        (diagram["id"],) if diagram else (-1,),
    )
    parts = fetch_all(
        """
        SELECT
          ap.id AS assembly_part_id,
          ap.ref,
          ap.quantity,
          ap.row_kind,
          p.id AS part_id,
          p.name,
          p.part_number,
          p.normalized_part_number,
          p.manufacturer,
          obl.bitrix_product_id,
          obl.product_url,
          po.price,
          po.currency,
          po.availability
        FROM oem_assembly_parts ap
        JOIN oem_parts p ON p.id = ap.part_id
        LEFT JOIN oem_part_bitrix_links obl ON obl.part_id = p.id AND obl.is_active
        LEFT JOIN oem_part_offers po ON po.part_id = p.id
        WHERE ap.assembly_id = %s
        ORDER BY NULLIF(regexp_replace(ap.ref, '\\D', '', 'g'), '')::int NULLS LAST, ap.ref, ap.id
        """,
        (assembly_id,),
    )
    return {
        "assembly": assembly,
        "diagram": diagram,
        "hotspots": hotspots,
        "parts": parts,
        "source": {
            "code": assembly.get("source_code"),
            "url": assembly.get("source_url"),
            "source_node_id": assembly.get("source_node_id"),
        },
    }


def search_parts(q: str, limit: int = 50, offset: int = 0) -> list[dict[str, Any]]:
    normalized = "".join(ch for ch in q.upper() if ch.isalnum())
    return fetch_all(
        """
        SELECT
          p.id,
          p.part_number,
          p.normalized_part_number,
          p.name,
          p.manufacturer,
          COUNT(DISTINCT ap.id) AS used_in_count,
          MAX(obl.bitrix_product_id) AS bitrix_product_id,
          MAX(obl.product_url) AS product_url,
          MAX(po.price) AS price,
          MAX(po.currency) AS currency,
          MAX(po.availability) AS availability
        FROM oem_parts p
        LEFT JOIN oem_assembly_parts ap ON ap.part_id = p.id
        LEFT JOIN oem_part_bitrix_links obl ON obl.part_id = p.id AND obl.is_active
        LEFT JOIN oem_part_offers po ON po.part_id = p.id
        WHERE p.normalized_part_number ILIKE %s OR p.part_number ILIKE %s OR p.name ILIKE %s
        GROUP BY p.id
        ORDER BY p.part_number
        LIMIT %s OFFSET %s
        """,
        (f"%{normalized}%", f"%{q}%", f"%{q}%", limit, offset),
    )
