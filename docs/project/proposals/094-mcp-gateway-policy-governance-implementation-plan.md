# Proposal 094 — MCP Gateway Policy & Governance Layer (Implementation Plan)

**Date:** 2026-10-28
**Status:** ✅ IMPLEMENTED — all six phases shipped 2026-10-28 on `feat/mcp-gateway-policy-governance` (PR #7269 → `alpha-working`). Phases 0–5 landed in one PR (they were smaller than estimated and share the evaluation pipeline); Phase 6 = this docs/deploy close-out.
**Related:** [094-mcp-gateway-policy-governance-proposal.md](./094-mcp-gateway-policy-governance-proposal.md), [055-nvoos-mcp-gateway-implementation-plan.md](./055-nvoos-mcp-gateway-implementation-plan.md)

---

## Executive Summary

Six phases, ~5–7 dev-days. Phases 0–4 extend `addons/mcp-gateway` (Node,
zero new dependencies — the quota buckets, rule evaluator, trace stamping,
and HMAC sink are all stdlib-implementable); Phase 5 adds the Complete
Console Gateway page; Phase 6 is docs, mirror, and deploy. Every phase
ships green `node --test` suites with fake upstreams, following the
existing gateway's conventions (`src/**/*.test.js`, env-driven, stateless).
The platform side (WordPress) is **not modified** — sites already enforce
the deep layer; the gateway adds the fleet layer on top.

Phase dependency note: 0 and 1 are independent and can land in one PR;
2 depends on 1 (shared evaluation pipeline); 3 depends on 0 (trace IDs);
4 depends on 1+2; 5 depends on 3+4's catalog endpoint.

---

## Phase 0 — Trace propagation baseline (0.5 day)

**Goal:** one W3C `traceparent` across gateway → site hops, `traceId` on
every gateway log line.

1. `src/trace/context.js` — `startOrInherit(req)`: parse inbound
   `traceparent` (W3C regex, version `00`, 128-bit trace id, span id,
   flags); else generate (crypto.randomBytes — stdlib). No PII in
   `tracestate` (W3C requirement).
2. `src/middleware/log.js` — add `traceId` (hex) to the existing structured
   JSON line; keep `id` (X-Request-Id) as the log-correlation key.
3. `src/routes/mcp.js` — stamp `traceparent` on every upstream fetch
   (init, tools/list, tools/call, notifications). Sites already tolerate
   unknown headers; the site-side `X-MCP-Bridge-Name` precedent confirms
   header pass-through safety.
4. Tests: inherits valid inbound traceparent; generates on missing;
   malformed inbound → regenerate (never echo garbage); upstream request
   carries the same trace id; log line contains `traceId`.

## Phase 1 — Per-tool quota engine (1 day)

**Goal:** RFC 9558-compliant per-tool quotas at the fleet layer.

1. `src/quota/token-bucket.js` — stdlib token bucket `{ capacity, refill }`
   per key `(keyId, site, tool)`, in-memory Map with lazy expiry (no timers
   — expire on access, gateway stays event-loop-clean).
2. `src/quota/config.js` — parse `TOOL_QUOTAS` compact form:
   `TOOL_QUOTAS=delete_post:5/1h:2,woo_*:60/1m:*` (glob tool match,
   `rate/window:burst`, `*` = unlimited burst; window units s/m/h).
   Plus `POLICY_FILE` alternative (JSON: `{ "quotas": [...] }`).
3. `src/middleware/rate-limit.js` — after existing per-key group budgets,
   resolve the effective tool quota (most-specific glob wins), consume a
   token; on breach return 429 with `Retry-After` and RFC 9558 headers
   (`RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`,
   `quota-policy`) — never silently drop.
4. Degradation: unconfigured tool → group budget only (today's behavior);
   upstream 429 passthrough unchanged (sites keep their own ceilings).
5. Tests: refill math, burst, glob specificity, per-key isolation (one
   noisy key can't exhaust another's tool quota), 429 header set, unknown
   tool falls back, expiry-on-access.

## Phase 2 — Policy rules engine (1 day)

**Goal:** declarative allow/deny/require_approval rules: tool, site, key,
time window, geo country.

1. `src/policy/evaluator.js` — input shape (Rego-mirrored, D1):
   `{ tool, site, key, time, country }`; condition grammar:
   - `tool.match` (glob), `site` (slug list), `key` (key-id list),
   - `time.window` (`{ tz: "Europe/London", days: [1..7],
     start: "09:00", end: "17:00" }` — stdlib Intl for tz),
   - `geo.country` (`[ "GB", "US" ]`).
   Effects: `allow | deny | require_approval`. First-match-wins, default
   deny when any rule set exists, allow-all when `GATEWAY_POLICY` unset
   (back-compat).
2. `src/policy/geo.js` — country resolution: `CF-IPCountry` header first
   (trusted only behind the configured proxy — existing `TRUST_PROXY`
   gate), else static `GEO_MAP` (JSON file, optional). No IP storage.
3. Wire into `src/routes/mcp.js`:
   - `tools/list` — catalog filtering: drop denied tools for that key
     (keeps them out of model context; agentgateway best practice).
   - `tools/call` — evaluate before dispatch; `deny` → JSON-RPC error
     `-32001` + policy id; `require_approval` → 428 + preview payload
     (DestructiveOpsGate precedent shape).
4. Config: `GATEWAY_POLICY` (JSON string) or `POLICY_FILE`; boot fails
   closed on parse error (existing strict-mode pattern).
5. Tests: first-match order, time-window boundaries incl. tz shift, geo
   header trust (spoofed header without TRUST_PROXY ignored), deny filtering
   from tools/list, 428 preview shape, malformed policy → boot refusal.

## Phase 3 — Fleet audit sink + trace join (0.5–1 day)

**Goal:** gateway events flow to one place, joinable by `traceId`.

1. `src/audit/sink.js` — `AUDIT_SINK_URL` + `AUDIT_SINK_SECRET`: POST
   `{ ts, traceId, key, sites, tool, effect, quota, status, ms }` with
   `sha256=` HMAC signature headers (OutboundWebhook pattern); fire-and-
   forget with stdlib fetch, exponential backoff ×3, drop silently after
   (gateway never blocks on audit).
2. Emit events: `deny`, `require_approval`, `quota_exceeded`, `call_ok`
   (tool name only; arguments excluded unless `LOG_TOOL_ARGS=1`, which
   still credential-redacts).
3. Sink targets: any HTTPS receiver; reference consumer = a Complete
   Console-backed collector route later (Phase 5) or a customer webhook.
4. Tests: HMAC verified by fake sink, retry ×3 then drop, arg redaction,
   no sink configured → logs only.

## Phase 4 — Policy inspector (dry-run + simulation + shadow) (1 day)

**Goal:** test a policy before enforcing it (HarrisonCN policy-sim
pattern, adapted).

1. `src/admin/router.js` — operator-token-gated `/admin/*` surface
   (`op_` token, timing-safe compare; 401 otherwise).
2. `GET /admin/policy` — current effective policy (redacted secrets) +
   quota table snapshot.
3. `POST /admin/policy-sim` — body: candidate policy JSON; replay against
   the in-memory call ring buffer (Phase 1's quota keys + last N recorded
   calls, N=200, args redacted — toolkit audit ring precedent). Response:
   per-call `{ tool, site, decision, quota }` + summary diff vs current
   policy. Never forwards to sites.
4. `dryRun: true` in `tools/call` — full quota + policy evaluation,
   decision envelope returned (`{ dryRun: true, would_execute, policy_hits,
   quota_remaining }`), no upstream dispatch.
5. Shadow policies — `SHADOW_POLICY` env: evaluate + log/emit events, never
   enforce; `/admin/policy-sim` can diff shadow vs live.
6. Tests: operator auth gate, replay correctness, redaction, dryRun never
   reaches fake upstream, shadow logs but allows.

## Phase 5 — Catalog & Complete Console Gateway page (1 day)

**Goal:** the internal create → test → publish console.

1. `src/catalog/index.js` — `GET /admin/catalog` (operator auth):
   aggregates gateway `server.json` + per-bound-site descriptors (health
   endpoint + toolkit-server descriptor fields already served per site:
   name, version, limits, surfaces) into one manifest array with lifecycle
   state from config (`SITE_LIFECYCLE=slug:draft|published|retired`,
   default `published`).
2. `examples/nvoos-complete-velocity` — new `#/gateway` route +
   `GatewayPage.tsx`:
   - Catalog table (site, tools count, health, lifecycle badge, version).
   - Quotas/policy read-only view (via `/admin/policy` proxy with an
     operator token stored in the site's vault — no secrets in the SPA).
   - Audit join: per-site audit REST (existing `mcp-ai/v1/security/events`
     and slash-command audit) queried via the BFF, joined on `traceId` with
     a "gateway view" toggle.
   - Test Connection reuse (existing spa-v2 component).
3. `mcp-publisher` doc note: publishing a bound site's toolkit surface
   mirrors the gateway's existing server.json flow.
4. Tests: vitest for the page (host conventions), node --test for
   `/admin/catalog` aggregation + lifecycle defaults.

## Phase 6 — Docs, mirror, deploy, close the loop (0.5–1 day)

1. `addons/mcp-gateway/README.md` — new env tables (`TOOL_QUOTAS`,
   `GATEWAY_POLICY`, `POLICY_FILE`, `AUDIT_SINK_URL/SECRET`, `GEO_MAP`,
   `SHADOW_POLICY`, `SITE_LIFECYCLE`), policy reference section, dryRun
   and policy-sim examples.
2. `docs/operations/deployment/mcp-gateway-velocity-setup.md` — new env
   entries + policy change control (redeploy = production policy change).
3. `server.json` — bump version, add `_meta` capability notes.
4. Mirror sync (`sync-mcp-gateway.yml`), Velocity deploy, registry
   re-publish via `mcp-publisher`.
5. Update `docs/nv-oos-vs-wso2-mcp-gateway-comparison.html` — mark the
   five borrow items closed/dated.

---

## Validation Strategy

- **Per phase:** `npm test` in `addons/mcp-gateway` (node --test, fake
  upstreams — existing convention), plus `npm run smoke` full-chain where
  available.
- **Standards checks:** 429 + RFC 9558 headers asserted verbatim in quota
  tests; W3C regex asserted in trace tests; HMAC signature verified by the
  fake sink.
- **Console:** `npm run typecheck && npm test` in
  `examples/nvoos-complete-velocity` (vitest host conventions).
- **Manual smoke (deploy):** bind two docker sites to a local gateway,
  apply a deny/geo/time policy, run `npx nvoos-mcp-bridge` against it —
  assert tools/list filtering, dryRun envelope, policy-sim diff, and the
  audit sink event.
- **Non-regression:** the existing 33 gateway tests must stay green
  untouched (no behaviour change when new env vars are unset).

## File Manifest

Shipped layout (as-implemented — catalog lives in the admin router, quotas
are enforced from the routes layer via `quota/check.js`; there is no
separate `src/catalog/` module and `middleware/rate-limit.js` was left
untouched, keeping group-budget behavior byte-identical when the new env
vars are unset):

```
addons/mcp-gateway/
  src/trace/context.js + context.test.js
  src/quota/token-bucket.js, config.js, check.js + *.test.js
  src/policy/evaluator.js, geo.js + *.test.js
  src/audit/sink.js + sink.test.js
  src/admin/router.js (GET /admin/policy, POST /admin/policy-sim, GET /admin/catalog),
            recorder.js + router.test.js
  src/routes/mcp.js          (modified: trace, quota, policy, dryRun)
  src/routes/mcp-dryrun.test.js
  src/middleware/log.js      (modified: traceId)
  src/utils/config.js        (modified: new env surface, fail-closed parsing)
  src/utils/upstream.js      (modified: traceparent stamping)
  src/index.js               (modified: compose + admin router mount)
  package.json / package-lock.json / server.json   (0.2.0 + _meta capabilities)
  CHANGELOG.md               (0.2.0 section)
  README.md                  (modified: env tables + policy reference)
examples/nvoos-complete-velocity/
  src/console/GatewayPage.tsx (catalog + policy view over /admin/catalog + /admin/policy)
  src/console/__tests__/gateway-page.test.tsx (vitest, 3 tests)
  src/console/nav-features.tsx (added /gateway nav item)
  src/console/ConsoleShell.tsx (added lazy import + route)
  src/shell.css                (appended .nvoos-gateway-* styles)
docs/
  operations/deployment/mcp-gateway-velocity-setup.md (modified: §8 governance)
  nv-oos-vs-wso2-mcp-gateway-comparison.html           (borrow list → shipped)
  project/proposals/094-*.md                           (this pair)
```

## Rollout

Executed as a single PR (Phases 0–5 together, Phase 6 as this close-out):

1. ✅ PR: Phases 0–5 (trace + quota + policy + audit sink + inspector +
   console Gateway page) → merge → mirror sync → Velocity.
2. ✅ Phase 6: docs (deployment guide §8, comparison borrow-list closure),
   `server.json` 0.2.0 + `_meta` capabilities.
3. ⏳ Registry re-publish via `mcp-publisher` — last, after the merge
   lands on the mirror (never before the PR is merged).
   Each deploy is additive (unset env = current behavior), so the fleet
   can adopt policy incrementally, one bound site at a time.
