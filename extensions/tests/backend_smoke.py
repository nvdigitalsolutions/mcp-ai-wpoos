"""Functional smoke test for the nv-oos-fleet backend plugin (Phase 0-3).

Runs plugin_api.py's routes in-process against a mocked upstream WordPress
site (httpx.MockTransport injected into the plugin's client pools), exercising:

- registry CRUD, validation, redaction, persistence;
- fleet health/status/overview, logs, jobs (incl. write gating), analytics,
  security posture, tools (list + gated call), token usage, paper-store CRUD,
  workflow runs, mesh peers, costs summary;
- the jobs SSE hub (one upstream connection, fan-out to subscribers);
- mcp-config generation and local Hermes config apply (temp HERMES_HOME).

Refuses to run if a real sites.yaml exists (never clobber live tokens).

Run (throwaway venv keeps the repo environment clean):

    python -m venv .venv && .venv/Scripts/python -m pip install fastapi httpx pyyaml
    .venv/Scripts/python tests/backend_smoke.py      # Windows
    # or: .venv/bin/python tests/backend_smoke.py    # POSIX
"""

import asyncio
import json
import os
import shutil
import sys
import tempfile
from pathlib import Path

# Isolate HERMES_HOME before importing plugin_api so mcp-config/apply writes
# into a temp dir instead of the operator's real ~/.hermes.
TMP_HERMES_HOME = tempfile.mkdtemp(prefix="nv-oos-fleet-test-hermes-")
os.environ["HERMES_HOME"] = TMP_HERMES_HOME

DASHBOARD = Path(__file__).resolve().parent.parent / "nv-oos-fleet" / "dashboard"
sys.path.insert(0, str(DASHBOARD))

import httpx  # noqa: E402
import yaml  # noqa: E402

import plugin_api  # noqa: E402

from fastapi import FastAPI  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

REGISTRY = DASHBOARD / "sites.yaml"
assert not REGISTRY.exists(), "pre-existing sites.yaml would be clobbered"


def yaml_safe_load(path):
    return yaml.safe_load(Path(path).read_text(encoding="utf-8"))

# ---------------------------------------------------------------------------
# Mock upstream WordPress site
# ---------------------------------------------------------------------------


def _sse_response(events):
    payload = "".join(events).encode("utf-8")
    return httpx.Response(
        200,
        headers={"content-type": "text/event-stream"},
        content=payload,
    )


def _mock_handler(request: httpx.Request) -> httpx.Response:
    path = request.url.path
    method = request.method

    def ok(data, status=200):
        return httpx.Response(status, json=data)

    # MCP JSON-RPC endpoint.
    if path.endswith("/wp-json/mcp-ai/v1/mcp") and method == "POST":
        body = json.loads(request.content or b"{}")
        rpc_method = body.get("method")
        params = body.get("params") or {}
        if rpc_method == "tools/list":
            return ok(
                {
                    "jsonrpc": "2.0",
                    "id": 1,
                    "result": {
                        "tools": [
                            {"name": "get_recent_posts", "description": "List recent posts"},
                            {"name": "web_search", "description": "Search the web"},
                        ]
                    },
                }
            )
        if rpc_method == "tools/call":
            name = params.get("name")
            arguments = params.get("arguments") or {}
            if name == "get_system_logs_validated":
                return ok(
                    {
                        "jsonrpc": "2.0",
                        "id": 1,
                        "result": {
                            "success": True,
                            "data": {
                                "activity": [
                                    {
                                        "type": "tool_execution",
                                        "message": "Ran web_search",
                                        "timestamp": "2026-09-03T00:00:00Z",
                                    }
                                ],
                                "errors": [
                                    {"message": "Upstream timeout", "timestamp": "2026-09-03T00:01:00Z"}
                                ],
                            },
                        },
                    }
                )
            if name == "list_cron_jobs":
                return ok(
                    {
                        "jsonrpc": "2.0",
                        "id": 1,
                        "result": {
                            "success": True,
                            "data": {
                                "jobs": [
                                    {
                                        "job_id": "cron-1",
                                        "hook": "wp_mcp_ai_daily",
                                        "next_run": "2026-09-04T00:00:00Z",
                                    }
                                ]
                            },
                        },
                    }
                )
            if name == "delete_cron_job":
                return ok(
                    {
                        "jsonrpc": "2.0",
                        "id": 1,
                        "result": {"success": True, "data": {"deleted": arguments.get("job_id")}},
                    }
                )
            if name == "get_site_summary":
                return ok(
                    {
                        "jsonrpc": "2.0",
                        "id": 1,
                        "result": {
                            "success": True,
                            "data": {"name": "Demo Site", "url": "https://demo.example.com", "posts": 42},
                        },
                    }
                )
            if name == "get_update_status":
                return ok(
                    {
                        "jsonrpc": "2.0",
                        "id": 1,
                        "result": {"success": True, "data": {"plugins": [{"name": "mcp-ai-wpoos", "update": "none"}]}},
                    }
                )
            return ok(
                {
                    "jsonrpc": "2.0",
                    "id": 1,
                    "result": {"success": True, "data": {"echo": arguments}},
                }
            )
        return ok({"jsonrpc": "2.0", "id": 1, "result": {}})

    # Plain REST surface.
    if path.endswith("/wp-json/mcp-ai/v1/assistants") and method == "GET":
        return ok([{"id": 1, "name": "A"}, {"id": 2, "name": "B"}])
    if path.endswith("/wp-json/mcp-ai/v1/health") and method == "GET":
        return ok({"status": "ok", "checks": {"php": "ok"}})
    if path.endswith("/wp-json/mcp-ai/v1/status") and method == "GET":
        return ok({"ok": True, "services": [{"name": "api", "status": "up"}]})
    if path.endswith("/wp-json/mcp-ai/v1/cron-status") and method == "GET":
        return ok({"jobs": [{"job_id": "job-1", "tool": "web_search", "status": "running"}]})
    if "/wp-json/mcp-ai/v1/cron-status/" in path and method == "POST":
        return ok({"success": True})
    if path.endswith("/wp-json/mcp-ai/v1/cost/dashboard-summary") and method == "GET":
        return ok({"total_cost": 2.5, "currency": "USD"})
    if path.endswith("/wp-json/mcp-ai/v1/cost/total") and method == "GET":
        return ok({"total": 2.5, "currency": "USD"})
    if path.endswith("/wp-json/mcp-ai/v1/cost/by-provider") and method == "GET":
        return ok({"openai": 1.5, "gemini": 1.0})
    if path.endswith("/wp-json/mcp-ai/v1/security/posture") and method == "GET":
        return ok({"score": 87, "signals": [{"name": "rate_limiting", "ok": True}]})
    if path.endswith("/wp-json/mcp-ai/v1/paper-store") and method == "GET":
        return ok({"collections": [{"name": "knowledge", "count": 3}]})
    if path.endswith("/wp-json/mcp-ai/v1/paper-store/knowledge"):
        if method == "GET":
            return ok([{"id": "r1", "title": "Record one"}])
        if method == "POST":
            return ok({"id": "r2", "title": "Created"})
    if path.endswith("/wp-json/mcp-ai/v1/paper-store/knowledge/r1") and method == "DELETE":
        return ok({"ok": True})
    if path.endswith("/wp-json/mcp-ai/v1/orchestration/runs") and method == "GET":
        return ok([{"id": 7, "workflow": "w-1", "status": "completed"}])
    if path.endswith("/wp-json/mcp-ai/v1/orchestration/runs/7") and method == "GET":
        return ok({"id": 7, "workflow": "w-1", "status": "completed"})
    if path.endswith("/wp-json/mcp-ai/v1/orchestration/runs/7/events") and method == "GET":
        return ok([{"event": "started", "at": "2026-09-03T00:00:00Z"}])
    if path.endswith("/wp-json/ai-dir/v1/peers") and method == "GET":
        return ok([{"id": 3, "name": "Peer Three", "url": "https://peer.example"}])
    if "/wp-json/ai-dir/v1/reverify/" in path and method == "POST":
        return ok({"ok": True})
    if "/wp-json/ai-dir/v1/report/" in path and method == "POST":
        return ok({"ok": True})
    if "/wp-json/mcp-ai/v1/users/" in path and "token-usage" in path and method == "GET":
        return ok({"used": 1234, "limit": 10000})
    if path.endswith("/wp-json/mcp-ai/v1/cron-status/stream") and method == "GET":
        return _sse_response(
            ["event: job:update\ndata: {\"job_id\":\"job-1\"}\n\n", "event: job:done\ndata: {\"job_id\":\"job-1\"}\n\n"]
        )

    return httpx.Response(404, json={"code": "rest_no_route", "message": "mock: no route " + path, "data": {"status": 404}})


_mock = httpx.MockTransport(_mock_handler)
plugin_api._clients[True] = httpx.AsyncClient(
    transport=_mock, timeout=httpx.Timeout(5.0, read=10.0)
)
plugin_api._clients[False] = plugin_api._clients[True]
plugin_api._stream_clients[True] = httpx.AsyncClient(
    transport=_mock, timeout=httpx.Timeout(5.0, read=25.0)
)
plugin_api._stream_clients[False] = plugin_api._stream_clients[True]

app = FastAPI()
app.include_router(plugin_api.router)
client = TestClient(app)

TOKEN_A = "op_abcdefghijklmnopqrstuvwxyz.SECRET"
TOKEN_B = "op_zyxwvutsrqponmlkjihgfedcba.SECRET"

# ---------------------------------------------------------------------------
# Phase 0 — registry, redaction, validation
# ---------------------------------------------------------------------------

r = client.get("/meta")
assert r.status_code == 200 and r.json()["ok"] is True, r.text

r = client.post(
    "/sites",
    json={"label": "Bad", "url": "http://insecure.example", "token": TOKEN_A},
)
assert r.status_code == 422, r.text

r = client.post(
    "/sites",
    json={"label": "Demo A", "url": "https://demo.example.com/", "token": TOKEN_A, "write": True},
)
assert r.status_code == 201, r.text
site_a = r.json()
assert "token" not in site_a, "token leaked in create response"
assert site_a["token_hint"].startswith("op_") and "*" in site_a["token_hint"]
assert site_a["url"] == "https://demo.example.com"
assert site_a["write"] is True
sid_a = site_a["id"]

r = client.post(
    "/sites",
    json={"label": "Demo B", "url": "https://demo2.example.com/", "token": TOKEN_B},
)
assert r.status_code == 201, r.text
site_b = r.json()
assert site_b["write"] is False
sid_b = site_b["id"]

r = client.put(f"/sites/{sid_a}", json={"notes": "hello"})
assert r.status_code == 200 and r.json()["notes"] == "hello"
r = client.put(f"/sites/{sid_a}", json={"token": "   "})
assert r.status_code == 422, "empty token accepted"
r = client.get("/sites/site-nope")
assert r.status_code == 404

r = client.get(f"/sites/{sid_a}/mcp-config")
assert r.status_code == 200
body = r.json()
assert "${env:" in body["yaml"], body["yaml"]
assert "op_" not in body["yaml"], "token echoed in mcp-config"

# Fleet health now sees the mocked upstream → healthy with 2 assistants.
r = client.get("/fleet/health")
assert r.status_code == 200, r.text
health = r.json()
assert health["total"] == 2 and health["ok"] == 2 and health["degraded"] == 0
assert health["sites"][0]["assistants"] == 2

r2 = client.get("/fleet/status")
assert r2.status_code == 200
assert r2.json()["checked_at"] == health["checked_at"], "cache missed"

# ---------------------------------------------------------------------------
# Phase 1 — read-only monitoring
# ---------------------------------------------------------------------------

r = client.get(f"/fleet/overview?site={sid_a}")
assert r.status_code == 200, r.text
overview = r.json()
assert overview["health"]["status"] == "ok"
assert overview["status"]["ok"] is True
assert overview["summary"]["posts"] == 42
assert overview["updates"]["plugins"][0]["name"] == "mcp-ai-wpoos"

r = client.get(f"/logs?site={sid_a}&limit=30")
assert r.status_code == 200, r.text
logs = r.json()
assert len(logs["activity"]) == 1 and len(logs["errors"]) == 1

r = client.get(f"/jobs?site={sid_a}")
assert r.status_code == 200, r.text
jobs = r.json()
assert len(jobs["async_jobs"]) == 1 and len(jobs["wp_cron"]) == 1

# Write gating: site B has write:false → 403 on all control actions.
r = client.post(f"/jobs/{sid_b}/cancel", json={"job_id": "job-1"})
assert r.status_code == 403, r.text
r = client.post(f"/jobs/{sid_b}/wp-cron/delete", json={"job_id": "cron-1"})
assert r.status_code == 403, r.text

# Site A allows writes.
r = client.post(f"/jobs/{sid_a}/cancel", json={"job_id": "job-1"})
assert r.status_code == 200 and r.json()["success"] is True
r = client.post(f"/jobs/{sid_a}/retry", json={"job_id": "job-1"})
assert r.status_code == 200
r = client.post(f"/jobs/{sid_a}/wp-cron/delete", json={"job_id": "cron-1"})
assert r.status_code == 200 and r.json()["deleted"] == "cron-1"

r = client.get(f"/analytics/summary?site={sid_a}")
assert r.status_code == 200, r.text
analytics = r.json()
assert analytics["total"]["total"] == 2.5
assert analytics["by_provider"]["openai"] == 1.5

r = client.get(f"/security/posture?site={sid_a}")
assert r.status_code == 200, r.text
assert r.json()["score"] == 87

# ---------------------------------------------------------------------------
# Phase 2 — control plane
# ---------------------------------------------------------------------------

r = client.get(f"/tools?site={sid_a}")
assert r.status_code == 200, r.text
tools = r.json()
assert len(tools["tools"]) == 2

r = client.post(
    "/tools/call",
    json={"site": sid_b, "tool": "web_search", "arguments": {"q": "x"}},
)
assert r.status_code == 403, "tools/call must be write-gated"
r = client.post(
    "/tools/call",
    json={"site": sid_a, "tool": "web_search", "arguments": {"q": "x"}},
)
assert r.status_code == 200 and r.json()["echo"]["q"] == "x"

r = client.get(f"/tokens/usage?site={sid_a}&user_id=7")
assert r.status_code == 200 and r.json()["used"] == 1234

r = client.get(f"/paper-store?site={sid_a}")
assert r.status_code == 200 and r.json()["collections"][0]["name"] == "knowledge"
r = client.get(f"/paper-store/records?site={sid_a}&collection=knowledge")
assert r.status_code == 200 and r.json()["records"][0]["id"] == "r1"
r = client.post(
    "/paper-store/records",
    json={"site": sid_b, "collection": "knowledge", "record": {"title": "Nope"}},
)
assert r.status_code == 403, "paper-store create must be write-gated"
r = client.post(
    "/paper-store/records",
    json={"site": sid_a, "collection": "knowledge", "record": {"title": "Created"}},
)
assert r.status_code == 201 and r.json()["id"] == "r2"
r = client.request(
    "DELETE",
    "/paper-store/records",
    content=json.dumps({"site": sid_a, "collection": "knowledge", "record_id": "r1"}),
    headers={"Content-Type": "application/json"},
)
assert r.status_code == 200 and r.json()["ok"] is True

r = client.get(f"/workflows/runs?site={sid_a}")
assert r.status_code == 200 and r.json()["runs"][0]["id"] == 7
r = client.get(f"/workflows/runs/{sid_a}/7/events")
assert r.status_code == 200 and r.json()["events"][0]["event"] == "started"

r = client.get(f"/mesh/peers?site={sid_a}")
assert r.status_code == 200 and r.json()["peers"][0]["name"] == "Peer Three"
r = client.post("/mesh/peers/reverify", json={"site": sid_b, "peer_id": 3})
assert r.status_code == 403, "reverify must be write-gated"
r = client.post("/mesh/peers/reverify", json={"site": sid_a, "peer_id": 3})
assert r.status_code == 200
r = client.post("/mesh/peers/report", json={"site": sid_a, "peer_id": 3, "reason": "spam"})
assert r.status_code == 200

# ---------------------------------------------------------------------------
# Input validation hardening (upstream path-segment injection guards)
# ---------------------------------------------------------------------------

r = client.get(f"/paper-store/records?site={sid_a}&collection=..%2F..%2Fetc")
assert r.status_code == 422, "traversal collection accepted"
r = client.post(
    "/paper-store/records",
    json={"site": sid_a, "collection": "bad/../../etc", "record": {"title": "x"}},
)
assert r.status_code == 422, "traversal collection accepted on create"
r = client.request(
    "DELETE",
    "/paper-store/records",
    content=json.dumps({"site": sid_a, "collection": "knowledge", "record_id": "../r1"}),
    headers={"Content-Type": "application/json"},
)
assert r.status_code == 422, "traversal record_id accepted"
r = client.post(
    "/tools/call",
    json={"site": sid_a, "tool": "bad tool/name", "arguments": {}},
)
assert r.status_code == 422, "malformed tool name accepted"
r = client.post(f"/jobs/{sid_a}/cancel", json={"job_id": "bad/job"})
assert r.status_code == 422, "malformed job_id accepted"
r = client.get(f"/paper-store/search?site={sid_a}&q={'x' * 300}")
assert r.status_code == 422, "over-long query accepted"
r = client.post(
    "/sites",
    json={"label": "L" * 300, "url": "https://demo3.example.com", "token": TOKEN_A},
)
assert r.status_code == 422, "over-long label accepted"

# Manifest audit — mirrors the WebUI workstream's validate-extensions tooling:
# every file the dashboard manifest references must exist.
manifest = json.loads((DASHBOARD / "manifest.json").read_text(encoding="utf-8"))
assert manifest["name"] == "nv-oos-fleet"
assert manifest["tab"]["path"].startswith("/")
assert manifest["entry"] and manifest["css"] and manifest["api"]
for rel in (manifest["entry"], manifest["css"], manifest["api"]):
    assert (DASHBOARD / rel).exists(), f"manifest references missing file: {rel}"
assert "header-right" in manifest["slots"]

# ---------------------------------------------------------------------------
# Phase 3 — agent ops
# ---------------------------------------------------------------------------

# mcp-config/apply writes into the temp HERMES_HOME (config pre-seeded).
hermes_home = Path(TMP_HERMES_HOME)
(hermes_home / "config.yaml").write_text("model:\n  default: claude\n", encoding="utf-8")
r = client.post(f"/sites/{sid_a}/mcp-config/apply")
assert r.status_code == 200, r.text
cfg = yaml_safe_load(hermes_home / "config.yaml")
assert cfg["mcp_servers"][sid_a]["url"].endswith("/wp-json/mcp-ai/v1/mcp")
assert "${env:" in cfg["mcp_servers"][sid_a]["headers"]["Authorization"]
env_text = (hermes_home / ".env").read_text(encoding="utf-8")
assert TOKEN_A in env_text, ".env must carry the real token for Hermes"
backups = list(hermes_home.glob("config.yaml.bak-nv-oos-*"))
assert len(backups) == 1, "backup of config.yaml expected"

# Cross-site costs summary.
r = client.get("/costs/summary")
assert r.status_code == 200, r.text
costs = r.json()
assert len(costs["sites"]) == 2
assert all(s["ok"] for s in costs["sites"])

# ---------------------------------------------------------------------------
# SSE hub — one upstream connection, fan-out, clean shutdown
#
# Exercised directly through StreamingResponse.body_iterator: this venv's
# starlette/httpx ASGITransport cannot deliver streaming responses (a minimal
# FastAPI streaming app hangs the same way — harness issue, not plugin code).
# ---------------------------------------------------------------------------


async def stream_test():
    response = await plugin_api.jobs_stream(site=sid_a)
    body = response.body_iterator
    first = await body.__anext__()
    assert "fleet-connected" in first, first
    second = await asyncio.wait_for(body.__anext__(), timeout=5)
    assert "job" in second, second  # forwarded from the mocked upstream SSE
    await body.aclose()  # subscriber leaves → reader task must be cancelled
    await asyncio.sleep(1.0)
    assert sid_a not in plugin_api._stream_tasks, "reader task leaked after last subscriber left"


asyncio.run(stream_test())

# ---------------------------------------------------------------------------
# Persistence + cleanup
# ---------------------------------------------------------------------------

on_disk = yaml_safe_load(REGISTRY)
assert on_disk["version"] == 1 and len(on_disk["sites"]) == 2

r = client.delete(f"/sites/{sid_a}")
assert r.status_code == 200 and r.json()["ok"] is True
r = client.delete(f"/sites/{sid_b}")
assert r.status_code == 200
r = client.get("/meta")
assert r.json()["sites"] == 0

# cleanup
REGISTRY.unlink(missing_ok=True)
(REGISTRY.with_suffix(".yaml.tmp")).unlink(missing_ok=True)
shutil.rmtree(TMP_HERMES_HOME, ignore_errors=True)

print("BACKEND_FUNCTIONAL_OK")
