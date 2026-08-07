from __future__ import annotations

import json
import sqlite3
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from app.yamaha_ps_v1.client import AriNode, brand_context, clean_text, list_children, make_client
from app.yamaha_ps_v1.constants import (
    BRAND_CODES,
    CIRCUIT_BREAKER_ERRORS,
    DEFAULT_SNAPSHOT_DELAY_MS,
    DEFAULT_SNAPSHOT_JITTER_MS,
    ROOT_ARIB,
    SNAPSHOT_FLUSH_SIZE,
    SNAPSHOT_SCHEMA_VERSION,
)
from app.yamaha_ps_v1.keys import assembly_key, variant_fields
from app.yamaha_ps_v1.progress import ProgressReporter

# Container-local overlay is reliable; bind-mounted ./storage on Docker Desktop/macOS
# frequently raises sqlite3.OperationalError: disk I/O error with WAL.
_WORK_DIR = Path("/tmp/oem-yamaha-ps")


def _is_disk_io_error(exc: BaseException) -> bool:
    if not isinstance(exc, sqlite3.OperationalError):
        return False
    msg = str(exc).lower()
    return "disk i/o" in msg or "disk i/o error" in msg or "input/output" in msg


def _retry_db(op: Callable[[], Any], *, attempts: int = 5, label: str = "sqlite") -> Any:
    last: BaseException | None = None
    for attempt in range(1, attempts + 1):
        try:
            return op()
        except sqlite3.OperationalError as exc:
            last = exc
            if not _is_disk_io_error(exc) or attempt >= attempts:
                raise
            time.sleep(min(8.0, 0.4 * attempt))
            print(f"[yamaha-ps-snapshot] retry {label} after I/O error ({attempt}/{attempts}): {exc}", flush=True)
    assert last is not None
    raise last


def _purge_sqlite_sidecars(path: Path) -> None:
    for suffix in ("-wal", "-shm", "-journal"):
        side = Path(f"{path}{suffix}")
        if side.exists():
            side.unlink()


class SnapshotStore:
    def __init__(self, path: str | Path) -> None:
        self.final_path = Path(path)
        self.final_path.parent.mkdir(parents=True, exist_ok=True)
        self.path = self.final_path  # import/open_snapshot consumers use final path
        _WORK_DIR.mkdir(parents=True, exist_ok=True)
        self.work_path = _WORK_DIR / self.final_path.name

        # Prefer reseeding work DB from a healthy published snapshot.
        if self.final_path.exists() and self.final_path.stat().st_size > 4096:
            if not self.work_path.exists() or self.work_path.stat().st_size < self.final_path.stat().st_size:
                _purge_sqlite_sidecars(self.work_path)
                if self.work_path.exists():
                    self.work_path.unlink()
                src = sqlite3.connect(f"file:{self.final_path}?mode=ro", uri=True)
                try:
                    dst = sqlite3.connect(self.work_path)
                    try:
                        src.backup(dst)
                        dst.commit()
                    finally:
                        dst.close()
                finally:
                    src.close()
        elif self.work_path.exists() and self.work_path.stat().st_size <= 4096:
            # Stale empty/corrupt work copy from a previous crash.
            _purge_sqlite_sidecars(self.work_path)
            self.work_path.unlink()

        _purge_sqlite_sidecars(self.final_path)
        # DELETE journal avoids WAL I/O failures on Docker Desktop bind mounts.
        self.conn = sqlite3.connect(self.work_path, timeout=120.0)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA journal_mode=DELETE")
        self.conn.execute("PRAGMA synchronous=NORMAL")
        self.conn.execute("PRAGMA temp_store=MEMORY")
        self.conn.execute("PRAGMA locking_mode=EXCLUSIVE")
        self._init_schema()
        self._node_batch: list[tuple[Any, ...]] = []
        self._assembly_batch: list[tuple[Any, ...]] = []
        self._batch_size = SNAPSHOT_FLUSH_SIZE
        self._finalized = False
        self._ops_since_publish = 0

    def _init_schema(self) -> None:
        self.conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS meta (
              key TEXT PRIMARY KEY,
              value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS api_nodes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              root_arib TEXT NOT NULL,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL,
              partstream_brand TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_api_nodes_root ON api_nodes(root_arib, depth);
            CREATE TABLE IF NOT EXISTS catalog_assemblies (
              variant_key TEXT NOT NULL,
              assembly_key TEXT NOT NULL,
              root_arib TEXT NOT NULL,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              title TEXT NOT NULL,
              path_json TEXT NOT NULL,
              partstream_brand TEXT,
              PRIMARY KEY (variant_key, assembly_key)
            );
            CREATE INDEX IF NOT EXISTS idx_catalog_assemblies_variant ON catalog_assemblies(variant_key);
            CREATE TABLE IF NOT EXISTS catalog_variants (
              variant_key TEXT PRIMARY KEY,
              root_arib TEXT NOT NULL,
              model_name TEXT NOT NULL,
              year_from INTEGER,
              source_designation TEXT,
              variant_section TEXT,
              browse_line TEXT,
              path_json TEXT NOT NULL,
              assembly_count INTEGER NOT NULL DEFAULT 0,
              partstream_brand TEXT
            );
            CREATE TABLE IF NOT EXISTS scan_errors (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              root_arib TEXT NOT NULL,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL,
              partstream_brand TEXT,
              error TEXT NOT NULL,
              status TEXT NOT NULL DEFAULT 'pending',
              attempts INTEGER NOT NULL DEFAULT 1,
              updated_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_scan_errors_status ON scan_errors(status);
            """
        )
        self.conn.commit()

    def get_meta(self, key: str, default: Any = None) -> Any:
        row = self.conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
        if row is None:
            return default
        try:
            return json.loads(row["value"])
        except json.JSONDecodeError:
            return row["value"]

    def set_meta(self, **values: Any) -> None:
        def _write() -> None:
            for key, value in values.items():
                self.conn.execute(
                    "INSERT INTO meta(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                    (key, json.dumps(value, ensure_ascii=False) if not isinstance(value, str) else value),
                )
            self.conn.commit()

        _retry_db(_write, label="set_meta")

    def completed_brands(self) -> set[str]:
        value = self.get_meta("completed_brands", [])
        return set(value) if isinstance(value, list) else set()

    def mark_brand_completed(self, brand: str) -> None:
        done = sorted(self.completed_brands() | {brand})
        self.set_meta(completed_brands=done, schema_version=SNAPSHOT_SCHEMA_VERSION)
        self.publish()

    def record_scan_error(self, node: AriNode, error: str) -> None:
        now = datetime.now(timezone.utc).isoformat()
        path_json = json.dumps(node.path, ensure_ascii=False)
        existing = self.conn.execute(
            """
            SELECT id, attempts FROM scan_errors
            WHERE status = 'pending' AND root_arib = ? AND arib = ? AND aria IS ? AND path_json = ?
            """,
            (node.root_arib, node.arib, node.aria, path_json),
        ).fetchone()
        if existing:
            self.conn.execute(
                "UPDATE scan_errors SET error = ?, attempts = ?, updated_at = ? WHERE id = ?",
                (error, int(existing["attempts"]) + 1, now, existing["id"]),
            )
        else:
            self.conn.execute(
                """
                INSERT INTO scan_errors(
                  root_arib, arib, aria, slug, rel, title, depth, path_json,
                  partstream_brand, error, status, attempts, updated_at
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?)
                """,
                (
                    node.root_arib,
                    node.arib,
                    node.aria,
                    node.slug,
                    node.rel or "",
                    node.title,
                    node.depth,
                    path_json,
                    node.partstream_brand,
                    error,
                    now,
                ),
            )

    def pending_errors(self) -> list[AriNode]:
        rows = self.conn.execute("SELECT * FROM scan_errors WHERE status = 'pending' ORDER BY id").fetchall()
        nodes: list[AriNode] = []
        for row in rows:
            nodes.append(
                AriNode(
                    title=row["title"],
                    arib=row["arib"],
                    aria=row["aria"],
                    rel=row["rel"] or "",
                    slug=row["slug"],
                    depth=int(row["depth"]),
                    path=json.loads(row["path_json"]),
                    root_arib=row["root_arib"],
                    partstream_brand=row["partstream_brand"] or "",
                )
            )
        return nodes

    def resolve_scan_error(self, node: AriNode) -> None:
        self.conn.execute(
            """
            UPDATE scan_errors SET status = 'ok', updated_at = ?
            WHERE status = 'pending' AND root_arib = ? AND arib = ? AND aria IS ? AND path_json = ?
            """,
            (
                datetime.now(timezone.utc).isoformat(),
                node.root_arib,
                node.arib,
                node.aria,
                json.dumps(node.path, ensure_ascii=False),
            ),
        )

    def record_node(self, node: AriNode) -> None:
        self._node_batch.append(
            (
                node.root_arib,
                node.arib,
                node.aria,
                node.slug,
                node.rel or "",
                node.title,
                node.depth,
                json.dumps(node.path, ensure_ascii=False),
                node.partstream_brand,
            )
        )
        if len(self._node_batch) >= self._batch_size:
            self._flush_nodes()

    def record_assembly(self, node: AriNode) -> None:
        fields = variant_fields(node.root_arib, node.path)
        asm_key = assembly_key(
            root_arib=node.root_arib,
            aria=node.aria,
            slug=node.slug,
            path=node.path,
        )
        self._assembly_batch.append(
            (
                fields["variant_key"],
                asm_key,
                node.root_arib,
                node.arib,
                node.aria,
                node.slug,
                node.title,
                json.dumps(node.path, ensure_ascii=False),
                node.partstream_brand,
            )
        )

        def _upsert_variant() -> None:
            self.conn.execute(
                """
                INSERT INTO catalog_variants(
                  variant_key, root_arib, model_name, year_from, source_designation,
                  variant_section, browse_line, path_json, assembly_count, partstream_brand
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                ON CONFLICT(variant_key) DO UPDATE SET assembly_count = assembly_count + 1
                """,
                (
                    fields["variant_key"],
                    fields["root_arib"],
                    fields["model_name"],
                    fields["year_from"],
                    fields["source_designation"],
                    fields["variant_section"],
                    fields["browse_line"],
                    json.dumps(fields["path_json"], ensure_ascii=False),
                    node.partstream_brand,
                ),
            )

        _retry_db(_upsert_variant, label="variant upsert")
        if len(self._assembly_batch) >= self._batch_size:
            self._flush_assemblies()

    def _flush_nodes(self, *, allow_publish: bool = True) -> None:
        if not self._node_batch:
            return
        batch = list(self._node_batch)
        self._node_batch.clear()

        def _write() -> None:
            self.conn.executemany(
                """
                INSERT INTO api_nodes(
                  root_arib, arib, aria, slug, rel, title, depth, path_json, partstream_brand
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                batch,
            )
            self.conn.commit()

        try:
            _retry_db(_write, label="flush nodes")
        except Exception:
            # Put rows back so close()/retry can try again.
            self._node_batch = batch + self._node_batch
            raise
        self._ops_since_publish += len(batch)
        if allow_publish and self._ops_since_publish >= SNAPSHOT_FLUSH_SIZE * 5:
            self.publish()

    def _flush_assemblies(self, *, allow_publish: bool = True) -> None:
        if not self._assembly_batch:
            return
        batch = list(self._assembly_batch)
        self._assembly_batch.clear()

        def _write() -> None:
            self.conn.executemany(
                """
                INSERT OR IGNORE INTO catalog_assemblies(
                  variant_key, assembly_key, root_arib, arib, aria, slug, title, path_json, partstream_brand
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                batch,
            )
            self.conn.commit()

        try:
            _retry_db(_write, label="flush assemblies")
        except Exception:
            self._assembly_batch = batch + self._assembly_batch
            raise
        self._ops_since_publish += len(batch)
        if allow_publish and self._ops_since_publish >= SNAPSHOT_FLUSH_SIZE * 5:
            self.publish()

    def publish(self) -> None:
        """Mirror work DB (in /tmp) to bind-mounted storage path via SQLite backup API."""
        self._flush_nodes(allow_publish=False)
        self._flush_assemblies(allow_publish=False)

        def _backup() -> None:
            self.conn.commit()
            _purge_sqlite_sidecars(self.final_path)
            dest = sqlite3.connect(self.final_path, timeout=120.0)
            try:
                dest.execute("PRAGMA journal_mode=DELETE")
                self.conn.backup(dest)
                dest.commit()
            finally:
                dest.close()

        _retry_db(_backup, label="publish backup")
        self._ops_since_publish = 0

    def reset_for_fresh_scan(self) -> None:
        """Wipe work + published snapshot before a non-resume scan."""
        self._node_batch.clear()
        self._assembly_batch.clear()
        self.conn.close()
        _purge_sqlite_sidecars(self.work_path)
        _purge_sqlite_sidecars(self.final_path)
        if self.work_path.exists():
            self.work_path.unlink()
        if self.final_path.exists():
            self.final_path.unlink()
        self.conn = sqlite3.connect(self.work_path, timeout=120.0)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA journal_mode=DELETE")
        self.conn.execute("PRAGMA synchronous=NORMAL")
        self.conn.execute("PRAGMA temp_store=MEMORY")
        self.conn.execute("PRAGMA locking_mode=EXCLUSIVE")
        self._init_schema()

    def finalize(self) -> None:
        self._flush_nodes()
        self._flush_assemblies()
        self.set_meta(scan_status="complete", finalized_at=datetime.now(timezone.utc).isoformat())
        self.publish()
        self._finalized = True

    def close(self) -> None:
        try:
            self._flush_nodes()
            self._flush_assemblies()
            self.conn.commit()
            if not self._finalized:
                try:
                    self.publish()
                except Exception as exc:
                    print(f"[yamaha-ps-snapshot] publish on close failed: {exc}", flush=True)
        finally:
            self.conn.close()

    def counts(self) -> dict[str, int]:
        out: dict[str, int] = {}
        for table in ("api_nodes", "catalog_assemblies", "catalog_variants", "scan_errors"):
            row = self.conn.execute(f"SELECT COUNT(*) AS c FROM {table}").fetchone()
            out[table] = int(row["c"]) if row else 0
        pending = self.conn.execute("SELECT COUNT(*) AS c FROM scan_errors WHERE status = 'pending'").fetchone()
        out["scan_errors_pending"] = int(pending["c"]) if pending else 0
        return out


def _reserve(progress: ProgressReporter, amount: int = 1, *, stage: bool = True) -> None:
    """Grow overall/stage totals as real work is discovered (no fake denominators)."""
    if amount <= 0:
        return
    progress.add_total(amount)
    if stage:
        progress.add_stage_total(amount)


def _complete(progress: ProgressReporter, message: str, *, step: int = 1) -> None:
    progress.advance(message, step=step)


def _drop_reserved(progress: ProgressReporter, amount: int) -> None:
    """Remove work units that will never be processed (limit / circuit break)."""
    amount = int(amount)
    if amount <= 0:
        return
    # ProgressReporter.add_total ignores negatives — adjust totals directly.
    progress.total = max(0, progress.total - amount)
    progress.stage_total = max(0, progress.stage_total - amount)
    progress.stage_done = min(progress.stage_done, progress.stage_total)
    progress.done = min(progress.done, progress.total)


def _process_children(
    *,
    store: SnapshotStore,
    node: AriNode,
    children: list[dict[str, Any]],
    queue: list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    limit_assemblies: int | None = None,
) -> None:
    for child in children:
        if limit_assemblies and stats["assemblies"] >= limit_assemblies:
            return
        attr = child.get("attr") or {}
        title = clean_text(child.get("data"))
        child_arib = attr.get("arib") or node.arib
        child_node = AriNode(
            title=title,
            arib=child_arib,
            aria=attr.get("aria"),
            rel=attr.get("rel") or "",
            slug=attr.get("slug") or None,
            depth=node.depth + 1,
            path=[*node.path, title],
            root_arib=ROOT_ARIB,
            partstream_brand=node.partstream_brand,
        )
        if child_node.rel == "assembly":
            if child_node.slug:
                store.record_node(child_node)
                store.record_assembly(child_node)
                stats["assemblies"] += 1
                _reserve(progress, 1)
                _complete(progress, f"assembly {child_node.partstream_brand} depth={child_node.depth}")
        else:
            queue.append(child_node)
            _reserve(progress, 1)


def _walk_queue(
    *,
    store: SnapshotStore,
    client: Any,
    brand: Any,
    queue: list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    delay_ms: int,
    jitter_ms: int,
    limit_assemblies: int | None = None,
) -> None:
    consecutive_errors = 0
    while queue:
        if limit_assemblies and stats["assemblies"] >= limit_assemblies:
            _drop_reserved(progress, len(queue))
            queue.clear()
            progress.tick(f"limit reached assemblies={stats['assemblies']}")
            return
        node = queue.pop(0)
        stats["nodes"] += 1
        store.record_node(node)

        if node.rel == "assembly":
            _complete(progress, f"assembly-node {node.partstream_brand} depth={node.depth}")
            continue

        try:
            children = list_children(
                client,
                brand,
                node.arib,
                node.aria,
                delay_ms=delay_ms,
                jitter_ms=jitter_ms,
            )
            stats["api_calls"] += 1
            consecutive_errors = 0
        except Exception as exc:
            stats["errors"] += 1
            consecutive_errors += 1
            store.record_scan_error(node, str(exc))
            _complete(progress, f"ERROR {node.partstream_brand} / {' / '.join(node.path)}: {exc}")
            if consecutive_errors >= CIRCUIT_BREAKER_ERRORS:
                _drop_reserved(progress, len(queue))
                queue.clear()
                progress.tick(
                    f"CIRCUIT OPEN after {consecutive_errors} consecutive GetAssembly errors — stop walk"
                )
                stats["circuit_open"] = 1
                return
            continue

        _process_children(
            store=store,
            node=node,
            children=children,
            queue=queue,
            stats=stats,
            progress=progress,
            limit_assemblies=limit_assemblies,
        )
        _complete(
            progress,
            f"node {node.partstream_brand} depth={node.depth} queue={len(queue)} "
            f"api={stats['api_calls']} assemblies={stats['assemblies']}",
        )


def _resolve_brands(brand: str | None) -> list[str]:
    if not brand or brand.strip().lower() in {"all", "*"}:
        return list(BRAND_CODES)
    code = brand.strip().upper()
    if code not in BRAND_CODES:
        raise ValueError(f"Unknown brand {brand!r}; expected YAM, YAMMR, or all")
    return [code]


def scan_to_snapshot(
    *,
    snapshot_path: str,
    brand: str = "all",
    resume: bool = False,
    limit_assemblies: int | None = None,
    delay_ms: int = DEFAULT_SNAPSHOT_DELAY_MS,
    jitter_ms: int = DEFAULT_SNAPSHOT_JITTER_MS,
) -> dict[str, Any]:
    started = time.time()
    codes = _resolve_brands(brand)
    store = SnapshotStore(snapshot_path)
    completed = store.completed_brands() if resume else set()

    if not resume:
        store.reset_for_fresh_scan()
        store.set_meta(
            schema_version=SNAPSHOT_SCHEMA_VERSION,
            scanned_at=datetime.now(timezone.utc).isoformat(),
            brands=codes,
            completed_brands=[],
            root_arib=ROOT_ARIB,
            scan_status="partial",
        )
    else:
        # Resume may add brands not previously requested.
        # Note: resume only skips *completed* brands; a crashed mid-brand scan
        # should be restarted without --resume (fresh wipe).
        prev = store.get_meta("brands", [])
        merged = sorted(set(prev if isinstance(prev, list) else []) | set(codes))
        store.set_meta(brands=merged)

    stats = {"nodes": 0, "assemblies": 0, "errors": 0, "api_calls": 0, "circuit_open": 0}
    # total=0 → grows via _reserve as nodes/assemblies are discovered (real overall).
    progress = ProgressReporter(total=0, label="yamaha-ps-snapshot")

    try:
        for code in codes:
            if resume and code in completed:
                _reserve(progress, 1)
                _complete(progress, f"skip completed brand={code}")
                continue
            if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                break

            brand_ctx = brand_context(code)
            progress.set_stage(f"scan {code}", 0)
            progress.tick(f"scan brand={code} ({brand_ctx.label})")

            with make_client(referer=brand_ctx.ariv) as client:
                try:
                    children = list_children(
                        client,
                        brand_ctx,
                        brand_ctx.partstream_arib,
                        delay_ms=delay_ms,
                        jitter_ms=jitter_ms,
                    )
                    stats["api_calls"] += 1
                except Exception as exc:
                    stats["errors"] += 1
                    _reserve(progress, 1)
                    _complete(progress, f"ERROR root children {code}: {exc}")
                    continue

                # Top nav: Yamaha PowerSport | Yamaha Marine
                brand_root = AriNode(
                    title=brand_ctx.label,
                    arib=brand_ctx.partstream_arib,
                    aria=None,
                    rel="brand",
                    slug=None,
                    depth=0,
                    path=[brand_ctx.label],
                    root_arib=ROOT_ARIB,
                    partstream_brand=code,
                )
                store.record_node(brand_root)

                queue: list[AriNode] = []
                for child in children:
                    attr = child.get("attr") or {}
                    title = clean_text(child.get("data"))
                    child_arib = attr.get("arib") or brand_ctx.partstream_arib
                    queue.append(
                        AriNode(
                            title=title,
                            arib=child_arib,
                            aria=attr.get("aria"),
                            rel=attr.get("rel") or "",
                            slug=attr.get("slug") or None,
                            depth=1,
                            path=[brand_ctx.label, title],
                            root_arib=ROOT_ARIB,
                            partstream_brand=code,
                        )
                    )
                _reserve(progress, len(queue))

                _walk_queue(
                    store=store,
                    client=client,
                    brand=brand_ctx,
                    queue=queue,
                    stats=stats,
                    progress=progress,
                    delay_ms=delay_ms,
                    jitter_ms=jitter_ms,
                    limit_assemblies=limit_assemblies,
                )

                for node in store.pending_errors():
                    if node.partstream_brand != code:
                        continue
                    if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                        break
                    retry_queue: list[AriNode] = []
                    _reserve(progress, 1)
                    try:
                        children = list_children(
                            client,
                            brand_ctx,
                            node.arib,
                            node.aria,
                            delay_ms=delay_ms,
                            jitter_ms=jitter_ms,
                        )
                        stats["api_calls"] += 1
                    except Exception as exc:
                        stats["errors"] += 1
                        store.record_scan_error(node, str(exc))
                        _complete(progress, f"ERROR retry {node.partstream_brand}: {exc}")
                        continue
                    _process_children(
                        store=store,
                        node=node,
                        children=children,
                        queue=retry_queue,
                        stats=stats,
                        progress=progress,
                        limit_assemblies=limit_assemblies,
                    )
                    _complete(progress, f"retry ok {node.partstream_brand} depth={node.depth}")
                    _walk_queue(
                        store=store,
                        client=client,
                        brand=brand_ctx,
                        queue=retry_queue,
                        stats=stats,
                        progress=progress,
                        delay_ms=delay_ms,
                        jitter_ms=jitter_ms,
                        limit_assemblies=limit_assemblies,
                    )
                    store.resolve_scan_error(node)

            if stats.get("circuit_open"):
                progress.tick(f"brand={code} incomplete (circuit open); not marking completed")
                break
            store.mark_brand_completed(code)
            progress.tick(f"done brand={code}")

        store.finalize()
        counts = store.counts()
        payload = {
            "snapshot_path": str(store.path),
            "root_arib": ROOT_ARIB,
            "duration_seconds": round(time.time() - started, 1),
            "scan_stats": stats,
            "counts": counts,
            "completed_brands": sorted(store.completed_brands()),
        }
        progress.finish(f"snapshot written counts={counts}")
        store.close()
        return payload
    except Exception:
        store.close()
        raise


def open_snapshot(path: str | Path) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    return conn
