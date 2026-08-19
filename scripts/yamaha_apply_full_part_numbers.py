#!/usr/bin/env python3
"""Replace short Yamaha part numbers with MegaZip full numbers on EU/JP.

Uses oem_parts.full_part_number collected for YMH-JP. Mapping is applied to
YMH-EU and YMH-JP diagrams (oem_parts.part_number / normalized_part_number).

Only same-part expansions are applied, e.g.:
  95812-06014  →  95812-06014-00
NOT replacement substitutions like:
  95827-10050  →  95817-10050-00   (handled by replacements script)

If no full number is found for a short number, the row is left unchanged.
If the target full number already exists as another row, this short number
is also left unchanged (no merge, no delete).

Does not run anything by itself. Commands:
  ./scripts/yamaha_apply_full_part_numbers.sh status
  ./scripts/yamaha_apply_full_part_numbers.sh apply --dry-run
  ./scripts/yamaha_apply_full_part_numbers.sh apply
"""

from __future__ import annotations

import argparse
import json
import os
import re
import time
from pathlib import Path
from typing import Any

try:
    import psycopg
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg — run via scripts/yamaha_apply_full_part_numbers.sh") from exc


ROOTS = ("YMH-EU", "YMH-JP")
MAP_ROOT = "YMH-JP"
DEFAULT_WORK = Path("/app/storage/yamaha-full-pn")
BATCH = 500


def _dsn() -> str:
    return (
        os.environ.get("OEM_YAMAHA_DATABASE_DSN")
        or "postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog"
    )


def _connect() -> psycopg.Connection:
    return psycopg.connect(_dsn(), row_factory=dict_row)


def _log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def work_paths(work_dir: Path) -> dict[str, Path]:
    work_dir.mkdir(parents=True, exist_ok=True)
    return {
        "report": work_dir / "apply_report.json",
        "samples": work_dir / "mismatch_samples.jsonl",
    }


def normalize_part_number(value: str | None) -> str:
    if not value:
        return ""
    return re.sub(r"[^A-Z0-9]", "", value.upper())


def is_same_part_full(short: str, full: str) -> bool:
    """True if `full` is a longer/hyphenated form of the same catalog number."""
    ns = normalize_part_number(short)
    nf = normalize_part_number(full)
    if not ns or not nf:
        return False
    if ns == nf:
        return (short or "").strip() != (full or "").strip()
    # Typical Yamaha: 9581206014 → 958120601400
    return nf.startswith(ns) and len(nf) > len(ns)


def ensure_schema(conn: psycopg.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            ALTER TABLE oem_parts
              ADD COLUMN IF NOT EXISTS original_part_number VARCHAR(255)
            """
        )
    conn.commit()
    _log("schema: original_part_number ready")


def load_jp_map(conn: psycopg.Connection) -> tuple[dict[str, str], dict[str, str], int]:
    """Build short→full maps from YMH-JP MegaZip full_part_number.

    Returns (by_part_number, by_normalized, mismatch_count).
    """
    by_pn: dict[str, str] = {}
    by_norm: dict[str, str] = {}
    mismatch = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT part_number, normalized_part_number, full_part_number
            FROM oem_parts
            WHERE root_arib = %s
              AND full_part_number IS NOT NULL
              AND BTRIM(full_part_number) <> ''
            """,
            (MAP_ROOT,),
        )
        for row in cur:
            short = (row["part_number"] or "").strip()
            full = (row["full_part_number"] or "").strip()
            if not short or not full:
                continue
            if not is_same_part_full(short, full) and normalize_part_number(short) != normalize_part_number(full):
                mismatch += 1
                continue
            if normalize_part_number(short) == normalize_part_number(full) and short == full:
                continue
            by_pn.setdefault(short, full)
            ns = normalize_part_number(short)
            if ns:
                by_norm.setdefault(ns, full)
    return by_pn, by_norm, mismatch


def resolve_full(part_number: str, normalized: str, by_pn: dict[str, str], by_norm: dict[str, str]) -> str | None:
    full = by_pn.get(part_number) or by_norm.get(normalized or "") or by_norm.get(normalize_part_number(part_number))
    if not full:
        return None
    if not is_same_part_full(part_number, full) and normalize_part_number(part_number) != normalize_part_number(full):
        return None
    if (part_number or "").strip() == full.strip():
        return None
    return full


def cmd_status(work_dir: Path) -> None:
    with _connect() as conn:
        ensure_schema(conn)
        by_pn, by_norm, mismatch = load_jp_map(conn)
        _log(f"map from {MAP_ROOT}: by_part_number={len(by_pn)} by_normalized={len(by_norm)} skipped_replacements={mismatch}")
        report: dict[str, Any] = {
            "map_size": len(by_pn),
            "map_norm_size": len(by_norm),
            "skipped_replacements": mismatch,
            "roots": {},
        }
        with conn.cursor() as cur:
            for root in ROOTS:
                cur.execute(
                    """
                    SELECT
                      COUNT(*) AS parts,
                      COUNT(*) FILTER (WHERE original_part_number IS NOT NULL) AS already_applied,
                      COUNT(*) FILTER (
                        WHERE full_part_number IS NOT NULL AND BTRIM(full_part_number) <> ''
                      ) AS with_local_full
                    FROM oem_parts
                    WHERE root_arib = %s
                    """,
                    (root,),
                )
                row = dict(cur.fetchone())
                cur.execute(
                    """
                    SELECT part_number, normalized_part_number, full_part_number
                    FROM oem_parts
                    WHERE root_arib = %s
                    """,
                    (root,),
                )
                would = 0
                already_full = 0
                for p in cur:
                    pn = (p["part_number"] or "").strip()
                    full = resolve_full(pn, p["normalized_part_number"] or "", by_pn, by_norm)
                    if p.get("full_part_number") and root == MAP_ROOT:
                        local = (p["full_part_number"] or "").strip()
                        if is_same_part_full(pn, local) or normalize_part_number(pn) == normalize_part_number(local):
                            if pn != local:
                                full = full or local
                    if not full:
                        continue
                    if pn == full:
                        already_full += 1
                        continue
                    would += 1
                row["would_rename"] = would
                row["already_equals_full"] = already_full
                report["roots"][root] = row
                _log(
                    f"{root}: parts={row['parts']} would_rename={would} "
                    f"already_applied={row['already_applied']}"
                )
    paths = work_paths(work_dir)
    paths["report"].write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2), flush=True)


def _candidate_rows(
    conn: psycopg.Connection,
    *,
    root: str,
    by_pn: dict[str, str],
    by_norm: dict[str, str],
) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, part_number, normalized_part_number, full_part_number, original_part_number
            FROM oem_parts
            WHERE root_arib = %s
            ORDER BY id
            """,
            (root,),
        )
        for p in cur:
            pn = (p["part_number"] or "").strip()
            full = resolve_full(pn, p["normalized_part_number"] or "", by_pn, by_norm)
            if not full and p.get("full_part_number") and root == MAP_ROOT:
                local = (p["full_part_number"] or "").strip()
                if local and (
                    is_same_part_full(pn, local)
                    or normalize_part_number(pn) == normalize_part_number(local)
                ):
                    full = local if pn != local else None
            if not full or pn == full:
                continue
            out.append(
                {
                    "id": int(p["id"]),
                    "part_number": pn,
                    "normalized": p["normalized_part_number"] or "",
                    "full": full,
                    "full_norm": normalize_part_number(full),
                    "original_part_number": p.get("original_part_number"),
                }
            )
    return out


def cmd_apply(work_dir: Path, *, dry_run: bool, batch_size: int) -> None:
    paths = work_paths(work_dir)
    t0 = time.time()
    with _connect() as conn:
        ensure_schema(conn)
        by_pn, by_norm, mismatch = load_jp_map(conn)
        _log(
            f"apply start dry_run={dry_run} map={len(by_pn)} "
            f"skipped_replacements={mismatch} roots={list(ROOTS)}"
        )
        report: dict[str, Any] = {
            "dry_run": dry_run,
            "map_size": len(by_pn),
            "skipped_replacements": mismatch,
            "roots": {},
        }

        for root in ROOTS:
            rows = _candidate_rows(conn, root=root, by_pn=by_pn, by_norm=by_norm)
            stats = {
                "candidates": len(rows),
                "renamed": 0,
                "skipped_no_full": 0,
                "skipped_conflict": 0,
                "skipped_empty_norm": 0,
                "errors": 0,
            }
            _log(f"{root}: candidates={len(rows)}")
            if dry_run:
                existing_norms = _existing_norms(conn, root)
                would = 0
                conflict = 0
                claimed: set[str] = set()
                samples = []
                for r in rows:
                    fn = r["full_norm"]
                    if not fn or fn in existing_norms or fn in claimed:
                        conflict += 1
                        continue
                    claimed.add(fn)
                    would += 1
                    if len(samples) < 15:
                        samples.append({"id": r["id"], "from": r["part_number"], "to": r["full"]})
                stats["would_rename"] = would
                stats["skipped_conflict"] = conflict
                stats["samples"] = samples
                report["roots"][root] = stats
                _log(f"{root} dry-run would_rename={would} skipped_conflict={conflict}")
                continue

            total = len(rows)
            for i in range(0, total, batch_size):
                chunk = rows[i : i + batch_size]
                with conn.cursor() as cur:
                    for item in chunk:
                        try:
                            _apply_one(cur, root=root, item=item, stats=stats)
                        except Exception as exc:  # noqa: BLE001
                            stats["errors"] += 1
                            _log(f"{root}: error id={item['id']} {item['part_number']} → {item['full']}: {exc}")
                            conn.rollback()
                            # continue with a fresh transaction
                            continue
                conn.commit()
                done = min(i + batch_size, total)
                elapsed = max(time.time() - t0, 0.001)
                _log(
                    f"apply {root} {done}/{total} ({100.0 * done / max(total, 1):.1f}%) "
                    f"renamed={stats['renamed']} skipped_conflict={stats['skipped_conflict']} "
                    f"errors={stats['errors']} elapsed={elapsed / 60:.1f}min"
                )
            report["roots"][root] = stats
            _log(
                f"{root} done renamed={stats['renamed']} "
                f"skipped_conflict={stats['skipped_conflict']} errors={stats['errors']}"
            )

    report["elapsed_sec"] = round(time.time() - t0, 1)
    paths["report"].write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    _log(f"apply done in {report['elapsed_sec']}s dry_run={dry_run}")
    print(json.dumps(report, ensure_ascii=False, indent=2), flush=True)


def _existing_norms(conn: psycopg.Connection, root: str) -> set[str]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT normalized_part_number
            FROM oem_parts
            WHERE root_arib = %s
            """,
            (root,),
        )
        return {r["normalized_part_number"] for r in cur if r["normalized_part_number"]}


def _apply_one(cur: Any, *, root: str, item: dict[str, Any], stats: dict[str, Any]) -> None:
    full = item["full"]
    full_norm = item["full_norm"]
    part_id = item["id"]
    if not full_norm:
        stats["skipped_empty_norm"] += 1
        return

    cur.execute(
        """
        SELECT id, part_number
        FROM oem_parts
        WHERE id = %s AND root_arib = %s
        FOR UPDATE
        """,
        (part_id, root),
    )
    current = cur.fetchone()
    if not current:
        return
    if (current["part_number"] or "").strip() == full:
        return

    cur.execute(
        """
        SELECT id
        FROM oem_parts
        WHERE root_arib = %s
          AND normalized_part_number = %s
          AND id <> %s
        LIMIT 1
        """,
        (root, full_norm, part_id),
    )
    if cur.fetchone():
        stats["skipped_conflict"] += 1
        return

    cur.execute(
        """
        UPDATE oem_parts
        SET
          original_part_number = COALESCE(original_part_number, part_number),
          part_number = %s,
          normalized_part_number = %s,
          updated_at = now()
        WHERE id = %s
        """,
        (full, full_norm, part_id),
    )
    stats["renamed"] += int(cur.rowcount)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Apply MegaZip full part numbers to YMH-EU / YMH-JP")
    parser.add_argument("command", choices=["status", "apply"])
    parser.add_argument("--work-dir", default=str(DEFAULT_WORK))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--batch-size", type=int, default=BATCH)
    args = parser.parse_args(argv)

    work_dir = Path(args.work_dir)
    if args.command == "status":
        cmd_status(work_dir)
    elif args.command == "apply":
        cmd_apply(work_dir, dry_run=args.dry_run, batch_size=max(1, args.batch_size))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
