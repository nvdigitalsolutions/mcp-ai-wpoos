# Proposal 015: MCP Protocol Upgrade to 2026-07-28 — Stateless Core, MRTR, Header Routing

**Date:** July 30, 2026
**Status:** Draft — For Review
**Plugin Version:** 2.x (current)
**Proposal Type:** Architecture / Protocol Migration
**Priority:** Critical (protocol deadline July 28, 2026 — already published)
**Estimated Total Effort:** 14–21 days of focused work

---

## Executive Summary

The Model Context Protocol specification **2026-07-28** published on July 28, 2026, is the largest revision since MCP's launch. It is a **major breaking revision** that removes the `initialize` handshake, eliminates `Mcp-Session-Id` sessions from the protocol layer, introduces Multi Round-Trip Requests (MRTR), requires mandatory `Mcp-Method` and `Mcp-Name` headers on Streamable HTTP, adds response caching (`ttlMs`/`cacheScope`), hardens OAuth authorization, deprecates Roots/Sampling/Logging (12-month off-ramp), and formally deprecates HTTP+SSE transport.

**This plugin serves as BOTH an MCP server and client**, so both sides require coordinated updates across ~12 files. The current implementation is based on the 2024-11-05 specification with stateful session management baked into the core REST handler. Client-side code targets 2025-03-26. Pro toolkit servers target 2025-06-18. All three must converge on `2026-07-28`.

This proposal consolidates:
1. **Industry standards review** — What the new spec requires, backed by official MCP blog, SDK migration guides, and community best practices (Stacktree, 4sysops, Developers Digest, BOVO Digital, TypeScript SDK v2 docs)
2. **Gap analysis** — Precisely what must change in the codebase
3. **Implementation plan** — Phased with concrete code patterns, test strategy, and rollback considerations

---

## 1. Industry Standards Review

### 1.1 What the 2026-07-28 Specification Requires

The final specification published July 28, 2026, consolidates a year of roadmap work (announced March 9, 2026) into a single dated release. The release candidate was locked May 21, 2026, with a ten-week validation window. All four Tier 1 SDKs (TypeScript, Python, Go, C#) speak 2026-07-28 as of publication day. Anthropic announced same-day adoption across Claude products.

**Six Specification Enhancement Proposals (SEPs)** drive the stateless core:

| SEP | Change | Status |
|-----|--------|--------|
| SEP-2567 | Sessions removed — no `Mcp-Session-Id`, list endpoints connection-independent | **Breaking** |
| SEP-2575 | Handshake removed — `initialize`/`initialized` gone; `_meta` per-request; `server/discover` for capability probing | **Breaking** |
| SEP-2243 | `Mcp-Method` and `Mcp-Name` headers required on Streamable HTTP POST | **Breaking** |
| SEP-2549 | `ttlMs` and `cacheScope` on `tools/list`, `resources/list`, `resources/read` | Compatible, new fields |
| SEP-2322 | Multi Round-Trip Requests (MRTR) replace server-to-client `elicitation`/`sampling`/`roots` | **Breaking** for elicitation users |
| SEP-2663 | Tasks moved from experimental core to extension; `tasks/get` polling replaces blocking `tasks/result` | **Breaking** for Tasks users |

**Additional hardening:**

| SEP | Change |
|-----|--------|
| SEP-2468 | RFC 9207 `iss` validation — clients MUST validate `iss` against recorded issuer |
| SEP-837 | `application_type` during DCR to avoid OIDC redirect URI conflicts |
| SEP-2352 | Credentials bound to issuer; re-register on authorization server change |
| SEP-2596 | Formal deprecation policy (12-month minimum window); HTTP+SSE deprecated |
| SEP-2577 | Roots, Sampling, Logging deprecated (12-month off-ramp) |
| SEP-2106 | JSON Schema 2020-12 for tool input/output schemas |
| SEP-2164 | Error code `-32002` replaced by `-32602` (missing resource to Invalid Params) |
| SEP-414 | W3C Trace Context standardized for distributed tracing |

### 1.2 Best Practices from Industry Migration Guides

Based on analysis of six authoritative sources (TypeScript SDK v2 migration guide, Developers Digest checklist, BOVO Digital enterprise plan, Stacktree, 4sysops, MCP blog):

**Server-side best practices:**
1. **Remove `initialize` handshake entirely** — every request is self-contained; protocol version, client identity, and capabilities travel in `_meta` per-request
2. **Implement `server/discover`** — servers MUST support this RPC for capability probing; clients MAY call it for up-front version selection or STDIO backwards-compatibility probing
3. **Emit `Mcp-Method`/`Mcp-Name` headers** on every Streamable HTTP response; validate them against the body on inbound (gateways route on headers, not body inspection)
4. **Add `ttlMs`/`cacheScope` to list endpoints** — conservative defaults: `ttlMs: 0`, `cacheScope: 'private'`
5. **Use explicit handles for cross-call state** — instead of hidden session state, return `basket_id`, `workflow_run_id`, etc. as ordinary tool arguments; the model can reason about and compose them
6. **Migrate elicitation to MRTR** — return `InputRequiredResult` with `inputRequests` + opaque `requestState`; client gathers answers and re-issues the original call
7. **Migrate SSE transport to pure Streamable HTTP** — SSE deprecated with 12-month off-ramp; plan migration now

**Client-side best practices:**
1. **Stop sending `initialize`** — issue self-contained requests against 2026-07-28 servers
2. **Do not assume a pinned server instance** — design for any request reaching any instance
3. **Honor `ttlMs`** — cache tool lists for the declared window
4. **Implement `iss` validation (SEP-2468)** first — OAuth mix-up attack mitigation
5. **Plan for deprecated capabilities** — Roots, Sampling, Logging have 12 months; do not build new features on them
6. **Support MRTR auto-fulfilment** — collect `inputResponses` and re-issue requests with echoed `requestState`
7. **Sandbox MCP Apps UI** if adopted — server-rendered HTML is untrusted content

**Testing strategy:**
- Round-robin load test without sticky sessions (verifies statelessness)
- Chaos test: kill instance mid-request, verify recovery via `requestState`
- Protocol conformance suite replay with session affinity removed
- Version mismatch: verify `UnsupportedProtocolVersionError` on wrong version

**Rollback planning:**
- Keep a legacy path for 2025-era clients during transition (per TypeScript SDK v2 `legacy: 'stateless'` pattern)
- Deprecation window means nothing breaks on day one for existing 2025-11-25 clients
- All Tier 1 SDKs support both eras simultaneously

### 1.3 Formal Deprecation Policy (SEP-2596)

MCP now has three lifecycle states: **Active → Deprecated → Removed**. Key guarantee: a feature must remain Deprecated for **at least 12 months** before removal. Expedited removal for critical security issues requires a minimum **90 days**. This means:

- Roots, Sampling, Logging: functional until at least July 28, 2027
- HTTP+SSE transport: functional until at least July 28, 2027
- No urgency to remove these, but **do not build new dependencies on them**

---

## 2. Current Plugin Gap Analysis

### 2.1 Critical — Session-Based Transport (Server Side)

**Location:** `includes/class-wp-mcp-ai-rest-mcp-methods.php`

| Issue | Current State | Required Change |
|-------|--------------|-----------------|
| Session ID generation | `attach_session_header()` (line 1738) generates `sess_` + `bin2hex(random_bytes(16))`, stores in WP transients with 1-hour TTL | Remove entirely; no more session IDs |
| Session ID reading | `handle_mcp_request()` (line 46) reads `Mcp-Session-Id` from request headers | Remove header read; requests are stateless |
| Session ID response | `Mcp-Session-Id` exposed via CORS `Access-Control-Expose-Headers` (line 1858) | Remove from CORS configuration |
| Session transient storage | `wp_mcp_ai_session_*` transients created/updated per request | Drop transient storage; clean up existing transients on upgrade |
| `initialize` handshake | `mcp_initialize()` (line 361) returns `protocolVersion: '2024-11-05'` | Convert to `server/discover`; bump `protocolVersion` to `2026-07-28` |
| `notifications/initialized` | `mcp_notifications_initialized()` handler (line 1647) processes post-initialize notification | Drop handler; `notifications/initialized` is retired |
| `_meta` response | Line 480 adds `_meta` with OAuth discovery only | Add `io.modelcontextprotocol/serverInfo` to every response per SEP-2575 |

**Code location detail:**
- `attach_session_header()`: lines 1738–1778
- `handle_mcp_request()` session read: lines 46–48
- `mcp_initialize()`: lines 361–480
- CORS headers with `Mcp-Session-Id`: lines 1853–1858
- `notifications/initialized` handler: lines 1647–1670

### 2.2 Critical — Session-Based Transport (Client Side)

**Location:** `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php`

| Issue | Current State | Required Change |
|-------|--------------|-----------------|
| Protocol version | `const PROTOCOL_VERSION = '2025-03-26'` (line 46) | Bump to `'2026-07-28'` |
| `initialize()` method | Sends `initialize` JSON-RPC, advertises `roots` capability, sends `notifications/initialized` (lines 155–180) | Replace with `server/discover` probe; remove `roots` capability |
| Session ID tracking | `$this->session_id` property (line 88), captured from `mcp-session-id` response header (line 359), sent on every request (line 471) | Remove property; remove header capture; remove header emission |
| `roots` capability | `'roots' => new stdClass()` advertised at line 158 | Remove; Roots is deprecated (12-month off-ramp) |
| `get_session_id()` | Public accessor at line 579 | Remove method |
| `_meta` per-request | Not sent | Add `_meta` with `io.modelcontextprotocol/protocolVersion`, `clientInfo`, `clientCapabilities` to every request |

**Additional client files:**

| File | Current Version | Required |
|------|----------------|----------|
| `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php` line 103 | `'2024-11-05'` | `'2026-07-28'` |

### 2.3 Critical — Pro Toolkit Servers

**Locations:**
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-base.php` line 344
- `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-rest-controller.php` lines 377, 488
- `addons/pro/tests/test-toolkit-server-contract.php` line 101

| Issue | Current State | Required Change |
|-------|--------------|-----------------|
| Protocol version | `'2025-06-18'` in descriptor, `initialize` response, and `list_descriptors` | Bump all three to `'2026-07-28'` |
| Test assertion | `test-toolkit-server-contract.php` asserts `'2025-06-18'` | Update test expectation |

### 2.4 Moderate — Deprecated Features in Active Use

| Feature | Location | Status | Action |
|---------|----------|--------|--------|
| `roots` capability | `class-wp-mcp-ai-mcp-app-client.php:158` | Deprecated per SEP-2577 | Remove from client advertisement; harmless to keep for 12 months but should not be adopted by new implementations |
| `logging` capability | `mcp_initialize()` line 407 returns `'logging' => new stdClass()` | Deprecated per SEP-2577 | Can keep for backward compatibility; note for removal by July 2027 |
| `completions` capability | `mcp_initialize()` line 406 | Check spec status | Verify if still supported or deprecated in 2026-07-28 |

### 2.5 Moderate — SSE Transport Deprecated

| Location | Current State | Required Change |
|----------|--------------|-----------------|
| `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` | Offers SSE via GET `/mcp` (lines 124–136) | Add deprecation notice; plan migration to pure Streamable HTTP (already supported via POST) |
| SSE handler | `includes/rest/class-wp-mcp-ai-sse-handler.php` | Functional until July 2027 per deprecation policy; add deprecation notice in docs |

### 2.6 Minor — Header Routing Not Yet Implemented

| Issue | Current State | Required Change |
|-------|--------------|-----------------|
| `Mcp-Method` header | Not emitted or validated | Server: add to responses; validate against body on inbound. Client: add to requests |
| `Mcp-Name` header | Not emitted | Required on `tools/call`, `resources/read`, `prompts/get` |
| `MCP-Protocol-Version` header | Not emitted | Add to every Streamable HTTP request per SEP-2243 |

### 2.7 Minor — List Caching Not Implemented

| Issue | Current State | Required Change |
|-------|--------------|-----------------|
| `tools/list` caching | `mcp_tools_list()` (line 564) returns no cache metadata | Add `ttlMs` (default 0, configurable via filter) and `cacheScope` (`'private'`) |
| `resources/list` caching | Not yet implemented in base plugin | If/when added, include `ttlMs`/`cacheScope` |

### 2.8 Minor — Admin UI and Documentation

| Location | Current State | Required Change |
|----------|--------------|-----------------|
| `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php` line 133 | Displays `<code>2024-11-05</code>` | Update to `2026-07-28` |
| Diagnostic code sample line 183 | Shows `"protocolVersion": "2024-11-05"` | Update sample JSON |
| `includes/class-wp-mcp-ai-stdio-transport.php` line 29 | PHPDoc references `MCP 2024-11-05` | Update docblock |
| ~10 PHPDoc blocks referencing `2024-11-05` | Throughout `class-wp-mcp-ai-rest-mcp-methods.php` | Update to `2026-07-28` |

### 2.9 Additional — A2A Agent Card

**Location:** `includes/a2a/class-wp-mcp-ai-a2a-agent-card.php`

A2A is a separate protocol (Google Agent-to-Agent), not MCP. Its `PROTOCOL_VERSION` constant (`'0.3.0'`) is unaffected by this migration. No changes needed.

---

## 3. Implementation Plan

### 3.1 Phase 1: Server-Side Stateless Core (Days 1–4)

**Files to modify:**
1. `includes/class-wp-mcp-ai-rest-mcp-methods.php`

**Changes:**

#### Step 1.1 — Remove Session Management

Remove the following code paths:
- `handle_mcp_request()` lines 46-48: `$session_id = $request->get_header( 'Mcp-Session-Id' )`
- `handle_mcp_request()` call to `$this->attach_session_header()`
- `$session_id` parameter from `handle_mcp_batch()`
- Entire `attach_session_header()` method (lines 1738-1778)
- `'Mcp-Session-Id'` from CORS `Access-Control-Allow-Headers` (line 1852)
- `'Mcp-Session-Id'` from CORS `Access-Control-Expose-Headers` (line 1858)
- Add to CORS allowed headers: `'Mcp-Method, Mcp-Name'`
- Add to CORS expose headers: `'Mcp-Method, Mcp-Name'`

#### Step 1.2 — Convert `initialize` to `server/discover`

Add new method `mcp_server_discover()` that returns:

```php
protected function mcp_server_discover( $params, WP_REST_Request $request ) {
    $assistant_id = isset( $params['assistant_id'] ) ? absint( $params['assistant_id'] ) : 0;
    $assistant_id = $this->resolve_assistant_id( $assistant_id );
    $scoped_id    = $this->apply_token_assistant_scope( $assistant_id );
    if ( ! is_wp_error( $scoped_id ) ) {
        $assistant_id = $scoped_id;
    }

    $response = array(
        'protocolVersion' => '2026-07-28',
        'capabilities'    => array(
            'tools'     => array( 'listChanged' => true ),
            'resources' => array( 'subscribe' => false, 'listChanged' => true ),
            'prompts'   => array( 'listChanged' => true ),
        ),
        'instructions' => $this->build_discover_instructions( $assistant_id ),
    );

    // OAuth discovery metadata if available.
    if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
        $response['_meta'] = array(
            'io.modelcontextprotocol/oauth' => WP_MCP_AI_OAuth_Server::get_discovery_metadata( $assistant_id ),
        );
    }

    // Optionally embed tools for clients that expect them upfront.
    $include_tools = apply_filters( 'wp_mcp_ai_discover_include_tools', true, $params, $request );
    if ( $include_tools ) {
        $tools_result = $this->mcp_tools_list( $params, $request );
        if ( ! is_wp_error( $tools_result ) && isset( $tools_result['tools'] ) ) {
            $response['tools'] = $tools_result['tools'];
        }
    }

    return $response;
}
```

#### Step 1.3 — Legacy Shim for `initialize`

In `process_single_mcp_message()`, route legacy `initialize` calls to `server/discover`:

```php
case 'initialize':
    // Legacy shim: route 2024/2025-era initialize to server/discover.
    return $this->mcp_server_discover( $message['params'] ?? array(), $request );

case 'server/discover':
    return $this->mcp_server_discover( $message['params'] ?? array(), $request );

case 'notifications/initialized':
    // No-op: retired in 2026-07-28. Acknowledge silently.
    return array();
```

#### Step 1.4 — Add Server Identity to Every Response via `_meta`

Per SEP-2575, stamp `_meta['io.modelcontextprotocol/serverInfo']` on every response.

### 3.2 Phase 2: Header Routing and Caching (Days 4–6)

**Files to modify:**
1. `includes/class-wp-mcp-ai-rest-mcp-methods.php`
2. `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php`

#### Step 2.1 — Server Response Headers

After processing each JSON-RPC request, set response headers:

```php
$method = $message['method'] ?? '';
$response->header( 'Mcp-Method', $method );

if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
    $name = $message['params']['name'] ?? '';
    $response->header( 'Mcp-Name', $name );
}
```

#### Step 2.2 — Inbound Header Validation (SEP-2243)

```php
$header_method = $request->get_header( 'Mcp-Method' );
if ( ! empty( $header_method ) && $header_method !== $message['method'] ) {
    return $this->mcp_error_response(
        $message['id'] ?? null,
        -32020, // HeaderMismatch
        'Header mismatch: Mcp-Method does not match body method'
    );
}
```

#### Step 2.3 — Add ttlMs/cacheScope to tools/list

```php
return array(
    'tools'      => $mcp_tools,
    'ttlMs'      => apply_filters( 'wp_mcp_ai_tools_list_cache_ttl_ms', 0 ),
    'cacheScope' => 'private',
);
```

### 3.3 Phase 3: Client-Side Migration (Days 6–10)

**Files to modify:**
1. `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php`
2. `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php`

#### Step 3.1 — Bump Version and Add `_meta` Per-Request

- `PROTOCOL_VERSION` constant: `'2025-03-26'` → `'2026-07-28'`
- Add `build_request_meta()` method that returns `_meta` with `io.modelcontextprotocol/protocolVersion`, `clientInfo`, `clientCapabilities`
- Merge `_meta` into params on every request except `server/discover`

#### Step 3.2 — Replace `initialize()` with `discover()`

- New `discover()` method sends `server/discover` RPC instead of `initialize`
- No `notifications/initialized` sent after
- Returns same shape: `success`, `server_info`, `capabilities`, `has_tools`, `has_resources`

#### Step 3.3 — Remove Session Tracking

- Remove `$this->session_id` property
- Remove session capture from `mcp-session-id` response header
- Remove `Mcp-Session-Id` header emission in `get_request_headers()`
- Remove `get_session_id()` method

#### Step 3.4 — Add Routing Headers to Client Requests

In `send_request()`, add:
- `Mcp-Method` header for every request
- `Mcp-Name` header for `tools/call`, `resources/read`, `prompts/get`
- `MCP-Protocol-Version` header on every request

### 3.4 Phase 4: Pro Toolkit Servers (Days 10–12)

**Files to modify:**
1. `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-base.php` — bump `protocolVersion` to `'2026-07-28'`
2. `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-rest-controller.php` — bump in two locations
3. `addons/pro/tests/test-toolkit-server-contract.php` — update test assertion

### 3.5 Phase 5: Admin UI, Diagnostics, and Documentation (Days 12–14)

**Files to modify:**
1. `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php` — update version strings
2. `includes/class-wp-mcp-ai-stdio-transport.php` — update docblock
3. Multiple PHPDoc `@since` references in `includes/class-wp-mcp-ai-rest-mcp-methods.php`

### 3.6 Phase 6: Testing and Validation (Days 14–21)

#### Test Plan

| # | Test | Type | Priority |
|---|------|------|----------|
| 1 | `server/discover` returns correct capabilities and `2026-07-28` | Unit | Critical |
| 2 | Legacy `initialize` routes to `server/discover` (backward compat) | Integration | Critical |
| 3 | Requests without `Mcp-Session-Id` succeed (stateless) | Integration | Critical |
| 4 | `Mcp-Method` header emitted on all responses | Unit | Critical |
| 5 | `Mcp-Name` header emitted on `tools/call`, `resources/read`, `prompts/get` | Unit | Critical |
| 6 | `Mcp-Method` header mismatch returns `-32020` | Unit | Moderate |
| 7 | `tools/list` returns `ttlMs` and `cacheScope` fields | Unit | Moderate |
| 8 | Client sends `_meta` per request | Integration | Critical |
| 9 | Client `discover()` replaces `initialize()` correctly | Integration | Critical |
| 10 | Client headers (`Mcp-Method`/`Mcp-Name`/`MCP-Protocol-Version`) on every request | Unit | Critical |
| 11 | Client no longer sends `Mcp-Session-Id` | Unit | Critical |
| 12 | Pro toolkit servers return `2026-07-28` | Unit | Critical |
| 13 | Diagnostic page shows `2026-07-28` | Manual | Minor |
| 14 | OAuth `iss` validation (SEP-2468) — if OAuth used | Security | Moderate |
| 15 | Session transients cleaned up on upgrade | Migration | Moderate |

#### Regression Tests

1. Full PHPUnit suite: `composer run test`
2. Pro addon test suite
3. Manual test: Claude Desktop 2026-07-28 client connection
4. Manual test: Chat UI via SSE (deprecated but not removed)
5. Verify A2A agent card unaffected

---

## 4. Rollout Strategy

### 4.1 Version Compatibility Matrix

| Client Version | Server Version | Behavior |
|---------------|---------------|----------|
| 2026-07-28 | 2026-07-28 | Full stateless, header routing, cacheable lists |
| 2025-11-25 / 2024-11-05 | 2026-07-28 (with shim) | `initialize` routed to `server/discover`, session header ignored |
| 2026-07-28 | 2024-11-05 (old) | Client sends `server/discover`; gets `-32601`; falls back to `initialize` |
| 2025-11-25 | 2024-11-05 | Unchanged |

### 4.2 Rollout Phases

**Phase A — Development (Days 1–14):** Feature branch, full test suite, manual Claude Desktop testing

**Phase B — Staging (Days 14–18):** Staging deploy, round-robin load test (2+ instances), chaos test

**Phase C — Production Canary (Days 18–20):** 10% production, monitor MCP endpoint error rates, rollback if >1% error increase

**Phase D — Full Production (Day 21):** Full deploy, run session transient cleanup, 7-day monitor

### 4.3 Rollback Plan

1. Deploy previous version with 2024-11-05 protocol
2. Session transients not deleted until Phase D upgrade routine
3. Legacy `initialize` shim preserved for backward compatibility
4. No data loss risk — sessions are ephemeral transients

---

## 5. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Breaking existing MCP client connections | Medium | High | Legacy `initialize` shim; backward compat tested in staging |
| Pro MCP App Client breaks remote server connections | Medium | High | Client falls back to `initialize` for 2025-era servers; test with MCP directories |
| OAuth flow breakage from `iss` validation | Low | Medium | Validate SEP-2468 compliance in staging |
| SSE transport breakage | Low | Low | SSE code path unchanged; deprecated but not removed |

---

## 6. Success Metrics

- [ ] All PHPUnit tests pass (base + Pro)
- [ ] `server/discover` returns `2026-07-28` protocol version
- [ ] `Mcp-Method` and `Mcp-Name` headers present on all Streamable HTTP responses
- [ ] `tools/list` includes `ttlMs` and `cacheScope` fields
- [ ] Client no longer sends `Mcp-Session-Id` header
- [ ] Client sends `_meta` per request
- [ ] Diagnostic page shows `2026-07-28`
- [ ] Legacy `initialize` requests still receive valid responses
- [ ] Pro toolkit servers report `2026-07-28`
- [ ] Zero regressions in existing tool execution

---

## 7. References

### Official Specification
1. [MCP 2026-07-28 Release Candidate](https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/)
2. [MCP 2026-07-28 Specification Final](https://blog.modelcontextprotocol.io/posts/2026-07-28/)
3. [Key Changes Changelog](https://modelcontextprotocol.io/specification/changelog)
4. [Feature Lifecycle and Deprecation Policy](https://modelcontextprotocol.io/specification/feature-lifecycle)
5. [MCP schema.ts SEP-2322 MRTR](https://github.com/modelcontextprotocol/specification/blob/main/schema/2026-07-28/schema.ts)

### SDK Migration Guides
6. [TypeScript SDK v2: Supporting 2026-07-28](https://ts.sdk.modelcontextprotocol.io/v2/migration/support-2026-07-28)
7. [Beta SDKs for 2026-07-28 RC](https://blog.modelcontextprotocol.io/posts/sdk-betas-2026-07-28/)

### Industry Analysis
8. [Stacktree: What Changed in 2026-07 MCP](https://stacktr.ee/blog/mcp-2026-spec-changes)
9. [4sysops: 2026-07-28 MCP Analysis](https://4sysops.com/archives/2026-07-28-model-context-protocol-mcp-stateless-multi-round-trip-routable-headers-authorization-hardening/)
10. [Developers Digest: MCP 2026-07-28 Migration](https://www.developersdigest.tech/blog/mcp-2026-07-28-breaking-changes)
11. [BOVO Digital: Enterprise Migration Guide](https://www.bovo-digital.tech/en/blog/mcp-2026-specification-stateless-enterprise-agents)
12. [Microsoft MCP for Beginners: 2026-07-28 RC](https://github.com/microsoft/mcp-for-beginners/blob/main/01-CoreConcepts/mcp-2026-07-28-release-candidate.md)

---

## Appendix A: File Change Summary

| # | File | Change Type | Effort |
|---|------|------------|--------|
| 1 | `includes/class-wp-mcp-ai-rest-mcp-methods.php` | Major refactor — sessions, discover, headers, cache, CORS | **Large** |
| 2 | `addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php` | Major refactor — sessions, meta, headers, version | **Large** |
| 3 | `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php` | Version bump | Trivial |
| 4 | `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-server-base.php` | Version bump | Trivial |
| 5 | `addons/pro/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-rest-controller.php` | Version bump x 2 | Trivial |
| 6 | `addons/pro/tests/test-toolkit-server-contract.php` | Update assertion | Trivial |
| 7 | `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php` | Version strings | Trivial |
| 8 | `includes/class-wp-mcp-ai-stdio-transport.php` | Docblock | Trivial |
| 9 | `includes/class-wp-mcp-ai-rest.php` | Docblocks | Trivial |
| 10 | `addons/pro/tests/test-jetengine-mcp-client.php` | Test updates | Small |
| 11 | `CHANGELOG.md` | Changelog entry | Trivial |

## Appendix B: Suggested Constants

```php
// Main plugin file — single point of truth for protocol version:
define( 'WP_MCP_AI_PROTOCOL_VERSION', '2026-07-28' );
```

## Appendix C: Upgrade Routine for Session Transient Cleanup

```php
function wp_mcp_ai_cleanup_session_transients() {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( '_transient_wp_mcp_ai_session_' ) . '%'
        )
    );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( '_transient_timeout_wp_mcp_ai_session_' ) . '%'
        )
    );
}
```
