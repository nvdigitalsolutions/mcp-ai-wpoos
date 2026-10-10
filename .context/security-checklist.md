# NV oOS Security Checklist

> **GSD Context File** — Load this at the start of every AI development session.
> This checklist must be applied to **every code change** without exception.
> **Last reviewed:** October 10, 2026 (v1.2.2).
> **v1.2.2 updates**: **Chat-profile read-only gate (PR #6977, Proposal 015 — the 11th `includes/security/` class)** — `WP_MCP_AI_Read_Only_Profile_Gate` hooks `wp_mcp_ai_before_tool_execution` at priority 0 **before** the destructive-ops gate (deny → ask → allow) and blocks tools carrying `write`/`state-changing`/`destructive`/`irreversible`/`financial-impact`/`access-control-change`/`mass-email`/`data-destruction` capability flags; profiles resolve **server-side per request** (user meta → site default → write fail-safe) so a client can never widen its own grant — client-sent profiles are honoured only for `wp_mcp_ai_change_chat_profile` (→ `manage_options`) holders, and the REST `GET/POST /mcp-ai/v1/chat-profile` endpoint is deliberately **not a tool** (no mid-run self-elevation); guests default to read-only; audit events `chat_profile_blocked`/`chat_profile_escalation_attempt`; documented Phase D gaps: async worker-side enforcement is queue-time only and the `confirm_write` one-shot override is deferred. **Google OAuth production readiness (PR #6981)** — Drive scopes unified on the non-sensitive `drive.file` scope across all four flows behind the `wp_mcp_ai_google_drive_oauth_scope` filter (removes Drive from the CASA verification surface; full-Drive reads stay available via the filter for verified/internal projects); the Calendar permission profile defaults to **Minimal** (non-sensitive scopes) for new installs; Gmail/Drive disconnect revokes the upstream token best-effort via the shared `WP_MCP_AI_Google_OAuth_Service::revoke()` (Limited Use deletion); publishing-status warnings land on the Gmail/Drive footers (7-day Testing token expiry); GA4 stays service-account based and Graphify's Drive driver intentionally keeps broad metadata scopes. **CORS same-origin enforcement (PR #6986)** — WordPress core `rest_send_cors_headers()` (`rest_pre_serve_request`, priority 10) reflects **any** `Origin` back with `Access-Control-Allow-Credentials: true` on every REST response, which silently overrode the Security → Network "Same Origin" setting; the new `WP_MCP_AI_CORS_Guard` (**12th** `includes/security/` class) hooks priority 20 and, in `site` mode, echoes only exact-allowlisted origins (site origin + the `cors_allowed_origins` setting + the `wp_mcp_ai_cors_allowed_origins` filter) — everything else gets the site's own origin + credentials off; `star` mode keeps core's reflection; **every CORS emitter must resolve through `WP_MCP_AI_CORS_Guard::resolve_allow_origin()`** (never hardcode `'*'` or `get_site_url()` on a new REST/SSE surface); the legacy `wp_mcp_ai_cors_allow_origin` filter stays the final override; default enforcement scope is `/mcp-ai/v1` only — widen `wp_mcp_ai_cors_guard_route_prefixes` for `/mcp-ai-pro/v1` SPA surfaces.
> **v1.2.1 updates**: **Docs Hub checkout upsell (PR #6963, addon 0.5.2)** — the wp.org Docs Hub plugin's commerce stack: the vendor checkout REST is **admin-only** (`manage_options`) with per-user throttling and an **already-licensed short-circuit** (a licensed install can never be charged twice); the Stripe.js bundle loads on demand and the modal collects ToS/refund consent + buyer email + EU VAT address; `== External Services ==` discloses the checkout servers + data sent; pending-intent recovery + manual ZIP download keep a usable escape hatch — extend the modal only through the same consent/recovery discipline. **MCP bridge-name header (PR #6956)** — `X-MCP-Bridge-Name` is sanitized + 120-char capped into `$context['bridge_name']` and is **purely informational — never used for authorization** (asserted in tests; the `wp_mcp_ai_mcp_server_identity` filter docblock forbids adding secrets to the identity payload).
> **v1.1.99 updates**: **Fleet Operator token masking (PR #6950)** — the External Operators page no longer prints freshly created operator tokens in plaintext: tokens render in a readonly `type="password"` input (`autocomplete="new-password"`) with Show/Hide + Copy (`navigator.clipboard` + `execCommand` fallback) and credential blocks collapse behind `<details>` — keep the masked-first presentation when extending that page (the console site still needs the updated fleet-operator build deployed). **Gateway OAuth resource server (PR #6945, addon-owned)** — the mcp-gateway addon's Phase 1 OAuth path adds RFC 9728 metadata + `WWW-Authenticate` challenges with `resource_metadata`/scopes, zero-dependency JWT validation (node:crypto, JWKS cache + rotation refetch, strict iss/aud/exp/nbf/scope, **fail-closed** on any fetch/parse failure), scope-based site binding (`site:<slug>`), and **no token passthrough** (OAuth tokens never travel upstream — the gateway keeps exchanging for per-site Fleet Operator tokens); everything stays inert (404 / unchanged 401s) until `GATEWAY_OAUTH_ISSUER` is set — keep the confused-deputy discipline on future gateway auth paths. **Worker synthetic external targets (PR #6941)** — heartbeat-less targets (`STATUS_EXTERNAL_TARGETS`) probe through the shared SSRF guard and the sweeper stays the single owner of status transitions — new status writers must keep that ownership invariant.
> **v1.1.98 updates**: **Unified Blueprints toolkit gating (PR #6933)** — the Pro blueprints page's both AJAX handlers (`install`, `get_details`) now reject toolkits the settings flags disable — `$toolkit_enable_keys` mirrors each per-toolkit import tool's `is_available()` gate, so the admin surface can no longer install blueprints for toolkits the site has disabled; keep the map in sync when a toolkit's availability gate changes. **Dependency sweep (PR #6931)** — bounded in-major bumps across 17 trees (axios ≥1.20.0 <2, brace-expansion@^1 ≥1.1.21 <2, source-map-js ≥1.2.2 <2, dompurify, proxy-addr, compression, joi, katex ^0.18.2, moment, simple-git ≥4.0.1 + @simple-git/argv-parser, basic-ftp, postcss-selector-parser@^7, webpack-dev-middleware) — 138 of 166 open alerts resolved with npm-pack evidence for each cross-version move; the 28 deferred items (postcss 6.x, sentry-pinned @opentelemetry/instrumentation-*, react-cosmos wdm 6.1.3, 11 no-patch advisories) are tracked in issue #6930.
> **v1.1.97 updates**: **`scan_assistant_security` (PR #6912)** — the new base tool audits assistant configurations with OWASP-informed checks and returns **masked secret evidence** — never echo raw secrets when extending its findings shape. **Hook profiles (PR #6912)** — the new `includes/hooks/class-wp-mcp-ai-hook-profiles.php` gates harness hooks by profile (minimal/standard/strict) with meta → filter → option → constant resolution, a master switch, and a kill list — guardrail code must resolve the profile, not assume the hook fires. **Guardrail parity (PR #6913)** — `apply_pre_response_render()` now runs the Output Guardrail + Citation Verifier at the final payload on **all three** chat surfaces (legacy non-streaming, legacy streaming's final SSE event, and the OOS handler), and the OOS handler translates the three gate exceptions to the canonical 428/429 envelopes via `WP_MCP_AI_REST::translate_gate_exception()` — a guardrail must **never** degrade to a 500 on either path; `bin/check-chat-parity.php` (35 → 38 features, `parity-check` CI job) enforces the parity. **Gateway negotiation (PR #6914)** — the mcp-gateway addon's `initialize` now negotiates the protocol version (fail-closed `2024-11-05` default) instead of hardcoding `2026-07-28`.
> **v1.1.95 updates**: **Media path traversal closed** (PR #6886) — `scan_orphaned_media`'s `year_month` is now strictly validated as `^\d{4}/\d{2}$` before any path concatenation (previously `sanitize_text_field` + trim let `../`-style values list arbitrary directories — info disclosure), `delete_file_safe` containment upgraded to a path-component boundary (trailing-separator values like `uploads-evil/` no longer pass), and glob metacharacters are escaped in `delete_size_variants` patterns — keep those validations when extending media paths. **Unreferenced-media fail-safe** (PR #6886) — `delete_unreferenced=true` now checks `is_attachment_referenced_in_meta()` (postmeta LIKE patterns for comma-lists/serialized ints/quoted IDs + filename/URL patterns + well-known option checks) before deletion — never delete attachments referenced from post meta or options; the check deliberately skips a table-wide options scan (serialized blobs classify everything as referenced). **Shell gating** (PRs #6875/#6885) — `extract_pdf_text`, `export_products_report`, and `generate_invoice_pdf` no longer call `shell_exec()`/`exec()` unguarded: CLI work routes through the guarded `is_cli_tool_available()`/`run_cli_command()` helpers or the `WP_MCP_AI_ALLOW_SHELL_TOOLS` gate + Process Service (`run_silent` array form) — exec-disabled hosts must always degrade to pure-PHP/sidecar paths, never fatal. **Canonical envelopes in the audited toolkits** (PRs #6875–#6893) — the nine-toolkit hardening wave converts non-canonical `success: false` envelopes to `WP_Error` and flattens array-of-parts provider content at the response boundary (the `flatten_response_content()` pattern) — new tools in these toolkits must keep the two-gate + canonical-envelope discipline. **HIPAA audit trail** (PR #6887) — the dead `wp_mcp_ai_log_activity()` path now logs through `WP_MCP_AI_Healthcare_Audit`/`WP_MCP_AI_Logger` — keep healthcare audit writes on the real logger. **SSE keepalives** (PR #6879) — the chat stream emits comment frames only (no data leakage — comments are static); the Phase 4b offload proposal is design-only, not shipped.
> **v1.1.94 updates**: **Decision-scope guard** (PR #6866, Proposal 052) — every decision-model dispatch (`->decide()` / `->create_decision()`) must sit in a method declaring `@decision-domain` + `@decision-authority` and gate through `WP_MCP_AI_Decision_Scope_Guard::gate()` before any client call; banned domains (`life`, `people`, `ethics`, `identity`) fail closed to the caller's fallback (the formula never runs), `act` authority requires the `wp_mcp_ai_decision_act_domains` filter (banned domains can never be granted), and the new `WPMCPAI.Decisions.ScopeDeclared` sniff fails CI on undeclared dispatches — keep the guard on every new decision path (never bypass it at a client dispatch). **Toolkit MCP grant gate now actually enforced** (PR #6872) — the metabox class loads in every context via a definition-only require in `mcp-servers-init.php`, so `handle_jsonrpc()`'s deny-by-default grant check finally fires outside wp-admin (previously the admin-only class made the gate dead code in REST); keep `get_allowed_servers()` resolvable in REST/front-end contexts when extending. **Chat-tool exposure bridge** (PR #6872) — `wp_mcp_ai_toolkit_servers_expose_tools()` appends the granted **and enabled** servers' `effective_tool_slugs()` on the `wp_mcp_ai_chat_effective_tools` seam; every appended slug still passes the per-tool capability checks downstream (the seam runs before them — never let the bridge bypass capability checks), disabled servers contribute nothing, and the Context Window Estimator counts the same slugs via `wp_mcp_ai_prompt_window_toolkit_tool_slugs`. **OOS slug-pipeline parity** (PR #6872) — `handle_chat_request_oos()` runs the same `wp_mcp_ai_attention_tool_slugs` → `wp_mcp_ai_chat_effective_tools` pipeline as `build_tools_payload()` before handing the config to the orchestrator — never hand the orchestrator a config whose `tools` skipped those filters. **Legacy hook arg translation** (PR #6872) — the WordPress `EventDispatcher` translates the four mapped domain events (`BeforeChatRequest`, `AfterChatResponse`, `BeforeToolExecution`, `AfterToolExecution`) into the documented legacy `wp_mcp_ai_*` argument tuples before firing; never fire those hooks with the raw event object (legacy subscribers declare required parameters against the documented shapes), and the `$request` slot is null on the OOS path — subscribers must stay null-safe. **Safe-mode REST error masking** (PR #6869, closes #6860) — while a site stays in `safe` mode, admins (`manage_options`) can opt in to full detail per request via `?verbose_errors=1` (guests can never use the override); every masked response carries a 10-char correlation `ref` that maps to a server-side Recent Errors entry; `sanitize_error()` preserves the original HTTP status — never hardcode 500. **OAuth resource-server contract** (PR #6871) — the new `/.well-known/oauth-protected-resource` + `/.well-known/openai-apps-challenge` endpoints and the MCP route's 401 `WWW-Authenticate` implement the RFC 9728 contract (Auth0 contract preferred; the Pro `WP_MCP_AI_OAuth_Server` fallback stays); per-tool `securitySchemes` in `tools/list` (read-only → `site:read`; writes add `content:write`; **no `noauth` entries** — the endpoint authenticates every call); `nvoos_get_profile` exposes only a stable opaque HMAC id. **FlowHub MCP OAuth login proxy** (PR #6867) — the OAuth login flow now resolves the connection's proxy (from `connection_ref` or gateway-URL match) and persists it in the flow-state transient, so discovery/DCR/token exchange/refresh/revoke all ride the proxy — keep the flow-state carry when extending the flow. **Site Health** (PR #6870) — `get_site_health` pre-loads `wp-admin/includes/misc.php` before declaring the `wp_check_php_version()` polyfill (no more redeclare fatal on WP 6.9), and the schema check verifies the real activation option (`wp_mcp_ai_activated_version`). **Dependency floor** (PR #6868) — `markdown-it` `>=14.3.1 <15` across the three trees (GHSA-253c-mchw-3w2r, dev-only tooling).
> **v1.1.93 updates**: **Status-module trust boundaries** (PR #6849) — the media worker's status module is opt-in (`STATUS_ENABLED=1`); heartbeats are validated against the payload-v1 allowlist with credential-shape rejection, synthetic HTTP/TLS checks run through the shared SSRF guard, and webhook/email alerts are HMAC-signed with cooldown — keep those boundaries when extending the fleet surface. **FlowHub MCP Apps proxy egress** (PR #6861) — the FlowHub MCP Apps gateway path now applies the connection's `proxy_url`/`proxy_auth` (proxy password decrypted from the connection record, toolkit-settings fallback) via the `http_api_curl` cURL layer on every outbound call incl. OAuth discovery/token refresh, with guaranteed `finally` cleanup; in-process same-origin dispatch stays direct — never let a proxy change route same-origin traffic. **Registry validation surfaces real errors** (PR #6853) — `tool_validation_failed` now carries **HTTP 400** with the error list (a status-less `WP_Error` renders as REST 500), and `validate_dependencies()` resolves `required_settings` keys through `WP_MCP_AI_Credential_Resolver` (env vars, constants, WP 7.0 Connectors) so valid keys outside the settings array can't false-negative the gate. **WP-CLI least privilege** (PR #6852) — every mutating `wp mcp-ai` subcommand gates on `manage_options` (incl. the fleet-operator credential CLI); `wp mcp-ai vault` is metadata-only and never renders secret material; keep the gate on new subcommands. **Finnhub BYO-key** (PR #6857) — the Financial Planner market-data key is masked in the UI (full keys never echoed back), mask-safe sanitize keeps existing keys on re-submit, and rotation happens on 401/403/429 — no plaintext key egress. **undici floor** (PR #6850) — undici ≥7.30.0 (or ≥8.10.2/≥6.28.1) across the addon lockfile trees (GHSA-w293-vg96-wgc3, TLS certificate validation bypass).
> **v1.1.92 updates**: **Image egress + provenance control** (PR #6846) — OpenAI/DeepSeek vision requests now inline base64 data URLs fetched **server-side** (`WP_MCP_AI_Image_Data_Url` — local attachments read off disk, remote URLs downloaded by WordPress, `''` on oversize/non-image/download failure so callers keep the URL fallback); the WordPress server is the egress point for image bytes — keep that boundary when extending vision paths. **Inline optimization** (#6846) — the 2048px vision-tile cap + opaque-PNG→JPEG re-encode use a header-only dimension probe and **never modify the original upload** (optimized bytes used only when smaller); the toggle defaults on across all three settings layers with `wp_mcp_ai_image_inline_*` filters — keep the originals-untouched guarantee. **Media Studio consent + disclosure rails** (PRs #6839/#6844) — the D-1 one-time acknowledgment gate and the D-3 cost tripwires ($0.25 per-image / $10 per-job review, $100 hard cap block, unknown pricing → review, consent transforms always review) must stay enforced on every `/ai/*` path; face outputs carry a forced disclosure watermark and the disclosure policy floor stays at `metadata` (never downgradable). **Provenance** (#6844) — IPTC 2025.1 XMP + best-effort C2PA written into JPEG/PNG/WebP containers; provenance claims must stay best-effort (never block export on metadata failure). **Vision-tool credential resolution** (PR #6845) — `generate_image_alt_text`/`generate_image_caption`/`analyze_comment_content` now resolve keys through the **merged settings view + `WP_MCP_AI_Credential_Resolver`**, not the raw `wp_mcp_ai_settings` option — new tools must read credentials through the resolver, never a single option.
> **v1.1.91 updates**: **OAuth discovery hardening** (PR #6835) — `discover_metadata()` walks the full chain (RFC 8414 + §3.2 path insertion, RFC 9728 protected-resource metadata with every `authorization_servers` entry, 401 `WWW-Authenticate` probe, OIDC + WordPress REST fallbacks); RFC 8414 documents are accepted only with both endpoints; every attempt is logged and the failure alert surfaces the real transport error; `parse_www_authenticate()` splits multiple challenges per RFC 7235 §2.1; per-probe timeout capped at 10 s — keep the diagnostics (never swallow transport errors) when extending. **OAuth redirect allowlists** (PRs #6831/#6832) — LinkedIn, QuickBooks, Mailjet, and Yahoo Sports hosts join `allowed_redirect_hosts` via per-provider filters derived from the authorize-endpoint filter (never hardcode a host without the filter seam); new OAuth flows must allowlist their consent-page host or `wp_safe_redirect()` silently falls back to wp-admin. **DB output guard** (PR #6827) — `WP_MCP_AI_Db_Output_Guard::run()` suppresses `$wpdb` error output around callbacks (logging failures with the last query) and wraps `execute_tool()` + both REST tool handlers — no surface may leak database error HTML into a JSON response; keep the guard on new dispatch paths. **FlowHub MCP proxy egress** (PR #6836) — MCP-mode FlowHub connections route toolkit MCP calls through the explicit-connection path (decrypted credentials + the connection's encrypted proxy via `http_api_curl`); explicit `connection_id` arguments always win — never let an injected ID override a caller-supplied one. **Dependency floors** (PRs #6833/#6834) — `nodemailer` ≥10.0.9 in pro + media-worker (GHSA-g57g-f23g-4646) with the vendor bundle refreshed (old `lib/` removed); `fast-uri` ≥4.1.5 across the four alert-bearing trees (GHSA-jvvf-x445-j334).
> **v1.1.90 updates**: **Strict MCP assistant scope** (PR #6819) — opt-in `mcp_require_assistant_scope` (default off, Security → Access & Identity) fails `tools/list` and `tools/call` closed with HTTP 403 (`wp_mcp_ai_assistant_scope_required`) when no assistant resolves; the HTTP 403 special-case is scoped to this one error code (all other errors keep the HTTP 200 JSON-RPC envelope); keep the toggle opt-in when extending. **Memory cross-agent closure** (PR #6815) — the eight memory tools resolve `agent_id` from the execution context; cross-agent access is gated behind `manage_options` (403 `mcp_ai_memory_scope_denied`); `store_agent_context` scans for credential patterns (incl. the plugin's own `cred_…<secret>` format) and flags `sensitive_patterns`; keep the identity resolver the single path for scope decisions. **Roboflow tiers fail closed** (PR #6824) — serverless/dedicated require `va_roboflow_api_key` (raw `Authorization` header); self-host loopback/private is the only key-less + plain-HTTP tier; every endpoint URL passes `WP_MCP_AI_URL_Guard::validate()`; XL/2XL (PML 1.0) stay behind the `va_roboflow_allow_pml` consent toggle. **Dependency floors** (PR #6817) — `js-yaml` ≥5.4.1 across 13 trees + `webpack-dev-middleware@^8` ≥8.3.0; the `@ai-sdk/provider-utils` 2.x-line advisory is intentionally open (override would break `useChat` — tracked as #6818).
> **v1.1.89 updates**: **CodeQL closure** (PR #6807) — the repo is at **0 open code scanning alerts**: the orchestration diagnostic page escapes DOM-injected status/error strings, the twitter-api-v2 vendor bundle derives OAuth1 nonces from `crypto.randomBytes()` with rejection sampling (the local patch must be re-applied on vendor re-packaging), and yfinance no longer leaks stack traces. **Dependency advisory floors** (PR #6806) — `ip-address` 10.7.2 (NAT64 SSRF), `undici` 7.29.1/8.10.2/6.28.1 (WebSocket DoS), `multer` 2.4.0 (orphaned writes) across every affected tree. **Google Classroom restricted scopes** (PR #6809) — all Classroom scopes flagged restricted, granular-consent implication checks, Pub/Sub webhook verifies a shared secret before acking, and everything ships off by default (`enable_eca_classroom_integration`) — keep the deny-by-default flag and the secret-verify boundary. **Design System email gates** (PR #6810) — generated templates pass `wp_kses` + WCAG/EMC audit BLOCK gates (structure/security) before use; set-active + test-send are admin-gated; the `wp_mail` wrapper uses a sentinel double-wrap guard. **Upwork MCP-mode tokens** (PR #6804) — MCP-mode OAuth tokens persist to the **encrypted** central `mcp_oauth` store and auto-refresh via the attached OAuth client; never add a plaintext store for them.
> **v1.1.88 updates**: **MCP App credential-at-rest closure** (PR #6798) — inline MCP App secrets (`token`, `oauth_data` access/refresh) and Remote Sites `verify_token`/`verification_token` are now AES-256-CBC-encrypted at rest, masked in the metabox, and preserve blanks on submit (previously plaintext post meta echoed into hidden inputs); decrypt-on-read is idempotent so legacy plaintext rows keep working and encrypt on their next save; the three raw-store webhook read sites decrypt explicitly — keep the central-decryption boundary when extending. **OAuth refresh persistence** (PR #6798) — rotated tokens persist (inline assistant meta, central `update_mcp_oauth()`, tool-bridge re-hydration) and central rotations emit `mcp_oauth_refresh` activity events with metadata only — never the credential material. **Toolkit MCP grant gating** (PR #6796) — `handle_jsonrpc()` is now deny-by-default for assistant-scoped requests (`params.assistant_id`): non-granted servers return `-32601`; `initialize`/`ping` stay ungated and `toolkitServers` always reflects the exact grant list; requests without `assistant_id` bypass the gate (backward-compatible) — keep the master server-level toggle authoritative. **Guarded dependency requires** (PR #6801) — the 8 PayHere/Flowhub tool files now `file_exists()`-guard their client/helper requires and fail with canonical `WP_Error`s instead of `E_ERROR` fatals; new tools with optional client dependencies must keep the guard-then-self-report pattern. **Loopback OAuth** (PR #6794) — the manual paste-back completion endpoint carries a 10-minute state TTL and exchanges only the pasted code; never loosen the TTL or accept credentials in the callback URL. **Decision-model Phase A** (PR #6792) — Jev receives only the first user message (never tool results or transcripts), every Jev path fails open to deterministic behavior, and both features ship opt-in/off-by-default — keep those guarantees when extending the cascade.
> **v1.1.87 updates**: **Parity suite native security** (PR #6777) — the 30 mcp-wordpress tools follow the two-gate rule with capability checks, multisite guards, and the chat-client restriction trait on sensitive write tools; `create_application_password` masks the plaintext in logs — never log app passwords. **Image ladder fails closed** (PR #6780) — every external rung (`detect_image_content` Cloud Vision, `search_similar_images` Bing/SerpApi) is key-gated: missing credentials are reported as skipped, never an error, and never an HTTP request; web search is opt-in per call; all server-side URL fetches go through the SSRF guard. **Outbound booking safety rails** (PR #6786) — manual approval default for DMs, daily caps, send windows, consent attestation, and a token-authenticated reply webhook; the public booking REST endpoint is rate-limited, honeypotted, and consent-gated — keep those gates when extending. **Gateway addon auth** (PR #6778) — `X-MCP-Token` comparison is timing-safe (≥32 chars, rotation-window overlap), a 1 MB body cap applies, and `MCP_TOOLS_ALLOW`/`MCP_TOOLS_DENY` filter at tool registration (deny wins — filtered tools are absent from tools/list and rejected on tools/call). **Chat messages filter** (PR #6776) — `wp_mcp_ai_chat_messages` runs on both chat paths; the injected session-budget warning is a one-shot system notice — never let it carry secrets or user content.
> **v1.1.86 updates**: **MCP Server central credentials** (PR #6761) — the `mcp_server` Remote Sites connection type stores credentials AES-256-CBC-encrypted (including the `mcp_oauth` blob) and resolves them **decrypt-on-use** at chat time — credentials must never be written back to post meta; keep the encryption boundary when extending. **Portability redaction** (PR #6761) — `_wp_mcp_ai_mcp_apps` `token`/`oauth_data` are redacted on export and stripped on import (default on; opt-out filters `wp_mcp_ai_*`); stored credentials are preserved on overwrite imports, and reference entries cross the boundary as non-secret pointers — imported bundles with missing refs auto-disable with a warning (`wp_mcp_ai_mcp_apps_validate_imported_refs`). **Higgsfield credentials** (PR #6772) — the two-part `Key ID:SECRET` auth resolves through the settings → env → constants chain via the credential resolver; dotted Higgsfield model IDs are sanitized with `sanitize_text_field`, **not** `sanitize_key` (sanitize_key would strip the dots and break the IDs — a real bug the tests caught); the 480 s async-executor timeout override stays scoped to the Higgsfield jobs. **Log-search filters** (PR #6768) — `search` is a case-insensitive substring cap (200 chars, mb-safe) over structured entries + file-log lines; no new credential surface (filters never echo secrets beyond the existing log redaction). **Cost envelope** (PR #6771) — `cost_usd`/`is_estimated`/`provider`/`model` are display/aggregation metadata only; the envelope must never carry raw usage payloads.
> **v1.1.85 updates**: **MCP App Allowed Hosts** (PR #6753) — the Security Center → Network & Headers textarea merges the `wp_mcp_ai_mcp_app_allowed_hosts` filter + saved setting, with the `WP_MCP_AI_MCP_APP_ALLOWED_HOSTS` constant as a hard override; keep the precedence chain when extending. **Token masking** (PR #6753) — stored MCP App credentials are never echoed into the metabox HTML; empty means keep and is resolved server-side for tests — preserve that boundary. **In-process bridge safety rails** (PR #6756) — same-origin dispatch via `rest_do_request()` applies only when the host matches and the route is registered; remote hosts, unregistered routes, and the `wp_mcp_ai_mcp_app_disable_inprocess_bridge` filter always fall back to outbound HTTP; auth/session/protocol headers still pass through `WP_REST_Request::set_header()`. **`rest_post_dispatch` parity** (PR #6757) — the bridge re-applies the filter exactly like `serve_request()`; keep the in-process path behaviorally identical to HTTP for response-side filters. **Bridge exposure seam** (PRs #6755/#6758) — the `wp_mcp_ai_chat_effective_tools` filter runs **before** the per-tool capability check, so appended bridge slugs still pass `edit_posts` gating; never let the seam bypass capability checks. **Negative discovery cache** (PR #6758) — 60 s cache for down/unreachable app servers; `save_apps()` invalidates the `/tools` REST list cache.
> **v1.1.84 updates**: **Jev decision tools are all `manage_options`-gated** (PRs #6745/#6747) — `typesafe_decide` (capability metadata now matches the enforced gate, CI-pinned), the new base `typesafe_guardrail`, and the three new Pro tools (`typesafe_rerank`, `typesafe_eval`, `typesafe_skill_select`) must keep the admin gate when extended. **Guest-chat Jev guardrail fails open** (PR #6747) — `WP_MCP_AI_Pro_Jev_Guardrail` on the Layer I `wp_mcp_ai_pre_chat_message` filter is opt-in (`enable_jev_guest_guardrail`) and vetoes only high-confidence block verdicts; every error path must keep failing open (a guardrail outage must never block chat). **Advisory decision cache** (PR #6747) — cache keys embed endpoint/base/model/payload; hits return `cached: true` with zeroed usage (no double-billing); the cache is advisory and opt-in (`enable_typesafe_cache`). **Citation checks are advisory** (PR #6747) — `check_citations()` attaches `citation_checks` to research envelopes and fails open; it must never drop or reject content. **Retry discipline** (PR #6747) — 429/5xx retries honour `retry-after` (bounded via `wp_mcp_ai_typesafe_retry_attempts`/`_retry_sleep`); 4xx/transport errors never retry — keep that split when touching the clients.
> **v1.1.83 post updates**: **`typesafe_decide` is `manage_options`-gated** (PR #6728) and carries an adversarial-state caveat — the decision model only sees the state the caller passes; keep the gate and the caveat when extending. **Jev is a decision client, never a chat provider** (PR #6728) — the `Interface_WP_MCP_AI_Decision_Client` contract is deliberately separate from `Interface_WP_MCP_AI_Provider_Client`; keep Jev out of chat-provider dropdowns and never route prose generation through it. **Usage-monitor bridge filter** (PR #6726) — `handle_save_settings()` now applies `wp_mcp_ai_admin_settings_sanitize` with the **raw** posted input before sanitization; bridge handlers must keep no-op-when-absent so unrelated saves never flip monitor settings (third regression test pins this). **Webchat logging** (PR #6738) — `WP_MCP_AI_Logger::log_activity()` does not exist; route through the real `log_event( 'activity', … )` API. **Webchat SQL** (PR #6740) — the list/count queries now use explicit placeholders per `message_type` branch instead of interpolated WHERE fragments + array-spread args (the shape phpcs cannot statically resolve); keep placeholders explicit. **P3 ID-handoff contracts** (PRs #6729–#6738) are metadata-only annotations (`produces`/`consumes` handoffs + the coverage manifest) — they change no capability gates and must not loosen any.
> **v1.1.83 updates**: **Tool guidance is model-facing text, not an auth surface** (PRs #6686, #6695, #6687–#6723) — the `[Usage: …]` suffix is assembled from tool-declared guidance fields and appended to the model-facing description; keep guidance fields factual and never embed secrets, connection details, or raw user data in `when_to_use`/`notes` (descriptions are prompt-injected into every model call). The adaptive tool cap (`wp_mcp_ai_adaptive_tool_cap` + per-assistant override) is **opt-in, default off** — keep it that way when extending. **Pro bootstrap incomplete-install guard** (PR #6677) — `mcp-ai-wpoos-pro.php` now `file_exists`-checks the module registry require and degrades to `wp_mcp_ai_pro_incomplete_install_notice` + a WP_DEBUG line instead of a site-wide fatal; new Pro requires must keep the same guard pattern (degradation, never a fatal). **Telegram chunking** (PR #6677) — hard-split chunks drop `parse_mode` deliberately (mid-tag breaks are rejected); `chunk=false` keeps the legacy single-send; mid-sequence failures return `wp_mcp_ai_telegram_chunk_error` with the failed index — no new credential surface. **Gmail `connection_id="settings"`** (PR #6677) — the literal string resolves to the settings-based credential fallback across the four Pro Gmail tools, the Drive client, and base `search_gmail`; keep the resolver chain (explicit connection → settings fallback) and never echo decrypted credentials. **Upwork gating** (PR #6678) — `has_valid_connection()` mode + credential gates are byte-identical with the sibling tools; keep all three branches gated the same way. **Markdown converters** (PR #6684) — indented continuation lines fold into their list item (`<br>`); both converters (result-delivery + Teams webhook) must stay in sync. **npm advisories** (PR #6681) — adm-zip 0.6.1 (CVE-2026-77301 + symlink-overwrite), js-yaml 4.3.2 (GHSA-2883-xcg3-v3hh), colord 2.10.0 (GHSA-2wm5-q62r-hmrv); keep the pins on security bumps.
> **v1.1.82 updates**: **Token-tracking table hardening** (PR #6669) — dbDelta probes and the existence check now run with `$wpdb->suppress_errors()` inside try/catch; `record_usage()` returns false on failure with a single `error_log` line (no HTML error dump); reads return empty results when the table is absent. The schema-version option advances only when the table verifiably exists — never trust the option blindly. **Result-delivery metadata redaction** (PR #6661) — `delivery_safe_data()` strips the duplicate `response` copy plus `assistant_id`/`is_agentic` metadata before any rendering; legitimate data keys (`steps`, broadcast summaries, `tool_calls`) pass through — keep the allowlist when extending. **Shortcodes render markup only** (PR #6668) — the `[ollama_status]` checker moved to a footer-enqueued script (`wp_register_script(…, false, …)` + `wp_add_inline_script`) because inline scripts inside `the_content` get texturized (`&&` → `&#038;&#038;`); never emit executable JS from a shortcode body. **Docs Hub 0.4.7 (sub-project)** (PRs #6659/#6667) — cron scheduled on `init` (not `plugins_loaded` — avoids the WP 6.7+ early-translation notice via WooCommerce's translated interval), deactivation clears cron + pending tick events, no post-deactivation async rebuild. **OpenTerminal keyless microservice auth** (PR #6639) — the reworked yfinance service authenticates against the market-data microservices without API keys; the provider fallback chains + stale-while-revalidate caching never log or export provider credentials, and the eight new financial tools keep raw responses sanitized at the canonical-envelope boundary. **Multi-recipient email sanitization** (PR #6643) — `sanitize_email_recipients()` sanitizes each address individually (empties dropped, deduped) and runs at both the edit-modal save and the `sanitize_result_delivery()` boundary; a list that sanitizes to nothing fails with `missing_email_recipient` — never send to unsanitized addresses. **FlowHub credential resolution + proxy** (PRs #6635/#6637) — the shared resolver reads decrypted Remote Sites credentials (never echoed); live requests attach the connection proxy via `http_api_curl` so egress no longer leaks the server IP; keep proxy resolution connection-first with toolkit settings as fallback. **Shopify UCP live-only paths** (PR #6634) — UCP catalog queries write nothing locally (no transients/CCT, per UCP usage guidelines) and every response is marked `live: true`; buyer `context` fields are allowlisted and sanitized.
> **v1.1.80 updates**: **Assistant portability credential redaction** (PR #6628) — the `nvoos-assistant` export/import engine never exports `_wp_mcp_ai_credentials` hashes, strips them from any import payload (filterable `wp_mcp_ai_assistant_export_meta_denylist`), and only plugin-owned meta keys (`_wp_mcp_ai_*`, `mcp_ai_*`, `_wp_mcp_ai_pro_*`) are eligible; the backup export provider now shares the engine denylist — previously its assistants export **included credential hashes**. Import tools (`import_assistant`) route through the destructive-ops gate; REST import/export are `manage_options`-gated with nonce/bearer auth and a 2 MB payload cap. **Security usage monitor hardening** (PR #6632) — `sanitize_monitor_settings()` updates only submitted keys (an unrelated settings save can no longer silently disable the monitor); admin-edited regex patterns are validated at sanitize time and skipped at scan time (no `preg_match()` warnings); the new `POST /mcp-ai/v1/security/clear-violations` + `/clear-shutdown` routes are `manage_options`-gated with cookie-auth nonce, matching the Security Center controller patterns. **WhatsApp webhook self-tests** (PR #6622) — verification-handshake replay + HMAC-SHA256 signature validation + `subscribed_apps` shadow-delivery check on the Remote Sites edit form. **Shopify tokens** (PRs #6623/#6624/#6630) — catalog tokens capped at 60 min (Shopify can advertise a day-long `expires_in` while the JWT dies in 60), `read_global_api_catalog_search` scope validated, 401 purge-and-retry, and connection saves invalidate cached tokens for old + new client IDs; the keyless Storefront/Global Catalog UCP modes reject CCT sync (no cached catalog data) and the public `/ucp/agent-profile` route serves no secrets.
> **v1.1.79 updates**: **Imaging study-deletion symlink hardening** (PR #6616) — both recursive deletion paths (study delete + privacy eraser) now check `is_link()` first and remove the link itself, never its target; every iterator entry is `realpath()`-verified against the storage root before `unlink`/`rmdir`; `is_path_within_storage()` now requires a directory-boundary match (sibling-prefix paths no longer pass); blocked removals fire `study_delete_link_failed` / `study_delete_outside_storage_blocked`. **Docs Hub 0.4.5/0.4.6 symlink-safe deletion** (PRs #6615/#6617) — `uninstall.php` checks `is_link()` on the top-level cache dir before recursing; `Cache::get_live_dir()` / `rm_rf()` keep same-category guards; the readme's `== External Services ==` documents the allowlisted documentation-import hosts (raw.githubusercontent.com / api.github.com, server-side, admin-triggered). **Checkout statement descriptor (sub-project)** (PR #6613) — Stripe rejects the full `statement_descriptor` with `automatic_payment_methods` (424 on every live session); the vendor now ships `statement_descriptor_suffix` (2–22 chars, ≥1 letter, invalid values drop out) — client plugins are unaffected. **Content-graph already-licensed gate (sub-project, 1.0.8)** — `/payments/session` refuses chargeable sessions when the site is already licensed + active (`already_licensed`), so a second charge is impossible; the Payment Element billing-address mode moves `never` → `auto` so non-EU buyers stop dying on Stripe's required `billing_details.address.country`.
> **v1.1.78 updates**: **Slash-command tool adapter** (PR #6604) — `WP_MCP_AI_Slash_Command_Tool_Adapter::execute_tool_command()` delegates execution to `WP_MCP_AI_Tool_Registry::execute_tool()` so capability gates, validation, sanitisation, and the canonical envelope live in the tool layer; parsing, auth, rate-limiting, and audit stay in the handler — never bypass the tool layer from a command mapping. **MCP prompts bridge** (PR #6604) — `slash.*` prompt templates are user-controlled (MCP spec split: tools = model-controlled, prompts = user-controlled); keep the registry of commands the single source of truth for both surfaces. **Docs Hub 0.4.4 hardening** (PR #6606) — `/search` filters `source = "context"` entries for users without `manage_options`; context-source slugs no longer appear in the public sitemap; `uninstall.php`/`Cache::rm_rf()` skip symlinks and verify resolved-path containment inside the plugin cache directory; staging toggles isolate `get_page()`/`set_page()` from live transients. **DeepSeek V4 Pro restoration** (PR #6608) — model lifecycle only, no new credential surfaces; the migration map deliberately leaves v4-pro unmapped (stored references untouched). **Checkout price display** (PR #6603) — the modal's price label is display-only; the vendor re-verifies the amount server-side — keep it that way when touching the commerce JS.
> **v1.1.77 updates**: **Base+pro toolkit gating** (PR #6561) — toolkit load gates and ~550 Pro tool availability gates now use the `( ! $is_base || defined( 'WP_MCP_AI_PRO_VERSION' ) )` escape; availability gates are not authorization — capability checks on the tools themselves are unchanged, and the gates must never bypass a capability check. **Pro WP-CLI load-order guard** (PR #6585) — `wp_mcp_ai_pro_load_cli_commands()` bails cleanly when `WP_MCP_AI_PATH` is undefined and defers CLI registration to `plugins_loaded` 30; the CLI base command returns early on an undefined constant (defense in depth — keep both guards). **Memory CCT canonical slug** (PR #6591) — Pro retention queries `jet_cct_ai_agent_memories` first with a legacy `jet_cct_ai_chat_agent_memories` fallback; never introduce a new slug without a migration read path. **Checkout security (sub-project)** (PRs #6568/#6589/#6594) — the Stripe "Test connection" probe is nonce-protected, read-only (`GET /v1/balance`), and never writes; the 424/502 contract surfaces Stripe's own message in-modal (never stack details); Stripe keys stay masked on the admin form. **Dependency sweep** (PR #6592) — sharp 0.35.4, nodemailer 9.1.1, joi 18.2.8, postcss-selector-parser 6.1.3/7.1.6; keep the pins on security bumps. **Model refresh** (PR #6555) — DeepSeek V4 Flash line retired, V4 Pro deprecated (sunset 2026-09-14, migration map rewrites stored references); no new credential surfaces.
> **v1.1.76 updates**: **Telegram delivery route + parse mode** (PR #6525) — chat-channel delivery now routes through `send_telegram_message` directly (the broadcast tool strips HTML) so `parse_mode` works; the cron capability waiver extends to `wp_mcp_ai_send_telegram_message_capability` **only** for the internal `pro_schedule_manager_result_delivery` context — never waive for user-initiated tool calls; `MarkdownV2` content is reserved-char escaped before send; group messages skipped by `require_mention` now log `telegram_group_mention_required_ignored` instead of silently dropping. **Duplicate-summary skip** (PR #6548) — `response_starts_with_summary()` compares normalized (tags/whitespace-stripped, trailing-ellipsis-stripped) text before prepending a derived summary; no new input surface. **Dependency sweeps** (PRs #6515/#6532/#6546) — Dependabot bumps (tiptap, multer, csv-parse, react-router-dom), SVGO CVE-2026-84370 floor `>=4.1.0`, svgo/hono/vitest across 11 lockfiles; keep the pins on security bumps. Delivery diagnostics still never carry secrets.
> **v1.1.75 updates**: **Cron delivery capability waiver** (PR #6488) — `unified_channel_broadcast`'s `manage_options` gate is waived only for the internal `pro_schedule_manager_result_delivery` context via the `wp_mcp_ai_unified_channel_broadcast_capability` filter (cron runs with no logged-in user), with the schedule creator as the acting `user_id`; keep the waiver context-scoped — never waive for user-initiated tool calls. **Delivery diagnostics** (PR #6488) — failure `WP_Error`s carry diagnostics on `error_data` (referenced `connection_id`, inline-credential presence, counts of matching/enabled connections) and must **never** contain secret material; the warning log reads `error_data` only. **Remote Sites credential extraction** (PR #6482) — `extract_credentials_from_connection()` decrypts `api_key` (Slack/Discord/Telegram/Messenger/WhatsApp) and `token` (Teams) from the Remote Sites record; never log or error-echo the decrypted values. **Credential type normalization** (PR #6482) — `normalize_channel_credentials()` coerces JSON-string credentials into arrays and rejects other non-array values to an empty array so malformed stored values fail with `missing_channel_credentials` instead of a fatal; the broadcast tool treats malformed per-channel values as `failures` entries, never as a crash.
> **v1.1.74 updates**: **Email HTML rendering** (PR #6465) — `WP_MCP_AI_Markdown_Converter` HTML-escapes every text node, assembles the fragment, and passes a `wp_kses` allowlist; links are protocol-allowlisted. Raw assistant HTML can never reach the email body — keep both gates (escape + allowlist) when extending the converter. **Google Calendar query encoding** (PR #6460) — `add_query_arg()` deliberately leaves values unencoded, so Calendar query values must be `rawurlencode()`d before hand-off (the raw `+` in RFC3339 offsets decodes as a space and 400s the query); never hand-build calendar query strings without encoding. **Schedule manager `assistant_config` sanitization** (PR #6469) — `assistant_id`/`max_agentic_iterations` use `max( 0, absint( … ) )` clamps and `message` uses `sanitize_textarea_field`; keep the create-path validation (`missing_assistant_id`/`missing_assistant_message`) on the edit path.
> **v1.1.73 updates**: **Notify-suppression scoping** (PR #6448) — `update_woo_product_qty`'s `notify=false` suppresses WooCommerce low/no-stock *emails* by scoping the `woocommerce_should_send_low_stock_notification` / `woocommerce_should_send_no_stock_notification` filters to `__return_false` for the single write and removing them in a `finally` — early returns/errors can never leak a global filter, and the `woocommerce_low_stock`/`woocommerce_no_stock` actions still fire (push triggers and non-email observers unaffected). **Bulk scope expansion** (PR #6447) — `bulk_update_products`'s `scope=all` expands variable/grouped parents to children via the shared updater trait and re-syncs `WC_Product_Variable`; capability gates, sanitize-at-entry, and the canonical envelope are unchanged. **Queue table bootstrap** (PR #6423) — `get_queue_stats()` fails soft with zeroed stats when the `wp_mcp_ai_job_queue` table is missing, so the Load Guard can never spam SQL errors again; the table is created on activation and via a `DB_VERSION`-gated `maybe_create_table()`.
> **v1.1.72 updates**: **Pro update integrity** — the plugin updater verifies Pro vendor integrity before and after "Update Pro Now", so an incomplete package can no longer swap in and white-screen the site (PR #6338). **Dependency patches** — `browserslist` and `qs` bumped to patched versions across all seven `package-lock.json` files, closing 12 Dependabot alerts (PR #6365). **Container binding** (PR #6339) — `tool_registry` is registered `transient` so `get()` resolves the live registry singleton (production-equivalent; safe against test swaps). The two new Woo tools (#6388) go through the standard Pro tool capability gates.
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
- Error verbosity filtering (Safe/Moderate/Debug tiers). Since 1.1.94, masked
  errors carry a `ref` correlation ID that maps to a server-side entry in the
  Recent Errors log (full code/message/data kept server-side), the original
  HTTP status is carried through instead of being forced to 500, and admins
  can see unredacted detail per request while a site stays in Safe mode by
  appending `?verbose_errors=1` to the plugin REST URL.
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
