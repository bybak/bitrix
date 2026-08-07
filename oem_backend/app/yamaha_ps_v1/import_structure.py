from __future__ import annotations

import json
from typing import Any

from app.db import get_yamaha_conn
from app.yamaha_v1.import_structure import _purge_root_structure
from app.yamaha_ps_v1.constants import BULK_BATCH_SIZE, ROOT_ARIB, SOURCE_CODE
from app.yamaha_ps_v1.progress import ProgressReporter
from app.yamaha_ps_v1.snapshot import open_snapshot


def _chunks(rows: list[tuple[Any, ...]], size: int) -> list[list[tuple[Any, ...]]]:
    return [rows[i : i + size] for i in range(0, len(rows), size)]


def import_structure(*, snapshot_path: str, resume: bool = True) -> dict[str, Any]:
    snap = open_snapshot(snapshot_path)
    try:
        nav_rows = snap.execute(
            """
            SELECT DISTINCT root_arib, arib, aria, slug, rel, title, depth, path_json, partstream_brand
            FROM api_nodes
            WHERE rel <> 'assembly'
            ORDER BY root_arib, depth, title
            """
        ).fetchall()
        variant_rows = snap.execute("SELECT * FROM catalog_variants ORDER BY variant_key").fetchall()
        assembly_rows = snap.execute(
            "SELECT * FROM catalog_assemblies ORDER BY variant_key, assembly_key"
        ).fetchall()
    finally:
        snap.close()

    progress = ProgressReporter(
        total=max(len(nav_rows) + len(variant_rows) + len(assembly_rows), 1),
        label="yamaha-ps-import",
    )

    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if not resume:
                _purge_root_structure(cur, root_arib=ROOT_ARIB, log=lambda m: print(f"[yamaha-ps-import] {m}", flush=True))
                conn.commit()

            existing_variants = set()
            if resume:
                cur.execute("SELECT variant_key FROM oem_variants WHERE root_arib = %s", (ROOT_ARIB,))
                existing_variants = {row["variant_key"] for row in cur.fetchall()}

            progress.set_stage("nav_nodes", len(nav_rows))
            path_to_id: dict[str, int] = {}
            for row in nav_rows:
                path = json.loads(row["path_json"])
                path_key = json.dumps([row["root_arib"], *path], ensure_ascii=False)
                parent_key = json.dumps([row["root_arib"], *path[:-1]], ensure_ascii=False) if path else None
                parent_id = path_to_id.get(parent_key) if parent_key else None
                cur.execute(
                    """
                    INSERT INTO oem_nav_nodes(
                      root_arib, parent_id, aria, slug, rel, title, path_json, depth, sort_order
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s)
                    ON CONFLICT (root_arib, path_json, rel, title) DO UPDATE SET
                      parent_id = EXCLUDED.parent_id,
                      aria = EXCLUDED.aria,
                      slug = EXCLUDED.slug,
                      depth = EXCLUDED.depth
                    RETURNING id
                    """,
                    (
                        row["root_arib"],
                        parent_id,
                        row["aria"],
                        row["slug"],
                        row["rel"],
                        row["title"],
                        json.dumps(path, ensure_ascii=False),
                        int(row["depth"]),
                        500,
                    ),
                )
                inserted = cur.fetchone()
                path_to_id[path_key] = int(inserted["id"])
                progress.advance("nav node")

            conn.commit()

            progress.set_stage("variants", len(variant_rows))
            variant_id_map: dict[str, int] = {}
            batch: list[tuple[Any, ...]] = []
            for row in variant_rows:
                if resume and row["variant_key"] in existing_variants:
                    continue
                payload = {
                    "source": SOURCE_CODE,
                    "partstream_brand": row["partstream_brand"],
                }
                batch.append(
                    (
                        row["root_arib"],
                        row["variant_key"],
                        row["model_name"],
                        row["source_designation"],
                        row["year_from"],
                        row["variant_section"],
                        row["browse_line"],
                        row["path_json"],
                        int(row["assembly_count"]),
                        json.dumps(payload, ensure_ascii=False),
                    )
                )
                if len(batch) >= BULK_BATCH_SIZE:
                    _flush_variants(cur, batch, variant_id_map)
                    progress.advance("", step=len(batch))
                    batch.clear()

            if batch:
                _flush_variants(cur, batch, variant_id_map)
                progress.advance("", step=len(batch))
            conn.commit()

            if resume:
                cur.execute(
                    "SELECT id, variant_key FROM oem_variants WHERE root_arib = %s",
                    (ROOT_ARIB,),
                )
                variant_id_map = {row["variant_key"]: int(row["id"]) for row in cur.fetchall()}

            progress.set_stage("assemblies", len(assembly_rows))
            existing_assemblies: set[tuple[int, str]] = set()
            if resume:
                cur.execute(
                    """
                    SELECT variant_id, assembly_key FROM oem_assemblies
                    WHERE root_arib = %s
                    """,
                    (ROOT_ARIB,),
                )
                existing_assemblies = {(int(row["variant_id"]), row["assembly_key"]) for row in cur.fetchall()}

            asm_batch: list[tuple[Any, ...]] = []
            details_batch: list[tuple[Any, ...]] = []
            for row in assembly_rows:
                variant_id = variant_id_map.get(row["variant_key"])
                if not variant_id:
                    continue
                if resume and (variant_id, row["assembly_key"]) in existing_assemblies:
                    continue
                payload = {
                    "source": SOURCE_CODE,
                    "partstream_brand": row["partstream_brand"],
                }
                asm_batch.append(
                    (
                        variant_id,
                        row["root_arib"],
                        row["assembly_key"],
                        row["aria"],
                        row["slug"],
                        row["title"],
                        row["path_json"],
                        500,
                        json.dumps(payload, ensure_ascii=False),
                    )
                )
                if len(asm_batch) >= BULK_BATCH_SIZE:
                    ids = _flush_assemblies(cur, asm_batch)
                    for assembly_id in ids:
                        details_batch.append((assembly_id,))
                    progress.advance("", step=len(asm_batch))
                    asm_batch.clear()
                    if len(details_batch) >= BULK_BATCH_SIZE:
                        _flush_details_pages(cur, details_batch)
                        details_batch.clear()

            if asm_batch:
                ids = _flush_assemblies(cur, asm_batch)
                for assembly_id in ids:
                    details_batch.append((assembly_id,))
                progress.advance("", step=len(asm_batch))
            if details_batch:
                _flush_details_pages(cur, details_batch)

            conn.commit()

            cur.execute("SELECT COUNT(*) AS c FROM oem_variants WHERE root_arib = %s", (ROOT_ARIB,))
            variants_count = int(cur.fetchone()["c"])
            cur.execute("SELECT COUNT(*) AS c FROM oem_assemblies WHERE root_arib = %s", (ROOT_ARIB,))
            assemblies_count = int(cur.fetchone()["c"])
            cur.execute("SELECT COUNT(*) AS c FROM oem_nav_nodes WHERE root_arib = %s", (ROOT_ARIB,))
            nav_count = int(cur.fetchone()["c"])

    progress.finish("import complete")
    return {
        "root_arib": ROOT_ARIB,
        "variants": variants_count,
        "assemblies": assemblies_count,
        "nav_nodes": nav_count,
    }


def _flush_variants(cur: Any, batch: list[tuple[Any, ...]], variant_id_map: dict[str, int]) -> None:
    cur.executemany(
        """
        INSERT INTO oem_variants(
          root_arib, variant_key, model_name, source_designation, year_from,
          variant_section, browse_line, path_json, assembly_count, source_payload
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)
        ON CONFLICT (variant_key) DO UPDATE SET
          assembly_count = EXCLUDED.assembly_count,
          source_payload = EXCLUDED.source_payload,
          updated_at = now()
        """,
        batch,
    )
    keys = [row[1] for row in batch]
    cur.execute(
        "SELECT id, variant_key FROM oem_variants WHERE variant_key = ANY(%s)",
        (keys,),
    )
    for row in cur.fetchall():
        variant_id_map[row["variant_key"]] = int(row["id"])


def _flush_assemblies(cur: Any, batch: list[tuple[Any, ...]]) -> list[int]:
    ids: list[int] = []
    cur.executemany(
        """
        INSERT INTO oem_assemblies(
          variant_id, root_arib, assembly_key, aria, slug, title, path_json, sort_order, source_payload
        ) VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)
        ON CONFLICT (variant_id, assembly_key) DO UPDATE SET
          source_payload = EXCLUDED.source_payload,
          updated_at = now()
        """,
        batch,
    )
    for row in batch:
        cur.execute(
            "SELECT id FROM oem_assemblies WHERE variant_id = %s AND assembly_key = %s",
            (row[0], row[2]),
        )
        found = cur.fetchone()
        if found:
            ids.append(int(found["id"]))
    return ids


def _flush_details_pages(cur: Any, batch: list[tuple[Any, ...]]) -> None:
    cur.executemany(
        """
        INSERT INTO oem_details_pages(assembly_id)
        VALUES (%s)
        ON CONFLICT (assembly_id) DO NOTHING
        """,
        batch,
    )
