# NV oOS REST API Patterns

> **GSD Context File** — Load this when working on REST API endpoints.
> Last reviewed: September 3, 2026 (v1.1.68).
>
> **New in v1.1.68 (PR #6225):** the chat controller exposes `GET /mcp-ai/v1/session/nonce` — deliberately `__return_true` + nonce-free because the returned nonce is already embedded in every page of the site, it mints the nonce from the request's own auth cookie (`wp_get_session_token`), and it sends `no-cache, no-store, must-revalidate` headers so edge caches never hand one user's nonce to another (F-AUTHZ-01 class: keep the justification comment if you touch it). The chat client retries `403 rest_cookie_invalid_nonce` failures against it instead of surfacing "Cookie check failed".
>
> **New in v1.1.67 (PRs #6141, #6172, #6186, #6187):** the assistant-directory REST handler guards an empty `title` param (#6141); tool-error reporting is null-safe when the request object is absent (#6186); LLM payload sanitization is delegated to `WP_MCP_AI_REST_Validator` (`sanitize_metadata_for_llm()` recursion + transport-field stripping — `headers`/`raw`/`response`/`request`/`retrieved_at`/`fetched_at`/`user_agent`) instead of private REST helpers (#6187); transcript `display` metadata segments are sanitized (`sanitize_display_metadata()` — `bubbleType` via `sanitize_key()`) and persisted (#6172).
>
> **New in v1.1.66 (PRs #6008, #6009, #6014–#6019, #6036, #6037):** chat-endpoint validation tests reconciled with the current contract — role validation lives in `sanitize_messages()`, orphaned tool messages are discarded, and attachments are embedded message segments (never re-add the top-level `attachments` arg or the args-layer role/pairing enums); attachment-segment validation errors now carry an explicit `status => 400` instead of defaulting to 500. Assistant-access caching: the define-time `WP_MCP_AI_DISABLE_CACHE` pattern was replaced by a `wp_mcp_ai_assistant_access_cache_enabled` filter, and `validate_assistant_access()` caches `WP_Error` results like successful lookups. REST analytics endpoints, the token-tier endpoint + tier-change audit logging, the permission-callback allowlist, and bearer-auth context sync (Simple JWT + assistant-access paths) were fixed. Job-notifier REST routes resolve dot-IDs with owner-scoped auth.
>
> **New in v1.1.65 (PRs #5987, #5991, #5993, #5994):** the chat route no longer declares a top-level `attachments` arg — embedded content segments are authoritative and undeclared params are ignored, so legacy clients that still send `attachments` are tolerated instead of hard-400'd; attachment segment preparation errors now propagate to the client (`WP_Error` returned) instead of silently dropping the segment. Role enum validation moved from the REST args validator to the sanitize layer so the `wp_mcp_ai_allowed_message_roles` filter sees custom roles. Orphaned tool messages (missing/mismatched `tool_call_id`) are silently discarded by `WP_MCP_AI_REST::filter_tool_messages_without_matching_calls()` before dispatch. Transcript pagination uses a sign-preserving `sanitize_signed_page_number()` callback (absint() flipped negatives to positives, defeating the clamp-to-default). Legacy `input_text` content segments are normalized to `text` in the validator (documented compatibility behaviour).
>
> **New in v1.1.64 (PR #5957):** the `/mcp` route no longer declares `jsonrpc`/`method` as `required` REST args — doing so made WordPress answer `rest_missing_callback_param` before the callback ran and turned `handle_mcp_request()`'s spec-correct `-32700`/`-32600` JSON-RPC envelopes into dead code. Let protocol validators own malformed-request handling: REST args stay permissive on raw protocol routes, and the JSON-RPC handler validates `jsonrpc` + `method` itself. The MCP Server Diagnostics page, its assets, and both AJAX handlers were orphaned by the entry-file rename — `WP_MCP_AI_MCP_Server_Diagnostic::init()` is wired again.

---

## REST API Namespace

```
/wp-json/mcp-ai/v1/
```

All NV oOS endpoints use this namespace.

---

## Registering an Endpoint

In the REST controller class (extends `WP_REST_Controller`):

```php
public function register_routes() {
    register_rest_route(
        'mcp-ai/v1',
        '/my-resource',
        array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_items' ),
                'permission_callback' => array( $this, 'check_read_permission' ),
                'args'                => $this->get_collection_params(),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_item' ),
                'permission_callback' => array( $this, 'check_write_permission' ),
                'args'                => $this->get_create_item_params(),
            ),
        )
    );
}
```

---

## Permission Callbacks (Never Skip)

```php
/**
 * Check read permissions.
 *
 * @param WP_REST_Request $request Full request data.
 * @return bool|WP_Error
 */
public function check_read_permission( $request ) {
    return current_user_can( 'read' );
}

/**
 * Check write permissions.
 *
 * @param WP_REST_Request $request Full request data.
 * @return bool|WP_Error
 */
public function check_write_permission( $request ) {
    return current_user_can( 'edit_posts' );
}
```

---

## Authentication Methods

NV oOS supports three authentication methods:

### 1. WordPress Nonce (Same-Origin)
```javascript
// In JS:
headers: { 'X-WP-Nonce': wpApiSettings.nonce }
```

### 2. Assistant Bearer Tokens
```http
Authorization: Bearer cred_xxxxx.SECRET
```
Credentials are stored hashed in post meta. Generated via `WP_MCP_AI_Credential_Manager`.

Since v1.1.55 the raw credential form is also accepted on MCP routes
(`Authorization: cred_xxxxx.SECRET` without the `Bearer ` prefix) for agent
configs that forward the header verbatim (Cloudways Agent, etc.). Gated by
filter `wp_mcp_ai_accept_raw_credential_header` (default `true`).

### 3. Guest Tokens
```http
X-WP-MCP-AI-Guest: <guest-token>
```
Temporary tokens for public chat surfaces; expire after 24 hours.

---

## MCP Endpoint Transport & Error Semantics

`POST /mcp-ai/v1/mcp` is the JSON-RPC 2.0 endpoint (Streamable HTTP). Two
client classes exist and are distinguished on GET by the `Accept` header:

- **Streamable HTTP** — `Accept` contains `application/json` → GET returns
  the discovery JSON, POST returns JSON-RPC over JSON.
- **Legacy HTTP+SSE** (v1.1.55+, `WP_MCP_AI_LEGACY_SSE_ENABLED`) —
  SSE-only `Accept: text/event-stream` or `?stream=true` → GET serves an
  `event: endpoint` handshake with a credential-bound session
  (`WP_MCP_AI_SSE_Session_Store`); POST with `session_id` returns 202 and
  the JSON-RPC response is delivered as `event: message` on the GET stream.

Rules for REST/controller code:

1. **JSON-RPC errors must return HTTP 200** with the `{"jsonrpc","id","error"}`
   envelope (`mcp_error_response()`). Client SDKs drop non-2xx bodies, so a
   500 turns a tool error into a silent hang for the agent. Auth/permission
   failures and pre-dispatch guards keep real HTTP statuses (401/403/429).
   Filter: `wp_mcp_ai_mcp_error_http_status`.
2. **GET/HEAD must not consume the request rate-limit budget**
   (`check_rate_limit()` exempts them) — discovery/SSE probe retry loops
   would otherwise exhaust the hourly quota.
3. **Tool rate limiting** is settings-driven (`tool_rate_limit_max` /
   `tool_rate_limit_window` / `tool_rate_limit_exempt_tokens`) and exempts
   credential-token traffic by default. Constants `TOOL_RATE_LIMIT_MAX` /
   `TOOL_RATE_LIMIT_WINDOW` remain only as fallbacks.
4. **`GET /sse` is a directory stream, not an MCP message channel** — legacy
   SSE clients get their handshake from GET `/mcp` (SSE-only Accept), never
   from `/sse`.
5. **Direct `tools/call` completes synchronously** — direct MCP clients
   (Hermes, Zed bridges, Claude Desktop) consume the JSON-RPC result inline
   and cannot poll background jobs, so `mcp_tools_call()` sets
   `agentic_loop` and non-background tools return their result in the same
   response. The async poll budget (`mcp_wait_for_async_tool()`, ~45s =
   15 polls × 3s, with `kick_inline_if_stale()`) applies to background-only
   tools (Priority 1) and other async paths. Do not raise the poll budget
   past ~30 polls on Cloudflare-proxied sites (524 at ~100s).
6. **`prompts/list` and `prompts/get` are assistant-scoped** — both resolve
   the assistant the same way `tools/list` does (`resolve_assistant_id()` +
   `apply_token_assistant_scope()`): token-bound credentials see only their
   own assistant, unscoped auth falls back to the site's default assistant,
   out-of-scope slugs return a not-found error, and no resolvable assistant
   yields an empty prompt list (never every assistant on the site).

See `docs/developer/implementation-plan-mcp-agent-compat.md` for the full
rationale and the `.agents/skills/mcp-ai-wpoos-plugin` skill for operational
client-configuration details.

---

## Request Parameter Validation

```php
/**
 * Returns schema for create item parameters.
 *
 * @return array
 */
public function get_create_item_params() {
    return array(
        'name'    => array(
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => 'rest_validate_request_arg',
        ),
        'post_id' => array(
            'required'          => false,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ),
    );
}
```

---

## Response Format

```php
// Success response:
return rest_ensure_response(
    array(
        'success' => true,
        'data'    => $prepared_data,
    )
);

// Error response:
return new WP_Error(
    'not_found',
    __( 'Resource not found.', 'mcp-ai-wpoos' ),
    array( 'status' => 404 )
);

// Paginated collection:
$response = rest_ensure_response( $items );
$response->header( 'X-WP-Total', $total );
$response->header( 'X-WP-TotalPages', $total_pages );
return $response;
```

---

## Server-Sent Events (SSE) Streaming

NV oOS uses SSE for streaming AI responses. Pattern:

```php
// Set headers:
header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
header( 'X-Accel-Buffering: no' );

// Send event:
echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
flush();

// Close stream:
echo 'data: [DONE]' . "\n\n";
flush();
```

---

## Existing Key Endpoints

| Method | Route | Description |
|--------|-------|-------------|
| POST | `/mcp-ai/v1/mcp` | MCP JSON-RPC 2.0 endpoint (Streamable HTTP); optional `session_id` param for legacy SSE sessions |
| GET | `/mcp-ai/v1/mcp` | Discovery JSON by default; legacy SSE handshake for SSE-only `Accept` (v1.1.55+) |
| GET | `/mcp-ai/v1/assistants` | List available assistants (SSE support) |
| POST | `/mcp-ai/v1/chat` | Send chat messages with streaming responses |
| POST | `/mcp-ai/v1/tools` | Execute tools directly |
| GET | `/mcp-ai/v1/sse` | Server-Sent Events streaming endpoint |
| GET | `/mcp-ai/v1/cron-status` | Snapshot of the current user's async tool jobs |
| GET | `/mcp-ai/v1/cron-status/stream` | SSE stream of `job:*` events for the Tasks Drawer |
| POST | `/mcp-ai/v1/cron-status/{job_id}/cancel` | Cancel a running async tool job (owner only) |
| POST | `/mcp-ai/v1/cron-status/{job_id}/retry` | Re-queue a failed/cancelled async tool job (owner only) |
| `*` | `/mcp-ai/v1/chat-memory/*` | Chat-client Memory Bridge proxy (6 routes — preferences, wake-up, recall, store, audit, `/{context_id}`) |
| `*` | `/mcp-ai/v1/paper-store` | Paper Store CRUD + search + import/export for remote site access (v1.1.52) |
| `*` | `/mcp-ai-pro/v1/catalogues/*` | Skill catalogue management (Pro) — discover/install skills from remote GitHub repos |
| `*` | `/mcp-ai-pro/v1/analytics/*` | Shared Analytics Service endpoints (Pro, v1.1.53) — cross-platform social/ecommerce analytics |
| GET | `/mcp-ai/v1/restrictions` | List active user-restriction records (v1.1.60) |
| GET/POST | `/mcp-ai/v1/users/{id}/restrictions` | List / add restrictions for a user (v1.1.60, `manage_options` on writes) |
| DELETE | `/mcp-ai/v1/users/{id}/restrictions/{type}` | Lift a restriction by type (v1.1.60, `manage_options`) |
| `*` | `/mcp-ai-pro/v1/okf/*` | Pro read-only OKF surface (v1.1.62): bundle list/health stats, concept browse + search, per-assistant skill grants — implemented by `WP_MCP_AI_Pro_REST_OKF` (`addons/pro/includes/rest/`), registered through the Pro module registry |

The cron-status routes are implemented by `WP_MCP_AI_REST_Tools_Controller` and
delegate to `WP_MCP_AI_Tool_Async_Executor::cancel_job()` / `retry_job()` /
`is_owned_by()`; the snapshot / stream routes fire OTel hooks
`wp_mcp_ai_chat_jobs_snapshot`, `wp_mcp_ai_before_chat_jobs_stream`, and
`wp_mcp_ai_after_chat_jobs_stream`; the cancel / retry routes fire actions
`wp_mcp_ai_chat_jobs_cancel`, `wp_mcp_ai_chat_jobs_retry`,
`wp_mcp_ai_job_cancelled`, and `wp_mcp_ai_job_retried`.

The chat-memory bridge is implemented by
`WP_MCP_AI_REST_Chat_Memory_Controller`; full reference:
[`docs/features/memory/chat-client-integration.md`](../docs/features/memory/chat-client-integration.md).

**v1.1.62 — Pro OKF REST surface:** `WP_MCP_AI_Pro_REST_OKF` exposes a
**read-only** `mcp-ai-pro/v1/okf` namespace for the Pro SPA v2 skills drawer:
bundle list/stats, concept browse + search, and per-assistant skill grants
(`_wp_mcp_ai_okf_concepts`). No write routes — any future write endpoints must
gate on `manage_options` (never `__return_true`). Concept-ID route segments
allow `%` and are `rawurldecode()`d in the handler (IDs containing `/` arrive
as `%2F` from the SPA client).

**v1.1.61 — agent identity bridging:** unscoped recall through the bridge
now merges buckets stored under virtual agent keys
(`WP_MCP_AI_Agent_Identity_Resolver`): each merged record carries a
`stored_under` stamp, the envelope carries `merged_sources`, and the merged
list is capped at the requested `limit` (default 25). Scoped wake-up /
recall failures retry once without the wing/room scope before the error
surfaces, and the `wake_up_context` graph bridge degrades to the transient
path on any failure.

The restriction routes are implemented by `WP_MCP_AI_REST_Restrictions_Controller`
(backed by `WP_MCP_AI_Restriction_Registry`); `WP_MCP_AI_Rate_Limit_Headers` adds
IETF rate-limit headers to rate-limited responses; full reference:
[`docs/features/security/user-restrictions.md`](../docs/features/security/user-restrictions.md).

---

## REST Controller File Location

```
includes/class-wp-mcp-ai-rest.php              # Core REST routing
includes/rest/class-wp-mcp-ai-{name}.php       # Individual controllers
addons/pro/includes/rest/class-wp-mcp-ai-{name}.php  # Pro controllers
```

---

## Testing REST Endpoints

```php
class Test_My_Endpoint extends WP_Test_REST_TestCase {

    public function setUp(): void {
        parent::setUp();
        $this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $this->user_id );
    }

    public function test_get_items_returns_200() {
        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/my-resource' );
        $response = rest_do_request( $request );
        $this->assertEquals( 200, $response->get_status() );
    }

    public function test_unauthenticated_request_returns_401() {
        wp_set_current_user( 0 );
        $request  = new WP_REST_Request( 'POST', '/mcp-ai/v1/my-resource' );
        $response = rest_do_request( $request );
        $this->assertEquals( 401, $response->get_status() );
    }
}
```
