#!/usr/bin/env python3
"""Compare two Polaris PartStream structure snapshots (US vs Canada)."""

from __future__ import annotations

import argparse
import json
import sqlite3
from pathlib import Path


def _connect(path: Path) -> sqlite3.Connection:
    if not path.is_file():
        raise SystemExit(f"snapshot not found: {path}")
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    return con


def _counts(con: sqlite3.Connection) -> dict[str, int]:
    out: dict[str, int] = {}
    for table in ("api_nodes", "catalog_variants", "catalog_assemblies", "scan_errors"):
        try:
            out[table] = int(con.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0])
        except sqlite3.DatabaseError:
            out[table] = -1
    return out


def _assembly_keys(con: sqlite3.Connection) -> set[str]:
    rows = con.execute(
        """
        SELECT assembly_key, title, path_json
        FROM catalog_assemblies
        """
    ).fetchall()
    keys: set[str] = set()
    for row in rows:
        path = json.loads(row["path_json"] or "[]")
        title = (row["title"] or "").strip()
        # Compare by browse path + title, ignore root_arib prefix in assembly_key.
        keys.add(json.dumps([*path, title], ensure_ascii=False, separators=(",", ":")))
    return keys


def _variant_keys(con: sqlite3.Connection) -> set[str]:
    rows = con.execute("SELECT path_json, model_name FROM catalog_variants").fetchall()
    keys: set[str] = set()
    for row in rows:
        path = json.loads(row["path_json"] or "[]")
        keys.add(json.dumps(path, ensure_ascii=False, separators=(",", ":")))
    return keys


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--us", default="storage/polaris-snapshot-pol.db")
    parser.add_argument("--cdn", default="storage/polaris-snapshot-polcdn.db")
    parser.add_argument("--sample", type=int, default=15)
    args = parser.parse_args()

    us_path = Path(args.us)
    cdn_path = Path(args.cdn)
    us = _connect(us_path)
    cdn = _connect(cdn_path)

    print(f"US  {us_path}")
    print(f"CDN {cdn_path}")
    print()
    us_counts = _counts(us)
    cdn_counts = _counts(cdn)
    print("counts")
    for key in us_counts:
        print(f"  {key:22} us={us_counts[key]:7}  cdn={cdn_counts[key]:7}  delta={cdn_counts[key] - us_counts[key]:+7}")

    us_asm = _assembly_keys(us)
    cdn_asm = _assembly_keys(cdn)
    only_us = sorted(us_asm - cdn_asm)
    only_cdn = sorted(cdn_asm - us_asm)
    both = len(us_asm & cdn_asm)
    print()
    print("assemblies by path+title")
    print(f"  both={both}  only_us={len(only_us)}  only_cdn={len(only_cdn)}")
    if only_us:
        print("  sample only_us:")
        for item in only_us[: args.sample]:
            print(f"    {item}")
    if only_cdn:
        print("  sample only_cdn:")
        for item in only_cdn[: args.sample]:
            print(f"    {item}")

    us_var = _variant_keys(us)
    cdn_var = _variant_keys(cdn)
    print()
    print("variants by path")
    print(
        f"  both={len(us_var & cdn_var)}  only_us={len(us_var - cdn_var)}  only_cdn={len(cdn_var - us_var)}"
    )


if __name__ == "__main__":
    main()
