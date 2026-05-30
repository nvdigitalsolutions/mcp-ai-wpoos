# Cloudways Pro Toolkit — Full Implementation Plan

> **Status:** Proposal / awaiting approval to build.
> **Author:** NV oOS coding agent.
> **Created:** 2026-05-29.
> **Scope of first build slice:** folder + init wiring, **API v2** client,
> Phase‑1 read‑only tools, management dashboard scaffolding, tests.
> **Setting key:** `enable_cloudways_toolkit` (already defined, currently a no‑op).

---

## 1. Background & Findings

The "Enable Cloudways Pro Toolkit" toggle (`enable_cloudways_toolkit`) is registered
in `includes/admin/sections/class-wp-mcp-ai-section-tools.php` (~L628) and its docs
advertise **"58+ tools"** marked **"✅ Active"**. In reality the toolkit was **declared
but never implemented**:

| Expected (per setting/docs) | Reality |
|---|---|
| `addons/pro/includes/cloudways-toolkit-init.php` | ❌ Missing |
| Cloudways tool classes | ❌ Zero (`grep cloudways addons/pro/` → 0) |
| Code that reads `enable_cloudways_toolkit` to load tools | ❌ None |
| 58+ working tools | ❌ 0 |
| Management dashboard / workflow | ❌ None |

The only real Cloudways code lives in the **base** plugin (wrong tier):

- `includes/integrations/class-wp-mcp-ai-cloudways-oauth-handler.php` — token exchange.
- `includes/integrations/cloudways-integration-init.php` — admin connect/disconnect actions.
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` — `handle_fetch_cloudways_data()` + `handle_test_cloudways_connection()`.
- Settings fields in `class-wp-mcp-ai-admin-settings-base.php` (`cloudways_email`, `cloudways_api_key`, …).
- Tests: `tests/test-cloudways-connection-test.php`, `tests/test-cloudways-oauth-handler.php`.

**Two critical defects in the existing code:**

1. **Dead API version.** All requests hardcode `https://api.cloudways.com/api/v1/…`.
   **Cloudways API v1 reached end‑of‑life on 2026‑03‑31** (today is 2026‑05‑29),
   superseded by **API v2**. The credential/server‑listing flow is therefore
   likely already broken.
2. **Wrong tier.** The toggle/docs say "Pro," but all code sits in base `includes/`.

Per the user decision, **the v1 base integration may be removed** (it was never
really used) and rebuilt as a proper **Pro** toolkit on **API v2**.

---

## 2. Research Summary (Industry Standards)

### 2.1 Cloudways official capabilities (reference surface)

Cloudways now ships an **official MCP server** (`https://mcp.cloudways.com/mcp/`)
exposing ~150 tools across 12 categories. This is the authoritative reference for
what a "complete" Cloudways toolkit should cover:

| Category | Representative operations |
|---|---|
| Server Management | list/get/create/start/stop/restart/delete/backup/scale/clone, operation status |
| Application Management | list/get/create/clone/delete/backup/restore, cache purge, credentials, vulnerabilities, CNAME, cron, FPM/Varnish settings, SSH/SSL flags |
| Service Management | service status, start/stop/restart (nginx, mysql, php‑fpm, varnish, redis…) |
| Add‑on Management | list/activate/deactivate (account + server level) |
| Cloudflare CDN | add domain, details, TXT records |
| DNS Made Easy | domains + records CRUD, usage |
| Server Settings | PHP/MySQL config, disk cleanup |
| Monitoring & Analytics | server/app summaries, graphs, traffic, PHP/MySQL/cron analytics |
| Copilot Insights | infrastructure insights/alerts |
| Projects | group servers/apps |
| Git Deployment | keys, branches, clone, pull, history |
| SSH Key Management | create/delete/update keys |

### 2.2 Cloudways API v2

- **Migration is path‑level:** "replace `v1` with `v2` in the API call URL"
  (per Cloudways' v2 announcement). Base URL → `https://api.cloudways.com/api/v2`.
- **Auth unchanged:** `POST /oauth/access_token` with `email` + `api_key`
  → `{ access_token, expires_in }` (token valid ~60 min); send
  `Authorization: Bearer <token>` on subsequent calls.
- **New in v2:** Copilot, Security Suite, Password Protection, WP Multisite,
  App Stack version, expanded Cloudflare analytics, Client Billing & Reporting,
  vertical scaling. Bot Protection (MalCare) endpoints deprecated.
- ⚠️ **Verification caveat:** the v2 Redocly portal + Playground are JS‑rendered
  behind Cloudflare and could not be scraped here. Exact v2 paths/payloads must be
  confirmed against the live Playground during implementation. The client is
  designed so the version segment and per‑endpoint paths are centralized/filterable
  to absorb any path deltas.

### 2.3 MCP / agentic tool best practices (applied below)

- **Read‑first rollout.** Ship read‑only tools first; gate state‑changing and
  destructive tools behind explicit phases + capability flags
  (`read-only` vs `write` / `state-changing` / `performance-impact`).
- **Explicit, typed schemas.** Every parameter typed with description + enums;
  required fields enforced; IDs validated.
- **Idempotency + operation polling.** Async Cloudways operations return an
  operation id → expose `cloudways_get_operation_status` and tag long ops
  `async` / `requires-polling`.
- **Least privilege.** All tools require `manage_options` (infra control is an
  admin concern) and declare `requires-credentials` + `external-api` + `pro`.
- **No silent destructive actions.** Destructive tools (delete server/app) are
  deferred to a later phase, require a `confirm: true` parameter, and are
  flagged `state-changing` + non‑`reversible`.

### 2.4 Secret handling (OWASP / `wp-security-secrets` skill)

- Cloudways `api_key` + `email` + cached `access_token` stored in the existing
  `wp_mcp_ai_settings` option; ensure the option is **not autoloaded** if not
  needed every request, and that all credential fields remain in
  `get_sensitive_fields()` (they already are) so they are redacted in exports/UI.
- **Never** echo the API key or token back to the browser or logs; redact
  `Authorization` headers before any `error_log`/debug output.
- Token comparison (if any signature/callback is added later) uses `hash_equals`.
- Settings writes are `manage_options`‑gated and nonce‑protected (existing pattern).

---

## 3. Target Architecture

### 3.1 Folder layout (new)

```
addons/pro/includes/
├── cloudways-toolkit-init.php                 # NEW — conditional loader + helpers + admin page wiring
├── cloudways/                                  # NEW — toolkit-private classes
│   ├── README.md                               # NEW — folder README (7 required H2 sections)
│   ├── class-wp-mcp-ai-cloudways-client.php    # NEW — API v2 client (token cache/refresh, request, error→WP_Error)
│   └── class-wp-mcp-ai-cloudways-helpers.php   # NEW — toolkit-enabled check, capability flags, shared schema fragments
└── tools/
    └── cloudways/                              # NEW — one class per tool
        ├── README.md                           # NEW — folder README (tool catalogue + status)
        ├── class-wp-mcp-ai-tool-cloudways-base.php          # NEW — abstract base (client accessor, is_available, flags, envelope)
        ├── class-wp-mcp-ai-tool-cloudways-list-servers.php  # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-get-server.php    # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-list-apps.php     # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-get-app.php       # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-service-status.php       # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-server-monitor-summary.php  # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-app-monitor-summary.php     # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-server-settings-get.php     # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-app-analytics-traffic.php   # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-app-analytics-php.php       # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-app-analytics-mysql.php     # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-app-vulnerabilities-list.php # Phase 1
        ├── class-wp-mcp-ai-tool-cloudways-list-projects.php  # Phase 1
        └── class-wp-mcp-ai-tool-cloudways-get-operation-status.php    # Phase 1

addons/pro/includes/admin/
└── class-wp-mcp-ai-cloudways-settings-page.php # NEW — dashboard (extends WP_MCP_AI_Toolkit_Settings_Base)

addons/pro/tests/cloudways/                     # NEW — phpunit tests
├── test-cloudways-client.php
├── test-cloudways-tool-list-servers.php
├── test-cloudways-toolkit-gating.php
└── ... (one per Phase‑1 tool)
```

### 3.2 Registration wiring (matches existing toolkit pattern)

Mirror the e‑commerce toolkit exactly:

1. **Loader** — in `wp_mcp_ai_pro_init()` (`addons/pro/mcp-ai-wpoos-pro.php`), add:
   ```php
   if ( ! empty( $settings['enable_cloudways_toolkit'] ) ) {
       require_once WP_MCP_AI_PRO_PATH . 'includes/cloudways-toolkit-init.php';
   }
   ```
2. **Tool map** — in `wp_mcp_ai_pro_register_tools()`, add a gated block:
   ```php
   if ( ! empty( $settings['enable_cloudways_toolkit'] ) ) {
       $cloudways_toolkit_tools = array(
           'WP_MCP_AI_Tool_Cloudways_List_Servers' => WP_MCP_AI_PRO_PATH . 'includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-list-servers.php',
           // … Phase-1 tools …
       );
       $pro_tools = array_merge( $pro_tools, $cloudways_toolkit_tools );
   }
   ```
   Each tool also implements `public static function is_available()` (toolkit
   enabled + not base + credentials configured) so the registry's `$should_register`
   gate provides defense‑in‑depth.

---

## 4. Components

### 4.1 API v2 client — `WP_MCP_AI_Cloudways_Client`

Single responsibility: authenticated HTTP to Cloudways API v2. No business logic.

Public surface:
```php
class WP_MCP_AI_Cloudways_Client {
    const API_BASE = 'https://api.cloudways.com/api/v2';
    const OAUTH_ENDPOINT = 'https://api.cloudways.com/api/v2/oauth/access_token';

    public static function instance();                 // shared singleton
    public function is_configured(): bool;             // email + api_key present
    public function get_access_token();                // string | WP_Error — cached + auto-refresh
    public function get( string $path, array $query = array() );   // → array | WP_Error
    public function post( string $path, array $body = array() );   // → array | WP_Error
    public function put( string $path, array $body = array() );    // → array | WP_Error
    public function delete( string $path, array $body = array() ); // → array | WP_Error
}
```

Design points:
- **Token cache/refresh:** reuse existing settings keys
  (`cloudways_access_token`, `cloudways_token_expires_at`) with a 60s safety buffer;
  re‑exchange `email`+`api_key` on expiry. Supersedes the static methods on the
  old OAuth handler.
- **Centralized version segment** via `apply_filters( 'wp_mcp_ai_cloudways_api_base', self::API_BASE )`
  so a future v3 / path delta is a one‑line change.
- **Uniform error mapping:** non‑200 / `is_wp_error` / unparseable body → `WP_Error`
  with the Cloudways `message`/`error_description` when present.
- **Timeout** from `WP_MCP_AI_Resource_Manager` like the existing AJAX handlers.
- **Redaction:** any debug logging strips `Authorization` and `api_key`.
- **Rate‑limit aware:** surfaces HTTP 429 as a typed `WP_Error( 'cloudways_rate_limited' )`.

### 4.2 Tool base — `WP_MCP_AI_Tool_Cloudways_Base`

Abstract base implementing `WP_MCP_AI_Tool_Interface`,
`WP_MCP_AI_Tool_Capability_Flags_Interface`, and using
`WP_MCP_AI_Tool_Envelope` (or `WP_MCP_AI_Tool_Chat_Response`). Provides:

- `get_required_capability()` → `'manage_options'`.
- `static is_available()` → toolkit enabled + `! base_version` + client configured.
- `static get_unavailable_reason()` → human message (no creds / toolkit off).
- `client()` accessor → `WP_MCP_AI_Cloudways_Client::instance()`.
- Default `get_capability_flags()` → `['pro','requires-credentials','external-api','network-dependent']`;
  read tools add `read-only` + `cacheable`, action tools add `write`/`state-changing`.
- Shared param fragments (e.g. `server_id`, `app_id` integer schema with validation).

### 4.3 Phase‑1 read tools (the build target — 14 tools)

| Tool slug | Cloudways ref | API v2 (to verify) | Notes |
|---|---|---|---|
| `cloudways_list_servers` | server_list | `GET /server` | flags: read-only, cacheable |
| `cloudways_get_server` | server_get | `GET /server` + filter by id | requires `server_id` |
| `cloudways_list_apps` | app_list | `GET /server` (apps embedded) or `/app` | requires `server_id` |
| `cloudways_get_app` | app_get | derive from server payload | `server_id`+`app_id` |
| `cloudways_service_status` | service_status | `GET /service?server_id=` | |
| `cloudways_server_monitor_summary` | monitoring_server_summary | `GET /server/monitor/summary` | bandwidth/disk |
| `cloudways_app_monitor_summary` | monitoring_app_summary | `GET /app/monitor/summary` | |
| `cloudways_server_settings_get` | server_settings_get | `GET /server/settings` | PHP/MySQL config |
| `cloudways_app_traffic_analytics` | analytics_app_traffic | `GET /app/analytics/traffic` | |
| `cloudways_app_php_analytics` | analytics_app_php | `GET /app/analytics/php` | slow pages |
| `cloudways_app_mysql_analytics` | analytics_app_mysql | `GET /app/analytics/mysql` | slow queries |
| `cloudways_app_vulnerabilities_list` | app_vulnerabilities_list | `GET /app/vulnerabilities` | read-only |
| `cloudways_list_projects` | project_list | `GET /project` | |
| `cloudways_get_operation_status` | operation_status | `GET /operation/{id}` | for async polling |

All Phase‑1 tools are **read‑only**, `manage_options`, two‑gate compliant
(sanitize ids with `absint`/`sanitize_text_field` at entry; `esc_*`/`wp_json_encode`
at exit), and return the canonical envelope.

### 4.4 Management dashboard — `WP_MCP_AI_Cloudways_Settings_Page`

Extends `WP_MCP_AI_Toolkit_Settings_Base` (`parent_slug = 'nvoos-pro-dashboard'`).
Tabs:

- **Overview** — connection status, account name, server/app counts, "last synced."
- **Servers & Apps** — table (label, provider, region, IP, status, app count); per‑row
  service‑health badges. Data via the client (server‑side, cached in a transient).
- **Configuration** — credential entry (email + API key), Connect/Test buttons
  (reuse/replace the existing AJAX `test_cloudways_connection`), API version note.
- **Tools** — list of registered Cloudways tools (uses base class `get_tools_list()`).
- **Help** — link to Cloudways API v2 docs + the official MCP server article.

Dashboard scaffolding (tabs + overview + configuration + tools list) ships in the
first slice; live "Servers & Apps" data table can be wired in the same slice using
the client's `cloudways_list_servers` path (read‑only, cached).

### 4.5 Workflow presets

Add Cloudways presets so the toolkit participates in the Pro Workflow Builder and
tool‑preset systems:

- **Incident response (read‑only):** `cloudways_service_status` →
  `cloudways_server_monitor_summary` → `cloudways_app_php_analytics` →
  `cloudways_app_mysql_analytics`.
- **Security review:** `cloudways_app_vulnerabilities_list` → summarize.
- **Pre‑deploy safety (later phase, needs action tools):** backup → cache purge.

Implemented by registering a Cloudways preset group via the existing
`WP_MCP_AI_Pro_Workflow_Presets` pattern and/or the tool‑presets helper. (Workflow
presets that reference action tools are deferred to Phase 3.)

---

## 5. Phased Roadmap

| Phase | Contents | Build now? |
|---|---|---|
| **0 — Cleanup** | Remove v1 base integration files + AJAX handlers + init; keep settings fields (email/api_key/token) since the Pro client reuses them; add a thin back‑compat shim only if other code references the old class (audit shows only tests). Update/relocate tests. | ✅ Yes |
| **1 — Foundation + read tools** | Folder, init wiring, **v2 client**, base tool class, 14 read‑only tools, dashboard scaffolding (+ live read‑only servers table), folder READMEs, tests. | ✅ Yes |
| **2 — Safe actions** | `cloudways_purge_app_cache`, `cloudways_restart_service`, `cloudways_create_app_backup`, `cloudways_create_server_backup`, `cloudways_update_server_label`, `cloudways_update_app_label`, `cloudways_git_pull`, `cloudways_git_history`. Each `state-changing`, polled via operation status. | Plan only |
| **3 — Provisioning & destructive** | scale, clone, create app/server, start/stop server, **delete** (server/app) with `confirm:true` guard. Workflow presets that mutate. | Plan only |
| **4 — Add‑ons & DNS & Cloudflare & SSH/Git keys** | Cloudflare CDN, DNS Made Easy, add‑ons, SSH key mgmt, Copilot insights. Reaches the advertised "58+" surface. | Plan only |

> Building Phases 1–4 fully reaches ~60 tools, honoring the "58+ tools" doc claim.
> If we stop earlier, **§9 requires updating the docs to the real count** so the
> setting description stops over‑promising.

---

## 6. Security Model

- **Capability:** every tool + every dashboard write requires `manage_options`.
- **Nonces:** dashboard forms + AJAX use existing `wp-mcp-ai-settings` nonce pattern.
- **Credential storage:** `cloudways_email`, `cloudways_api_key`,
  `cloudways_access_token`, `cloudways_token_expires_at` remain in
  `wp_mcp_ai_settings`; confirm all secret keys are in `get_sensitive_fields()`
  (api_key already is — **add `cloudways_access_token`**). Consider `autoload=false`
  follow‑up for the settings option (cross‑cutting; out of toolkit scope).
- **Output:** API key/token never returned to browser or LLM; redact `Authorization`
  in any debug path.
- **Two‑gate:** sniffs `WPMCPAI.Tools.SanitizeAtEntry` + `WPMCPAI.Tools.CanonicalReturnEnvelope`
  must pass at severity 5.
- **Destructive guard:** Phase‑3 delete tools require explicit `confirm: true` and are
  flagged non‑`reversible`; context‑restricted away from public `chat-client`.
- **SSRF:** all requests target the fixed Cloudways host via the centralized base URL;
  no user‑supplied host/URL is fetched.

---

## 7. Testing Strategy

- **Client unit tests** (`addons/pro/tests/cloudways/test-cloudways-client.php`):
  token caching, expiry/refresh, error mapping (401/429/5xx → typed `WP_Error`),
  base‑URL filter — using `pre_http_request` to stub `wp_remote_*`.
- **Tool tests** (one per Phase‑1 tool): success envelope shape
  (`success`/`message`/`data`), `WP_Error` on client failure, required‑param
  validation, `is_available()` gating, `get_required_capability()` == `manage_options`.
- **Gating test:** tools not registered when `enable_cloudways_toolkit` is off or in
  base version.
- **Mocking:** no live network; `add_filter( 'pre_http_request', … )` returns canned
  Cloudways JSON fixtures.
- Follow `.context/testing.md`; run `composer run test -- --filter Cloudways`.

---

## 8. File‑by‑File Manifest (Phase 0 + 1)

**Create**
- `addons/pro/includes/cloudways-toolkit-init.php`
- `addons/pro/includes/cloudways/README.md`
- `addons/pro/includes/cloudways/class-wp-mcp-ai-cloudways-client.php`
- `addons/pro/includes/cloudways/class-wp-mcp-ai-cloudways-helpers.php`
- `addons/pro/includes/tools/cloudways/README.md`
- `addons/pro/includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-base.php`
- `addons/pro/includes/tools/cloudways/class-wp-mcp-ai-tool-cloudways-*.php` (14 tools)
- `addons/pro/includes/admin/class-wp-mcp-ai-cloudways-settings-page.php`
- `addons/pro/tests/cloudways/test-*.php`

**Modify**
- `addons/pro/mcp-ai-wpoos-pro.php` — toolkit loader block + tool‑map block.
- `includes/admin/class-wp-mcp-ai-admin-settings-base.php` — add `cloudways_access_token` to `get_sensitive_fields()`.
- `docs/guides/admin/pro-settings-toolkits.md` + `addons/pro/docs/NEW_TOOLKITS_INTEGRATION_GUIDE.md` — correct the "58+ / ✅ Active" claim to the real count + status.
- `.context/pro-vs-base.md` — note Cloudways toolkit flag if listed.

**Remove (Phase 0 — v1, unused)**
- `includes/integrations/class-wp-mcp-ai-cloudways-oauth-handler.php`
- `includes/integrations/cloudways-integration-init.php`
- `handle_fetch_cloudways_data()` + `handle_test_cloudways_connection()` + the two
  v1 endpoints from `class-wp-mcp-ai-admin-ajax-handlers.php` (or repoint them at
  the new v2 client).
- Relocate/rewrite `tests/test-cloudways-*.php` to the new Pro client.

> ⚠️ Before deleting, grep for `WP_MCP_AI_Cloudways_OAuth_Handler` and the AJAX
> action names to catch any remaining references (admin JS, settings page). Provide a
> deprecation shim if any non‑test caller exists.

---

## 9. Documentation & Truth‑in‑Advertising

After the build, update the setting description and toolkit docs to the **actual**
tool count and status (e.g., "Phase 1: 14 read tools available" or the final count).
The current "58+ tools / ✅ Active" text must not remain unless all phases ship.

---

## 10. Validation Checklist (per slice)

- [ ] `composer run lint` (WPCS + custom tool sniffs, severity 5) — zero violations.
- [ ] `composer run lint:compat` — PHP 7.4–8.3 clean.
- [ ] `composer run test -- --filter Cloudways` — green.
- [ ] `composer run docs:check-folder-readmes:all` — both new folder READMEs pass.
- [ ] `npm run lint:js` if any dashboard JS is added.
- [ ] Manual: enable toolkit → tools appear in registry; disable → gone.

---

## 11. Open Questions / Risks

1. **Exact v2 paths.** Mirrors of v1 per Cloudways' "replace v1→v2" guidance, but the
   Playground is JS/Cloudflare‑gated and unscrapable here. Paths are centralized so
   corrections are one‑liners; needs a quick live verification pass during build.
2. **Apps endpoint shape.** v1 embedded apps inside `/server`; confirm whether v2 has
   a dedicated `/app` collection or keeps them embedded (affects `list_apps`/`get_app`).
3. **Token lifetime.** Assumed ~60 min (v1 behavior); confirm v2 `expires_in`.
4. **Dashboard live data caching TTL.** Proposed 5‑min transient for servers/apps;
   confirm acceptable staleness.
5. **Scope of "full."** Confirm whether to build all Phases 1–4 to reach 58+ tools now,
   or ship Phase 1 + dashboard first and iterate (recommended).
