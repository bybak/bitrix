from __future__ import annotations

from typing import Any

from app.db import get_conn
from app.remotors_v3.snapshot import open_snapshot


def verify_v3(*, snapshot_path: str) -> dict[str, Any]:
    snap = open_snapshot(snapshot_path)
    try:
        snap_variants = int(snap.execute("SELECT COUNT(*) AS c FROM catalog_variants").fetchone()["c"])
        snap_assemblies = int(snap.execute("SELECT COUNT(*) AS c FROM catalog_assemblies").fetchone()["c"])
    finally:
        snap.close()

    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS c FROM oem_variants")
            pg_variants = int(cur.fetchone()["c"])
            cur.execute("SELECT COUNT(*) AS c FROM oem_assemblies")
            pg_assemblies = int(cur.fetchone()["c"])
            cur.execute("SELECT COUNT(*) AS c FROM oem_details_pages WHERE html_status='ok'")
            html_ok = int(cur.fetchone()["c"])
            cur.execute("SELECT COUNT(*) AS c FROM oem_details_pages WHERE parse_status='ok'")
            parse_ok = int(cur.fetchone()["c"])
            cur.execute(
                """
                SELECT COUNT(*) AS c FROM (
                  SELECT a.id
                  FROM oem_assemblies a
                  LEFT JOIN oem_assembly_parts ap ON ap.assembly_id = a.id
                  GROUP BY a.id
                  HAVING COUNT(ap.id) = 0
                ) z
                """
            )
            zero_parts = int(cur.fetchone()["c"])

    issues: list[str] = []
    if pg_variants != snap_variants:
        issues.append(f"variant count mismatch snapshot={snap_variants} pg={pg_variants}")
    if pg_assemblies != snap_assemblies:
        issues.append(f"assembly count mismatch snapshot={snap_assemblies} pg={pg_assemblies}")

    return {
        "snapshot_variants": snap_variants,
        "snapshot_assemblies": snap_assemblies,
        "pg_variants": pg_variants,
        "pg_assemblies": pg_assemblies,
        "html_ok": html_ok,
        "parse_ok": parse_ok,
        "zero_part_assemblies": zero_parts,
        "ok": not issues,
        "issues": issues,
    }
