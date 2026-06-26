import json
import re
import time
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from typing import Any
from urllib.parse import quote, urljoin

import httpx
from bs4 import BeautifulSoup

from app.config import get_settings
from app.importers import writer
from app.importers.assets import download_image
from app.importers.progress import ProgressReporter
from app.importers.remotors_catalog import (
    BRAND_NAMES,
    DEFAULT_BRANDS,
    assembly_external_id,
    canonical_assembly_arib,
    canonical_brand_and_type,
    filter_brand_codes,
)


SOURCE_CODE = "remotors_ari"
APP_KEY = "Ja5mWoFztyQhVLuUin3C"
BASE_PAGE_URL = "https://remotors.fi/eng/partfinder"
STREAM_ENDPOINT = "https://partstream.arinet.com"
GET_ASSEMBLY_URL = f"{STREAM_ENDPOINT}/Parts/GetAssembly"
GET_DETAILS_URL = f"{STREAM_ENDPOINT}/Parts/GetDetails"
RETRYABLE_HTTP_ERRORS = (
    httpx.ConnectError,
    httpx.ConnectTimeout,
    httpx.ReadError,
    httpx.ReadTimeout,
    httpx.RemoteProtocolError,
    httpx.PoolTimeout,
)


@dataclass
class AriNode:
    title: str
    arib: str
    aria: str | None
    rel: str
    slug: str | None
    parent_id: int | None
    depth: int
    path: list[str]


def _clean_text(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def _jsonp_payload(text: str) -> dict[str, Any]:
    text = text.strip()
    if text.startswith("/**/"):
        text = text[4:].strip()
    match = re.match(r"^[^(]*\((.*)\)\s*;?\s*$", text, re.S)
    if match:
        text = match.group(1)
    return json.loads(text)


def _client() -> httpx.Client:
    settings = get_settings()
    return httpx.Client(timeout=settings.http_timeout, follow_redirects=True, headers={"User-Agent": "MotorForceOEMBot/0.1"})


def _jsonp_get(client: httpx.Client, url: str, params: dict[str, Any], *, retries: int = 6) -> dict[str, Any]:
    payload = {
        **params,
        "arik": APP_KEY,
        "aril": "en-EU",
        "ariv": BASE_PAGE_URL,
        "cb": "callback",
    }
    for attempt in range(1, retries + 1):
        try:
            response = client.get(url, params=payload)
            response.raise_for_status()
            return _jsonp_payload(response.text)
        except RETRYABLE_HTTP_ERRORS:
            if attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt))
        except httpx.HTTPStatusError as exc:
            if exc.response.status_code < 500 or attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt))
    raise RuntimeError("unreachable retry state")


def _fetch_brand_codes(client: httpx.Client) -> list[str]:
    try:
        response = client.get("https://services.arinet.com/PartStream/", params={"appKey": APP_KEY})
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "lxml")
        codes = [
            option.get("value")
            for option in soup.select("#ari_brands option[value]")
            if option.get("value") and option.get("value") != "0"
        ]
        return codes or DEFAULT_BRANDS
    except Exception:
        return DEFAULT_BRANDS


def _list_children(client: httpx.Client, arib: str, aria: str | None = None) -> list[dict[str, Any]]:
    data: dict[str, Any] = {"arib": arib}
    if aria:
        data["aria"] = aria
    payload = _jsonp_get(client, GET_ASSEMBLY_URL, data)
    return ((payload.get("model") or {}).get("json") or [])


def _parse_year(path: list[str]) -> int | None:
    for item in path:
        if re.fullmatch(r"\d{4}", item):
            return int(item)
    return None


def _parse_model_title(path: list[str]) -> str:
    model_title = path[-2] if len(path) >= 2 else path[-1]
    model_title = re.sub(r"\s*-\s*\d{4}\s*$", "", model_title)
    model_title = re.sub(r"\s+(CHASSIS|ENGINE|US ENGINE)\s*$", "", model_title, flags=re.I)
    return _clean_text(model_title) or "Unknown model"


def _parse_variant_section(path: list[str]) -> str | None:
    model_title = path[-2] if len(path) >= 2 else ""
    if re.search(r"\bENGINE\b", model_title, re.I):
        return "engine"
    if re.search(r"\bCHASSIS\b", model_title, re.I):
        return "chassis"
    return None


def _currency_and_price(text: str) -> tuple[str | None, Decimal | None]:
    text = _clean_text(text)
    currency = "EUR" if "€" in text else None
    number = re.sub(r"[^0-9,.\-]", "", text).replace(",", ".")
    try:
        return currency, Decimal(number) if number else None
    except InvalidOperation:
        return currency, None


def _parse_quantity(row: Any) -> float | None:
    qty_input = row.select_one("input[id^='ariparts_qty']")
    value = qty_input.get("value") if qty_input else None
    try:
        return float(value) if value not in (None, "") else None
    except ValueError:
        return None


def _parse_hotspot_coords(raw: str | None) -> tuple[float, float, float, float]:
    values = [float(part) for part in (raw or "").split(";") if part.strip()]
    if len(values) != 4:
        return 0, 0, 0, 0
    x1, y1, x2, y2 = values
    return x1, y1, max(0, x2 - x1), max(0, y2 - y1)


def _details_slug(slug: str) -> str:
    return slug if slug.endswith("/y") else f"{slug}/y"


def _source_url_for(arib: str, slug: str | None) -> str:
    return f"{BASE_PAGE_URL}?aribrand={quote(arib)}#{slug or ''}"


def _ensure_catalog_context(node: AriNode) -> tuple[int, int, int, int]:
    model_name = _parse_model_title(node.path)
    source_designation = node.path[-2] if len(node.path) >= 2 else model_name
    extra = f"{model_name} {source_designation}"
    brand_name, vehicle_type = canonical_brand_and_type(node.arib, node.path, extra_text=extra)
    year = _parse_year(node.path)

    brand_id = writer.ensure_brand(brand_name)
    writer.ensure_brand_alias(brand_id, SOURCE_CODE, node.arib)
    model_id = writer.ensure_model_family(vehicle_type, brand_id, model_name)
    writer.ensure_model_alias(model_id, SOURCE_CODE, source_designation, reviewed=False)
    variant_id = writer.ensure_variant(
        model_id,
        year_from=year,
        year_to=year,
        market_name=model_name,
        source_designation=source_designation,
        variant_section=_parse_variant_section(node.path),
    )
    if node.parent_id:
        writer.link_source_node(node.parent_id, "vehicle_variant", variant_id)
    return brand_id, model_id, variant_id, year or 0


def _import_assembly_detail(
    client: httpx.Client,
    node: AriNode,
    progress: ProgressReporter,
    download_images: bool,
    force: bool = False,
) -> dict[str, int]:
    if not node.slug:
        return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 0}

    external_id = assembly_external_id(arib=node.arib, aria=node.aria, slug=node.slug, path=node.path)
    if not force:
        status = writer.assembly_import_status(
            SOURCE_CODE,
            external_id,
            arib=node.arib,
            aria=node.aria,
            slug=node.slug,
        )
        if status["is_complete"]:
            brand_id, _model_id, variant_id, _year = _ensure_catalog_context(node)
            assembly_title = node.title
            source_node_id = int(status["source_node_id"] or 0) or None
            existing_assembly_id = int(status["assembly_id"] or 0) or None
            _linked_id, action = writer.ensure_assembly_variant_link(
                variant_id,
                assembly_title,
                source_node_id,
                existing_assembly_id=existing_assembly_id,
            )
            if action == "moved":
                progress.advance(
                    (
                        f"relinked assembly to variant {variant_id} {node.arib} / "
                        f"{' / '.join(node.path)} parts={status['part_count']}"
                    )
                )
                return {"assemblies": 1, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 0}
            progress.advance(
                (
                    f"skip already imported {node.arib} / {' / '.join(node.path)} "
                    f"parts={status['part_count']} hotspots={status['hotspot_count']}"
                )
            )
            return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 1, "errors": 0}

    payload = _jsonp_get(client, GET_DETAILS_URL, {"ariq": _details_slug(node.slug)})
    html = payload.get("html") or ""
    soup = BeautifulSoup(html, "lxml")

    image = soup.select_one("#ariparts_image")
    image_url = image.get("src") if image else None
    if image_url:
        image_url = urljoin(STREAM_ENDPOINT, image_url)
        if not image_url.rstrip("/").endswith("/Max"):
            image_url = image_url.rstrip("/") + "/Max"

    asset: dict[str, Any] = {}

    with writer.batch_conn():
        brand_id, _model_id, variant_id, _year = _ensure_catalog_context(node)
        assembly_title = node.title
        source_url = _source_url_for(node.arib, node.slug)
        assembly_node_id = writer.ensure_source_node(
            source_code=SOURCE_CODE,
            node_type="assembly",
            title=assembly_title,
            external_id=external_id,
            parent_id=node.parent_id,
            source_url=source_url,
            arib=canonical_assembly_arib(node.arib) or node.arib,
            aria=node.aria,
            slug=node.slug,
        )
        assembly_id = writer.ensure_assembly(variant_id, assembly_title, assembly_node_id)
        writer.link_source_node(assembly_node_id, "assembly", assembly_id)

        if image_url and download_images:
            try:
                asset = download_image(
                    image_url, SOURCE_CODE, BRAND_NAMES.get(node.arib, node.arib), assembly_node_id
                )
            except Exception as exc:
                asset = {"download_error": str(exc)}

        diagram_id = writer.ensure_diagram(
            assembly_id,
            source_node_id=assembly_node_id,
            original_url=image_url,
            local_path=asset.get("local_path"),
            source_image_id=(image_url or "").rstrip("/").split("/")[-2] if image_url else None,
            width=asset.get("width"),
            height=asset.get("height"),
            checksum_sha256=asset.get("checksum_sha256"),
        )

        parts_by_ref: dict[str, int] = {}
        parts = 0
        for row in soup.select("tr.ariPartInfo"):
            ref = _clean_text(row.select_one(".ariPLTag").get_text(" ") if row.select_one(".ariPLTag") else "")
            number = _clean_text(row.select_one(".ariPLSku").get_text(" ") if row.select_one(".ariPLSku") else "")
            name = _clean_text(row.select_one(".ariPLDesc").get_text(" ") if row.select_one(".ariPLDesc") else "")
            price_text = _clean_text(row.select_one(".ariPLPrice").get_text(" ") if row.select_one(".ariPLPrice") else "")
            currency, price = _currency_and_price(price_text)
            quantity = _parse_quantity(row)
            if not number:
                continue
            part_id = writer.ensure_part(BRAND_NAMES.get(node.arib, node.arib), number, name, brand_id)
            assembly_part_id = writer.add_assembly_part(
                assembly_id=assembly_id,
                part_id=part_id,
                ref=ref,
                quantity=quantity,
                row_kind="original",
                source_node_id=assembly_node_id,
                source_row_id=f"{node.arib}:{node.aria or node.slug}:ref:{ref}",
                raw_payload={"price_text": price_text, "quantity": quantity, "html": str(row)},
            )
            if price is not None:
                writer.add_source_price_snapshot(
                    source_code=SOURCE_CODE,
                    part_id=part_id,
                    assembly_part_id=assembly_part_id,
                    source_price_id=f"{node.arib}:{node.aria or node.slug}:ref:{ref}:{currency or ''}",
                    price=str(price),
                    currency=currency,
                    min_qty=quantity,
                    raw_payload={"ref": ref, "part_number": number, "price_text": price_text, "quantity": quantity},
                )
            parts_by_ref[ref] = assembly_part_id
            parts += 1

        writer.clear_diagram_hotspots(diagram_id)
        hotspots = 0
        for hotspot in soup.select(".ariHotSpot"):
            ref = hotspot.get("tag")
            raw_coords = hotspot.get("coords")
            x, y, width, height = _parse_hotspot_coords(raw_coords)
            writer.add_hotspot(
                diagram_id=diagram_id,
                assembly_part_id=parts_by_ref.get(ref or ""),
                shape="rect",
                raw_coords=raw_coords,
                x=x,
                y=y,
                width=width,
                height=height,
                ref=ref,
                raw_payload={"attrs": dict(hotspot.attrs)},
            )
            hotspots += 1

    progress.advance(f"assembly {node.arib} / {' / '.join(node.path)} parts={parts} hotspots={hotspots}")
    return {"assemblies": 1, "diagrams": 1, "parts": parts, "hotspots": hotspots, "skipped": 0, "errors": 0}


def import_assembly_image(
    client: httpx.Client,
    node: AriNode,
    progress: ProgressReporter,
    *,
    assembly_id: int | None = None,
    force: bool = False,
) -> dict[str, int]:
    """Download diagram PNG for an assembly that already exists in PostgreSQL."""
    if not node.slug:
        return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 1}

    external_id = assembly_external_id(arib=node.arib, aria=node.aria, slug=node.slug, path=node.path)
    resolved_assembly_id = assembly_id
    source_node_id: int | None = None
    if resolved_assembly_id:
        image_status = writer.assembly_diagram_image_status(resolved_assembly_id)
        source_node_id = image_status.get("source_node_id")
        if not force and image_status.get("has_local_file"):
            progress.advance(
                f"skip image exists {node.arib} / {' / '.join(node.path)} "
                f"path={image_status.get('local_path')}"
            )
            return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 1, "errors": 0}
    else:
        status = writer.assembly_import_status(
            SOURCE_CODE,
            external_id,
            arib=node.arib,
            aria=node.aria,
            slug=node.slug,
        )
        resolved_assembly_id = int(status["assembly_id"] or 0) or None
        if not resolved_assembly_id:
            progress.advance(f"ERROR image assembly not found {node.arib} / {' / '.join(node.path)}")
            return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 1}
        image_status = writer.assembly_diagram_image_status(resolved_assembly_id)
        source_node_id = image_status.get("source_node_id")
        if not force and image_status.get("has_local_file"):
            progress.advance(
                f"skip image exists {node.arib} / {' / '.join(node.path)} "
                f"path={image_status.get('local_path')}"
            )
            return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 1, "errors": 0}

    payload = _jsonp_get(client, GET_DETAILS_URL, {"ariq": _details_slug(node.slug)})
    html = payload.get("html") or ""
    soup = BeautifulSoup(html, "lxml")
    image = soup.select_one("#ariparts_image")
    image_url = image.get("src") if image else None
    if image_url:
        image_url = urljoin(STREAM_ENDPOINT, image_url)
        if not image_url.rstrip("/").endswith("/Max"):
            image_url = image_url.rstrip("/") + "/Max"

    if not image_url:
        progress.advance(f"ERROR image no URL {node.arib} / {' / '.join(node.path)}")
        return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 1}

    if not source_node_id:
        progress.advance(f"ERROR image no source_node {node.arib} / {' / '.join(node.path)}")
        return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 1}

    asset: dict[str, Any] = {}
    try:
        asset = download_image(
            image_url, SOURCE_CODE, BRAND_NAMES.get(node.arib, node.arib), int(source_node_id)
        )
    except Exception as exc:
        progress.advance(f"ERROR image download {node.arib} / {' / '.join(node.path)}: {exc}")
        return {"assemblies": 0, "diagrams": 0, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 1}

    with writer.batch_conn():
        writer.ensure_diagram(
            int(resolved_assembly_id),
            source_node_id=int(source_node_id),
            original_url=image_url,
            local_path=asset.get("local_path"),
            source_image_id=(image_url or "").rstrip("/").split("/")[-2] if image_url else None,
            width=asset.get("width"),
            height=asset.get("height"),
            checksum_sha256=asset.get("checksum_sha256"),
        )

    progress.advance(f"image {node.arib} / {' / '.join(node.path)} -> {asset.get('local_path')}")
    return {"assemblies": 0, "diagrams": 1, "parts": 0, "hotspots": 0, "skipped": 0, "errors": 0}


def _is_year_title(title: str) -> bool:
    return bool(re.fullmatch(r"\d{4}", title))


def _apply_year_filter(children: list[dict[str, Any]], allowed_years: set[str] | None) -> list[dict[str, Any]]:
    """Keep only requested years when this level of the tree exposes year nodes.

    BRP exposes category folders at brand level (Can-Am ATV, …), years deeper.
    KTM/HUM/LNX expose years directly under the brand node.
    """
    if not allowed_years:
        return children
    titles = [_clean_text(child.get("data")) for child in children]
    if not any(_is_year_title(title) for title in titles):
        return children
    return [child for child in children if _clean_text(child.get("data")) in allowed_years]


def _node_type_for(node: AriNode) -> str:
    if node.rel == "assembly":
        return "assembly"
    if _is_year_title(node.title):
        return "year"
    return "model_node"


def _walk_catalog_tree(
    client: httpx.Client,
    queue: list[AriNode],
    *,
    progress: ProgressReporter,
    counters: dict[str, int],
    allowed_years: set[str] | None,
    max_models: int | None,
    max_assemblies: int | None,
    download_images: bool,
    force: bool,
) -> None:
    processed_assemblies = 0
    processed_models = 0
    while queue:
        node = queue.pop(0)
        if node.rel == "assembly":
            if max_assemblies is not None and processed_assemblies >= max_assemblies:
                progress.advance(f"assembly limit reached, skipped {node.title}")
                continue
            try:
                detail_counts = _import_assembly_detail(client, node, progress, download_images, force)
                for key, value in detail_counts.items():
                    counters[key] += value
            except Exception as exc:
                counters["errors"] += 1
                progress.advance(f"ERROR skipped assembly {node.arib} / {' / '.join(node.path)}: {exc}")
            processed_assemblies += 1
            continue

        node_type = _node_type_for(node)
        if node_type == "model_node":
            if max_models is not None and processed_models >= max_models:
                progress.advance(f"model limit reached, skipped {' / '.join(node.path)}")
                continue
            processed_models += 1
        source_node_id = writer.ensure_source_node(
            source_code=SOURCE_CODE,
            node_type=node_type,
            title=node.title,
            external_id=f"{node.arib}:{node.aria or node.slug or '/'.join(node.path)}",
            parent_id=node.parent_id,
            source_url=_source_url_for(node.arib, node.slug) if node.slug else None,
            arib=node.arib,
            aria=node.aria,
            slug=node.slug,
        )
        counters["source_nodes"] += 1

        try:
            children = _list_children(client, node.arib, node.aria)
        except Exception as exc:
            counters["errors"] += 1
            progress.advance(f"ERROR skipped node {node.arib} / {' / '.join(node.path)}: {exc}")
            continue
        children = _apply_year_filter(children, allowed_years)
        progress.add_total(len(children))
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
                    parent_id=source_node_id,
                    depth=node.depth + 1,
                    path=[*node.path, title],
                )
            )
        progress.advance(f"node {node.arib} / {' / '.join(node.path)} children={len(children)}")


def crawl(
    *,
    brands: list[str] | None = None,
    years: list[int] | None = None,
    max_models: int | None = None,
    max_assemblies: int | None = None,
    download_images: bool = True,
    force: bool = False,
) -> dict[str, int]:
    progress = ProgressReporter(total=1, label="remotors_full")
    counters = {
        "brands": 0,
        "source_nodes": 0,
        "assemblies": 0,
        "diagrams": 0,
        "parts": 0,
        "hotspots": 0,
        "skipped": 0,
        "errors": 0,
    }

    with _client() as client:
        fetched = brands or _fetch_brand_codes(client)
        brand_codes = filter_brand_codes(fetched, explicit=brands is not None)
        progress.set_stage("brands", len(brand_codes))
        progress.add_total(len(brand_codes))

        queue: list[AriNode] = []
        allowed_years = {str(year) for year in years} if years else None
        for arib in brand_codes:
            brand_name = BRAND_NAMES.get(arib, arib)
            brand_node_id = writer.ensure_source_node(
                source_code=SOURCE_CODE,
                node_type="brand",
                title=brand_name,
                external_id=arib,
                arib=arib,
            )
            counters["brands"] += 1
            counters["source_nodes"] += 1
            children = _apply_year_filter(_list_children(client, arib), allowed_years)
            progress.add_total(len(children))
            for child in children:
                attr = child.get("attr") or {}
                queue.append(
                    AriNode(
                        title=_clean_text(child.get("data")),
                        arib=attr.get("arib") or arib,
                        aria=attr.get("aria"),
                        rel=attr.get("rel") or "",
                        slug=attr.get("slug") or None,
                        parent_id=brand_node_id,
                        depth=1,
                        path=[_clean_text(child.get("data"))],
                    )
                )
            progress.advance(f"brand {arib} discovered children={len(children)}")

        progress.set_stage("catalog tree", max(len(queue), 1))
        _walk_catalog_tree(
            client,
            queue,
            progress=progress,
            counters=counters,
            allowed_years=allowed_years,
            max_models=max_models,
            max_assemblies=max_assemblies,
            download_images=download_images,
            force=force,
        )

    progress.finish("remotors crawl finished")
    return counters


def repair_variants(
    *,
    variant_ids: list[int],
    download_images: bool = True,
    force: bool = False,
    max_assemblies: int | None = None,
) -> dict[str, int]:
    from app.importers.catalog_fix import variant_repair_roots

    progress = ProgressReporter(total=1, label="remotors_variants")
    counters = {
        "variants": 0,
        "source_nodes": 0,
        "assemblies": 0,
        "diagrams": 0,
        "parts": 0,
        "hotspots": 0,
        "skipped": 0,
        "errors": 0,
    }
    roots = variant_repair_roots(variant_ids)
    counters["variants"] = len(roots)
    progress.set_stage("variant repair", max(len(roots), 1))
    progress.add_total(len(roots))

    with _client() as client:
        queue: list[AriNode] = []
        for root in roots:
            queue.append(
                AriNode(
                    title=root["title"],
                    arib=root["arib"],
                    aria=root["aria"],
                    rel="",
                    slug=root.get("slug"),
                    parent_id=root["parent_id"],
                    depth=root["depth"],
                    path=root["path"],
                )
            )
            progress.advance(
                f"variant {root['variant_id']} root {root['arib']} / {' / '.join(root['path'])}"
            )
        _walk_catalog_tree(
            client,
            queue,
            progress=progress,
            counters=counters,
            allowed_years=None,
            max_models=None,
            max_assemblies=max_assemblies,
            download_images=download_images,
            force=force,
        )

    progress.finish("variant repair finished")
    return counters
