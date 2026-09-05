# NV oOS Security Checklist

> **GSD Context File** — Load this at the start of every AI development session.
> This checklist must be applied to **every code change** without exception.
> **Last reviewed:** September 5, 2026 (v1.1.71).
> **v1.1.71 updates**: **Fixed-window rate limiting** — `check_rate_limit()` uses a `{count, first_seen}` transient payload whose TTL is never extended past the window end (the old sliding window kept blocks alive indefinitely under steady traffic) and `retry_after` reports the remaining time, not the full window; legacy integer transients are normalized on read. **Rate-limit flagging** — when the limit trips, fire `wp_mcp_ai_rest_request_rate_limit_exceeded` (carrying the window-end timestamp) so `WP_MCP_AI_Restriction_Registry` flags a `rate_limit` restriction (scope `rest`, auto-release at window end) — blocked users must be liftable from the Restrictions tab; lifting also deletes the `wp_mcp_ai_rate_limit_user_{id}` transient. Guest (IP-keyed) blocks are not user-attached and expire on their own (PR #6322). **Salts** — never read `AUTH_KEY`/`SECURE_AUTH_KEY` constants directly for key material; derive `wp_salt( 'auth' ) . wp_salt( 'secure_auth' )` so installs without salt constants keep working (checkout-api fix, PR #6315).
> **v1.1.70 updates**: **exec/proc_open hardening** — on PHP 8+ a disabled function throws a fatal `Error` that `@` cannot suppress, so any shell call must first check `function_exists` and fall back to `WP_MCP_AI_Process_Service` (`proc_open`); use the canonical `wp_mcp_ai_check_nodejs_available()` / `wp_mcp_ai_get_nodejs_version()` helpers (`addons/pro/includes/npm-integration-filters.php`) instead of raw `@exec()` (PR #6295). **Acting-user authorization** — image tools check `read_post` against the acting user (`user_can( $user_id, … )`), not the global current user (cron/CLI/token-authenticated executions), and chart HTML attachments gate on the acting user's `unfiltered_html` (mirroring WP core) with `html` temporarily re-allowed around `wp_upload_bits()` (PRs #6305, #6311). **User-ID validation** — candidate IDs must validate as positive integers; `absint()` flips negatives into positives (`-1` → `1`) and switches to the wrong account (PR #6305); the same clamp applies to `assistant_id`/time-limit/passing-score sanitizers (`max( 0, … )`, never `absint()`, PRs #6295/#6303). **Auth0 audience validation** — audiences are API identifiers, not endpoints: validate structurally (`esc_url_raw` + host check) without DNS resolution; the Auth0 domain keeps the full `wp_http_validate_url` SSRF guard (PRs #6301, #6305). **Mesh peer URLs** — validate the raw input with `FILTER_VALIDATE_URL` **before** `esc_url_raw()` (scheme-less strings were silently prefixed with `http://` and accepted, PR #6285). **Webhook IDs** — generate `sanitize_key`-stable (lowercase) IDs and keep dotted event names (`sanitize_text_field`) so unsubscribe/dispatch agree at the REST boundary (PR #6311). **Settings gate reads** — `WP_MCP_AI_Settings_Repository::get()` falls back to the canonical `wp_mcp_ai_settings` blob so runtime gates (request guard, security manager, destructive-ops gate) see dashboard-saved values like IP blacklists; per-key values still win and the fallback is not cached (PR #6311).
> **v1.1.69 updates**: **ZipSlip guard revival** — `ZipArchive::$num_files` is not exposed on PHP 8.x, so the old `for ( $i = 0; $i < $zip->num_files; $i++ )` loops silently never ran; the OKF bundle manager and four Pro admin pages (Comic/Media Consolidate, Skill Manager ×2) must iterate `count( $zip )` to reject `../` archive entries (PR #6270, security-relevant: the guard was dead code). **Vision Analysis toolkit SSRF rule** — remote image URLs passed to `analyze_image_objects` go through the shared SSRF URL guard before fetch; keep that when adding new image sources (PR #6267). **Rate-limiter classification** — `check_rate_limit()` classifies internal dispatches by the dispatching request's real HTTP verb, not the ambient `$_SERVER['REQUEST_METHOD']`; GET/HEAD stay exempt; the nefarious monitor keeps its own `wp_mcp_ai_nefarious_rate_limit_` counter (sharing the chat limiter's counter halves the configured budget) (PR #6265). **Tool schema encoding** — `properties` must encode as a JSON object (`{}`), never an empty array (`[]`); `LegacyToolAdapter` preserves object maps and upgrades empty arrays (PR #6272).
> **v1.1.68 updates**: **Session nonce endpoint** — `GET /mcp-ai/v1/session/nonce` is nonce/capability-free by design (the value it returns is already embedded in every page of the site), mints from the request's own auth cookie, and sends `no-cache`/`no-store` headers; keep those three properties if you touch it (F-AUTHZ-01 class — the `__return_true` carries a justification comment). **Provider enable defaults** — fresh installs now disable OpenAI/Anthropic/Gemini by default; the onboarding wizard auto-enables the provider whose key is entered, and provider dropdowns list only enabled + credentialed providers (`WP_MCP_AI_Model_Config::get_available_providers()`) — keep that invariant when adding providers (PR #6255). **Dependency security** — `fast-uri` override bumped to >=4.1.4 across root/Pro/SaaS npm trees (PR #6244); Tiptap pinned to 3.30.4 in canvas-toolkit + document-editor (`mergeAttributes` prototype pollution, PR #6250) — keep the pins on security bumps. **Test campaign rule** — the Sep 3 PHPUnit campaign wave (~21 PRs) never weakened security assertions to make a suite pass; keep that invariant.
> **v1.1.67 updates**: **Destructive-ops gate settings source** — `require_confirm_destructive_ops` is now read from the canonical combined-settings array (written by the admin UI) with a fallback to the legacy settings-repository option and a fail-safe enabled default (PR #6151); any new confirmation-gated tool must keep reading through the gate, not a hand-rolled option. **WhatsApp webhook signatures** — signature validation now **rejects** when no App Secret is configured (previously allowed) (PR #6192); keep that fail-closed behavior. **Crawler job contract** — `base_url` values are URL-validated and task IDs sanitized before dispatch, and `spawn_cron()` is filterable via `wp_mcp_ai_crawl4ai_auto_spawn_cron` (PRs #6124/#6125); new crawler jobs must pass the same validation. **Test campaign rule** — the Sep 1–2 PHPUnit campaign (~95 PRs) never weakened security assertions to make a suite pass; keep that invariant.
> **v1.1.66 updates**: **REST permission-callback allowlist** — the allowlist used by the REST permission audits was refreshed (PR #6019); new state-changing routes must keep landing on it (permission_callbacks must never be `__return_true` without a justification). **Token-tier endpoint + audit** — the token-tier endpoint and tier-change audit logging were fixed (PR #6018): every tier change must keep writing the audit trail. **Guest tokens are origin-bound** (audit F-AUTHZ-04) — test requests and any new issuer must carry the site's own Origin. **Bearer-auth context sync** — the Simple JWT and assistant-access paths now sync bearer-auth context consistently (PRs #6014, #6016); do not regress the two-path sync. **Test campaign rule** — the Aug 28–31 PHPUnit campaign (~100 PRs) never weakened security assertions to make a suite pass; keep that invariant.
> **v1.1.65 updates**: **Algorave Tone.js raw eval (F-AI-01)** — when the operator opted into the raw-eval Tone.js engine, pasted code requires one explicit confirmation per browser session plus a visible warning banner; the permission flag is capability-scoped from PHP via `nvoosAlgoraveConfig` — keep that gate whenever extending the live coder (Strudel, the sandboxed default, must never need it). **Google Chat webhooks** — when `disable_oidc_verification` is on, the connection MUST carry a `verification_token`; requests authenticate via `?token=` or `X-Google-Chat-Token` — never accept a completely unauthenticated OIDC-disabled webhook. **Orphaned tool messages** — the REST layer now silently discards tool messages whose `tool_call_id` doesn't pair with an assistant call (`filter_tool_messages_without_matching_calls()`) and the validator no longer enforces the pairing enum — this is payload tolerance, not an auth/capability relaxation; do not reintroduce a hard 400 at the REST args gate for pairing. **Webhook `__return_true` callbacks** — legitimately-public callbacks carry inline justification comments (F-AUTHZ-01); new public webhooks must keep the justification pattern and stay scoped to public-by-design endpoints.
> **v1.1.64 updates**: **Log redaction** — the shared redactor now masks 26 credential-bearing URL query-parameter names inside any logged string (a one-time Composio Connect Link `state` grant was reaching `wp_mcp_ai_recent_activity` in plaintext); any new logging path must pass through the same redactor (never hand-rolled `error_log` of URLs). **Sensitive result fields** — tools carrying capability credentials under innocuous keys (or opaque URL path segments) must declare them via `WP_MCP_AI_Tool_Sensitive_Result_Interface::get_sensitive_result_fields()`; masking is **logging-only** and the `wp_mcp_ai_tool_sensitive_result_fields` filter is additive-only (it can shield third-party tools but never weaken a declaration). **Log buffers** — persistence-path byte budgets (fingerprinting `assistant_config`/`system_prompt`, truncation) only affect what is *stored* in `wp_mcp_ai_recent_errors`/`wp_mcp_ai_recent_activity`; the `wp_mcp_ai_log_entry` filter and `error_log()` still receive the full sanitized context — never move raw secrets into the stored buffers. **Composio** — account health verdicts are advisory (probe-verified ≠ trust; never bypass capability checks on `verified`), app removal is nonce-gated and revokes the upstream grant, and proxied 401/403 must keep mapping to `wp_mcp_ai_composio_account_auth_required`. **Google Calendar** — every Calendar write must pass `WP_MCP_AI_Google_Calendar_Scopes` scope enforcement; new Google integrations must build on `includes/google/` instead of copy-pasting an OAuth start/callback pair.
> **v1.1.63 updates**: **Artifact evolution (Phases A–G) is a self-modification surface — every layer defaults off and stays opt-in** (proposal 007): new evolution work must keep the pre-commit admission gate (structural/harmlessness/marginal-gain critics) before any artifact is admitted, route deploys through the holdout/shadow gate + human approval queue, and budget every mutation path through `WP_MCP_AI_Evolution_Governor` (shared hourly budget, per-path rate limits, site-wide cap) — no bypasses, no default-on. Refiner output must stay PII-scrubbed + sanitized before storage. **Addons admin page** (`WP_MCP_AI_Addons_Page`): one-click install/activate AJAX is gated by nonce + `install_plugins` + an explicit allowlist — new installable addons must be allowlisted, never auto-install arbitrary slugs. **Test-seam guards** added to four admin handlers (`WP_MCP_AI_TESTS_RUNNING`) change *exit behavior only* (catchable exceptions in tests) — they must never weaken nonce/capability checks or change production control flow. **Tool schemas:** never reintroduce `"properties": []` — empty property maps must encode as `{}` (DeepSeek 400s). Toolkit MCP scope enforcement is OAuth-only (null scope passes for cookie/token/Auth0/mesh auth) and resource URIs must not be run through `esc_url_raw()` (it strips `nvoos://`).
> **v1.1.62 updates**: Vector store tools migrated off the Assistants API ahead of OpenAI's **2026-08-26** removal — do not reintroduce the `OpenAI-Beta: assistants=v2` header in `WP_MCP_AI_OpenAI_Client` or `lib/core` vector tools; file ingestion uses the Responses `file_batches` endpoint with bounded polling (`wp_mcp_ai_vector_store_batch_poll_max_seconds`) and a headerless fallback. New Pro REST surface `mcp-ai-pro/v1/okf` is **read-only** — bundle list/stats, concept browse/search, and assistant skill grants only; any future write routes must keep `manage_options` gating. `WP_MCP_AI_OKF_Bundle_Manager` is the single OKF filesystem authority: ZipSlip-safe import (symlink rejection, entry/size caps), `realpath` containment, protected `skill-knowledge` bundle, and `.htaccess`/`index.php` guards on the knowledge root — route all new OKF filesystem work through it. Percent-encoded OKF concept routes (`%2F`) are `rawurldecode()`d in the handler — keep `%` in the route pattern when extending.
> **v1.1.61 updates**: `undici` npm override pinned to ^7.29.0 across seven addons — jsdom 29.1.1 breaks on undici 8 (removed `lib/handler/wrap-handler.js`); keep addons on 7.x until jsdom compatibility resolves and re-check CVE coverage when the pin is revisited. `WP_MCP_AI_Agent_Identity_Resolver` persists an alias map in the `wp_mcp_ai_agent_id_aliases` site option — bounded (200), never autoloaded, every value sanitised; treat it as a cache of intent, not a source of truth. OKF skill-knowledge bundle generation writes into the uploads knowledge directory via `WP_MCP_AI_Filesystem_Service` (atomic writes) — no new file-handling paths outside that service.
> **v1.1.60 updates**: Restricted-user flagging — `WP_MCP_AI_Restriction_Registry` persists rate-limit / token-budget blocks as reviewable records (user meta + `wp_mcp_ai_active_restrictions` index, daily cleanup cron, audit-logged) and the OOS `RateLimiter` adapter now fires `wp_mcp_ai_rate_limit_exceeded`; new REST restriction routes and AJAX lift actions MUST gate on `manage_options` (never `__return_true`); rate-limited REST responses carry IETF `RateLimit-Policy` / `RateLimit` / `Retry-After` headers via `WP_MCP_AI_Rate_Limit_Headers`; chat rate limits are filterable (`wp_mcp_ai_chat_rate_limit` / `wp_mcp_ai_chat_rate_limit_window`) — do not hardcode 60/min in new paths; conversation-import tools are JetEngine-gated and `manage_options`-only, and imported transcripts feed GDPR export/erase (keep retention scoping when extending).
> **v1.1.59 updates**: Media Worker v3.2.0 crawl endpoints — every crawl/crawl4ai URL passes the shared SSRF guard (`utils/safe-url.js`) before fetch or navigation; keep it that way when extending crawl features. New `wp_mcp_ai_plugin_updated` action fired by the copy-in-place plugin updater (core never fires `upgrader_process_complete` for these updates) — addons caching plugin files must subscribe and scope invalidation to NV-oOS plugins (Docs Hub 0.4.1 pattern). Tool registry now wraps legacy-format tool classes transparently and tracks skipped tools in `unavailable_tool_slugs` — registration-side changes only, no auth/capability relaxation.
> **v1.1.58 updates**: OOS runtime consolidation Phases 0–5.8 — security-gate parity (`ToolGuardInterface`) so the OOS path enforces the same capability/destructive-op checks as the legacy path; shadow mode + canary routing default off (`wp_mcp_ai_oos_shadow_enabled()` / `wp_mcp_ai_oos_canary` gates, audit-logged parity runs, zero user exposure); Composio Connect — OAuth state nonce + token storage in the auth handler and signature-verified webhook ingestion (verify both before extending); `deepmerge-ts` CVE-2026-40345 overridden in media-worker + Pro packages.
> **v1.1.57 updates**: Plugin updater rework — copy-in-place install with backup/rollback replaces `Plugin_Upgrader` (live directory never renamed; nonce-scoped base-update AJAX actions); Service Status provider detection via `WP_MCP_AI_Credential_Resolver` (settings + WP 7.0 Connectors + env + constants); Hermes WebUI chat async submit/poll; MCP `prompts/list`/`prompts/get` scoped to the authenticated assistant (cross-assistant prompt leakage prevented); Media Worker Dependabot fixes — puppeteer ^25.7.0 (removes the unpatched `extract-zip` chain, GHSA-jmr9-qjv8-65gv), `uuid` override ^11.1.1 (GHSA-w5hq-g745-h8pq), Node engine floor ≥22.12.0.
> **v1.1.56 updates**: Media Worker v3.0.0 — multi-tenant shared worker mode (`SITE_TOKENS` fail-closed auth, per-site rate limits, token rotation), per-site provider keys (`SITE_PROVIDER_KEYS`, `PROVIDER_KEYS_STRICT`), opt-in Redis rate-limit store (`RATE_LIMIT_REDIS=1`), `PROVIDER_KEYS_FILE` hot-reload, zero-downtime rotation (`WORKER_API_TOKEN_PREVIOUS`), Canvas v3 napi prebuilds; worker routing with local fallbacks; Hermes MCP bridges (env-file parser hardening).
> **v1.1.55 updates**: MCP JSON-RPC errors return HTTP 200 (SDK compat), settings-driven tool rate limiter with credential-token exemption, GET/HEAD quota exemption, raw `cred_*` header acceptance, legacy HTTP+SSE transport with credential-bound session store, Media Worker v2.2.0 sidecar hardening, database connection pooling stance (Proposal 023), PostCSS >=8.5.26 (GHSA-6g55-p6wh-862q).
> **v1.1.54 updates**: PostCSS CVE-2026-69153 resolved, API key merged-settings enforced in 20 research tools, plugin updater integrity check v2 (stat cache fix).

---

## Pre-Implementation Security Review

Before writing any code, confirm:
- [ ] Authentication method identified (WordPress Nonce / Bearer token / Guest token)
- [ ] Required capabilities defined for each operation
- [ ] Data flow mapped (what user input reaches the database/API)
- [ ] Third-party API credentials storage confirmed (encrypted meta, never plain text)
- [ ] File upload requirements identified (MIME validation needed?)

---

## Input Sanitization (Required for All User Input)

> **Tools — the two-gate rule (Unix Theory Compliance §2.6, Phase P6):** Every
> `$arguments` value is sanitised at the **top of `execute()`** before any
> business logic (Gate 1), and every value returned in the canonical-envelope
> `data` array is escaped on its way out (Gate 2). The repo enforces the
> highest-risk Gate-1 violations via the PHPCS sniff
> `WPMCPAI.Tools.SanitizeAtEntry`. See the codification document at
> [`docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`](../docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md)
> for the canonical sanitiser/escaper allow-list and the sniff's scope.

### Use the Right Function

| Input Type | Function |
|-----------|---------|
| General string | `sanitize_text_field()` |
| Multiline text | `sanitize_textarea_field()` |
| Integer | `absint()` or `intval()` |
| Float | `(float)` cast + range check |
| Email | `sanitize_email()` |
| URL (for storage) | `esc_url_raw()` |
| HTML content | `wp_kses_post()` or `wp_kses( $data, $allowed )` |
| Slug/key | `sanitize_key()` |
| File name | `sanitize_file_name()` |
| SQL value | `$wpdb->prepare()` — never string-concatenate |
| Array/JSON | Sanitize each value individually |

### Common Mistakes to Avoid
- ❌ `$_POST['data']` without sanitization
- ❌ `$_GET['id']` passed directly to a query
- ❌ `json_decode( $_POST['json'] )` without sanitizing values
- ✅ `absint( $_POST['id'] )` before any use
- ✅ `sanitize_text_field( wp_unslash( $_POST['name'] ) )`

---

## Output Escaping (Required for All Output)

### Use the Right Function

| Output Context | Function |
|---------------|---------|
| Plain text in HTML | `esc_html()` |
| HTML attribute value | `esc_attr()` |
| URL in href/src/action | `esc_url()` |
| Inline JavaScript string | `esc_js()` |
| JSON output | `wp_json_encode()` |
| HTML content (trusted) | `wp_kses_post()` |
| Translation strings | `esc_html__()`, `esc_attr__()` |

### Common Mistakes to Avoid
- ❌ `echo $variable;` — always escape
- ❌ `echo get_option( 'wp_mcp_ai_title' );` — escape output
- ✅ `echo esc_html( get_option( 'wp_mcp_ai_title' ) );`
- ✅ `echo esc_url( $url );`

---

## Capability Checks

Every privileged operation must check capability BEFORE execution:

```php
// In tool execute() methods:
if ( ! current_user_can( $this->get_required_capability() ) ) {
    return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
}

// In REST endpoint permission_callback:
public function check_permissions() {
    return current_user_can( 'edit_posts' );
}

// In AJAX handlers:
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ) );
    wp_die();
}
```

### Capability Reference

| Operation | Required Capability |
|-----------|-------------------|
| Read public content | (none — open) |
| Read own content | `read` |
| Create/edit posts | `edit_posts` |
| Manage plugin settings | `manage_options` |
| Delete content | `delete_posts` |
| Manage users | `manage_options` |
| Custom admin ops | `manage_options` |

---

## Nonce Verification

All state-changing requests (POST, AJAX, form submissions) MUST verify a nonce:

```php
// Generate nonce (in template/form):
wp_nonce_field( 'wp_mcp_ai_action', 'nonce' );
// Or via localization:
'nonce' => wp_create_nonce( 'wp_mcp_ai_action' )

// Verify in AJAX handler:
check_ajax_referer( 'wp_mcp_ai_action', 'nonce' );

// Verify in form handler:
if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wp_mcp_ai_action' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
}
```

### REST API Authentication
REST endpoints use `permission_callback` — never skip it:

```php
array(
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => array( $this, 'handle_request' ),
    'permission_callback' => array( $this, 'check_permissions' ),
)
```

---

## File Upload Security

When handling file uploads:

```php
// Validate MIME type:
$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif' );
$file_type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
    return new WP_Error( 'invalid_type', __( 'File type not allowed.', 'mcp-ai-wpoos' ) );
}

// Use wp_handle_upload() — never move files manually:
$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
```

---

## API Key & Credential Storage

- ✅ Store encrypted via `WP_MCP_AI_Encryption` helper (if available)
- ✅ Store in WordPress options with `wp_mcp_ai_` prefix
- ❌ Never log API keys (not even partial keys in error messages)
- ❌ Never commit credentials to source control
- ❌ Never expose API keys in REST API responses or JS globals

---

## Database Security

```php
// Always use $wpdb->prepare() for dynamic queries:
$results = $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM %i WHERE user_id = %d AND status = %s',
        $wpdb->prefix . 'my_table',
        $user_id,
        $status
    )
);

// Never:
$wpdb->query( "SELECT * FROM $table WHERE id = $id" ); // ❌ SQL injection risk
```

---

## External HTTP Requests

```php
// Always use wp_safe_remote_get/post for user-provided URLs — never curl directly:
$response = wp_safe_remote_post(
    esc_url_raw( $api_url ),
    array(
        'timeout' => 30,
        'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        'body'    => wp_json_encode( $data ),
    )
);

if ( is_wp_error( $response ) ) {
    return $response;
}

$body = wp_remote_retrieve_body( $response );
$data = json_decode( $body, true );
```

---

## Advanced Patterns (Added July 2026)

### HMAC-Signed Policy Tokens

When server-controlled configuration crosses a client boundary (e.g. shortcode attrs → JS → AJAX handler), use an HMAC-signed policy token instead of sending raw config values:

```php
// Server — generate token:
$payload = wp_json_encode( array(
    'assistant'          => $assistant_id,
    'allow_sensitive'    => false,
    'exp'                => time() + HOUR_IN_SECONDS,
) );
$policy_token = base64_encode( $payload . '|' . wp_hash( $payload ) );

// Client — send token (never reconstruct raw attrs)
// Server — verify token:
list( $json, $hmac ) = explode( '|', base64_decode( $policy_token ), 2 );
if ( ! hash_equals( wp_hash( $json ), $hmac ) ) {
    return new WP_Error( 'invalid_token' );
}
$data = json_decode( $json, true );
if ( $data['exp'] < time() ) {
    return new WP_Error( 'expired_token' );
}
```

Reference: `class-wp-mcp-ai-professional-selector-shortcode.php`.

### Path Traversal Prevention (realpath Containment)

Before any recursive filesystem operation:

```php
$resolved = realpath( $target_path );
$base     = realpath( wp_upload_dir()['basedir'] );
if ( false === $resolved || 0 !== strpos( $resolved, $base ) ) {
    // Log security event, abort operation
    return new WP_Error( 'path_traversal' );
}
```

Reference: `class-wp-mcp-ai-optional-components.php` (ZIP validation), `class-wp-mcp-ai-pro-privacy.php` (directory deletion).

### Admin-Post CSRF Protection

`admin-post.php` endpoints must verify a nonce:

```php
// In the handler at entry:
check_admin_referer( 'wp_mcp_ai_{toolkit}_sync' );

// In the inline JS that builds the URL:
url += '&_wpnonce=' + '<?php echo wp_create_nonce( 'wp_mcp_ai_{toolkit}_sync' ); ?>';
```

Reference: `class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php`, `class-wp-mcp-ai-ezuite-toolkit-settings-page.php`, `class-wp-mcp-ai-flowhub-toolkit-settings-page.php`.

---

## Security Infrastructure (v1.1.42+)

The plugin ships 7 security infrastructure classes in `includes/security/`. When working near REST dispatching, error handling, or destructive operations, reference these classes for canonical patterns:

### Request Guard

`WP_MCP_AI_Request_Guard::wrap_dispatch()` hooks into `rest_dispatch_request` (WP >= 6.5 signature: 5 params: `$result, $wp_rest_server, $request, $route, $handler`). Provides:
- SSE connection slot limiting
- JSON depth enforcement
- Request body size enforcement
- Error verbosity filtering (Safe/Moderate/Debug tiers)
- Asset version stripping (`?ver=` query string removal)

### Security Posture

`WP_MCP_AI_Security_Posture` computes a 0-100 weighted score from 23 base signals (Pro adds more via the filter). Cached (5-minute TTL). Filter: `wp_mcp_ai_security_posture_signals`. New in v1.1.60: `restriction_registry_on` — informational signal reflecting whether the restriction registry is active.

### Destructive Ops Gate

`WP_MCP_AI_Destructive_Ops_Gate` enforces confirmation gates for irreversible operations (bulk delete, mass email, etc.).

### Other Guards

- `WP_MCP_AI_URL_Guard` — URL validation/sanitization before outbound requests
- `WP_MCP_AI_Concurrency_Guard` — prevents overlapping destructive operations; wired into execution pipeline (v1.1.53) to auto-throttle tool calls under load
- `WP_MCP_AI_Cost_Tracker` — per-operation cost estimation and budget enforcement; enforced at pipeline level (v1.1.53)
- `WP_MCP_AI_Api_Key_Store` — encrypted at-rest API key storage
- **Circuit breaker protection** (v1.1.53) — all 15 AI provider clients have configurable failure thresholds and cooldown periods
- **MCP tool rate limiter** (v1.1.55) — settings-driven (`tool_rate_limit_max` / `tool_rate_limit_window` / `tool_rate_limit_exempt_tokens`); credential-token traffic exempt by default; GET/HEAD exempt from the request quota
- **MCP JSON-RPC error semantics** (v1.1.55) — JSON-RPC errors return HTTP 200 with the error envelope so agent SDKs that drop non-2xx bodies relay tool errors instead of hanging; auth/permission failures keep real HTTP statuses (401/403/429)
- **MCP raw credential headers** (v1.1.55) — `Authorization: cred_*` without `Bearer` accepted for verbatim header forwarding; filter `wp_mcp_ai_accept_raw_credential_header`
- **Legacy MCP HTTP+SSE transport** (v1.1.55) — credential-bound sessions via `WP_MCP_AI_SSE_Session_Store`, gated by `WP_MCP_AI_LEGACY_SSE_ENABLED`
- **Media Worker v2.2.0** (v1.1.55) — timing-safe `X-Site-Token` auth, SSRF guard, sandboxed Puppeteer, rate limiting, Helmet headers; `WP_MEDIA_WORKER_TOKEN` constant in the plugin client
- **Media Worker v2.4.0 → v3.0.0** (v1.1.56) — multi-tenant fail-closed auth (`SITE_TOKENS`, `AUTH_MODE=strict`), per-site provider keys (`SITE_PROVIDER_KEYS`), `PROVIDER_KEYS_FILE` hot-reload, opt-in Redis rate-limit store, zero-downtime token rotation (`WORKER_API_TOKEN_PREVIOUS`), Canvas v3 napi prebuilds
- **Connection pooling stance** (v1.1.55) — atomic concurrency slots (`mcp_ai_concurrency_slots`), RabbitMQ gating of Action Scheduler fallback and DB polling cron, PDO persistence in the Content Graph standalone plugins (formerly Graphify)
- **PostCSS GHSA-6g55-p6wh-862q** (v1.1.55) — `postcss` minimum bumped to >=8.5.26 in `addons/schedule-anything-spa/package.json`
- **PostCSS CVE-2026-69153** (v1.1.54) — `postcss` minimum bumped to 8.5.23; affects CSS build chain across addons
- **API key merged-settings** (v1.1.54) — 20 research tools now use `get_merged_credentials()` honoring per-assistant/provider overrides
- **Post-install integrity check** (v1.1.52) — verifies 15 critical file paths after every plugin update
- **REST require_once guards** (v1.1.52) — `file_exists()` checks before all REST controller `require_once` calls

Reference: `includes/security/README.md`, `docs/operations/production-hardening-guide.md`.

---

## ABSPATH Guard (Every Non-Root PHP File)

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

---

## Per-Commit Security Checklist

Before every commit, verify:
- [ ] All user input sanitized with appropriate function
- [ ] All output escaped with appropriate function
- [ ] Capability check present before every privileged operation
- [ ] Nonce verified for every state-changing request
- [ ] No credentials or API keys in source code
- [ ] ABSPATH guard on every new PHP file (except root plugin file)
- [ ] `$wpdb->prepare()` used for all dynamic database queries
- [ ] External HTTP requests use `wp_remote_*` functions
- [ ] File uploads use `wp_handle_upload()` with MIME validation
- [ ] `permission_callback` defined for every new REST endpoint
