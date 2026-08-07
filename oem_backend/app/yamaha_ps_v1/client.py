from __future__ import annotations

import json
import random
import re
import socket
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import quote, urljoin

import httpx
from bs4 import BeautifulSoup

from app.config import get_settings

from .constants import (
    BRAND_CONFIG,
    GET_ASSEMBLY_URL,
    GET_DETAILS_URL,
    HTTP_USER_AGENT,
    LABEL_TO_BRAND,
    STREAM_ENDPOINT,
)

RETRYABLE_HTTP_ERRORS = (
    httpx.ConnectError,
    httpx.ConnectTimeout,
    httpx.ReadError,
    httpx.ReadTimeout,
    httpx.RemoteProtocolError,
    httpx.PoolTimeout,
    socket.gaierror,
    OSError,
)


def is_retryable_http_error(exc: BaseException) -> bool:
    if isinstance(exc, RETRYABLE_HTTP_ERRORS):
        if isinstance(exc, OSError) and exc.errno not in {-2, -3, -5, 11, 110, 111}:
            return False
        return True
    cause = exc.__cause__
    return isinstance(cause, RETRYABLE_HTTP_ERRORS) and is_retryable_http_error(cause)


@dataclass(frozen=True)
class BrandContext:
    code: str
    partstream_arib: str
    label: str
    appkey: str
    ariv: str
    aril: str


@dataclass
class AriNode:
    title: str
    arib: str
    aria: str | None
    rel: str
    slug: str | None
    depth: int
    path: list[str]
    root_arib: str
    partstream_brand: str


def brand_context(code: str) -> BrandContext:
    key = code.strip().upper()
    cfg = BRAND_CONFIG.get(key)
    if not cfg:
        raise ValueError(f"Unknown PartStream brand {code!r}; expected one of {sorted(BRAND_CONFIG)}")
    return BrandContext(
        code=key,
        partstream_arib=str(cfg["partstream_arib"]),
        label=str(cfg["label"]),
        appkey=str(cfg["appkey"]),
        ariv=str(cfg["ariv"]),
        aril=str(cfg["aril"]),
    )


def brand_from_path(path: list[str] | None) -> BrandContext | None:
    if not path:
        return None
    code = LABEL_TO_BRAND.get(path[0])
    return brand_context(code) if code else None


def clean_text(value: str | None) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def jsonp_payload(text: str) -> dict[str, Any]:
    text = text.strip()
    if text.startswith("/**/"):
        text = text[4:].strip()
    match = re.match(r"^[^(]*\((.*)\)\s*;?\s*$", text, re.S)
    if match:
        text = match.group(1)
    return json.loads(text)


def make_client(*, referer: str | None = None, max_connections: int = 200) -> httpx.Client:
    settings = get_settings()
    headers = {
        "User-Agent": HTTP_USER_AGENT,
        "Accept": "*/*",
        "Accept-Language": "en-US,en;q=0.9",
    }
    if referer:
        headers["Referer"] = referer
    limits = httpx.Limits(
        max_connections=max(20, int(max_connections)),
        max_keepalive_connections=max(20, int(max_connections)),
    )
    return httpx.Client(
        timeout=settings.http_timeout,
        follow_redirects=True,
        headers=headers,
        limits=limits,
    )


def _sleep_polite(*, delay_ms: int = 0, jitter_ms: int = 0) -> None:
    total = max(0, int(delay_ms))
    if jitter_ms > 0:
        total += random.randint(0, int(jitter_ms))
    if total > 0:
        time.sleep(total / 1000.0)


def jsonp_get(
    client: httpx.Client,
    url: str,
    params: dict[str, Any],
    *,
    brand: BrandContext,
    retries: int = 6,
    delay_ms: int = 0,
    jitter_ms: int = 0,
) -> dict[str, Any]:
    payload = {
        **params,
        "arik": brand.appkey,
        "aril": brand.aril,
        "ariv": brand.ariv,
        "cb": "callback",
    }
    for attempt in range(1, retries + 1):
        _sleep_polite(delay_ms=delay_ms if attempt == 1 else 0, jitter_ms=jitter_ms if attempt == 1 else 0)
        try:
            response = client.get(
                url,
                params=payload,
                headers={"Referer": brand.ariv, "Origin": brand.ariv.rsplit("/", 1)[0]},
            )
            response.raise_for_status()
            body = response.text
            if "Incapsula" in body or "_Incapsula_Resource" in body:
                raise httpx.HTTPStatusError(
                    "Incapsula challenge",
                    request=response.request,
                    response=response,
                )
            return jsonp_payload(body)
        except httpx.HTTPStatusError as exc:
            status = exc.response.status_code if exc.response is not None else 0
            if status and status < 500 and "Incapsula" not in str(exc):
                raise
            if attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt) + random.uniform(0, 0.5))
        except Exception as exc:
            if not is_retryable_http_error(exc):
                raise
            if attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt) + random.uniform(0, 0.5))
    raise RuntimeError("unreachable retry state")


def list_children(
    client: httpx.Client,
    brand: BrandContext,
    arib: str,
    aria: str | None = None,
    *,
    delay_ms: int = 0,
    jitter_ms: int = 0,
) -> list[dict[str, Any]]:
    data: dict[str, Any] = {"arib": arib}
    if aria:
        data["aria"] = aria
    payload = jsonp_get(
        client,
        GET_ASSEMBLY_URL,
        data,
        brand=brand,
        delay_ms=delay_ms,
        jitter_ms=jitter_ms,
    )
    return (payload.get("model") or {}).get("json") or []


def fetch_details_html(
    client: httpx.Client,
    brand: BrandContext,
    slug: str,
    *,
    delay_ms: int = 0,
    jitter_ms: int = 0,
) -> str:
    details_slug = slug if slug.endswith("/y") else f"{slug}/y"
    payload = jsonp_get(
        client,
        GET_DETAILS_URL,
        {"ariq": details_slug},
        brand=brand,
        delay_ms=delay_ms,
        jitter_ms=jitter_ms,
    )
    return payload.get("html") or ""


def source_url_for(brand: BrandContext, slug: str | None) -> str:
    return f"{brand.ariv}?aribrand={quote(brand.partstream_arib)}#{slug or ''}"


def is_placeholder_diagram_url(url: str | None) -> bool:
    """PartStream placeholder when a diagram has no real illustration (e.g. NoImage.gif/Max)."""
    if not url:
        return False
    lowered = url.lower()
    return "noimage" in lowered or "/content/images/noimage" in lowered


def extract_diagram_url(html: str) -> str | None:
    soup = BeautifulSoup(html, "lxml")
    image = soup.select_one("#ariparts_image")
    if not image:
        # CDN-hosted diagram sometimes appears as first datamanager img.
        for img in soup.select("img"):
            src = img.get("src") or ""
            if "datamanager.arinet.com" in src or "/image/" in src:
                image_url = src
                break
        else:
            return None
    else:
        image_url = image.get("src")
    if not image_url:
        return None
    image_url = urljoin(STREAM_ENDPOINT + "/", image_url)
    if image_url.startswith("//"):
        image_url = "https:" + image_url
    # Placeholder — keep as-is; do not append /Max (404).
    if is_placeholder_diagram_url(image_url):
        return image_url
    # Legacy PartStream image handler wants /Max; CDN URLs are already full PNG.
    if "partstream.arinet.com" in image_url and not image_url.rstrip("/").endswith("/Max"):
        image_url = image_url.rstrip("/") + "/Max"
    return image_url
