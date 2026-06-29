import hashlib
import json
import threading
from contextlib import contextmanager
from typing import Any, Iterator

from app.db import get_conn
from app.normalization import normalize_part_number, normalize_text

_local = threading.local()
_source_ids: dict[str, int] = {}
_vehicle_type_ids: dict[str, int] = {}
_lookup_lock = threading.Lock()


def _normalize_assembly_slug(slug: str | None) -> str | None:
    if not slug:
        return None
    value = slug.strip().rstrip("/")
    if value.endswith("/y"):
        value = value[:-2]
    return value or None


def canonical_assembly_arib(arib: str | None) -> str | None:
    if not arib:
        return None
    code = arib.strip().upper()
    if code in {"BRP_SEA", "BRP_SKI"}:
        return "BRP"
    return code


def assembly_compare_key(
    *,
    arib: str | None,
    aria: str | None = None,
    slug: str | None = None,
    path: list[str] | None = None,
    external_id: str | None = None,
) -> str | None:
    canon_arib = canonical_assembly_arib(arib)
    aria_value = (aria or "").strip() or None
    if canon_arib and aria_value:
        return f"{canon_arib}:{aria_value}"
    normalized_slug = _normalize_assembly_slug(slug)
    if canon_arib and normalized_slug:
        return f"{canon_arib}:{normalized_slug}"
    if external_id and ":" in external_id:
        ext_arib, ext_rest = external_id.split(":", 1)
        canon_ext = canonical_assembly_arib(ext_arib) or ext_arib
        if ext_rest and not ext_rest.startswith("/"):
            return f"{canon_ext}:{ext_rest}"
    if canon_arib and path:
        return f"{canon_arib}:{aria_value or 'no-aria'}:{'/'.join(path)}"
    return external_id


def _active_conn():
    return getattr(_local, "conn", None)


@contextmanager
def batch_conn() -> Iterator[None]:
    """Reuse one PostgreSQL connection + single commit per assembly import."""
    if _active_conn() is not None:
        yield
        return
    with get_conn() as conn:
        _local.conn = conn
        try:
            yield
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            _local.conn = None


def _one(query: str, params: tuple[Any, ...]) -> dict[str, Any]:
    conn = _active_conn()
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            row = cur.fetchone()
            if row is None:
                raise RuntimeError(f"No row returned for query: {query}")
            return row
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            row = cur.fetchone()
            if row is None:
                raise RuntimeError(f"No row returned for query: {query}")
            return row


def source_id(code: str) -> int:
    with _lookup_lock:
        cached = _source_ids.get(code)
    if cached is not None:
        return cached
    row = _one("SELECT id FROM oem_sources WHERE code = %s", (code,))
    with _lookup_lock:
        _source_ids[code] = row["id"]
        return row["id"]


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
    with _lookup_lock:
        cached = _vehicle_type_ids.get(code)
    if cached is not None:
        return cached
    row = _one("SELECT id FROM oem_vehicle_types WHERE code = %s", (code,))
    with _lookup_lock:
        _vehicle_type_ids[code] = row["id"]
        return row["id"]


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


def assembly_import_status(
    source_code: str,
    external_id: str,
    *,
    arib: str | None = None,
    aria: str | None = None,
    slug: str | None = None,
) -> dict[str, Any]:
    sid = source_id(source_code)
    lookup_ids = {external_id}
    canonical_key = assembly_compare_key(arib=arib, aria=aria, slug=slug, external_id=external_id)
    if canonical_key:
        lookup_ids.add(canonical_key)

    aria_value = (aria or "").strip() or None
    lookup_arib = canonical_assembly_arib(arib) or (arib or "").upper()
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
        LEFT JOIN oem_assemblies a
          ON a.id = snl.entity_id
          OR a.source_node_id = sn.id
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        LEFT JOIN oem_diagram_hotspots h ON h.diagram_id = d.id
        WHERE sn.source_id = %s
          AND sn.node_type = 'assembly'
          AND (
            sn.external_id = ANY(%s)
            OR (
              NULLIF(%s::text, '') IS NOT NULL
              AND sn.aria = %s::text
              AND UPPER(COALESCE(sn.arib, '')) IN ('BRP', 'BRP_SKI', 'BRP_SEA', %s::text)
            )
          )
        """,
        (
            sid,
            list(lookup_ids),
            aria_value,
            aria_value,
            lookup_arib,
        ),
    )
    row["is_complete"] = bool(row["assembly_id"] and row["diagram_count"] and row["part_count"])
    return row


def assembly_diagram_image_status(assembly_id: int) -> dict[str, Any]:
    """Diagram row for an existing assembly (image sync checks local_path on disk)."""
    from pathlib import Path

    from app.config import get_settings

    row = _one(
        """
        SELECT
          a.id AS assembly_id,
          a.source_node_id,
          d.id AS diagram_id,
          d.local_path,
          d.original_url,
          sn.arib
        FROM oem_assemblies a
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE a.id = %s
        ORDER BY d.id NULLS LAST
        LIMIT 1
        """,
        (assembly_id,),
    )
    local_path = row.get("local_path")
    has_file = False
    if local_path:
        has_file = (Path(get_settings().asset_root) / local_path).is_file()
    row["has_local_file"] = has_file
    return row


def _source_node_compare_rank(*, arib: str | None, aria: str | None, slug: str | None, external_id: str | None) -> tuple[int, int]:
    key = assembly_compare_key(arib=arib, aria=aria, slug=slug, external_id=external_id)
    if not key:
        return (2, 0)
    _canon_arib, rest = key.split(":", 1)
    prefers_aria = bool(rest) and not rest.startswith("/")
    return (0 if prefers_aria else 1, len(key))


def _upgrade_assembly_source_node(assembly_id: int, source_node_id: int | None) -> None:
    if not source_node_id:
        return
    current = _one(
        """
        SELECT a.source_node_id, sn.arib, sn.aria, sn.slug, sn.external_id
        FROM oem_assemblies a
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE a.id = %s
        """,
        (assembly_id,),
    )
    if not current:
        return
    if not current.get("source_node_id"):
        _one(
            """
            UPDATE oem_assemblies
            SET source_node_id = %s, updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (source_node_id, assembly_id),
        )
        return
    if int(current["source_node_id"]) == int(source_node_id):
        return
    candidate = _one(
        """
        SELECT arib, aria, slug, external_id
        FROM oem_source_nodes
        WHERE id = %s
        """,
        (source_node_id,),
    )
    if not candidate:
        return
    current_rank = _source_node_compare_rank(
        arib=current.get("arib"),
        aria=current.get("aria"),
        slug=current.get("slug"),
        external_id=current.get("external_id"),
    )
    candidate_rank = _source_node_compare_rank(
        arib=candidate.get("arib"),
        aria=candidate.get("aria"),
        slug=candidate.get("slug"),
        external_id=candidate.get("external_id"),
    )
    if candidate_rank >= current_rank:
        return
    _one(
        """
        UPDATE oem_assemblies
        SET source_node_id = %s, updated_at = now()
        WHERE id = %s
        RETURNING id
        """,
        (source_node_id, assembly_id),
    )


def _all(query: str, params: tuple[Any, ...]) -> list[dict[str, Any]]:
    conn = _active_conn()
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())


def _run(query: str, params: tuple[Any, ...] = ()) -> None:
    conn = _active_conn()
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
        return
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(query, params)
        conn.commit()


def _assembly_is_complete(assembly_id: int) -> bool:
    row = _one(
        """
        SELECT
          COUNT(DISTINCT d.id) AS diagram_count,
          COUNT(DISTINCT ap.id) AS part_count
        FROM oem_assemblies a
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        WHERE a.id = %s
        GROUP BY a.id
        """,
        (assembly_id,),
    )
    return bool(int(row["diagram_count"] or 0) and int(row["part_count"] or 0))


def _find_assembly_on_variant(
    variant_id: int,
    *,
    source_node_id: int | None,
    normalized_title: str | None = None,
) -> int | None:
    row = _one(
        """
        SELECT COALESCE((
          SELECT id FROM oem_assemblies
          WHERE vehicle_variant_id = %s
            AND (
              (%s::bigint IS NOT NULL AND source_node_id = %s)
              OR (%s::text IS NOT NULL AND normalized_title = %s)
            )
          ORDER BY CASE WHEN source_node_id = %s THEN 0 ELSE 1 END, id
          LIMIT 1
        ), 0) AS id
        """,
        (
            variant_id,
            source_node_id,
            source_node_id,
            normalized_title,
            normalized_title,
            source_node_id,
        ),
    )
    return int(row["id"]) if row["id"] else None


def clone_assembly_contents(*, donor_assembly_id: int, target_assembly_id: int) -> None:
    """Copy diagram, parts, hotspots, and price snapshots onto an empty target assembly."""
    if donor_assembly_id == target_assembly_id:
        return
    if _assembly_is_complete(target_assembly_id):
        return

    donor_diagram = _one(
        """
        SELECT COALESCE((
          SELECT id FROM oem_diagrams WHERE assembly_id = %s ORDER BY sort_order, id LIMIT 1
        ), 0) AS id
        """,
        (donor_assembly_id,),
    )
    target_diagram = _one(
        """
        SELECT COALESCE((
          SELECT id FROM oem_diagrams WHERE assembly_id = %s ORDER BY sort_order, id LIMIT 1
        ), 0) AS id
        """,
        (target_assembly_id,),
    )
    target_diagram_id = int(target_diagram["id"] or 0)
    if not target_diagram_id and donor_diagram["id"]:
        copied = _one(
            """
            INSERT INTO oem_diagrams (
              assembly_id, source_node_id, original_url, local_path, public_url,
              source_image_id, width, height, mime_type, checksum_sha256, sort_order
            )
            SELECT
              %s, source_node_id, original_url, local_path, public_url,
              source_image_id, width, height, mime_type, checksum_sha256, sort_order
            FROM oem_diagrams
            WHERE id = %s
            RETURNING id
            """,
            (target_assembly_id, int(donor_diagram["id"])),
        )
        target_diagram_id = int(copied["id"])

    donor_diagram_id = int(donor_diagram["id"] or 0)
    if not target_diagram_id or not donor_diagram_id:
        return

    donor_part_count = _one(
        "SELECT COUNT(*) AS cnt FROM oem_assembly_parts WHERE assembly_id = %s",
        (donor_assembly_id,),
    )
    if int(donor_part_count["cnt"] or 0) <= 0:
        return

    _run(
        """
        WITH donor_parts AS (
          SELECT id, part_id, source_node_id, ref, quantity, row_kind,
                 source_row_id, source_items_list_id, raw_payload
          FROM oem_assembly_parts
          WHERE assembly_id = %s
        ),
        inserted_parts AS (
          INSERT INTO oem_assembly_parts (
            assembly_id, part_id, source_node_id, ref, quantity, row_kind,
            source_row_id, source_items_list_id, raw_payload
          )
          SELECT
            %s, part_id, source_node_id, ref, quantity, row_kind,
            source_row_id, source_items_list_id, raw_payload
          FROM donor_parts
          ORDER BY id
          RETURNING id
        ),
        part_map AS (
          SELECT dp.id AS donor_part_id, ip.id AS target_part_id
          FROM (
            SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn
            FROM donor_parts
          ) dp
          JOIN (
            SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn
            FROM inserted_parts
          ) ip USING (rn)
        ),
        inserted_hotspots AS (
          INSERT INTO oem_diagram_hotspots (
            diagram_id, assembly_part_id, shape, raw_coords, x, y, width, height,
            polygon_json, ref, source_items_list_id, raw_payload
          )
          SELECT
            %s,
            pm.target_part_id,
            h.shape, h.raw_coords, h.x, h.y, h.width, h.height,
            h.polygon_json, h.ref, h.source_items_list_id, h.raw_payload
          FROM oem_diagram_hotspots h
          LEFT JOIN part_map pm ON pm.donor_part_id = h.assembly_part_id
          WHERE h.diagram_id = %s
          RETURNING id
        )
        INSERT INTO oem_source_price_snapshots (
          source_id, part_id, assembly_part_id, source_price_id, price, currency,
          min_qty, raw_payload
        )
        SELECT
          p.source_id, p.part_id, pm.target_part_id, p.source_price_id, p.price, p.currency,
          p.min_qty, p.raw_payload
        FROM oem_source_price_snapshots p
        JOIN part_map pm ON pm.donor_part_id = p.assembly_part_id
        """,
        (
            donor_assembly_id,
            target_assembly_id,
            target_diagram_id,
            donor_diagram_id,
        ),
    )


def ensure_assembly_linked_on_variant(
    variant_id: int,
    title: str,
    source_node_id: int | None,
    *,
    donor_assembly_id: int,
) -> tuple[int, str]:
    """Ensure variant has its own complete assembly row for a shared Remotors aria."""
    normalized = normalize_text(title)
    existing_id = _find_assembly_on_variant(
        variant_id,
        source_node_id=source_node_id,
        normalized_title=normalized,
    )
    if existing_id and _assembly_is_complete(existing_id):
        _upgrade_assembly_source_node(existing_id, source_node_id)
        return existing_id, "exists"

    if existing_id:
        _upgrade_assembly_source_node(existing_id, source_node_id)
        clone_assembly_contents(donor_assembly_id=donor_assembly_id, target_assembly_id=existing_id)
        return existing_id, "filled"

    target_id = ensure_assembly(variant_id, title, source_node_id)
    clone_assembly_contents(donor_assembly_id=donor_assembly_id, target_assembly_id=target_id)
    return target_id, "cloned"


def ensure_assembly_variant_link(
    variant_id: int,
    title: str,
    source_node_id: int | None,
    *,
    existing_assembly_id: int | None = None,
) -> tuple[int, str]:
    """Attach imported assembly content to the variant from the crawl path.

    When repair skips a complete assembly, it may still be linked to the wrong
    vehicle_variant_id. Snapshot assigns each assembly_key to one variant only,
    so moving the row is safe.

    When ``existing_assembly_id`` is set (align/relink), always move that row
    unless it is already on ``variant_id``. Do not treat a different assembly on
    the target with the same normalized title as "already linked" — that stub
    blocked global align and left thin variants (e.g. Maverick 7XTD).
    """
    normalized = normalize_text(title)

    if existing_assembly_id:
        row = _one(
            """
            SELECT COALESCE((
              SELECT vehicle_variant_id FROM oem_assemblies WHERE id = %s
            ), 0) AS vehicle_variant_id
            """,
            (existing_assembly_id,),
        )
        current_variant_id = int(row["vehicle_variant_id"] or 0)
        if current_variant_id == variant_id:
            _upgrade_assembly_source_node(existing_assembly_id, source_node_id)
            return existing_assembly_id, "exists"
        if not current_variant_id:
            return ensure_assembly(variant_id, title, source_node_id), "created"

        _one(
            """
            UPDATE oem_assemblies
            SET vehicle_variant_id = %s,
                source_node_id = COALESCE(%s, source_node_id),
                title = %s,
                normalized_title = %s,
                updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (variant_id, source_node_id, title, normalized, existing_assembly_id),
        )
        return existing_assembly_id, "moved"

    existing_on_target = _one(
        """
        SELECT COALESCE((
          SELECT id FROM oem_assemblies
          WHERE vehicle_variant_id = %s
            AND (
              normalized_title = %s
              OR (%s::bigint IS NOT NULL AND source_node_id = %s)
            )
          LIMIT 1
        ), 0) AS id
        """,
        (variant_id, normalized, source_node_id, source_node_id),
    )
    if existing_on_target["id"]:
        assembly_id = int(existing_on_target["id"])
        _upgrade_assembly_source_node(assembly_id, source_node_id)
        return assembly_id, "exists"

    return ensure_assembly(variant_id, title, source_node_id), "created"


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
        _upgrade_assembly_source_node(int(existing["id"]), source_node_id)
        return int(existing["id"])
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
