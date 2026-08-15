# Plan: Legacy MCP HTTP+SSE Transport Support (Server-Side)

**Status:** Proposed
**Scope:** `includes/rest/`, `includes/class-wp-mcp-ai-rest.php`, tests, docs
**Target:** Make `GET/POST /wp-json/mcp-ai/v1/mcp` speak the **legacy MCP
HTTP+SSE transport** (the `sse_client` wire protocol) in addition to the
existing Streamable HTTP (JSON-over-POST) transport — without regressing any
currently-working client.

## 1. Problem

Clients built on the legacy MCP SSE transport (e.g. Cloudways Agent 0.19.0,
older MCP SDK versions configured with an SSE transport) do this:

1. `GET <configured-url>` with `Accept: text/event-stream`
2. Expect an SSE stream containing `event: endpoint` + `data: <post-url>`
3. `POST` each JSON-RPC message to `<post-url>?session_id=...`
4. Read JSON-RPC responses back off the **GET stream** as
   `event: message` frames

Our server currently answers every `GET /mcp` with a JSON discovery document
(`Content-Type: application/json`), so the client's SSE reader dies with:

```
SSEError: Expected response with content type 'text/event-stream', got 'application/json'
ExceptionGroup: unhandled errors in a TaskGroup (1 sub-exception)
```

(reproduced with the official MCP Python SDK `sse_client`; matches the
Cloudways Agent log `MCP server '...' initial connection failed (attempt 1/3)`).

Secondary issue: some agents send the `Authorization` header value verbatim as
configured (e.g. `cred_xxxxx.SECRET` **without** the `Bearer ` prefix). Our
`permissions_check_mcp()` rejects that with 401 (`/^Bearer\s+(.*)$/` match only).

## 2. Target wire protocol (reference: MCP SDK `SseServerTransport`)

```
Client                          Server
  | GET /mcp                       |
  |  Accept: text/event-stream     |
  |  Authorization: Bearer cred_.. |
  |-------------------------------->
  |                                | 200 text/event-stream
  |                                | event: endpoint
  |                                | data: /wp-json/mcp-ai/v1/mcp?session_id=<uuid>
  |  (stream stays open)           |
  |                                | : keepalive            (every ~15s)
  | POST /mcp?session_id=<uuid>    |
  |  Content-Type: application/json|
  |  {"jsonrpc":"2.0","method":    |
  |   "initialize",...}            |
  |-------------------------------->
  |                                | 202 Accepted (no body)
  |                                | event: message
  |                                | data: {"jsonrpc":"2.0","id":1,"result":{...}}
  | POST ... (tools/list) ...      |  ... same cycle ...
```

Rules learned from the reference implementation and the installed client SDK:

- The `endpoint` event data must be same-origin (relative path is safest).
- Session ID travels as `session_id` (or `sessionId`) query param.
- The client **does not read the POST body** for responses — it only checks
  the HTTP status. Responses MUST be delivered on the GET stream as
  `event: message` frames.
- Unknown events and SSE comments are ignored by clients → safe for keepalives.
- `event: message` with empty data is skipped by clients → another keepalive option.
- POST must return quickly (202) — tool execution can be slow; the response
  arrives on the GET stream whenever ready (this incidentally fixes the
  server-side timeouts seen on heavy tools like `remote_wp_connection`).
- The session is owned by the credential that created it; POSTs with a
  different credential get 404 (no existence leak).

## 3. Design decisions

### 3.1 Transport discrimination (the delicate part)

Streamable HTTP clients (Zed, Cursor, LM Studio, mcp-remote, new SDKs) send
`Accept: application/json, text/event-stream` on POST **and expect JSON back**.
Legacy SSE clients send `Accept: text/event-stream` **only**.

Decision:

- **GET /mcp** → serve the legacy SSE handshake when:
  - `?stream=true` is present, OR
  - `Accept` contains `text/event-stream` and does **not** contain
    `application/json`.
  Otherwise keep returning the discovery JSON (unchanged).
- **POST /mcp** → treat as a legacy-SSE message POST when it carries a valid
  `session_id` query param that maps to a live session whose owner credential
  matches. Otherwise keep the existing JSON behavior (unchanged).

This is conservative: the only requests whose behavior changes are ones that
are currently broken (legacy SSE clients) or explicitly opt-in (`?stream=true`).

Behavior change to call out: `GET /mcp?stream=true` previously streamed the
assistant directory (`event: directory`) — it becomes the MCP SSE handshake.
The directory stream remains available at `GET /sse` (unchanged).

### 3.2 Reuse the JSON-RPC dispatch core

`WP_MCP_AI_REST_MCP_Methods::process_single_mcp_message()` and
`handle_mcp_batch()` already return `WP_REST_Response` objects whose
`get_data()` is the exact JSON-RPC response array. Extract a thin helper:

```php
protected function process_mcp_request_to_data( WP_REST_Request $request ) : array
// returns array of JSON-RPC response arrays (0..n entries; notifications -> none)
```

`handle_mcp_request()` keeps building the JSON `WP_REST_Response` from that
array — byte-identical to today. The SSE path enqueues the same arrays onto
the session queue. **No change to tool/method semantics.**

### 3.3 Session storage

WordPress transients, one per session:

- Key: `wp_mcp_ai_sse_session_<uuid>` → JSON `{ credential_hash, created, queue: [] }`
  with TTL = max session lifetime.
- Registry option `wp_mcp_ai_sse_session_registry` → map `uuid → expiry` for
  counting/GC (transients can't be listed).
- Session ID: `wp_generate_uuid4()` (fallback `bin2hex( random_bytes(16) )`).
- Credential hash: `hash( 'sha256', normalized Authorization header )` — binds
  the session to the exact credential, mirroring the SDK's `_session_owners`.

### 3.4 Long-lived GET on PHP/WordPress

Reuse `WP_MCP_AI_SSE_Handler` (`send_sse_headers()`, `send_sse_comment()`,
`finish()`). The handshake handler:

1. Runs the strict bearer auth check (see §5.2).
2. Creates the session, sends `send_sse_headers()` + `event: endpoint`.
3. Polls every ~1s: `connection_aborted()` → break; drain queue → emit
   `event: message` frames; every 15s emit a keepalive comment (Cloudflare
   idle cutoff); touch the transient TTL; enforce absolute max lifetime.
4. Deletes the session and calls `finish()`.

PHP-FPM pins one worker per open stream — this is the main operational cost
(see §6 caps).

## 4. New/changed files

| File | Change |
|------|--------|
| `includes/rest/class-wp-mcp-ai-sse-session-store.php` | **NEW.** Session registry + queue (transients), owner binding, caps, GC. |
| `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` | GET: discriminator → `handle_mcp_sse_handshake()`. POST: `session_id` branch → enqueue + 202. Add `session_id` arg to POST route. |
| `includes/class-wp-mcp-ai-rest-mcp-methods.php` | Extract `process_mcp_request_to_data()`; relax `method` enum (§5.4). |
| `includes/class-wp-mcp-ai-rest.php` | `handle_sse_handshake()` delegates to the real handshake; add session-owner helpers. |
| `includes/rest/class-wp-mcp-ai-rest-authenticator.php` | Optional raw `cred_*` header acceptance (§5.2). |
| `tests/test-legacy-sse-transport.php` | **NEW.** Unit tests (discriminator, session store, auth parsing, queueing). |
| `docs/rest-api.md`, `docs/tool-reference.md` | Document the second transport + auth compat. |
| `readme.txt` / changelog | Note the feature + `?stream=true` behavior change. |

## 5. Implementation steps

### 5.1 Session store (`class-wp-mcp-ai-sse-session-store.php`)

```php
class WP_MCP_AI_SSE_Session_Store {
    public function create( string $credential_hash ) : string|WP_Error; // enforces caps
    public function is_owner( string $session_id, string $credential_hash ) : bool; // 404-style false
    public function enqueue( string $session_id, array $jsonrpc_response ) : bool; // cap 50, touch TTL
    public function drain( string $session_id ) : array;
    public function touch( string $session_id ) : void;
    public function delete( string $session_id ) : void;
    public function active_count() : int; // prunes registry lazily
}
```

Filters (defaults): `wp_mcp_ai_sse_session_ttl` (1800s),
`wp_mcp_ai_sse_queue_max` (50), `wp_mcp_ai_sse_max_per_credential` (5),
`wp_mcp_ai_sse_max_total` (20). Kill-switch constant:
`WP_MCP_AI_LEGACY_SSE_ENABLED` (default `true`).

Known limitation (document in code): enqueue/drain are get-modify-set on a
transient; the SDK sends messages sequentially so races are practically
nonexistent. If concurrent POSTs ever matter, serialize per-session with the
existing `WP_MCP_AI_Concurrency_Guard`.

### 5.2 Auth

**Required — session ownership.** The SSE handshake handler calls the same
validation chain as `permissions_check_mcp()` (bearer/mesh/basic) and refuses
unauthenticated handshakes (the GET route's current `permissions_check`
stays for the JSON-discovery path).

**Optional but recommended — raw credential header.** In
`permissions_check_mcp()`, after the existing `Bearer` regex fails, accept a
raw `cred_<id>.<secret>` Authorization value (pattern:
`/^cred_[A-Za-z0-9]+\.[A-Za-z0-9_-]{8,}$/`) as if it were the bearer token,
gated by filter `wp_mcp_ai_accept_raw_credential_header` (default `true`).
Rationale: the credential itself is the secret; the `Bearer` label adds no
security, and several agent configs (including Cloudways Agent) forward the
header verbatim. Alternative for stricter sites: leave the filter `false` and
fix the header client-side.

### 5.3 GET /mcp handshake (`handle_mcp_sse_handshake`)

```
1. authenticate (strict)                        -> 401 WP_Error on failure
2. if active_count() >= cap                     -> 503 WP_Error
3. session = store->create( credential_hash )
4. sse_handler->send_sse_headers()              (existing; flushes early)
5. emit: event: endpoint / data: ?session_id=<id> (relative URL)
6. loop until connection_aborted() or ttl exceeded:
     - drain queue -> for each: emit event: message, data: wp_json_encode(resp)
     - every 15s: send_sse_comment('keepalive'); store->touch(id)
     - sleep 1s
7. store->delete(id); sse_handler->finish()
```

### 5.4 POST /mcp legacy branch

1. `$session_id = $request->get_param('session_id')` — if absent → existing
   JSON path (unchanged).
2. Validate session + owner match → else 404 `WP_Error` (no leak).
3. `$responses = $this->process_mcp_request_to_data( $request )`
   (reuses existing parse/validate/dispatch; notifications yield nothing).
4. Enqueue each response; return 202 with empty body + CORS headers.

**Route `args` change (required):** the POST route currently declares
`method` with an `enum` of only 5 methods. Legacy clients send
`notifications/initialized` (and possibly `ping`) which would be rejected by
arg validation with 400. Remove the `enum` and let the JSON-RPC dispatcher
answer unsupported methods with `-32601 Method not found` (spec-correct).
Also register optional `session_id` in `args` (sanitize: `[a-f0-9-]{8,}`).

### 5.5 Cleanups

- `handle_sse_handshake()` in `WP_MCP_AI_REST` currently forces
  `stream=true` → directory stream. Point it at the new handshake instead;
  keep `GET /sse` (directory) as-is.
- CORS: `handle_mcp_options()` already allows `Mcp-Session-Id`; no change.

## 6. Security & operational safeguards

- **Session ownership**: POSTs must present the same credential hash as the
  GET that created the session; mismatches get 404 (mirrors SDK behavior,
  prevents session hijacking).
- **Unguessable IDs**: UUIDv4; never log raw session IDs with tokens.
- **Worker protection**: hard caps (5 sessions/credential, 20 total), 30-min
  absolute TTL, then the server closes the stream (clients reconnect with a
  fresh handshake — the reference server behaves the same way).
- **Queue caps**: max 50 pending responses/session; drop + log on overflow.
- **Rate/abuse**: existing REST rate limits and `WP_MCP_AI_Security_Posture`
  still apply; handshakes are authenticated-only.
- **Proxy behavior**: keepalives every 15s keep Cloudflare/nginx from killing
  the stream (Cloudflare idle timeout ~100s).

## 7. Verification plan

### 7.1 Automated

- PHPUnit: Accept-header discriminator matrix; session store lifecycle
  (create/owner-match/mismatch/expiry/queue-cap/registry GC); raw-cred auth
  parsing on/off via filter; `process_mcp_request_to_data()` returns identical
  arrays to the JSON path for initialize/tools/list/notifications/unknown-method.
- Existing suite (`composer run test`) must stay green; `composer run lint`.

### 7.2 Manual wire tests (curl)

```
GET  /mcp -H "Accept: text/event-stream" -H "Authorization: Bearer cred_.."
     -> 200 text/event-stream, event: endpoint with session_id
POST /mcp?session_id=<id>  (initialize)
     -> 202 empty
GET stream continues      -> event: message with initialize result
POST tools/list           -> event: message with 96 tools
GET /mcp (no Accept)      -> discovery JSON (regression)
POST /mcp (no session_id) -> JSON response (regression)
GET /sse                  -> directory stream (regression)
OPTIONS /mcp              -> CORS preflight (regression)
```

### 7.3 Definitive E2E

1. **Legacy path**: Python MCP SDK `sse_client(MCP_URL, headers=...)` →
   expect `INIT OK: Sophie 1.1.54`, `TOOLS LISTED: 96` (today this raises
   `SSEError`; after the fix it must pass).
2. **Streamable path (regression)**: `streamable_http_client(...)` →
   still `INIT OK` + 96 tools.
3. **Bridge (regression)**: `npx mcp-remote@latest <url> --header ...` →
   still connects via `StreamableHTTPClientTransport`.
4. **Cloudways Agent config**: with the raw-token acceptance enabled, the
   existing `url:` + `headers:` entry should list tools (their client treats
   `url` as SSE → now served the handshake; header accepted as-is).

### 7.4 Production soak (victory.nvdigital.solutions)

- Deploy during low traffic; watch PHP-FPM worker pool and Cloudflare
  `cf-ray` logs for long GETs; confirm keepalive prevents 524s beyond 100s
  (single 3-minute curl handshake test).
- Confirm the session registry drains to 0 after clients disconnect.

## 8. Rollout

- Ship behind `WP_MCP_AI_LEGACY_SSE_ENABLED` (default `true`); document the
  kill switch.
- Announce the `?stream=true` behavior change in the changelog.
- The only changed behavior for existing traffic is limited to requests that
  were already failing (SSE-only Accept) or explicitly opt-in.

## 9. Out of scope / follow-ups

- Heavy-tool server-side hangs (`remote_wp_connection`, `ezuite_erp` 0-byte
  responses) — separate root cause (external connection timeouts); SSE
  mitigates the symptom by decoupling response delivery from the POST request.
- `/sse` directory stream upgrade to a full legacy handshake — unnecessary
  once `/mcp` serves the handshake.
- Streamable HTTP session headers (`Mcp-Session-Id`) — not required by the
  stateless flow used by current clients.
