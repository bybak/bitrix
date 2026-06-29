from __future__ import annotations

import json
import re
import socket
import time
from dataclasses import dataclass
from typing import Any
from urllib.parse import quote, urljoin

import httpx
from bs4 import BeautifulSoup

from app.config import get_settings

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


def make_client() -> httpx.Client:
    settings = get_settings()
    return httpx.Client(
        timeout=settings.http_timeout,
        follow_redirects=True,
        headers={"User-Agent": "MotorForceOEMBot/0.1"},
    )


def jsonp_get(client: httpx.Client, url: str, params: dict[str, Any], *, retries: int = 6) -> dict[str, Any]:
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
            return jsonp_payload(response.text)
        except httpx.HTTPStatusError as exc:
            if exc.response.status_code < 500 or attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt))
        except Exception as exc:
            if not is_retryable_http_error(exc):
                raise
            if attempt >= retries:
                raise
            time.sleep(min(60, 2**attempt))
    raise RuntimeError("unreachable retry state")


def list_children(client: httpx.Client, arib: str, aria: str | None = None) -> list[dict[str, Any]]:
    data: dict[str, Any] = {"arib": arib}
    if aria:
        data["aria"] = aria
    payload = jsonp_get(client, GET_ASSEMBLY_URL, data)
    return (payload.get("model") or {}).get("json") or []


def fetch_details_html(client: httpx.Client, slug: str) -> str:
    details_slug = slug if slug.endswith("/y") else f"{slug}/y"
    payload = jsonp_get(client, GET_DETAILS_URL, {"ariq": details_slug})
    return payload.get("html") or ""


def source_url_for(root_arib: str, slug: str | None) -> str:
    return f"{BASE_PAGE_URL}?aribrand={quote(root_arib)}#{slug or ''}"


def extract_diagram_url(html: str) -> str | None:
    soup = BeautifulSoup(html, "lxml")
    image = soup.select_one("#ariparts_image")
    if not image:
        return None
    image_url = image.get("src")
    if not image_url:
        return None
    image_url = urljoin(STREAM_ENDPOINT, image_url)
    if not image_url.rstrip("/").endswith("/Max"):
        image_url = image_url.rstrip("/") + "/Max"
    return image_url
