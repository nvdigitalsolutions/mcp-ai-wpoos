# Implementation Plan: MCP Agent Compatibility & Reliability (Server-Side)

**Status:** Proposed — supersedes/extends `legacy-sse-transport-plan.md`
**Scope:** `includes/` (REST, admin, security), `addons/pro/includes/`, tests, docs
**Goal:** Make the NV oOS MCP endpoint reliable for AI-agent clients (Cloudways
Agent 0.19.0 "Hermes", mcp-remote bridges, Python/TS SDKs) — tool responses
must always come back, errors must be visible, and rate limits must be
configurable and agent-aware.

## 0. Verified problem statement

| # | Symptom | Root cause (verified) |
|---|---------|----------------------|
| 1 | Agent shows no tools | `/mcp` has no legacy SSE transport; SSE-only clients die on the JSON discovery response (see `legacy-sse-transport-plan.md`) |
| 2 | Tool call responses never come back | Tool `WP_Error`s are returned as JSON-RPC errors with **HTTP 500/400/404**; mcp-remote's TS SDK **silently drops non-2xx responses** — pending `tools/call` never resolves. Includes rate-limit hits (429→500), so it triggers under agent bursts |
| 3 | Bursts of tool calls fail | Tool rate limiter hardcoded at **60 executions / 60s** (`TOOL_RATE_LIMIT_MAX/WINDOW`), no settings UI, shared per user/IP across all clients of a credential |
| 4 | Constant `429 ... Failed to open SSE stream` | mcp-remote's GET SSE-stream retry loop burns the general **100 req/hour** bucket (site configured at 100; plugin default 300, admin text recommends 300–1000) |
| 5 | Heavy tools hang, 0-byte responses | `remote_wp_connection`/`ezuite_erp` execute long external work inside the POST request; Cloudflare kills at ~100s. `list_connections` appears to make sequential per-connection calls (each up to 15–30s) |
| 6 | 401 with agent's raw header | `Authorization: cred_xxx` without `Bearer ` is rejected (`permissions_check_mcp()` regex) |

---

## WS-1 — JSON-RPC errors always return HTTP 200

**Why:** JSON-RPC errors are transport-independent. Non-2xx statuses cause
SDKs (mcp-remote's TS SDK, proven) to drop the body — converting every tool
error into silence. Returning 200 + the `{"jsonrpc","id","error"}` envelope
makes all clients relay the error to the agent.

**Files:** `includes/class-wp-mcp-ai-rest-mcp-methods.php`

1. `mcp_error_response()`: always `WP_REST_Response(..., 200)`.
   Keep the existing 400 branch only for… nothing — parse errors
   (`-32700`), method-not-found (`-32601`), internal (`-32603`) all become
   200 with the JSON-RPC error envelope. Keep `Content-Type` + CORS headers.
2. Audit every WP_Error → `mcp_error_response()` wrapper in
   `process_single_mcp_message()` / `handle_mcp_batch()` so no other non-2xx
   leaks from a parsed JSON-RPC message.
3. **Boundary (important):** authentication/authorization failures at the
   REST layer (401/403 from `permissions_check_mcp`) and pre-dispatch guards
   (429 from `check_rate_limit`/load guard) keep their HTTP statuses — SDKs
   handle those as connection/auth errors, and they occur *before* a message
   is parsed. Only errors produced while answering a parsed JSON-RPC message
   become HTTP 200.
4. Add a `wp_mcp_ai_mcp_error_http_status` filter (default 200) for sites
   that prefer strict HTTP semantics.

**Acceptance:**
- `tools/call` for a missing/erroring tool returns HTTP 200 with a valid
  JSON-RPC error; mcp-remote relays it to the agent (E2E test).
- Direct curl: HTTP 200 + `error` key present; initialize/tools/list
  success paths unchanged (regression).

---

## WS-2 — Tool rate limit: settings-driven, agent-aware, with admin UI

**Why:** 60/min is sized for chat-UI abuse. A credentialed agent (max_turns
75, parallel delegation) legitimately exceeds it, and the resulting errors
were invisible (fixed by WS-1) but still break workflows. Make it
configurable, default it sanely, and let admins exempt agent tokens.

**Files:**
- `includes/class-wp-mcp-ai-rest.php`
- `includes/admin/sections/class-wp-mcp-ai-section-security.php`
- `includes/admin/class-wp-mcp-ai-admin-settings-base.php`
- `includes/admin/class-wp-mcp-ai-admin-settings.php`

**Steps:**

1. **New settings** (stored in `wp_mcp_ai_settings`):
   - `tool_rate_limit_max` — int, default `300` (0 = unlimited)
   - `tool_rate_limit_window` — int, default `60`
   - `tool_rate_limit_exempt_tokens` — bool, default `true`
2. **`check_tool_rate_limit()`** rewrite:
   - Read values from settings (fallback: existing constants, then existing
     filters `wp_mcp_ai_tool_rate_limit_max` / `wp_mcp_ai_tool_rate_limit_window`
     kept as overrides for BC).
   - `max <= 0` → allowed.
   - **Exemption:** skip entirely when the request is credential-token
     authenticated (`$auth_context['token_authenticated']` is already
     available at the call site in `handle_tool_request()`, line ~5895) and
     `tool_rate_limit_exempt_tokens` is on. Pass the auth context into the
     method (signature change; single caller + any tests updated).
   - Keep guest/nonce (chat UI) traffic limited — the original abuse
     protection stays intact.
3. **Admin UI** (new "Tool Rate Limiting" heading in Security → Rate
   Limiting section, after `rate_limit_by`):
   ```php
   '_heading_tool_rate_limiting'  => heading 'Tool Rate Limiting',
   'tool_rate_limit_max'          => number, label 'Max Tool Executions',
       description 'Maximum tool executions per window per user/IP. 0 = unlimited.
       Recommended: 300+ for AI agents (agents can fire dozens of tool calls per minute).',
       default 300,
   'tool_rate_limit_window'       => number, label 'Tool Rate Limit Window (seconds)',
       default 60,
   'tool_rate_limit_exempt_tokens'=> checkbox, label 'Exempt AI Agent Tokens',
       checkbox_label 'Do not rate-limit tool calls authenticated with assistant credential tokens',
       description 'Assistant credentials are an explicit grant of the tool set.
       Recommended ON for agent workloads; chat-UI/guest traffic stays limited.',
       default true,
   ```
   The generic field renderer (`type => number|checkbox`) handles output;
   no renderer code needed.
4. **Sanitization**:
   - `includes/admin/class-wp-mcp-ai-admin-settings.php::sanitize_settings()`
     — add explicit entries:
     `tool_rate_limit_max = max(0, absint(...))`,
     `tool_rate_limit_window = max(10, absint(...))`,
     `tool_rate_limit_exempt_tokens = rest_sanitize_boolean(...)`.
   - Defaults registered in `includes/admin/class-wp-mcp-ai-admin-settings-base.php`
     (near `rate_limit_requests`, ~line 644).
5. **Docs:** `docs/rest-api.md` note + changelog.

**Acceptance:**
- Settings appear under oOS → Security and persist.
- Guest chat traffic still limited; agent tokens exempt by default.
- PHPUnit: settings read path, exemption path, 0=unlimited, filter override BC.

---

## WS-3 — Stop the general quota burn from SSE probes

**Why:** mcp-remote GETs the endpoint repeatedly as its SSE stream; each GET
consumes the general rate-limit bucket (site set to 100/hr).

**Steps:**
1. **Exempt GET requests from `check_rate_limit()` counting** — the limiter
   targets expensive/state-changing traffic; a discovery GET is cheap and is
   replayed by retry loops. Implement inside `check_rate_limit()` (only
   counts POST/PUT/PATCH; GETs return true without touching the counter) —
   keep the mesh/bearer/nonce call sites unchanged.
2. **Recommendation + doc:** set `rate_limit_requests` to 1000 for agent
   sites (admin description already recommends 300–1000; no code change).
3. **Root fix for the retry loop** is WS-5 (serve a real SSE stream so the
   client stops retrying).

**Acceptance:** 200× GET /mcp in a loop never 429s while POSTs still count
toward the bucket.

---

## WS-4 — Accept raw `cred_*` Authorization header (compat)

**Why:** Cloudways Agent forwards configured headers verbatim
(`Authorization: cred_xxx` without `Bearer `) → 401 before anything runs.

**Files:** `includes/class-wp-mcp-ai-rest.php` (`permissions_check_mcp()`,
~line 2473)

1. After the existing `Bearer` regex fails, match
   `/^cred_[A-Za-z0-9]+\.[A-Za-z0-9_-]{8,}$/` and treat the value as the
   bearer token (same `validate_local_token()` flow).
2. Gate with filter `wp_mcp_ai_accept_raw_credential_header` (default
   `true`); sites wanting strict semantics can disable.
3. Same handling in `permissions_check_assistant_list`-adjacent paths if the
   raw header reaches them (audit the other `Bearer` regex sites — the
   authenticator and directory permission checks).

**Acceptance:** curl with `Authorization: cred_...` (no Bearer) → 200;
filter off → 401 (regression).

---

## WS-5 — Legacy MCP HTTP+SSE transport on `/mcp`

Implemented per `docs/developer/legacy-sse-transport-plan.md`. Additional
items discovered since that doc was written:

- **Route args:** POST `/mcp` must accept `notifications/initialized`,
  `ping`, etc. — remove the `method` enum from the route `args` (dispatch
  layer answers unknown methods with `-32601`); register optional
  `session_id` arg.
- This workstream also fixes symptom #1 (tools not shown) for SSE-only
  clients and stops mcp-remote's GET retry loop (real SSE stream → no more
  429 burn).
- Feature flag: `WP_MCP_AI_LEGACY_SSE_ENABLED` (default true) + session caps
  (5/credential, 20 total, 30-min TTL) as per the plan.

**Acceptance:** Python SDK `sse_client()` lists 96 tools; streamable HTTP
clients and mcp-remote unchanged (regression matrix in the plan doc).

---

## WS-6 — Heavy-tool hang mitigation (investigate → fix)

**Hypothesis (to confirm first):** `remote_wp_connection list_connections`
and `ezuite_erp list_connections` perform sequential per-connection network
calls (15–30s each) inside one PHP request → cumulative >100s → Cloudflare
524 / PHP kill → 0-byte response (observed).

**Steps:**
1. **Instrument:** enable plugin logging; reproduce via curl; log entry/exit
   per connection test to find the slow call.
2. **Fix candidates (in order of preference):**
   - `list_connections` returns stored config **without** live probing
     (move liveness to the explicit `test_connection` action, which is
     already in the tool's enum).
   - Bound any aggregate work to a wall-clock budget (e.g. 30s), fail fast
     with a clean `WP_Error` ("connection timed out — use test_connection").
   - Ensure every `make_request()` has an explicit timeout ≤ 30s (audit
     `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`;
     most calls already pass 15s, verify the `list_connections` path).
3. **After WS-1:** any resulting `WP_Error` is relayed as a visible JSON-RPC
   error instead of silence — the UX changes from "hang" to "clear error".
4. **Follow-up (optional):** route known-slow tools through the existing
   async job path + `mcp_wait_for_async_tool()` polling (already built),
   decoupling POST lifetime from execution time (also what WS-5 SSE
   message queues provide).

**Acceptance:** `list_connections` returns within ~30s with either data or a
clear error; no 0-byte responses on repeated calls.

---

## Execution order & dependencies

1. **WS-1** (small, unblocks visibility for everything else)
2. **WS-2** (settings + UI + exemption)
3. **WS-3** (quota burn) — quick
4. **WS-4** (auth compat) — quick
5. **WS-5** (legacy SSE transport) — largest; depends on WS-1's error
   mapping for handshake failures
6. **WS-6** (hangs) — investigation first, then targeted fix

Ship WS-1…4 together (safe, additive), WS-5 behind its feature flag, WS-6
after diagnosis.

## Test & verification matrix

| Layer | Tests |
|-------|-------|
| PHPUnit | WS-1: `mcp_error_response()` status/body; WS-2: settings read, exemption, 0=unlimited, filter BC; WS-3: GET exemption; WS-4: raw-header auth on/off; WS-5: Accept discriminator, session store (per plan); settings sanitization for new keys |
| curl | WS-1: erroring tool → HTTP 200 + JSON-RPC error; WS-3: 200 GETs no 429; WS-4: raw header 200, `Bearer` still works |
| mcp-remote E2E | WS-1/2: burst 65 `tools/call` → 60 results + 5 visible JSON-RPC errors (none silent); normal calls relay; WS-5 regression: bridge still connects |
| SDK E2E | Python `sse_client` → 96 tools (WS-5); `streamable_http_client` regression; raw-header connect (WS-4) |
| Production | victory.nvdigital.solutions: verify Hermes lists tools + receives tool errors; monitor PHP-FPM workers + Cloudflare logs during SSE soak |

## Rollout

- Deploy WS-1…4 to production, update the site's `rate_limit_requests` to
  1000 (admin setting), enable WS-5 flag, run the matrix.
- Changelog entries for: JSON-RPC error status change, new Tool Rate
  Limiting UI, token exemption default, raw credential header acceptance,
  legacy SSE transport.
- Keep kill switches: `wp_mcp_ai_mcp_error_http_status` filter,
  `wp_mcp_ai_accept_raw_credential_header` filter,
  `WP_MCP_AI_LEGACY_SSE_ENABLED` constant, tool-limit settings (0=off).
