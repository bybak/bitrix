"""Read-only audit: compare local OEM catalog counts with Remotors/ARI API tree."""

from __future__ import annotations

import json
import time
from collections import defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TextIO

import httpx

from app.db import get_conn
from app.importers.remotors import (
    AriNode,
    _clean_text,
    _list_children,
    _parse_model_title,
    _parse_variant_section,
    _parse_year,
    _client,
)
from app.importers.remotors_catalog import (
    DEPRECATED_ARIB_CODES,
    HIDDEN_CANONICAL_BRANDS,
    assembly_compare_key,
    canonical_brand_and_type,
    crawl_arib_for_brand,
)
from app.normalization import normalize_text

SOURCE_CODE = "remotors_ari"


@dataclass
class EntitySets:
    models: set[tuple[Any, ...]] = field(default_factory=set)
    variants: set[tuple[Any, ...]] = field(default_factory=set)
    assemblies: set[str] = field(default_factory=set)

    def counts(self) -> dict[str, int]:
        return {
            "models": len(self.models),
            "variants": len(self.variants),
            "assemblies": len(self.assemblies),
        }


def _log_line(log_fp: TextIO | None, message: str) -> None:
    line = f"[{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')}Z] {message}"
    print(line, flush=True)
    if log_fp is not None:
        log_fp.write(line + "\n")
        log_fp.flush()


def _local_brands() -> list[dict[str, Any]]:
    hidden = tuple(sorted(HIDDEN_CANONICAL_BRANDS))
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT b.id, b.name, b.normalized_name
                FROM oem_brands b
                WHERE b.normalized_name <> ALL(%s)
                  AND EXISTS (
                    SELECT 1 FROM oem_model_families mf WHERE mf.brand_id = b.id
                  )
                ORDER BY b.name
                """,
                (list(hidden),),
            )
            return list(cur.fetchall())


def _local_sets_by_brand() -> dict[str, EntitySets]:
    hidden = tuple(sorted(HIDDEN_CANONICAL_BRANDS))
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT
                  b.normalized_name AS brand_normalized,
                  b.name AS brand_name,
                  vt.code AS vehicle_type,
                  mf.normalized_name AS model_normalized,
                  vv.id AS variant_id,
                  vv.year_from,
                  vv.source_designation,
                  vv.variant_section,
                  a.id AS assembly_id,
                  sn.external_id AS assembly_external_id,
                  sn.arib AS assembly_arib,
                  sn.aria AS assembly_aria,
                  sn.slug AS assembly_slug,
                  a.normalized_title AS assembly_normalized
                FROM oem_brands b
                JOIN oem_model_families mf ON mf.brand_id = b.id
                JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
                LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
                LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
                LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
                WHERE b.normalized_name <> ALL(%s)
                """,
                (list(hidden),),
            )
            rows = list(cur.fetchall())

    grouped: dict[str, EntitySets] = defaultdict(EntitySets)
    for row in rows:
        brand = row["brand_normalized"]
        bucket = grouped[brand]
        bucket.models.add((row["vehicle_type"], row["model_normalized"]))
        if row["variant_id"] is not None:
            bucket.variants.add(
                (
                    row["vehicle_type"],
                    row["model_normalized"],
                    row["year_from"],
                    normalize_text(row.get("source_designation") or ""),
                    normalize_text(row.get("variant_section") or ""),
                )
            )
        if row["assembly_id"] is not None:
            assembly_key = assembly_compare_key(
                arib=row.get("assembly_arib"),
                aria=row.get("assembly_aria"),
                slug=row.get("assembly_slug"),
                external_id=row.get("assembly_external_id"),
            ) or f"local:{row['assembly_id']}:{row['assembly_normalized']}"
            bucket.assemblies.add(assembly_key)
    return dict(grouped)


def _ari_codes_for_brands(brand_normalized: set[str]) -> list[str]:
    codes = {crawl_arib_for_brand(name) for name in brand_normalized}
    codes.discard("")
    return sorted(code for code in codes if code not in DEPRECATED_ARIB_CODES)


def _format_duration(seconds: float) -> str:
    seconds = max(0, int(seconds))
    minutes, sec = divmod(seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f"{hours:02d}:{minutes:02d}:{sec:02d}"
    return f"{minutes:02d}:{sec:02d}"


@dataclass
class _ScanProgress:
    log_fp: TextIO | None
    started_at: float = field(default_factory=time.monotonic)
    last_log_at: float = 0.0
    nodes: int = 0
    assemblies: int = 0
    api_calls: int = 0
    errors: int = 0
    log_every_nodes: int = 250
    log_every_seconds: float = 15.0

    def heartbeat(
        self,
        *,
        arib: str,
        path: list[str],
        queue_len: int,
        phase: str = "fetch",
    ) -> None:
        now = time.monotonic()
        if (now - self.last_log_at) < self.log_every_seconds:
            return
        self.last_log_at = now
        elapsed = now - self.started_at
        path_hint = " / ".join(path[-5:]) if path else arib
        _log_line(
            self.log_fp,
            "progress "
            f"arib={arib} phase={phase} path={path_hint} "
            f"nodes={self.nodes} assemblies={self.assemblies} queue={queue_len} "
            f"api={self.api_calls} errors={self.errors} "
            f"elapsed={_format_duration(elapsed)}",
        )

    def bump(
        self,
        *,
        arib: str,
        path: list[str],
        queue_len: int,
        is_assembly: bool = False,
        force: bool = False,
    ) -> None:
        self.nodes += 1
        if is_assembly:
            self.assemblies += 1
        now = time.monotonic()
        due_time = (now - self.last_log_at) >= self.log_every_seconds
        due_nodes = self.nodes == 1 or self.nodes % self.log_every_nodes == 0
        due_assemblies = is_assembly and self.assemblies % 500 == 0
        if not (force or due_time or due_nodes or due_assemblies):
            return
        self.last_log_at = now
        elapsed = now - self.started_at
        rate = self.nodes / elapsed if elapsed > 0 else 0.0
        eta = (queue_len / rate) if rate > 0 else 0.0
        path_hint = " / ".join(path[-5:]) if path else arib
        _log_line(
            self.log_fp,
            "progress "
            f"arib={arib} path={path_hint} "
            f"nodes={self.nodes} assemblies={self.assemblies} queue={queue_len} "
            f"api={self.api_calls} errors={self.errors} "
            f"elapsed={_format_duration(elapsed)} eta~={_format_duration(eta)}",
        )


def _catalog_keys_from_assembly(node: AriNode) -> tuple[str, str, tuple[Any, ...], tuple[Any, ...], str] | None:
    """Return brand_normalized, brand_display, model_key, variant_key, assembly_key."""
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
    assembly_key = assembly_compare_key(
        arib=node.arib,
        aria=node.aria,
        slug=node.slug,
        path=node.path,
    )
    if assembly_key is None:
        return None
    return brand_normalized, brand_name, model_key, variant_key, assembly_key


def _scan_remote(
    *,
    arib_codes: list[str],
    allowed_brands: set[str],
    log_fp: TextIO | None,
) -> tuple[dict[str, EntitySets], dict[str, int]]:
    stats = {"nodes": 0, "assemblies": 0, "errors": 0, "api_calls": 0}
    grouped: dict[str, EntitySets] = defaultdict(EntitySets)
    progress = _ScanProgress(log_fp=log_fp)

    with _client() as client:
        for arib in arib_codes:
            _log_line(log_fp, f"scan start arib={arib}")
            try:
                children = _list_children(client, arib)
                stats["api_calls"] += 1
                progress.api_calls += 1
            except Exception as exc:
                stats["errors"] += 1
                progress.errors += 1
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
            _log_line(log_fp, f"scan queued arib={arib} children={len(children)} queue={len(queue)} — walking tree")

            brand_start_nodes = progress.nodes
            brand_start_assemblies = stats["assemblies"]
            while queue:
                node = queue.pop(0)
                if progress.nodes == brand_start_nodes:
                    _log_line(
                        log_fp,
                        f"scan walking arib={arib} queue={len(queue) + 1} — requesting Remotors API...",
                    )

                if node.rel == "assembly":
                    parsed = _catalog_keys_from_assembly(node)
                    if parsed is not None:
                        brand_normalized, _brand_name, model_key, variant_key, assembly_key = parsed
                        if brand_normalized in allowed_brands:
                            bucket = grouped[brand_normalized]
                            bucket.models.add(model_key)
                            bucket.variants.add(variant_key)
                            bucket.assemblies.add(assembly_key)
                            stats["assemblies"] += 1
                    progress.bump(
                        arib=arib,
                        path=node.path,
                        queue_len=len(queue),
                        is_assembly=True,
                    )
                else:
                    progress.heartbeat(
                        arib=arib,
                        path=node.path,
                        queue_len=len(queue),
                        phase="fetch",
                    )
                    try:
                        children = _list_children(client, node.arib, node.aria)
                        stats["api_calls"] += 1
                        progress.api_calls += 1
                    except Exception as exc:
                        stats["errors"] += 1
                        progress.errors += 1
                        _log_line(log_fp, f"ERROR node {node.arib} / {' / '.join(node.path)}: {exc}")
                        progress.bump(arib=arib, path=node.path, queue_len=len(queue), force=True)
                        continue
                    child_count = len(children)
                    if child_count >= 20:
                        _log_line(
                            log_fp,
                            f"expand arib={arib} depth={node.depth} children={child_count} "
                            f"path={' / '.join(node.path[-4:])}",
                        )
                    for child in children:
                        attr = child.get("attr") or {}
                        title = _clean_text(child.get("data"))
                        queue.append(
                            AriNode(
                                title=title,
                                arib=attr.get("arib") or node.arib,
                                aria=attr.get("aria"),
                                rel=attr.get("rel") or "",
                                slug=attr.get("slug") or None,
                                parent_id=None,
                                depth=node.depth + 1,
                                path=[*node.path, title],
                            )
                        )
                    progress.bump(arib=arib, path=node.path, queue_len=len(queue))

            progress.bump(arib=arib, path=[arib, "done"], queue_len=0, force=True)
            brand_nodes = progress.nodes - brand_start_nodes
            brand_assemblies = stats["assemblies"] - brand_start_assemblies
            _log_line(
                log_fp,
                f"scan done arib={arib} brand_nodes={brand_nodes} brand_assemblies={brand_assemblies} "
                f"total_nodes={progress.nodes} total_assemblies={stats['assemblies']} "
                f"api_calls={stats['api_calls']} errors={stats['errors']}",
            )

    stats["nodes"] = progress.nodes
    return dict(grouped), stats


def _brand_report(
    *,
    brand_normalized: str,
    brand_name: str,
    local: EntitySets,
    remote: EntitySets,
    sample_limit: int,
) -> dict[str, Any]:
    local_counts = local.counts()
    remote_counts = remote.counts()
    matched = local.assemblies & remote.assemblies
    missing_assemblies = sorted(local.assemblies - remote.assemblies)
    extra_assemblies = sorted(remote.assemblies - local.assemblies)
    local_asm = local_counts["assemblies"] or 1
    return {
        "brand_name": brand_name,
        "brand_normalized": brand_normalized,
        "local": local_counts,
        "remote": remote_counts,
        "delta": {
            "models": local_counts["models"] - remote_counts["models"],
            "variants": local_counts["variants"] - remote_counts["variants"],
            "assemblies": local_counts["assemblies"] - remote_counts["assemblies"],
        },
        "matched_assemblies_count": len(matched),
        "matched_assemblies_pct": round(100.0 * len(matched) / local_asm, 1),
        "missing_assemblies_count": len(missing_assemblies),
        "extra_assemblies_count": len(extra_assemblies),
        "missing_assemblies_sample": missing_assemblies[:sample_limit],
        "extra_assemblies_sample": extra_assemblies[:sample_limit],
    }


def run_audit(*, output: str | None = None, log_path: str | None = None, sample_limit: int = 30) -> dict[str, Any]:
    """Compare local DB vs Remotors API (GetAssembly tree only, no HTML, no DB writes)."""
    started = time.time()
    brands = _local_brands()
    allowed = {row["normalized_name"] for row in brands}
    brand_names = {row["normalized_name"]: row["name"] for row in brands}
    arib_codes = _ari_codes_for_brands(allowed)

    log_fp: TextIO | None = None
    if log_path:
        path = Path(log_path)
        path.parent.mkdir(parents=True, exist_ok=True)
        log_fp = path.open("a", encoding="utf-8")

    try:
        _log_line(log_fp, f"audit start brands={len(brands)} arib_codes={','.join(arib_codes)}")
        _log_line(
            log_fp,
            "remote scan uses Parts/GetAssembly JSON only (no GetDetails/HTML, no DB/image writes)",
        )

        _log_line(log_fp, "loading local catalog from PostgreSQL...")
        local_by_brand = _local_sets_by_brand()
        local_assemblies = sum(len(b.assemblies) for b in local_by_brand.values())
        _log_line(
            log_fp,
            f"local catalog loaded brands={len(local_by_brand)} assemblies={local_assemblies}",
        )
        _log_line(log_fp, "starting remote scan (progress every ~15s or 250 nodes)...")
        remote_by_brand, remote_stats = _scan_remote(
            arib_codes=arib_codes,
            allowed_brands=allowed,
            log_fp=log_fp,
        )

        brand_reports: list[dict[str, Any]] = []
        totals = {
            "local": {"models": 0, "variants": 0, "assemblies": 0},
            "remote": {"models": 0, "variants": 0, "assemblies": 0},
        }
        for brand_normalized in sorted(allowed):
            local = local_by_brand.get(brand_normalized, EntitySets())
            remote = remote_by_brand.get(brand_normalized, EntitySets())
            report = _brand_report(
                brand_normalized=brand_normalized,
                brand_name=brand_names.get(brand_normalized, brand_normalized),
                local=local,
                remote=remote,
                sample_limit=sample_limit,
            )
            brand_reports.append(report)
            for key in ("models", "variants", "assemblies"):
                totals["local"][key] += report["local"][key]
                totals["remote"][key] += report["remote"][key]

        totals["delta"] = {
            key: totals["local"][key] - totals["remote"][key] for key in ("models", "variants", "assemblies")
        }

        payload = {
            "source": SOURCE_CODE,
            "ari_codes_scanned": arib_codes,
            "brands_in_db": [brand_names[b] for b in sorted(allowed)],
            "note": (
                "Counts use the same classification keys as the Remotors crawler. "
                "Assembly keys prefer arib:slug (legacy aria-only external_id in DB is normalized). "
                "GetDetails/HTML is not used in the remote scan."
            ),
            "assembly_key_format": "arib:slug (fallback arib:aria)",
            "duration_seconds": round(time.time() - started, 1),
            "remote_scan_stats": remote_stats,
            "totals": totals,
            "brands": brand_reports,
        }

        _log_line(
            log_fp,
            "audit done "
            f"local assemblies={totals['local']['assemblies']} "
            f"remote assemblies={totals['remote']['assemblies']} "
            f"delta={totals['delta']['assemblies']} "
            f"duration_s={payload['duration_seconds']}",
        )

        if output:
            out = Path(output)
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            _log_line(log_fp, f"wrote report {out}")

        return payload
    finally:
        if log_fp is not None:
            log_fp.close()
