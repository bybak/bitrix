"""Live walk: browse tree → upsert PG structure → fetch diagram JSON immediately.

PNG stays in a separate crawl-images phase. Obsolete branches (gone from live browse)
are marked html_status='obsolete' and skipped by claim/parse.
"""

from __future__ import annotations

import hashlib
import json
import threading
import time
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.db import get_yamaha_conn
from app.yamaha_v1.progress import ProgressReporter

from .client import (
    YamahaUsApiError,
    browse_categories,
    browse_diagrams,
    browse_model_detail,
    browse_models,
    browse_years,
    configure_diagram_api_concurrency,
    fetch_diagram,
)
from .constants import (
    JSON_STORAGE_ROOT,
    OUTBOARD_SLUG,
    ROOT_ARIB,
    US_PRODUCT_TYPES,
)
from .keys import assembly_key, clean_label, nav_path, parse_year, variant_key

WALK_PHASE = "yamaha-us-walk"
JSON_BATCH_LOG = 50


def _select_products(product_slug: str | None) -> list[dict[str, str]]:
    if product_slug and product_slug.lower() != "all":
        for row in US_PRODUCT_TYPES:
            if row["slug"] == product_slug:
                return [row]
        raise ValueError(f"unknown US product slug: {product_slug}")
    return list(US_PRODUCT_TYPES)


def _filter_diagrams(
    diagrams: list[dict[str, Any]],
    *,
    limit: int | None,
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for diagram in diagrams:
        diagram_name = clean_label(diagram.get("name") or "")
        if "TITLE PAGE" in diagram_name.upper():
            continue
        if not (diagram.get("availableImageIds") or []):
            continue
        rows.append(diagram)
        if limit is not None and len(rows) >= limit:
            break
    return rows


def _json_path(assembly_id: int) -> Path:
    return Path(JSON_STORAGE_ROOT) / ROOT_ARIB / f"{assembly_id}.json"


def _checkpoint_get(item_key: str) -> dict[str, Any] | None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT status, payload
                FROM oem_crawl_checkpoints
                WHERE phase = %s AND item_key = %s
                """,
                (WALK_PHASE, item_key),
            )
            row = cur.fetchone()
    if not row:
        return None
    payload = row["payload"] or {}
    if isinstance(payload, str):
        payload = json.loads(payload)
    return {"status": row["status"], "payload": payload}


def _checkpoint_set(item_key: str, status: str, payload: dict[str, Any] | None = None) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO oem_crawl_checkpoints(phase, item_key, status, payload, updated_at)
                VALUES (%s, %s, %s, %s::jsonb, now())
                ON CONFLICT (phase, item_key) DO UPDATE SET
                  status = EXCLUDED.status,
                  payload = EXCLUDED.payload,
                  updated_at = now()
                """,
                (WALK_PHASE, item_key, status, json.dumps(payload or {}, ensure_ascii=False)),
            )
        conn.commit()


def _clear_walk_checkpoints(*, product_slug: str | None = None) -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if product_slug and product_slug.lower() != "all":
                cur.execute(
                    """
                    DELETE FROM oem_crawl_checkpoints
                    WHERE phase = %s
                      AND (item_key = %s OR item_key LIKE %s)
                    """,
                    (WALK_PHASE, product_slug, f"{product_slug}:%"),
                )
            else:
                cur.execute(
                    "DELETE FROM oem_crawl_checkpoints WHERE phase = %s",
                    (WALK_PHASE,),
                )
            deleted = cur.rowcount
        conn.commit()
    return int(deleted or 0)


def _upsert_nav(
    *,
    rel: str,
    title: str,
    path: list[str],
    parent_path: list[str] | None,
) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            parent_id = None
            if parent_path is not None:
                cur.execute(
                    """
                    SELECT id FROM oem_nav_nodes
                    WHERE root_arib = %s AND path_json = %s::jsonb
                    ORDER BY id LIMIT 1
                    """,
                    (ROOT_ARIB, json.dumps(parent_path, ensure_ascii=False)),
                )
                prow = cur.fetchone()
                parent_id = int(prow["id"]) if prow else None
            cur.execute(
                """
                INSERT INTO oem_nav_nodes(
                  root_arib, parent_id, aria, slug, rel, title, path_json, depth, sort_order
                ) VALUES (%s, %s, NULL, NULL, %s, %s, %s::jsonb, %s, %s)
                ON CONFLICT (root_arib, path_json, rel, title) DO UPDATE SET
                  parent_id = COALESCE(EXCLUDED.parent_id, oem_nav_nodes.parent_id),
                  depth = EXCLUDED.depth
                """,
                (
                    ROOT_ARIB,
                    parent_id,
                    rel,
                    title,
                    json.dumps(path, ensure_ascii=False),
                    max(0, len(path) - 1),
                    500,
                ),
            )
        conn.commit()


def _upsert_variant(row: dict[str, Any]) -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
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
                  variant_section = EXCLUDED.variant_section,
                  browse_line = EXCLUDED.browse_line,
                  path_json = EXCLUDED.path_json,
                  assembly_count = EXCLUDED.assembly_count,
                  source_payload = EXCLUDED.source_payload,
                  updated_at = now()
                RETURNING id
                """,
                (
                    ROOT_ARIB,
                    row["variant_key"],
                    row["model_name"],
                    row["source_designation"],
                    row["year_from"],
                    row["variant_section"],
                    row["browse_line"],
                    json.dumps(row["path_json"], ensure_ascii=False),
                    int(row["assembly_count"]),
                    json.dumps(row["source_payload"], ensure_ascii=False),
                ),
            )
            variant_id = int(cur.fetchone()["id"])
        conn.commit()
    return variant_id


def _upsert_assembly(
    *,
    variant_id: int,
    assembly_key_value: str,
    title: str,
    path: list[str],
    source_payload: dict[str, Any],
) -> tuple[int, bool]:
    """Return (assembly_id, created)."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO oem_assemblies(
                  variant_id, root_arib, assembly_key, aria, slug, title,
                  path_json, sort_order, source_payload
                ) VALUES (%s, %s, %s, NULL, NULL, %s, %s::jsonb, %s, %s::jsonb)
                ON CONFLICT (variant_id, assembly_key) DO UPDATE SET
                  title = EXCLUDED.title,
                  path_json = EXCLUDED.path_json,
                  source_payload = EXCLUDED.source_payload,
                  updated_at = now()
                RETURNING id, (xmax = 0) AS inserted
                """,
                (
                    variant_id,
                    ROOT_ARIB,
                    assembly_key_value,
                    title,
                    json.dumps(path, ensure_ascii=False),
                    500,
                    json.dumps(source_payload, ensure_ascii=False),
                ),
            )
            row = cur.fetchone()
            assembly_id = int(row["id"])
            created = bool(row.get("inserted"))
            # Fallback if xmax probe unavailable
            if row.get("inserted") is None:
                created = False
            cur.execute(
                """
                INSERT INTO oem_details_pages(assembly_id)
                VALUES (%s)
                ON CONFLICT (assembly_id) DO NOTHING
                RETURNING assembly_id
                """,
                (assembly_id,),
            )
            if cur.fetchone():
                created = True
            # Revive obsolete rows when branch reappears in live browse.
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'pending',
                    error_message = NULL,
                    updated_at = now()
                WHERE assembly_id = %s
                  AND html_status = 'obsolete'
                """,
                (assembly_id,),
            )
        conn.commit()
    return assembly_id, created


def _details_status(assembly_id: int) -> str | None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT html_status FROM oem_details_pages WHERE assembly_id = %s",
                (assembly_id,),
            )
            row = cur.fetchone()
    return str(row["html_status"]) if row else None


def _mark_json_ok(assembly_id: int, json_path: Path, digest: str) -> None:
    rel = str(json_path).replace("\\", "/")
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'ok',
                    html_path = %s,
                    html_hash = %s,
                    fetched_at = now(),
                    error_message = NULL,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (rel, digest, assembly_id),
            )
        conn.commit()


def _mark_json_error(assembly_id: int, message: str) -> None:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE oem_details_pages
                SET html_status = 'error',
                    error_message = %s,
                    updated_at = now()
                WHERE assembly_id = %s
                """,
                (message[:2000], assembly_id),
            )
        conn.commit()


def _fetch_one_json(
    *,
    assembly_id: int,
    model_id: str,
    image_id: str | int,
    force: bool,
) -> str:
    status = _details_status(assembly_id)
    json_path = _json_path(assembly_id)
    if not force and status == "ok" and json_path.is_file():
        return "skip"
    if not force and status == "ok":
        return "skip"
    try:
        diagram = fetch_diagram(model_id=model_id, image_id=image_id)
        json_path.parent.mkdir(parents=True, exist_ok=True)
        blob = {
            "fetched_at": datetime.now(timezone.utc).isoformat(),
            "request": {"model_id": model_id, "image_id": image_id},
            "response": diagram,
        }
        payload = json.dumps(blob, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        digest = hashlib.sha256(payload).hexdigest()
        json_path.write_bytes(payload)
        _mark_json_ok(assembly_id, json_path, digest)
        return "ok"
    except Exception as exc:
        _mark_json_error(assembly_id, f"diagram fetch failed: {exc}")
        return "error"


def _mark_obsolete_assemblies(
    *,
    product_slug: str,
    top_id: str | None,
    category_id: str | None,
    keep_assembly_keys: set[str],
    reason: str,
) -> int:
    """Mark assemblies in scope that were not seen in this live walk as obsolete."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            clauses = [
                "a.root_arib = %s",
                "a.source_payload->>'product_slug' = %s",
                "dp.html_status <> 'obsolete'",
            ]
            params_where: list[Any] = [ROOT_ARIB, product_slug]

            if top_id is not None:
                clauses.append("a.source_payload->>'top_id' = %s")
                params_where.append(top_id)
            if category_id is not None:
                clauses.append("a.source_payload->>'category_id' = %s")
                params_where.append(category_id)
            elif top_id is not None:
                # Non-outboard / no category: only rows without category_id
                clauses.append(
                    "(a.source_payload->>'category_id' IS NULL OR a.source_payload->>'category_id' = '')"
                )

            keep = sorted(keep_assembly_keys)
            if keep:
                clauses.append("NOT (a.assembly_key = ANY(%s))")
                params_where.append(keep)

            sql = f"""
                UPDATE oem_details_pages dp
                SET html_status = 'obsolete',
                    error_message = %s,
                    updated_at = now()
                FROM oem_assemblies a
                WHERE a.id = dp.assembly_id
                  AND {' AND '.join(clauses)}
            """
            cur.execute(sql, [reason[:2000], *params_where])
            updated = cur.rowcount
        conn.commit()
    return int(updated or 0)


def _mark_obsolete_missing_categories(
    *,
    product_slug: str,
    top_id: str,
    live_category_ids: set[str],
) -> int:
    """Outboard: categories that disappeared from browse → obsolete all their assemblies."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if live_category_ids:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = 'obsolete',
                        error_message = %s,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND a.source_payload->>'product_slug' = %s
                      AND a.source_payload->>'top_id' = %s
                      AND COALESCE(a.source_payload->>'category_id', '') <> ''
                      AND NOT (a.source_payload->>'category_id' = ANY(%s))
                      AND dp.html_status <> 'obsolete'
                    """,
                    (
                        "category gone from live browse",
                        ROOT_ARIB,
                        product_slug,
                        top_id,
                        sorted(live_category_ids),
                    ),
                )
            else:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = 'obsolete',
                        error_message = %s,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND a.source_payload->>'product_slug' = %s
                      AND a.source_payload->>'top_id' = %s
                      AND COALESCE(a.source_payload->>'category_id', '') <> ''
                      AND dp.html_status <> 'obsolete'
                    """,
                    ("category gone from live browse", ROOT_ARIB, product_slug, top_id),
                )
            updated = cur.rowcount
        conn.commit()
    return int(updated or 0)


def _mark_obsolete_missing_tops(
    *,
    product_slug: str,
    live_top_ids: set[str],
) -> int:
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            if live_top_ids:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = 'obsolete',
                        error_message = %s,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND a.source_payload->>'product_slug' = %s
                      AND NOT (a.source_payload->>'top_id' = ANY(%s))
                      AND dp.html_status <> 'obsolete'
                    """,
                    ("top/year gone from live browse", ROOT_ARIB, product_slug, sorted(live_top_ids)),
                )
            else:
                cur.execute(
                    """
                    UPDATE oem_details_pages dp
                    SET html_status = 'obsolete',
                        error_message = %s,
                        updated_at = now()
                    FROM oem_assemblies a
                    WHERE a.id = dp.assembly_id
                      AND a.root_arib = %s
                      AND a.source_payload->>'product_slug' = %s
                      AND dp.html_status <> 'obsolete'
                    """,
                    ("top/year gone from live browse", ROOT_ARIB, product_slug),
                )
            updated = cur.rowcount
        conn.commit()
    return int(updated or 0)


def _scope_key(product_slug: str, top_id: str, category_id: str | None) -> str:
    if category_id:
        return f"{product_slug}:{top_id}:{category_id}"
    return f"{product_slug}:{top_id}"


class _JsonPipeline:
    def __init__(self, *, concurrency: int, force: bool, stats: dict[str, int], stats_lock: threading.Lock):
        self.concurrency = max(1, concurrency)
        self.force = force
        self.stats = stats
        self.stats_lock = stats_lock
        self.executor = ThreadPoolExecutor(max_workers=self.concurrency)
        self.in_flight: dict[Any, tuple[int, str, str | int]] = {}
        self._batch_ok = 0
        self._batch_err = 0
        self._batch_skip = 0

    def submit(self, assembly_id: int, model_id: str, image_id: str | int) -> None:
        while len(self.in_flight) >= self.concurrency:
            self._drain(wait_one=True)
        fut = self.executor.submit(
            _fetch_one_json,
            assembly_id=assembly_id,
            model_id=model_id,
            image_id=image_id,
            force=self.force,
        )
        self.in_flight[fut] = (assembly_id, model_id, image_id)

    def _drain(self, *, wait_one: bool = False) -> None:
        if not self.in_flight:
            return
        if wait_one:
            done, _ = wait(self.in_flight.keys(), return_when=FIRST_COMPLETED)
        else:
            done, _ = wait(self.in_flight.keys())
        for fut in done:
            self.in_flight.pop(fut, None)
            result = fut.result()
            with self.stats_lock:
                if result == "ok":
                    self.stats["json_ok"] += 1
                    self._batch_ok += 1
                elif result == "error":
                    self.stats["json_error"] += 1
                    self._batch_err += 1
                else:
                    self.stats["json_skip"] += 1
                    self._batch_skip += 1
                batch_done = self._batch_ok + self._batch_err + self._batch_skip
                if batch_done >= JSON_BATCH_LOG:
                    err_pct = self._batch_err / batch_done * 100 if batch_done else 0.0
                    print(
                        f"[yamaha-us-walk] batch ok={self._batch_ok} err={self._batch_err} "
                        f"skip={self._batch_skip} ({err_pct:.0f}% err) "
                        f"total_ok={self.stats['json_ok']} total_err={self.stats['json_error']}",
                        flush=True,
                    )
                    self._batch_ok = self._batch_err = self._batch_skip = 0

    def flush(self) -> None:
        while self.in_flight:
            self._drain(wait_one=False)
        batch_done = self._batch_ok + self._batch_err + self._batch_skip
        if batch_done:
            err_pct = self._batch_err / batch_done * 100 if batch_done else 0.0
            print(
                f"[yamaha-us-walk] batch ok={self._batch_ok} err={self._batch_err} "
                f"skip={self._batch_skip} ({err_pct:.0f}% err)",
                flush=True,
            )
            self._batch_ok = self._batch_err = self._batch_skip = 0

    def close(self) -> None:
        self.flush()
        self.executor.shutdown(wait=True)


def _walk_model(
    *,
    product: dict[str, str],
    top_row: dict[str, Any],
    category_row: dict[str, Any] | None,
    model_row: dict[str, Any],
    limit_diagrams: int | None,
    force: bool,
    pipeline: _JsonPipeline,
    stats: dict[str, int],
    seen_assembly_keys: set[str],
    progress: ProgressReporter,
) -> str:
    product_slug = product["slug"]
    product_name = product["name"]
    top_id = str(top_row["id"])
    top_name = clean_label(top_row.get("name"))
    category_id = str(category_row["id"]) if category_row else None
    category_name = clean_label(category_row.get("name")) if category_row else None
    model_id = str(model_row["id"])
    model_name = clean_label(model_row.get("name"))

    model_nav = nav_path(
        product_name=product_name,
        top_name=top_name,
        category_name=category_name,
        model_name=model_name,
    )
    parent = model_nav[:-1]
    _upsert_nav(rel="model", title=model_name, path=model_nav, parent_path=parent)

    vkey = variant_key(
        product_slug=product_slug,
        top_id=top_id,
        category_id=category_id,
        model_id=model_id,
    )

    try:
        detail = browse_model_detail(product_slug=product_slug, top_id=top_id, model_id=model_id)
        diagrams = browse_diagrams(product_slug=product_slug, top_id=top_id, model_id=model_id)
    except Exception as exc:
        stats["errors"] += 1
        progress.advance(f"ERROR model {model_name}: {exc}")
        return vkey

    diagrams = _filter_diagrams(diagrams, limit=limit_diagrams)
    assemblies_payload: list[dict[str, Any]] = []
    for diagram in diagrams:
        diagram_id = str(diagram.get("id") or "")
        diagram_name = clean_label(diagram.get("name")) or diagram_id
        for image_id in diagram.get("availableImageIds") or []:
            asm_key = assembly_key(model_id=model_id, diagram_id=diagram_id, image_id=image_id)
            seen_assembly_keys.add(asm_key)
            assemblies_payload.append(
                {
                    "assembly_key": asm_key,
                    "title": diagram_name,
                    "path": [*model_nav, diagram_name],
                    "source_payload": {
                        "source": "yamaha_us",
                        "product_slug": product_slug,
                        "top_id": top_id,
                        "category_id": category_id,
                        "model_id": model_id,
                        "diagram_id": diagram_id,
                        "image_id": image_id,
                        "catalog_id": detail.get("catalogId"),
                    },
                    "model_id": model_id,
                    "image_id": image_id,
                }
            )

    variant_id = _upsert_variant(
        {
            "variant_key": vkey,
            "model_name": model_name,
            "source_designation": model_name,
            "year_from": parse_year(top_name) or parse_year(model_name),
            "variant_section": category_name,
            "browse_line": product_name,
            "path_json": model_nav,
            "assembly_count": len(assemblies_payload),
            "source_payload": {
                "source": "yamaha_us",
                "product_slug": product_slug,
                "top_id": top_id,
                "top_name": top_name,
                "category_id": category_id,
                "category_name": category_name,
                "model_id": model_id,
                "model_detail": detail,
            },
        }
    )
    stats["variants"] += 1

    for asm in assemblies_payload:
        assembly_id, created = _upsert_assembly(
            variant_id=variant_id,
            assembly_key_value=asm["assembly_key"],
            title=asm["title"],
            path=asm["path"],
            source_payload=asm["source_payload"],
        )
        if created:
            stats["assemblies_new"] += 1
        else:
            stats["assemblies_upd"] += 1
        pipeline.submit(assembly_id, asm["model_id"], asm["image_id"])

    progress.advance(f"{product_slug} {model_name} assemblies={len(assemblies_payload)}")
    return vkey


def _walk_scope(
    *,
    product: dict[str, str],
    top_row: dict[str, Any],
    category_row: dict[str, Any] | None,
    pipeline: _JsonPipeline,
    stats: dict[str, int],
    progress: ProgressReporter,
    resume: bool,
    mark_obsolete: bool,
    limit_models: int | None,
    limit_diagrams: int | None,
    models_budget: list[int | None],
) -> None:
    product_slug = product["slug"]
    product_name = product["name"]
    top_id = str(top_row["id"])
    top_name = clean_label(top_row.get("name"))
    category_id = str(category_row["id"]) if category_row else None
    category_name = clean_label(category_row.get("name")) if category_row else None
    scope = _scope_key(product_slug, top_id, category_id)

    if resume:
        cp = _checkpoint_get(scope)
        if cp and cp.get("status") == "ok" and not (cp.get("payload") or {}).get("truncated"):
            print(f"[yamaha-us-walk] skip done scope {scope}", flush=True)
            return

    path = nav_path(
        product_name=product_name,
        top_name=top_name,
        category_name=category_name,
    )
    rel = "subcategory" if category_row else "year"
    _upsert_nav(rel=rel, title=path[-1], path=path, parent_path=path[:-1])

    print(f"[yamaha-us-walk] scope {scope} ({' / '.join(path)})", flush=True)
    _checkpoint_set(scope, "running", {"path": path})

    try:
        models = browse_models(
            product_slug=product_slug,
            top_id=top_id,
            category_id=category_id,
        )
    except YamahaUsApiError as exc:
        stats["errors"] += 1
        print(f"[yamaha-us-walk] browse models FAIL {scope}: {exc}", flush=True)
        if mark_obsolete and exc.status == 404:
            n = _mark_obsolete_assemblies(
                product_slug=product_slug,
                top_id=top_id,
                category_id=category_id,
                keep_assembly_keys=set(),
                reason=f"browse models HTTP {exc.status}: gone from live API",
            )
            stats["obsolete"] += n
            print(f"[yamaha-us-walk] obsolete marked={n} for dead scope {scope}", flush=True)
        _checkpoint_set(scope, "error", {"error": str(exc)})
        return
    except Exception as exc:
        stats["errors"] += 1
        print(f"[yamaha-us-walk] browse models FAIL {scope}: {exc}", flush=True)
        _checkpoint_set(scope, "error", {"error": str(exc)})
        return

    if models_budget[0] is not None:
        left = models_budget[0]
        if left <= 0:
            _checkpoint_set(scope, "ok", {"models": 0, "truncated": True})
            return
        models = models[:left]
        models_budget[0] = left - len(models)
    elif limit_models is not None:
        # handled via models_budget when set; keep for clarity
        pass

    progress.add_total(len(models))
    progress.add_stage_total(len(models))

    seen_assembly_keys: set[str] = set()
    for model_row in models:
        _walk_model(
            product=product,
            top_row=top_row,
            category_row=category_row,
            model_row=model_row,
            limit_diagrams=limit_diagrams,
            force=pipeline.force,
            pipeline=pipeline,
            stats=stats,
            seen_assembly_keys=seen_assembly_keys,
            progress=progress,
        )

    pipeline.flush()

    # Only mark obsolete after a FULL scope walk — limits would wipe siblings.
    if mark_obsolete and limit_models is None and limit_diagrams is None:
        n = _mark_obsolete_assemblies(
            product_slug=product_slug,
            top_id=top_id,
            category_id=category_id,
            keep_assembly_keys=seen_assembly_keys,
            reason="assembly not in live browse for this scope",
        )
        stats["obsolete"] += n
        if n:
            print(f"[yamaha-us-walk] obsolete in scope {scope}: {n}", flush=True)

    _checkpoint_set(
        scope,
        "ok",
        {
            "models": len(models),
            "assemblies_seen": len(seen_assembly_keys),
            "truncated": limit_models is not None or limit_diagrams is not None,
        },
    )


def _product_has_open_scopes(product_slug: str) -> bool:
    """True if any year/category scope under this product is not fully done."""
    with get_yamaha_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT 1
                FROM oem_crawl_checkpoints
                WHERE phase = %s
                  AND item_key LIKE %s
                  AND status IS DISTINCT FROM 'ok'
                LIMIT 1
                """,
                (WALK_PHASE, f"{product_slug}:%"),
            )
            return cur.fetchone() is not None


def _walk_product(
    *,
    product: dict[str, str],
    pipeline: _JsonPipeline,
    stats: dict[str, int],
    progress: ProgressReporter,
    resume: bool,
    mark_obsolete: bool,
    limit_models: int | None,
    limit_diagrams: int | None,
) -> None:
    product_slug = product["slug"]
    product_name = product["name"]
    if resume:
        cp = _checkpoint_get(product_slug)
        if (
            cp
            and cp.get("status") == "ok"
            and not (cp.get("payload") or {}).get("truncated")
            and not _product_has_open_scopes(product_slug)
        ):
            print(f"[yamaha-us-walk] skip done product {product_slug}", flush=True)
            return
        if cp and cp.get("status") == "ok" and _product_has_open_scopes(product_slug):
            print(
                f"[yamaha-us-walk] product={product_slug} marked ok but has open scopes — resuming",
                flush=True,
            )

    print(f"[yamaha-us-walk] product={product_slug}", flush=True)
    _checkpoint_set(product_slug, "running", {})
    _upsert_nav(rel="product", title=product_name, path=[product_name], parent_path=None)

    try:
        years = browse_years(product_slug=product_slug)
    except Exception as exc:
        stats["errors"] += 1
        print(f"[yamaha-us-walk] browse years FAIL {product_slug}: {exc}", flush=True)
        _checkpoint_set(product_slug, "error", {"error": str(exc)})
        return

    live_top_ids = {str(y["id"]) for y in years}
    models_budget: list[int | None] = [limit_models]
    scope_failures = 0

    for top_row in years:
        top_id = str(top_row["id"])
        top_name = clean_label(top_row.get("name"))
        top_path = [product_name, top_name]
        _upsert_nav(rel="year" if product_slug != OUTBOARD_SLUG else "category", title=top_name, path=top_path, parent_path=[product_name])

        if product_slug == OUTBOARD_SLUG:
            try:
                categories = browse_categories(top_id=top_id)
            except Exception as exc:
                stats["errors"] += 1
                scope_failures += 1
                print(f"[yamaha-us-walk] browse categories FAIL top={top_id}: {exc}", flush=True)
                continue
            live_cat_ids = {str(c["id"]) for c in categories}
            for category_row in categories:
                if models_budget[0] is not None and models_budget[0] <= 0:
                    break
                before_errors = stats["errors"]
                _walk_scope(
                    product=product,
                    top_row=top_row,
                    category_row=category_row,
                    pipeline=pipeline,
                    stats=stats,
                    progress=progress,
                    resume=resume,
                    mark_obsolete=mark_obsolete,
                    limit_models=limit_models,
                    limit_diagrams=limit_diagrams,
                    models_budget=models_budget,
                )
                if stats["errors"] > before_errors:
                    scope_failures += 1
            if mark_obsolete and limit_models is None and limit_diagrams is None:
                n = _mark_obsolete_missing_categories(
                    product_slug=product_slug,
                    top_id=top_id,
                    live_category_ids=live_cat_ids,
                )
                stats["obsolete"] += n
                if n:
                    print(
                        f"[yamaha-us-walk] obsolete missing categories top={top_id}: {n}",
                        flush=True,
                    )
        else:
            if models_budget[0] is not None and models_budget[0] <= 0:
                break
            before_errors = stats["errors"]
            _walk_scope(
                product=product,
                top_row=top_row,
                category_row=None,
                pipeline=pipeline,
                stats=stats,
                progress=progress,
                resume=resume,
                mark_obsolete=mark_obsolete,
                limit_models=limit_models,
                limit_diagrams=limit_diagrams,
                models_budget=models_budget,
            )
            if stats["errors"] > before_errors:
                scope_failures += 1

    if mark_obsolete and limit_models is None and limit_diagrams is None and scope_failures == 0:
        n = _mark_obsolete_missing_tops(product_slug=product_slug, live_top_ids=live_top_ids)
        stats["obsolete"] += n
        if n:
            print(f"[yamaha-us-walk] obsolete missing tops product={product_slug}: {n}", flush=True)

    if scope_failures or _product_has_open_scopes(product_slug):
        _checkpoint_set(
            product_slug,
            "error",
            {
                "tops": len(years),
                "scope_failures": scope_failures,
                "error": "one or more scopes incomplete",
            },
        )
        print(
            f"[yamaha-us-walk] product={product_slug} incomplete scope_failures={scope_failures}",
            flush=True,
        )
    else:
        _checkpoint_set(
            product_slug,
            "ok",
            {
                "tops": len(years),
                "truncated": limit_models is not None or limit_diagrams is not None,
            },
        )


def walk_catalog(
    *,
    product_slug: str = "all",
    concurrency: int = 100,
    api_concurrency: int | None = None,
    force: bool = False,
    resume: bool = True,
    mark_obsolete: bool = True,
    limit_models: int | None = None,
    limit_diagrams: int | None = None,
) -> dict[str, Any]:
    """Browse live API → upsert structure to PG → fetch diagram JSON. PNG is separate."""
    products = _select_products(product_slug)
    api_limit = configure_diagram_api_concurrency(api_concurrency)
    print(
        f"[yamaha-us-walk] products={len(products)} workers={concurrency} "
        f"api_in_flight_limit={api_limit} force={force} resume={resume} "
        f"obsolete={mark_obsolete} turbo",
        flush=True,
    )
    if not resume:
        cleared = _clear_walk_checkpoints(product_slug=product_slug)
        print(f"[yamaha-us-walk] cleared checkpoints={cleared}", flush=True)

    stats: dict[str, int] = {
        "variants": 0,
        "assemblies_new": 0,
        "assemblies_upd": 0,
        "json_ok": 0,
        "json_error": 0,
        "json_skip": 0,
        "obsolete": 0,
        "errors": 0,
    }
    stats_lock = threading.Lock()
    progress = ProgressReporter(total=1, label="yamaha-us-walk")
    progress.enable_thread_safe()
    started = time.monotonic()

    pipeline = _JsonPipeline(
        concurrency=concurrency,
        force=force,
        stats=stats,
        stats_lock=stats_lock,
    )
    try:
        for product in products:
            _walk_product(
                product=product,
                pipeline=pipeline,
                stats=stats,
                progress=progress,
                resume=resume,
                mark_obsolete=mark_obsolete,
                limit_models=limit_models,
                limit_diagrams=limit_diagrams,
            )
    finally:
        pipeline.close()

    elapsed = time.monotonic() - started
    progress.finish(f"walk stats={stats} elapsed={elapsed:.1f}s")
    return {
        "stats": stats,
        "elapsed_sec": round(elapsed, 1),
        "concurrency": concurrency,
        "api_concurrency": api_limit,
        "product_slug": product_slug,
        "force": force,
        "resume": resume,
        "mark_obsolete": mark_obsolete,
    }


__all__ = ["walk_catalog", "WALK_PHASE"]
