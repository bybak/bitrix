import hashlib
import json
from typing import Any

from app.db import get_conn
from app.normalization import normalize_part_number, normalize_text


def _one(query: str, params: tuple[Any, ...]) -> dict[str, Any]:
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            row = cur.fetchone()
            if row is None:
                raise RuntimeError(f"No row returned for query: {query}")
            return row


def source_id(code: str) -> int:
    return _one("SELECT id FROM oem_sources WHERE code = %s", (code,))["id"]


def ensure_brand(name: str) -> int:
    row = _one(
        """
        INSERT INTO oem_brands (name, normalized_name)
        VALUES (%s, %s)
        ON CONFLICT (normalized_name) DO UPDATE SET name = EXCLUDED.name, updated_at = now()
        RETURNING id
        """,
        (name, normalize_text(name)),
    )
    return row["id"]


def ensure_brand_alias(brand_id: int, source_code: str, alias: str) -> None:
    sid = source_id(source_code)
    _one(
        """
        INSERT INTO oem_brand_aliases (brand_id, source_id, alias, normalized_alias)
        VALUES (%s, %s, %s, %s)
        ON CONFLICT (source_id, normalized_alias) DO UPDATE SET brand_id = EXCLUDED.brand_id
        RETURNING id
        """,
        (brand_id, sid, alias, normalize_text(alias)),
    )


def vehicle_type_id(code: str) -> int:
    return _one("SELECT id FROM oem_vehicle_types WHERE code = %s", (code,))["id"]


def ensure_model_family(vehicle_type: str, brand_id: int, name: str) -> int:
    vt_id = vehicle_type_id(vehicle_type)
    row = _one(
        """
        INSERT INTO oem_model_families (vehicle_type_id, brand_id, name, normalized_name)
        VALUES (%s, %s, %s, %s)
        ON CONFLICT (vehicle_type_id, brand_id, normalized_name)
        DO UPDATE SET name = EXCLUDED.name, updated_at = now()
        RETURNING id
        """,
        (vt_id, brand_id, name, normalize_text(name)),
    )
    return row["id"]


def ensure_model_alias(model_family_id: int, source_code: str, alias: str, reviewed: bool = False) -> None:
    sid = source_id(source_code)
    _one(
        """
        INSERT INTO oem_model_aliases (model_family_id, source_id, alias, normalized_alias, is_reviewed)
        VALUES (%s, %s, %s, %s, %s)
        ON CONFLICT (source_id, normalized_alias)
        DO UPDATE SET model_family_id = EXCLUDED.model_family_id
        RETURNING id
        """,
        (model_family_id, sid, alias, normalize_text(alias), reviewed),
    )


def ensure_variant(model_family_id: int, **fields: Any) -> int:
    existing = _one(
        """
        SELECT COALESCE((
          SELECT id
          FROM oem_vehicle_variants
          WHERE model_family_id = %s
            AND COALESCE(year_from, 0) = COALESCE(%s, 0)
            AND COALESCE(year_to, 0) = COALESCE(%s, 0)
            AND COALESCE(model_code, '') = COALESCE(%s, '')
            AND COALESCE(region_code, '') = COALESCE(%s, '')
            AND COALESCE(color_code, '') = COALESCE(%s, '')
            AND COALESCE(source_designation, '') = COALESCE(%s, '')
            AND COALESCE(variant_section, '') = COALESCE(%s, '')
          LIMIT 1
        ), 0) AS id
        """,
        (
            model_family_id,
            fields.get("year_from"),
            fields.get("year_to"),
            fields.get("model_code"),
            fields.get("region_code"),
            fields.get("color_code"),
            fields.get("source_designation"),
            fields.get("variant_section"),
        ),
    )
    if existing["id"]:
        return existing["id"]
    row = _one(
        """
        INSERT INTO oem_vehicle_variants (
          model_family_id, year_from, year_to, model_code, region, region_code,
          color_code, color_name, engine_cc, market_name, source_designation, variant_section
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        RETURNING id
        """,
        (
            model_family_id,
            fields.get("year_from"),
            fields.get("year_to"),
            fields.get("model_code"),
            fields.get("region"),
            fields.get("region_code"),
            fields.get("color_code"),
            fields.get("color_name"),
            fields.get("engine_cc"),
            fields.get("market_name"),
            fields.get("source_designation"),
            fields.get("variant_section"),
        ),
    )
    return row["id"]


def ensure_source_node(
    *,
    source_code: str,
    node_type: str,
    title: str,
    external_id: str,
    parent_id: int | None = None,
    source_url: str | None = None,
    url_path: str | None = None,
    arib: str | None = None,
    aria: str | None = None,
    slug: str | None = None,
    raw_payload: str | None = None,
) -> int:
    sid = source_id(source_code)
    raw_hash = hashlib.sha256(raw_payload.encode("utf-8")).hexdigest() if raw_payload else None
    row = _one(
        """
        INSERT INTO oem_source_nodes (
          source_id, parent_id, node_type, title, normalized_title, source_url, url_path,
          external_id, arib, aria, slug, raw_hash, last_seen_at
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, now())
        ON CONFLICT (source_id, external_id) DO UPDATE SET
          title = EXCLUDED.title,
          normalized_title = EXCLUDED.normalized_title,
          source_url = EXCLUDED.source_url,
          url_path = EXCLUDED.url_path,
          arib = EXCLUDED.arib,
          aria = EXCLUDED.aria,
          slug = EXCLUDED.slug,
          raw_hash = EXCLUDED.raw_hash,
          last_seen_at = now(),
          updated_at = now()
        RETURNING id
        """,
        (
            sid,
            parent_id,
            node_type,
            title,
            normalize_text(title),
            source_url,
            url_path,
            external_id,
            arib,
            aria,
            slug,
            raw_hash,
        ),
    )
    return row["id"]


def link_source_node(source_node_id: int, entity_type: str, entity_id: int, confidence: float = 1.0) -> None:
    _one(
        """
        INSERT INTO oem_source_node_links (source_node_id, entity_type, entity_id, confidence)
        VALUES (%s, %s, %s, %s)
        ON CONFLICT (source_node_id, entity_type, entity_id) DO UPDATE SET confidence = EXCLUDED.confidence
        RETURNING id
        """,
        (source_node_id, entity_type, entity_id, confidence),
    )


def assembly_import_status(source_code: str, external_id: str) -> dict[str, Any]:
    sid = source_id(source_code)
    row = _one(
        """
        SELECT
          COALESCE(MAX(sn.id), 0) AS source_node_id,
          COALESCE(MAX(a.id), 0) AS assembly_id,
          COUNT(DISTINCT d.id) AS diagram_count,
          COUNT(DISTINCT ap.id) AS part_count,
          COUNT(DISTINCT h.id) AS hotspot_count
        FROM oem_source_nodes sn
        LEFT JOIN oem_source_node_links snl
          ON snl.source_node_id = sn.id
          AND snl.entity_type = 'assembly'
        LEFT JOIN oem_assemblies a ON a.id = snl.entity_id
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        LEFT JOIN oem_diagram_hotspots h ON h.diagram_id = d.id
        WHERE sn.source_id = %s
          AND sn.external_id = %s
        """,
        (sid, external_id),
    )
    row["is_complete"] = bool(row["assembly_id"] and row["diagram_count"] and row["part_count"])
    return row


def ensure_assembly(variant_id: int, title: str, source_node_id: int | None = None, sort_order: int = 500) -> int:
    existing = _one(
        """
        SELECT COALESCE((
          SELECT id FROM oem_assemblies
          WHERE vehicle_variant_id = %s AND normalized_title = %s
          LIMIT 1
        ), 0) AS id
        """,
        (variant_id, normalize_text(title)),
    )
    if existing["id"]:
        return existing["id"]
    row = _one(
        """
        INSERT INTO oem_assemblies (vehicle_variant_id, source_node_id, title, normalized_title, sort_order)
        VALUES (%s, %s, %s, %s, %s)
        RETURNING id
        """,
        (variant_id, source_node_id, title, normalize_text(title), sort_order),
    )
    return row["id"]


def ensure_diagram(
    assembly_id: int,
    *,
    source_node_id: int | None,
    original_url: str | None,
    local_path: str | None = None,
    public_url: str | None = None,
    source_image_id: str | None = None,
    width: int | None = None,
    height: int | None = None,
    checksum_sha256: str | None = None,
) -> int:
    existing = _one(
        """
        SELECT COALESCE((
          SELECT id
          FROM oem_diagrams
          WHERE assembly_id = %s
            AND COALESCE(source_image_id, '') = COALESCE(%s, '')
          LIMIT 1
        ), 0) AS id
        """,
        (assembly_id, source_image_id),
    )
    if existing["id"]:
        row = _one(
            """
            UPDATE oem_diagrams
            SET
              source_node_id = %s,
              original_url = %s,
              local_path = %s,
              public_url = %s,
              width = %s,
              height = %s,
              checksum_sha256 = %s,
              updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (source_node_id, original_url, local_path, public_url, width, height, checksum_sha256, existing["id"]),
        )
        return row["id"]
    row = _one(
        """
        INSERT INTO oem_diagrams (
          assembly_id, source_node_id, original_url, local_path, public_url,
          source_image_id, width, height, checksum_sha256
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        RETURNING id
        """,
        (assembly_id, source_node_id, original_url, local_path, public_url, source_image_id, width, height, checksum_sha256),
    )
    return row["id"]


def ensure_part(manufacturer: str, part_number: str, name: str | None = None, brand_id: int | None = None) -> int:
    normalized = normalize_part_number(part_number)
    row = _one(
        """
        INSERT INTO oem_parts (brand_id, manufacturer, part_number, normalized_part_number, name)
        VALUES (%s, %s, %s, %s, %s)
        ON CONFLICT (normalized_part_number) DO UPDATE SET
          name = COALESCE(EXCLUDED.name, oem_parts.name),
          manufacturer = COALESCE(EXCLUDED.manufacturer, oem_parts.manufacturer),
          updated_at = now()
        RETURNING id
        """,
        (brand_id, manufacturer, part_number, normalized, name),
    )
    return row["id"]


def add_assembly_part(
    *,
    assembly_id: int,
    part_id: int,
    ref: str | None,
    quantity: float | None,
    row_kind: str = "original",
    source_node_id: int | None = None,
    source_row_id: str | None = None,
    source_items_list_id: str | None = None,
    raw_payload: dict[str, Any] | None = None,
) -> int:
    existing = _one(
        """
        SELECT COALESCE((
          SELECT id
          FROM oem_assembly_parts
          WHERE assembly_id = %s
            AND part_id = %s
            AND COALESCE(ref, '') = COALESCE(%s, '')
            AND COALESCE(source_row_id, '') = COALESCE(%s, '')
          LIMIT 1
        ), 0) AS id
        """,
        (assembly_id, part_id, ref, source_row_id),
    )
    if existing["id"]:
        row = _one(
            """
            UPDATE oem_assembly_parts
            SET
              source_node_id = %s,
              quantity = %s,
              row_kind = %s,
              source_items_list_id = %s,
              raw_payload = %s,
              updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (
                source_node_id,
                quantity,
                row_kind,
                source_items_list_id,
                json.dumps(raw_payload) if raw_payload else None,
                existing["id"],
            ),
        )
        return row["id"]
    row = _one(
        """
        INSERT INTO oem_assembly_parts (
          assembly_id, part_id, source_node_id, ref, quantity, row_kind,
          source_row_id, source_items_list_id, raw_payload
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        RETURNING id
        """,
        (
            assembly_id,
            part_id,
            source_node_id,
            ref,
            quantity,
            row_kind,
            source_row_id,
            source_items_list_id,
            json.dumps(raw_payload) if raw_payload else None,
        ),
    )
    return row["id"]


def add_hotspot(
    *,
    diagram_id: int,
    assembly_part_id: int | None = None,
    shape: str = "rect",
    raw_coords: str | None = None,
    x: float | None = None,
    y: float | None = None,
    width: float | None = None,
    height: float | None = None,
    ref: str | None = None,
    source_items_list_id: str | None = None,
    raw_payload: dict[str, Any] | None = None,
) -> int:
    existing = _one(
        """
        SELECT COALESCE((
          SELECT id
          FROM oem_diagram_hotspots
          WHERE diagram_id = %s
            AND COALESCE(assembly_part_id, 0) = COALESCE(%s, 0)
            AND COALESCE(ref, '') = COALESCE(%s, '')
            AND COALESCE(source_items_list_id, '') = COALESCE(%s, '')
            AND COALESCE(raw_coords, '') = COALESCE(%s, '')
          LIMIT 1
        ), 0) AS id
        """,
        (diagram_id, assembly_part_id, ref, source_items_list_id, raw_coords),
    )
    if existing["id"]:
        row = _one(
            """
            UPDATE oem_diagram_hotspots
            SET
              shape = %s,
              raw_coords = %s,
              x = %s,
              y = %s,
              width = %s,
              height = %s,
              raw_payload = %s,
              updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (
                shape,
                raw_coords,
                x,
                y,
                width,
                height,
                json.dumps(raw_payload) if raw_payload else None,
                existing["id"],
            ),
        )
        return row["id"]
    row = _one(
        """
        INSERT INTO oem_diagram_hotspots (
          diagram_id, assembly_part_id, shape, raw_coords, x, y, width, height,
          ref, source_items_list_id, raw_payload
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        RETURNING id
        """,
        (
            diagram_id,
            assembly_part_id,
            shape,
            raw_coords,
            x,
            y,
            width,
            height,
            ref,
            source_items_list_id,
            json.dumps(raw_payload) if raw_payload else None,
        ),
    )
    return row["id"]


def clear_diagram_hotspots(diagram_id: int) -> None:
    _one(
        """
        WITH deleted AS (
          DELETE FROM oem_diagram_hotspots
          WHERE diagram_id = %s
          RETURNING id
        )
        SELECT COUNT(*) AS id FROM deleted
        """,
        (diagram_id,),
    )


def add_source_price_snapshot(
    *,
    source_code: str,
    part_id: int | None = None,
    assembly_part_id: int | None = None,
    source_price_id: str | None = None,
    price: float | str | None = None,
    currency: str | None = None,
    min_qty: float | int | None = None,
    raw_payload: dict[str, Any] | None = None,
) -> int:
    sid = source_id(source_code)
    row = _one(
        """
        INSERT INTO oem_source_price_snapshots (
          source_id, part_id, assembly_part_id, source_price_id, price, currency, min_qty, raw_payload
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        RETURNING id
        """,
        (
            sid,
            part_id,
            assembly_part_id,
            source_price_id,
            price,
            currency,
            min_qty,
            json.dumps(raw_payload) if raw_payload else None,
        ),
    )
    return row["id"]


def add_part_relation(source_part_id: int, target_part_id: int, relation_type: str, source_code: str, raw_payload: dict[str, Any] | None = None) -> None:
    sid = source_id(source_code)
    _one(
        """
        INSERT INTO oem_part_relations (source_part_id, target_part_id, relation_type, source_id, raw_payload)
        VALUES (%s, %s, %s, %s, %s)
        ON CONFLICT (source_part_id, target_part_id, relation_type) DO UPDATE SET raw_payload = EXCLUDED.raw_payload
        RETURNING id
        """,
        (source_part_id, target_part_id, relation_type, sid, json.dumps(raw_payload) if raw_payload else None),
    )
