"""NV oOS Fleet — Hermes dashboard backend plugin.

Mounted by the Hermes web dashboard at ``/api/plugins/nv-oos-fleet/`` (see
``manifest.json`` → ``api``). Persists a site registry to ``sites.yaml`` next
to this file (0600 where supported), proxies connection tests to each site's
NV oOS REST API, and serves fleet health/status with a short in-memory TTL
cache.

Security contract:
- Tokens live only in ``sites.yaml``; no endpoint ever returns them.
- ``token_hint`` redacts everything but the credential prefix/suffix.
- https-only URLs unless the site entry opts into ``allow_insecure``.
- Upstream errors never include request headers or tokens.

Compatibility: Python 3.11+ (Hermes runtime), FastAPI + pydantic v1 or v2,
httpx, PyYAML.
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
from typing import Any, Dict, List, Optional, Tuple

import httpx
import yaml
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

log = logging.getLogger("nv-oos-fleet")

router = APIRouter()

REGISTRY_FILE = Path(__file__).resolve().parent / "sites.yaml"

FLEET_CACHE_TTL = 30.0  # seconds
CONNECT_TIMEOUT = 5.0
READ_TIMEOUT = 10.0

HEALTH_PATH = "/wp-json/mcp-ai/v1/assistants"

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


def _body_dict(model: BaseModel) -> Dict[str, Any]:
    """Return only explicitly-set fields, pydantic v1/v2 compatible."""
    if hasattr(model, "model_dump"):
        return model.model_dump(exclude_unset=True)
    return model.dict(exclude_unset=True)  # type: ignore[attr-defined]


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


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


def _get_client(verify: bool = True) -> httpx.AsyncClient:
    if _clients.get(verify) is None or _clients[verify].is_closed:
        _clients[verify] = httpx.AsyncClient(
            timeout=httpx.Timeout(CONNECT_TIMEOUT, read=READ_TIMEOUT),
            follow_redirects=True,
            verify=verify,
        )
    return _clients[verify]


async def _check_site(site: Dict[str, Any]) -> Dict[str, Any]:
    """GET the site's assistant index with the operator token."""
    result: Dict[str, Any] = {"id": site["id"], "label": site.get("label", "")}
    headers = {"Authorization": f"Bearer {site['token']}"}
    verify = not bool(site.get("allow_insecure", False))
    started = time.monotonic()
    try:
        client = _get_client(verify=verify)
        resp = await client.get(f"{site['url']}{HEALTH_PATH}", headers=headers)
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


def _require_site(site_id: str) -> Dict[str, Any]:
    _load_registry()
    site = _registry.get(site_id)
    if site is None:
        raise HTTPException(status_code=404, detail="site not found")
    return site


# ---------------------------------------------------------------------------
# Routes
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
    now = _now()
    site = {
        "id": f"site-{uuid.uuid4().hex[:10]}",
        "label": body.label.strip() or url,
        "url": url,
        "token": token,
        "write": bool(body.write),
        "allow_insecure": bool(body.allow_insecure),
        "notes": body.notes.strip(),
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
        changes["label"] = str(changes["label"]).strip() or site.get("label", "")
    if changes.get("notes") is not None:
        changes["notes"] = str(changes["notes"]).strip()
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
    _invalidate_cache()
    return {"ok": True}


@router.post("/sites/{site_id}/test")
async def test_site(site_id: str) -> Dict[str, Any]:
    return await _check_site(_require_site(site_id))


@router.get("/sites/{site_id}/mcp-config")
async def mcp_config(site_id: str) -> Dict[str, Any]:
    """Emit a config.yaml fragment for Hermes' own MCP client.

    Uses ``${env:VAR}`` substitution (Hermes .env convention). The real token
    is deliberately not echoed — copy it from the WP-side operator generator.
    """
    site = _require_site(site_id)
    env_var = re.sub(r"[^A-Z0-9]", "_", site_id.upper()) + "_TOKEN"
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
    _cache_set("fleet-health", payload)
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
