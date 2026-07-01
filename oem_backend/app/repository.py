import struct
from pathlib import Path
from typing import Any

from app.config import get_settings
from app.db import get_conn
from app.normalization import normalize_part_number


def png_dimensions(path: Path) -> tuple[int, int] | None:
    if not path.is_file():
        return None
    with path.open("rb") as handle:
        header = handle.read(24)
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    width, height = struct.unpack(">II", header[16:24])
    if width <= 0 or height <= 0:
        return None
    return int(width), int(height)


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


def get_variant(variant_id: int) -> dict[str, Any] | None:
    return fetch_one(
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
        WHERE v.id = %s
        """,
        (variant_id,),
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
        SELECT id, public_url, local_path, original_url, width, height, coord_width, coord_height, mime_type
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
    image_size = None
    if diagram and diagram.get("local_path"):
        image_path = Path(get_settings().asset_root) / str(diagram["local_path"])
        image_size = png_dimensions(image_path)
    coord_space = None
    if diagram and diagram.get("coord_width") and diagram.get("coord_height"):
        coord_space = {
            "width": float(diagram["coord_width"]),
            "height": float(diagram["coord_height"]),
        }
    return {
        "assembly": assembly,
        "diagram": diagram,
        "hotspots": hotspots,
        "parts": parts,
        "image_size": (
            {"width": image_size[0], "height": image_size[1]} if image_size else None
        ),
        "coord_space": coord_space,
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


def _family_label(*, browse_line: str | None, path_json: Any) -> str:
    browse = (browse_line or "").strip()
    if browse:
        return browse
    path = path_json
    if isinstance(path, str):
        import json

        path = json.loads(path)
    if isinstance(path, list) and path:
        return str(path[0])
    return "Прочее"


def search_part_usages(q: str, *, limit: int = 1000) -> dict[str, Any]:
    normalized = normalize_part_number(q)
    if len(normalized) < 2:
        return {
            "query": q,
            "normalized": normalized,
            "match_count": 0,
            "total_count": 0,
            "truncated": False,
            "groups": [],
        }

    count_row = fetch_one(
        """
        SELECT COUNT(DISTINCT a.id) AS total_count
        FROM oem_parts p
        JOIN oem_assembly_parts ap ON ap.part_id = p.id
        JOIN oem_assemblies a ON a.id = ap.assembly_id
        WHERE p.normalized_part_number = %s
           OR p.normalized_part_number LIKE %s
           OR p.part_number ILIKE %s
        """,
        (normalized, f"{normalized}%", f"%{q.strip()}%"),
    )
    total_count = int((count_row or {}).get("total_count") or 0)

    rows = fetch_all(
        """
        SELECT
          r.arib_code AS root_arib,
          r.name AS root_name,
          r.sort_order AS root_sort_order,
          v.id AS variant_id,
          v.model_name,
          v.source_designation,
          v.year_from,
          v.variant_section,
          v.browse_line,
          v.path_json,
          a.id AS assembly_id,
          a.title AS assembly_title,
          ap.ref,
          ap.quantity,
          p.part_number,
          p.name AS part_name
        FROM oem_parts p
        JOIN oem_assembly_parts ap ON ap.part_id = p.id
        JOIN oem_assemblies a ON a.id = ap.assembly_id
        JOIN oem_variants v ON v.id = a.variant_id
        JOIN oem_catalog_roots r ON r.arib_code = v.root_arib
        WHERE p.normalized_part_number = %s
           OR p.normalized_part_number LIKE %s
           OR p.part_number ILIKE %s
        ORDER BY
          r.sort_order,
          r.name,
          v.browse_line NULLS LAST,
          v.path_json::text,
          v.model_name,
          v.year_from DESC NULLS LAST,
          v.source_designation,
          a.sort_order,
          a.title,
          ap.ref
        LIMIT %s
        """,
        (normalized, f"{normalized}%", f"%{q.strip()}%", limit + 1),
    )
    truncated = len(rows) > limit
    if truncated:
        rows = rows[:limit]

    brands: dict[str, dict[str, Any]] = {}
    for row in rows:
        root_key = row["root_arib"]
        brand = brands.get(root_key)
        if brand is None:
            brand = {
                "root_arib": row["root_arib"],
                "root_name": row["root_name"],
                "families": {},
            }
            brands[root_key] = brand

        family_label = _family_label(browse_line=row["browse_line"], path_json=row["path_json"])
        families: dict[str, dict[str, Any]] = brand["families"]
        family = families.get(family_label)
        if family is None:
            family = {
                "key": family_label,
                "label": family_label,
                "models": {},
            }
            families[family_label] = family

        model_label = (row["model_name"] or "").strip() or "Без модели"
        models: dict[str, dict[str, Any]] = family["models"]
        model = models.get(model_label)
        if model is None:
            model = {
                "key": model_label,
                "label": model_label,
                "variants": {},
            }
            models[model_label] = model

        variant_id = int(row["variant_id"])
        variants: dict[int, dict[str, Any]] = model["variants"]
        variant = variants.get(variant_id)
        if variant is None:
            variant = {
                "id": variant_id,
                "model_name": row["model_name"],
                "source_designation": row["source_designation"],
                "year_from": row["year_from"],
                "variant_section": row["variant_section"],
                "browse_line": row["browse_line"],
                "path_json": row["path_json"],
                "assemblies": {},
            }
            variants[variant_id] = variant

        assembly_id = int(row["assembly_id"])
        assemblies: dict[int, dict[str, Any]] = variant["assemblies"]
        assembly = assemblies.get(assembly_id)
        if assembly is None:
            assembly = {
                "id": assembly_id,
                "title": row["assembly_title"],
                "part_number": row["part_number"],
                "part_name": row["part_name"],
                "refs": [],
            }
            assemblies[assembly_id] = assembly
        ref = (row["ref"] or "").strip()
        if ref and ref not in assembly["refs"]:
            assembly["refs"].append(ref)

    groups: list[dict[str, Any]] = []
    assembly_count = 0
    for brand in sorted(brands.values(), key=lambda item: (item["root_name"], item["root_arib"])):
        family_list: list[dict[str, Any]] = []
        for family in sorted(brand["families"].values(), key=lambda item: item["label"].casefold()):
            model_list: list[dict[str, Any]] = []
            for model in sorted(family["models"].values(), key=lambda item: item["label"].casefold()):
                variant_list: list[dict[str, Any]] = []
                for variant in sorted(
                    model["variants"].values(),
                    key=lambda item: (
                        item["year_from"] is None,
                        -(item["year_from"] or 0),
                        (item["source_designation"] or "").casefold(),
                    ),
                ):
                    assembly_list = sorted(variant["assemblies"].values(), key=lambda item: item["title"].casefold())
                    assembly_count += len(assembly_list)
                    variant_list.append(
                        {
                            "id": variant["id"],
                            "model_name": variant["model_name"],
                            "source_designation": variant["source_designation"],
                            "year_from": variant["year_from"],
                            "variant_section": variant["variant_section"],
                            "browse_line": variant["browse_line"],
                            "path_json": variant["path_json"],
                            "assemblies": assembly_list,
                        }
                    )
                model_list.append(
                    {
                        "key": model["key"],
                        "label": model["label"],
                        "variants": variant_list,
                    }
                )
            family_list.append(
                {
                    "key": family["key"],
                    "label": family["label"],
                    "models": model_list,
                }
            )
        groups.append(
            {
                "root_arib": brand["root_arib"],
                "root_name": brand["root_name"],
                "families": family_list,
            }
        )

    return {
        "query": q,
        "normalized": normalized,
        "match_count": assembly_count,
        "total_count": total_count,
        "truncated": truncated,
        "groups": groups,
    }
