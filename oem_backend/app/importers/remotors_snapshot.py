"""Remotors API catalog snapshot (SQLite) for offline compare and selective sync."""

from __future__ import annotations

import json
import sqlite3
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TextIO

from app.importers.remotors import (
    AriNode,
    _clean_text,
    _client,
    _list_children,
    _parse_model_title,
    _parse_variant_section,
    _parse_year,
)
from app.importers.remotors_catalog import (
    DEPRECATED_ARIB_CODES,
    HIDDEN_CANONICAL_BRANDS,
    assembly_compare_key,
    canonical_brand_and_type,
    crawl_arib_for_brand,
)
from app.normalization import normalize_text

SCHEMA_VERSION = "2"


def key_to_str(key: tuple[Any, ...] | list[Any]) -> str:
    return json.dumps(list(key), ensure_ascii=False, separators=(",", ":"))


@dataclass
class RepairRoot:
    arib: str
    aria: str
    slug: str | None
    title: str
    path: list[str]


class SnapshotStore:
    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.conn = sqlite3.connect(self.path)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA journal_mode=WAL")
        self.conn.execute("PRAGMA synchronous=NORMAL")
        self._init_schema()
        self._node_batch: list[tuple[Any, ...]] = []
        self._assembly_batch: list[tuple[Any, ...]] = []
        self._batch_size = 500
        self._finalized = False

    def _init_schema(self) -> None:
        self.conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS meta (
              key TEXT PRIMARY KEY,
              value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS api_nodes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_api_nodes_arib_aria ON api_nodes(arib, aria);
            CREATE TABLE IF NOT EXISTS catalog_assemblies (
              assembly_key TEXT PRIMARY KEY,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              title TEXT NOT NULL,
              path_json TEXT NOT NULL,
              brand_normalized TEXT NOT NULL,
              brand_name TEXT NOT NULL,
              vehicle_type TEXT NOT NULL,
              model_key TEXT NOT NULL,
              variant_key TEXT NOT NULL,
              repair_arib TEXT NOT NULL,
              repair_aria TEXT NOT NULL,
              repair_slug TEXT,
              repair_title TEXT NOT NULL,
              repair_path_json TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_catalog_assemblies_variant ON catalog_assemblies(variant_key);
            CREATE TABLE IF NOT EXISTS catalog_variants (
              variant_key TEXT PRIMARY KEY,
              brand_normalized TEXT NOT NULL,
              brand_name TEXT NOT NULL,
              vehicle_type TEXT NOT NULL,
              model_name TEXT NOT NULL,
              year_from INTEGER,
              source_designation TEXT,
              variant_section TEXT,
              repair_arib TEXT NOT NULL,
              repair_aria TEXT NOT NULL,
              repair_slug TEXT,
              repair_title TEXT NOT NULL,
              repair_path_json TEXT NOT NULL,
              assembly_count INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS scan_errors (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL,
              error TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'pending',
              attempts INTEGER NOT NULL DEFAULT 1,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_scan_errors_status ON scan_errors(status);
            """
        )
        self._ensure_catalog_models_table()
        self.conn.commit()

    def _ensure_catalog_models_table(self) -> None:
        row = self.conn.execute(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='catalog_models'"
        ).fetchone()
        if row is None:
            self.conn.execute(
                """
                CREATE TABLE catalog_models (
                  brand_normalized TEXT NOT NULL,
                  model_key TEXT NOT NULL,
                  brand_name TEXT NOT NULL,
                  vehicle_type TEXT NOT NULL,
                  model_name TEXT NOT NULL,
                  variant_count INTEGER NOT NULL DEFAULT 0,
                  assembly_count INTEGER NOT NULL DEFAULT 0,
                  PRIMARY KEY (brand_normalized, model_key)
                )
                """
            )
            return
        sql = row["sql"] or ""
        if "PRIMARY KEY (brand_normalized, model_key)" not in sql.replace("\n", " "):
            self.conn.execute("DROP TABLE IF EXISTS catalog_models")
            self.conn.execute(
                """
                CREATE TABLE catalog_models (
                  brand_normalized TEXT NOT NULL,
                  model_key TEXT NOT NULL,
                  brand_name TEXT NOT NULL,
                  vehicle_type TEXT NOT NULL,
                  model_name TEXT NOT NULL,
                  variant_count INTEGER NOT NULL DEFAULT 0,
                  assembly_count INTEGER NOT NULL DEFAULT 0,
                  PRIMARY KEY (brand_normalized, model_key)
                )
                """
            )

    def get_meta(self, key: str, default: Any = None) -> Any:
        row = self.conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
        if row is None:
            return default
        try:
            return json.loads(row["value"])
        except json.JSONDecodeError:
            return row["value"]

    def set_meta(self, **values: Any) -> None:
        for key, value in values.items():
            self.conn.execute(
                "INSERT INTO meta(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (key, json.dumps(value, ensure_ascii=False) if not isinstance(value, str) else value),
            )
        self.conn.commit()

    def completed_aribs(self) -> set[str]:
        value = self.get_meta("completed_arib", [])
        return set(value) if isinstance(value, list) else set()

    def mark_arib_completed(self, arib: str) -> None:
        done = sorted(self.completed_aribs() | {arib})
        self.set_meta(completed_arib=done, schema_version=SCHEMA_VERSION)
        self.conn.execute("PRAGMA wal_checkpoint(PASSIVE)")

    def record_scan_error(self, node: AriNode, error: str) -> None:
        now = datetime.now(timezone.utc).isoformat()
        existing = self.conn.execute(
            """
            SELECT id, attempts FROM scan_errors
            WHERE status = 'pending' AND arib = ? AND aria IS ? AND path_json = ?
            """,
            (node.arib, node.aria, json.dumps(node.path, ensure_ascii=False)),
        ).fetchone()
        if existing:
            self.conn.execute(
                """
                UPDATE scan_errors SET error = ?, attempts = ?, updated_at = ?
                WHERE id = ?
                """,
                (error, int(existing["attempts"]) + 1, now, existing["id"]),
            )
        else:
            self.conn.execute(
                """
                INSERT INTO scan_errors(
                  arib, aria, slug, rel, title, depth, path_json, error, status, attempts, updated_at
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?)
                """,
                (
                    node.arib,
                    node.aria,
                    node.slug,
                    node.rel or "",
                    node.title,
                    node.depth,
                    json.dumps(node.path, ensure_ascii=False),
                    error,
                    now,
                ),
            )

    def pending_errors(self) -> list[AriNode]:
        rows = self.conn.execute(
            "SELECT * FROM scan_errors WHERE status = 'pending' ORDER BY id"
        ).fetchall()
        nodes: list[AriNode] = []
        for row in rows:
            nodes.append(
                AriNode(
                    title=row["title"],
                    arib=row["arib"],
                    aria=row["aria"],
                    rel=row["rel"] or "",
                    slug=row["slug"],
                    parent_id=None,
                    depth=int(row["depth"]),
                    path=json.loads(row["path_json"]),
                )
            )
        return nodes

    def resolve_scan_error(self, node: AriNode) -> None:
        self.conn.execute(
            """
            UPDATE scan_errors SET status = 'ok', updated_at = ?
            WHERE status = 'pending' AND arib = ? AND aria IS ? AND path_json = ?
            """,
            (
                datetime.now(timezone.utc).isoformat(),
                node.arib,
                node.aria,
                json.dumps(node.path, ensure_ascii=False),
            ),
        )

    def record_node(self, node: AriNode) -> None:
        self._node_batch.append(
            (
                node.arib,
                node.aria,
                node.slug,
                node.rel or "",
                node.title,
                node.depth,
                json.dumps(node.path, ensure_ascii=False),
            )
        )
        if len(self._node_batch) >= self._batch_size:
            self._flush_nodes()

    def record_assembly(
        self,
        node: AriNode,
        *,
        brand_normalized: str,
        brand_name: str,
        vehicle_type: str,
        model_key: tuple[Any, ...],
        variant_key: tuple[Any, ...],
        repair: RepairRoot,
    ) -> None:
        assembly_key = assembly_compare_key(arib=node.arib, aria=node.aria, slug=node.slug, path=node.path)
        if assembly_key is None:
            return
        model_name = _parse_model_title(node.path)
        year = _parse_year(node.path)
        source_designation = node.path[-2] if len(node.path) >= 2 else model_name
        variant_section = _parse_variant_section(node.path)
        self._assembly_batch.append(
            (
                assembly_key,
                node.arib,
                node.aria,
                node.slug,
                node.title,
                json.dumps(node.path, ensure_ascii=False),
                brand_normalized,
                brand_name,
                vehicle_type,
                key_to_str(model_key),
                key_to_str(variant_key),
                repair.arib,
                repair.aria,
                repair.slug,
                repair.title,
                json.dumps(repair.path, ensure_ascii=False),
            )
        )
        self.conn.execute(
            """
            INSERT INTO catalog_variants(
              variant_key, brand_normalized, brand_name, vehicle_type, model_name,
              year_from, source_designation, variant_section,
              repair_arib, repair_aria, repair_slug, repair_title, repair_path_json,
              assembly_count
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON CONFLICT(variant_key) DO UPDATE SET
              assembly_count = assembly_count + 1,
              repair_arib = excluded.repair_arib,
              repair_aria = excluded.repair_aria,
              repair_slug = excluded.repair_slug,
              repair_title = excluded.repair_title,
              repair_path_json = excluded.repair_path_json
            """,
            (
                key_to_str(variant_key),
                brand_normalized,
                brand_name,
                vehicle_type,
                model_name,
                year,
                source_designation,
                variant_section,
                repair.arib,
                repair.aria,
                repair.slug,
                repair.title,
                json.dumps(repair.path, ensure_ascii=False),
            ),
        )
        if len(self._assembly_batch) >= self._batch_size:
            self._flush_assemblies()

    def _flush_nodes(self) -> None:
        if not self._node_batch:
            return
        self.conn.executemany(
            """
            INSERT INTO api_nodes(arib, aria, slug, rel, title, depth, path_json)
            VALUES(?, ?, ?, ?, ?, ?, ?)
            """,
            self._node_batch,
        )
        self._node_batch.clear()
        self.conn.commit()

    def _flush_assemblies(self) -> None:
        if not self._assembly_batch:
            return
        self.conn.executemany(
            """
            INSERT OR IGNORE INTO catalog_assemblies(
              assembly_key, arib, aria, slug, title, path_json,
              brand_normalized, brand_name, vehicle_type, model_key, variant_key,
              repair_arib, repair_aria, repair_slug, repair_title, repair_path_json
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            self._assembly_batch,
        )
        self._assembly_batch.clear()
        self.conn.commit()

    def finalize_aggregates(self) -> None:
        self._flush_nodes()
        self._flush_assemblies()
        self.conn.execute("DELETE FROM catalog_models")
        self.conn.execute(
            """
            INSERT INTO catalog_models(
              brand_normalized, model_key, brand_name, vehicle_type, model_name,
              variant_count, assembly_count
            )
            SELECT
              ca.brand_normalized,
              ca.model_key,
              ca.brand_name,
              ca.vehicle_type,
              MIN(cv.model_name),
              COUNT(DISTINCT ca.variant_key),
              COUNT(*)
            FROM catalog_assemblies ca
            JOIN catalog_variants cv ON cv.variant_key = ca.variant_key
            GROUP BY ca.brand_normalized, ca.model_key, ca.brand_name, ca.vehicle_type
            """
        )
        self.conn.commit()
        self.set_meta(scan_status="complete", finalized_at=datetime.now(timezone.utc).isoformat())
        self._finalized = True

    def close(self, *, finalize: bool = False) -> None:
        if finalize and not self._finalized:
            self.finalize_aggregates()
        self._flush_nodes()
        self._flush_assemblies()
        self.conn.commit()
        self.conn.close()

    def counts(self) -> dict[str, int]:
        out: dict[str, int] = {}
        for table in ("api_nodes", "catalog_assemblies", "catalog_variants", "catalog_models", "scan_errors"):
            row = self.conn.execute(f"SELECT COUNT(*) AS c FROM {table}").fetchone()
            out[table] = int(row["c"]) if row else 0
        pending = self.conn.execute(
            "SELECT COUNT(*) AS c FROM scan_errors WHERE status = 'pending'"
        ).fetchone()
        out["scan_errors_pending"] = int(pending["c"]) if pending else 0
        return out


def _format_duration(seconds: float) -> str:
    seconds = max(0, int(seconds))
    minutes, sec = divmod(seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours:02d}:{minutes:02d}:{sec:02d}"
    return f"{minutes:02d}:{sec:02d}"


def _log_line(log_fp: TextIO | None, message: str) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, flush=True)
    if log_fp is not None:
        log_fp.write(line + "\n")
        log_fp.flush()


def _repair_root_from(node: AriNode) -> RepairRoot:
    crawl_arib = crawl_arib_for_brand("", node.arib) or node.arib
    return RepairRoot(
        arib=crawl_arib,
        aria=node.aria or "",
        slug=node.slug,
        title=node.title,
        path=list(node.path),
    )


def _catalog_keys_from_assembly(node: AriNode) -> tuple[str, str, tuple[Any, ...], tuple[Any, ...], str] | None:
    model_name = _parse_model_title(node.path)
    source_designation = node.path[-2] if len(node.path) >= 2 else model_name
    extra = f"{model_name} {source_designation}"
    brand_name, vehicle_type = canonical_brand_and_type(node.arib, node.path, extra_text=extra)
    brand_normalized = normalize_text(brand_name)
    year = _parse_year(node.path)
    variant_section = _parse_variant_section(node.path)
    model_key = (vehicle_type, normalize_text(model_name))
    variant_key = (
        vehicle_type,
        normalize_text(model_name),
        year,
        normalize_text(source_designation or ""),
        normalize_text(variant_section or ""),
    )
    assembly_key = assembly_compare_key(arib=node.arib, aria=node.aria, slug=node.slug, path=node.path)
    if assembly_key is None:
        return None
    return brand_normalized, brand_name, model_key, variant_key, assembly_key


def _process_children(
    *,
    store: SnapshotStore,
    allowed_brands: set[str],
    node: AriNode,
    children: list[dict[str, Any]],
    path_roots: dict[tuple[str, ...], RepairRoot],
    queue: list[AriNode],
    stats: dict[str, int],
) -> None:
    repair = _repair_root_from(node)
    for child in children:
        attr = child.get("attr") or {}
        title = _clean_text(child.get("data"))
        rel = attr.get("rel") or ""
        child_node = AriNode(
            title=title,
            arib=attr.get("arib") or node.arib,
            aria=attr.get("aria"),
            rel=rel,
            slug=attr.get("slug") or None,
            parent_id=None,
            depth=node.depth + 1,
            path=[*node.path, title],
        )
        if rel == "assembly":
            store.record_node(child_node)
            parsed = _catalog_keys_from_assembly(child_node)
            if parsed is not None:
                brand_normalized, brand_name, model_key, variant_key, _assembly_key = parsed
                if brand_normalized in allowed_brands:
                    designation_title = normalize_text(_parse_model_title(child_node.path))
                    variant_repair = repair
                    best_len = -1
                    for path_key, root in path_roots.items():
                        if len(path_key) > len(child_node.path):
                            continue
                        if tuple(child_node.path[: len(path_key)]) != path_key:
                            continue
                        if normalize_text(root.title) != designation_title:
                            continue
                        if len(path_key) > best_len:
                            best_len = len(path_key)
                            variant_repair = root
                    store.record_assembly(
                        child_node,
                        brand_normalized=brand_normalized,
                        brand_name=brand_name,
                        vehicle_type=model_key[0],
                        model_key=model_key,
                        variant_key=variant_key,
                        repair=variant_repair,
                    )
                    stats["assemblies"] += 1
        else:
            queue.append(child_node)


def _walk_queue(
    *,
    store: SnapshotStore,
    client: Any,
    queue: list[AriNode],
    allowed_brands: set[str],
    arib: str,
    stats: dict[str, int],
    log_fp: TextIO | None,
    started_mono: float,
    last_log: float,
) -> float:
    path_roots: dict[tuple[str, ...], RepairRoot] = {}
    while queue:
        node = queue.pop(0)
        stats["nodes"] += 1
        store.record_node(node)

        if node.rel == "assembly":
            continue

        path_roots[tuple(node.path)] = _repair_root_from(node)

        try:
            children = _list_children(client, node.arib, node.aria)
            stats["api_calls"] += 1
        except Exception as exc:
            stats["errors"] += 1
            store.record_scan_error(node, str(exc))
            _log_line(log_fp, f"ERROR node {node.arib} / {' / '.join(node.path)}: {exc}")
            continue

        _process_children(
            store=store,
            allowed_brands=allowed_brands,
            node=node,
            children=children,
            path_roots=path_roots,
            queue=queue,
            stats=stats,
        )

        now = time.monotonic()
        if stats["nodes"] % 250 == 0 or (now - last_log) >= 15:
            last_log = now
            _log_line(
                log_fp,
                f"progress arib={arib} nodes={stats['nodes']} assemblies={stats['assemblies']} "
                f"queue={len(queue)} api={stats['api_calls']} errors={stats['errors']} "
                f"elapsed={_format_duration(now - started_mono)}",
            )
    return last_log


def _retry_pending_errors(
    *,
    store: SnapshotStore,
    client: Any,
    allowed_brands: set[str],
    log_fp: TextIO | None,
    stats: dict[str, int],
    started_mono: float,
) -> None:
    pending = store.pending_errors()
    if not pending:
        return
    _log_line(log_fp, f"retry pending scan errors count={len(pending)}")
    last_log = time.monotonic()
    for node in pending:
        _log_line(log_fp, f"retry node {node.arib} / {' / '.join(node.path)}")
        path_roots: dict[tuple[str, ...], RepairRoot] = {tuple(node.path): _repair_root_from(node)}
        queue: list[AriNode] = []
        try:
            children = _list_children(client, node.arib, node.aria)
            stats["api_calls"] += 1
        except Exception as exc:
            stats["errors"] += 1
            store.record_scan_error(node, str(exc))
            _log_line(log_fp, f"ERROR retry failed {node.arib} / {' / '.join(node.path)}: {exc}")
            continue

        stats["nodes"] += 1
        store.record_node(node)
        _process_children(
            store=store,
            allowed_brands=allowed_brands,
            node=node,
            children=children,
            path_roots=path_roots,
            queue=queue,
            stats=stats,
        )
        last_log = _walk_queue(
            store=store,
            client=client,
            queue=queue,
            allowed_brands=allowed_brands,
            arib=node.arib,
            stats=stats,
            log_fp=log_fp,
            started_mono=started_mono,
            last_log=last_log,
        )
        store.resolve_scan_error(node)
        _log_line(log_fp, f"retry ok {node.arib} / {' / '.join(node.path)}")


def scan_to_snapshot(
    *,
    snapshot_path: str,
    arib_codes: list[str],
    allowed_brands: set[str],
    log_fp: TextIO | None = None,
    resume: bool = False,
    finalize_only: bool = False,
) -> dict[str, Any]:
    """Walk Remotors GetAssembly tree and persist all nodes + catalog keys to SQLite."""
    started = time.time()
    started_mono = time.monotonic()
    store = SnapshotStore(snapshot_path)

    if finalize_only:
        counts = store.counts()
        if counts["catalog_assemblies"] == 0:
            store.close(finalize=False)
            raise RuntimeError(
                f"snapshot {snapshot_path} has no assemblies — scan data missing "
                "(likely rolled back after a previous finalize crash; re-run snapshot or resume)"
            )
        _log_line(log_fp, "finalize only — rebuilding catalog_models from existing assemblies")
        store.finalize_aggregates()
        counts = store.counts()
        payload = {
            "snapshot_path": str(store.path),
            "mode": "finalize_only",
            "duration_seconds": round(time.time() - started, 1),
            "counts": counts,
        }
        _log_line(log_fp, f"snapshot finalized {store.path} counts={counts}")
        store.close(finalize=False)
        return payload

    completed = store.completed_aribs() if resume else set()
    if resume:
        counts = store.counts()
        if not completed:
            stored_codes = store.get_meta("arib_codes", arib_codes)
            if counts["catalog_assemblies"] > 0 and isinstance(stored_codes, list) and stored_codes:
                completed = set(stored_codes)
                store.set_meta(completed_arib=sorted(completed))
                _log_line(
                    log_fp,
                    f"resume inferred completed_arib={','.join(sorted(completed))} "
                    f"(legacy snapshot, assemblies={counts['catalog_assemblies']})",
                )
            elif counts["catalog_assemblies"] == 0 and stored_codes:
                _log_line(
                    log_fp,
                    "WARNING resume: meta present but assemblies=0 — previous run likely rolled back; full re-scan",
                )
                completed = set()
        elif counts["catalog_assemblies"] == 0:
            _log_line(log_fp, "WARNING resume: completed_arib set but assemblies=0 — full re-scan")
            completed = set()
        _log_line(log_fp, f"resume snapshot completed_arib={','.join(sorted(completed)) or '(none)'}")
    else:
        store.set_meta(
            schema_version=SCHEMA_VERSION,
            scanned_at=datetime.now(timezone.utc).isoformat(),
            arib_codes=arib_codes,
            allowed_brands=sorted(allowed_brands),
            completed_arib=[],
            scan_status="partial",
        )

    stats = {"nodes": 0, "assemblies": 0, "errors": 0, "api_calls": 0}
    last_log = started_mono

    try:
        with _client() as client:
            for arib in arib_codes:
                if resume and arib in completed:
                    _log_line(log_fp, f"snapshot skip completed arib={arib}")
                    continue

                _log_line(log_fp, f"snapshot scan start arib={arib}")
                try:
                    children = _list_children(client, arib)
                    stats["api_calls"] += 1
                except Exception as exc:
                    stats["errors"] += 1
                    _log_line(log_fp, f"ERROR brand children arib={arib}: {exc}")
                    continue

                queue: list[AriNode] = []
                for child in children:
                    attr = child.get("attr") or {}
                    queue.append(
                        AriNode(
                            title=_clean_text(child.get("data")),
                            arib=attr.get("arib") or arib,
                            aria=attr.get("aria"),
                            rel=attr.get("rel") or "",
                            slug=attr.get("slug") or None,
                            parent_id=None,
                            depth=1,
                            path=[_clean_text(child.get("data"))],
                        )
                    )

                _log_line(log_fp, f"snapshot queued arib={arib} children={len(children)} queue={len(queue)}")
                last_log = _walk_queue(
                    store=store,
                    client=client,
                    queue=queue,
                    allowed_brands=allowed_brands,
                    arib=arib,
                    stats=stats,
                    log_fp=log_fp,
                    started_mono=started_mono,
                    last_log=last_log,
                )
                store.mark_arib_completed(arib)
                _log_line(
                    log_fp,
                    f"snapshot done arib={arib} total_nodes={stats['nodes']} assemblies={stats['assemblies']}",
                )

            _retry_pending_errors(
                store=store,
                client=client,
                allowed_brands=allowed_brands,
                log_fp=log_fp,
                stats=stats,
                started_mono=started_mono,
            )

        store.finalize_aggregates()
        counts = store.counts()
        payload = {
            "snapshot_path": str(store.path),
            "mode": "resume" if resume else "full",
            "duration_seconds": round(time.time() - started, 1),
            "scan_stats": stats,
            "counts": counts,
            "completed_arib": sorted(store.completed_aribs()),
        }
        _log_line(log_fp, f"snapshot written {store.path} counts={counts}")
        store.close(finalize=False)
        return payload
    except Exception:
        store.close(finalize=False)
        raise


def _local_brands() -> list[dict[str, Any]]:
    from app.db import get_conn

    hidden = tuple(sorted(HIDDEN_CANONICAL_BRANDS))
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT b.normalized_name
                FROM oem_brands b
                WHERE b.normalized_name <> ALL(%s)
                  AND EXISTS (SELECT 1 FROM oem_model_families mf WHERE mf.brand_id = b.id)
                ORDER BY b.name
                """,
                (list(hidden),),
            )
            return list(cur.fetchall())


def _ari_codes_for_brands(brand_normalized: set[str]) -> list[str]:
    codes = {crawl_arib_for_brand(name) for name in brand_normalized}
    codes.discard("")
    return sorted(code for code in codes if code not in DEPRECATED_ARIB_CODES)


def run_snapshot_catalog(
    *,
    snapshot_path: str,
    log_path: str | None = None,
    resume: bool = False,
    finalize_only: bool = False,
    retry_node_file: str | None = None,
) -> dict[str, Any]:
    """Scan Remotors API and write SQLite snapshot (read-only vs PostgreSQL)."""
    brands = _local_brands()
    allowed = {row["normalized_name"] for row in brands}
    arib_codes = _ari_codes_for_brands(allowed)

    log_fp: TextIO | None = None
    if log_path:
        path = Path(log_path)
        path.parent.mkdir(parents=True, exist_ok=True)
        log_fp = path.open("a", encoding="utf-8")

    try:
        mode = "finalize_only" if finalize_only else ("resume" if resume else "full")
        _log_line(log_fp, f"snapshot catalog start mode={mode} brands={len(brands)} arib={','.join(arib_codes)}")

        if retry_node_file and not finalize_only:
            _inject_retry_nodes(snapshot_path, retry_node_file, log_fp)

        return scan_to_snapshot(
            snapshot_path=snapshot_path,
            arib_codes=arib_codes,
            allowed_brands=allowed,
            log_fp=log_fp,
            resume=True if retry_node_file else resume,
            finalize_only=finalize_only,
        )
    finally:
        if log_fp is not None:
            log_fp.close()


def _inject_retry_nodes(snapshot_path: str, retry_node_file: str, log_fp: TextIO | None) -> None:
    """Load nodes from JSON and queue them as pending scan_errors."""
    payload = json.loads(Path(retry_node_file).read_text(encoding="utf-8"))
    nodes = payload if isinstance(payload, list) else payload.get("nodes", [])
    store = SnapshotStore(snapshot_path)
    try:
        for item in nodes:
            node = AriNode(
                title=item["title"],
                arib=item["arib"],
                aria=item.get("aria"),
                rel=item.get("rel") or "",
                slug=item.get("slug"),
                parent_id=None,
                depth=int(item.get("depth") or len(item.get("path") or [])),
                path=list(item.get("path") or []),
            )
            store.record_scan_error(node, item.get("error") or "manual retry")
        store.conn.commit()
        _log_line(log_fp, f"queued {len(nodes)} nodes for retry from {retry_node_file}")
    finally:
        store.close(finalize=False)


def load_assembly_row(conn: sqlite3.Connection, assembly_key: str) -> dict[str, Any] | None:
    row = conn.execute(
        "SELECT * FROM catalog_assemblies WHERE assembly_key = ?",
        (assembly_key,),
    ).fetchone()
    return dict(row) if row else None


def load_assembly_for_gap(conn: sqlite3.Connection, item: dict[str, Any]) -> dict[str, Any] | None:
    """Resolve snapshot row: compare keys are arib:aria, stored PK is often arib:slug."""
    assembly_key = item.get("assembly_key")
    if assembly_key:
        row = load_assembly_row(conn, assembly_key)
        if row:
            return row
    arib = item.get("arib")
    aria = item.get("aria")
    slug = item.get("slug")
    if arib and aria:
        row = conn.execute(
            "SELECT * FROM catalog_assemblies WHERE arib = ? AND aria = ? LIMIT 1",
            (arib, aria),
        ).fetchone()
        if row:
            return dict(row)
    if arib and slug:
        row = conn.execute(
            "SELECT * FROM catalog_assemblies WHERE arib = ? AND slug = ? LIMIT 1",
            (arib, slug),
        ).fetchone()
        if row:
            return dict(row)
    return None


def gap_item_to_ari_node(item: dict[str, Any]) -> AriNode | None:
    slug = item.get("slug")
    path = item.get("path")
    if not slug or not path:
        return None
    return AriNode(
        title=item.get("title") or item.get("assembly_key") or "",
        arib=item.get("arib") or "BRP",
        aria=item.get("aria"),
        rel="assembly",
        slug=slug,
        parent_id=None,
        depth=len(path),
        path=list(path),
    )


def assembly_node_for_gap(conn: sqlite3.Connection | None, item: dict[str, Any]) -> AriNode | None:
    if conn is not None:
        row = load_assembly_for_gap(conn, item)
        if row and row.get("slug"):
            return assembly_to_ari_node(row)
    return gap_item_to_ari_node(item)


def load_variant_row(conn: sqlite3.Connection, variant_key: str) -> dict[str, Any] | None:
    row = conn.execute(
        "SELECT * FROM catalog_variants WHERE variant_key = ?",
        (variant_key,),
    ).fetchone()
    return dict(row) if row else None


def assembly_to_ari_node(row: dict[str, Any]) -> AriNode:
    path = json.loads(row["path_json"])
    return AriNode(
        title=row["title"],
        arib=row["arib"],
        aria=row.get("aria"),
        rel="assembly",
        slug=row.get("slug"),
        parent_id=None,
        depth=len(path),
        path=path,
    )


def variant_to_repair_root(row: dict[str, Any]) -> dict[str, Any]:
    return {
        "title": row["repair_title"],
        "arib": row["repair_arib"],
        "aria": row["repair_aria"],
        "slug": row.get("repair_slug"),
        "parent_id": None,
        "depth": len(json.loads(row["repair_path_json"])),
        "path": json.loads(row["repair_path_json"]),
    }


def open_snapshot(path: str | Path) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_catalog_assemblies_arib_aria ON catalog_assemblies(arib, aria)"
    )
    return conn
