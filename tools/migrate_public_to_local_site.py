#!/usr/bin/env python3
"""
One-off migration: move public PHP entrypoints into local/site/ and leave thin wrappers.
Run: python3 tools/migrate_public_to_local_site.py
"""
from __future__ import annotations

import os
import re
import shutil
from pathlib import Path

HERE = Path(__file__).resolve()
ROOT = HERE.parents[1] / "www"
SITE = ROOT / "local" / "site"
PAGES = SITE / "pages"

SKIP_TOP_NAMES = {
    "bitrix",
    "upload",
    "wizards",
    "node_modules",
    ".git",
}

ROOT_INDEX = ROOT / "index.php"

EXTRA_ROOT_PHP = (
    "404.php",
    "top.menu.php",
    "sect_bottom.php",
    "sect_sidebar.php",
    "license_restriction.php",
)


def should_prune_local(rel: Path) -> bool:
    if not rel.parts or rel.parts[0] != "local":
        return False
    if len(rel.parts) >= 2 and rel.parts[1] == "site":
        return False
    return True


def collect_index_like() -> list[Path]:
    out: list[Path] = []
    for dirpath, dirnames, filenames in os.walk(ROOT, topdown=True):
        p = Path(dirpath)
        rel = p.relative_to(ROOT)
        if rel.parts and rel.parts[0] in SKIP_TOP_NAMES:
            dirnames[:] = []
            continue
        if should_prune_local(rel):
            dirnames[:] = []
            continue
        for fn in filenames:
            if fn not in ("index.php", "detail.php", "section.php", "basket.php", "result.php"):
                continue
            fp = p / fn
            if fp == ROOT_INDEX:
                continue
            try:
                txt = fp.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            if re.search(
                r"(/bitrix/header\.php|urlrewrite\.php|prolog_before\.php|prolog_admin)",
                txt,
            ):
                out.append(fp)
    for name in EXTRA_ROOT_PHP:
        fp = ROOT / name
        if fp.is_file():
            out.append(fp)
    return sorted(set(out))


def migrate_root_index() -> None:
    if not ROOT_INDEX.is_file():
        return
    dst = PAGES / "__root_index.php"
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(ROOT_INDEX), str(dst))
    ROOT_INDEX.write_text(
        "<?php\n"
        "require $_SERVER['DOCUMENT_ROOT'].'/local/site/pages/__root_index.php';\n",
        encoding="utf-8",
    )
    print("migrated", ROOT_INDEX.name, "->", dst.relative_to(ROOT))


def migrate_file(src: Path) -> None:
    rel = src.relative_to(ROOT)
    dst = PAGES / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(src), str(dst))
    src.write_text(
        "<?php\n"
        f"require $_SERVER['DOCUMENT_ROOT'].'/local/site/pages/{rel.as_posix()}';\n",
        encoding="utf-8",
    )
    print("migrated", rel.as_posix())


def migrate_dir_contents(src_dir: Path, dst_sub: str) -> None:
    if not src_dir.is_dir():
        return
    dst_dir = SITE / dst_sub
    dst_dir.mkdir(parents=True, exist_ok=True)
    candidates = sorted([p for p in src_dir.rglob("*.php") if p.is_file()])
    for fp in candidates:
        rel = fp.relative_to(src_dir)
        dst = dst_dir / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(str(fp), str(dst))
        fp.write_text(
            "<?php\n"
            f"require $_SERVER['DOCUMENT_ROOT'].'/local/site/{dst_sub}/{rel.as_posix()}';\n",
            encoding="utf-8",
        )
        print("migrated", fp.relative_to(ROOT).as_posix(), "->", dst.relative_to(ROOT).as_posix())


def main() -> None:
    marker = PAGES / "__root_index.php"
    if marker.is_file() and ROOT_INDEX.is_file():
        txt = ROOT_INDEX.read_text(encoding="utf-8", errors="replace")
        if "__root_index.php" in txt and len(txt) < 200:
            print("Already migrated (marker + short root index). Abort.")
            return

    SITE.mkdir(parents=True, exist_ok=True)
    PAGES.mkdir(parents=True, exist_ok=True)

    migrate_root_index()

    for fp in collect_index_like():
        migrate_file(fp)

    migrate_dir_contents(ROOT / "ajax", "ajax")
    migrate_dir_contents(ROOT / "include", "include")
    migrate_dir_contents(ROOT / "tools", "tools")

    print("done")


if __name__ == "__main__":
    main()
