"""Fix Remotors catalog data in PostgreSQL without a full re-crawl."""

from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any

from app.db import get_conn
from app.importers import writer
from app.importers.remotors_catalog import (
    HIDDEN_CANONICAL_BRANDS,
    classify_source,
    crawl_arib_for_brand,
    path_from_slug,
)
from app.normalization import normalize_text

SOURCE_CODE = "remotors_ari"


@dataclass
class FixStats:
    lynx_moved: int = 0
    atv_reclassified: int = 0
    brp_reclassified: int = 0
    model_families_merged: int = 0
    variants_moved: int = 0
    brand_aliases_removed: int = 0
    brands_hidden: int = 0
    parts_reassigned: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "lynx_moved": self.lynx_moved,
            "atv_reclassified": self.atv_reclassified,
            "brp_reclassified": self.brp_reclassified,
            "model_families_merged": self.model_families_merged,
            "variants_moved": self.variants_moved,
            "brand_aliases_removed": self.brand_aliases_removed,
            "brands_hidden": self.brands_hidden,
            "parts_reassigned": self.parts_reassigned,
        }


def _fetch_all(query: str, params: tuple[Any, ...] = (), *, conn=None) -> list[dict[str, Any]]:
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())
    with get_conn() as connection:
        with connection.cursor() as cur:
            cur.execute(query, params)
            return list(cur.fetchall())


def _fetch_one(query: str, params: tuple[Any, ...] = (), *, conn=None) -> dict[str, Any] | None:
    if conn is not None:
        with conn.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()
    with get_conn() as connection:
        with connection.cursor() as cur:
            cur.execute(query, params)
            return cur.fetchone()


def _source_id() -> int:
    return writer.source_id(SOURCE_CODE)


def diagnose() -> dict[str, Any]:
    sid = _source_id()
    return {
        "lynx_in_motorcycle": _fetch_all(
            """
            SELECT mf.id, mf.name, COUNT(vv.id) AS variant_count
            FROM oem_model_families mf
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            WHERE b.normalized_name = 'lynx' AND vt.code = 'motorcycle'
            GROUP BY mf.id, mf.name
            ORDER BY mf.name
            """
        ),
        "likely_misclassified_atv": _fetch_all(
            """
            SELECT b.name AS brand, mf.id, mf.name, vt.code AS vehicle_type, COUNT(vv.id) AS variants
            FROM oem_model_families mf
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            WHERE vt.code = 'atv'
              AND (
                b.normalized_name IN ('lynx', 'ski-doo')
                OR mf.name ILIKE %s
                OR EXISTS (
                  SELECT 1 FROM oem_vehicle_variants vv2
                  WHERE vv2.model_family_id = mf.id
                    AND vv2.source_designation ~* '(skandic|ski[- ]doo|lynx|snowmobile)'
                )
              )
            GROUP BY b.name, mf.id, mf.name, vt.code
            ORDER BY b.name, mf.name
            """,
            ("%skandic%",),
        ),
        "brp_family_brands": _fetch_all(
            """
            SELECT b.normalized_name, b.name, vt.code, COUNT(DISTINCT mf.id) AS models, COUNT(DISTINCT a.id) AS assemblies
            FROM oem_brands b
            JOIN oem_model_families mf ON mf.brand_id = b.id
            JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
            LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
            LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
            WHERE b.normalized_name IN ('brp', 'sea-doo', 'ski-doo', 'can-am', 'lynx')
            GROUP BY b.normalized_name, b.name, vt.code
            ORDER BY b.name, vt.code
            """
        ),
        "aria_slug_collisions": _fetch_all(
            """
            SELECT sn.aria, sn.arib, COUNT(*) AS node_count,
                   COUNT(DISTINCT sn.slug) AS slug_count,
                   array_agg(DISTINCT LEFT(COALESCE(sn.slug, ''), 100)) AS sample_slugs
            FROM oem_source_nodes sn
            WHERE sn.source_id = %s
              AND sn.node_type = 'assembly'
              AND sn.aria IS NOT NULL
            GROUP BY sn.aria, sn.arib
            HAVING COUNT(DISTINCT sn.slug) > 1
            ORDER BY node_count DESC
            LIMIT 200
            """,
            (sid,),
        ),
        "thin_variants": list_thin_variants(limit=200),
        "duplicate_variants": list_duplicate_variant_groups(limit=200),
        "recrawl_plan": build_recrawl_plan(limit=200),
    }


def list_thin_variants(*, limit: int = 500, min_reference_assemblies: int = 5, ratio: float = 0.3) -> list[dict[str, Any]]:
    rows = _fetch_all(
        """
        WITH variant_counts AS (
          SELECT
            vv.id AS variant_id,
            vv.model_family_id,
            vv.year_from,
            vv.source_designation,
            vv.variant_section,
            mf.name AS model_name,
            mf.normalized_name AS model_normalized,
            b.name AS brand_name,
            b.normalized_name AS brand_normalized,
            vt.code AS vehicle_type,
            sn.arib,
            COUNT(a.id) AS assembly_count
          FROM oem_vehicle_variants vv
          JOIN oem_model_families mf ON mf.id = vv.model_family_id
          JOIN oem_brands b ON b.id = mf.brand_id
          JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
          LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
          LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
          LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
          GROUP BY vv.id, vv.model_family_id, vv.year_from, vv.source_designation, vv.variant_section,
                   mf.name, mf.normalized_name, b.name, b.normalized_name, vt.code, sn.arib
        ),
        model_stats AS (
          SELECT model_family_id, MAX(assembly_count) AS max_assemblies
          FROM variant_counts
          GROUP BY model_family_id
        )
        SELECT vc.*
        FROM variant_counts vc
        JOIN model_stats ms ON ms.model_family_id = vc.model_family_id
        WHERE ms.max_assemblies >= %s
          AND vc.assembly_count < GREATEST(1, (ms.max_assemblies * %s)::int)
        ORDER BY ms.max_assemblies DESC, vc.assembly_count ASC, vc.brand_name, vc.model_name, vc.year_from
        LIMIT %s
        """,
        (min_reference_assemblies, ratio, limit),
    )
    for row in rows:
        row["crawl_arib"] = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
    return rows


def build_recrawl_plan(*, limit: int = 500) -> list[dict[str, Any]]:
    thin = list_thin_variants(limit=limit)
    grouped: dict[tuple[str, int | None], dict[str, Any]] = {}
    for row in thin:
        arib = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
        year = row.get("year_from")
        key = (arib, year)
        bucket = grouped.setdefault(
            key,
            {
                "arib": arib,
                "year": year,
                "brand_name": row.get("brand_name"),
                "thin_variant_count": 0,
                "sample_model": row.get("model_name"),
                "sample_assemblies": row.get("assembly_count"),
            },
        )
        bucket["thin_variant_count"] += 1
    return sorted(grouped.values(), key=lambda item: (item["arib"], item["year"] or 0))


def build_variant_recrawl_plan(*, limit: int = 500) -> list[dict[str, Any]]:
    """One repair target per thin variant (deduped by source-node root)."""
    thin = list_thin_variants(limit=limit)
    variant_ids = [int(row["variant_id"]) for row in thin if row.get("variant_id")]
    roots = variant_repair_roots(variant_ids)
    by_variant = {root["variant_id"]: root for root in roots}
    plan: list[dict[str, Any]] = []
    for row in thin:
        root = by_variant.get(int(row["variant_id"]))
        if not root:
            continue
        plan.append(
            {
                "variant_id": row["variant_id"],
                "model_family_id": row.get("model_family_id"),
                "year_from": row.get("year_from"),
                "brand_name": row.get("brand_name"),
                "model_name": row.get("model_name"),
                "assembly_count": row.get("assembly_count"),
                "arib": root.get("arib"),
                "path": " / ".join(root.get("path") or []),
            }
        )
    return plan


def _source_node_chain(source_node_id: int, *, conn=None) -> list[dict[str, Any]]:
    return _fetch_all(
        """
        WITH RECURSIVE chain AS (
          SELECT id, parent_id, title, arib, aria, slug, 0 AS depth
          FROM oem_source_nodes
          WHERE id = %s
          UNION ALL
          SELECT sn.id, sn.parent_id, sn.title, sn.arib, sn.aria, sn.slug, chain.depth + 1
          FROM oem_source_nodes sn
          JOIN chain ON sn.id = chain.parent_id
        )
        SELECT id, parent_id, title, arib, aria, slug, depth
        FROM chain
        ORDER BY depth ASC
        """,
        (source_node_id,),
        conn=conn,
    )


def variant_repair_roots(variant_ids: list[int]) -> list[dict[str, Any]]:
    """Map variant IDs to ARI subtree roots for targeted re-crawl."""
    roots: list[dict[str, Any]] = []
    seen: set[tuple[str, str | None]] = set()
    for variant_id in variant_ids:
        row = _fetch_one(
            """
            SELECT sn.id, sn.arib, sn.aria, sn.slug, sn.title, sn.parent_id,
                   b.normalized_name AS brand_normalized
            FROM oem_vehicle_variants vv
            JOIN oem_model_families mf ON mf.id = vv.model_family_id
            JOIN oem_brands b ON b.id = mf.brand_id
            JOIN oem_source_node_links snl
              ON snl.entity_type = 'vehicle_variant'
             AND snl.entity_id = vv.id
            JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
            WHERE vv.id = %s
              AND sn.node_type <> 'assembly'
            ORDER BY LENGTH(COALESCE(sn.slug, '')) DESC, sn.id DESC
            LIMIT 1
            """,
            (variant_id,),
        )
        if not row or not row.get("aria"):
            assembly_parent = _fetch_one(
                """
                SELECT sn.id, sn.arib, sn.aria, sn.slug, sn.title, sn.parent_id
                FROM oem_assemblies a
                JOIN oem_source_nodes sn ON sn.id = a.source_node_id
                WHERE a.vehicle_variant_id = %s
                ORDER BY a.id ASC
                LIMIT 1
                """,
                (variant_id,),
            )
            if assembly_parent and assembly_parent.get("parent_id"):
                row = _fetch_one(
                    """
                    SELECT id, arib, aria, slug, title, parent_id
                    FROM oem_source_nodes
                    WHERE id = %s
                    """,
                    (assembly_parent["parent_id"],),
                )
        if not row or not row.get("aria"):
            continue
        dedupe_key = (row["arib"], row["aria"])
        if dedupe_key in seen:
            continue
        seen.add(dedupe_key)
        chain = _source_node_chain(row["id"])
        if not chain:
            continue
        path = [item["title"] for item in reversed(chain) if item.get("title")]
        crawl_arib = crawl_arib_for_brand(row.get("brand_normalized"), row.get("arib"))
        roots.append(
            {
                "variant_id": variant_id,
                "title": row["title"],
                "arib": crawl_arib,
                "legacy_arib": row["arib"],
                "aria": row["aria"],
                "slug": row.get("slug"),
                "parent_id": row["parent_id"],
                "depth": len(chain) - 1,
                "path": path,
            }
        )
    return roots

def _category_titles_for_model_family(model_family_id: int, *, conn=None) -> str:
    """Walk source-node parents — picks up Remotors folders like 'Can-Am ATV'."""
    anchor_sql = """
        WITH RECURSIVE chain AS (
          SELECT id, parent_id, title, depth FROM (
            SELECT sn.id, sn.parent_id, sn.title, 0 AS depth
            FROM oem_source_nodes sn
            JOIN oem_source_node_links snl
              ON snl.source_node_id = sn.id
             AND snl.entity_type = 'vehicle_variant'
            JOIN oem_vehicle_variants vv ON vv.id = snl.entity_id
            WHERE vv.model_family_id = %s
            LIMIT 1
          ) seed
          UNION ALL
          SELECT sn.id, sn.parent_id, sn.title, chain.depth + 1
          FROM oem_source_nodes sn
          JOIN chain ON sn.id = chain.parent_id
        )
        SELECT title FROM chain ORDER BY depth DESC
    """
    rows = _fetch_all(anchor_sql, (model_family_id,), conn=conn)
    if not rows:
        rows = _fetch_all(
            """
            WITH RECURSIVE chain AS (
              SELECT id, parent_id, title, depth FROM (
                SELECT sn.id, sn.parent_id, sn.title, 0 AS depth
                FROM oem_source_nodes sn
                JOIN oem_assemblies a ON a.source_node_id = sn.id
                JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
                WHERE vv.model_family_id = %s
                LIMIT 1
              ) seed
              UNION ALL
              SELECT sn.id, sn.parent_id, sn.title, chain.depth + 1
              FROM oem_source_nodes sn
              JOIN chain ON sn.id = chain.parent_id
            )
            SELECT title FROM chain ORDER BY depth DESC
            """,
            (model_family_id,),
            conn=conn,
        )
    return " / ".join(row["title"] for row in rows if row.get("title"))


def _reassign_model_family_target(
    *,
    source_id: int,
    normalized_name: str,
    target_brand_id: int,
    target_vt_id: int,
    dry_run: bool,
    conn,
) -> tuple[str, int]:
    """Move or merge model_family onto canonical (brand, vehicle_type). Returns action, variants_moved."""
    conflict = _fetch_one(
        """
        SELECT id FROM oem_model_families
        WHERE vehicle_type_id = %s
          AND brand_id = %s
          AND normalized_name = %s
          AND id <> %s
        LIMIT 1
        """,
        (target_vt_id, target_brand_id, normalized_name, source_id),
        conn=conn,
    )
    if conflict:
        if dry_run:
            row = _fetch_one(
                "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
                (source_id,),
                conn=conn,
            )
            return "would_merge", int(row["cnt"] if row else 0)
        moved = _merge_model_family(source_id, conflict["id"], dry_run=dry_run, conn=conn)
        _delete_empty_model_family(source_id, dry_run=dry_run, conn=conn)
        return "merged", moved
    if dry_run:
        return "would_update", 0
    _fetch_one(
        """
        UPDATE oem_model_families
        SET brand_id = %s, vehicle_type_id = %s, updated_at = now()
        WHERE id = %s
        RETURNING id
        """,
        (target_brand_id, target_vt_id, source_id),
        conn=conn,
    )
    return "updated", 0


def _sample_path_for_model_family(model_family_id: int, *, conn=None) -> tuple[str | None, list[str], str]:
    row = _fetch_one(
        """
        SELECT sn.arib, sn.slug, sn.title, mf.name AS model_name,
               (SELECT vv.source_designation FROM oem_vehicle_variants vv
                WHERE vv.model_family_id = mf.id AND vv.source_designation IS NOT NULL
                LIMIT 1) AS source_designation
        FROM oem_model_families mf
        LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
        LEFT JOIN oem_source_node_links snl ON snl.entity_type = 'vehicle_variant' AND snl.entity_id = vv.id
        LEFT JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
        WHERE mf.id = %s
        ORDER BY LENGTH(COALESCE(sn.slug, '')) DESC
        LIMIT 1
        """,
        (model_family_id,),
        conn=conn,
    )
    if not row:
        row = _fetch_one(
            """
            SELECT sn.arib, sn.slug, sn.title, mf.name AS model_name, vv.source_designation
            FROM oem_source_nodes sn
            JOIN oem_assemblies a ON a.source_node_id = sn.id
            JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
            JOIN oem_model_families mf ON mf.id = vv.model_family_id
            WHERE mf.id = %s
              AND sn.slug IS NOT NULL
            ORDER BY LENGTH(sn.slug) DESC
            LIMIT 1
            """,
            (model_family_id,),
            conn=conn,
        )
    category_titles = _category_titles_for_model_family(model_family_id, conn=conn)
    if not row:
        return None, [], category_titles
    slug_path = path_from_slug(row.get("slug"))
    extra = " ".join(
        part
        for part in (category_titles, row.get("model_name"), row.get("source_designation"), row.get("title"))
        if part
    )
    return row.get("arib"), slug_path, extra


def _ensure_target_model_family(
    *,
    brand_name: str,
    vehicle_type: str,
    model_name: str,
    dry_run: bool,
) -> int | None:
    brand_id = writer.ensure_brand(brand_name) if not dry_run else _brand_id_by_name(brand_name)
    if brand_id is None:
        return None
    if dry_run:
        vt_id = _vehicle_type_id(vehicle_type)
        existing = _fetch_one(
            """
            SELECT id FROM oem_model_families
            WHERE vehicle_type_id = %s AND brand_id = %s AND normalized_name = %s
            """,
            (vt_id, brand_id, normalize_text(model_name)),
        )
        return existing["id"] if existing else -1
    return writer.ensure_model_family(vehicle_type, brand_id, model_name)


def _brand_id_by_name(name: str) -> int | None:
    row = _fetch_one("SELECT id FROM oem_brands WHERE normalized_name = %s", (normalize_text(name),))
    return row["id"] if row else None


def _vehicle_type_id(code: str) -> int:
    row = _fetch_one("SELECT id FROM oem_vehicle_types WHERE code = %s", (code,))
    if not row:
        raise RuntimeError(f"Unknown vehicle type: {code}")
    return row["id"]


def _move_variants(source_model_id: int, target_model_id: int, *, dry_run: bool, conn=None) -> int:
    if source_model_id == target_model_id:
        return 0
    if dry_run:
        row = _fetch_one(
            "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
            (source_model_id,),
            conn=conn,
        )
        return int(row["cnt"] if row else 0)
    row = _fetch_one(
        """
        WITH moved AS (
          UPDATE oem_vehicle_variants
          SET model_family_id = %s, updated_at = now()
          WHERE model_family_id = %s
          RETURNING id
        )
        SELECT COUNT(*) AS cnt FROM moved
        """,
        (target_model_id, source_model_id),
        conn=conn,
    )
    return int(row["cnt"] if row else 0)


def _merge_model_family(source_id: int, target_id: int, *, dry_run: bool, conn=None) -> int:
    return _move_variants(source_id, target_id, dry_run=dry_run, conn=conn)


def _delete_empty_model_family(model_family_id: int, *, dry_run: bool, conn=None) -> None:
    row = _fetch_one(
        "SELECT COUNT(*) AS cnt FROM oem_vehicle_variants WHERE model_family_id = %s",
        (model_family_id,),
        conn=conn,
    )
    if row and int(row["cnt"]) > 0:
        return
    if dry_run:
        return
    _fetch_one("DELETE FROM oem_model_aliases WHERE model_family_id = %s RETURNING id", (model_family_id,), conn=conn)
    _fetch_one("DELETE FROM oem_model_families WHERE id = %s RETURNING id", (model_family_id,), conn=conn)


# Deprecated ARI umbrella brands: reassign parts, never DELETE (FK from oem_parts).
HIDDEN_BRAND_PART_TARGETS: dict[str, str] = {
    "brp_sea": "sea-doo",
    "brp_ski": "ski-doo",
}


def _reassign_parts_for_hidden_brands(*, dry_run: bool, conn) -> int:
    total = 0
    for src_name, tgt_name in HIDDEN_BRAND_PART_TARGETS.items():
        if dry_run:
            row = _fetch_one(
                """
                SELECT COUNT(*) AS cnt
                FROM oem_parts p
                JOIN oem_brands src ON src.id = p.brand_id
                WHERE src.normalized_name = %s
                """,
                (src_name,),
                conn=conn,
            )
            total += int(row["cnt"] if row else 0)
            continue
        row = _fetch_one(
            """
            WITH updated AS (
              UPDATE oem_parts p
              SET brand_id = tgt.id, updated_at = now()
              FROM oem_brands src
              JOIN oem_brands tgt ON tgt.normalized_name = %s
              WHERE p.brand_id = src.id
                AND src.normalized_name = %s
              RETURNING p.id
            )
            SELECT COUNT(*) AS cnt FROM updated
            """,
            (tgt_name, src_name),
            conn=conn,
        )
        total += int(row["cnt"] if row else 0)
    return total


def _count_hidden_brands_without_models(*, conn) -> int:
    row = _fetch_one(
        """
        SELECT COUNT(*) AS cnt
        FROM oem_brands b
        WHERE b.normalized_name = ANY(%s)
          AND NOT EXISTS (
            SELECT 1 FROM oem_model_families mf WHERE mf.brand_id = b.id
          )
        """,
        (list(HIDDEN_CANONICAL_BRANDS),),
        conn=conn,
    )
    return int(row["cnt"] if row else 0)


def apply_fixes(*, dry_run: bool = True) -> dict[str, Any]:
    stats = FixStats()
    sid = _source_id()

    with get_conn() as conn:
        try:
            stats = _apply_fixes_body(stats, sid, dry_run=dry_run, conn=conn)
            stats.parts_reassigned = _reassign_parts_for_hidden_brands(dry_run=dry_run, conn=conn)
            stats.brands_hidden = _count_hidden_brands_without_models(conn=conn)
            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    return {"dry_run": dry_run, "stats": stats.as_dict(), "message": _fix_summary(stats, dry_run=dry_run)}


def _fix_summary(stats: FixStats, *, dry_run: bool) -> str:
    mode = "dry-run" if dry_run else "apply"
    changed = (
        stats.lynx_moved
        + stats.atv_reclassified
        + stats.brp_reclassified
        + stats.model_families_merged
        + stats.variants_moved
        + stats.brand_aliases_removed
        + stats.parts_reassigned
    )
    if changed == 0:
        return (
            f"{mode}: nothing to change — catalog already fixed. "
            f"brands_hidden={stats.brands_hidden}: umbrella BRP has no models "
            f"(hidden in UI, row kept for oem_parts FK)."
        )
    return f"{mode}: {changed} change groups; see stats."


def _apply_fixes_body(stats: FixStats, sid: int, *, dry_run: bool, conn) -> FixStats:
    path_cache: dict[int, tuple[str | None, list[str], str]] = {}

    def sample_path(model_family_id: int) -> tuple[str | None, list[str], str]:
        if model_family_id not in path_cache:
            path_cache[model_family_id] = _sample_path_for_model_family(model_family_id, conn=conn)
        return path_cache[model_family_id]

    lynx_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, mf.normalized_name
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE b.normalized_name = 'lynx' AND vt.code = 'motorcycle'
        """,
        conn=conn,
    )
    snowmobile_id = _vehicle_type_id("snowmobile")
    lynx_brand_id = _brand_id_by_name("lynx")
    for row in lynx_rows:
        action, moved = _reassign_model_family_target(
            source_id=row["id"],
            normalized_name=row["normalized_name"],
            target_brand_id=lynx_brand_id,
            target_vt_id=snowmobile_id,
            dry_run=dry_run,
            conn=conn,
        )
        if action in ("merged", "would_merge"):
            stats.model_families_merged += 1
            stats.variants_moved += moved
        if action in ("merged", "would_merge", "would_update", "updated"):
            stats.lynx_moved += 1

    atv_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, b.normalized_name AS brand_normalized
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE vt.code = 'atv'
          AND (
            b.normalized_name IN ('lynx', 'ski-doo')
            OR mf.name ILIKE %s
            OR EXISTS (
              SELECT 1 FROM oem_vehicle_variants vv
              WHERE vv.model_family_id = mf.id
                AND vv.source_designation ~* '(skandic|ski[- ]doo|lynx|snowmobile)'
            )
          )
        """,
        ("%skandic%",),
        conn=conn,
    )
    for row in atv_rows:
        arib, path, extra = sample_path(row["id"])
        _, vehicle_type = classify_source(arib or "LNX", path, extra_text=extra)
        if vehicle_type == "atv":
            vehicle_type = "snowmobile"
        if dry_run:
            stats.atv_reclassified += 1
            continue
        _fetch_one(
            """
            UPDATE oem_model_families
            SET vehicle_type_id = %s, updated_at = now()
            WHERE id = %s
            RETURNING id
            """,
            (_vehicle_type_id(vehicle_type), row["id"]),
            conn=conn,
        )
        stats.atv_reclassified += 1

    brp_rows = _fetch_all(
        """
        SELECT mf.id, mf.name, mf.normalized_name, b.normalized_name AS brand_normalized, vt.code AS current_vehicle_type
        FROM oem_model_families mf
        JOIN oem_brands b ON b.id = mf.brand_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        WHERE b.normalized_name IN ('brp', 'brp_sea', 'brp_ski', 'sea-doo', 'ski-doo', 'can-am')
        ORDER BY CASE WHEN b.normalized_name = 'brp' THEN 0 ELSE 1 END, mf.id
        """,
        conn=conn,
    )
    if brp_rows:
        print(f"[fix-remotors] Checking {len(brp_rows)} BRP-family models...", flush=True)
    for index, row in enumerate(brp_rows, start=1):
        arib, path, extra = sample_path(row["id"])
        if not arib:
            arib = {"sea-doo": "BRP_SEA", "brp_sea": "BRP_SEA", "ski-doo": "BRP_SKI", "brp_ski": "BRP_SKI", "can-am": "BRP", "brp": "BRP"}.get(
                row["brand_normalized"], "BRP"
            )
        brand_name, vehicle_type = classify_source(arib, path, extra_text=f"{row['name']} {extra}")
        if normalize_text(brand_name) == row["brand_normalized"] and vehicle_type == row["current_vehicle_type"]:
            continue
        if stats.brp_reclassified == 0 or stats.brp_reclassified % 100 == 0:
            print(f"[fix-remotors] BRP updates in progress ({stats.brp_reclassified} changed so far)", flush=True)
        target_brand_id = writer.ensure_brand(brand_name) if not dry_run else _brand_id_by_name(brand_name)
        if target_brand_id is None and dry_run:
            target_brand_id = -1
        target_vt_id = _vehicle_type_id(vehicle_type)
        action, moved = _reassign_model_family_target(
            source_id=row["id"],
            normalized_name=row["normalized_name"],
            target_brand_id=target_brand_id,
            target_vt_id=target_vt_id,
            dry_run=dry_run,
            conn=conn,
        )
        if action in ("merged", "would_merge"):
            stats.model_families_merged += 1
            stats.variants_moved += moved
        if action in ("merged", "would_merge", "would_update", "updated"):
            stats.brp_reclassified += 1

    alias_rows = _fetch_all(
        """
        SELECT ba.id, ba.alias
        FROM oem_brand_aliases ba
        WHERE ba.source_id = %s
          AND ba.normalized_alias IN ('brp', 'brp_sea', 'brp_ski')
        """,
        (sid,),
    )
    for row in alias_rows:
        if dry_run:
            stats.brand_aliases_removed += 1
            continue
        _fetch_one("DELETE FROM oem_brand_aliases WHERE id = %s RETURNING id", (row["id"],), conn=conn)
        stats.brand_aliases_removed += 1

    return stats


def cleanup_orphans(*, dry_run: bool = True) -> dict[str, Any]:
    sid = _source_id()
    legacy_nodes = _fetch_all(
        """
        SELECT sn.id, sn.external_id
        FROM oem_source_nodes sn
        WHERE sn.source_id = %s
          AND sn.node_type = 'assembly'
          AND sn.external_id IS NOT NULL
          AND sn.slug IS NOT NULL
          AND sn.external_id = sn.arib || ':' || sn.aria
          AND NOT EXISTS (
            SELECT 1 FROM oem_source_node_links snl
            WHERE snl.source_node_id = sn.id AND snl.entity_type = 'assembly'
          )
        LIMIT 5000
        """,
        (sid,),
    )
    empty_assemblies = _fetch_all(
        """
        SELECT a.id
        FROM oem_assemblies a
        LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
        LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
        WHERE d.id IS NULL AND ap.id IS NULL
        LIMIT 5000
        """
    )
    deleted_nodes = 0
    deleted_assemblies = 0
    if not dry_run:
        for row in legacy_nodes:
            _fetch_one("DELETE FROM oem_source_nodes WHERE id = %s RETURNING id", (row["id"],))
            deleted_nodes += 1
        for row in empty_assemblies:
            _fetch_one("DELETE FROM oem_assemblies WHERE id = %s RETURNING id", (row["id"],))
            deleted_assemblies += 1
    return {
        "dry_run": dry_run,
        "legacy_source_nodes": len(legacy_nodes),
        "empty_assemblies": len(empty_assemblies),
        "deleted_nodes": deleted_nodes,
        "deleted_assemblies": deleted_assemblies,
    }


def render_recrawl_commands(plan: list[dict[str, Any]] | None = None, *, mode: str = "variant") -> str:
    if mode == "year":
        return _render_year_recrawl_commands(plan or build_recrawl_plan())
    return _render_variant_recrawl_commands(plan or build_variant_recrawl_plan())


def _render_year_recrawl_commands(plan: list[dict[str, Any]]) -> str:
    lines = [
        "#!/bin/bash",
        "set -euo pipefail",
        "# Year-level re-crawl (KTM/LNX ok; BRP needs fixed crawler for nested years).",
        "# Uses umbrella BRP only (never BRP_SEA / BRP_SKI). Review before running.",
        "",
    ]
    if not plan:
        lines.append("# No thin variants detected.")
        return "\n".join(lines) + "\n"
    for item in plan:
        arib = item["arib"]
        year = item.get("year")
        if not year:
            continue
        lines.append(
            "docker compose exec -T oem_backend python -m app.cli crawl-remotors "
            f"--brands {arib} --year {year} --confirm-full-crawl --force"
        )
    return "\n".join(lines) + "\n"


def _render_variant_recrawl_commands(plan: list[dict[str, Any]], *, batch_size: int = 20) -> str:
    lines = [
        "#!/bin/bash",
        "set -euo pipefail",
        "# Targeted re-crawl: only thin variants from diagnose (by variant_id).",
        "# Much faster than full-year BRP crawls.",
        "",
    ]
    if not plan:
        lines.append("# No thin variants detected.")
        return "\n".join(lines) + "\n"
    variant_ids = [str(item["variant_id"]) for item in plan if item.get("variant_id")]
    for index in range(0, len(variant_ids), batch_size):
        batch = ",".join(variant_ids[index : index + batch_size])
        lines.append(
            "docker compose exec -T oem_backend python -m app.cli recrawl-remotors-variants "
            f"--variant-ids {batch} --force"
        )
    return "\n".join(lines) + "\n"


@dataclass
class DuplicateVariantFixStats:
    groups_merged: int = 0
    variants_removed: int = 0
    assemblies_moved: int = 0
    assemblies_merged: int = 0
    source_links_moved: int = 0

    def as_dict(self) -> dict[str, int]:
        return {
            "groups_merged": self.groups_merged,
            "variants_removed": self.variants_removed,
            "assemblies_moved": self.assemblies_moved,
            "assemblies_merged": self.assemblies_merged,
            "source_links_moved": self.source_links_moved,
        }


def _variant_identity_key(row: dict[str, Any]) -> tuple[Any, ...]:
    """Identity as shown in OEM UI (title + subtitle + section)."""
    return (
        int(row["model_family_id"]),
        row.get("year_from"),
        row.get("year_to"),
        normalize_text(row.get("market_name") or ""),
        normalize_text(row.get("source_designation") or ""),
        normalize_text(row.get("variant_section") or ""),
    )


def list_duplicate_variant_groups(*, limit: int = 500) -> list[dict[str, Any]]:
    rows = _fetch_all(
        """
        SELECT
          vv.id AS variant_id,
          vv.model_family_id,
          vv.year_from,
          vv.year_to,
          vv.market_name,
          vv.source_designation,
          vv.variant_section,
          vv.model_code,
          vv.color_code,
          mf.name AS model_name,
          b.name AS brand_name,
          COUNT(a.id) AS assembly_count
        FROM oem_vehicle_variants vv
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_brands b ON b.id = mf.brand_id
        LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
        GROUP BY
          vv.id, vv.model_family_id, vv.year_from, vv.year_to, vv.market_name,
          vv.source_designation, vv.variant_section, vv.model_code, vv.color_code,
          mf.name, b.name
        ORDER BY mf.name, vv.year_from, vv.id
        """
    )
    buckets: dict[tuple[Any, ...], list[dict[str, Any]]] = {}
    for row in rows:
        buckets.setdefault(_variant_identity_key(row), []).append(row)

    groups: list[dict[str, Any]] = []
    for items in buckets.values():
        if len(items) < 2:
            continue
        items.sort(key=lambda item: (-int(item["assembly_count"]), int(item["variant_id"])))
        groups.append(
            {
                "model_family_id": items[0]["model_family_id"],
                "year_from": items[0]["year_from"],
                "model_name": items[0]["model_name"],
                "brand_name": items[0]["brand_name"],
                "market_name": items[0]["market_name"],
                "source_designation": items[0]["source_designation"],
                "variant_section": items[0]["variant_section"],
                "variant_count": len(items),
                "variants": [
                    {
                        "variant_id": int(item["variant_id"]),
                        "assembly_count": int(item["assembly_count"]),
                    }
                    for item in items
                ],
            }
        )
    groups.sort(key=lambda group: (-group["variant_count"], group["model_name"] or ""))
    return groups[:limit]


def _find_matching_assembly(
    *,
    target_variant_id: int,
    normalized_title: str,
    source_node_id: int | None,
    conn,
) -> dict[str, Any] | None:
    return _fetch_one(
        """
        SELECT a.id
        FROM oem_assemblies a
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE a.vehicle_variant_id = %s
          AND (
            a.normalized_title = %s
            OR (
              %s IS NOT NULL
              AND a.source_node_id IS NOT NULL
              AND (
                a.source_node_id = %s
                OR EXISTS (
                  SELECT 1
                  FROM oem_source_nodes sn_src
                  WHERE sn_src.id = %s
                    AND sn.external_id IS NOT NULL
                    AND sn_src.external_id = sn.external_id
                )
              )
            )
          )
        ORDER BY a.id ASC
        LIMIT 1
        """,
        (target_variant_id, normalized_title, source_node_id, source_node_id, source_node_id),
        conn=conn,
    )


def _reassign_assembly_children(source_asm_id: int, target_asm_id: int, *, conn) -> None:
    _fetch_one(
        """
        UPDATE oem_assembly_parts
        SET assembly_id = %s, updated_at = now()
        WHERE assembly_id = %s
        RETURNING id
        """,
        (target_asm_id, source_asm_id),
        conn=conn,
    )
    _fetch_one(
        """
        UPDATE oem_diagrams
        SET assembly_id = %s, updated_at = now()
        WHERE assembly_id = %s
        RETURNING id
        """,
        (target_asm_id, source_asm_id),
        conn=conn,
    )


def _merge_variant_into(
    source_variant_id: int,
    target_variant_id: int,
    *,
    dry_run: bool,
    conn,
) -> DuplicateVariantFixStats:
    stats = DuplicateVariantFixStats()
    if source_variant_id == target_variant_id:
        return stats

    assemblies = _fetch_all(
        """
        SELECT id, normalized_title, source_node_id
        FROM oem_assemblies
        WHERE vehicle_variant_id = %s
        ORDER BY id
        """,
        (source_variant_id,),
        conn=conn,
    )
    for assembly in assemblies:
        match = _find_matching_assembly(
            target_variant_id=target_variant_id,
            normalized_title=assembly["normalized_title"],
            source_node_id=assembly.get("source_node_id"),
            conn=conn,
        )
        if match:
            if not dry_run:
                _reassign_assembly_children(int(assembly["id"]), int(match["id"]), conn=conn)
                _fetch_one("DELETE FROM oem_assemblies WHERE id = %s RETURNING id", (assembly["id"],), conn=conn)
            stats.assemblies_merged += 1
        elif dry_run:
            stats.assemblies_moved += 1
        else:
            _fetch_one(
                """
                UPDATE oem_assemblies
                SET vehicle_variant_id = %s, updated_at = now()
                WHERE id = %s
                RETURNING id
                """,
                (target_variant_id, assembly["id"]),
                conn=conn,
            )
            stats.assemblies_moved += 1

    if dry_run:
        stats.source_links_moved += int(
            (
                _fetch_one(
                    """
                    SELECT COUNT(*) AS cnt
                    FROM oem_source_node_links
                    WHERE entity_type = 'vehicle_variant' AND entity_id = %s
                    """,
                    (source_variant_id,),
                    conn=conn,
                )
                or {}
            ).get("cnt", 0)
        )
        return stats

    moved_links = _fetch_one(
        """
        WITH moved AS (
          UPDATE oem_source_node_links snl
          SET entity_id = %s
          WHERE entity_type = 'vehicle_variant'
            AND entity_id = %s
            AND NOT EXISTS (
              SELECT 1
              FROM oem_source_node_links existing
              WHERE existing.source_node_id = snl.source_node_id
                AND existing.entity_type = 'vehicle_variant'
                AND existing.entity_id = %s
            )
          RETURNING id
        )
        SELECT COUNT(*) AS cnt FROM moved
        """,
        (target_variant_id, source_variant_id, target_variant_id),
        conn=conn,
    )
    stats.source_links_moved += int(moved_links["cnt"] if moved_links else 0)
    _fetch_one(
        "DELETE FROM oem_source_node_links WHERE entity_type = 'vehicle_variant' AND entity_id = %s RETURNING id",
        (source_variant_id,),
        conn=conn,
    )
    _fetch_one("DELETE FROM oem_vehicle_variants WHERE id = %s RETURNING id", (source_variant_id,), conn=conn)
    return stats


def apply_duplicate_variant_fixes(*, dry_run: bool = True, limit: int = 10000) -> dict[str, Any]:
    stats = DuplicateVariantFixStats()
    groups = list_duplicate_variant_groups(limit=limit)
    with get_conn() as conn:
        try:
            for group in groups:
                variant_ids = [item["variant_id"] for item in group["variants"]]
                if len(variant_ids) < 2:
                    continue
                target_id = variant_ids[0]
                for source_id in variant_ids[1:]:
                    merged = _merge_variant_into(source_id, target_id, dry_run=dry_run, conn=conn)
                    stats.assemblies_moved += merged.assemblies_moved
                    stats.assemblies_merged += merged.assemblies_merged
                    stats.source_links_moved += merged.source_links_moved
                    stats.variants_removed += 1
                stats.groups_merged += 1
            if not dry_run:
                conn.commit()
            else:
                conn.rollback()
        except Exception:
            conn.rollback()
            raise

    mode = "dry-run" if dry_run else "apply"
    changed = (
        stats.groups_merged
        + stats.variants_removed
        + stats.assemblies_moved
        + stats.assemblies_merged
    )
    message = (
        f"{mode}: merged {stats.groups_merged} duplicate variant groups, "
        f"removed {stats.variants_removed} variants, "
        f"moved {stats.assemblies_moved} assemblies, "
        f"deduped {stats.assemblies_merged} assemblies."
        if changed
        else f"{mode}: no duplicate variants to merge."
    )
    return {"dry_run": dry_run, "groups_found": len(groups), "stats": stats.as_dict(), "message": message}


