"""Compare Remotors SQLite snapshot against local PostgreSQL catalog."""

from __future__ import annotations

import json
import sqlite3
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from app.db import get_conn
from app.importers.remotors_catalog import (
    HIDDEN_CANONICAL_BRANDS,
    assembly_compare_key,
    assembly_match_token,
    best_assembly_compare_key,
    snapshot_model_key,
    snapshot_variant_key,
)
from app.importers.remotors_snapshot import open_snapshot
from app.normalization import normalize_text

SOURCE_CODE = "remotors_ari"


def _load_assembly_source_identities() -> dict[int, list[dict[str, Any]]]:
    """Direct source_node_id plus link-table nodes for each assembly."""
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT a.id AS assembly_id, sn.external_id, sn.arib, sn.aria, sn.slug
                FROM oem_assemblies a
                JOIN oem_source_nodes sn ON sn.id = a.source_node_id
                UNION ALL
                SELECT snl.entity_id AS assembly_id, sn.external_id, sn.arib, sn.aria, sn.slug
                FROM oem_source_node_links snl
                JOIN oem_source_nodes sn ON sn.id = snl.source_node_id
                WHERE snl.entity_type = 'assembly'
                """,
            )
            rows = list(cur.fetchall())

    grouped: dict[int, list[dict[str, Any]]] = defaultdict(list)
    seen: dict[int, set[tuple[Any, ...]]] = defaultdict(set)
    for row in rows:
        assembly_id = int(row["assembly_id"])
        identity = (
            row.get("external_id"),
            row.get("arib"),
            row.get("aria"),
            row.get("slug"),
        )
        if identity in seen[assembly_id]:
            continue
        seen[assembly_id].add(identity)
        grouped[assembly_id].append(dict(row))
    return grouped


def _assembly_identity_keys(identity_rows: list[dict[str, Any]]) -> tuple[str | None, str | None, dict[str, Any]]:
    assembly_key = None
    match_token = None
    best_identity: dict[str, Any] = {}
    for identity in identity_rows:
        key = assembly_compare_key(
            arib=identity.get("arib"),
            aria=identity.get("aria"),
            slug=identity.get("slug"),
            external_id=identity.get("external_id"),
        )
        token = assembly_match_token(
            arib=identity.get("arib"),
            aria=identity.get("aria"),
            slug=identity.get("slug"),
            compare_key=key,
        )
        if not key and not token:
            continue
        if assembly_key is None or (key and best_assembly_compare_key([assembly_key, key]) == key):
            assembly_key = key
            match_token = token
            best_identity = identity
    return assembly_key, match_token, best_identity


def _local_catalog() -> dict[str, Any]:
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
                  mf.name AS model_name,
                  vv.id AS variant_id,
                  vv.market_name,
                  vv.year_from,
                  vv.source_designation,
                  vv.variant_section,
                  a.id AS assembly_id,
                  sn.external_id,
                  sn.arib,
                  sn.aria,
                  sn.slug,
                  d.local_path AS diagram_path
                FROM oem_brands b
                JOIN oem_model_families mf ON mf.brand_id = b.id
                JOIN oem_vehicle_types vt ON vt.id = mf.vehicle_type_id
                LEFT JOIN oem_vehicle_variants vv ON vv.model_family_id = mf.id
                LEFT JOIN oem_assemblies a ON a.vehicle_variant_id = vv.id
                LEFT JOIN oem_source_nodes sn ON sn.id = a.source_node_id
                LEFT JOIN oem_diagrams d ON d.assembly_id = a.id
                WHERE b.normalized_name <> ALL(%s)
                """,
                (list(hidden),),
            )
            rows = list(cur.fetchall())

    assembly_identities = _load_assembly_source_identities()

    models: set[str] = set()
    variants: dict[str, dict[str, Any]] = {}
    assemblies: dict[str, dict[str, Any]] = {}
    assemblies_by_token: dict[str, dict[str, Any]] = {}
    variant_assembly_keys: dict[str, set[str]] = defaultdict(set)
    variant_assembly_tokens: dict[str, set[str]] = defaultdict(set)
    brand_names: dict[str, str] = {}

    for row in rows:
        brand = row["brand_normalized"]
        brand_names[brand] = row["brand_name"]
        model_key = snapshot_model_key(
            vehicle_type=row["vehicle_type"],
            model_name=row.get("market_name") or row["model_name"],
        )
        models.add(model_key)
        if row["variant_id"] is not None:
            variant_key = snapshot_variant_key(
                vehicle_type=row["vehicle_type"],
                market_name=row.get("market_name"),
                source_designation=row.get("source_designation"),
                year_from=row["year_from"],
                variant_section=row.get("variant_section"),
            )
            bucket = variants.setdefault(
                variant_key,
                {
                    "variant_id": row["variant_id"],
                    "brand_normalized": brand,
                    "brand_name": row["brand_name"],
                    "model_name": row["model_name"],
                    "year_from": row["year_from"],
                    "assembly_count": 0,
                    "assembly_key_count": 0,
                    "assembly_token_count": 0,
                    "assemblies_with_images": 0,
                    "_assembly_ids": set(),
                    "_assembly_keys": set(),
                    "_assembly_tokens": set(),
                    "_assemblies_with_images": set(),
                },
            )
            if row["assembly_id"] is not None:
                asm_id = int(row["assembly_id"])
                if asm_id not in bucket["_assembly_ids"]:
                    bucket["_assembly_ids"].add(asm_id)
                    bucket["assembly_count"] += 1
                    if row.get("diagram_path"):
                        bucket["assemblies_with_images"] += 1
                elif row.get("diagram_path"):
                    if asm_id not in bucket["_assemblies_with_images"]:
                        bucket["_assemblies_with_images"].add(asm_id)
                        bucket["assemblies_with_images"] += 1
                identity_rows = list(assembly_identities.get(int(row["assembly_id"]), []))
                if not identity_rows and row.get("arib"):
                    identity_rows = [
                        {
                            "external_id": row.get("external_id"),
                            "arib": row.get("arib"),
                            "aria": row.get("aria"),
                            "slug": row.get("slug"),
                        }
                    ]
                assembly_key, match_token, best_identity = _assembly_identity_keys(identity_rows)
                if match_token:
                    if match_token not in bucket["_assembly_tokens"]:
                        bucket["_assembly_tokens"].add(match_token)
                        bucket["assembly_token_count"] += 1
                    variant_assembly_tokens[variant_key].add(match_token)
                if assembly_key:
                    if assembly_key not in bucket["_assembly_keys"]:
                        bucket["_assembly_keys"].add(assembly_key)
                        bucket["assembly_key_count"] += 1
                    variant_assembly_keys[variant_key].add(assembly_key)
                    entry = {
                        "assembly_id": row["assembly_id"],
                        "variant_id": row["variant_id"],
                        "variant_key": variant_key,
                        "brand_normalized": brand,
                        "has_image": bool(row.get("diagram_path")),
                        "slug": best_identity.get("slug") or row.get("slug"),
                        "arib": best_identity.get("arib") or row.get("arib"),
                        "aria": best_identity.get("aria") or row.get("aria"),
                        "match_token": match_token,
                        "assembly_key": assembly_key,
                    }
                    assemblies[assembly_key] = entry
                    if match_token and match_token not in assemblies_by_token:
                        assemblies_by_token[match_token] = entry

    cleaned_variants: dict[str, dict[str, Any]] = {}
    for key, value in variants.items():
        cleaned_variants[key] = {
            kk: vv for kk, vv in value.items() if not kk.startswith("_")
        }
    return {
        "models": models,
        "variants": cleaned_variants,
        "assemblies": assemblies,
        "assemblies_by_token": assemblies_by_token,
        "variant_assembly_keys": {
            key: set(keys) for key, keys in variant_assembly_keys.items()
        },
        "variant_assembly_tokens": {
            key: set(tokens) for key, tokens in variant_assembly_tokens.items()
        },
        "brand_names": brand_names,
    }


def _remote_catalog(conn: sqlite3.Connection) -> dict[str, Any]:
    models = {
        row["model_key"]
        for row in conn.execute("SELECT model_key FROM catalog_models").fetchall()
    }
    variants = {
        row["variant_key"]: dict(row)
        for row in conn.execute("SELECT * FROM catalog_variants").fetchall()
    }
    assemblies: dict[str, dict[str, Any]] = {}
    assemblies_by_token: dict[str, dict[str, Any]] = {}
    variant_assembly_keys: dict[str, set[str]] = defaultdict(set)
    variant_assembly_tokens: dict[str, set[str]] = defaultdict(set)
    for row in conn.execute("SELECT * FROM catalog_assemblies").fetchall():
        row_dict = dict(row)
        path = json.loads(row_dict["path_json"])
        assembly_key = assembly_compare_key(
            arib=row_dict.get("arib"),
            aria=row_dict.get("aria"),
            slug=row_dict.get("slug"),
            path=path,
        )
        match_token = assembly_match_token(
            arib=row_dict.get("arib"),
            aria=row_dict.get("aria"),
            slug=row_dict.get("slug"),
            path=path,
            compare_key=assembly_key,
        )
        if match_token:
            variant_assembly_tokens[row_dict["variant_key"]].add(match_token)
            if match_token not in assemblies_by_token:
                assemblies_by_token[match_token] = {**row_dict, "path": path, "match_token": match_token}
        if not assembly_key:
            continue
        variant_assembly_keys[row_dict["variant_key"]].add(assembly_key)
        if assembly_key not in assemblies:
            assemblies[assembly_key] = {**row_dict, "path": path, "match_token": match_token}
    for variant_key, row in variants.items():
        tokens = variant_assembly_tokens.get(variant_key, set())
        row["assembly_count"] = len(tokens) or len(variant_assembly_keys.get(variant_key, set()))
        row["assembly_token_count"] = len(tokens)
    return {
        "models": models,
        "variants": variants,
        "assemblies": assemblies,
        "assemblies_by_token": assemblies_by_token,
        "variant_assembly_keys": dict(variant_assembly_keys),
        "variant_assembly_tokens": dict(variant_assembly_tokens),
    }


def compare_snapshot(
    *,
    snapshot_path: str,
    output: str | None = None,
    sample_limit: int = 50,
) -> dict[str, Any]:
    """Build unified gap report: snapshot (Remotors) vs local PostgreSQL."""
    local = _local_catalog()
    conn = open_snapshot(snapshot_path)
    try:
        remote = _remote_catalog(conn)
        meta = {
            row["key"]: row["value"]
            for row in conn.execute("SELECT key, value FROM meta").fetchall()
        }
    finally:
        conn.close()

    local_tokens = set(local["assemblies_by_token"])
    remote_tokens = set(remote["assemblies_by_token"])

    missing_models = sorted(remote["models"] - local["models"])
    missing_variants_keys = sorted(remote["variants"].keys() - local["variants"].keys())

    remote_variant_tokens = remote["variant_assembly_tokens"]
    local_variant_tokens = local["variant_assembly_tokens"]
    remote_variant_keys = remote["variant_assembly_keys"]
    local_variant_keys = local["variant_assembly_keys"]

    thin_variants: list[dict[str, Any]] = []
    key_mismatch_variants: list[dict[str, Any]] = []
    for variant_key, remote_row in remote["variants"].items():
        local_row = local["variants"].get(variant_key)
        if not local_row:
            continue
        remote_tokens_v = remote_variant_tokens.get(variant_key, set())
        local_tokens_v = local_variant_tokens.get(variant_key, set())
        remote_keys = remote_variant_keys.get(variant_key, set())
        local_keys = local_variant_keys.get(variant_key, set())
        missing_tokens = remote_tokens_v - local_tokens_v
        missing_keys = remote_keys - local_keys

        if missing_tokens:
            thin_variants.append(
                {
                    "variant_key": variant_key,
                    "variant_id": local_row["variant_id"],
                    "brand_name": local_row["brand_name"],
                    "model_name": local_row["model_name"],
                    "year_from": local_row["year_from"],
                    "local_assemblies": local_row.get("assembly_count", 0),
                    "local_assembly_keys": len(local_keys),
                    "local_assembly_tokens": len(local_tokens_v),
                    "remote_assemblies": int(remote_row.get("assembly_count") or 0),
                    "remote_assembly_keys": len(remote_keys),
                    "remote_assembly_tokens": len(remote_tokens_v),
                    "missing_assemblies": len(missing_tokens),
                    "missing_assembly_keys": len(missing_keys),
                    "missing_assembly_tokens": len(missing_tokens),
                    "missing_assembly_tokens_sample": sorted(missing_tokens)[:20],
                    "repair_arib": remote_row["repair_arib"],
                    "repair_aria": remote_row["repair_aria"],
                    "repair_title": remote_row["repair_title"],
                    "repair_path": json.loads(remote_row["repair_path_json"]),
                }
            )
        elif missing_keys:
            key_mismatch_variants.append(
                {
                    "variant_key": variant_key,
                    "variant_id": local_row["variant_id"],
                    "brand_name": local_row["brand_name"],
                    "model_name": local_row["model_name"],
                    "year_from": local_row["year_from"],
                    "local_assembly_keys": len(local_keys),
                    "remote_assembly_keys": len(remote_keys),
                    "key_only_mismatch": len(missing_keys),
                    "note": "aria tokens match; canonical arib:aria keys differ (run fix-remotors-assembly-arib-from-brand)",
                }
            )
    thin_variants.sort(key=lambda item: item["missing_assembly_tokens"], reverse=True)

    missing_variants = []
    for variant_key in missing_variants_keys:
        row = remote["variants"][variant_key]
        missing_variants.append(
            {
                "variant_key": variant_key,
                "brand_normalized": row["brand_normalized"],
                "brand_name": row["brand_name"],
                "model_name": row["model_name"],
                "year_from": row["year_from"],
                "source_designation": row["source_designation"],
                "remote_assemblies": int(row["assembly_count"] or 0),
                "repair_arib": row["repair_arib"],
                "repair_aria": row["repair_aria"],
                "repair_slug": row.get("repair_slug"),
                "repair_title": row["repair_title"],
                "repair_path": json.loads(row["repair_path_json"]),
            }
        )

    missing_assemblies = []
    seen_missing_tokens: set[str] = set()
    for token in sorted(remote_tokens - local_tokens):
        if token in seen_missing_tokens:
            continue
        seen_missing_tokens.add(token)
        row = remote["assemblies_by_token"][token]
        assembly_key = assembly_compare_key(
            arib=row.get("arib"),
            aria=row.get("aria"),
            slug=row.get("slug"),
            path=row.get("path") or json.loads(row["path_json"]),
        )
        missing_assemblies.append(
            {
                "assembly_key": assembly_key or token,
                "match_token": token,
                "brand_name": row["brand_name"],
                "variant_key": row["variant_key"],
                "arib": row["arib"],
                "aria": row.get("aria"),
                "slug": row.get("slug"),
                "title": row["title"],
                "path": row.get("path") or json.loads(row["path_json"]),
            }
        )

    missing_images = []
    for token, local_row in local["assemblies_by_token"].items():
        if local_row.get("has_image"):
            continue
        remote_row = remote["assemblies_by_token"].get(token)
        assembly_key = local_row.get("assembly_key")
        missing_images.append(
            {
                "assembly_key": assembly_key or token,
                "match_token": token,
                "assembly_id": local_row["assembly_id"],
                "variant_id": local_row["variant_id"],
                "brand_normalized": local_row["brand_normalized"],
                "slug": local_row.get("slug") or (remote_row.get("slug") if remote_row else None),
                "arib": local_row.get("arib") or (remote_row.get("arib") if remote_row else None),
                "title": remote_row.get("title") if remote_row else None,
                "path": (
                    remote_row.get("path")
                    if remote_row and remote_row.get("path")
                    else (json.loads(remote_row["path_json"]) if remote_row else None)
                ),
            }
        )

    matched_tokens = len(local_tokens & remote_tokens)
    remote_token_count = len(remote_tokens) or 1
    matched_keys = len(set(local["assemblies"]) & set(remote["assemblies"]))

    brand_summary: list[dict[str, Any]] = []
    brand_norms = set(local["brand_names"]) | {
        row["brand_normalized"] for row in remote["variants"].values()
    }
    for brand_norm in sorted(brand_norms):
        local_vk = {k for k, v in local["variants"].items() if v["brand_normalized"] == brand_norm}
        remote_vk = {k for k, v in remote["variants"].items() if v["brand_normalized"] == brand_norm}
        local_tok = {
            token
            for vk in local_vk
            for token in local_variant_tokens.get(vk, set())
        }
        remote_tok = {
            token
            for vk in remote_vk
            for token in remote_variant_tokens.get(vk, set())
        }
        brand_summary.append(
            {
                "brand_normalized": brand_norm,
                "brand_name": local["brand_names"].get(brand_norm, brand_norm),
                "local": {
                    "variants": len(local_vk),
                    "assembly_tokens": len(local_tok),
                },
                "remote": {
                    "variants": len(remote_vk),
                    "assembly_tokens": len(remote_tok),
                },
                "missing_variants": len(remote_vk - local_vk),
                "thin_variants": sum(
                    1
                    for vk in local_vk & remote_vk
                    if remote_variant_tokens.get(vk, set()) - local_variant_tokens.get(vk, set())
                ),
                "key_mismatch_variants": sum(
                    1
                    for vk in local_vk & remote_vk
                    if remote_variant_tokens.get(vk, set()) == local_variant_tokens.get(vk, set())
                    and remote_variant_keys.get(vk, set()) != local_variant_keys.get(vk, set())
                ),
                "missing_assembly_tokens": len(remote_tok - local_tok),
                "matched_assembly_tokens": len(local_tok & remote_tok),
            }
        )

    payload = {
        "snapshot_path": snapshot_path,
        "snapshot_meta": meta,
        "compared_at": datetime.now(timezone.utc).isoformat(),
        "assembly_match_format": "aria:… when aria present, else slug:… (brand-agnostic)",
        "assembly_key_format": "canonical arib:aria (BRP for BRP_SKI/BRP_SEA; fallback normalized slug)",
        "totals": {
            "local": {
                "models": len(local["models"]),
                "variants": len(local["variants"]),
                "assemblies": len(local["assemblies"]),
                "assembly_tokens": len(local_tokens),
            },
            "remote": {
                "models": len(remote["models"]),
                "variants": len(remote["variants"]),
                "assemblies": len(remote["assemblies"]),
                "assembly_tokens": len(remote_tokens),
            },
            "missing_models": len(missing_models),
            "missing_variants": len(missing_variants_keys),
            "thin_variants": len(thin_variants),
            "key_mismatch_variants": len(key_mismatch_variants),
            "variants_with_missing_assembly_tokens": len(thin_variants),
            "missing_assemblies": len(missing_assemblies),
            "missing_images": len(missing_images),
            "matched_assembly_tokens": matched_tokens,
            "remote_assembly_tokens_missing_locally": len(missing_assemblies),
            "remote_assembly_tokens_matched_pct": round(100.0 * matched_tokens / remote_token_count, 1),
            "matched_assembly_keys": matched_keys,
            "local_assembly_keys_not_in_snapshot": len(set(local["assemblies"]) - set(remote["assemblies"])),
        },
        "brands": brand_summary,
        "missing_variants": missing_variants[:sample_limit],
        "missing_variants_count": len(missing_variants),
        "thin_variants": thin_variants[:sample_limit],
        "thin_variants_count": len(thin_variants),
        "key_mismatch_variants": key_mismatch_variants[:sample_limit],
        "key_mismatch_variants_count": len(key_mismatch_variants),
        "missing_assemblies": missing_assemblies[:sample_limit],
        "missing_assemblies_count": len(missing_assemblies),
        "missing_images": missing_images[:sample_limit],
        "missing_images_count": len(missing_images),
        "sync_plan": {
            "variant_roots_to_crawl": len(
                {
                    (item["repair_arib"], item["repair_aria"])
                    for item in missing_variants + thin_variants
                }
            ),
            "assemblies_to_import": len(missing_assemblies),
            "images_to_download": len(missing_images),
        },
        "_full": {
            "missing_variants": missing_variants,
            "thin_variants": thin_variants,
            "key_mismatch_variants": key_mismatch_variants,
            "missing_assemblies": missing_assemblies,
            "missing_images": missing_images,
        },
    }

    if output:
        out = Path(output)
        out.parent.mkdir(parents=True, exist_ok=True)
        public = {k: v for k, v in payload.items() if k != "_full"}
        out.write_text(json.dumps(public, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        sync_path = out.with_suffix(".sync.json")
        sync_path.write_text(
            json.dumps(
                {
                    "snapshot_path": snapshot_path,
                    "compared_at": payload["compared_at"],
                    "missing_variants": missing_variants,
                    "thin_variants": thin_variants,
                    "missing_assemblies": missing_assemblies,
                    "missing_images": missing_images,
                },
                ensure_ascii=False,
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        payload["sync_data_path"] = str(sync_path)
        payload["gaps_sync_path"] = str(sync_path)

    return payload
