# Hermes Dashboard Fleet Extensions — Implementation Plan

> **Branch:** `add/hermes-dashboard-fleet-extensions`
> **Status:** Phases 0–3 implemented (v0.2.0); deferred items in §9.1
> **Proposal:** [`hermes-dashboard-extensions-plan.md`](hermes-dashboard-extensions-plan.md)
> **Companion (WP-side):** `addons/fleet-operator/`

---

## 1. Purpose & scope

Deliver the Hermes-side companion to the Fleet Operator addon: a Hermes
dashboard extension (`nv-oos-fleet`) that lets an operator register a fleet of
NV oOS WordPress sites, monitor them from one pane of glass, and (in later
phases) operate them — moving viewing/orchestration load off WordPress while
tool execution stays where the data lives.

**In scope (this branch — Phases 0–3 delivered):**

- Repo home `hermes-extensions/` (source of truth, excluded from the WP.org
  build via `.distignore`).
- `nv-oos-fleet` dashboard plugin v0.2.0: site registry CRUD + connection
  tests, cached fleet health/status, per-site overview, logs, async-job + WP
  cron views with a live SSE hub, analytics summary, security posture, tool
  registry browse + gated tool calls, token-usage passthrough, Paper Store
  browse/CRUD, workflow-run monitor, federation mesh view, MCP config
  generation + local apply (with backups), and cross-site cost summary.
- UI slots: `header-right` fleet badge, `chat:bottom` ask-the-fleet,
  `sessions:bottom` fleet costs.
- `nv-oos` dashboard theme YAML.
- Installer script + operator README.
- Functional smoke test with a mocked upstream WP site
  (`hermes-extensions/tests/backend_smoke.py`).
- This implementation plan and the earlier proposal document.

**Deferred (see §9.1):** full chat-session monitor via `/sse`, one-click
config application from the chat widget, credential *issuance* from the
dashboard (stays on the WP side), WP-side `WP_MCP_AI_HEADLESS_ADMIN` constant.

---

## 2. Repo layout (source of truth)

```
hermes-extensions/
├── README.md                      # operator docs: install, verify, security
├── install.sh                     # copies plugin + theme into ~/.hermes
├── .gitignore                     # never commit sites.yaml (real tokens)
└── nv-oos-fleet/
    ├── dashboard/
    │   ├── manifest.json          # tab + entry + css + api declarations
    │   ├── dist/
    │   │   ├── index.js           # IIFE bundle — no build step (ES5-safe)
    │   │   └── style.css          # theme-aware (--color-* vars)
    │   ├── plugin_api.py          # FastAPI backend plugin
    │   └── sites.yaml.example     # registry template (real file is 0600)
    └── theme/
        └── nv-oos.yaml            # dashboard theme (palette/typography/layout)
```

Runtime mapping (per Hermes docs):

| Source | Runtime |
|---|---|
| `hermes-extensions/nv-oos-fleet/` | `~/.hermes/plugins/nv-oos-fleet/` |
| `hermes-extensions/nv-oos-fleet/theme/nv-oos.yaml` | `~/.hermes/dashboard-themes/nv-oos.yaml` |

---

## 3. Phase 0 — component specifications (implemented)

### 3.1 `manifest.json`

```json
{
  "name": "nv-oos-fleet",
  "label": "NV oOS Fleet",
  "description": "Register, monitor, and operate NV oOS WordPress sites from the Hermes dashboard.",
  "icon": "Globe",
  "version": "0.2.0",
  "tab": { "path": "/nv-oos-fleet", "position": "end" },
  "slots": ["header-right", "chat:bottom", "sessions:bottom"],
  "entry": "dist/index.js",
  "css": "dist/style.css",
  "api": "plugin_api.py"
}
```

Decisions: one nav tab with internal sub-views (12 views) instead of many
manifest tabs, so later additions never pollute the nav. `icon` is `Globe`
(in the documented Lucide map). `slots` is documentation only; real
registration happens in the bundle.

### 3.2 `plugin_api.py` — backend plugin

Exports module-level `router = APIRouter()`. Mounted by the dashboard at
`/api/plugins/nv-oos-fleet/` behind the dashboard auth gate. Runs in-process,
so it may import Hermes internals later; Phase 0 only uses stdlib + `httpx` +
`yaml` + FastAPI/pydantic.

#### Endpoint contract

| Method | Path | Purpose | Returns |
|---|---|---|---|
| GET | `/meta` | plugin self-check (install verification) | `{"ok": true, "sites": n, "registry_file": "sites.yaml"}` |
| GET | `/sites` | list registry (public shape, no tokens) | `{"sites": [SitePublic…]}` |
| POST | `/sites` | add site | `201` + `SitePublic` |
| GET | `/sites/{id}` | one site | `SitePublic` |
| PUT | `/sites/{id}` | partial update | `SitePublic` |
| DELETE | `/sites/{id}` | remove site | `{"ok": true}` |
| POST | `/sites/{id}/test` | live connection test (uncached) | `SiteCheck` |
| GET | `/sites/{id}/mcp-config` | config.yaml fragment for Hermes' MCP client | `{"site_id", "env_var", "yaml"}` |
| GET | `/fleet/health` | concurrent health fan-out, 30 s TTL cache | `FleetHealth` |
| GET | `/fleet/status` | badge payload derived from cached health | `FleetStatus` |

#### Data shapes

```jsonc
// SitePublic — never contains the token
{
  "id": "site-3f9a2b1c4d",
  "label": "Victory Store",
  "url": "https://victory.nvdigital.solutions",
  "write": false,
  "allow_insecure": false,
  "notes": "",
  "token_hint": "op_*****.SECRET",      // redaction rule below
  "created_at": "2026-09-03T…Z",
  "updated_at": "2026-09-03T…Z"
}

// SiteCheck
{
  "id": "site-…", "label": "…",
  "ok": true, "status_code": 200,
  "latency_ms": 312, "assistants": 4,
  "error": null                        // null | "HTTP 401" | "timeout" | exception class
}

// FleetHealth
{
  "checked_at": "2026-09-03T…Z",
  "total": 3, "ok": 2, "degraded": 1,
  "sites": [SiteCheck…]
}
```

#### `sites.yaml` registry schema (0600, beside `plugin_api.py`)

```yaml
version: 1
sites:
  - id: site-3f9a2b1c4d
    label: Victory Store
    url: https://victory.nvdigital.solutions
    token: op_xxxxx.SECRET
    write: false
    allow_insecure: false
    notes: ""
    created_at: "2026-09-03T…Z"
    updated_at: "2026-09-03T…Z"
```

#### Behaviour rules

1. **Redaction** — tokens are never returned by any endpoint. `token_hint`
   keeps the credential's prefix (4 chars) and suffix (8 chars), e.g.
   `op_*****.SECRET`; short tokens collapse to `********`.
2. **Validation** — `url` must be `https://` unless `allow_insecure` is set
   (then `http://` is permitted, and TLS verification is skipped for that
   site only). Trailing slashes stripped. Empty tokens rejected (422).
3. **Concurrency** — module-level `asyncio.Lock` guards registry writes;
   in-memory dict is the source of truth, persisted atomically
   (tmp file + `os.replace`) with `chmod 0600` (best-effort; no-op on
   Windows filesystems). Registry survives a corrupt `sites.yaml` by
   starting empty and logging the error.
4. **Health check** — `GET {url}/wp-json/mcp-ai/v1/assistants` with
   `Authorization: Bearer {token}`, connect timeout 5 s / read timeout 10 s.
   TLS verification is selected via pooled clients keyed on verify mode
   (httpx 0.28+ only accepts `verify` at client level). Exceptions are
   caught and classified; the token never appears in error strings or logs.
5. **Caching** — fleet endpoints share one 30 s TTL in-memory cache keyed on
   `fleet-health`; `/sites/{id}/test` bypasses it. Any registry mutation
   invalidates the cache.
6. **pydantic compatibility** — models use plain fields only; a
   `_body_dict()` helper prefers `model_dump(exclude_unset=True)` (v2) and
   falls back to `dict(exclude_unset=True)` (v1). No validator decorators,
   so the file loads under either major version.
7. **Secrets hygiene** — the MCP-config endpoint emits `${env:SITE_XXX_TOKEN}`
   substitution (Hermes `.env` convention) and never echoes the real token.

### 3.3 `dist/index.js` — UI bundle (IIFE, no build)

- Guards on `window.__HERMES_PLUGIN_SDK__` / `window.__HERMES_PLUGINS__`
  existing (older dashboards degrade to a console warning, no crash).
- React and hooks come exclusively from `SDK.React` / `SDK.hooks`.
- shadcn components come from `SDK.components` with `"div"`/`"span"`/
  `"button"`/`"input"` fallbacks so missing primitives never break render.
- `request()` helper: uses `SDK.fetchJSON` for GETs; raw same-origin `fetch`
  (cookies, default credentials) for mutations, since the documented
  `fetchJSON(path)` signature only covers GET. This is the one assumption to
  re-verify against a live dashboard (see §8 validation).
- `FleetApp` (registered as `nv-oos-fleet`): internal Sites/Fleet sub-nav,
  loads `/sites` once on mount.
- `SitesView`: add form (label, url, token, write checkbox), per-site card
  with `token_hint`, Test button (per-site in-flight state, latency/status/
  error result), Delete with `window.confirm`.
- `FleetView`: polls `/fleet/health` every 30 s (cleanup on unmount), grid of
  site cards with ok/degraded badge, latency, assistant count, error text.
- `FleetBadge` (registered into `header-right`): polls `/fleet/status` every
  30 s; renders nothing when zero sites; shows
  `NV oOS: 3 sites · 1 degraded`.
- All polling intervals are cleared on unmount; all error states render
  inline (never throw during render).

### 3.4 `dist/style.css`

Small, theme-aware stylesheet: every color/border/radius references
`--color-*` / `--radius` / `--spacing-mul` with fallback literals, so the
plugin reskins with any active theme. Class names are `nvoos-` prefixed to
avoid collisions with the dashboard.

### 3.5 `theme/nv-oos.yaml`

NV oOS brand theme: deep-navy background, signal-cyan midground, amber glow,
system font stacks (no `fontUrl` — zero external requests), 0.5 rem radius,
comfortable density, subtle cyan inner ring on cards, `primary`/`ring`
overrides. `layoutVariant: standard` (cockpit rail is a later phase decision).

### 3.6 `install.sh` + `README.md` + `.gitignore`

- `install.sh`: `set -euo pipefail`, honours `HERMES_HOME` (default
  `$HOME/.hermes`), idempotent (removes previous plugin copy), copies plugin
  tree + theme, prints verification commands (rescan endpoint + restart
  reminder for backend routes).
- `README.md`: layout, install, verify (`curl /meta`), security notes
  (tokens in `sites.yaml` 0600, dashboard auth gate, don't expose with
  `--host 0.0.0.0`), relationship to `addons/fleet-operator`.
- `.gitignore`: ignores `sites.yaml` everywhere under the tree (real
  credentials), keeps `sites.yaml.example`.

### 3.7 `.distignore`

Add `hermes-extensions` so the WP.org SVN build never ships Hermes-side code.

---

## 4. Phase 1 — read-only fleet monitoring (implemented)

Delivered as backend routes + UI sub-views; all reads against the existing
WP REST/MCP surface (no WP changes). Route contracts live in
`plugin_api.py`'s docstring and the smoke test's mock handler.

| Sub-view | Backend routes | WP endpoint | Cache TTL |
|---|---|---|---|
| Overview | `GET /fleet/overview?site=` | `/health`, `/status`, RPC `get_site_summary`, `get_update_status` | 60 s |
| Logs | `GET /logs?site=&limit=` | RPC `get_system_logs_validated` | 15 s |
| Jobs | `GET /jobs?site=`, `GET /jobs/stream?site=` (SSE hub), `POST /jobs/{site}/cancel|retry`, `POST /jobs/{site}/wp-cron/delete` | `/cron-status`, `/cron-status/stream`, `/cron-status/{id}/cancel|retry`, RPC `list_cron_jobs` / `delete_cron_job` | 5 s snapshot; stream passthrough |
| Analytics | `GET /analytics/summary?site=` | `/cost/dashboard-summary`, `/cost/total`, `/cost/by-provider` | 300 s |
| Security | `GET /security/posture?site=&refresh=` | `/security/posture` | 60 s |

Implemented rules: per-source degradation (a failing sub-source becomes an
`errors.<key>` entry, never a 5xx), one upstream SSE connection per site via
the in-process hub with bounded subscriber queues and clean task teardown,
`cancel`/`retry`/`wp-cron delete` gated on `write: true`.

---

## 5. Phase 2 — control plane (implemented, with deviations)

| Sub-view | Backend routes | Gate |
|---|---|---|
| Tools | `GET /tools?site=` (RPC `tools/list`), `POST /tools/call` | call gated on `write: true` + site-side allowlist |
| Tokens | `GET /tokens/usage?site=&user_id=` (read-only passthrough) | — |
| Paper Store | `GET /paper-store`, `/paper-store/records`, `/paper-store/search`; `POST` / `DELETE /paper-store/records` | writes gated |
| Workflows | `GET /workflows/runs`, `/workflows/runs/{site}/{id}`, `.../events` | read-only |
| Mesh | `GET /mesh/peers?site=`; `POST /mesh/peers/reverify|report` | writes gated |

Deviations from the forward spec:
- **Tokens**: the base REST surface has no credential-issuance route —
  generating/revoking `op_*` credentials stays on the WordPress side
  (fleet-operator admin / `wp mcp-ai operator`). The tab is a read-only
  usage passthrough and says so in the UI.
- **Workflows**: upstream exposes list/single/events only; dispatch/cancel
  routes were not found, so no write paths were invented.
- **Bulk token rotation** and Paper Store import are available through the
  generic `tools/call` when the operator's allowlist includes those tools.

---

## 6. Phase 3 — agent ops (implemented, with deviations)

| Feature | Delivered as |
|---|---|
| MCP bridge | `GET /sites/{id}/mcp-config` fragment; `POST /sites/{id}/mcp-config/apply` writes `~/.hermes/config.yaml` (merged `mcp_servers` entry, backup created) + `.env` line (0600) |
| Live jobs monitor | `GET /jobs/stream` SSE hub (one upstream connection per site, fan-out to browser tabs) |
| Ask-the-fleet | `chat:bottom` slot with per-site MCP-config copy button |
| Cost summary | `sessions:bottom` slot backed by `GET /costs/summary` (300 s cache) |

Deviations:
- Full chat-session monitoring via the public `/sse` directory stream is
  deferred (§9.1) — the jobs stream covers the standing-load case.
- The chat widget copies the config; one-click apply from that widget is
  deferred (the Sites tab already has an "Apply MCP config" button).

---

## 7. Security checklist (applies to every phase)

- [ ] Tokens only in `sites.yaml` (0600); never in JS, logs, or responses.
- [ ] `token_hint` redaction on every endpoint.
- [ ] https-only unless per-site `allow_insecure` opt-in.
- [ ] Write paths gated by per-site `write: true` + scoped operator tokens.
- [ ] Dashboard not exposed with `--host 0.0.0.0` while the plugin is
      installed (official Hermes warning).
- [ ] Upstream errors never include request headers/tokens.
- [ ] Registry file corruption degrades to empty registry + logged error.

---

## 8. Testing & validation

### Automated (runs on this branch, no Hermes install needed)

```bash
python3 -m py_compile hermes-extensions/nv-oos-fleet/dashboard/plugin_api.py
node --check hermes-extensions/nv-oos-fleet/dashboard/dist/index.js
python3 -c "import yaml; yaml.safe_load(open('hermes-extensions/nv-oos-fleet/theme/nv-oos.yaml'))"
bash -n hermes-extensions/install.sh
```

Plus a functional backend test (`hermes-extensions/tests/backend_smoke.py`)
that runs every route in-process against a mocked upstream WP site
(httpx.MockTransport injected into the plugin's client pools): registry CRUD,
validation, redaction, caching, fleet health/status/overview, logs, jobs
(incl. write gating), analytics, posture, tools list + gated call, token
usage, paper-store CRUD, workflow runs, mesh peers, costs summary, the SSE
hub (fan-out + teardown), and mcp-config/apply against a temp HERMES_HOME.
It refuses to run when a live `sites.yaml` exists:

```bash
cd hermes-extensions
python3 -m venv .venv && .venv/bin/python -m pip install fastapi httpx pyyaml
.venv/bin/python tests/backend_smoke.py
```

Test-harness note: the SSE hub is exercised directly through
`StreamingResponse.body_iterator` because this venv's starlette/httpx
ASGITransport cannot deliver streaming responses at all (a minimal FastAPI
streaming app hangs identically — a harness limitation, not plugin code).
The suite already caught two real bugs: httpx 0.28's removal of per-request
`verify` (fixed via pooled per-verify clients) and a Starlette TestClient
`delete(json=...)` incompatibility. Run it on every backend change.

### Manual smoke test (requires a Hermes dashboard + one Docker WP site)

1. `bash hermes-extensions/install.sh`
2. Restart `hermes dashboard` (backend routes mount at startup).
3. `curl http://127.0.0.1:9119/api/dashboard/plugins` → `nv-oos-fleet`
   discovered; `curl …/api/plugins/nv-oos-fleet/meta` → `{"ok": true}`.
4. UI: add the Docker site (credential from `wp mcp-ai operator create` or an
   assistant `cred_` token), Test → green with latency; Fleet tab shows the
   card; header badge appears; theme switcher shows **NV oOS**; Logs, Jobs
   (live stream), Analytics, Security, Tools, Paper Store, Workflows, Mesh
   tabs render for the site; write-gated buttons enable only when the site
   entry has `write: true`.
5. Negative tests: bad URL (rejected), wrong token (Test → 401, degraded
   badge), delete + confirm, restart persistence of `sites.yaml`,
   mcp-config/apply writes + backups.

### Load validation (Phase 1+ acceptance)

- WP Query Monitor: aggregate queries per view vs. per TTL window.
- PHP-FPM: one upstream SSE per site regardless of viewer count.

---

## 9. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `SDK.fetchJSON` signature differs for POST | mutations use raw same-origin `fetch`; verify in smoke test §8, adapt trivially |
| pydantic v1 vs v2 in the Hermes venv | plain-field models + `_body_dict()` compat helper |
| Backend routes mount once at startup | README calls out restart; `/meta` endpoint detects stale installs |
| `sites.yaml` token leak via repo | `.gitignore` + `sites.yaml.example` + 0600 + redaction |
| Hermes plugin API is young | only documented surfaces used; defensive SDK guards throughout |
| Upstream REST gaps (no credential-issuance or workflow-cancel routes) | endpoints degrade per-source; issuance stays on the WP side; no invented write routes |
| SSE delivery depends on the dashboard's ASGI streaming path | hub logic unit-tested directly; bounded queues + teardown prevent leaks |

### 9.1 Deferred items (explicit)

- Chat-session monitoring via the public `/sse` directory stream.
- One-click "apply MCP config" from the `chat:bottom` widget (Sites tab
  already has the apply button).
- Credential issuance/revocation from the dashboard (WP-side only today).
- WP-side `WP_MCP_AI_HEADLESS_ADMIN` constant to unregister heavy admin
  screens on fully-managed sites (separate PR against the PHP plugin).
- Audit rows into `hermes_state` for proxied writes (Hermes-internals
  import, needs live-dashboard verification first).

---

## 10. Acceptance criteria (this branch)

- [x] New top-level `hermes-extensions/` tree with the Phase 0–3 files.
- [x] `.distignore` excludes the tree from WP.org builds.
- [x] `plugin_api.py` compiles; `index.js` parses; YAML/JSON files load;
      `install.sh` passes `bash -n`.
- [x] Functional smoke test passes: all Phase 0–3 endpoints, write gating,
      redaction, SSE hub teardown, mcp-config/apply with backups.
- [x] Proposal + implementation plan docs cross-link each other and
      `addons/fleet-operator/`.
- [ ] (needs live Hermes) §8 manual smoke test passes end to end.

---

## 11. Rollout & rollback

- Rollout: merge branch → clone/update repo → `install.sh` → dashboard
  restart → theme pick.
- Rollback: `rm -rf ~/.hermes/plugins/nv-oos-fleet ~/.hermes/dashboard-themes/nv-oos.yaml`;
  WP side untouched by design (no PHP changes in any phase of this plan).

---

## 12. References

- Hermes — Extending the Dashboard:
  <https://hermes-agent.nousresearch.com/docs/user-guide/features/extending-the-dashboard>
- Hermes reference plugins:
  <https://github.com/NousResearch/hermes-example-plugins>
- Fleet Operator addon (WP-side): `addons/fleet-operator/README.md`
- Fleet Operator proposal:
  `docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md`
- NV oOS REST surface: `.context/rest-api.md`
