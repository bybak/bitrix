from __future__ import annotations

import json
import sqlite3
import threading
import time
from collections import deque
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.remotors_v3.client import AriNode, clean_text, list_children, make_client
from app.remotors_v3.constants import BULK_BATCH_SIZE, ROOT_ARIB_CODES, SNAPSHOT_SCHEMA_VERSION
from app.remotors_v3.keys import assembly_key, resolve_root_arib, variant_fields
from app.remotors_v3.progress import ProgressReporter


class SnapshotStore:
    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        # Shared across snapshot worker threads; all access goes through self._lock.
        self.conn = sqlite3.connect(self.path, check_same_thread=False)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA journal_mode=WAL")
        self.conn.execute("PRAGMA synchronous=NORMAL")
        self._lock = threading.RLock()
        self._init_schema()
        self._node_batch: list[tuple[Any, ...]] = []
        self._assembly_batch: list[tuple[Any, ...]] = []
        self._batch_size = BULK_BATCH_SIZE
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
              root_arib TEXT NOT NULL,
              arib TEXT NOT NULL,
              aria TEXT,
              slug TEXT,
              rel TEXT NOT NULL,
              title TEXT NOT NULL,
              depth INTEGER NOT NULL,
              path_json TEXT NOT NULL
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
              assembly_count INTEGER NOT NULL DEFAULT 0
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
        with self._lock:
            row = self.conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
            if row is None:
                return default
            try:
                return json.loads(row["value"])
            except json.JSONDecodeError:
                return row["value"]

    def set_meta(self, **values: Any) -> None:
        with self._lock:
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
        self.set_meta(completed_arib=done, schema_version=SNAPSHOT_SCHEMA_VERSION)

    def record_scan_error(self, node: AriNode, error: str) -> None:
        now = datetime.now(timezone.utc).isoformat()
        path_json = json.dumps(node.path, ensure_ascii=False)
        with self._lock:
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
                      root_arib, arib, aria, slug, rel, title, depth, path_json, error, status, attempts, updated_at
                    ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?)
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
                        error,
                        now,
                    ),
                )
            self.conn.commit()

    def pending_errors(self) -> list[AriNode]:
        with self._lock:
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
                )
            )
        return nodes

    def resolve_scan_error(self, node: AriNode) -> None:
        with self._lock:
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
            self.conn.commit()

    def record_node(self, node: AriNode) -> None:
        with self._lock:
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
        with self._lock:
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
                )
            )
            self.conn.execute(
                """
                INSERT INTO catalog_variants(
                  variant_key, root_arib, model_name, year_from, source_designation,
                  variant_section, browse_line, path_json, assembly_count
                ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 1)
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
                ),
            )
            if len(self._assembly_batch) >= self._batch_size:
                self._flush_assemblies()

    def _flush_nodes(self) -> None:
        if not self._node_batch:
            return
        self.conn.executemany(
            """
            INSERT INTO api_nodes(root_arib, arib, aria, slug, rel, title, depth, path_json)
            VALUES(?, ?, ?, ?, ?, ?, ?, ?)
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
              variant_key, assembly_key, root_arib, arib, aria, slug, title, path_json
            ) VALUES(?, ?, ?, ?, ?, ?, ?, ?)
            """,
            self._assembly_batch,
        )
        self._assembly_batch.clear()
        self.conn.commit()

    def finalize(self) -> None:
        with self._lock:
            self._flush_nodes()
            self._flush_assemblies()
        self.set_meta(scan_status="complete", finalized_at=datetime.now(timezone.utc).isoformat())
        self._finalized = True

    def close(self) -> None:
        with self._lock:
            self._flush_nodes()
            self._flush_assemblies()
            self.conn.commit()
            self.conn.close()

    def counts(self) -> dict[str, int]:
        with self._lock:
            out: dict[str, int] = {}
            for table in ("api_nodes", "catalog_assemblies", "catalog_variants", "scan_errors"):
                row = self.conn.execute(f"SELECT COUNT(*) AS c FROM {table}").fetchone()
                out[table] = int(row["c"]) if row else 0
            pending = self.conn.execute(
                "SELECT COUNT(*) AS c FROM scan_errors WHERE status = 'pending'"
            ).fetchone()
            out["scan_errors_pending"] = int(pending["c"]) if pending else 0
            return out


def _reserve(progress: ProgressReporter, amount: int = 1, *, stage: bool = True) -> None:
    """Grow overall/stage totals as real work is discovered (no fake /1000)."""
    if amount <= 0:
        return
    progress.add_total(amount)
    if stage:
        progress.add_stage_total(amount)


def _complete(progress: ProgressReporter, message: str, *, step: int = 1) -> None:
    progress.advance(message, step=step)


def _drop_reserved(progress: ProgressReporter, amount: int) -> None:
    """Remove work units that will never be processed (limit reached)."""
    amount = int(amount)
    if amount <= 0:
        return

    def _apply() -> None:
        progress.total = max(0, progress.total - amount)
        progress.stage_total = max(0, progress.stage_total - amount)
        progress.stage_done = min(progress.stage_done, progress.stage_total)
        progress.done = min(progress.done, progress.total)

    lock = getattr(progress, "_lock", None)
    if lock is not None:
        with lock:
            _apply()
    else:
        _apply()


def _append_child_nodes(
    *,
    store: SnapshotStore,
    node: AriNode,
    children: list[dict[str, Any]],
    queue: deque[AriNode] | list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    limit_assemblies: int | None = None,
) -> None:
    """Record assemblies / enqueue folders. Caller holds queue/stats lock if concurrent."""
    for child in children:
        if limit_assemblies and stats["assemblies"] >= limit_assemblies:
            return
        attr = child.get("attr") or {}
        title = clean_text(child.get("data"))
        child_arib = attr.get("arib") or node.arib
        root_arib = resolve_root_arib(child_arib, node.root_arib)
        child_node = AriNode(
            title=title,
            arib=child_arib,
            aria=attr.get("aria"),
            rel=attr.get("rel") or "",
            slug=attr.get("slug") or None,
            depth=node.depth + 1,
            path=[*node.path, title],
            root_arib=root_arib,
        )
        if child_node.rel == "assembly":
            if child_node.slug:
                store.record_node(child_node)
                store.record_assembly(child_node)
                stats["assemblies"] += 1
                _reserve(progress, 1)
                _complete(progress, f"assembly {child_node.root_arib} depth={child_node.depth}")
        else:
            if isinstance(queue, deque):
                queue.append(child_node)
            else:
                queue.append(child_node)
            _reserve(progress, 1)


def _walk_queue_serial(
    *,
    store: SnapshotStore,
    client: Any,
    queue: list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    limit_assemblies: int | None = None,
) -> None:
    work: deque[AriNode] = deque(queue)
    queue.clear()
    while work:
        if limit_assemblies and stats["assemblies"] >= limit_assemblies:
            _drop_reserved(progress, len(work))
            work.clear()
            progress.tick(f"limit reached assemblies={stats['assemblies']}")
            return
        node = work.popleft()
        stats["nodes"] += 1
        store.record_node(node)

        if node.rel == "assembly":
            _complete(progress, f"assembly-node {node.root_arib} depth={node.depth}")
            continue

        try:
            children = list_children(client, node.arib, node.aria)
            stats["api_calls"] += 1
        except Exception as exc:
            stats["errors"] += 1
            store.record_scan_error(node, str(exc))
            _complete(progress, f"ERROR {node.root_arib} / {' / '.join(node.path)}: {exc}")
            continue

        _append_child_nodes(
            store=store,
            node=node,
            children=children,
            queue=work,
            stats=stats,
            progress=progress,
            limit_assemblies=limit_assemblies,
        )
        _complete(
            progress,
            f"node {node.root_arib} depth={node.depth} queue={len(work)} "
            f"api={stats['api_calls']} assemblies={stats['assemblies']}",
        )


def _walk_queue_parallel(
    *,
    store: SnapshotStore,
    queue: list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    concurrency: int,
    limit_assemblies: int | None = None,
) -> None:
    work: deque[AriNode] = deque(queue)
    queue.clear()
    cond = threading.Condition()
    in_flight = 0
    stop = False
    progress.enable_thread_safe()

    def worker() -> None:
        nonlocal in_flight, stop
        client = make_client()
        try:
            while True:
                with cond:
                    while not work and in_flight > 0 and not stop:
                        cond.wait(timeout=1.0)
                    if stop or (not work and in_flight == 0):
                        return
                    if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                        if work:
                            _drop_reserved(progress, len(work))
                            work.clear()
                        stop = True
                        cond.notify_all()
                        progress.tick(f"limit reached assemblies={stats['assemblies']}")
                        return
                    node = work.popleft()
                    in_flight += 1
                    stats["nodes"] += 1

                store.record_node(node)

                if node.rel == "assembly":
                    with cond:
                        in_flight -= 1
                        _complete(progress, f"assembly-node {node.root_arib} depth={node.depth}")
                        cond.notify_all()
                    continue

                try:
                    children = list_children(client, node.arib, node.aria)
                    api_ok = True
                    api_exc: Exception | None = None
                except Exception as exc:
                    api_ok = False
                    api_exc = exc
                    children = []

                with cond:
                    if api_ok:
                        stats["api_calls"] += 1
                        _append_child_nodes(
                            store=store,
                            node=node,
                            children=children,
                            queue=work,
                            stats=stats,
                            progress=progress,
                            limit_assemblies=limit_assemblies,
                        )
                        _complete(
                            progress,
                            f"node {node.root_arib} depth={node.depth} queue={len(work)} "
                            f"api={stats['api_calls']} assemblies={stats['assemblies']} "
                            f"workers={concurrency}",
                        )
                        if limit_assemblies and stats["assemblies"] >= limit_assemblies and work:
                            _drop_reserved(progress, len(work))
                            work.clear()
                            stop = True
                    else:
                        stats["errors"] += 1
                        store.record_scan_error(node, str(api_exc))
                        _complete(
                            progress,
                            f"ERROR {node.root_arib} / {' / '.join(node.path)}: {api_exc}",
                        )
                    in_flight -= 1
                    cond.notify_all()
        finally:
            client.close()

    workers = max(1, min(int(concurrency), 32))
    with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="snap-v3") as pool:
        futures = [pool.submit(worker) for _ in range(workers)]
        for future in futures:
            future.result()


def _walk_queue(
    *,
    store: SnapshotStore,
    client: Any | None,
    queue: list[AriNode],
    stats: dict[str, int],
    progress: ProgressReporter,
    concurrency: int = 1,
    limit_assemblies: int | None = None,
) -> None:
    concurrency = max(1, min(int(concurrency), 32))
    if concurrency <= 1:
        if client is None:
            with make_client() as owned:
                _walk_queue_serial(
                    store=store,
                    client=owned,
                    queue=queue,
                    stats=stats,
                    progress=progress,
                    limit_assemblies=limit_assemblies,
                )
        else:
            _walk_queue_serial(
                store=store,
                client=client,
                queue=queue,
                stats=stats,
                progress=progress,
                limit_assemblies=limit_assemblies,
            )
        return
    _walk_queue_parallel(
        store=store,
        queue=queue,
        stats=stats,
        progress=progress,
        concurrency=concurrency,
        limit_assemblies=limit_assemblies,
    )


def scan_to_snapshot(
    *,
    snapshot_path: str,
    arib_codes: list[str] | None = None,
    resume: bool = False,
    limit_assemblies: int | None = None,
    concurrency: int = 1,
) -> dict[str, Any]:
    started = time.time()
    codes = list(arib_codes or ROOT_ARIB_CODES)
    concurrency = max(1, min(int(concurrency), 32))
    snapshot_file = Path(snapshot_path)
    if not resume and snapshot_file.exists():
        # Fresh run: recreate file. Safer than DELETE after Ctrl+C / WAL corruption
        # on Docker bind mounts (macOS), which can leave "database disk image is malformed".
        for suffix in ("", "-wal", "-shm"):
            sibling = Path(str(snapshot_file) + suffix) if suffix else snapshot_file
            try:
                sibling.unlink(missing_ok=True)
            except OSError:
                pass

    try:
        store = SnapshotStore(snapshot_path)
    except sqlite3.DatabaseError as exc:
        if resume:
            raise SystemExit(
                f"snapshot DB is corrupted ({exc}). Delete it and rerun without --resume:\n"
                f"  rm -f {snapshot_path} {snapshot_path}-wal {snapshot_path}-shm"
            ) from exc
        for suffix in ("", "-wal", "-shm"):
            sibling = Path(str(snapshot_file) + suffix) if suffix else snapshot_file
            try:
                sibling.unlink(missing_ok=True)
            except OSError:
                pass
        store = SnapshotStore(snapshot_path)

    completed = store.completed_aribs() if resume else set()

    if not resume:
        store.set_meta(
            schema_version=SNAPSHOT_SCHEMA_VERSION,
            scanned_at=datetime.now(timezone.utc).isoformat(),
            arib_codes=codes,
            completed_arib=[],
            scan_status="partial",
            concurrency=concurrency,
        )

    stats = {"nodes": 0, "assemblies": 0, "errors": 0, "api_calls": 0}
    label = "snapshot-v3" if concurrency <= 1 else f"snapshot-v3x{concurrency}"
    # total=0 → grows via _reserve as nodes/assemblies are discovered (real overall).
    progress = ProgressReporter(total=0, label=label)
    if concurrency > 1:
        progress.enable_thread_safe()
    progress.set_stage("scan", 0)

    try:
        with make_client() as client:
            for arib in codes:
                if resume and arib in completed:
                    _reserve(progress, 1)
                    _complete(progress, f"skip completed root={arib}")
                    continue
                if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                    break

                progress.tick(f"scan root={arib} concurrency={concurrency}")
                try:
                    children = list_children(client, arib)
                    stats["api_calls"] += 1
                except Exception as exc:
                    stats["errors"] += 1
                    _reserve(progress, 1)
                    _complete(progress, f"ERROR root children {arib}: {exc}")
                    continue

                queue: list[AriNode] = []
                for child in children:
                    attr = child.get("attr") or {}
                    title = clean_text(child.get("data"))
                    child_arib = attr.get("arib") or arib
                    queue.append(
                        AriNode(
                            title=title,
                            arib=child_arib,
                            aria=attr.get("aria"),
                            rel=attr.get("rel") or "",
                            slug=attr.get("slug") or None,
                            depth=1,
                            path=[title],
                            root_arib=arib,
                        )
                    )
                _reserve(progress, len(queue))

                _walk_queue(
                    store=store,
                    client=client if concurrency <= 1 else None,
                    queue=queue,
                    stats=stats,
                    progress=progress,
                    concurrency=concurrency,
                    limit_assemblies=limit_assemblies,
                )
                store.mark_arib_completed(arib)
                progress.tick(f"done root={arib}")
                if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                    break

            for node in store.pending_errors():
                if limit_assemblies and stats["assemblies"] >= limit_assemblies:
                    break
                retry_queue: list[AriNode] = []
                _reserve(progress, 1)
                try:
                    children = list_children(client, node.arib, node.aria)
                    stats["api_calls"] += 1
                except Exception as exc:
                    stats["errors"] += 1
                    store.record_scan_error(node, str(exc))
                    _complete(progress, f"ERROR retry {node.root_arib}: {exc}")
                    continue
                _append_child_nodes(
                    store=store,
                    node=node,
                    children=children,
                    queue=retry_queue,
                    stats=stats,
                    progress=progress,
                    limit_assemblies=limit_assemblies,
                )
                _complete(progress, f"retry ok {node.root_arib} depth={node.depth}")
                _walk_queue(
                    store=store,
                    client=client if concurrency <= 1 else None,
                    queue=retry_queue,
                    stats=stats,
                    progress=progress,
                    concurrency=concurrency,
                    limit_assemblies=limit_assemblies,
                )
                store.resolve_scan_error(node)

        store.finalize()
        counts = store.counts()
        payload = {
            "snapshot_path": str(store.path),
            "duration_seconds": round(time.time() - started, 1),
            "concurrency": concurrency,
            "scan_stats": stats,
            "counts": counts,
            "completed_arib": sorted(store.completed_aribs()),
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
