from __future__ import annotations

import json
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.yamaha_v1.constants import BULK_BATCH_SIZE, SNAPSHOT_FLUSH_SIZE, SNAPSHOT_SCHEMA_VERSION


class SnapshotDatabaseError(RuntimeError):
    pass


def snapshot_sidecar_paths(path: str | Path) -> list[Path]:
    db_path = Path(path)
    return [
        db_path,
        Path(f"{db_path}-wal"),
        Path(f"{db_path}-shm"),
        Path(f"{db_path}-journal"),
    ]


def remove_snapshot_files(path: str | Path) -> None:
    for candidate in snapshot_sidecar_paths(path):
        if candidate.is_file():
            candidate.unlink()


def verify_snapshot_integrity(path: str | Path) -> None:
    db_path = Path(path)
    if not db_path.is_file():
        return
    conn = sqlite3.connect(db_path)
    try:
        row = conn.execute("PRAGMA integrity_check").fetchone()
    finally:
        conn.close()
    if row is None or str(row[0]).strip().lower() != "ok":
        detail = str(row[0]) if row else "unknown error"
        raise SnapshotDatabaseError(
            "Snapshot SQLite database is corrupted "
            f"({db_path}). Remove snapshot files and restart with --no-resume.\n"
            f"integrity_check: {detail[:500]}"
        )


class YamahaSnapshotStore:
    def __init__(self, path: str | Path, *, reset: bool = False) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        if reset and self.path.exists():
            remove_snapshot_files(self.path)
        elif self.path.exists():
            verify_snapshot_integrity(self.path)

        self.conn = sqlite3.connect(self.path, timeout=60.0)
        self.conn.row_factory = sqlite3.Row
        # DELETE + FULL is slower but much safer on Docker Desktop bind mounts (macOS).
        self.conn.execute("PRAGMA journal_mode=DELETE")
        self.conn.execute("PRAGMA synchronous=FULL")
        self.conn.execute("PRAGMA temp_store=MEMORY")
        self.conn.execute("PRAGMA busy_timeout=60000")
        self._nav_batch: list[tuple[Any, ...]] = []
        self._assembly_batch: list[tuple[Any, ...]] = []
        self._assembly_keys: set[str] = set()
        self._init_schema()
        self._load_assembly_keys()

    def _init_schema(self) -> None:
        self.conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS meta (
              key TEXT PRIMARY KEY,
              value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS catalog_variants (
              variant_key TEXT PRIMARY KEY,
              root_arib TEXT NOT NULL,
              model_name TEXT NOT NULL,
              source_designation TEXT,
              year_from INTEGER,
              variant_section TEXT,
              browse_line TEXT,
              path_json TEXT NOT NULL,
              source_payload TEXT NOT NULL,
              assembly_count INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS catalog_assemblies (
              variant_key TEXT NOT NULL,
              assembly_key TEXT NOT NULL,
              root_arib TEXT NOT NULL,
              title TEXT NOT NULL,
              path_json TEXT NOT NULL,
              source_payload TEXT NOT NULL,
              illust_url TEXT,
              PRIMARY KEY (variant_key, assembly_key)
            );
            CREATE INDEX IF NOT EXISTS idx_catalog_assemblies_root ON catalog_assemblies(root_arib);
            CREATE INDEX IF NOT EXISTS idx_catalog_assemblies_key ON catalog_assemblies(assembly_key);
            CREATE TABLE IF NOT EXISTS nav_nodes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              root_arib TEXT NOT NULL,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL,
              UNIQUE(root_arib, path_json, rel, title)
            );
            CREATE TABLE IF NOT EXISTS api_records (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              root_arib TEXT NOT NULL,
              endpoint TEXT NOT NULL,
              task_key TEXT,
              request_json TEXT NOT NULL,
              response_json TEXT NOT NULL,
              created_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_api_records_task ON api_records(task_key);
            CREATE TABLE IF NOT EXISTS scan_errors (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              root_arib TEXT NOT NULL,
              stage TEXT NOT NULL,
              context_json TEXT NOT NULL,
              error TEXT NOT NULL,
              created_at TEXT NOT NULL
            );
            """
        )
        self.conn.commit()

    def _load_assembly_keys(self) -> None:
        rows = self.conn.execute("SELECT assembly_key FROM catalog_assemblies").fetchall()
        self._assembly_keys = {str(row["assembly_key"]) for row in rows}

    def get_meta(self, key: str, default: Any = None) -> Any:
        row = self.conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
        if row is None:
            return default
        try:
            return json.loads(row["value"])
        except json.JSONDecodeError:
            return row["value"]

    def set_meta(self, key: str, value: Any) -> None:
        encoded = json.dumps(value, ensure_ascii=False) if not isinstance(value, str) else value
        self.conn.execute(
            "INSERT INTO meta(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (key, encoded),
        )
        self.conn.commit()

    def completed_regions(self) -> set[str]:
        value = self.get_meta("completed_regions", [])
        return set(value) if isinstance(value, list) else set()

    def mark_region_completed(self, root_arib: str) -> None:
        done = sorted(self.completed_regions() | {root_arib})
        self.set_meta("completed_regions", done)

    def has_variant(self, variant_key: str) -> bool:
        row = self.conn.execute("SELECT 1 FROM catalog_variants WHERE variant_key = ?", (variant_key,)).fetchone()
        return row is not None

    def has_assembly(self, assembly_key: str) -> bool:
        return assembly_key in self._assembly_keys

    def count_variants(self, root_arib: str | None = None) -> int:
        if root_arib:
            row = self.conn.execute("SELECT COUNT(*) AS c FROM catalog_variants WHERE root_arib = ?", (root_arib,)).fetchone()
        else:
            row = self.conn.execute("SELECT COUNT(*) AS c FROM catalog_variants").fetchone()
        return int(row["c"])

    def count_assemblies(self, root_arib: str | None = None) -> int:
        if root_arib:
            row = self.conn.execute("SELECT COUNT(*) AS c FROM catalog_assemblies WHERE root_arib = ?", (root_arib,)).fetchone()
        else:
            row = self.conn.execute("SELECT COUNT(*) AS c FROM catalog_assemblies").fetchone()
        return int(row["c"])

    def record_api(
        self,
        *,
        root_arib: str,
        endpoint: str,
        request: dict[str, Any],
        response: dict[str, Any],
        task_key: str | None = None,
    ) -> None:
        self.conn.execute(
            """
            INSERT INTO api_records(root_arib, endpoint, task_key, request_json, response_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                root_arib,
                endpoint,
                task_key,
                json.dumps(request, ensure_ascii=False),
                json.dumps(response, ensure_ascii=False),
                datetime.now(timezone.utc).isoformat(),
            ),
        )
        self.conn.commit()

    def add_nav_node(self, *, root_arib: str, rel: str, title: str, depth: int, path: list[str]) -> None:
        self._nav_batch.append((root_arib, rel, title, depth, json.dumps(path, ensure_ascii=False)))
        if len(self._nav_batch) >= SNAPSHOT_FLUSH_SIZE:
            self._flush_nav()

    def _flush_nav(self) -> None:
        if not self._nav_batch:
            return
        self.conn.executemany(
            """
            INSERT OR IGNORE INTO nav_nodes(root_arib, rel, title, depth, path_json)
            VALUES (?, ?, ?, ?, ?)
            """,
            self._nav_batch,
        )
        self._nav_batch.clear()
        self.conn.commit()

    def upsert_variant(self, row: dict[str, Any]) -> None:
        self.conn.execute(
            """
            INSERT INTO catalog_variants(
              variant_key, root_arib, model_name, source_designation, year_from,
              variant_section, browse_line, path_json, source_payload, assembly_count
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(variant_key) DO UPDATE SET
              model_name=excluded.model_name,
              source_designation=excluded.source_designation,
              year_from=excluded.year_from,
              path_json=excluded.path_json,
              source_payload=excluded.source_payload,
              assembly_count=excluded.assembly_count
            """,
            (
                row["variant_key"],
                row["root_arib"],
                row["model_name"],
                row["source_designation"],
                row["year_from"],
                row["variant_section"],
                row["browse_line"],
                json.dumps(row["path_json"], ensure_ascii=False),
                json.dumps(row["source_payload"], ensure_ascii=False),
                row["assembly_count"],
            ),
        )
        self.conn.commit()

    def queue_assembly(self, row: dict[str, Any]) -> None:
        self._assembly_batch.append(
            (
                row["variant_key"],
                row["assembly_key"],
                row["root_arib"],
                row["title"],
                json.dumps(row["path_json"], ensure_ascii=False),
                json.dumps(row["source_payload"], ensure_ascii=False),
                row.get("illust_url"),
            )
        )
        if len(self._assembly_batch) >= BULK_BATCH_SIZE:
            self._flush_assemblies()

    def _flush_assemblies(self) -> None:
        if not self._assembly_batch:
            return
        self.conn.executemany(
            """
            INSERT INTO catalog_assemblies(
              variant_key, assembly_key, root_arib, title, path_json, source_payload, illust_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(variant_key, assembly_key) DO UPDATE SET
              title=excluded.title,
              path_json=excluded.path_json,
              source_payload=excluded.source_payload,
              illust_url=excluded.illust_url
            """,
            self._assembly_batch,
        )
        for row in self._assembly_batch:
            self._assembly_keys.add(str(row[1]))
        self._assembly_batch.clear()
        self.conn.commit()

    def log_error(self, *, root_arib: str, stage: str, context: dict[str, Any], error: str) -> None:
        self.conn.execute(
            """
            INSERT INTO scan_errors(root_arib, stage, context_json, error, created_at)
            VALUES (?, ?, ?, ?, ?)
            """,
            (
                root_arib,
                stage,
                json.dumps(context, ensure_ascii=False),
                error,
                datetime.now(timezone.utc).isoformat(),
            ),
        )
        self.conn.commit()

    def list_scan_errors(
        self,
        *,
        root_arib: str | None = None,
        stage: str | None = None,
    ) -> list[dict[str, Any]]:
        query = "SELECT id, root_arib, stage, context_json, error, created_at FROM scan_errors"
        clauses: list[str] = []
        params: list[Any] = []
        if root_arib is not None:
            clauses.append("root_arib = ?")
            params.append(root_arib)
        if stage is not None:
            clauses.append("stage = ?")
            params.append(stage)
        if clauses:
            query += " WHERE " + " AND ".join(clauses)
        query += " ORDER BY id"
        rows = self.conn.execute(query, params).fetchall()
        return [dict(row) for row in rows]

    def delete_scan_error(self, error_id: int) -> None:
        self.conn.execute("DELETE FROM scan_errors WHERE id = ?", (error_id,))
        self.conn.commit()

    def update_scan_error(self, error_id: int, *, error: str) -> None:
        self.conn.execute(
            "UPDATE scan_errors SET error = ?, created_at = ? WHERE id = ?",
            (error, datetime.now(timezone.utc).isoformat(), error_id),
        )
        self.conn.commit()

    def finalize(self) -> None:
        self._flush_nav()
        self._flush_assemblies()
        self.set_meta("schema_version", SNAPSHOT_SCHEMA_VERSION)
        self.set_meta("finalized_at", datetime.now(timezone.utc).isoformat())

    def close(self) -> None:
        self._flush_nav()
        self._flush_assemblies()
        self.conn.close()


def open_snapshot(path: str | Path) -> sqlite3.Connection:
    verify_snapshot_integrity(path)
    conn = sqlite3.connect(path, timeout=60.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=60000")
    return conn
