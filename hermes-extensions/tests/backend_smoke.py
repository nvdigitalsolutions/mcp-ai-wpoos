"""Functional smoke test for the nv-oos-fleet backend plugin.

Runs plugin_api.py's routes in-process (FastAPI TestClient), exercising CRUD,
validation, redaction, health classification, caching, and persistence.
Refuses to run if a real sites.yaml exists (never clobber live tokens).

Run (throwaway venv keeps the repo environment clean):

    python -m venv .venv && .venv/Scripts/python -m pip install fastapi httpx pyyaml
    .venv/Scripts/python tests/backend_smoke.py      # Windows
    # or: .venv/bin/python tests/backend_smoke.py    # POSIX
"""

import sys
from pathlib import Path

DASHBOARD = Path(__file__).resolve().parent.parent / "nv-oos-fleet" / "dashboard"
sys.path.insert(0, str(DASHBOARD))

import plugin_api  # noqa: E402

from fastapi import FastAPI  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

REGISTRY = DASHBOARD / "sites.yaml"
assert not REGISTRY.exists(), "pre-existing sites.yaml would be clobbered"

app = FastAPI()
app.include_router(plugin_api.router)
client = TestClient(app)

# 1. meta
r = client.get("/meta")
assert r.status_code == 200 and r.json()["ok"] is True, r.text

# 2. http URL rejected unless allow_insecure
r = client.post(
    "/sites",
    json={"label": "Bad", "url": "http://insecure.example", "token": "op_aaaaaaaaaaaaaaaaaaaaaaaaaa.SECRET"},
)
assert r.status_code == 422, r.text

# 3. valid create: 201, token never echoed, hint redacted, slash stripped
r = client.post(
    "/sites",
    json={
        "label": "Demo",
        "url": "https://demo.example.com/",
        "token": "op_abcdefghijklmnopqrstuvwxyz.SECRET",
        "write": True,
    },
)
assert r.status_code == 201, r.text
site = r.json()
assert "token" not in site, "token leaked in create response"
assert site["token_hint"].startswith("op_") and "*" in site["token_hint"]
assert site["url"] == "https://demo.example.com", site["url"]
assert site["write"] is True
sid = site["id"]

# 4. read / update / 404 / empty-token rejection
r = client.get(f"/sites/{sid}")
assert r.status_code == 200 and r.json()["label"] == "Demo"
r = client.put(f"/sites/{sid}", json={"notes": "hello"})
assert r.status_code == 200 and r.json()["notes"] == "hello"
r = client.put(f"/sites/{sid}", json={"token": "   "})
assert r.status_code == 422, "empty token accepted"
r = client.get("/sites/site-nope")
assert r.status_code == 404

# 5. mcp-config fragment: env substitution, no token echo
r = client.get(f"/sites/{sid}/mcp-config")
assert r.status_code == 200
body = r.json()
assert "${env:" in body["yaml"], body["yaml"]
assert "op_" not in body["yaml"], "token echoed in mcp-config"

# 6. fleet/health classifies the unreachable site as degraded (never raises)
r = client.get("/fleet/health")
assert r.status_code == 200, r.text
health = r.json()
assert health["total"] == 1 and health["degraded"] == 1 and health["ok"] == 0
entry = health["sites"][0]
assert entry["ok"] is False and isinstance(entry["error"], str)

# 7. status payload mirrors health and is cached (same checked_at)
r2 = client.get("/fleet/status")
assert r2.status_code == 200
status = r2.json()
assert status["total"] == 1 and status["degraded"] == 1
assert status["checked_at"] == health["checked_at"], "cache missed"

# 8. persistence: registry file exists on disk with expected shape
import yaml  # noqa: E402

on_disk = yaml.safe_load(REGISTRY.read_text(encoding="utf-8"))
assert on_disk["version"] == 1 and len(on_disk["sites"]) == 1
assert on_disk["sites"][0]["id"] == sid

# 9. delete
r = client.delete(f"/sites/{sid}")
assert r.status_code == 200 and r.json()["ok"] is True
r = client.get(f"/sites/{sid}")
assert r.status_code == 404
r = client.get("/meta")
assert r.json()["sites"] == 0

# cleanup
REGISTRY.unlink(missing_ok=True)
(REGISTRY.with_suffix(".yaml.tmp")).unlink(missing_ok=True)

print("BACKEND_FUNCTIONAL_OK")
