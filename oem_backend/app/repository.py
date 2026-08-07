import json
import struct
from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Any

from app.config import get_settings
from app.normalization import normalize_part_number
from app.registry import repository as registry_repo
from app.registry.catalog_router import (
    get_catalog_conn,
    get_catalog_conn_for_root,
    list_catalog_db_codes,
    resolve_root_arib_for_assembly,
    resolve_root_arib_for_nav_node,
    resolve_root_arib_for_variant,
)


def _variant_select_fields(*, alias: str = "v") -> str:
    a = alias
    return f"""
          {a}.id,
          {a}.root_arib,
          {a}.variant_key,
          {a}.model_name,
          {a}.source_designation,
          {a}.year_from,
          {a}.variant_section,
          {a}.browse_line,
          {a}.path_json,
          {a}.assembly_count,
          {a}.source_payload->'model_variant'->>'modelTypeCode' AS model_type_code,
          {a}.source_payload->'model_variant'->>'productNo' AS product_no,
          {a}.source_payload->'model_variant'->>'colorType' AS color_code,
          {a}.source_payload->'model_variant'->>'colorName' AS color_name,
          {a}.source_payload->'model_variant'->>'prodPictureFileURL' AS thumbnail_url
    """


def _normalize_external_url(url: str | None) -> str | None:
    if not url:
        return None
    value = str(url).strip()
    if not value:
        return None
    if value.startswith("//"):
        return f"https:{value}"
    return value


def _format_variant_row(row: dict[str, Any]) -> dict[str, Any]:
    payload = dict(row)
    model_type_code = payload.get("model_type_code")
    product_no = payload.get("product_no")
    color_code = payload.get("color_code")
    color_name = payload.get("color_name")
    path_json = payload.get("path_json") or []

    if not model_type_code or not product_no or not color_code:
        try:
            key_parts = json.loads(str(payload.get("variant_key") or "[]"))
        except json.JSONDecodeError:
            key_parts = []
        if len(key_parts) >= 9:
            model_type_code = model_type_code or str(key_parts[6] or "").strip() or None
            product_no = product_no or str(key_parts[7] or "").strip() or None
            color_code = color_code or str(key_parts[8] or "").strip() or None

    if not color_name and isinstance(path_json, list) and len(path_json) >= 5:
        color_name = str(path_json[-1] or "").strip() or None

    model_code_parts = [part for part in (model_type_code, product_no) if part]
    model_code = "-".join(model_code_parts)
    if model_code and color_code:
        model_code = f"{model_code}-{color_code}"

    payload["model_type_code"] = model_type_code
    payload["product_no"] = product_no
    payload["color_code"] = color_code
    payload["color_name"] = color_name
    payload["model_code"] = model_code or None
    payload["thumbnail_url"] = _normalize_external_url(payload.get("thumbnail_url"))
    return payload


def _format_variant_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [_format_variant_row(row) for row in rows]


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


@contextmanager
def _catalog_conn(
    *,
    root_arib: str | None = None,
    db_code: str | None = None,
    variant_id: int | None = None,
    assembly_id: int | None = None,
    nav_node_id: int | None = None,
) -> Iterator:
    if root_arib:
        with get_catalog_conn_for_root(root_arib=root_arib) as conn:
            yield conn
        return
    if db_code:
        with get_catalog_conn(db_code=db_code) as conn:
            yield conn
        return
    resolved: str | None = None
    if variant_id is not None:
        resolved = resolve_root_arib_for_variant(variant_id)
    elif assembly_id is not None:
        resolved = resolve_root_arib_for_assembly(assembly_id)
    elif nav_node_id is not None:
        resolved = resolve_root_arib_for_nav_node(nav_node_id)
    if resolved:
        with get_catalog_conn_for_root(root_arib=resolved) as conn:
            yield conn
        return
    raise ValueError("catalog routing context is required")


def fetch_all(
    query: str,
    params: tuple[Any, ...] = (),
    *,
    root_arib: str | None = None,
    db_code: str | None = None,
    variant_id: int | None = None,
    assembly_id: int | None = None,
    nav_node_id: int | None = None,
) -> list[dict[str, Any]]:
    with _catalog_conn(
        root_arib=root_arib,
        db_code=db_code,
        variant_id=variant_id,
        assembly_id=assembly_id,
        nav_node_id=nav_node_id,
    ) as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())


def fetch_one(
    query: str,
    params: tuple[Any, ...] = (),
    *,
    root_arib: str | None = None,
    db_code: str | None = None,
    variant_id: int | None = None,
    assembly_id: int | None = None,
    nav_node_id: int | None = None,
) -> dict[str, Any] | None:
    with _catalog_conn(
        root_arib=root_arib,
        db_code=db_code,
        variant_id=variant_id,
        assembly_id=assembly_id,
        nav_node_id=nav_node_id,
    ) as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()


def fetch_all_catalogs(query: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for db_code in list_catalog_db_codes():
        with get_catalog_conn(db_code=db_code) as conn:
            with conn.cursor() as cur:
                cur.execute(query, params)
                rows.extend(cur.fetchall())
    return rows


def list_brands() -> list[dict[str, Any]]:
    return registry_repo.list_brands()


def _root_payload(row: dict[str, Any]) -> dict[str, Any]:
    return {
        "arib_code": row["root_arib"],
        "name": row["name"],
        "sort_order": row["sort_order"],
        "brand_code": row["brand_code"],
        "brand_name": row["brand_name"],
        "catalog_db_code": row["catalog_db_code"],
    }


def list_roots(*, brand_code: str | None = None) -> list[dict[str, Any]]:
    rows = registry_repo.list_brand_roots(brand_code=brand_code)
    return [_root_payload(row) for row in rows]


def get_root(*, root_arib: str) -> dict[str, Any] | None:
    code = root_arib.strip().upper()
    for row in registry_repo.list_brand_roots():
        if row["root_arib"].upper() == code:
            return _root_payload(row)
    return None


def _nav_variant_count_sql(nav_alias: str = "n") -> str:
    return f"""
              (
                SELECT COUNT(*) FROM oem_variants v
                WHERE v.root_arib = {nav_alias}.root_arib
                  AND (
                    v.path_json = {nav_alias}.path_json
                    OR (
                      v.path_json @> {nav_alias}.path_json
                      AND jsonb_array_length(v.path_json) = jsonb_array_length({nav_alias}.path_json) + 1
                    )
                  )
              ) AS variant_count
    """


def list_nav_children(root: str, parent_id: int | None = None) -> list[dict[str, Any]]:
    variant_count_sql = _nav_variant_count_sql("n")
    if parent_id is None:
        return fetch_all(
            f"""
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
              {variant_count_sql}
            FROM oem_nav_nodes n
            WHERE n.root_arib = %s AND n.parent_id IS NULL AND n.rel <> 'region'
            ORDER BY n.sort_order, n.title
            """,
            (root.upper(),),
            root_arib=root,
        )
    return fetch_all(
        f"""
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
          {variant_count_sql}
        FROM oem_nav_nodes n
        WHERE n.root_arib = %s AND n.parent_id = %s
        ORDER BY n.sort_order, n.title
        """,
        (root.upper(), parent_id),
        root_arib=root,
    )


def list_variants_for_nav(nav_node_id: int, *, root_arib: str) -> list[dict[str, Any]]:
    rows = fetch_all(
        f"""
        SELECT
          {_variant_select_fields(alias="v")}
        FROM oem_variants v
        JOIN oem_nav_nodes n ON n.id = %s
        WHERE v.root_arib = n.root_arib
          AND (
            v.path_json = n.path_json
            OR (
              v.path_json @> n.path_json
              AND jsonb_array_length(v.path_json) = jsonb_array_length(n.path_json) + 1
            )
          )
        ORDER BY v.model_name, v.year_from DESC NULLS LAST, v.source_designation, v.variant_section
        """,
        (nav_node_id,),
        root_arib=root_arib,
    )
    return _format_variant_rows(rows)


def list_variants_by_root(root: str, q: str | None = None) -> list[dict[str, Any]]:
    params: list[Any] = [root.upper()]
    where = ["v.root_arib = %s"]
    if q:
        where.append("(v.model_name ILIKE %s OR v.source_designation ILIKE %s)")
        params.extend([f"%{q}%", f"%{q}%"])
    rows = fetch_all(
        f"""
        SELECT
          {_variant_select_fields(alias="v")}
        FROM oem_variants v
        WHERE {" AND ".join(where)}
        ORDER BY v.model_name, v.year_from DESC NULLS LAST, v.source_designation
        LIMIT 200
        """,
        tuple(params),
        root_arib=root,
    )
    return _format_variant_rows(rows)


def list_assemblies(variant_id: int, *, root_arib: str, q: str | None = None) -> list[dict[str, Any]]:
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
        root_arib=root_arib,
    )


def get_variant(variant_id: int, *, root_arib: str) -> dict[str, Any] | None:
    row = fetch_one(
        f"""
        SELECT
          {_variant_select_fields(alias="v")}
        FROM oem_variants v
        WHERE v.id = %s
        """,
        (variant_id,),
        root_arib=root_arib,
    )
    return _format_variant_row(row) if row else None


def get_diagram_payload(assembly_id: int, *, root_arib: str) -> dict[str, Any] | None:
    assembly = fetch_one(
        """
        SELECT a.id, a.title, a.variant_id, a.root_arib, a.assembly_key, a.slug
        FROM oem_assemblies a
        WHERE a.id = %s
        """,
        (assembly_id,),
        root_arib=root_arib,
    )
    if not assembly:
        return None
    root_arib = str(assembly["root_arib"])
    diagram = fetch_one(
        """
        SELECT id, public_url, local_path, original_url, width, height, coord_width, coord_height, mime_type
        FROM oem_diagrams
        WHERE assembly_id = %s
        LIMIT 1
        """,
        (assembly_id,),
        root_arib=root_arib,
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
        root_arib=root_arib,
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
        root_arib=root_arib,
    )
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
    query = f"""
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
        """
    if root:
        return fetch_all(query, tuple(params), root_arib=root)
    rows = fetch_all_catalogs(query, tuple(params))
    rows.sort(key=lambda row: (row.get("part_number") or "").casefold())
    return rows[offset : offset + limit]


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

    count_params = (normalized, f"{normalized}%", f"%{q.strip()}%")
    count_rows = fetch_all_catalogs(
        """
        SELECT COUNT(DISTINCT a.id) AS total_count
        FROM oem_parts p
        JOIN oem_assembly_parts ap ON ap.part_id = p.id
        JOIN oem_assemblies a ON a.id = ap.assembly_id
        WHERE p.normalized_part_number = %s
           OR p.normalized_part_number LIKE %s
           OR p.part_number ILIKE %s
        """,
        count_params,
    )
    total_count = sum(int((row or {}).get("total_count") or 0) for row in count_rows)

    rows = fetch_all_catalogs(
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
    rows.sort(
        key=lambda row: (
            row.get("root_sort_order") or 0,
            (row.get("root_name") or "").casefold(),
            (row.get("browse_line") or "").casefold(),
            str(row.get("path_json") or ""),
            (row.get("model_name") or "").casefold(),
            row.get("year_from") is None,
            -(row.get("year_from") or 0),
            (row.get("source_designation") or "").casefold(),
            (row.get("assembly_title") or "").casefold(),
            (row.get("ref") or "").casefold(),
        )
    )
    truncated = len(rows) > limit
    if truncated:
        rows = rows[:limit]

    root_registry = {row["arib_code"]: row for row in list_roots()}
    brand_root_counts: dict[str, int] = {}
    for reg in root_registry.values():
        code = str(reg["brand_code"])
        brand_root_counts[code] = brand_root_counts.get(code, 0) + 1

    brands: dict[str, dict[str, Any]] = {}
    for row in rows:
        root_key = row["root_arib"]
        reg = root_registry.get(root_key)
        brand_code = str(reg["brand_code"]) if reg else root_key
        if reg and brand_root_counts.get(str(reg["brand_code"]), 0) > 1:
            root_name = f"{reg['brand_name']} · {reg['name']}"
        else:
            root_name = str(reg["brand_name"] if reg else row["root_name"])
        brand = brands.get(root_key)
        if brand is None:
            brand = {
                "root_arib": row["root_arib"],
                "root_name": root_name,
                "brand_code": brand_code,
                "brand_name": str(reg["brand_name"]) if reg else root_name,
                "region_name": str(reg["name"]) if reg and brand_root_counts.get(str(reg["brand_code"]), 0) > 1 else None,
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
