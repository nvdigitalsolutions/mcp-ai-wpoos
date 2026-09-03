"""NV oOS Fleet — Hermes dashboard backend plugin (Phase 0-3).

Mounted by the Hermes web dashboard at ``/api/plugins/nv-oos-fleet/`` (see
``manifest.json`` → ``api``). Persists a site registry to ``sites.yaml`` next
to this file (0600 where supported) and proxies monitoring/control traffic to
each site's NV oOS REST + MCP JSON-RPC surface.

Surface map (WP side — no PHP changes required):
- Public REST: /mcp-ai/v1/health, /status, /cost/*, /paper-store/*,
  /orchestration/runs, /cron-status, /security/posture, ai-dir/v1/peers.
- MCP JSON-RPC 2.0: POST /mcp-ai/v1/mcp (tools/list, tools/call for
  get_system_logs_validated, list_cron_jobs, delete_cron_job,
  get_site_summary, get_update_status, ...).

Security contract:
- Tokens live only in ``sites.yaml``; no endpoint ever returns them.
- ``token_hint`` redacts everything but the credential prefix/suffix.
- https-only URLs unless the site entry opts into ``allow_insecure``.
- Every write path is gated on the site entry's ``write: true`` flag AND the
  site-side operator allowlist (defense in depth).
- Upstream errors never include request headers or tokens.

Compatibility: Python 3.11+ (Hermes runtime), FastAPI + pydantic v1 or v2,
httpx (0.28+ — ``verify`` is client-level only), PyYAML.
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, AsyncIterator, Dict, List, Optional, Tuple

import httpx
import yaml
from fastapi import APIRouter, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

log = logging.getLogger("nv-oos-fleet")

router = APIRouter()

REGISTRY_FILE = Path(__file__).resolve().parent / "sites.yaml"
HERMES_HOME = Path(os.environ.get("HERMES_HOME", str(Path.home() / ".hermes")))

FLEET_CACHE_TTL = 30.0  # seconds
CONNECT_TIMEOUT = 5.0
READ_TIMEOUT = 10.0
STREAM_TIMEOUT = 25.0  # no per-chunk deadline; bounded by connect + heartbeats

HEALTH_PATH = "/wp-json/mcp-ai/v1/assistants"

# Upstream path-segment patterns (mirror the WP route regexes) — values that
# will be interpolated into URLs must match these or be rejected with 422.
_SITE_ID_RE = re.compile(r"^[a-z0-9-]{1,64}$")
_COLLECTION_RE = re.compile(r"^[a-zA-Z0-9_-]{1,64}$")
_RECORD_ID_RE = re.compile(r"^[a-zA-Z0-9_-]{1,128}$")
_JOB_ID_RE = re.compile(r"^[a-zA-Z0-9_.:-]{1,128}$")
_TOOL_RE = re.compile(r"^[a-zA-Z0-9_.:-]{1,128}$")

LABEL_MAX = 100
NOTES_MAX = 500
QUERY_MAX = 200
REASON_MAX = 500

# Per-endpoint cache TTLs (seconds).
TTL = {
    "fleet": 30.0,
    "overview": 60.0,
    "logs": 15.0,
    "jobs": 5.0,
    "analytics": 300.0,
    "posture": 60.0,
    "tools": 120.0,
    "paper": 30.0,
    "workflows": 30.0,
    "mesh": 60.0,
    "costs": 300.0,
}

# ---------------------------------------------------------------------------
# Registry persistence
# ---------------------------------------------------------------------------

_registry_lock = asyncio.Lock()
_registry: Dict[str, Dict[str, Any]] = {}
_registry_loaded = False

_cache: Dict[str, Tuple[float, Any]] = {}
# Pooled clients keyed by TLS-verification mode (httpx 0.28+ accepts `verify`
# at client level only, so per-request overrides are not portable).
_clients: Dict[bool, httpx.AsyncClient] = {}
_stream_clients: Dict[bool, httpx.AsyncClient] = {}


def _load_registry() -> None:
    """Load sites.yaml once; survive a corrupt file by starting empty."""
    global _registry_loaded
    if _registry_loaded:
        return
    if REGISTRY_FILE.exists():
        try:
            data = yaml.safe_load(REGISTRY_FILE.read_text(encoding="utf-8")) or {}
            for site in data.get("sites", []):
                site_id = site.get("id")
                if site_id and site.get("url"):
                    _registry[str(site_id)] = site
        except Exception as exc:  # noqa: BLE001 - never crash the dashboard on bad registry
            log.error("nv-oos-fleet: could not read %s: %s", REGISTRY_FILE, exc)
    _registry_loaded = True


def _save_registry() -> None:
    """Atomically persist the registry; best-effort 0600 perms."""
    payload = {
        "version": 1,
        "sites": sorted(
            _registry.values(), key=lambda s: str(s.get("label", "")).lower()
        ),
    }
    tmp = REGISTRY_FILE.with_suffix(".yaml.tmp")
    tmp.write_text(yaml.safe_dump(payload, sort_keys=False), encoding="utf-8")
    os.replace(tmp, REGISTRY_FILE)
    try:
        os.chmod(REGISTRY_FILE, 0o600)
    except OSError:
        pass  # Windows / filesystems without POSIX permissions.


def _invalidate_cache() -> None:
    _cache.clear()


def _cache_get(key: str) -> Optional[Any]:
    item = _cache.get(key)
    if item is None:
        return None
    expires, value = item
    if time.monotonic() < expires:
        return value
    _cache.pop(key, None)
    return None


def _cache_set(key: str, value: Any, ttl: float = FLEET_CACHE_TTL) -> None:
    _cache[key] = (time.monotonic() + ttl, value)


# ---------------------------------------------------------------------------
# Models (plain fields only — pydantic v1/v2 compatible)
# ---------------------------------------------------------------------------


class SiteIn(BaseModel):
    label: str
    url: str
    token: str
    write: bool = False
    allow_insecure: bool = False
    notes: str = ""


class SitePatch(BaseModel):
    label: Optional[str] = None
    url: Optional[str] = None
    token: Optional[str] = None
    write: Optional[bool] = None
    allow_insecure: Optional[bool] = None
    notes: Optional[str] = None


class JobAction(BaseModel):
    job_id: str


class ToolCall(BaseModel):
    site: str
    tool: str
    arguments: Dict[str, Any] = {}


class PaperRecordIn(BaseModel):
    site: str
    collection: str
    records: Optional[List[Dict[str, Any]]] = None  # batch import
    record: Optional[Dict[str, Any]] = None  # single create


class PaperRecordDelete(BaseModel):
    site: str
    collection: str
    record_id: str


class MeshAction(BaseModel):
    site: str
    peer_id: int


class MeshReport(MeshAction):
    reason: str = ""


def _body_dict(model: BaseModel) -> Dict[str, Any]:
    """Return only explicitly-set fields, pydantic v1/v2 compatible."""
    if hasattr(model, "model_dump"):
        return model.model_dump(exclude_unset=True)
    return model.dict(exclude_unset=True)  # type: ignore[attr-defined]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _check(pattern: "re.Pattern", value: str, label: str) -> str:
    """Reject values that would be interpolated into an upstream URL path."""
    if not value or not isinstance(value, str) or not pattern.fullmatch(value):
        raise HTTPException(status_code=422, detail=f"invalid {label}")
    return value


def _cap(value: str, limit: int, label: str) -> str:
    value = value.strip()
    if len(value) > limit:
        raise HTTPException(status_code=422, detail=f"{label} exceeds {limit} characters")
    return value


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _validate_url(raw: str, allow_insecure: bool) -> str:
    url = raw.strip().rstrip("/")
    if not url:
        raise HTTPException(status_code=422, detail="url is required")
    if not url.startswith("https://"):
        if not (allow_insecure and url.startswith("http://")):
            raise HTTPException(
                status_code=422,
                detail="url must use https:// (set allow_insecure to permit http/self-signed)",
            )
    return url


def _redact(token: str) -> str:
    token = token or ""
    if len(token) <= 16:
        return "********"
    return f"{token[:4]}{'*' * 8}{token[-8:]}"


def _public(site: Dict[str, Any]) -> Dict[str, Any]:
    """Registry entry minus the secret."""
    return {
        "id": site["id"],
        "label": site.get("label", ""),
        "url": site["url"],
        "write": bool(site.get("write", False)),
        "allow_insecure": bool(site.get("allow_insecure", False)),
        "notes": site.get("notes", ""),
        "token_hint": _redact(str(site.get("token", ""))),
        "created_at": site.get("created_at"),
        "updated_at": site.get("updated_at"),
    }


def _unwrap(raw: Any) -> Any:
    """Unwrap the canonical tool envelope ({success, data}) to its data."""
    if isinstance(raw, dict) and "success" in raw and "data" in raw:
        return raw["data"]
    return raw


def _as_list(raw: Any) -> List[Any]:
    """Coerce common upstream list shapes to a plain list."""
    if isinstance(raw, list):
        return raw
    if isinstance(raw, dict):
        for key in (
            "jobs",
            "items",
            "data",
            "results",
            "tools",
            "collections",
            "records",
            "peers",
            "runs",
        ):
            if isinstance(raw.get(key), list):
                return raw[key]
    return []


def _auth_headers(site: Dict[str, Any]) -> Dict[str, str]:
    return {"Authorization": f"Bearer {site['token']}"}


class UpstreamError(Exception):
    """Raised when a proxied call fails; never carries tokens."""

    def __init__(self, status: int, detail: str):
        self.status = status
        self.detail = detail
        super().__init__(detail)


def _get_client(verify: bool = True) -> httpx.AsyncClient:
    if _clients.get(verify) is None or _clients[verify].is_closed:
        _clients[verify] = httpx.AsyncClient(
            timeout=httpx.Timeout(CONNECT_TIMEOUT, read=READ_TIMEOUT),
            follow_redirects=True,
            verify=verify,
        )
    return _clients[verify]


def _get_stream_client(verify: bool = True) -> httpx.AsyncClient:
    if _stream_clients.get(verify) is None or _stream_clients[verify].is_closed:
        _stream_clients[verify] = httpx.AsyncClient(
            timeout=httpx.Timeout(CONNECT_TIMEOUT, read=STREAM_TIMEOUT),
            follow_redirects=True,
            verify=verify,
        )
    return _stream_clients[verify]


async def _rest_get(
    site: Dict[str, Any], path: str, params: Optional[Dict[str, Any]] = None
) -> Any:
    """GET /wp-json/{path} with the site token; raise UpstreamError on failure."""
    url = f"{site['url']}/wp-json/{path}"
    try:
        client = _get_client(verify=not bool(site.get("allow_insecure", False)))
        resp = await client.get(url, headers=_auth_headers(site), params=params)
    except httpx.HTTPError as exc:
        raise UpstreamError(502, exc.__class__.__name__) from exc
    if resp.status_code >= 400:
        detail = f"HTTP {resp.status_code}"
        try:
            body = resp.json()
            if isinstance(body, dict):
                detail = str(body.get("message") or detail)
        except ValueError:
            pass
        raise UpstreamError(resp.status_code, detail)
    try:
        return resp.json()
    except ValueError as exc:
        raise UpstreamError(502, "invalid JSON from upstream") from exc


async def _rest_send(
    site: Dict[str, Any],
    method: str,
    path: str,
    json_body: Optional[Any] = None,
    params: Optional[Dict[str, Any]] = None,
) -> Any:
    """State-changing REST call with the site token."""
    url = f"{site['url']}/wp-json/{path}"
    try:
        client = _get_client(verify=not bool(site.get("allow_insecure", False)))
        resp = await client.request(
            method, url, headers=_auth_headers(site), json=json_body, params=params
        )
    except httpx.HTTPError as exc:
        raise UpstreamError(502, exc.__class__.__name__) from exc
    if resp.status_code >= 400:
        detail = f"HTTP {resp.status_code}"
        try:
            body = resp.json()
            if isinstance(body, dict):
                detail = str(body.get("message") or detail)
        except ValueError:
            pass
        raise UpstreamError(resp.status_code, detail)
    try:
        return resp.json()
    except ValueError:
        return {"ok": True}


async def _rpc(
    site: Dict[str, Any], method: str, params: Optional[Dict[str, Any]] = None
) -> Any:
    """JSON-RPC 2.0 call to /wp-json/mcp-ai/v1/mcp.

    The WP endpoint answers protocol errors with HTTP 200 + error envelope
    (see .context/rest-api.md), so both shapes are classified here.
    """
    payload: Dict[str, Any] = {"jsonrpc": "2.0", "id": 1, "method": method}
    if params is not None:
        payload["params"] = params
    url = f"{site['url']}/wp-json/mcp-ai/v1/mcp"
    headers = {**_auth_headers(site), "Content-Type": "application/json"}
    try:
        client = _get_client(verify=not bool(site.get("allow_insecure", False)))
        resp = await client.post(url, headers=headers, json=payload)
    except httpx.HTTPError as exc:
        raise UpstreamError(502, exc.__class__.__name__) from exc
    if resp.status_code != 200:
        raise UpstreamError(resp.status_code, f"HTTP {resp.status_code}")
    try:
        body = resp.json()
    except ValueError as exc:
        raise UpstreamError(502, "invalid JSON from upstream") from exc
    if isinstance(body, dict) and body.get("error"):
        err = body["error"]
        message = err.get("message", "rpc error") if isinstance(err, dict) else str(err)
        raise UpstreamError(502, f"rpc: {message}")
    return body.get("result") if isinstance(body, dict) else body


def _require_site(site_id: str) -> Dict[str, Any]:
    _load_registry()
    site = _registry.get(site_id)
    if site is None:
        raise HTTPException(status_code=404, detail="site not found")
    return site


def _require_write(site: Dict[str, Any]) -> None:
    if not bool(site.get("write", False)):
        raise HTTPException(
            status_code=403,
            detail="write operations are disabled for this site (set write: true on the site entry)",
        )


async def _check_site(site: Dict[str, Any]) -> Dict[str, Any]:
    """GET the site's assistant index with the operator token."""
    result: Dict[str, Any] = {"id": site["id"], "label": site.get("label", "")}
    started = time.monotonic()
    try:
        client = _get_client(verify=not bool(site.get("allow_insecure", False)))
        resp = await client.get(
            f"{site['url']}{HEALTH_PATH}", headers=_auth_headers(site)
        )
        latency_ms = int((time.monotonic() - started) * 1000)
        if resp.status_code == 200:
            count = 0
            try:
                payload = resp.json()
            except ValueError:
                payload = None
            if isinstance(payload, list):
                count = len(payload)
            elif isinstance(payload, dict):
                items = payload.get("assistants") or payload.get("data") or []
                count = len(items) if isinstance(items, list) else 0
            return {
                **result,
                "ok": True,
                "status_code": 200,
                "latency_ms": latency_ms,
                "assistants": count,
                "error": None,
            }
        return {
            **result,
            "ok": False,
            "status_code": resp.status_code,
            "latency_ms": latency_ms,
            "assistants": 0,
            "error": f"HTTP {resp.status_code}",
        }
    except httpx.TimeoutException:
        return {
            **result,
            "ok": False,
            "status_code": 0,
            "latency_ms": None,
            "assistants": 0,
            "error": "timeout",
        }
    except httpx.HTTPError as exc:
        return {
            **result,
            "ok": False,
            "status_code": 0,
            "latency_ms": None,
            "assistants": 0,
            "error": exc.__class__.__name__,
        }
    except Exception as exc:  # noqa: BLE001 - health checks must never raise
        log.warning("nv-oos-fleet: health check failed for %s: %s", site["id"], exc)
        return {
            **result,
            "ok": False,
            "status_code": 0,
            "latency_ms": None,
            "assistants": 0,
            "error": exc.__class__.__name__,
        }


# ---------------------------------------------------------------------------
# SSE hub — one upstream connection per site, fanned out to browser tabs
# ---------------------------------------------------------------------------

_stream_subscribers: Dict[str, List[asyncio.Queue]] = {}
_stream_tasks: Dict[str, asyncio.Task] = {}


async def _stream_reader(site_id: str, site: Dict[str, Any]) -> None:
    """Hold one upstream SSE connection per site and forward events."""
    url = f"{site['url']}/wp-json/mcp-ai/v1/cron-status/stream"
    verify = not bool(site.get("allow_insecure", False))
    backoff = 0.5
    while True:
        subscribers = _stream_subscribers.get(site_id) or []
        if not subscribers:
            break
        try:
            client = _get_stream_client(verify=verify)
            async with client.stream(
                "GET", url, headers=_auth_headers(site)
            ) as resp:
                if resp.status_code != 200:
                    raise UpstreamError(resp.status_code, f"HTTP {resp.status_code}")
                buffer = ""
                async for chunk in resp.aiter_text():
                    buffer += chunk
                    while "\n\n" in buffer:
                        event, buffer = buffer.split("\n\n", 1)
                        for queue in list(_stream_subscribers.get(site_id) or []):
                            try:
                                queue.put_nowait(event + "\n\n")
                            except asyncio.QueueFull:
                                pass
            backoff = 0.5  # clean end → reconnect quickly
        except asyncio.CancelledError:
            break
        except Exception as exc:  # noqa: BLE001 - streams must survive upstream hiccups
            log.warning("nv-oos-fleet: stream reader failed for %s: %s", site_id, exc)
        await asyncio.sleep(backoff)
        backoff = min(backoff * 2, 30.0)


# ---------------------------------------------------------------------------
# Routes — Phase 0 (registry, connection tests, fleet health)
# ---------------------------------------------------------------------------


@router.get("/meta")
async def meta() -> Dict[str, Any]:
    _load_registry()
    return {"ok": True, "sites": len(_registry), "registry_file": REGISTRY_FILE.name}


@router.get("/sites")
async def list_sites() -> Dict[str, Any]:
    _load_registry()
    ordered = sorted(_registry.values(), key=lambda s: str(s.get("label", "")).lower())
    return {"sites": [_public(s) for s in ordered]}


@router.get("/sites/{site_id}")
async def get_site(site_id: str) -> Dict[str, Any]:
    return _public(_require_site(site_id))


@router.post("/sites", status_code=201)
async def create_site(body: SiteIn) -> Dict[str, Any]:
    _load_registry()
    token = body.token.strip()
    if not token:
        raise HTTPException(status_code=422, detail="token is required")
    url = _validate_url(body.url, body.allow_insecure)
    label = _cap(body.label, LABEL_MAX, "label") if body.label.strip() else url
    notes = _cap(body.notes, NOTES_MAX, "notes")
    now = _now()
    site = {
        "id": f"site-{uuid.uuid4().hex[:10]}",
        "label": label,
        "url": url,
        "token": token,
        "write": bool(body.write),
        "allow_insecure": bool(body.allow_insecure),
        "notes": notes,
        "created_at": now,
        "updated_at": now,
    }
    async with _registry_lock:
        _registry[site["id"]] = site
        _save_registry()
    _invalidate_cache()
    return _public(site)


@router.put("/sites/{site_id}")
async def update_site(site_id: str, body: SitePatch) -> Dict[str, Any]:
    site = _require_site(site_id)
    changes = _body_dict(body)
    if not changes:
        return _public(site)
    if "token" in changes:
        token = (changes.get("token") or "").strip()
        if not token:
            raise HTTPException(status_code=422, detail="token must not be empty")
        changes["token"] = token
    if "url" in changes:
        changes["url"] = _validate_url(
            str(changes.get("url") or ""),
            bool(changes.get("allow_insecure", site.get("allow_insecure", False))),
        )
    if changes.get("label") is not None:
        changes["label"] = _cap(str(changes["label"]), LABEL_MAX, "label") or site.get("label", "")
    if changes.get("notes") is not None:
        changes["notes"] = _cap(str(changes["notes"]), NOTES_MAX, "notes")
    changes["updated_at"] = _now()
    async with _registry_lock:
        site.update(changes)
        _save_registry()
    _invalidate_cache()
    return _public(site)


@router.delete("/sites/{site_id}")
async def delete_site(site_id: str) -> Dict[str, Any]:
    _require_site(site_id)
    async with _registry_lock:
        del _registry[site_id]
        _save_registry()
    task = _stream_tasks.pop(site_id, None)
    if task is not None:
        task.cancel()
    _stream_subscribers.pop(site_id, None)
    _invalidate_cache()
    return {"ok": True}


@router.post("/sites/{site_id}/test")
async def test_site(site_id: str) -> Dict[str, Any]:
    return await _check_site(_require_site(site_id))


def _mcp_env_var(site_id: str) -> str:
    return re.sub(r"[^A-Z0-9]", "_", site_id.upper()) + "_TOKEN"


@router.get("/sites/{site_id}/mcp-config")
async def mcp_config(site_id: str) -> Dict[str, Any]:
    """Emit a config.yaml fragment for Hermes' own MCP client.

    Uses ``${env:VAR}`` substitution (Hermes .env convention). The real token
    is deliberately not echoed.
    """
    site = _require_site(site_id)
    env_var = _mcp_env_var(site_id)
    snippet = (
        "mcp_servers:\n"
        f"  {site_id}:\n"
        f"    url: \"{site['url']}/wp-json/mcp-ai/v1/mcp\"\n"
        "    headers:\n"
        f"      Authorization: \"Bearer ${{env:{env_var}}}\"\n"
        "    timeout: 120\n"
    )
    return {"site_id": site_id, "env_var": env_var, "yaml": snippet}


@router.get("/fleet/health")
async def fleet_health() -> Dict[str, Any]:
    _load_registry()
    cached = _cache_get("fleet-health")
    if cached is not None:
        return cached
    sites = list(_registry.values())
    results: List[Dict[str, Any]] = []
    if sites:
        results = list(await asyncio.gather(*(_check_site(s) for s in sites)))
    payload = {
        "checked_at": _now(),
        "total": len(sites),
        "ok": sum(1 for r in results if r.get("ok")),
        "degraded": sum(1 for r in results if not r.get("ok")),
        "sites": results,
    }
    _cache_set("fleet-health", payload, TTL["fleet"])
    return payload


@router.get("/fleet/status")
async def fleet_status() -> Dict[str, Any]:
    """Lightweight badge payload derived from the cached health snapshot."""
    health = await fleet_health()
    return {
        "total": health["total"],
        "ok": health["ok"],
        "degraded": health["degraded"],
        "checked_at": health["checked_at"],
        "sites": [
            {
                "id": s["id"],
                "label": s["label"],
                "ok": bool(s.get("ok")),
                "error": s.get("error"),
            }
            for s in health["sites"]
        ],
    }


# ---------------------------------------------------------------------------
# Routes — Phase 1 (read-only monitoring)
# ---------------------------------------------------------------------------


@router.get("/fleet/overview")
async def fleet_overview(site: str) -> Dict[str, Any]:
    """Per-site deep dive: health + status + summary + updates, each degrading
    independently."""
    site_entry = _require_site(site)
    cached = _cache_get(f"overview:{site}")
    if cached is not None:
        return cached
    payload: Dict[str, Any] = {
        "id": site,
        "label": site_entry.get("label", ""),
        "url": site_entry["url"],
        "errors": {},
    }
    try:
        payload["health"] = await _rest_get(site_entry, "mcp-ai/v1/health")
    except UpstreamError as exc:
        payload["errors"]["health"] = exc.detail
    try:
        payload["status"] = await _rest_get(site_entry, "mcp-ai/v1/status")
    except UpstreamError as exc:
        payload["errors"]["status"] = exc.detail
    try:
        payload["summary"] = _unwrap(
            await _rpc(
                site_entry,
                "tools/call",
                {"name": "get_site_summary", "arguments": {}},
            )
        )
    except UpstreamError as exc:
        payload["errors"]["summary"] = exc.detail
    try:
        payload["updates"] = _unwrap(
            await _rpc(
                site_entry,
                "tools/call",
                {"name": "get_update_status", "arguments": {}},
            )
        )
    except UpstreamError as exc:
        payload["errors"]["updates"] = exc.detail
    _cache_set(f"overview:{site}", payload, TTL["overview"])
    return payload


@router.get("/logs")
async def logs(site: str, limit: int = 20) -> Dict[str, Any]:
    site_entry = _require_site(site)
    limit = max(1, min(limit, 50))
    cached = _cache_get(f"logs:{site}")
    if cached is not None:
        return cached
    payload: Dict[str, Any] = {"id": site, "activity": [], "errors": []}
    try:
        data = _unwrap(
            await _rpc(
                site_entry,
                "tools/call",
                {
                    "name": "get_system_logs_validated",
                    "arguments": {
                        "activity_limit": limit,
                        "error_limit": limit,
                        "include_debug_log": False,
                    },
                },
            )
        )
        if isinstance(data, dict):
            payload["activity"] = data.get("activity") or data.get("activity_log") or []
            payload["errors"] = data.get("errors") or data.get("error_log") or []
    except UpstreamError as exc:
        payload["error"] = exc.detail
    _cache_set(f"logs:{site}", payload, TTL["logs"])
    return payload


@router.get("/jobs")
async def jobs(site: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"jobs:{site}")
    if cached is not None:
        return cached
    payload: Dict[str, Any] = {"id": site, "async_jobs": [], "wp_cron": [], "errors": {}}
    try:
        payload["async_jobs"] = _as_list(
            await _rest_get(site_entry, "mcp-ai/v1/cron-status")
        )
    except UpstreamError as exc:
        payload["errors"]["async_jobs"] = exc.detail
    try:
        payload["wp_cron"] = _as_list(
            _unwrap(
                await _rpc(
                    site_entry,
                    "tools/call",
                    {"name": "list_cron_jobs", "arguments": {}},
                )
            )
        )
    except UpstreamError as exc:
        payload["errors"]["wp_cron"] = exc.detail
    _cache_set(f"jobs:{site}", payload, TTL["jobs"])
    return payload


@router.get("/jobs/stream")
async def jobs_stream(site: str) -> StreamingResponse:
    """SSE passthrough of the site's job stream, fanned out to browser tabs."""
    site_entry = _require_site(site)
    queue: asyncio.Queue = asyncio.Queue(maxsize=100)
    subscribers = _stream_subscribers.setdefault(site, [])
    subscribers.append(queue)
    existing = _stream_tasks.get(site)
    if existing is None or existing.done():
        _stream_tasks[site] = asyncio.create_task(_stream_reader(site, site_entry))

    async def gen() -> AsyncIterator[str]:
        try:
            yield "event: fleet-connected\ndata: {}\n\n"
            while True:
                chunk = await queue.get()
                yield chunk
        finally:
            remaining = _stream_subscribers.get(site) or []
            if queue in remaining:
                remaining.remove(queue)
            if not remaining:
                task = _stream_tasks.pop(site, None)
                if task is not None:
                    task.cancel()

    return StreamingResponse(
        gen(),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )


@router.post("/jobs/{site}/cancel")
async def job_cancel(site: str, body: JobAction) -> Dict[str, Any]:
    site_entry = _require_site(site)
    _require_write(site_entry)
    job_id = _check(_JOB_ID_RE, body.job_id, "job_id")
    result = await _rest_send(
        site_entry, "POST", f"mcp-ai/v1/cron-status/{job_id}/cancel"
    )
    _invalidate_cache()
    return _unwrap(result)


@router.post("/jobs/{site}/retry")
async def job_retry(site: str, body: JobAction) -> Dict[str, Any]:
    site_entry = _require_site(site)
    _require_write(site_entry)
    job_id = _check(_JOB_ID_RE, body.job_id, "job_id")
    result = await _rest_send(
        site_entry, "POST", f"mcp-ai/v1/cron-status/{job_id}/retry"
    )
    _invalidate_cache()
    return _unwrap(result)


@router.post("/jobs/{site}/wp-cron/delete")
async def wp_cron_delete(site: str, body: JobAction) -> Dict[str, Any]:
    site_entry = _require_site(site)
    _require_write(site_entry)
    job_id = _check(_JOB_ID_RE, body.job_id, "job_id")
    result = _unwrap(
        await _rpc(
            site_entry,
            "tools/call",
            {"name": "delete_cron_job", "arguments": {"job_id": job_id}},
        )
    )
    _invalidate_cache()
    return result


@router.get("/analytics/summary")
async def analytics_summary(site: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"analytics:{site}")
    if cached is not None:
        return cached
    payload: Dict[str, Any] = {"id": site, "errors": {}}
    for key, path in (
        ("dashboard", "mcp-ai/v1/cost/dashboard-summary"),
        ("total", "mcp-ai/v1/cost/total"),
        ("by_provider", "mcp-ai/v1/cost/by-provider"),
    ):
        try:
            payload[key] = await _rest_get(site_entry, path)
        except UpstreamError as exc:
            payload["errors"][key] = exc.detail
    _cache_set(f"analytics:{site}", payload, TTL["analytics"])
    return payload


@router.get("/security/posture")
async def security_posture(site: str, refresh: bool = False) -> Dict[str, Any]:
    site_entry = _require_site(site)
    if not refresh:
        cached = _cache_get(f"posture:{site}")
        if cached is not None:
            return cached
    try:
        params = {"refresh": "1"} if refresh else None
        payload = await _rest_get(site_entry, "mcp-ai/v1/security/posture", params=params)
        if isinstance(payload, dict):
            payload.setdefault("id", site)
    except UpstreamError as exc:
        payload = {"id": site, "ok": False, "error": exc.detail}
    _cache_set(f"posture:{site}", payload, TTL["posture"])
    return payload


# ---------------------------------------------------------------------------
# Routes — Phase 2 (control plane; writes gated on site.write)
# ---------------------------------------------------------------------------


@router.get("/tools")
async def tools(site: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"tools:{site}")
    if cached is not None:
        return cached
    payload: Dict[str, Any] = {"id": site, "tools": []}
    try:
        raw = await _rpc(site_entry, "tools/list")
        tools_list = raw.get("tools", raw) if isinstance(raw, dict) else raw
        payload["tools"] = tools_list if isinstance(tools_list, list) else []
    except UpstreamError as exc:
        payload["error"] = exc.detail
    _cache_set(f"tools:{site}", payload, TTL["tools"])
    return payload


@router.post("/tools/call")
async def tools_call(body: ToolCall) -> Dict[str, Any]:
    site_entry = _require_site(body.site)
    _require_write(site_entry)
    tool = _check(_TOOL_RE, body.tool, "tool name")
    result = _unwrap(
        await _rpc(
            site_entry,
            "tools/call",
            {"name": tool, "arguments": body.arguments or {}},
        )
    )
    return result


@router.get("/tokens/usage")
async def token_usage(site: str, user_id: int) -> Dict[str, Any]:
    site_entry = _require_site(site)
    try:
        return await _rest_get(site_entry, f"mcp-ai/v1/users/{user_id}/token-usage")
    except UpstreamError as exc:
        return {"id": site, "ok": False, "error": exc.detail}


@router.get("/paper-store")
async def paper_store_collections(site: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"paper:{site}")
    if cached is not None:
        return cached
    try:
        payload = await _rest_get(site_entry, "mcp-ai/v1/paper-store")
        payload = {"id": site, "collections": _as_list(payload), "error": None}
    except UpstreamError as exc:
        payload = {"id": site, "collections": [], "error": exc.detail}
    _cache_set(f"paper:{site}", payload, TTL["paper"])
    return payload


@router.get("/paper-store/records")
async def paper_store_records(site: str, collection: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    collection = _check(_COLLECTION_RE, collection, "collection")
    cache_key = f"paper:{site}:{collection}"
    cached = _cache_get(cache_key)
    if cached is not None:
        return cached
    try:
        payload = await _rest_get(
            site_entry, f"mcp-ai/v1/paper-store/{collection}"
        )
        payload = {"id": site, "records": _as_list(payload), "error": None}
    except UpstreamError as exc:
        payload = {"id": site, "records": [], "error": exc.detail}
    _cache_set(cache_key, payload, TTL["paper"])
    return payload


@router.get("/paper-store/search")
async def paper_store_search(site: str, q: str) -> Dict[str, Any]:
    site_entry = _require_site(site)
    q = _cap(q, QUERY_MAX, "query")
    try:
        payload = await _rest_get(
            site_entry, "mcp-ai/v1/paper-store/search", params={"q": q}
        )
        return {"id": site, "records": _as_list(payload), "error": None}
    except UpstreamError as exc:
        return {"id": site, "records": [], "error": exc.detail}


@router.post("/paper-store/records", status_code=201)
async def paper_store_create(body: PaperRecordIn) -> Dict[str, Any]:
    site_entry = _require_site(body.site)
    _require_write(site_entry)
    collection = _check(_COLLECTION_RE, body.collection, "collection")
    if body.records is not None:
        result = await _rest_send(
            site_entry,
            "POST",
            f"mcp-ai/v1/paper-store/{collection}/import",
            json_body={"records": body.records},
        )
    else:
        result = await _rest_send(
            site_entry,
            "POST",
            f"mcp-ai/v1/paper-store/{collection}",
            json_body=body.record or {},
        )
    _invalidate_cache()
    return _unwrap(result)


@router.delete("/paper-store/records")
async def paper_store_delete(body: PaperRecordDelete) -> Dict[str, Any]:
    site_entry = _require_site(body.site)
    _require_write(site_entry)
    collection = _check(_COLLECTION_RE, body.collection, "collection")
    record_id = _check(_RECORD_ID_RE, body.record_id, "record_id")
    result = await _rest_send(
        site_entry,
        "DELETE",
        f"mcp-ai/v1/paper-store/{collection}/{record_id}",
    )
    _invalidate_cache()
    return _unwrap(result)


@router.get("/workflows/runs")
async def workflow_runs(site: str, per_page: int = 20) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"workflows:{site}")
    if cached is not None:
        return cached
    try:
        payload = await _rest_get(
            site_entry,
            "mcp-ai/v1/orchestration/runs",
            params={"per_page": max(1, min(per_page, 100))},
        )
        payload = {"id": site, "runs": _as_list(payload), "error": None}
    except UpstreamError as exc:
        payload = {"id": site, "runs": [], "error": exc.detail}
    _cache_set(f"workflows:{site}", payload, TTL["workflows"])
    return payload


@router.get("/workflows/runs/{site}/{run_id}")
async def workflow_run(site: str, run_id: int) -> Dict[str, Any]:
    site_entry = _require_site(site)
    try:
        return await _rest_get(site_entry, f"mcp-ai/v1/orchestration/runs/{run_id}")
    except UpstreamError as exc:
        return {"id": site, "ok": False, "error": exc.detail}


@router.get("/workflows/runs/{site}/{run_id}/events")
async def workflow_run_events(site: str, run_id: int) -> Dict[str, Any]:
    site_entry = _require_site(site)
    try:
        payload = await _rest_get(
            site_entry, f"mcp-ai/v1/orchestration/runs/{run_id}/events"
        )
        return {"id": site, "events": _as_list(payload)}
    except UpstreamError as exc:
        return {"id": site, "events": [], "error": exc.detail}


@router.get("/mesh/peers")
async def mesh_peers(site: str, per_page: int = 100) -> Dict[str, Any]:
    site_entry = _require_site(site)
    cached = _cache_get(f"mesh:{site}")
    if cached is not None:
        return cached
    try:
        payload = await _rest_get(
            site_entry,
            "ai-dir/v1/peers",
            params={"per_page": max(1, min(per_page, 100))},
        )
        payload = {"id": site, "peers": _as_list(payload), "error": None}
    except UpstreamError as exc:
        payload = {"id": site, "peers": [], "error": exc.detail}
    _cache_set(f"mesh:{site}", payload, TTL["mesh"])
    return payload


@router.post("/mesh/peers/reverify")
async def mesh_reverify(body: MeshAction) -> Dict[str, Any]:
    site_entry = _require_site(body.site)
    _require_write(site_entry)
    result = await _rest_send(
        site_entry, "POST", f"ai-dir/v1/reverify/{body.peer_id}"
    )
    _invalidate_cache()
    return _unwrap(result)


@router.post("/mesh/peers/report")
async def mesh_report(body: MeshReport) -> Dict[str, Any]:
    site_entry = _require_site(body.site)
    _require_write(site_entry)
    reason = _cap(body.reason, REASON_MAX, "reason")
    result = await _rest_send(
        site_entry,
        "POST",
        f"ai-dir/v1/report/{body.peer_id}",
        json_body={"reason": reason} if reason else {},
    )
    _invalidate_cache()
    return _unwrap(result)


# ---------------------------------------------------------------------------
# Routes — Phase 3 (agent ops)
# ---------------------------------------------------------------------------


def _atomic_write_text(path: Path, text: str, chmod_0600: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(text, encoding="utf-8")
    os.replace(tmp, path)
    if chmod_0600:
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass


def _atomic_write_yaml(path: Path, data: Any) -> None:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S")
    backup = path.with_name(f"{path.name}.bak-nv-oos-{stamp}")
    if path.exists():
        backup.write_bytes(path.read_bytes())
    _atomic_write_text(path, yaml.safe_dump(data, sort_keys=False))


@router.post("/sites/{site_id}/mcp-config/apply")
async def mcp_config_apply(site_id: str) -> Dict[str, Any]:
    """Write this site into the local Hermes config (~/.hermes/config.yaml)
    and .env (0600), with backups. Local-Hermes action; does not touch WP."""
    site = _require_site(site_id)
    env_var = _mcp_env_var(site_id)
    cfg_path = HERMES_HOME / "config.yaml"
    env_path = HERMES_HOME / ".env"

    if not cfg_path.exists():
        raise HTTPException(
            status_code=409, detail=f"{cfg_path} not found — run `hermes` once first"
        )
    try:
        cfg = yaml.safe_load(cfg_path.read_text(encoding="utf-8")) or {}
        if not isinstance(cfg, dict):
            raise HTTPException(
                status_code=409, detail="config.yaml root is not a mapping; refusing to edit"
            )
    except yaml.YAMLError as exc:
        raise HTTPException(status_code=409, detail=f"config.yaml is not valid YAML: {exc}") from exc

    servers = cfg.get("mcp_servers")
    if servers is None:
        servers = {}
    if not isinstance(servers, dict):
        raise HTTPException(
            status_code=409, detail="mcp_servers is not a mapping; refusing to edit"
        )
    servers[site_id] = {
        "url": f"{site['url']}/wp-json/mcp-ai/v1/mcp",
        "headers": {"Authorization": f"Bearer ${{env:{env_var}}}"},
        "timeout": 120,
    }
    cfg["mcp_servers"] = servers
    _atomic_write_yaml(cfg_path, cfg)

    env_lines: List[str] = []
    if env_path.exists():
        env_lines = env_path.read_text(encoding="utf-8").splitlines()
    new_line = f"{env_var}={site['token']}"
    replaced = False
    for i, line in enumerate(env_lines):
        if line.startswith(env_var + "="):
            env_lines[i] = new_line
            replaced = True
            break
    if not replaced:
        env_lines.append(new_line)
    _atomic_write_text(env_path, "\n".join(env_lines) + "\n", chmod_0600=True)

    return {
        "ok": True,
        "site_id": site_id,
        "env_var": env_var,
        "config_file": str(cfg_path),
        "env_file": str(env_path),
    }


async def _safe_cost_total(site: Dict[str, Any]) -> Dict[str, Any]:
    try:
        raw = await _rest_get(site, "mcp-ai/v1/cost/total")
        return {"id": site["id"], "label": site.get("label", ""), "ok": True, "cost": raw}
    except UpstreamError as exc:
        return {"id": site["id"], "label": site.get("label", ""), "ok": False, "error": exc.detail}


@router.get("/costs/summary")
async def costs_summary() -> Dict[str, Any]:
    """Cross-site cost totals for the sessions:bottom slot."""
    _load_registry()
    cached = _cache_get("costs-summary")
    if cached is not None:
        return cached
    sites = list(_registry.values())
    entries: List[Dict[str, Any]] = []
    if sites:
        entries = list(await asyncio.gather(*(_safe_cost_total(s) for s in sites)))
    payload = {"checked_at": _now(), "sites": entries}
    _cache_set("costs-summary", payload, TTL["costs"])
    return payload
