# NV oOS MCP Gateway — Comprehensive Implementation Plan

**Date:** 2026-10-05
**Status:** 🔮 PENDING
**Estimated Effort:** 2–4 dev-days (Phases 0–6) + ~1 day directory submissions/review cycles
**Priority:** MEDIUM
**Related:** [055-nvoos-mcp-gateway-proposal.md](./055-nvoos-mcp-gateway-proposal.md), [054-nvoos-mcp-bridge-npx-implementation-plan.md](./054-nvoos-mcp-bridge-npx-implementation-plan.md), [026-media-worker-multi-tenancy-sidecar-proposal.md](./026-media-worker-multi-tenancy-sidecar-proposal.md), `docs/operations/deployment/media-worker-velocity-setup.md`, `addons/media-worker/` (pattern donor)

---

## Executive Summary

Implement `addons/mcp-gateway/` — a media-worker-style Node service that
turns the NV oOS fleet into one public, branded, directory-listable MCP
endpoint at `https://mcp.nvoos.pro/mcp`. The gateway is an **MCP aggregating
proxy** speaking streamable HTTP (MCP 2026-07-28): key-authenticated,
rate-limited, stateless, forwarding `initialize` / `tools/list` /
`tools/call` to upstream NV oOS sites over their existing
`/wp-json/mcp-ai/v1/mcp` endpoints with Fleet Operator `op_` tokens, and
namespacing tools as `<site-slug>.<tool>`.

All design rationale, research, and threat modeling live in the proposal;
this document is the phase-by-phase build, test, deploy, and submit plan.

---

## Phase 0 — Baseline & protocol verification (0.5 day)

- [x] **Upstream contract verified live** (2026-10-05, Docker dev site):
  `GET /wp-json/mcp-ai/v1/mcp` returns discovery JSON
  (`protocolVersion: 2026-07-28`, `transports.streamable_http.default: true`,
  optional SSE) and `POST` answers JSON-RPC (`initialize`, `tools/list`,
  `tools/call`) with bearer credentials. No upstream changes needed.
- [ ] Create a dedicated **gateway operator** per bound site via the Fleet
      Operator admin (mode `read` or `readwrite` per site policy; allowlist =
      the public surface). Store tokens in Velocity env, never in the repo.
- [ ] Record each site's slug → URL → token mapping in the deploy doc (not
      in git).

## Phase 1 — Scaffold the addon (0.5 day)

Mirror `addons/media-worker/` anatomy exactly:

- [x] `addons/mcp-gateway/package.json` — name `design-mcp-gateway` (media-worker naming style), `version: 0.1.0`, `"type": "module"`, `engines.node: ">=22.12.0"`, scripts `start` / `dev` / `test` (`node --test src/**/*.test.js`), dependencies pinned to the same majors as media-worker: `express ^4`, `helmet ^8`, `express-rate-limit ^7`, `cors ^2`.
- [x] `src/index.js` — app bootstrap: helmet, cors (same-origin only for the landing page; MCP route allows configured origins), `TRUST_PROXY=1`, JSON body limit (1 MB), route mounting, `PORT` env, boot log to stdout (HTTP server — stdout logging is safe) with version + node version.
- [x] `src/routes/health.js` — `GET /health` (no auth): `{ status, service: "design-mcp-gateway", version, uptime, sites: { slug: ok|degraded } }` (media-worker §4 precedent). `GET /health/full` requires auth (same split).
- [x] `src/routes/landing.js` — `GET /`: human-readable landing (service name, endpoint URL, auth instructions, example configs, directory links). Plain HTML, no secrets.
- [x] `src/utils/config.js` — parses `GATEWAY_PUBLIC_KEYS` (`key1=slug-a,slug-b;key2=slug-c` or JSON) and `NVOOS_SITE_<slug>_URL` / `NVOOS_SITE_<slug>_TOKEN`; fails boot with a clear error on malformed/missing config (**fail-closed at startup**).
- [x] Secret hygiene is structural, not a masking utility: the request logger never reads `Authorization`/token headers (a deleted `redact.js` proved unneeded — dead code removed), upstream tokens live header-only, and tests assert no `SECRET` ever appears in a response.
- [x] `.gitignore`, `.dockerignore`, `README.md` (usage, env reference, mirror sync note).
- [x] `Dockerfile` — node:22-slim, `npm ci --omit=dev`, `CMD ["npm","start"]` (media-worker pattern; no Chromium/ffmpeg needed here).

## Phase 2 — Auth & rate limiting (0.5 day)

- [x] `src/middleware/auth.js` —
  - Timing-safe comparison (media-worker auth pattern) against
    `GATEWAY_PUBLIC_KEYS` + `GATEWAY_PUBLIC_KEYS_PREVIOUS` (rotation overlap).
  - Resolves key → site bindings; attaches `req.gatewayKeyId` + `req.gatewaySites`.
  - `AUTH_MODE=strict` default: missing/invalid key → structured 401 JSON,
    `WWW-Authenticate: Bearer`.
  - **Never** returns upstream tokens or binding internals in errors.
- [x] `src/middleware/rate-limit.js` — per-key limiter
      (`express-rate-limit` keyGenerator = key id, fallback to IP for the
      landing page), per-route-group budgets (`RATE_LIMIT_*` env, media-worker
      naming), 429 structured JSON with `Retry-After`.
- [x] `src/middleware/log.js` — structured request log (method, path, key id,
      site slugs, status, ms) with redaction; no tool arguments by default.

## Phase 3 — MCP routes (the core, 1 day)

- [x] `src/routes/mcp.js` —
  - `GET /mcp` → public discovery JSON (mounted **before** the auth gate; the
    router itself is POST-only — fix applied after the first test run caught
    discovery 401s).
  - `POST /mcp` → JSON-RPC 2.0 dispatch:
    - `initialize` → gateway's own serverInfo (protocol 2026-07-28).
    - `notifications/*` → forwarded upstream, 202 no content (JSON-RPC
      notification semantics preserved).
    - `tools/list` → parallel fan-out to all sites bound to the key with
      per-site timeout (`UPSTREAM_TIMEOUT_MS`, default 20 s); merge results;
      prefix each tool `name` with `<site-slug>.`; attach `_meta.gateway.site`
      per tool; a failed upstream degrades to `_meta.errors[slug]` instead of
      failing the whole list (graceful degradation).
    - `tools/call` → prefixed names always accepted (stripped); bare names
      accepted only in single-site mode; multi-site unprefixed → structured
      -32602 (threat T4). (Fix applied after the first test run: single-site
      mode initially did not strip prefixed names, which broke round-tripping
      `tools/list` → `tools/call`.)
    - `resources/list`, `prompts/list`, `completion/complete` → forwarded to
      the first bound site — passthrough (documented v1 limitation).
  - Stateless: no sessions, no SSE in v1 (proposal D4).
- [x] `src/utils/upstream.js` — small HTTP client over global `fetch`
      (Node 22): `Authorization: Bearer <op_token>`, timeout via
      `AbortSignal.timeout`, non-2xx → structured `{ ok, status, error }`
      for the degrade path.
- [x] `src/utils/namespacer.js` — pure functions `prefixTool(slug, tool)`,
      `splitToolName(name)` with unit tests (collision-by-construction check).

## Phase 4 — Tests (0.5–1 day)

Colocated `node --test` suites with **fake upstream NV oOS sites** (in-process
Express apps replaying discovery + tools/list + tools/call; no Docker, no
real site — the media-worker test style):

- [x] `src/utils/namespacer.test.js` — prefix/split roundtrip, edge cases
      (tool names with dots, empty slug, duplicate slug config error).
- [x] `src/utils/config.test.js` — env parsing, missing URL/token → boot
      error, `_PREVIOUS` rotation parsing, malformed key map, fail-closed
      strict mode.
- [x] `src/middleware/auth.test.js` — valid key resolves bindings; unknown
      key → 401; rotation window accepts previous key; no token in error
      bodies; runtime backstop (key bound to unconfigured site → 503).
- [x] `src/middleware/rate-limit.test.js` — per-key budget isolation; 429
      shape; `Retry-After` present.
- [x] `src/routes/mcp.test.js` — end-to-end through the app with fake
      upstreams: public discovery; initialize; multi-site `tools/list` merge +
      prefixing; `tools/call` reverse-map + passthrough result; notification
      suppression; single-site passthrough mode; upstream 500 → per-site
      `_meta` degrade; upstream token header verified on fake upstream
      (and never echoed in responses); batch; -32700/-32601/401 paths.
- [x] `src/routes/health.test.js` — public vs authed shape; degraded site
      surfaced.
- [x] **39/39 tests green** (npm test, Node 24 local; CI pins Node 22).
- [x] **Live smoke test**: gateway booted against the docker compose NV oOS
      site (real plugin endpoint) — public discovery, `initialize`,
      namespaced `tools/list` fan-out, `/health` site registry, structured
      logs all verified 2026-10-05.

## Phase 5 — Mirror repo, CI, Docker, Velocity doc (0.5–1 day)

- [x] `.github/workflows/` in `addons/mcp-gateway/` — CI: lint-free gates
      (test → syntax check → `npm audit`) mirroring media-worker's ci.yml.
- [x] Subtree-mirror workflow (media-worker pattern): root
      `.github/workflows/sync-mcp-gateway.yml` — on push to
      `main`/`alpha-working` under `addons/mcp-gateway/**`, `git subtree
      split --prefix=addons/mcp-gateway` → push to
      `nvdigitalsolutions/nvoos-mcp-gateway` branch `main` (maintainer
      creates the repo; token via `MCP_GATEWAY_REPO_TOKEN` secret).
- [x] `docs/operations/deployment/mcp-gateway-velocity-setup.md` — mirrors
      `media-worker-velocity-setup.md`: §1 provision (repo = mirror, Node 22,
      npm, entry `src/index.js`), env table, §2 upstream operator
      credentials, §3 public-key generation + rotation procedure, §4 DNS
      `mcp.nvoos.pro` + TLS, §5 monitoring, §6 directory listing checklist.
- [x] `README.md` final pass: npx-bridge compatibility note
      (`MCP_AI_BASE_URL=https://mcp.nvoos.pro/mcp`), example Zed/Claude
      configs, env reference, namespacing semantics.

## Phase 6 — Deploy, list, monitor (0.5 day + review cycles)

- [ ] Deploy to Velocity: push mirror `main`, set env vars (Phase 0 tokens),
      attach `mcp.nvoos.pro` + TLS, verify:
      `curl https://mcp.nvoos.pro/health` and an authenticated
      `initialize` + `tools/list` roundtrip (run the 054 package's
      `test:e2e` script pointed at the gateway URL).
- [ ] Create a **demo key** (read-only operator allowlist on the demo site;
      document in the landing page and listings).
- [ ] Submissions (in order): **mcpservers.org** (`/submit`, free-form) →
      **mcp.directory** (GitHub repo `nvdigitalsolutions/nvoos-mcp-gateway`)
      → **pulsemcp.com** → **official MCP Registry**
      (registry.modelcontextprotocol.io, after the first two are live).
      Each submission: name, one-line description, endpoint
      `https://mcp.nvoos.pro/mcp`, transport = streamable HTTP, auth = Bearer
      API key (state header), example config, homepage `https://mcp.nvoos.pro/`,
      logo (reuse NV oOS branding).
- [ ] Uptime + alerts on `/health`; watch 401/429 counters during the first
      week of listing traffic.

---

## Validation Strategy

| Layer | Tooling | What it proves |
|-------|---------|----------------|
| Unit | `node --test` colocated suites | Namespacing, config fail-closed, auth, rate limits |
| Integration | `node --test` + fake upstream Express apps | Full MCP proxy contract incl. degrade paths |
| Protocol conformance | Official MCP Inspector (`npx @modelcontextprotocol/inspector`) against `POST /mcp` | Real-client handshake, tools/list, tools/call |
| E2E | 054 package `test:e2e` with `MCP_AI_BASE_URL=https://mcp.nvoos.pro/mcp` | npx bridge → gateway → live site roundtrip |
| Deploy smoke | `curl /health` + authed initialize on Velocity | TLS, env, PM2 boot |
| Security | Red-team pass (unknown key, un-prefixed tool name, oversized body, upstream down) | T1/T4/T6 mitigations hold |

## File Manifest

```
addons/mcp-gateway/                    ← NEW (media-worker anatomy)
├── package.json
├── README.md
├── Dockerfile
├── .dockerignore
├── .gitignore
├── .github/workflows/
│   ├── ci.yml                         ← NEW (lint + test + sync dry-run)
│   └── subtree-mirror.yml             ← NEW (push to nvoos-mcp-gateway)
└── src/
    ├── index.js                       ← NEW
    ├── middleware/
    │   ├── auth.js                    ← NEW
    │   ├── auth.test.js               ← NEW
    │   ├── rate-limit.js              ← NEW
    │   ├── rate-limit.test.js         ← NEW
    │   └── log.js                     ← NEW
    ├── routes/
    │   ├── mcp.js                     ← NEW
    │   ├── mcp.test.js                ← NEW
    │   ├── health.js                  ← NEW
    │   ├── health.test.js             ← NEW
    │   └── landing.js                 ← NEW
    └── utils/
        ├── config.js                  ← NEW
        ├── config.test.js             ← NEW
        ├── upstream.js                ← NEW
        ├── namespacer.js              ← NEW
        ├── namespacer.test.js         ← NEW
        └── redact.js                  ← NEW

docs/operations/deployment/mcp-gateway-velocity-setup.md   ← NEW
docs/project/proposals/055-nvoos-mcp-gateway-proposal.md    ← DONE (sibling)
docs/project/proposals/README.md                            ← UPDATE (register 055)
```

## Rollout

1. Merge PR (gateway + docs) → mirror sync pushes the standalone repo.
2. Velocity deploy with demo site + demo key → verify E2E + Inspector.
3. Submit mcpservers.org + mcp.directory → land listing traffic on the demo
   key first.
4. Post-review: official MCP Registry + pulsemcp; OAuth 2.1 upgrade scoped
   as its own proposal (Phase 6+ of the proposal's roadmap).
