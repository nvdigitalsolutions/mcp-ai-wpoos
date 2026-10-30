# Proposal 094 — MCP Gateway Policy & Governance Layer

**Date:** 2026-10-28
**Status:** ✅ IMPLEMENTED — shipped 2026-10-28 on `feat/mcp-gateway-policy-governance` (PR #7269 → `alpha-working`)
**Estimated Effort:** 5–7 dev-days (Phases 0–6) + ~1 day Velocity deploy & registry updates
**Priority:** MEDIUM-HIGH
**Related:** [055-nvoos-mcp-gateway-proposal.md](./055-nvoos-mcp-gateway-proposal.md),
[055-nvoos-mcp-gateway-implementation-plan.md](./055-nvoos-mcp-gateway-implementation-plan.md),
[083-nvoos-complete-console-app-proposal.md](./083-nvoos-complete-console-app-proposal.md),
`addons/mcp-gateway/`, `examples/nvoos-complete-velocity/`,
`docs/nv-oos-vs-wso2-mcp-gateway-comparison.html` (the gap analysis this closes)

---

## Executive Summary

The WSO2 comparison (2026-10-28) audited the NV oOS gateway stack against
WSO2's MCP Gateway and verified **five real gaps** — everything else either
already exists in-platform (per-tool quotas, correlation IDs, destructive-op
previews, registry publication) or is out of scope (IAM products). This
proposal closes the five verified gaps by extending `addons/mcp-gateway`
into a **policy & governance layer**:

1. **Per-tool quotas at the fleet gateway** — today the gateway has only
   per-key group budgets; per-tool quotas exist per-site but not fleet-wide.
2. **Policy rules engine** — time-of-day, geo, and declarative conditions.
   (Per-session persona is already covered by capability boundaries.)
3. **Fleet-wide audit aggregation** — correlation IDs exist at both layers;
   what's missing is one trace-correlated view across the fleet.
4. **Policy inspector** — a dry-run/simulation mode that shows which quotas
   and rules a tool call would hit, without executing it.
5. **Catalog & lifecycle UI** — publish/version machinery exists
   (`server.json` + `mcp-publisher`); missing is the internal
   create → test → publish console.

The gateway stays a stateless Node service; policy is code (env/Git-backed,
fail-closed), audit stays structured and standards-aligned (W3C Trace
Context), and the only UI surface is a new Gateway page in the Complete
Console.

---

## Problem Statement

**Current state** (verified against the codebase, 2026-10-28):

1. `addons/mcp-gateway/src/middleware/rate-limit.js` enforces **per-key
   group budgets only** (`RATE_LIMIT_GLOBAL/_MCP/_HEALTH`). A fleet operator
   cannot say "`delete_post` may run 5×/hour across every bound site".
2. There is no rules layer: no time-window, geo, or declarative tool
   conditions at the gateway. (In-platform: capability gates, destructive-ops
   428 preview, capability boundaries, nefarious-usage auto-shutdown — all
   per-site, none fleet-scoped.)
3. Audit correlation is fragmented: the gateway emits `X-Request-Id`
   structured logs; sites emit `correlation_id` (slash-command audit table)
   and OTEL `trace_id` (agent executor) — but nothing propagates one trace
   identifier across the hop, and there is no fleet-level view.
4. There is no way to test a policy before enforcing it. (In-platform
   precedent: DestructiveOpsGate 428 + preview payload; workflow rule
   simulation; OOS shadow runner. Nothing equivalent at the gateway.)
5. The public gateway is registry-published and versioned
   (`server.json`, `mcp-publisher`), and per-site toolkit servers carry
   descriptors — but there is no internal catalog UI showing the fleet
   surface and lifecycle (draft → test → published → retired).

**Goal state.** The gateway enforces **quotas per tool** and **rules per
request** from declarative config; propagates a **W3C traceparent** through
every proxied call; offers a **policy-sim endpoint** and a **dryRun** mode;
and the Complete Console gains a **Gateway page** (catalog + lifecycle +
fleet audit join). All of it env/Git-configured, stateless, and tested the
same way the existing gateway is (`node --test`, fake upstreams).

---

## Goals & Non-Goals

### Goals

1. **Quota engine (fleet)** — per-tool token buckets scoped
   `(public-key × site × tool)`, RFC 9558 `RateLimit-*` response headers,
   graceful degradation per the existing `_meta.gateway.errors` pattern.
2. **Rules engine** — a small declarative policy (JSON/YAML env or file)
   with first-match-wins semantics and conditions: tool match (glob),
   site, key, time window (IANA tz), geo country (header-derived), and
   effects `allow | deny | require_approval`.
3. **Trace propagation** — gateway starts or inherits a W3C `traceparent`,
   stamps it on upstream requests and every log line, and passes it through
   (sites keep their own `trace_id`/`correlation_id`).
4. **Fleet audit** — an optional HMAC-signed `AUDIT_SINK_URL` webhook
   (OutboundWebhook precedent) plus a Complete Console Gateway page that
   joins gateway logs with per-site audit trails on the trace ID.
5. **Policy inspector** — `POST /admin/policy-sim` (operator auth) that
   replays a candidate policy against the recorded call ring buffer, plus
   `dryRun: true` on `tools/call` returning the decision envelope without
   execution, plus shadow-policy mode (evaluate + log, never enforce).
6. **Catalog & lifecycle** — `GET /catalog` (operator auth) aggregating
   `server.json`-shaped manifests for the gateway and bound sites with
   lifecycle states; the Console Gateway page renders it.

### Non-Goals

- **No OPA/Rego runtime in v1** — the rule DSL is deliberately tiny and
  OPA-compatible by design (migration path documented, see Research).
- **No geo IP database in the gateway** — country comes from the edge
  (`CF-IPCountry` when behind Cloudflare, otherwise a configured static
  map); no IP is stored beyond evaluation.
- **No audit storage in the gateway** — it stays stateless; sinks and the
  Console page aggregate.
- **No per-tool policy on the site-management gateway**
  (`addons/mcp-wordpress-gateway`) — it already has `MCP_TOOLS_ALLOW/DENY`;
  it only consumes the new trace headers where useful.
- **No changes to in-platform enforcement** — sites remain the deep
  enforcement layer; the gateway adds the fleet layer on top (it can only
  tighten, never weaken — the delegate model).

---

## Research Findings (industry standards)

**Policy-as-code.** The industry consensus for MCP tool governance is
policy-as-code decoupled from application code: OPA/Rego is the
most-referenced engine (OPA MCP server, Kiro + AgentCore's Rego interceptor
pattern, permit.io's agent-authorization guidance), with AWS Cedar as the
second mainstream option and lightweight rule DSLs (agentgateway's
`mcpAuthorization` rules) for small deployments. Best practice regardless
of engine: **catalog filtering** (denied tools never appear in
`tools/list` — keeps them out of model context) **plus call-time
authorization** (the security boundary). OPA itself documents Rego patterns
for RBAC, ABAC, and rate limiting.

**Rate limiting.** Token bucket per identity × resource is the standard for
agentic workloads (bursty tool calls); WSO2's "tool-based rate limiting"
and the IETF `RateLimit` header fields (RFC 9558:
`RateLimit-Limit/Remaining/Reset`, `quota-policy` parameter) define the
envelope clients should see. 429 + `Retry-After` remains the status contract
(already used in-platform).

**Trace & audit correlation.** W3C Trace Context (`traceparent`,
`tracestate`) is the interoperability standard — OpenTelemetry propagates it
at every hop; gateways are the documented place to *start* a trace when the
inbound request lacks one. W3C requires trace fields carry no PII. On the
audit side, ISO 27001 A.12.4 (logging & monitoring) and NIST AI RMF
(Govern/Map functions) both demand per-action traceability — which the
platform already partially delivers (security events table, slash-command
audit table, nefarious-usage violations). The missing piece is exactly the
cross-hop join.

**Policy simulation.** The strongest found precedent is HarrisonCN/
mcp-gateway PR #86: `POST /admin/policy-sim/simulate` diffs a candidate
policy against **recorded past calls** (replay with arguments, captured
with redaction) — plus dry-run and shadow policies. The MCP Inspector
remains the reference *protocol* tool; the Five-Gates article recommends
pairing it with automated scenario tests in CI rather than as a manual
substitute.

**Lifecycle.** The MCP Registry's `server.schema.json` + `mcp-publisher`
(already used for `io.github.nvdigitalsolutions/nvoos-mcp-gateway`) is the
publication standard; internal catalogs (WSO2 MCP Hub) add lifecycle states
and copy-ready configs. Our per-site toolkit-server descriptors already
carry versions and limits — the catalog is an aggregation problem, not a
format problem.

---

## Design Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | **Own tiny DSL, not OPA-in-process** | The gateway is a single-file-deploy Node service on Velocity; embedding OPA (WASM) adds a build/dep chain for ~5 condition types. The DSL maps 1:1 to Rego concepts (`input.tool`, `input.site`, `input.time`, `input.country`, `input.key`) so an OPA migration is mechanical later. |
| D2 | **Env-first, file-optional config** | `TOOL_QUOTAS` (compact form) and `GATEWAY_POLICY` (JSON) keep the Velocity deploy model; a `POLICY_FILE` path allows Git-versioned policy. Boot fails closed on invalid policy. |
| D3 | **First-match-wins, deny default** | Deterministic, auditable, matches agentgateway/WSO2 semantics; policy defaults to allow only what's explicitly permitted. |
| D4 | **Delegate, don't duplicate** | The gateway enforces *fleet* defaults; sites keep enforcing their deeper per-tool/iteration/nefarious layers. Gateway limits are ceilings, never floors. |
| D5 | **Trace propagation is the audit backbone** | The gateway starts/inherits `traceparent`, stamps upstream requests and logs; no new ID scheme — the existing `X-Request-Id` becomes the `traceparent`'s parent for logs. |
| D6 | **In-memory ring buffer for policy-sim** | Same pattern as the existing 200-entry toolkit audit ring buffer; arguments redacted by default (`LOG_TOOL_ARGS=1` precedent), PII never persisted. |
| D7 | **Admin surface is operator-token only** | `/admin/*` routes require an `op_` operator token; the public `/mcp` surface never sees policy config. |
| D8 | **Catalog reads live descriptors** | `GET /catalog` aggregates the gateway's `server.json` + each bound site's toolkit-server descriptors/health; lifecycle state is gateway config (draft/test/published/retired per site binding). |

---

## Architecture

```
                        ┌──────────────────────────────────────────────┐
 MCP clients ──────────▶│  NV oOS MCP Gateway (addons/mcp-gateway)     │
                        │                                              │
                        │  auth ──▶ quota engine (per key×site×tool)   │
                        │      ──▶ policy engine (time/geo/tool rules) │
                        │      ──▶ inspector (dryRun + /admin/policy-sim│
                        │              + shadow policies)              │
                        │      ──▶ trace (traceparent start/inherit)   │
                        │      ──▶ audit sink (HMAC webhook, optional)  │
                        │      ──▶ catalog (server.json aggregation)   │
                        └──────────────┬───────────────────────────────┘
                                       │ traceparent + op_ token
        ┌──────────────────────────────┼───────────────────────────────┐
        ▼                              ▼                               ▼
   site-a (deep enforcement)      site-b                          site-c
   per-tool RPM / token budgets / iteration caps / capability boundaries /
   nefarious-usage monitor / audit logger — unchanged, still authoritative
                        │
        Complete Console ─ Gateway page (catalog · lifecycle · audit join)
```

Request flow after this proposal: auth → quota check (429 + RFC 9558
headers on breach) → policy evaluation (deny / require_approval → 428 +
preview) → trace stamp → forward with `traceparent` → log line with
`traceId` → optional audit sink. `tools/list` filters tools the policy
denies for that key (catalog-filtering best practice).

---

## Security & Threat Model

- **Policy tampering** — policy/quota config is env-only at the process
  boundary; the `/admin/*` surface cannot mutate it (read-only inspection).
  Boot fails closed on malformed config (existing `AUTH_MODE=strict`
  precedent).
- **Trace/PII hygiene** — W3C Trace Context: `traceparent`/`tracestate`
  carry no PII; the ring buffer redacts tool arguments by default; country
  evaluation never persists IPs.
- **Audit sink integrity** — HMAC-SHA256 signed bodies, shared secret via
  env (OutboundWebhook's `sha256=` pattern); sink outage degrades to local
  logs only, never blocks tool calls.
- **Simulation abuse** — `/admin/policy-sim` requires an operator token and
  only replays already-recorded, already-redacted calls; it never forwards
  to sites.
- **DryRun ambiguity** — `dryRun: true` short-circuits *before* upstream
  dispatch; the response envelope explicitly marks `"dryRun": true` so no
  caller can mistake a simulated result for an execution.
- **Quota bypass** — quotas keyed on `(public key × site × tool)` with the
  same fail-closed attribution as today; unknown tool/site combos fall to
  the global key budget, never to unlimited.

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Config surface creep on Velocity (env size limits) | Compact `TOOL_QUOTAS` form; `POLICY_FILE` for large policies; defaults = today's behavior |
| DSL too weak, OPA needed later | D1 keeps the input model Rego-shaped; migration = swap evaluator behind the same `evaluate(input)` seam |
| Trace IDs leak across tenants | trace IDs are random 128-bit, W3C-spec; never derived from keys or slugs |
| Console page scope creep | Gateway page is read-only + dry-run controls; all writes stay in gateway config |
| Velocity redeploy latency for policy changes | Policy reload on SIGHUP in local/dev; Velocity redeploy is the production change control (documented) |

---

## Open Questions (resolved in implementation)

1. `require_approval` at the gateway → **428 + redacted preview, stateless** —
   the client re-issues after platform-side approval (matches the
   DestructiveOpsGate precedent; no callback needed).
2. Geo source when not behind Cloudflare → **both** — `CF-IPCountry` header
   first, optional static `GEO_MAP` (`country:<site-or-key>`) fallback.
3. `dryRun` transport → **both** — JSON-RPC request param (`dryRun: true`,
   peeks quotas, returns the decision envelope marked `"dryRun": true`)
   and `POST /admin/policy-sim` for replaying candidates against the
   recorded-call ring buffer.
4. Audit sink retry/DLQ → **exponential backoff, drop after 3** — the
   gateway stays dependency-free; sink outage degrades to local logs only.

---

## Deliverables Summary

- `addons/mcp-gateway/src/quota/`, `src/policy/`, `src/trace/`,
  `src/audit/`, `src/admin/`, `src/catalog/` + tests (node --test)
- `GATEWAY_POLICY`, `TOOL_QUOTAS`, `AUDIT_SINK_URL`, `AUDIT_SINK_SECRET`,
  `POLICY_FILE`, `GEO_MAP` env surface (README tables)
- Complete Console **Gateway page** (`examples/nvoos-complete-velocity`)
- Updated `server.json` (new `_meta` capabilities), mirror sync, Velocity
  deploy doc, comparison-doc "What to borrow" updated to closed
- See the implementation plan for the phase-by-phase manifest.
