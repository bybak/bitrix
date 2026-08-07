from __future__ import annotations

import json
import time
from typing import Any

from app.db import get_yamaha_conn
from app.yamaha_v1.constants import BULK_BATCH_SIZE, PROGRESS_INTERVAL_SEC
from app.yamaha_v1.progress import ProgressReporter
from app.yamaha_v1.snapshot import open_snapshot


def _roots_in_snapshot(snap) -> set[str]:
    roots: set[str] = set()
    for query in (
        "SELECT DISTINCT root_arib FROM nav_nodes",
        "SELECT DISTINCT root_arib FROM catalog_variants",
        "SELECT DISTINCT root_arib FROM catalog_assemblies",
    ):
        rows = snap.execute(query).fetchall()
        roots |= {str(row["root_arib"]) for row in rows}
    return roots


def _purge_root_structure(cur, *, root_arib: str, log=None) -> None:
    def _emit(message: str) -> None:
        if log is not None:
            log(message)

    _emit(f"purge {root_arib}: diagram hotspots (by assembly_part)")
    cur.execute(
        """
        DELETE FROM oem_diagram_hotspots hs
        USING oem_assembly_parts ap
        JOIN oem_assemblies a ON a.id = ap.assembly_id
        WHERE hs.assembly_part_id = ap.id
          AND a.root_arib = %s
        """,
        (root_arib,),
    )
    _emit(f"purge {root_arib}: diagram hotspots (by diagram)")
    cur.execute(
        """
        DELETE FROM oem_diagram_hotspots
        WHERE diagram_id IN (
          SELECT d.id FROM oem_diagrams d
          JOIN oem_assemblies a ON a.id = d.assembly_id
          WHERE a.root_arib = %s
        )
        """,
        (root_arib,),
    )
    _emit(f"purge {root_arib}: assembly parts")
    cur.execute(
        """
        DELETE FROM oem_assembly_parts
        WHERE assembly_id IN (SELECT id FROM oem_assemblies WHERE root_arib = %s)
        """,
        (root_arib,),
    )
    _emit(f"purge {root_arib}: diagrams")
    cur.execute(
        """
        DELETE FROM oem_diagrams
        WHERE assembly_id IN (SELECT id FROM oem_assemblies WHERE root_arib = %s)
        """,
        (root_arib,),
    )
    _emit(f"purge {root_arib}: details pages")
    cur.execute(
        """
        DELETE FROM oem_details_pages
        WHERE assembly_id IN (SELECT id FROM oem_assemblies WHERE root_arib = %s)
        """,
        (root_arib,),
    )
    _emit(f"purge {root_arib}: assemblies")
    cur.execute("DELETE FROM oem_assemblies WHERE root_arib = %s", (root_arib,))
    _emit(f"purge {root_arib}: variants")
    cur.execute("DELETE FROM oem_variants WHERE root_arib = %s", (root_arib,))
    _emit(f"purge {root_arib}: nav nodes")
    cur.execute("DELETE FROM oem_nav_nodes WHERE root_arib = %s", (root_arib,))


def import_structure(*, snapshot_path: str, resume: bool = True) -> dict[str, Any]:
    snap = open_snapshot(snapshot_path)
    try:
        nav_count = int(snap.execute("SELECT COUNT(*) AS c FROM nav_nodes").fetchone()["c"])
        variant_count = int(snap.execute("SELECT COUNT(*) AS c FROM catalog_variants").fetchone()["c"])
        assembly_count = int(snap.execute("SELECT COUNT(*) AS c FROM catalog_assemblies").fetchone()["c"])
        roots = _roots_in_snapshot(snap)
    finally:
        snap.close()

    total = nav_count + variant_count + assembly_count
    progress = ProgressReporter(total=max(total, 1), label="yamaha-import-structure")
    stats = {"nav_nodes": 0, "variants": 0, "assemblies": 0, "skipped_variants": 0, "skipped_assemblies": 0}
    last_tick = time.monotonic()

    snap = open_snapshot(snapshot_path)
    try:
        nav_rows = snap.execute("SELECT * FROM nav_nodes ORDER BY root_arib, depth, title")
        variant_rows = snap.execute("SELECT * FROM catalog_variants ORDER BY variant_key")

        with get_yamaha_conn() as conn:
            with conn.cursor() as cur:
                if not resume:
                    progress.set_stage("purge", len(roots))
                    for root_arib in sorted(roots):
                        progress.tick(f"purging {root_arib}")
                        _purge_root_structure(
                            cur,
                            root_arib=root_arib,
                            log=lambda message: progress.tick(message),
                        )
                        progress.advance(f"purged {root_arib}")
                    conn.commit()

                existing_variants: set[str] = set()
                if resume:
                    cur.execute("SELECT variant_key FROM oem_variants")
                    existing_variants = {row["variant_key"] for row in cur.fetchall()}

                progress.set_stage("nav_nodes", nav_count)
                path_to_id: dict[str, int] = {}
                nav_batch_count = 0
                for row in nav_rows:
                    path = json.loads(row["path_json"])
                    path_key = json.dumps([row["root_arib"], *path], ensure_ascii=False)
                    parent_key = json.dumps([row["root_arib"], *path[:-1]], ensure_ascii=False) if path else None
                    parent_id = path_to_id.get(parent_key) if parent_key else None
                    cur.execute(
                        """
                        INSERT INTO oem_nav_nodes(
                          root_arib, parent_id, aria, slug, rel, title, path_json, depth, sort_order
                        ) VALUES (%s, %s, NULL, NULL, %s, %s, %s::jsonb, %s, %s)
                        ON CONFLICT (root_arib, path_json, rel, title) DO UPDATE SET
                          parent_id = EXCLUDED.parent_id,
                          depth = EXCLUDED.depth
                        RETURNING id
                        """,
                        (
                            row["root_arib"],
                            parent_id,
                            row["rel"],
                            row["title"],
                            json.dumps(path, ensure_ascii=False),
                            int(row["depth"]),
                            500,
                        ),
                    )
                    path_to_id[path_key] = int(cur.fetchone()["id"])
                    stats["nav_nodes"] += 1
                    progress.advance("nav node")
                    nav_batch_count += 1
                    if nav_batch_count >= BULK_BATCH_SIZE:
                        conn.commit()
                        nav_batch_count = 0
                    if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
                        progress.tick(f"nav={stats['nav_nodes']}")
                        last_tick = time.monotonic()
                conn.commit()

                progress.set_stage("variants", variant_count)
                variant_id_map: dict[str, int] = {}
                batch: list[tuple[Any, ...]] = []
                for row in variant_rows:
                    if resume and row["variant_key"] in existing_variants:
                        stats["skipped_variants"] += 1
                        progress.advance("skip variant")
                        continue
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
                            row["source_payload"],
                        )
                    )
                    if len(batch) >= BULK_BATCH_SIZE:
                        _flush_variants(cur, batch, variant_id_map)
                        stats["variants"] += len(batch)
                        progress.advance("", step=len(batch))
                        batch.clear()
                        conn.commit()
                if batch:
                    _flush_variants(cur, batch, variant_id_map)
                    stats["variants"] += len(batch)
                    progress.advance("", step=len(batch))
                conn.commit()

                cur.execute(
                    "SELECT id, variant_key FROM oem_variants WHERE root_arib = ANY(%s)",
                    (list(roots),),
                )
                variant_id_map = {row["variant_key"]: int(row["id"]) for row in cur.fetchall()}

                progress.set_stage("assemblies", assembly_count)
                asm_batch: list[tuple[Any, ...]] = []
                details_batch: list[tuple[Any, ...]] = []
                assembly_rows = snap.execute(
                    "SELECT * FROM catalog_assemblies ORDER BY variant_key, assembly_key"
                )
                for row in assembly_rows:
                    variant_id = variant_id_map.get(row["variant_key"])
                    if not variant_id:
                        continue
                    asm_batch.append(
                        (
                            variant_id,
                            row["root_arib"],
                            row["assembly_key"],
                            None,
                            None,
                            row["title"],
                            row["path_json"],
                            500,
                            row["source_payload"],
                        )
                    )
                    if len(asm_batch) >= BULK_BATCH_SIZE:
                        inserted, skipped = _flush_assemblies(cur, asm_batch, resume=resume)
                        details_batch.extend((aid,) for aid in inserted)
                        stats["assemblies"] += len(inserted)
                        stats["skipped_assemblies"] += skipped
                        progress.advance("", step=len(inserted) + skipped)
                        asm_batch.clear()
                        for chunk in _chunks(details_batch, BULK_BATCH_SIZE):
                            cur.executemany(
                                """
                                INSERT INTO oem_details_pages(assembly_id)
                                VALUES (%s)
                                ON CONFLICT (assembly_id) DO NOTHING
                                """,
                                chunk,
                            )
                        details_batch.clear()
                        conn.commit()
                        if time.monotonic() - last_tick >= PROGRESS_INTERVAL_SEC:
                            progress.tick(f"assemblies={stats['assemblies']} skipped={stats['skipped_assemblies']}")
                            last_tick = time.monotonic()

                if asm_batch:
                    inserted, skipped = _flush_assemblies(cur, asm_batch, resume=resume)
                    details_batch.extend((aid,) for aid in inserted)
                    stats["assemblies"] += len(inserted)
                    stats["skipped_assemblies"] += skipped
                    progress.advance("", step=len(inserted) + skipped)

                for chunk in _chunks(details_batch, BULK_BATCH_SIZE):
                    cur.executemany(
                        """
                        INSERT INTO oem_details_pages(assembly_id)
                        VALUES (%s)
                        ON CONFLICT (assembly_id) DO NOTHING
                        """,
                        chunk,
                    )
                conn.commit()
    finally:
        snap.close()

    progress.finish(f"import stats={stats}")
    return stats


def _flush_variants(cur, batch: list[tuple[Any, ...]], variant_id_map: dict[str, int]) -> None:
    for row in batch:
        cur.execute(
            """
            INSERT INTO oem_variants(
              root_arib, variant_key, model_name, source_designation, year_from,
              variant_section, browse_line, path_json, assembly_count, source_payload
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)
            ON CONFLICT (variant_key) DO UPDATE SET
              model_name = EXCLUDED.model_name,
              source_designation = EXCLUDED.source_designation,
              year_from = EXCLUDED.year_from,
              browse_line = EXCLUDED.browse_line,
              path_json = EXCLUDED.path_json,
              assembly_count = EXCLUDED.assembly_count,
              source_payload = EXCLUDED.source_payload,
              updated_at = now()
            RETURNING id, variant_key
            """,
            row,
        )
        inserted = cur.fetchone()
        variant_id_map[inserted["variant_key"]] = int(inserted["id"])


def _flush_assemblies(cur, batch: list[tuple[Any, ...]], *, resume: bool) -> tuple[list[int], int]:
    ids: list[int] = []
    skipped = 0
    for row in batch:
        if resume:
            cur.execute(
                """
                INSERT INTO oem_assemblies(
                  variant_id, root_arib, assembly_key, aria, slug, title, path_json, sort_order, source_payload
                ) VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)
                ON CONFLICT (variant_id, assembly_key) DO NOTHING
                RETURNING id
                """,
                row,
            )
            inserted = cur.fetchone()
            if inserted is None:
                skipped += 1
                continue
            ids.append(int(inserted["id"]))
            continue

        cur.execute(
            """
            INSERT INTO oem_assemblies(
              variant_id, root_arib, assembly_key, aria, slug, title, path_json, sort_order, source_payload
            ) VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)
            ON CONFLICT (variant_id, assembly_key) DO UPDATE SET
              title = EXCLUDED.title,
              path_json = EXCLUDED.path_json,
              source_payload = EXCLUDED.source_payload,
              updated_at = now()
            RETURNING id
            """,
            row,
        )
        ids.append(int(cur.fetchone()["id"]))
    return ids, skipped


def _chunks(rows: list[tuple[Any, ...]], size: int) -> list[list[tuple[Any, ...]]]:
    return [rows[i : i + size] for i in range(0, len(rows), size)]
