"""Apply PostgreSQL schema migrations (idempotent, tracked in oem_schema_migrations)."""

from __future__ import annotations

from pathlib import Path

from app.db import get_conn

MIGRATIONS_DIR = Path(__file__).resolve().parent.parent / "db" / "migrations"


def apply_migrations() -> list[str]:
    applied: list[str] = []
    if not MIGRATIONS_DIR.is_dir():
        return applied

    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                CREATE TABLE IF NOT EXISTS oem_schema_migrations (
                  name TEXT PRIMARY KEY,
                  applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )
                """
            )
            cur.execute("SELECT name FROM oem_schema_migrations")
            done = {row["name"] for row in cur.fetchall()}

            for path in sorted(MIGRATIONS_DIR.glob("*.sql")):
                if path.name in done:
                    continue
                sql = path.read_text(encoding="utf-8")
                for statement in _split_sql_statements(sql):
                    cur.execute(statement)
                cur.execute(
                    "INSERT INTO oem_schema_migrations (name) VALUES (%s)",
                    (path.name,),
                )
                applied.append(path.name)
        conn.commit()
    return applied


def _split_sql_statements(sql: str) -> list[str]:
    statements: list[str] = []
    for chunk in sql.split(";"):
        lines: list[str] = []
        for line in chunk.splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith("--"):
                continue
            lines.append(stripped)
        statement = " ".join(lines).strip()
        if statement:
            statements.append(statement)
    return statements
