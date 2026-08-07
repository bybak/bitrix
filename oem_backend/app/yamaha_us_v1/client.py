from __future__ import annotations

import base64
import json
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

import httpx

from app.config import get_settings

from .constants import API_BASE, AUTH_PATH, HTTP_USER_AGENT, SITE_BASE, SNAPSHOT_API_RETRIES


class YamahaUsApiError(RuntimeError):
    def __init__(self, path: str, status: int, body: str) -> None:
        super().__init__(f"Yamaha US API {path} failed: HTTP {status}")
        self.path = path
        self.status = status
        self.body = body


_thread_local = threading.local()


def _browser_api_headers(*, include_content_type: bool = False) -> dict[str, str]:
    headers = {
        "User-Agent": HTTP_USER_AGENT,
        "Accept": "application/json",
        "Origin": SITE_BASE,
        "Referer": f"{SITE_BASE}/parts/",
    }
    if include_content_type:
        headers["Content-Type"] = "application/json"
    return headers


def _api_client(*, timeout: float | None = None) -> httpx.Client:
    timeout = timeout if timeout is not None else get_settings().http_timeout
    key = f"api:{timeout}"
    clients: dict[str, httpx.Client] = getattr(_thread_local, "clients", {})
    client = clients.get(key)
    if client is None or client.is_closed:
        client = httpx.Client(
            base_url=API_BASE,
            timeout=httpx.Timeout(timeout, connect=min(timeout, 10.0)),
            headers=_browser_api_headers(),
            limits=httpx.Limits(max_connections=256, max_keepalive_connections=128),
        )
        clients[key] = client
        _thread_local.clients = clients
    return client


def _parse_auth_payload(resp: httpx.Response) -> dict[str, Any]:
    if resp.status_code >= 400:
        raise RuntimeError(
            f"Yamaha US auth HTTP {resp.status_code}: {resp.text[:400]}"
        )
    payload = resp.json()
    token = payload.get("data", {}).get("token") or {}
    access = token.get("access")
    if not access:
        raise RuntimeError(f"Yamaha US auth missing access token: {payload}")
    return payload


class _TokenManager:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._access: str | None = None
        self._refresh: str | None = None
        self._access_expires_at = 0.0

    def _auth_post(self) -> dict[str, Any]:
        client = _api_client()
        resp = client.post(
            AUTH_PATH,
            json={},
            headers=_browser_api_headers(include_content_type=True),
        )
        payload = _parse_auth_payload(resp)
        token = payload.get("data", {}).get("token") or {}
        access = token.get("access")
        refresh = token.get("refresh")
        self._access = str(access)
        self._refresh = str(refresh) if refresh else None
        self._access_expires_at = time.monotonic() + 2.5 * 3600
        return payload

    def _auth_refresh(self) -> dict[str, Any]:
        if not self._refresh:
            return self._auth_post()
        client = _api_client()
        resp = client.patch(
            AUTH_PATH,
            json={"refresh": self._refresh},
            headers=_browser_api_headers(include_content_type=True),
        )
        if resp.status_code >= 400:
            return self._auth_post()
        payload = resp.json()
        access = payload.get("data", {}).get("access")
        if not access:
            return self._auth_post()
        self._access = str(access)
        self._access_expires_at = time.monotonic() + 2.5 * 3600
        return payload

    def get_access_token(self, *, force: bool = False) -> str:
        with self._lock:
            if (
                not force
                and self._access
                and time.monotonic() < self._access_expires_at
            ):
                return self._access
            if self._refresh and not force:
                try:
                    self._auth_refresh()
                    if self._access:
                        return self._access
                except Exception:
                    pass
            self._auth_post()
            assert self._access
            return self._access


_token_manager = _TokenManager()

_diagram_api_semaphore: threading.Semaphore | None = None
_diagram_api_semaphore_size = 0


def configure_diagram_api_concurrency(limit: int | None = None) -> int:
    """Limit simultaneous diagram API requests (per process)."""
    global _diagram_api_semaphore, _diagram_api_semaphore_size
    size = max(1, int(limit if limit is not None else get_settings().yamaha_diagram_api_concurrency))
    if _diagram_api_semaphore is None or _diagram_api_semaphore_size != size:
        _diagram_api_semaphore = threading.Semaphore(size)
        _diagram_api_semaphore_size = size
    return size


def _diagram_api_slot() -> threading.Semaphore:
    if _diagram_api_semaphore is None:
        configure_diagram_api_concurrency()
    assert _diagram_api_semaphore is not None
    return _diagram_api_semaphore


def get_access_token(*, force: bool = False) -> str:
    return _token_manager.get_access_token(force=force)


def get_json(
    path: str,
    *,
    params: dict[str, str] | None = None,
    retries: int = 3,
    timeout: float | None = None,
) -> dict[str, Any]:
    if not path.startswith("/"):
        path = f"/{path}"
    req_timeout = timeout if timeout is not None else get_settings().http_timeout
    last_error: Exception | None = None
    client = _api_client(timeout=req_timeout)

    for attempt in range(retries + 1):
        token = get_access_token(force=attempt > 0 and last_error is not None)
        try:
            resp = client.get(
                path,
                params=params,
                headers={"Authorization": f"Bearer {token}"},
            )
            raw = resp.text
            if not raw.strip():
                raise YamahaUsApiError(path, resp.status_code or 502, "empty response body")
            try:
                payload = json.loads(raw)
            except json.JSONDecodeError as exc:
                # Imperva/ALB sometimes returns blank/HTML under load — retry like a soft 502.
                raise YamahaUsApiError(
                    path,
                    resp.status_code or 502,
                    f"non-json body ({exc}): {raw[:200]!r}",
                ) from exc
            status = (payload.get("meta") or {}).get("statusCode") or resp.status_code
            if int(status) >= 400:
                raise YamahaUsApiError(path, int(status), raw[:400])
            return payload
        except YamahaUsApiError as exc:
            last_error = exc
            if exc.status in {401, 403} and attempt < retries:
                get_access_token(force=True)
                time.sleep(0.3 * (attempt + 1))
                continue
            # empty/non-json often comes as 200 with bad body — treat as retryable.
            retryable_soft = "empty response body" in str(exc) or "non-json body" in str(exc)
            if (exc.status >= 500 or retryable_soft) and attempt < retries:
                time.sleep(min(2.0**attempt, 16.0))
                continue
            raise
        except httpx.HTTPStatusError as exc:
            err_body = exc.response.text[:400]
            last_error = YamahaUsApiError(path, exc.response.status_code, err_body)
            if exc.response.status_code in {401, 403} and attempt < retries:
                get_access_token(force=True)
                time.sleep(0.3 * (attempt + 1))
                continue
            if exc.response.status_code >= 500 and attempt < retries:
                time.sleep(0.5 * (attempt + 1))
                continue
            raise last_error from exc
        except (httpx.TimeoutException, httpx.NetworkError, httpx.TransportError, OSError) as exc:
            last_error = exc
            if attempt < retries:
                time.sleep(min(2.0**attempt, 16.0))
                continue
            raise RuntimeError(f"Yamaha US API network error {path}: {exc}") from exc

    raise RuntimeError(f"Yamaha US API failed {path}: {last_error}")


def browse_years(*, product_slug: str, retries: int = SNAPSHOT_API_RETRIES) -> list[dict[str, Any]]:
    return list(get_json(f"/v1.0.0/parts/browse/{product_slug}/years", retries=retries).get("data") or [])


def browse_categories(*, top_id: str, retries: int = SNAPSHOT_API_RETRIES) -> list[dict[str, Any]]:
    return list(get_json(f"/v1.0.0/parts/browse/categories/{top_id}", retries=retries).get("data") or [])


def browse_models(
    *,
    product_slug: str,
    top_id: str,
    category_id: str | None = None,
    retries: int = SNAPSHOT_API_RETRIES,
) -> list[dict[str, Any]]:
    if category_id:
        path = f"/v1.0.0/parts/browse/{top_id}/{category_id}/models"
        params = {"rawModel": "true"} if product_slug == "outboard" else None
    elif product_slug == "outboard":
        path = f"/v1.0.0/parts/browse/outboard/{top_id}/models"
        params = {"rawModel": "true"}
    else:
        path = f"/v1.0.0/parts/browse/{product_slug}/{top_id}/models"
        params = None
    return list(get_json(path, params=params, retries=retries).get("data") or [])


def browse_model_detail(
    *,
    product_slug: str,
    top_id: str,
    model_id: str,
    retries: int = SNAPSHOT_API_RETRIES,
) -> dict[str, Any]:
    if product_slug == "outboard":
        path = f"/v1.0.0/parts/browse/outboard/{top_id}/{model_id}"
    else:
        path = f"/v1.0.0/parts/browse/{product_slug}/{top_id}/{model_id}"
    return dict(get_json(path, retries=retries).get("data") or {})


def browse_diagrams(
    *,
    product_slug: str,
    top_id: str,
    model_id: str,
    retries: int = SNAPSHOT_API_RETRIES,
) -> list[dict[str, Any]]:
    if product_slug == "outboard":
        path = f"/v1.0.0/parts/browse/outboard/{top_id}/{model_id}/diagrams"
    else:
        path = f"/v1.0.0/parts/browse/{product_slug}/{top_id}/{model_id}/diagrams"
    return list(get_json(path, retries=retries).get("data") or [])


def fetch_diagram(*, model_id: str, image_id: str | int) -> dict[str, Any]:
    """Single attempt, short timeout — fail fast; reset errors and re-run crawl later."""
    with _diagram_api_slot():
        return dict(
            get_json(
                f"/v1.0.0/parts/diagram/{model_id}/{image_id}",
                retries=0,
                timeout=get_settings().yamaha_diagram_timeout,
            ).get("data")
            or {}
        )


def _decode_data_url_image(data_url: str, *, label: str) -> bytes:
    if not data_url.startswith("data:image"):
        raise RuntimeError(f"Unexpected image payload for {label}")
    _header, encoded = data_url.split(",", 1)
    return base64.b64decode(encoded)


_SITE_USER_AGENT = HTTP_USER_AGENT
_MIN_FULL_DIAGRAM_BYTES = 4096
_BUILD_ID_RE = re.compile(r'"buildId":"([^"]+)"')


class _NextBuildIdCache:
    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._build_id: str | None = None

    def get(self, *, force_refresh: bool = False) -> str:
        with self._lock:
            if not force_refresh and self._build_id:
                return self._build_id
            self._build_id = _discover_next_build_id()
            return self._build_id

    def invalidate(self) -> None:
        with self._lock:
            self._build_id = None


_next_build_id_cache = _NextBuildIdCache()


def _site_request_headers(*, accept: str, referer: str | None = None) -> dict[str, str]:
    headers = {
        "User-Agent": _SITE_USER_AGENT,
        "Accept": accept,
        "Accept-Language": "en-US,en;q=0.9",
        "Accept-Encoding": "identity",
        "Connection": "keep-alive",
    }
    if referer:
        headers["Referer"] = referer
        headers["Sec-Fetch-Dest"] = "empty" if "json" in accept else "document"
        headers["Sec-Fetch-Mode"] = "cors" if "json" in accept else "navigate"
        headers["Sec-Fetch-Site"] = "same-origin"
    return headers


def _fetch_site_bytes(*, url: str, accept: str, referer: str | None = None) -> bytes:
    timeout = get_settings().http_timeout
    req = urllib.request.Request(
        url,
        headers=_site_request_headers(accept=accept, referer=referer),
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def _discover_next_build_id() -> str:
    html = _fetch_site_bytes(
        url=f"{SITE_BASE}/parts/",
        accept="text/html,application/xhtml+xml",
    ).decode("utf-8", errors="replace")
    match = _BUILD_ID_RE.search(html)
    if not match:
        raise RuntimeError("Yamaha US Next.js buildId missing on /parts/")
    return match.group(1)


def _next_data_diagram_url(*, build_id: str, model_id: str, image_id: str | int) -> str:
    params = urllib.parse.urlencode([("path", model_id), ("path", str(image_id))])
    return (
        f"{SITE_BASE}/_next/data/{build_id}/parts/diagram/{model_id}/{image_id}.json?{params}"
    )


def _decode_full_diagram_image(
    page_props: dict[str, Any],
    *,
    model_id: str,
    image_id: str | int,
) -> bytes:
    data_url = str(page_props.get("image") or "")
    image_bytes = _decode_data_url_image(data_url, label=f"{model_id}/{image_id}")
    if len(image_bytes) < _MIN_FULL_DIAGRAM_BYTES:
        raise RuntimeError(
            f"diagram image too small for {model_id}/{image_id}: {len(image_bytes)} bytes"
        )
    return image_bytes


def _fetch_full_diagram_image_via_next_data(
    *,
    model_id: str,
    image_id: str | int,
    build_id: str,
) -> bytes:
    url = _next_data_diagram_url(build_id=build_id, model_id=model_id, image_id=image_id)
    page_url = f"{SITE_BASE}/parts/diagram/{model_id}/{image_id}"
    req = urllib.request.Request(
        url,
        headers=_site_request_headers(
            accept="application/json",
            referer=page_url,
        )
        | {"x-nextjs-data": "1"},
        method="GET",
    )
    timeout = get_settings().http_timeout
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            payload = json.loads(resp.read().decode("utf-8", errors="replace"))
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            _next_build_id_cache.invalidate()
        raise
    page_props = payload.get("pageProps") or {}
    return _decode_full_diagram_image(page_props, model_id=model_id, image_id=image_id)


def _fetch_full_diagram_image_via_html(*, model_id: str, image_id: str | int) -> bytes:
    page_url = f"{SITE_BASE}/parts/diagram/{model_id}/{image_id}"
    html = _fetch_site_bytes(
        url=page_url,
        accept="text/html,application/xhtml+xml",
        referer=f"{SITE_BASE}/parts/",
    ).decode("utf-8", errors="replace")
    match = re.search(
        r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>',
        html,
        flags=re.DOTALL,
    )
    if not match:
        raise RuntimeError(f"__NEXT_DATA__ missing on {page_url}")
    payload = json.loads(match.group(1))
    page_props = (payload.get("props") or {}).get("pageProps") or {}
    return _decode_full_diagram_image(page_props, model_id=model_id, image_id=image_id)


def fetch_image_png(*, image_id: str | int, retries: int = 4) -> bytes:
    payload = get_json(f"/v1.0.0/parts/image/{image_id}", retries=retries)
    data_url = str((payload.get("data") or {}).get("image") or "")
    return _decode_data_url_image(data_url, label=str(image_id))


def fetch_image_png_full(*, model_id: str, image_id: str | int, retries: int = 3) -> bytes:
    """Full-size diagram PNG via Next.js data JSON (~300 KB); HTML SSR is fallback."""
    last_error: Exception | None = None

    for attempt in range(retries + 1):
        try:
            build_id = _next_build_id_cache.get(force_refresh=attempt > 0)
            return _fetch_full_diagram_image_via_next_data(
                model_id=model_id,
                image_id=image_id,
                build_id=build_id,
            )
        except (TimeoutError, urllib.error.URLError, OSError, urllib.error.HTTPError) as exc:
            last_error = exc
        except (json.JSONDecodeError, RuntimeError, ValueError) as exc:
            last_error = exc

        if attempt < retries:
            time.sleep(0.5 * (attempt + 1))
            continue

    try:
        return _fetch_full_diagram_image_via_html(model_id=model_id, image_id=image_id)
    except Exception as exc:
        last_error = exc

    raise RuntimeError(
        f"Yamaha US full diagram image unavailable {model_id}/{image_id}: {last_error}"
    )
