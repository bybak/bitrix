from typing import Any

from app.db import get_conn


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


def list_roots() -> list[dict[str, Any]]:
    return fetch_all(
        """
        SELECT id, arib_code, name, sort_order
        FROM oem_catalog_roots
        ORDER BY sort_order, name
        """
    )


def list_nav_children(root: str, parent_id: int | None = None) -> list[dict[str, Any]]:
    if parent_id is None:
        return fetch_all(
            """
            SELECT
              n.id,
              n.root_arib,
              n.parent_id,
              n.aria,
              n.slug,
              n.rel,
              n.title,
              n.path_json,
              n.depth,
              (
                SELECT COUNT(*) FROM oem_nav_nodes c WHERE c.parent_id = n.id
              ) AS child_count,
              (
                SELECT COUNT(*) FROM oem_variants v
                WHERE v.root_arib = n.root_arib AND v.path_json = n.path_json
              ) AS variant_count
            FROM oem_nav_nodes n
            WHERE n.root_arib = %s AND n.parent_id IS NULL
            ORDER BY n.title
            """,
            (root.upper(),),
        )
    return fetch_all(
        """
        SELECT
          n.id,
          n.root_arib,
          n.parent_id,
          n.aria,
          n.slug,
          n.rel,
          n.title,
          n.path_json,
          n.depth,
          (
            SELECT COUNT(*) FROM oem_nav_nodes c WHERE c.parent_id = n.id
          ) AS child_count,
          (
            SELECT COUNT(*) FROM oem_variants v
            WHERE v.root_arib = n.root_arib AND v.path_json = n.path_json
          ) AS variant_count
        FROM oem_nav_nodes n
        WHERE n.root_arib = %s AND n.parent_id = %s
        ORDER BY n.title
        """,
        (root.upper(), parent_id),
    )


def list_variants_for_nav(nav_node_id: int) -> list[dict[str, Any]]:
    return fetch_all(
        """
        SELECT
          v.id,
          v.root_arib,
          v.variant_key,
          v.model_name,
          v.source_designation,
          v.year_from,
          v.variant_section,
          v.browse_line,
          v.path_json,
          v.assembly_count
        FROM oem_variants v
        JOIN oem_nav_nodes n ON n.id = %s
        WHERE v.root_arib = n.root_arib AND v.path_json = n.path_json
        ORDER BY v.year_from DESC NULLS LAST, v.source_designation, v.variant_section
        """,
        (nav_node_id,),
    )


def list_variants_by_root(root: str, q: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [root.upper()]
    where = ["v.root_arib = %s"]
    if q:
        where.append("(v.model_name ILIKE %s OR v.source_designation ILIKE %s)")
        params.extend([f"%{q}%", f"%{q}%"])
    return fetch_all(
        f"""
        SELECT
          v.id,
          v.root_arib,
          v.variant_key,
          v.model_name,
          v.source_designation,
          v.year_from,
          v.variant_section,
          v.browse_line,
          v.path_json,
          v.assembly_count
        FROM oem_variants v
        WHERE {" AND ".join(where)}
        ORDER BY v.model_name, v.year_from DESC NULLS LAST, v.source_designation
        LIMIT 200
        """,
        tuple(params),
    )


def list_assemblies(variant_id: int, q: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [variant_id]
    where = ["a.variant_id = %s"]
    if q:
        where.append("a.title ILIKE %s")
        params.append(f"%{q}%")
    return fetch_all(
        f"""
        SELECT
          a.id,
          a.variant_id,
          a.root_arib,
          a.assembly_key,
          a.title,
          a.slug,
          COUNT(DISTINCT ap.id) AS part_count,
          MAX(d.public_url) AS public_url,
          MAX(d.local_path) AS local_path,
          MAX(dp.parse_status) AS parse_status
        FROM oem_assemblies a
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_details_pages dp ON dp.assembly_id = a.id
        WHERE {" AND ".join(where)}
        GROUP BY a.id
        ORDER BY a.sort_order, a.title
        """,
        tuple(params),
    )


def get_diagram_payload(assembly_id: int) -> dict[str, Any] | None:
    assembly = fetch_one(
        """
        SELECT a.id, a.title, a.variant_id, a.root_arib, a.assembly_key, a.slug
        FROM oem_assemblies a
        WHERE a.id = %s
        """,
        (assembly_id,),
    )
    if not assembly:
        return None
    diagram = fetch_one(
        """
        SELECT id, public_url, local_path, original_url, width, height, mime_type
        FROM oem_diagrams
        WHERE assembly_id = %s
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
          COALESCE(h.ref, ap.ref) AS ref
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
          p.normalized_part_number
        FROM oem_assembly_parts ap
        JOIN oem_parts p ON p.id = ap.part_id
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
    }


def search_parts(q: str, root: str | None = None, limit: int = 50, offset: int = 0) -> list[dict[str, Any]]:
    normalized = "".join(ch for ch in q.upper() if ch.isalnum())
    params: list[Any] = [f"%{normalized}%", f"%{q}%", f"%{q}%"]
    where = [
        "(p.normalized_part_number ILIKE %s OR p.part_number ILIKE %s OR p.name ILIKE %s)",
    ]
    if root:
        where.append("p.root_arib = %s")
        params.append(root.upper())
    params.extend([limit, offset])
    return fetch_all(
        f"""
        SELECT
          p.id,
          p.root_arib,
          p.part_number,
          p.normalized_part_number,
          p.name,
          COUNT(DISTINCT ap.id) AS used_in_count
        FROM oem_parts p
        LEFT JOIN oem_assembly_parts ap ON ap.part_id = p.id
        WHERE {" AND ".join(where)}
        GROUP BY p.id
        ORDER BY p.part_number
        LIMIT %s OFFSET %s
        """,
        tuple(params),
    )
