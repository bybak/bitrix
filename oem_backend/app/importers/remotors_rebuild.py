"""Rebuild PostgreSQL Remotors catalog from SQLite snapshot.

Preserves parsed assembly contents (parts, diagrams, hotspots) in temp tables,
rebuilds brand/model/variant/assembly structure from snapshot, then restores contents.
"""

from __future__ import annotations

import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.db import get_conn
from app.importers import writer
from app.importers.remotors import SOURCE_CODE
from app.importers.remotors_catalog import (
    HIDDEN_CANONICAL_BRANDS,
    assembly_external_id,
    assembly_match_token,
    crawl_arib_for_brand,
    is_excluded_catalog_brand_type,
    snapshot_variant_key,
)
from app.importers.remotors_snapshot import open_snapshot
from app.normalization import normalize_text

REBUILD_PREFIX = "_rebuild_"
BATCH_SIZE = 2000
RESTORE_ASSEMBLY_BATCH = 1500


def _log(message: str) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, file=sys.stdout, flush=True)


def _hidden_brands() -> tuple[str, ...]:
    return tuple(sorted(HIDDEN_CANONICAL_BRANDS))


def _table(name: str) -> str:
    return f"{REBUILD_PREFIX}{name}"


def _exec(query: str, params: tuple[Any, ...] = (), *, conn) -> None:
    with conn.cursor() as cur:
        cur.execute(query, params)


def _fetch_one(query: str, params: tuple[Any, ...] = (), *, conn) -> dict[str, Any] | None:
    with conn.cursor() as cur:
        cur.execute(query, params)
        return cur.fetchone()


def _fetch_all(query: str, params: tuple[Any, ...] = (), *, conn) -> list[dict[str, Any]]:
    with conn.cursor() as cur:
        cur.execute(query, params)
        return list(cur.fetchall())


def _exec_rowcount(query: str, params: tuple[Any, ...] = (), *, conn) -> int:
    with conn.cursor() as cur:
        cur.execute(query, params)
        return int(cur.rowcount or 0)


def _variant_key_from_row(row: dict[str, Any]) -> str:
    return snapshot_variant_key(
        vehicle_type=row["vehicle_type"],
        market_name=row.get("market_name"),
        source_designation=row.get("source_designation"),
        year_from=row.get("year_from"),
        variant_section=row.get("variant_section"),
    )


def _match_token_from_node(row: dict[str, Any]) -> str | None:
    return assembly_match_token(
        arib=row.get("arib"),
        aria=row.get("aria"),
        slug=row.get("slug"),
        compare_key=row.get("external_id"),
    )


def rebuild_tables_exist(*, conn) -> bool:
    row = _fetch_one(
        """
        SELECT 1 AS ok
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = %s
        """,
        (_table("assemblies"),),
        conn=conn,
    )
    return row is not None


def _executemany(query: str, rows: list[tuple[Any, ...]], *, conn) -> None:
    if not rows:
        return
    with conn.cursor() as cur:
        cur.executemany(query, rows)


def export_parsed_contents(*, conn) -> dict[str, int]:
    """Phase 1: copy trusted assembly-level data into temp tables keyed by variant_key + match_token."""
    _log("rebuild export start — building temp backup tables")
    counts: dict[str, int] = {}

    for suffix in ("assemblies", "diagrams", "assembly_parts", "hotspots", "meta"):
        _exec(f"DROP TABLE IF EXISTS {_table(suffix)}", conn=conn)

    _exec(
        f"""
        CREATE TABLE {_table("assemblies")} (
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL,
          title TEXT NOT NULL,
          normalized_title TEXT NOT NULL,
          sort_order INTEGER NOT NULL DEFAULT 500,
          sn_external_id TEXT,
          sn_arib TEXT,
          sn_aria TEXT,
          sn_slug TEXT,
          sn_title TEXT,
          sn_source_url TEXT,
          sn_url_path TEXT,
          old_assembly_id BIGINT NOT NULL,
          PRIMARY KEY (old_assembly_id)
        )
        """,
        conn=conn,
    )
    _exec(f"CREATE INDEX ON {_table('assemblies')} (variant_key, match_token)", conn=conn)

    _exec(
        f"""
        CREATE TABLE {_table("diagrams")} (
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL,
          original_url TEXT,
          local_path TEXT,
          public_url TEXT,
          source_image_id TEXT,
          width INTEGER,
          height INTEGER,
          mime_type TEXT,
          checksum_sha256 CHAR(64),
          sort_order INTEGER NOT NULL DEFAULT 500
        )
        """,
        conn=conn,
    )
    _exec(f"CREATE INDEX ON {_table('diagrams')} (variant_key, match_token)", conn=conn)

    _exec(
        f"""
        CREATE TABLE {_table("assembly_parts")} (
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL,
          ref TEXT,
          quantity NUMERIC(12,3),
          row_kind TEXT NOT NULL DEFAULT 'original',
          source_row_id TEXT,
          source_items_list_id TEXT,
          notes TEXT,
          raw_payload JSONB,
          part_number TEXT NOT NULL,
          normalized_part_number TEXT NOT NULL,
          manufacturer TEXT,
          part_name TEXT
        )
        """,
        conn=conn,
    )
    _exec(f"CREATE INDEX ON {_table('assembly_parts')} (variant_key, match_token)", conn=conn)

    _exec(
        f"""
        CREATE TABLE {_table("hotspots")} (
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL,
          ref TEXT,
          part_source_row_id TEXT,
          source_items_list_id TEXT,
          shape TEXT NOT NULL DEFAULT 'rect',
          raw_coords TEXT,
          x NUMERIC(12,4),
          y NUMERIC(12,4),
          width NUMERIC(12,4),
          height NUMERIC(12,4),
          polygon_json JSONB,
          raw_payload JSONB
        )
        """,
        conn=conn,
    )
    _exec(f"CREATE INDEX ON {_table('hotspots')} (variant_key, match_token)", conn=conn)

    _exec("DROP TABLE IF EXISTS tmp_rebuild_key_map", conn=conn)
    _exec(
        """
        CREATE TEMP TABLE tmp_rebuild_key_map (
          old_assembly_id BIGINT PRIMARY KEY,
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL
        ) ON COMMIT PRESERVE ROWS
        """,
        conn=conn,
    )
    _exec("CREATE INDEX ON tmp_rebuild_key_map (variant_key, match_token)", conn=conn)

    _log("rebuild export — scanning assemblies with parsed content")
    rows = _fetch_all(
        """
        SELECT
          a.id AS assembly_id,
          a.title,
          a.normalized_title,
          a.sort_order,
          vv.market_name,
          vv.source_designation,
          vv.year_from,
          vv.variant_section,
          vt.code AS vehicle_type,
          sn.external_id,
          sn.arib,
          sn.aria,
          sn.slug,
          sn.title AS sn_title,
          sn.source_url AS sn_source_url,
          sn.url_path AS sn_url_path
        FROM oem_assemblies a
        JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        JOIN oem_brands b ON b.id = mf.brand_id
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE b.normalized_name <> ALL(%s)
          AND (
            EXISTS (SELECT 1 FROM oem_assembly_parts ap WHERE ap.assembly_id = a.id)
            OR EXISTS (SELECT 1 FROM oem_diagrams d WHERE d.assembly_id = a.id)
          )
        ORDER BY a.id
        """,
        (list(_hidden_brands()),),
        conn=conn,
    )
    _log(f"rebuild export — candidate assemblies={len(rows)}")

    key_rows: list[tuple[Any, ...]] = []
    skipped = 0
    for index, row in enumerate(rows, start=1):
        match_token = _match_token_from_node(row)
        if not match_token:
            skipped += 1
            continue
        key_rows.append(
            (
                row["assembly_id"],
                _variant_key_from_row(row),
                match_token,
            )
        )
        if index == 1 or index % 50000 == 0 or index == len(rows):
            _log(f"rebuild export — key map progress {index}/{len(rows)} keyed={len(key_rows)} skipped={skipped}")

    for offset in range(0, len(key_rows), BATCH_SIZE):
        batch = key_rows[offset : offset + BATCH_SIZE]
        _executemany(
            """
            INSERT INTO tmp_rebuild_key_map (old_assembly_id, variant_key, match_token)
            VALUES (%s, %s, %s)
            ON CONFLICT (old_assembly_id) DO NOTHING
            """,
            batch,
            conn=conn,
        )
        if offset == 0 or (offset + BATCH_SIZE) >= len(key_rows):
            _log(f"rebuild export — key map inserted {min(offset + BATCH_SIZE, len(key_rows))}/{len(key_rows)}")

    _log("rebuild export — bulk copy assemblies")
    counts["assemblies"] = _exec_rowcount(
        f"""
        INSERT INTO {_table("assemblies")} (
          variant_key, match_token, title, normalized_title, sort_order,
          sn_external_id, sn_arib, sn_aria, sn_slug, sn_title, sn_source_url, sn_url_path,
          old_assembly_id
        )
        SELECT
          k.variant_key,
          k.match_token,
          a.title,
          a.normalized_title,
          a.sort_order,
          sn.external_id,
          sn.arib,
          sn.aria,
          sn.slug,
          sn.title,
          sn.source_url,
          sn.url_path,
          a.id
        FROM tmp_rebuild_key_map k
        JOIN oem_assemblies a ON a.id = k.old_assembly_id
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        """,
        conn=conn,
    )

    _log("rebuild export — bulk copy diagrams")
    counts["diagrams"] = _exec_rowcount(
        f"""
        INSERT INTO {_table("diagrams")} (
          variant_key, match_token, original_url, local_path, public_url,
          source_image_id, width, height, mime_type, checksum_sha256, sort_order
        )
        SELECT
          k.variant_key,
          k.match_token,
          d.original_url,
          d.local_path,
          d.public_url,
          d.source_image_id,
          d.width,
          d.height,
          d.mime_type,
          d.checksum_sha256,
          d.sort_order
        FROM tmp_rebuild_key_map k
        JOIN oem_diagrams d ON d.assembly_id = k.old_assembly_id
        """,
        conn=conn,
    )

    _log("rebuild export — bulk copy assembly parts")
    counts["assembly_parts"] = _exec_rowcount(
        f"""
        INSERT INTO {_table("assembly_parts")} (
          variant_key, match_token, ref, quantity, row_kind, source_row_id,
          source_items_list_id, notes, raw_payload,
          part_number, normalized_part_number, manufacturer, part_name
        )
        SELECT
          k.variant_key,
          k.match_token,
          ap.ref,
          ap.quantity,
          ap.row_kind,
          ap.source_row_id,
          ap.source_items_list_id,
          ap.notes,
          ap.raw_payload,
          p.part_number,
          p.normalized_part_number,
          p.manufacturer,
          p.name
        FROM tmp_rebuild_key_map k
        JOIN oem_assembly_parts ap ON ap.assembly_id = k.old_assembly_id
        JOIN oem_parts p ON p.id = ap.part_id
        """,
        conn=conn,
    )

    _log("rebuild export — bulk copy hotspots")
    counts["hotspots"] = _exec_rowcount(
        f"""
        INSERT INTO {_table("hotspots")} (
          variant_key, match_token, ref, part_source_row_id, source_items_list_id,
          shape, raw_coords, x, y, width, height, polygon_json, raw_payload
        )
        SELECT
          k.variant_key,
          k.match_token,
          h.ref,
          ap.source_row_id,
          h.source_items_list_id,
          h.shape,
          h.raw_coords,
          h.x,
          h.y,
          h.width,
          h.height,
          h.polygon_json,
          h.raw_payload
        FROM tmp_rebuild_key_map k
        JOIN oem_diagrams d ON d.assembly_id = k.old_assembly_id
        JOIN oem_diagram_hotspots h ON h.diagram_id = d.id
        LEFT JOIN oem_assembly_parts ap ON ap.id = h.assembly_part_id
        """,
        conn=conn,
    )

    _exec(
        f"""
        CREATE TABLE {_table("meta")} (
          key TEXT PRIMARY KEY,
          value TEXT NOT NULL
        )
        """,
        conn=conn,
    )
    _exec(
        f"INSERT INTO {_table('meta')} (key, value) VALUES ('exported_at', %s)",
        (datetime.now(timezone.utc).isoformat(),),
        conn=conn,
    )
    conn.commit()
    _log(f"rebuild export done counts={counts} skipped_no_token={skipped}")
    return counts


def truncate_catalog(*, conn) -> None:
    """Phase 2: clear catalog structure; keep oem_parts, bitrix links, sources, vehicle_types."""
    _log("rebuild truncate start — clearing catalog tables (keeping oem_parts + images on disk)")
    tables = (
        "oem_diagram_hotspots",
        "oem_source_price_snapshots",
        "oem_assembly_parts",
        "oem_diagrams",
        "oem_part_relations",
        "oem_assemblies",
        "oem_source_node_links",
        "oem_raw_snapshots",
        "oem_vehicle_variants",
        "oem_model_aliases",
        "oem_model_families",
        "oem_brand_aliases",
        "oem_source_nodes",
    )
    for table in tables:
        _log(f"rebuild truncate — {table}")
        _exec(f"TRUNCATE TABLE {table} RESTART IDENTITY CASCADE", conn=conn)

    deleted = _exec_rowcount(
        """
        DELETE FROM oem_brands b
        WHERE b.normalized_name <> ALL(%s)
          AND NOT EXISTS (SELECT 1 FROM oem_parts p WHERE p.brand_id = b.id)
        """,
        (list(_hidden_brands()),),
        conn=conn,
    )
    _log(f"rebuild truncate — removed orphan brands={deleted}")
    conn.commit()
    _log("rebuild truncate done")


def apply_schema_optimizations(*, conn) -> None:
    """Phase 3: indexes that prevent duplicate assemblies per variant/node."""
    _log("rebuild schema — applying optimizations")
    _exec(
        """
        CREATE UNIQUE INDEX IF NOT EXISTS uq_oem_assemblies_variant_source_node
        ON oem_assemblies (vehicle_variant_id, source_node_id)
        WHERE source_node_id IS NOT NULL
        """,
        conn=conn,
    )
    _exec(
        """
        CREATE INDEX IF NOT EXISTS ix_oem_source_nodes_assembly_aria
        ON oem_source_nodes (source_id, aria)
        WHERE node_type = 'assembly' AND aria IS NOT NULL AND aria <> ''
        """,
        conn=conn,
    )
    conn.commit()
    _log("rebuild schema done")


def _load_snapshot_rows(snapshot_path: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]], list[dict[str, Any]]]:
    hidden = set(_hidden_brands())
    conn = open_snapshot(snapshot_path)
    try:
        models = [
            dict(row)
            for row in conn.execute(
                """
                SELECT brand_normalized, brand_name, vehicle_type, model_key, model_name
                FROM catalog_models
                ORDER BY brand_normalized, model_key
                """
            ).fetchall()
            if row["brand_normalized"] not in hidden
            and not is_excluded_catalog_brand_type(row["brand_normalized"], row["vehicle_type"])
        ]
        variants = [
            dict(row)
            for row in conn.execute(
                """
                SELECT variant_key, brand_normalized, brand_name, vehicle_type, model_name,
                       year_from, source_designation, variant_section
                FROM catalog_variants
                ORDER BY variant_key
                """
            ).fetchall()
            if row["brand_normalized"] not in hidden
            and not is_excluded_catalog_brand_type(row["brand_normalized"], row["vehicle_type"])
        ]
        assemblies = [
            dict(row)
            for row in conn.execute(
                """
                SELECT assembly_key, variant_key, brand_normalized, brand_name, vehicle_type,
                       arib, aria, slug, title, path_json
                FROM catalog_assemblies
                ORDER BY variant_key, aria
                """
            ).fetchall()
            if row["brand_normalized"] not in hidden
            and not is_excluded_catalog_brand_type(row["brand_normalized"], row["vehicle_type"])
        ]
    finally:
        conn.close()
    return models, variants, assemblies


def import_structure_from_snapshot(*, snapshot_path: str) -> dict[str, int]:
    """Phase 4: brands, models, variants, assembly shells from SQLite snapshot."""
    _log(f"rebuild import structure start snapshot={snapshot_path}")
    models, variants, assemblies = _load_snapshot_rows(snapshot_path)
    _log(
        f"rebuild import structure snapshot rows "
        f"models={len(models)} variants={len(variants)} assemblies={len(assemblies)}"
    )

    model_index: dict[tuple[str, str, str], int] = {}
    variant_index: dict[str, int] = {}
    aria_nodes: dict[str, int] = {}
    counts = {
        "brands": 0,
        "models": 0,
        "variants": 0,
        "source_nodes": 0,
        "assemblies": 0,
    }
    seen_brands: set[str] = set()

    with writer.batch_conn():
        for index, model in enumerate(models, start=1):
            brand_norm = model["brand_normalized"]
            if brand_norm not in seen_brands:
                writer.ensure_brand(model["brand_name"])
                seen_brands.add(brand_norm)
                counts["brands"] += 1
            brand_id = writer.ensure_brand(model["brand_name"])
            model_id = writer.ensure_model_family(model["vehicle_type"], brand_id, model["model_name"])
            model_index[(brand_norm, model["vehicle_type"], normalize_text(model["model_name"]))] = model_id
            counts["models"] += 1
            if index == 1 or index % 500 == 0 or index == len(models):
                _log(f"rebuild import models {index}/{len(models)}")

        for index, variant in enumerate(variants, start=1):
            brand_id = writer.ensure_brand(variant["brand_name"])
            model_id = writer.ensure_model_family(
                variant["vehicle_type"],
                brand_id,
                variant["model_name"],
            )
            variant_id = writer.ensure_variant(
                model_id,
                year_from=variant.get("year_from"),
                year_to=variant.get("year_from"),
                market_name=variant["model_name"],
                source_designation=variant.get("source_designation"),
                variant_section=variant.get("variant_section"),
            )
            variant_index[str(variant["variant_key"])] = variant_id
            counts["variants"] += 1
            if index == 1 or index % 2000 == 0 or index == len(variants):
                _log(f"rebuild import variants {index}/{len(variants)}")

        for index, row in enumerate(assemblies, start=1):
            variant_key = str(row["variant_key"])
            variant_id = variant_index.get(variant_key)
            if variant_id is None:
                continue

            path = json.loads(row["path_json"]) if row.get("path_json") else []
            brand_norm = row["brand_normalized"]
            arib = crawl_arib_for_brand(brand_norm, row.get("arib"))
            aria = (row.get("aria") or "").strip() or None
            slug = row.get("slug")

            external_id = assembly_external_id(arib=arib, aria=aria, slug=slug, path=path)
            source_node_id: int | None = None
            if aria:
                source_node_id = aria_nodes.get(aria)
            if source_node_id is None:
                source_node_id = writer.ensure_source_node(
                    source_code=SOURCE_CODE,
                    node_type="assembly",
                    title=row["title"],
                    external_id=external_id,
                    arib=arib,
                    aria=aria,
                    slug=slug,
                )
                if aria:
                    aria_nodes[aria] = source_node_id
                counts["source_nodes"] += 1

            writer.ensure_assembly(variant_id, row["title"], source_node_id)
            counts["assemblies"] += 1

            if index == 1 or index % 10000 == 0 or index == len(assemblies):
                _log(
                    f"rebuild import assemblies {index}/{len(assemblies)} "
                    f"nodes={counts['source_nodes']}"
                )

    _log(f"rebuild import structure done counts={counts}")
    return counts


def _assembly_lookup(*, conn) -> dict[tuple[str, str], dict[str, Any]]:
    rows = _fetch_all(
        """
        SELECT
          a.id AS assembly_id,
          a.source_node_id,
          vv.market_name,
          vv.source_designation,
          vv.year_from,
          vv.variant_section,
          vt.code AS vehicle_type,
          sn.aria,
          sn.arib,
          sn.slug,
          sn.external_id
        FROM oem_assemblies a
        JOIN oem_vehicle_variants vv ON vv.id = a.vehicle_variant_id
        JOIN oem_model_families mf ON mf.id = vv.model_family_id
        JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
        JOIN oem_brands b ON b.id = mf.brand_id
        LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
        WHERE b.normalized_name <> ALL(%s)
        """,
        (list(_hidden_brands()),),
        conn=conn,
    )
    lookup: dict[tuple[str, str], dict[str, Any]] = {}
    for row in rows:
        variant_key = _variant_key_from_row(row)
        match_token = _match_token_from_node(row)
        if not match_token:
            continue
        lookup[(variant_key, match_token)] = row
    return lookup


def _clear_assembly_contents(*, conn) -> None:
    """Remove diagram/part rows so restore can be re-run safely."""
    _log("rebuild restore — clearing existing diagrams/parts/hotspots")
    for table in ("oem_diagram_hotspots", "oem_assembly_parts", "oem_diagrams"):
        _exec(f"TRUNCATE TABLE {table} RESTART IDENTITY CASCADE", conn=conn)


def _populate_restore_map(*, conn) -> tuple[int, int]:
    lookup = _assembly_lookup(conn=conn)
    backup_keys = _fetch_all(
        f"SELECT DISTINCT variant_key, match_token FROM {_table('assemblies')} ORDER BY 1, 2",
        conn=conn,
    )
    _exec("DROP TABLE IF EXISTS tmp_restore_map", conn=conn)
    _exec(
        """
        CREATE TEMP TABLE tmp_restore_map (
          variant_key TEXT NOT NULL,
          match_token TEXT NOT NULL,
          assembly_id BIGINT NOT NULL,
          source_node_id BIGINT,
          PRIMARY KEY (variant_key, match_token)
        ) ON COMMIT PRESERVE ROWS
        """,
        conn=conn,
    )
    _exec("CREATE INDEX ON tmp_restore_map (assembly_id)", conn=conn)

    map_rows: list[tuple[Any, ...]] = []
    missing = 0
    for row in backup_keys:
        key = (row["variant_key"], row["match_token"])
        target = lookup.get(key)
        if not target:
            missing += 1
            continue
        map_rows.append(
            (
                key[0],
                key[1],
                int(target["assembly_id"]),
                target.get("source_node_id"),
            )
        )

    for offset in range(0, len(map_rows), BATCH_SIZE):
        _executemany(
            """
            INSERT INTO tmp_restore_map (variant_key, match_token, assembly_id, source_node_id)
            VALUES (%s, %s, %s, %s)
            ON CONFLICT (variant_key, match_token) DO NOTHING
            """,
            map_rows[offset : offset + BATCH_SIZE],
            conn=conn,
        )
    matched = len(map_rows)
    _log(f"rebuild restore — map matched={matched} missing_target={missing}")
    return matched, missing


def _assembly_id_batches(*, conn, batch_size: int) -> list[list[int]]:
    rows = _fetch_all("SELECT assembly_id FROM tmp_restore_map ORDER BY assembly_id", conn=conn)
    ids = [int(row["assembly_id"]) for row in rows]
    return [ids[offset : offset + batch_size] for offset in range(0, len(ids), batch_size)]


def _ensure_backup_indexes(*, conn) -> None:
    _log("rebuild restore — ensuring backup indexes")
    _exec(
        f"CREATE INDEX IF NOT EXISTS ix_rebuild_ap_part "
        f"ON {_table('assembly_parts')} (normalized_part_number)",
        conn=conn,
    )
    conn.commit()


def _prepare_distinct_parts_temp(*, conn) -> int:
    _log("rebuild restore — building distinct parts temp table")
    _exec("DROP TABLE IF EXISTS tmp_rebuild_distinct_parts", conn=conn)
    _exec(
        f"""
        CREATE TEMP TABLE tmp_rebuild_distinct_parts ON COMMIT PRESERVE ROWS AS
        SELECT DISTINCT ON (b.normalized_part_number)
          b.normalized_part_number,
          b.part_number,
          b.manufacturer,
          b.part_name
        FROM {_table("assembly_parts")} b
        ORDER BY b.normalized_part_number, b.part_number
        """,
        conn=conn,
    )
    _exec("CREATE INDEX ON tmp_rebuild_distinct_parts (normalized_part_number)", conn=conn)
    row = _fetch_one("SELECT COUNT(*) AS cnt FROM tmp_rebuild_distinct_parts", conn=conn) or {"cnt": 0}
    count = int(row["cnt"] or 0)
    _log(f"rebuild restore — distinct parts={count}")
    return count


def _batched_assembly_restore(
    conn,
    *,
    label: str,
    query: str,
    batch_size: int = RESTORE_ASSEMBLY_BATCH,
    log_every: int = 1,
) -> int:
    batches = _assembly_id_batches(conn=conn, batch_size=batch_size)
    total = 0
    started = time.monotonic()
    _log(f"rebuild restore — {label} start batches={len(batches)} batch_size={batch_size}")
    for index, batch in enumerate(batches, start=1):
        if index == 1 or index % log_every == 0 or index == len(batches):
            _log(f"rebuild restore — {label} batch {index}/{len(batches)} running...")
        batch_started = time.monotonic()
        total += _exec_rowcount(query, (batch,), conn=conn)
        conn.commit()
        if index == 1 or index % log_every == 0 or index == len(batches):
            elapsed = int(time.monotonic() - started)
            batch_elapsed = int(time.monotonic() - batch_started)
            pct = (100.0 * index / len(batches)) if batches else 100.0
            _log(
                f"rebuild restore — {label} batch {index}/{len(batches)} ({pct:.1f}%) "
                f"rows={total} elapsed={elapsed}s last_batch={batch_elapsed}s"
            )
    return total


def _batched_parts_catalog_restore(conn) -> int:
    """Insert only part rows missing from oem_parts (table was kept across truncate)."""
    distinct_count = _prepare_distinct_parts_temp(conn=conn)
    missing_row = _fetch_one(
        """
        SELECT COUNT(*) AS cnt
        FROM tmp_rebuild_distinct_parts t
        WHERE NOT EXISTS (
          SELECT 1 FROM oem_parts p WHERE p.normalized_part_number = t.normalized_part_number
        )
        """,
        conn=conn,
    ) or {"cnt": distinct_count}
    missing = int(missing_row["cnt"] or 0)
    if missing == 0:
        _log(
            f"rebuild restore — parts catalog skip "
            f"(all {distinct_count} parts already in oem_parts)"
        )
        return 0

    _log(f"rebuild restore — parts catalog inserting missing={missing} of {distinct_count}")
    rows = _fetch_all(
        """
        SELECT t.normalized_part_number, t.part_number, t.manufacturer, t.part_name
        FROM tmp_rebuild_distinct_parts t
        WHERE NOT EXISTS (
          SELECT 1 FROM oem_parts p WHERE p.normalized_part_number = t.normalized_part_number
        )
        ORDER BY t.normalized_part_number
        """,
        conn=conn,
    )
    batch_size = 10_000
    batches = [rows[offset : offset + batch_size] for offset in range(0, len(rows), batch_size)]
    total = 0
    started = time.monotonic()
    _log(f"rebuild restore — parts catalog start batches={len(batches)} batch_size={batch_size}")
    for index, batch in enumerate(batches, start=1):
        _log(f"rebuild restore — parts catalog batch {index}/{len(batches)} running...")
        batch_started = time.monotonic()
        _executemany(
            """
            INSERT INTO oem_parts (brand_id, manufacturer, part_number, normalized_part_number, name)
            VALUES (NULL, COALESCE(NULLIF(%s, ''), 'unknown'), %s, %s, %s)
            ON CONFLICT (normalized_part_number) DO NOTHING
            """,
            [
                (
                    row.get("manufacturer"),
                    row["part_number"],
                    row["normalized_part_number"],
                    row.get("part_name"),
                )
                for row in batch
            ],
            conn=conn,
        )
        conn.commit()
        total += len(batch)
        elapsed = int(time.monotonic() - started)
        batch_elapsed = int(time.monotonic() - batch_started)
        pct = (100.0 * index / len(batches)) if batches else 100.0
        _log(
            f"rebuild restore — parts catalog batch {index}/{len(batches)} ({pct:.1f}%) "
            f"inserted={total} elapsed={elapsed}s last_batch={batch_elapsed}s"
        )
    return total


def restore_parsed_contents() -> dict[str, int]:
    """Phase 5: bulk reattach diagrams, parts, hotspots from temp tables."""
    with get_conn() as conn:
        if not rebuild_tables_exist(conn=conn):
            raise RuntimeError("rebuild backup tables missing — run export phase first")

        _log("rebuild restore start (batched SQL, progress per batch)")
        _clear_assembly_contents(conn=conn)

        matched, missing_target = _populate_restore_map(conn=conn)
        counts: dict[str, int] = {
            "matched": matched,
            "missing_target": missing_target,
        }

        _ensure_backup_indexes(conn=conn)
        counts["parts_catalog"] = _batched_parts_catalog_restore(conn)

        counts["diagrams"] = _batched_assembly_restore(
            conn,
            label="diagrams",
            query=f"""
                INSERT INTO oem_diagrams (
                  assembly_id, source_node_id, original_url, local_path, public_url,
                  source_image_id, width, height, mime_type, checksum_sha256, sort_order
                )
                SELECT
                  m.assembly_id,
                  m.source_node_id,
                  d.original_url,
                  d.local_path,
                  d.public_url,
                  d.source_image_id,
                  d.width,
                  d.height,
                  d.mime_type,
                  d.checksum_sha256,
                  d.sort_order
                FROM {_table("diagrams")} d
                JOIN tmp_restore_map m
                  ON m.variant_key = d.variant_key
                 AND m.match_token = d.match_token
                WHERE m.assembly_id = ANY(%s)
            """,
        )
        _log(f"rebuild restore — diagrams inserted={counts['diagrams']}")

        counts["parts"] = _batched_assembly_restore(
            conn,
            label="assembly parts",
            query=f"""
                INSERT INTO oem_assembly_parts (
                  assembly_id, part_id, source_node_id, ref, quantity, row_kind,
                  source_row_id, source_items_list_id, notes, raw_payload
                )
                SELECT
                  m.assembly_id,
                  p.id,
                  m.source_node_id,
                  b.ref,
                  b.quantity,
                  b.row_kind,
                  b.source_row_id,
                  b.source_items_list_id,
                  b.notes,
                  b.raw_payload
                FROM {_table("assembly_parts")} b
                JOIN tmp_restore_map m
                  ON m.variant_key = b.variant_key
                 AND m.match_token = b.match_token
                JOIN oem_parts p ON p.normalized_part_number = b.normalized_part_number
                WHERE m.assembly_id = ANY(%s)
            """,
        )
        _log(f"rebuild restore — parts inserted={counts['parts']}")

        counts["hotspots"] = _batched_assembly_restore(
            conn,
            label="hotspots",
            query=f"""
                INSERT INTO oem_diagram_hotspots (
                  diagram_id, assembly_part_id, shape, raw_coords,
                  x, y, width, height, polygon_json, ref, source_items_list_id, raw_payload
                )
                SELECT
                  dg.id,
                  ap.id,
                  h.shape,
                  h.raw_coords,
                  h.x,
                  h.y,
                  h.width,
                  h.height,
                  h.polygon_json,
                  h.ref,
                  h.source_items_list_id,
                  h.raw_payload
                FROM {_table("hotspots")} h
                JOIN tmp_restore_map m
                  ON m.variant_key = h.variant_key
                 AND m.match_token = h.match_token
                JOIN LATERAL (
                  SELECT d.id
                  FROM oem_diagrams d
                  WHERE d.assembly_id = m.assembly_id
                  ORDER BY d.sort_order, d.id
                  LIMIT 1
                ) dg ON TRUE
                LEFT JOIN LATERAL (
                  SELECT ap2.id
                  FROM oem_assembly_parts ap2
                  WHERE ap2.assembly_id = m.assembly_id
                    AND (
                      (h.part_source_row_id IS NOT NULL AND ap2.source_row_id = h.part_source_row_id)
                      OR (
                        h.source_items_list_id IS NOT NULL
                        AND ap2.source_items_list_id = h.source_items_list_id
                      )
                      OR (h.ref IS NOT NULL AND ap2.ref = h.ref)
                    )
                  ORDER BY
                    CASE
                      WHEN h.part_source_row_id IS NOT NULL AND ap2.source_row_id = h.part_source_row_id THEN 0
                      WHEN h.source_items_list_id IS NOT NULL AND ap2.source_items_list_id = h.source_items_list_id THEN 1
                      ELSE 2
                    END,
                    ap2.id
                  LIMIT 1
                ) ap ON TRUE
                WHERE m.assembly_id = ANY(%s)
            """,
        )
        _log(f"rebuild restore — hotspots inserted={counts['hotspots']}")

    _log(f"rebuild restore done counts={counts}")
    return counts


def drop_backup_tables(*, conn) -> None:
    _log("rebuild cleanup — dropping temp tables")
    for suffix in ("assemblies", "diagrams", "assembly_parts", "hotspots", "meta"):
        _exec(f"DROP TABLE IF EXISTS {_table(suffix)}", conn=conn)
    conn.commit()
    _log("rebuild cleanup done")


def run_rebuild(
    *,
    snapshot_path: str,
    phase: str = "all",
    skip_export: bool = False,
) -> dict[str, Any]:
    """Run one or all rebuild phases. Each phase commits and logs progress."""
    started = time.time()
    snapshot_file = Path(snapshot_path)
    if phase != "export" and not snapshot_file.is_file():
        raise FileNotFoundError(f"snapshot not found: {snapshot_path}")

    result: dict[str, Any] = {"phase": phase, "snapshot_path": str(snapshot_file)}
    phases = _phase_list(phase)

    with get_conn() as conn:
        if "export" in phases and not skip_export:
            result["export"] = export_parsed_contents(conn=conn)
        if "truncate" in phases:
            if not rebuild_tables_exist(conn=conn):
                raise RuntimeError("export backup tables missing — run export phase first")
            truncate_catalog(conn=conn)
            result["truncated"] = True
        if "schema" in phases:
            apply_schema_optimizations(conn=conn)
            result["schema"] = True

    if "import" in phases:
        result["import"] = import_structure_from_snapshot(snapshot_path=str(snapshot_file))
    if "restore" in phases:
        result["restore"] = restore_parsed_contents()
    if "cleanup" in phases:
        with get_conn() as conn:
            drop_backup_tables(conn=conn)
        result["cleanup"] = True

    result["duration_seconds"] = round(time.time() - started, 1)
    _log(f"rebuild finished phase={phase} elapsed={result['duration_seconds']}s")
    return result


def _phase_list(phase: str) -> list[str]:
    mapping = {
        "export": ["export"],
        "truncate": ["truncate"],
        "schema": ["schema"],
        "import": ["import"],
        "restore": ["restore"],
        "cleanup": ["cleanup"],
        "structure": ["truncate", "schema", "import"],
        "all": ["export", "truncate", "schema", "import", "restore", "cleanup"],
    }
    if phase not in mapping:
        raise ValueError(f"unknown phase: {phase}")
    return mapping[phase]
