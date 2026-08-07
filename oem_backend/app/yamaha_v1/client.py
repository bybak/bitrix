from __future__ import annotations

import json
import time
import urllib.error
import urllib.request
from typing import Any

from app.config import get_settings

from .constants import API_BASE, DEFAULT_LANG_ID


class YamahaApiError(RuntimeError):
    def __init__(self, endpoint: str, status: int, body: str) -> None:
        super().__init__(f"Yamaha API {endpoint} failed: HTTP {status}")
        self.endpoint = endpoint
        self.status = status
        self.body = body


def make_client() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener()


def post_json(
    endpoint: str,
    payload: dict[str, Any],
    *,
    timeout: float | None = None,
    retries: int = 2,
) -> dict[str, Any]:
    url = f"{API_BASE}/{endpoint.strip('/')}/"
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json, text/plain, */*",
        "User-Agent": "oem-yamaha-v1/1.0",
    }
    timeout = timeout or get_settings().http_timeout
    last_error: Exception | None = None

    for attempt in range(retries + 1):
        req = urllib.request.Request(url, data=body, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8", errors="replace")
                if not raw.strip().startswith("{"):
                    raise YamahaApiError(endpoint, resp.status, raw[:400])
                return json.loads(raw)
        except urllib.error.HTTPError as exc:
            err_body = exc.read().decode("utf-8", errors="replace")
            last_error = YamahaApiError(endpoint, exc.code, err_body[:400])
            if exc.code >= 500 and attempt < retries:
                time.sleep(0.5 * (attempt + 1))
                continue
            raise last_error from exc
        except (TimeoutError, urllib.error.URLError) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(0.5 * (attempt + 1))
                continue
            raise RuntimeError(f"Yamaha API {endpoint} network error: {exc}") from exc

    raise RuntimeError(f"Yamaha API {endpoint} failed: {last_error}")


def fetch_bytes(url: str, *, timeout: float | None = None, retries: int = 4) -> bytes:
    if url.startswith("//"):
        url = f"https:{url}"
    timeout = timeout or get_settings().http_timeout
    headers = {"User-Agent": "oem-yamaha-v1/1.0"}
    last_error: Exception | None = None

    for attempt in range(retries + 1):
        req = urllib.request.Request(url, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return resp.read()
        except urllib.error.HTTPError as exc:
            last_error = exc
            if exc.code in {429, 500, 502, 503, 504} and attempt < retries:
                time.sleep(min(8.0, 0.75 * (2**attempt)))
                continue
            raise
        except (TimeoutError, urllib.error.URLError, OSError) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(8.0, 0.75 * (2**attempt)))
                continue
            raise RuntimeError(f"Yamaha asset download failed: {url}: {exc}") from exc

    raise RuntimeError(f"Yamaha asset download failed: {url}: {last_error}")


def product_list(*, base_code: str, lang_id: str = DEFAULT_LANG_ID) -> dict[str, Any]:
    return post_json("product_list", {"baseCode": base_code, "langId": lang_id})


def model_name_list(
    *,
    base_code: str,
    product_id: str,
    displacement_type: str,
    lang_id: str = DEFAULT_LANG_ID,
) -> dict[str, Any]:
    return post_json(
        "model_name_list",
        {
            "productId": product_id,
            "displacementType": displacement_type,
            "baseCode": base_code,
            "langId": lang_id,
        },
    )


def model_year_list(*, payload: dict[str, Any]) -> dict[str, Any]:
    return post_json("model_year_list", payload)


def model_list(*, payload: dict[str, Any]) -> dict[str, Any]:
    return post_json("model_list", payload)


def catalog_index(*, payload: dict[str, Any]) -> dict[str, Any]:
    return post_json("catalog_index", payload)


def catalog_text(*, payload: dict[str, Any]) -> dict[str, Any]:
    return post_json("catalog_text", payload)
