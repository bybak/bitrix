#!/usr/bin/env python3
"""Translate Japanese Yamaha EU/JP assembly titles and part names to English.

Pipeline (run in order; nothing is applied until `apply`):
  1) extract     — unique JP strings + frequency
  2) build-map   — dictionary + token dict + US part-number match
  3) translate   — fill leftovers (argos | mymemory | file)
  4) status      — coverage report
  5) apply       — UPDATE oem_assemblies / oem_parts (use --dry-run first)

Examples:
  ./scripts/yamaha_translate_jp_en.sh extract
  ./scripts/yamaha_translate_jp_en.sh build-map
  ./scripts/yamaha_translate_jp_en.sh translate --backend argos
  ./scripts/yamaha_translate_jp_en.sh status
  ./scripts/yamaha_translate_jp_en.sh apply --dry-run
  ./scripts/yamaha_translate_jp_en.sh apply
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from collections import Counter
from pathlib import Path
from typing import Any, Iterable

try:
    import psycopg
    from psycopg.rows import dict_row
except ImportError as exc:  # pragma: no cover
    raise SystemExit("Need psycopg — run via scripts/yamaha_translate_jp_en.sh") from exc


ROOTS = ("YMH-EU", "YMH-JP")
US_ROOT = "YMH-US"

# Real JP letters only (exclude ・ ー and other kana punctuation that fake-positive English titles)
JP_LETTER_RE = re.compile(r"[\u3041-\u3096\u30A1-\u30FA\uFF66-\uFF9D\u4E00-\u9FFF]")

# Split compound titles while keeping separators
TOKEN_SPLIT_RE = re.compile(r"(\s+|&|,|/|\||・|．|\.|　)+")

DEFAULT_WORK = Path("/app/storage/yamaha-jp-en")
DEFAULT_DICT = Path(__file__).resolve().parent / "yamaha_jp_en_dict.json"


def _dsn() -> str:
    return (
        os.environ.get("OEM_YAMAHA_DATABASE_DSN")
        or "postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog"
    )


def _connect() -> psycopg.Connection:
    return psycopg.connect(_dsn(), row_factory=dict_row)


def has_japanese(text: str | None) -> bool:
    """True if text contains Japanese letters (not just ・/ー punctuation)."""
    return bool(text and JP_LETTER_RE.search(text))


def normalize_jp_key(text: str) -> str:
    """Normalize JP text for dictionary lookup (half-width kana → full-width, etc.)."""
    if not text:
        return ""
    import unicodedata

    t = unicodedata.normalize("NFKC", text)
    t = t.replace("ｰ", "ー").replace("−", "-").replace("–", "-")
    # ASCII hyphen between katakana → prolonged sound mark
    t = re.sub(r"(?<=[\u30A1-\u30FA])[-－](?=[\u30A1-\u30FA])", "ー", t)
    t = re.sub(r"[ \t]+", " ", t).strip()
    return t


def fix_yamaha_kana(text: str) -> str:
    """Fix common Yamaha halfwidth→fullwidth artifacts (ツ instead of ッ, シヤ instead of シャ)."""
    if not text:
        return ""
    t = text
    # youon digraphs written as two chars
    digraphs = [
        ("シヤ", "シャ"), ("シユ", "シュ"), ("シヨ", "ショ"),
        ("チヤ", "チャ"), ("チユ", "チュ"), ("チヨ", "チョ"),
        ("キヤ", "キャ"), ("キユ", "キュ"), ("キヨ", "キョ"),
        ("ニヤ", "ニャ"), ("ニユ", "ニュ"), ("ニヨ", "ニョ"),
        ("ヒヤ", "ヒャ"), ("ヒユ", "ヒュ"), ("ヒヨ", "ヒョ"),
        ("ミヤ", "ミャ"), ("ミユ", "ミュ"), ("ミヨ", "ミョ"),
        ("リヤ", "リャ"), ("リユ", "リュ"), ("リヨ", "リョ"),
        ("ギヤ", "ギャ"), ("ギユ", "ギュ"), ("ギヨ", "ギョ"),
        ("ジヤ", "ジャ"), ("ジユ", "ジュ"), ("ジヨ", "ジョ"),
        ("ビヤ", "ビャ"), ("ビユ", "ビュ"), ("ビヨ", "ビョ"),
        ("ピヤ", "ピャ"), ("ピユ", "ピュ"), ("ピヨ", "ピョ"),
        ("フヤ", "フャ"), ("フユ", "フュ"), ("フヨ", "フョ"),
        ("フア", "ファ"), ("フイ", "フィ"), ("フエ", "フェ"), ("フオ", "フォ"),
        ("ヴア", "ヴァ"), ("ヴイ", "ヴィ"), ("ヴエ", "ヴェ"), ("ヴオ", "ヴォ"),
        ("テい", "ティ"), ("デイ", "ディ"), ("テウ", "トゥ"), ("デウ", "ドゥ"),
    ]
    for a, b in digraphs:
        t = t.replace(a, b)
    # small tsu often stored as full ツ before a consonant row
    t = re.sub(
        r"ツ([\u30AB-\u30F3])",  # カ…ン
        r"ッ\1",
        t,
    )
    return t


def cleanup_jp_title(text: str) -> str:
    """Strip junk prefixes and normalize for a second-pass translation."""
    t = normalize_jp_key(text)
    t = fix_yamaha_kana(t)
    # leading junk: * , . ． … spaces middle-dots
    t = re.sub(r"^[\s\*\,\.．…・]+", "", t)
    t = re.sub(r"^(?:\.\s*)+", "", t)
    t = t.replace("・", " ").replace("、", ", ").replace("，", ", ")
    t = re.sub(r"\s+", " ", t).strip(" ,.-")
    return t


def _greedy_segment(text: str, dictionary: dict[str, str]) -> str | None:
    """Greedy longest-match over JP letters; keep digits/latin as-is."""
    keys = sorted(dictionary.keys(), key=len, reverse=True)
    i = 0
    parts: list[str] = []
    n = len(text)
    while i < n:
        ch = text[i]
        if not JP_LETTER_RE.match(ch):
            j = i + 1
            while j < n and not JP_LETTER_RE.match(text[j]):
                j += 1
            piece = text[i:j].strip()
            if piece:
                parts.append(piece)
            i = j
            continue
        matched = False
        for k in keys:
            if text.startswith(k, i):
                parts.append(dictionary[k])
                i += len(k)
                matched = True
                break
        if not matched:
            return None
    if not parts:
        return None
    # Join: if previous ends with alnum and next starts with alnum, use space/comma logic
    out: list[str] = []
    for p in parts:
        p = p.strip()
        if not p:
            continue
        if not out:
            out.append(p)
            continue
        if p.startswith(",") or out[-1].endswith(","):
            out.append(p if p.startswith(",") else ", " + p)
        else:
            out.append(" " + p)
    return "".join(out).replace("  ", " ").strip(" ,")


def greedy_dict_translate(text: str, dictionary: dict[str, str]) -> str | None:
    """Longest-match dictionary segmentation for concatenated katakana compounds."""
    key = cleanup_jp_title(text)
    if not key:
        return None
    for candidate in (key, normalize_jp_key(key), fix_yamaha_kana(normalize_jp_key(key))):
        if candidate in dictionary:
            return dictionary[candidate]
        hit = _dict_translate(candidate, dictionary)
        if hit:
            return hit
        hit = _token_translate(candidate, dictionary)
        if hit and not has_japanese(hit):
            return hit
    return _greedy_segment(key, dictionary)


def load_dict(path: Path) -> dict[str, str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    out: dict[str, str] = {}
    for k, v in data.items():
        nk = normalize_jp_key(k)
        if nk and v:
            out[nk] = str(v).strip()
    return out


def work_paths(work_dir: Path) -> dict[str, Path]:
    work_dir.mkdir(parents=True, exist_ok=True)
    return {
        "assemblies": work_dir / "unique_assemblies.jsonl",
        "parts": work_dir / "unique_parts.jsonl",
        "map": work_dir / "translation_map.json",
        "untranslated": work_dir / "untranslated.jsonl",
        "apply_report": work_dir / "apply_report.json",
    }


def write_jsonl(path: Path, rows: Iterable[dict[str, Any]]) -> int:
    n = 0
    with path.open("w", encoding="utf-8") as fh:
        for row in rows:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")
            n += 1
    return n


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    rows: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if line:
                rows.append(json.loads(line))
    return rows


def load_map(path: Path) -> dict[str, dict[str, str]]:
    if not path.is_file():
        return {}
    raw = json.loads(path.read_text(encoding="utf-8"))
    # { jp: {en, source} }
    return raw if isinstance(raw, dict) else {}


def save_map(path: Path, mapping: dict[str, dict[str, str]]) -> None:
    path.write_text(json.dumps(mapping, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def cmd_extract(work_dir: Path) -> None:
    paths = work_paths(work_dir)
    with _connect() as conn, conn.cursor() as cur:
        cur.execute(
            """
            SELECT title AS text, COUNT(*)::bigint AS freq
            FROM oem_assemblies
            WHERE root_arib = ANY(%s)
              AND title IS NOT NULL AND title <> ''
            GROUP BY title
            ORDER BY freq DESC, title
            """,
            (list(ROOTS),),
        )
        asm_rows = [
            {"text": r["text"], "freq": int(r["freq"]), "kind": "assembly"}
            for r in cur.fetchall()
            if has_japanese(r["text"])
        ]
        cur.execute(
            """
            SELECT name AS text, COUNT(*)::bigint AS freq
            FROM oem_parts
            WHERE root_arib = ANY(%s)
              AND name IS NOT NULL AND name <> ''
            GROUP BY name
            ORDER BY freq DESC, name
            """,
            (list(ROOTS),),
        )
        part_rows = [
            {"text": r["text"], "freq": int(r["freq"]), "kind": "part"}
            for r in cur.fetchall()
            if has_japanese(r["text"])
        ]
    n1 = write_jsonl(paths["assemblies"], asm_rows)
    n2 = write_jsonl(paths["parts"], part_rows)
    print(f"extract OK assemblies_unique_jp={n1} parts_unique_jp={n2}", flush=True)
    print(f"  → {paths['assemblies']}", flush=True)
    print(f"  → {paths['parts']}", flush=True)


def _dict_translate(text: str, dictionary: dict[str, str]) -> str | None:
    key = normalize_jp_key(text)
    if key in dictionary:
        return dictionary[key]
    # Try without trailing spaces / numbered suffixes kept
    return None


def _token_translate(text: str, dictionary: dict[str, str]) -> str | None:
    """Translate by replacing known JP tokens; keep digits/latin as-is."""
    key = normalize_jp_key(text)
    if not key:
        return None
    # If whole string known
    hit = _dict_translate(key, dictionary)
    if hit:
        return hit

    # Tokenize on separators, translate JP chunks
    parts = TOKEN_SPLIT_RE.split(key)
    if len(parts) <= 1:
        # Try longest-prefix greedy replace for concatenated katakana? skip for now
        return None

    out: list[str] = []
    translated_any = False
    unresolved_jp = False
    for part in parts:
        if not part:
            continue
        if TOKEN_SPLIT_RE.fullmatch(part):
            # normalize separators to English style
            sep = part
            if "・" in sep or "．" in sep:
                out.append(" ")
            elif "&" in sep:
                out.append(" & ")
            elif "," in sep:
                out.append(", ")
            elif "/" in sep:
                out.append("/")
            else:
                out.append(" ")
            continue
        if not has_japanese(part):
            out.append(part)
            continue
        eng = dictionary.get(normalize_jp_key(part))
        if eng:
            out.append(eng)
            translated_any = True
        else:
            unresolved_jp = True
            out.append(part)
    if translated_any and not unresolved_jp:
        return re.sub(r"\s+", " ", "".join(out)).strip(" ,")
    return None


def cmd_build_map(work_dir: Path, dict_path: Path) -> None:
    paths = work_paths(work_dir)
    dictionary = load_dict(dict_path)
    mapping = load_map(paths["map"])

    asm_rows = read_jsonl(paths["assemblies"])
    part_rows = read_jsonl(paths["parts"])
    if not asm_rows and not part_rows:
        raise SystemExit("No extract files — run `extract` first")

    stats = Counter()

    def put(jp: str, en: str, source: str) -> None:
        if not jp or not en or not has_japanese(jp):
            return
        # Do not overwrite a better source with a weaker one
        rank = {"us": 3, "dict": 2, "token": 1, "argos": 1, "mymemory": 1, "file": 1, "manual": 4}
        prev = mapping.get(jp)
        if prev and rank.get(prev.get("source", ""), 0) > rank.get(source, 0):
            return
        if prev and prev.get("en") == en and prev.get("source") == source:
            return
        mapping[jp] = {"en": en, "source": source}
        stats[source] += 1

    # 1) Exact + token dictionary for assemblies and parts
    for row in asm_rows + part_rows:
        text = row["text"]
        en = _dict_translate(text, dictionary)
        if en:
            put(text, en, "dict")
            continue
        en = _token_translate(text, dictionary)
        if en:
            put(text, en, "token")

    # 2) US part-number match (most common English name among matching US parts)
    with _connect() as conn, conn.cursor() as cur:
        cur.execute(
            """
            SELECT
              jp.name AS jp_name,
              MODE() WITHIN GROUP (ORDER BY us.name) AS us_name,
              COUNT(*)::bigint AS n
            FROM oem_parts jp
            JOIN oem_parts us
              ON us.root_arib = %s
             AND us.normalized_part_number = jp.normalized_part_number
             AND us.name IS NOT NULL AND us.name <> ''
             AND us.name !~ '[ァ-ン一-龯ひ-ん]'
            WHERE jp.root_arib = ANY(%s)
              AND jp.name IS NOT NULL AND jp.name <> ''
            GROUP BY jp.name
            """,
            (US_ROOT, list(ROOTS)),
        )
        for row in cur.fetchall():
            jp_name = row["jp_name"]
            us_name = row["us_name"]
            if has_japanese(jp_name) and us_name and not has_japanese(us_name):
                put(jp_name, us_name, "us")

    save_map(paths["map"], mapping)

    # Untranslated unique strings
    all_texts = {r["text"] for r in asm_rows + part_rows}
    missing = sorted(t for t in all_texts if t not in mapping)
    write_jsonl(
        paths["untranslated"],
        [{"text": t, "norm": normalize_jp_key(t)} for t in missing],
    )
    covered = len(all_texts) - len(missing)
    print(
        f"build-map OK mapped={len(mapping)} covered_unique={covered}/{len(all_texts)} "
        f"missing={len(missing)} sources={dict(stats)}",
        flush=True,
    )
    print(f"  → {paths['map']}", flush=True)
    print(f"  → {paths['untranslated']}", flush=True)


def _log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def _download_with_retries(url: str, dest: Path, *, retries: int = 5) -> Path:
    """Download large file with retries (Argos CDN often drops mid-transfer)."""
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".partial")
    last_err: BaseException | None = None
    for attempt in range(1, retries + 1):
        try:
            _log(f"download attempt {attempt}/{retries}: {url}")
            _log(f"  → {tmp}")
            # Resume if partial exists
            headers: dict[str, str] = {}
            mode = "wb"
            existing = tmp.stat().st_size if tmp.is_file() else 0
            if existing > 0:
                headers["Range"] = f"bytes={existing}-"
                mode = "ab"
                _log(f"  resuming from byte {existing}")
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=120) as resp, tmp.open(mode) as out:
                total = resp.headers.get("Content-Length")
                # For resumed downloads Content-Length is remaining bytes
                remaining = int(total) if total and total.isdigit() else None
                read = 0
                last_report = time.monotonic()
                while True:
                    chunk = resp.read(1024 * 256)
                    if not chunk:
                        break
                    out.write(chunk)
                    read += len(chunk)
                    now = time.monotonic()
                    if now - last_report >= 2.0:
                        if remaining:
                            done = existing + read
                            full = existing + remaining if headers.get("Range") else remaining
                            # If Range was accepted, full approx existing+remaining
                            if headers.get("Range") and resp.status == 206:
                                full = existing + remaining
                            elif not headers.get("Range"):
                                full = remaining
                            else:
                                full = max(existing + read, existing + remaining)
                            pct = 100.0 * done / full if full else 0
                            _log(f"  downloaded {done/1e6:.1f}/{full/1e6:.1f} MB ({pct:.1f}%)")
                        else:
                            _log(f"  downloaded {(existing+read)/1e6:.1f} MB")
                        last_report = now
            tmp.replace(dest)
            size = dest.stat().st_size
            _log(f"download OK: {dest} ({size/1e6:.1f} MB)")
            if size < 1_000_000:
                raise RuntimeError(f"downloaded file too small ({size} bytes) — likely incomplete")
            return dest
        except Exception as exc:
            last_err = exc
            _log(f"download FAIL attempt {attempt}: {exc}")
            time.sleep(min(30, 2 ** attempt))
    assert last_err is not None
    raise RuntimeError(f"failed to download after {retries} attempts: {last_err}") from last_err


def _install_argos_ja_en(*, model_path: Path | None = None, work_dir: Path | None = None) -> tuple[Any, Any]:
    import argostranslate.package
    import argostranslate.translate

    installed = argostranslate.translate.get_installed_languages()
    _log(
        "argos: installed languages: "
        + (", ".join(f"{l.code}:{l.name}" for l in installed) or "(none)")
    )
    from_lang = next((l for l in installed if l.code == "ja"), None)
    to_lang = next((l for l in installed if l.code == "en"), None)
    if from_lang and to_lang:
        _log("argos: ja→en pack already installed")
        return from_lang, to_lang

    package_file: Path | None = None
    if model_path and model_path.is_file():
        package_file = model_path
        _log(f"argos: using local model file {package_file}")
    else:
        # Prefer stable direct CDN URL (more reliable than AvailablePackage.download()).
        default_url = "https://data.argosopentech.com/argospm/v1/translate-ja_en-1_1.argosmodel"
        cache_dir = (work_dir or Path("/tmp")) / "argos-models"
        package_file = cache_dir / "translate-ja_en-1_1.argosmodel"
        if package_file.is_file() and package_file.stat().st_size > 1_000_000:
            _log(f"argos: reusing cached model {package_file}")
        else:
            _log("argos: ja→en pack NOT installed — downloading with retries…")
            try:
                argostranslate.package.update_package_index()
                available = argostranslate.package.get_available_packages()
                pkg = next((p for p in available if p.from_code == "ja" and p.to_code == "en"), None)
                url = default_url
                if pkg is not None:
                    links = getattr(pkg, "links", None) or getattr(pkg, "link", None)
                    if isinstance(links, list) and links:
                        url = links[0]
                    elif isinstance(links, str) and links:
                        url = links
                    _log(f"argos: package from index: {pkg}")
                _download_with_retries(url, package_file, retries=6)
            except Exception as exc:
                _log(f"argos: download via script failed: {exc}")
                raise SystemExit(
                    "Не удалось скачать модель Argos (~112MB).\n"
                    "Скачай вручную на хосте:\n"
                    "  mkdir -p storage/yamaha-jp-en/argos-models\n"
                    "  curl -L --retry 10 --retry-all-errors -C - \\\n"
                    "    -o storage/yamaha-jp-en/argos-models/translate-ja_en-1_1.argosmodel \\\n"
                    "    https://data.argosopentech.com/argospm/v1/translate-ja_en-1_1.argosmodel\n"
                    "Потом:\n"
                    "  ./scripts/yamaha_translate_jp_en.sh translate --backend argos \\\n"
                    "    --argos-model /app/storage/yamaha-jp-en/argos-models/translate-ja_en-1_1.argosmodel\n"
                ) from exc

    _log(f"argos: installing language pack from {package_file}…")
    t3 = time.monotonic()
    argostranslate.package.install_from_path(package_file)
    _log(f"argos: install OK in {time.monotonic() - t3:.1f}s")
    installed = argostranslate.translate.get_installed_languages()
    from_lang = next(l for l in installed if l.code == "ja")
    to_lang = next(l for l in installed if l.code == "en")
    return from_lang, to_lang


def _translate_argos(
    texts: list[str],
    *,
    model_path: Path | None = None,
    work_dir: Path | None = None,
) -> dict[str, str]:
    _log(f"argos: preparing translator for {len(texts)} strings")
    _log("argos: importing argostranslate (first import can take a while)…")
    t0 = time.monotonic()
    try:
        import argostranslate.translate
    except ImportError as exc:
        raise SystemExit("argos backend needs: pip install argostranslate") from exc
    _log(f"argos: import OK in {time.monotonic() - t0:.1f}s")

    from_lang, to_lang = _install_argos_ja_en(model_path=model_path, work_dir=work_dir)

    _log(f"argos: building translator {from_lang.code}→{to_lang.code}…")
    translator = from_lang.get_translation(to_lang)
    _log("argos: translator ready — starting batch translation")

    out: dict[str, str] = {}
    skipped_jp = 0
    failed = 0
    start = time.monotonic()
    report_every = 25 if len(texts) < 500 else 50
    for i, text in enumerate(texts, 1):
        try:
            en = translator.translate(text).strip()
        except Exception as exc:
            failed += 1
            _log(f"argos FAIL [{i}/{len(texts)}] {text!r}: {exc}")
            continue
        if en and not has_japanese(en):
            out[text] = en
        else:
            skipped_jp += 1
            if skipped_jp <= 5:
                _log(f"argos SKIP (still JP or empty) [{i}] {text!r} → {en!r}")
        if i % report_every == 0 or i == len(texts):
            elapsed = time.monotonic() - start
            rate = i / elapsed if elapsed > 0 else 0
            eta = (len(texts) - i) / rate if rate > 0 else 0
            _log(
                f"argos progress {i}/{len(texts)} "
                f"({100.0 * i / len(texts):.1f}%) "
                f"ok={len(out)} skip={skipped_jp} fail={failed} "
                f"rate={rate:.1f}/s eta={eta/60:.1f}min"
            )
    _log(
        f"argos done in {(time.monotonic() - start)/60:.1f}min: "
        f"ok={len(out)} skip={skipped_jp} fail={failed}"
    )
    return out


def _translate_mymemory(texts: list[str], *, sleep_s: float = 0.35) -> dict[str, str]:
    out: dict[str, str] = {}
    _log(f"mymemory: translating {len(texts)} strings (slow API, ~{len(texts)*sleep_s/60:.0f} min)…")
    start = time.monotonic()
    for i, text in enumerate(texts, 1):
        q = urllib.parse.urlencode({"q": text, "langpair": "ja|en"})
        url = f"https://api.mymemory.translated.net/get?{q}"
        try:
            with urllib.request.urlopen(url, timeout=30) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
            en = ((payload.get("responseData") or {}).get("translatedText") or "").strip()
            # MyMemory sometimes echoes the source or returns quota messages
            if en and not has_japanese(en) and "MYMEMORY WARNING" not in en.upper():
                out[text] = en
            elif en:
                _log(f"mymemory SKIP [{i}] {text!r} → {en!r}")
        except Exception as exc:
            _log(f"mymemory FAIL [{i}/{len(texts)}] {text!r}: {exc}")
        if i % 25 == 0 or i == len(texts):
            elapsed = time.monotonic() - start
            rate = i / elapsed if elapsed > 0 else 0
            eta = (len(texts) - i) / rate if rate > 0 else 0
            _log(
                f"mymemory progress {i}/{len(texts)} "
                f"ok={len(out)} rate={rate:.2f}/s eta={eta/60:.1f}min"
            )
        time.sleep(sleep_s)
    return out


def _translate_from_file(path: Path) -> dict[str, str]:
    """JSON object {jp: en} or JSONL {text, en}."""
    raw = path.read_text(encoding="utf-8").strip()
    out: dict[str, str] = {}
    if raw.startswith("{"):
        data = json.loads(raw)
        for k, v in data.items():
            if k and v and not has_japanese(str(v)):
                out[str(k)] = str(v).strip()
        return out
    for line in raw.splitlines():
        row = json.loads(line)
        jp = row.get("text") or row.get("jp")
        en = row.get("en")
        if jp and en and not has_japanese(str(en)):
            out[str(jp)] = str(en).strip()
    return out


def cmd_translate(
    work_dir: Path,
    backend: str,
    file_path: Path | None,
    *,
    argos_model: Path | None = None,
) -> None:
    paths = work_paths(work_dir)
    _log(f"translate: work_dir={work_dir}")
    _log(f"translate: loading map {paths['map']}")
    mapping = load_map(paths["map"])
    _log(f"translate: map size={len(mapping)}")
    _log(f"translate: loading untranslated {paths['untranslated']}")
    missing_rows = read_jsonl(paths["untranslated"])
    missing = [r["text"] for r in missing_rows if r.get("text") and r["text"] not in mapping]
    _log(f"translate: untranslated file rows={len(missing_rows)} still_missing={len(missing)}")
    if not missing:
        _log("translate: nothing to do — all unique strings already mapped (run status)")
        return

    # Show a few samples so user sees what will be translated
    _log("translate: sample missing strings:")
    for sample in missing[:10]:
        _log(f"  • {sample}")
    if len(missing) > 10:
        _log(f"  … and {len(missing) - 10} more")

    _log(f"translate: backend={backend}")
    if backend == "argos":
        filled = _translate_argos(missing, model_path=argos_model, work_dir=work_dir)
        source = "argos"
    elif backend == "mymemory":
        filled = _translate_mymemory(missing)
        source = "mymemory"
    elif backend == "file":
        if not file_path or not file_path.is_file():
            raise SystemExit("--file required for backend=file")
        _log(f"translate: reading file {file_path}")
        filled = _translate_from_file(file_path)
        source = "file"
    else:
        raise SystemExit(f"unknown backend {backend}")

    _log(f"translate: merging {len(filled)} new translations into map…")
    n = 0
    for jp, en in filled.items():
        if jp in missing and en:
            mapping[jp] = {"en": en, "source": source}
            n += 1
    save_map(paths["map"], mapping)

    still = [t for t in missing if t not in mapping]
    write_jsonl(paths["untranslated"], [{"text": t, "norm": normalize_jp_key(t)} for t in still])
    _log(f"translate DONE added={n} still_missing={len(still)} map_size={len(mapping)}")
    _log(f"  → {paths['map']}")
    _log(f"  → {paths['untranslated']}")
    if still:
        _log("translate: remaining samples:")
        for sample in still[:10]:
            _log(f"  • {sample}")
        _log("re-run translate or extend scripts/yamaha_jp_en_dict.json for leftovers")


def cmd_retry_leftovers(
    work_dir: Path,
    dict_path: Path,
    *,
    backend: str = "argos",
    argos_model: Path | None = None,
) -> None:
    """Second pass for Argos skips: cleanup junk/halfwidth, dict, then re-translate cleaned text."""
    paths = work_paths(work_dir)
    dictionary = load_dict(dict_path)
    mapping = load_map(paths["map"])
    missing_rows = read_jsonl(paths["untranslated"])
    missing = [r["text"] for r in missing_rows if r.get("text") and r["text"] not in mapping]
    _log(f"retry-leftovers: starting with {len(missing)} missing")
    if not missing:
        _log("retry-leftovers: nothing to do")
        return

    stats = Counter()
    need_mt: dict[str, list[str]] = {}  # cleaned -> [originals]

    for original in missing:
        cleaned = cleanup_jp_title(original)
        if not cleaned:
            stats["empty_after_cleanup"] += 1
            continue

        # Already English after cleanup (e.g. "AIR SHROUD・FAN" → "AIR SHROUD FAN")
        if not has_japanese(cleaned):
            mapping[original] = {"en": cleaned, "source": "cleanup"}
            stats["cleanup_latin"] += 1
            continue

        # Reuse existing map entry for cleaned form
        if cleaned in mapping and mapping[cleaned].get("en") and not has_japanese(mapping[cleaned]["en"]):
            mapping[original] = {"en": mapping[cleaned]["en"], "source": "cleanup+map"}
            stats["cleanup_map"] += 1
            continue

        en = greedy_dict_translate(cleaned, dictionary)
        if en and not has_japanese(en):
            mapping[original] = {"en": en, "source": "cleanup+greedy"}
            mapping.setdefault(cleaned, {"en": en, "source": "cleanup+greedy"})
            stats["cleanup_greedy"] += 1
            continue

        need_mt.setdefault(cleaned, []).append(original)
        stats["need_mt"] += 1

    _log(f"retry-leftovers: after cleanup/dict → {dict(stats)}")
    _log(f"retry-leftovers: unique cleaned strings for MT={len(need_mt)}")

    if need_mt:
        texts = sorted(need_mt.keys())
        _log("retry-leftovers: sample cleaned strings for MT:")
        for sample in texts[:10]:
            _log(f"  • {sample}")
        if backend == "argos":
            filled = _translate_argos(texts, model_path=argos_model, work_dir=work_dir)
        elif backend == "mymemory":
            filled = _translate_mymemory(texts)
        else:
            raise SystemExit(f"retry-leftovers backend must be argos|mymemory, got {backend}")

        mt_ok = 0
        mt_skip_greedy = 0
        for cleaned, originals in need_mt.items():
            en = filled.get(cleaned)
            if (not en or has_japanese(en)):
                # Argos skipped — last chance greedy dict on cleaned
                en2 = greedy_dict_translate(cleaned, dictionary)
                if en2 and not has_japanese(en2):
                    en = en2
                    src = "cleanup+greedy-after-mt"
                    mt_skip_greedy += 1
                else:
                    continue
            else:
                src = f"cleanup+{backend}"
            mapping[cleaned] = {"en": en, "source": src}
            for original in originals:
                mapping[original] = {"en": en, "source": src}
                mt_ok += 1
        stats["mt_ok"] = mt_ok
        stats["mt_skip_greedy"] = mt_skip_greedy
        _log(f"retry-leftovers: MT/greedy mapped originals={mt_ok} (greedy-after-skip={mt_skip_greedy})")

    save_map(paths["map"], mapping)
    still = [t for t in missing if t not in mapping or has_japanese(mapping[t].get("en", ""))]
    # Drop entries that somehow still have JP as "en"
    still = [t for t in missing if t not in mapping]
    write_jsonl(paths["untranslated"], [{"text": t, "norm": normalize_jp_key(t)} for t in still])
    _log(f"retry-leftovers DONE still_missing={len(still)} map_size={len(mapping)} stats={dict(stats)}")
    if still:
        _log("retry-leftovers: remaining samples (likely garbage OCR):")
        for sample in still[:15]:
            _log(f"  • {sample}")


def cmd_status(work_dir: Path) -> None:
    paths = work_paths(work_dir)
    asm = read_jsonl(paths["assemblies"])
    parts = read_jsonl(paths["parts"])
    mapping = load_map(paths["map"])
    missing = read_jsonl(paths["untranslated"])
    by_source = Counter(v.get("source", "?") for v in mapping.values())
    asm_cov = sum(1 for r in asm if r["text"] in mapping)
    part_cov = sum(1 for r in parts if r["text"] in mapping)
    print(
        json.dumps(
            {
                "assemblies_unique_jp": len(asm),
                "assemblies_mapped": asm_cov,
                "parts_unique_jp": len(parts),
                "parts_mapped": part_cov,
                "map_size": len(mapping),
                "untranslated": len(missing),
                "by_source": dict(by_source),
            },
            ensure_ascii=False,
            indent=2,
        ),
        flush=True,
    )


def cmd_apply(work_dir: Path, *, dry_run: bool, batch_size: int = 10_000) -> None:
    """Apply JP→EN map to DB.

    Strategy: load map → materialize matching row ids → UPDATE by id in chunks.
    Batches by map-keys were wrong (rows=0 + 65 seq-scans). ~700k assemblies /
    ~400k parts need id-based progress.
    """
    paths = work_paths(work_dir)
    mapping = load_map(paths["map"])
    if not mapping:
        raise SystemExit("Empty translation map — run build-map / translate first")

    pairs = [(jp, meta["en"]) for jp, meta in mapping.items() if meta.get("en") and has_japanese(jp)]
    # Prefer original extract keys (skip cleaned duplicates that are not in DB)
    extract_keys = {r["text"] for r in read_jsonl(paths["assemblies"])} | {
        r["text"] for r in read_jsonl(paths["parts"])
    }
    if extract_keys:
        pairs = [(jp, en) for jp, en in pairs if jp in extract_keys]
    n_pairs = len(pairs)
    load_batch = 2_000
    t0 = time.time()
    _log(f"apply start pairs={n_pairs} dry_run={dry_run} update_batch={batch_size}")

    report: dict[str, Any] = {
        "assemblies_updated": 0,
        "parts_updated": 0,
        "dry_run": dry_run,
        "pairs": n_pairs,
    }

    def _row_progress(stage: str, done: int, total: int, stage_t0: float) -> None:
        pct = 100.0 * done / total if total else 100.0
        elapsed = max(time.time() - stage_t0, 0.001)
        rate = done / elapsed
        eta = (total - done) / rate if rate > 0 and done < total else 0.0
        _log(
            f"apply {stage} {done}/{total} ({pct:.1f}%) "
            f"rate={rate:.0f} rows/s eta={eta / 60:.1f}min"
        )

    def _flush_updates(
        cur: Any,
        conn: Any,
        *,
        todo_table: str,
        target_table: str,
        target_col: str,
        stage: str,
    ) -> int:
        cur.execute(f"SELECT COUNT(*) AS c FROM {todo_table}")
        total = int(cur.fetchone()["c"])
        _log(f"apply {stage}: {total} rows to update (batch={batch_size})")
        if total == 0:
            return 0
        updated = 0
        stage_t0 = time.time()
        while True:
            cur.execute(
                f"""
                WITH chunk AS (
                  DELETE FROM {todo_table}
                  WHERE ctid IN (SELECT ctid FROM {todo_table} LIMIT %s)
                  RETURNING id, en
                )
                UPDATE {target_table} t
                SET {target_col} = chunk.en, updated_at = now()
                FROM chunk
                WHERE t.id = chunk.id
                """,
                (batch_size,),
            )
            n = int(cur.rowcount)
            conn.commit()
            if n <= 0:
                break
            updated += n
            if updated == n or updated % (batch_size * 2) == 0 or updated >= total:
                _row_progress(stage, min(updated, total), total, stage_t0)
        _log(f"apply {stage}: done rows={updated} in {time.time() - stage_t0:.1f}s")
        return updated

    with _connect() as conn, conn.cursor() as cur:
        cur.execute(
            """
            CREATE TEMP TABLE jp_en_map (
              jp text PRIMARY KEY,
              en text NOT NULL
            ) ON COMMIT PRESERVE ROWS
            """
        )
        for i in range(0, n_pairs, load_batch):
            chunk = pairs[i : i + load_batch]
            cur.executemany(
                "INSERT INTO jp_en_map(jp, en) VALUES (%s, %s) "
                "ON CONFLICT (jp) DO UPDATE SET en = EXCLUDED.en",
                chunk,
            )
            done = min(i + load_batch, n_pairs)
            if done == n_pairs or done % 10_000 == 0 or i == 0:
                _log(f"apply load-map {done}/{n_pairs}")
        conn.commit()
        _log(f"apply map loaded in {time.time() - t0:.1f}s")

        roots = list(ROOTS)

        _log("apply building assemblies todo (join title↔map)…")
        t_join = time.time()
        cur.execute(
            """
            CREATE TEMP TABLE asm_todo ON COMMIT PRESERVE ROWS AS
            SELECT a.id, m.en
            FROM oem_assemblies a
            JOIN jp_en_map m ON m.jp = a.title
            WHERE a.root_arib = ANY(%s)
            """,
            (roots,),
        )
        conn.commit()
        cur.execute("SELECT COUNT(*) AS c FROM asm_todo")
        asm_todo_n = int(cur.fetchone()["c"])
        _log(f"apply assemblies todo={asm_todo_n} in {time.time() - t_join:.1f}s")

        _log("apply building parts todo (join name↔map)…")
        t_join = time.time()
        cur.execute(
            """
            CREATE TEMP TABLE part_todo ON COMMIT PRESERVE ROWS AS
            SELECT p.id, m.en
            FROM oem_parts p
            JOIN jp_en_map m ON m.jp = p.name
            WHERE p.root_arib = ANY(%s)
            """,
            (roots,),
        )
        conn.commit()
        cur.execute("SELECT COUNT(*) AS c FROM part_todo")
        part_todo_n = int(cur.fetchone()["c"])
        _log(f"apply parts todo={part_todo_n} in {time.time() - t_join:.1f}s")

        if dry_run:
            report["assemblies_would_update"] = asm_todo_n
            report["parts_would_update"] = part_todo_n
            _log("apply dry-run: no UPDATE (rollback)")
            conn.rollback()
        else:
            report["assemblies_updated"] = _flush_updates(
                cur,
                conn,
                todo_table="asm_todo",
                target_table="oem_assemblies",
                target_col="title",
                stage="assemblies",
            )
            report["parts_updated"] = _flush_updates(
                cur,
                conn,
                todo_table="part_todo",
                target_table="oem_parts",
                target_col="name",
                stage="parts",
            )

        cur.execute("DROP TABLE IF EXISTS asm_todo")
        cur.execute("DROP TABLE IF EXISTS part_todo")
        cur.execute("DROP TABLE IF EXISTS jp_en_map")
        conn.commit()

    report["elapsed_sec"] = round(time.time() - t0, 1)
    paths["apply_report"].write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    _log(f"apply done in {report['elapsed_sec']}s")
    print(json.dumps(report, ensure_ascii=False, indent=2), flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Yamaha JP→EN translation pipeline")
    parser.add_argument(
        "command",
        choices=["extract", "build-map", "translate", "retry-leftovers", "status", "apply"],
    )
    parser.add_argument("--work-dir", default=str(DEFAULT_WORK))
    parser.add_argument("--dict", default=str(DEFAULT_DICT), dest="dict_path")
    parser.add_argument(
        "--backend",
        choices=["argos", "mymemory", "file"],
        default="argos",
        help="translate backend (default: argos)",
    )
    parser.add_argument("--file", type=str, default=None, help="JSON/JSONL translations for backend=file")
    parser.add_argument(
        "--argos-model",
        type=str,
        default=None,
        help="Path to pre-downloaded translate-ja_en-*.argosmodel",
    )
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    work_dir = Path(args.work_dir)
    dict_path = Path(args.dict_path)

    if args.command == "extract":
        cmd_extract(work_dir)
    elif args.command == "build-map":
        cmd_build_map(work_dir, dict_path)
    elif args.command == "translate":
        cmd_translate(
            work_dir,
            args.backend,
            Path(args.file) if args.file else None,
            argos_model=Path(args.argos_model) if args.argos_model else None,
        )
    elif args.command == "retry-leftovers":
        cmd_retry_leftovers(
            work_dir,
            dict_path,
            backend=args.backend,
            argos_model=Path(args.argos_model) if args.argos_model else None,
        )
    elif args.command == "status":
        cmd_status(work_dir)
    elif args.command == "apply":
        cmd_apply(work_dir, dry_run=args.dry_run)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
