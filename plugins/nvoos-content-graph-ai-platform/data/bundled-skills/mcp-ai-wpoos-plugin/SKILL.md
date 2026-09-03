---
type: Skill
name: mcp-ai-wpoos-plugin
description: Complete operational guide for the NV oOS (Open Operator System) WordPress plugin in Docker/WSL2 — setup, assistant creation, credential tokens, MCP tool calling, API key auto-detection, env var bridging, common fixes, and IGCSE study configuration. Use when setting up the plugin for the first time, creating assistants programmatically, generating MCP bridge tokens, troubleshooting Docker path issues, bridging API keys, or calling tools via JSON-RPC over HTTP.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.68"
  plugin-version-tested: "1.1.68"
  last-updated: "2026-09-02"
---
# NV oOS Plugin — Docker/WSL2 Setup & Operational Guide

Complete operational skill for the NV Digital Open Operator System (oOS)
WordPress plugin running in Docker on Windows/WSL2. Covers the full lifecycle:
startup, assistant creation, credential tokens, MCP tool calling, API key
auto-detection, and tool assignment.

## When to use this skill

- Setting up the plugin in a Docker Compose stack for the first time
- Plugin produces `file_put_contents` / `mkdir` PHP warnings on startup
- Docker volume mount fails with "invalid volume specification"
- Need to create an assistant programmatically (not via admin UI)
- Need to generate a `cred_xxxxx.SECRET` token for the MCP bridge
- Calling MCP tools via `curl` / HTTP JSON-RPC
- API keys not being picked up from Docker environment variables
- Troubleshooting 0 tools returned from `tools/list`
- Configuring the plugin for IGCSE study (which tools to assign)

## Architecture

```
Zed / Claude Desktop / Cursor
        │
        │ MCP JSON-RPC over HTTP
        ▼
┌─────────────────────────────────┐
│  WordPress REST API              │
│  /wp-json/mcp-ai/v1/mcp         │  ← MCP endpoint
│  Auth: Bearer cred_xxxxx.SECRET │
└──────────────┬──────────────────┘
               │
     ┌─────────┴──────────┐
     │  WP_MCP_AI_*       │
     │  Tool Registry     │  ~303 base / ~1,565 full tools
     │  Credentials       │  Token validation
     │  Assistant (CPT)   │  Post type: mcp_ai_assistant
     └────────────────────┘
```

## Quick Start (Docker)

### 1. Environment Setup

The docker-compose.yml uses **relative paths** (`.`) for volume mounts,
which Docker Desktop translates automatically for WSL2 — no manual path
fix needed.

```bash
cp .env.example .env
# Edit .env: set BASE_URL=http://localhost:8000 and add API keys
wsl docker compose up -d
# Wait for wp-plugin-seed to exit (installs WP + activates plugin)
wsl docker compose logs -f wp-plugin-seed
```

WordPress is at `http://localhost:8000`, admin at `/wp-admin`
(**admin / password**).

### 2. API Key Auto-Detection (v1.1.47+)

On activation, the plugin now **automatically reads** well-known environment
variables and populates settings. No manual bridging script needed.

| Environment Variable | Plugin Setting |
|----------------------|---------------|
| `OPENAI_API_KEY` | `openai_api_key` |
| `GEMINI_API_KEY` / `GOOGLE_API_KEY` | `gemini_api_key` |
| `ANTHROPIC_API_KEY` | `anthropic_api_key` |
| `DEEPSEEK_API_KEY` | `deepseek_api_key` |
| `BRAVE_API_KEY` / `BRAVE_SEARCH_API_KEY` | `brave_search_api_key` |
| `TAVILY_API_KEY` | `tavily_api_key` |
| `PERPLEXITY_API_KEY` | `perplexity_api_key` |
| `LM_STUDIO_API_KEY` | `lm_studio_api_key` |
| `NVIDIA_API_KEY` | `nvidia_api_key` |
| `HUGGINGFACE_API_KEY` / `HF_API_KEY` | `huggingface_api_key` |
| `CLOUDFLARE_API_TOKEN` | `cloudflare_api_token` |
| `KIMI_API_KEY` | `kimi_api_key` |
| `DIGITALOCEAN_API_KEY` | `digitalocean_api_key` |
| `STABILITY_API_KEY` | `stability_api_key` |
| `MUBERT_API_KEY` | `mubert_api_key` |
| `EXA_API_KEY` | `exa_api_key` |
| `CRAWL4AI_API_KEY` | `crawl4ai_api_key` |
| `REMOVEBG_API_KEY` | `removebg_api_key` |
| `GOOGLE_MAPS_API_KEY` | `google_maps_api_key` |
| `ITA_TARIFF_API_KEY` | `ita_tariff_api_key` |

Set them before starting Docker:

```bash
export OPENAI_API_KEY="sk-..."
export BRAVE_API_KEY="BSA..."
wsl docker compose up -d
```

**Guard rails:**
- Runs once per site (flag: `wp_mcp_ai_env_keys_checked`)
- Never overwrites existing manually-configured keys
- Stores in both `wp_mcp_ai_settings` array AND standalone `wp_mcp_ai_*` options
- Plaintext values are auto-migrated to AES-256-GCM encrypted on first read

Implementation: `includes/bootstrap/activation.php` →
`wp_mcp_ai_auto_detect_env_keys()`, called from `wp_mcp_ai_activate_single_site()`.

### 3. Fix Uploads Directory Warnings (v1.1.47+)

The plugin now uses `wp_mkdir_p()` (WordPress core, since WP 2.0) with
`is_dir()` guards before all `file_put_contents()` calls in:

- `includes/integrations/class-wp-mcp-ai-custom-tool-loader.php`
- `includes/paper-store/class-wp-mcp-ai-paper-store-manager.php`

If you still see warnings on older versions, pre-create the directories:

```bash
docker compose exec -T wordpress sh -c "
  mkdir -p /var/www/html/wp-content/uploads/wp-mcp-ai-custom-tools
  mkdir -p /var/www/html/wp-content/uploads/mcp-ai-wpoos/paper-store
  chown -R www-data:www-data /var/www/html/wp-content/uploads
"
```

### 4. WSL2 Docker Path Compatibility

The current `docker-compose.yml` uses relative paths (`.`) which Docker Desktop
translates automatically. No action needed. If you encounter path issues with
custom setups, use WSL paths (`/mnt/c/...`, `/mnt/f/...`) rather than Windows
drive letters (`C:/...`, `F:/...`). See Docker Compose header comment for details.

---

## Creating an Assistant Programmatically

Assistants are WordPress custom post types (`mcp_ai_assistant`). Create one
via PHP in the container:

```php
<?php
define( 'WP_USE_THEMES', false );
require_once '/var/www/html/wp-load.php';

// 1. Create the assistant post
$post_id = wp_insert_post( array(
    'post_type'    => 'mcp_ai_assistant',
    'post_title'   => 'My Assistant',
    'post_content' => 'Description of what this assistant does',
    'post_status'  => 'publish',
), true );

if ( is_wp_error( $post_id ) ) {
    die( 'Failed to create assistant: ' . $post_id->get_error_message() );
}

// 2. Assign tools (required — otherwise tools/list returns [])
$tools = array(
    'search_content', 'web_search', 'create_post',
    'save_post', 'get_recent_posts', 'create_chart',
    'get_site_health', 'get_environment_status',
    // ... add more from the IGCSE list below
);
update_post_meta( $post_id, '_wp_mcp_ai_tools', $tools );

// 3. Generate a credential token
$result = WP_MCP_AI_Credentials::issue_credential( $post_id, 1 );
if ( is_wp_error( $result ) ) {
    die( 'Failed to issue credential: ' . $result->get_error_message() );
}
$token = $result['token'];  // format: cred_XXXXX.SECRET
echo "Assistant ID: $post_id\n";
echo "Token: $token\n";
```

**Key insight:** If `_wp_mcp_ai_tools` post meta is empty, the MCP `tools/list`
returns `[]` even though hundreds of tools are registered at the system level.
Always assign tools after creating an assistant.

---

## MCP Token & Authentication

### Generating a Token

Tokens follow the format `cred_XXXXX.SECRET` (32-char secret, bcrypt hashed):

```php
$result = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $user_id );
$token  = $result['token']; // Already includes "cred_" prefix
```

The `parse_token()` method validates the format:
- Must be a non-empty string
- Must contain a `.` separator (exactly 2 parts)
- First part must start with `cred_`
- Both parts must be non-empty

Default credential lifetime is 90 days (configurable via
`credential_lifetime_days` setting; 0 = no expiry).

### Using the Token

```bash
# JSON-RPC via curl
curl -s -X POST http://localhost:8000/wp-json/mcp-ai/v1/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'

# MCP Bridge (stdio ↔ HTTP relay)
node bin/mcp-bridge.js
# Requires env: MCP_AI_BASE_URL + MCP_AI_TOKEN
```

### Auth Methods (in order of preference)

1. **Bearer token** (`cred_xxxxx.SECRET`) — recommended for MCP clients
2. **OAuth 2.0** (authorization_code grant) — browser-based MCP app flow
3. **Mesh Key** (`X-WP-MCP-AI-Mesh-Key` header) — mesh federation
4. **WordPress nonce** (`X-WP-Nonce` header + auth cookie) — browser admin

Application passwords (Basic auth) are not supported on the MCP endpoint.
Use Bearer tokens instead.

---

## MCP JSON-RPC Protocol

The endpoint is at `/wp-json/mcp-ai/v1/mcp`. Standard JSON-RPC 2.0.

### Initialize

```json
{
  "jsonrpc": "2.0",
  "method": "initialize",
  "params": {
    "protocolVersion": "2024-11-05",
    "capabilities": {},
    "clientInfo": {"name": "my-client", "version": "1.0"}
  },
  "id": 1
}
```

Response includes `serverInfo.name` (the assistant title) and server
`capabilities` (tools, resources, etc.).

### List Tools

```json
{"jsonrpc": "2.0", "method": "tools/list", "id": 1}
```

Returns only tools assigned to the authenticated assistant via
`_wp_mcp_ai_tools` post meta. Each tool includes `name`, `description`,
and `inputSchema` (JSON Schema).

### Call a Tool

```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "tool_slug",
    "arguments": {"param1": "value1"}
  },
  "id": 1
}
```

Response is in `result.content[0].text` as a JSON string. Canonical envelope:
success returns an array; errors return a `WP_Error` object serialized as
`{"code":"...", "message":"...", "data":{...}}`.

---

## Rate Limiting, Restricted Users & Conversation Import (v1.1.60+)

**Restricted users.** Ephemeral rate-limit and token-budget blocks become
persistent, reviewable restriction records (`WP_MCP_AI_Restriction_Registry`)
with auto-expiry and audit logging:

- Chat rate limit filterable via `wp_mcp_ai_chat_rate_limit` and
  `wp_mcp_ai_chat_rate_limit_window` (was hardcoded 60 req/min).
- Enforcement hooks: `wp_mcp_ai_tool_token_limit_exceeded`,
  `wp_mcp_ai_per_session_limit_exceeded`, `wp_mcp_ai_rate_limit_exceeded`.
- Admin: Token Manager "Restricted Users" panel (Base) + Pro Command Center
  **Restrictions** tab; notice toggle at Settings → Orchestration →
  Restriction Notifications (`enable_restriction_admin_notices`).
- WP-CLI: `wp mcp-ai restrictions list|lift|add`.
- REST: `GET /mcp-ai/v1/restrictions`,
  `GET|POST /mcp-ai/v1/users/{id}/restrictions`,
  `DELETE /mcp-ai/v1/users/{id}/restrictions/{type}`; rate-limited responses
  carry IETF `RateLimit-Policy` / `RateLimit` / `Retry-After` headers.
- Reference: `docs/features/security/user-restrictions.md`.

**Conversation import (JetEngine).** Imports ChatGPT, Gemini Takeout, Claude,
ShareGPT, and OpenAI JSONL exports into the `ai_chat_transcripts` CCT:

- Tools: `conversation_import_detect|run|status|delete` (require
  `manage_options`).
- WP-CLI: `wp mcp-ai conversation-import detect|import|status|delete`
  (`--dry-run`, `--policy=skip|refresh`, `--resume-token=`).
- Admin upload/preview page, GDPR export/erase, optional memory mining via
  `mine_agent_memory`. Guide: `docs/user-guides/conversation-import.md`.

---

## Agent Identity Bridging & OKF Bundle (v1.1.61+)

**Agent identity bridging.** `store_agent_context` resolves virtual agent
keys (e.g. `nvoos-pro-spa-memory-drawer`, `virtual_planner_*`) to the
canonical assistant post ID via `WP_MCP_AI_Agent_Identity_Resolver` (alias
map in the `wp_mcp_ai_agent_id_aliases` option; bounded, never autoloaded);
the envelope echoes `original_agent_id` / `agent_id_resolved`. Chat-memory
recall merges alias buckets with a `stored_under` stamp per record, and the
memory drawers (base, chat-spa, pro-spa) show scope/stored-under chips, the
agent ID they recall under, and a show-all-scopes toggle; open drawers
refresh on `memory_event` store frames.

**OKF bundle.** The `skill-knowledge` bundle is auto-generated from
`includes/bundled-skills/` on bootstrap (priority 32) and refreshed after
bundled-skill reinstall — `okf_search` works out of the box (no more "OKF
bundle not found: skill-knowledge").

## OKF Bundle Management & Pro Knowledge Routing (v1.1.62+)

- **Bundle Manager (Base)** — `WP_MCP_AI_OKF_Bundle_Manager` owns the OKF
  bundle lifecycle: create/list/rename/archive/delete, ZipSlip-safe ZIP
  import/export, health stats, log maintenance; `skill-knowledge` is
  protected from tool writes (`okf_protected_bundle` — curated knowledge
  belongs in `site-knowledge`). Admin screen under Assistants:
  `edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-okf-bundle-manager`
  (Bundles/Browser/Editor/Import-Export/Validate; `manage_options`).
- **Tools** — three new base tools (`okf_list_bundles`, `okf_validate_bundle`,
  `okf_import_bundle`) plus the `okf_write_concept` provenance schema
  (`resource`/`sources`/`usage_window`/`verified`) — OKF tool surface: 10.
  Two new Pro tools: `okf_enrich_site_content` (`manage_options`) and
  `route_knowledge_query` (`read`).
- **Pro knowledge routing** — `load_skill` resolves `bundle:concept_id`
  names (OKF-to-Skill Bridge, per-assistant grants + trust gating); the
  enrichment agent crawls site content into OKF concepts; the hybrid
  knowledge router classifies queries across OKF / vector / Paper stores.
- **Pro SPA v2 drawer** — in-chat OKF Skills Drawer backed by the read-only
  `mcp-ai-pro/v1/okf` REST surface (bundles, concept browse/search,
  assistant skill grants); `%2F`-encoded concept IDs decode correctly.
- **Vector stores** — all vector-store tools now run on the Responses API
  (no `OpenAI-Beta: assistants=v2` header; `file_batches` ingestion with
  bounded polling + fallback) ahead of the 2026-08-26 Assistants API
  removal.
- Guide: `docs/features/okf-integration.md`; roadmap:
  `docs/project/plans/OKF-BUNDLE-MANAGEMENT-IMPLEMENTATION-PLAN.md`.

---

## Artifact Evolution, Addons Page & Storage Worker (v1.1.63+)

- **Artifact evolution Phases A–G (Base + Pro)** — the Continual Harness
  Evolver + Meta-Harness are now a gated Darwinian self-improvement loop for
  skills, prompts, and roles (`includes/harness/class-wp-mcp-ai-artifact-*.php`):
  populations + parent sampling, failure replay + post-mutation verification,
  pre-commit admission gate, holdout-gated deployment with shadow A/B + drift
  rollback, governor + human approval queue + lineage. **Every layer defaults
  off**; the opt-in switches live in Settings → Orchestration Layer
  (`WP_MCP_AI_Evolution_Settings_Bridge`). The `evolve_harness` tool now calls
  the repaired Evolver contract (`analyze_failures()`, component-scoped
  `evolve()`, enforced `dry_run`). Proposal:
  `docs/project/proposals/007-artifact-evolution.md`.
- **Pro Addons page (v1.1.63+)** — NV oOS Pro Dashboard → Addons
  (`/wp-admin/admin.php?page=wp-mcp-ai-addons`) installs/activates standalone
  addons whose ZIPs ship in `build/`; nonce + `install_plugins` + allowlist;
  non-WordPress components listed read-only.
- **Chat storage worker offload** — saves ≥ `wp_mcp_ai_storage_worker_threshold`
  (default 10,000 chars) offload `JSON.stringify` to the browser
  `storage-worker.js`; sync fallback for small saves/unload/worker failure;
  kill switch: `add_filter( 'wp_mcp_ai_storage_worker_threshold',
  '__return_zero' );`. Plan:
  `docs/project/proposals/032-chat-web-workers-wiring-implementation-plan.md`.
- **DeepSeek empty-schema fix** — tool schema `properties` must encode as `{}`
  never `[]`; legacy tools are wrapped before the first register attempt.

## Reasoning Models, Full-Crawl Proxy & Security-Posture Closure (v1.1.65+)

- **OpenAI reasoning-model parameters** — o-series and gpt-5 models reject
  `max_tokens` (o-series also `temperature`); `lib/core`
  `OpenAiCompatibleClient` strips unsupported parameters per model
  (`applyModelConstraints()`) and retries 400 rejections with corrected
  payloads (`sendWithParameterCorrection()`) on sync and streaming paths.
  Do not blanket-add `max_tokens` for reasoning models.
- **Media Worker full-Crawl4AI proxy (031 Phase 3)** — env-gated
  `POST /api/crawl/full` + `GET /api/crawl/full/task/:id` forward to
  `CRAWL4AI_FULL_URL` with SSRF-validated targets, token gating, and
  503/502/400 envelopes; `TEMP_ROOT` is the strict-path sandbox root
  (028 Q5). Worker version stays **v3.2.0** — `package.json` is
  authoritative.
- **Security-posture closure (issue #5972)** — Algorave Tone.js raw eval
  requires per-session confirmation + warning banner; TMA source map
  removed; webhook `__return_true` callbacks carry justification comments.
- **Chat/REST hardening** — legacy top-level `attachments` parameters
  tolerated; custom message roles work via
  `wp_mcp_ai_allowed_message_roles`; orphaned tool messages silently
  discarded; sign-preserving transcript pagination; legacy `input_text`
  segments normalized to `text`.
- **TPM fallback** — token-budget service falls back to the bundled model
  catalog when the rate-limits CCT has no entry (TPM limits work without
  JetEngine).
- **Slash commands & blocks** — toolkit manager re-resolves the handler on
  registration; CSV list args accept `"1,2"` and `"1, 2"`;
  assistant-builder and Pro toolkit blocks register idempotently
  (WP 7.1 notices).

## Test-Suite Campaign, REST Hardening & Content Graph AI (v1.1.66+)

- **PHPUnit repair campaign (Aug 28–31, ~100 PRs)** — the single-process
  suite was repaired cluster-by-cluster; the recurring root causes and the
  cluster-PR workflow are catalogued in the sibling
  `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` skill, with the
  standing tracker `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`.
  Coding-time agent skills: 53.
- **Assistant-access caching** — `validate_assistant_access()` caches
  `WP_Error` results like successes; the cache-disable path is the
  `wp_mcp_ai_assistant_access_cache_enabled` filter (never a persisted
  `WP_MCP_AI_DISABLE_CACHE` define from a test).
- **REST/auth hardening** — attachment-segment validation errors return
  explicit HTTP 400s; token-tier endpoint + tier-change audit logging
  fixed; REST permission-callback allowlist refreshed; bearer-auth context
  synced across Simple JWT + assistant-access paths; guest tokens are
  origin-bound.
- **Job queue & notifier** — closure serialization fixed in legacy option
  storage; custom-table queries guard against missing schema (Graphify DB,
  job store, tenant DB); `update_status()` restored with dot-preserving
  job IDs and owner-scoped REST routes; progress events promote cached
  status to running.
- **Tool contract fixes** — Media Toolkit tools return canonical `WP_Error`
  envelopes; Remove Background gained a path guard; memory-capture failure
  envelope restored; web-search result building fixed for Exa/Perplexity;
  auto-categorize router/client fixed.
- **Content Graph AI 1.0.3** — `nvoos-content-graph-ai` standalone plugin
  bumped to 1.0.3 with a tool permission-check fix; `nvoos-content-graph`
  stays 1.0.3, Media Worker stays v3.2.0.

## Google Calendar Connection & Composio Hardening (v1.1.64+)

- **Google Calendar connection (Base + Pro)** — new shared foundation in
  `includes/google/` (OAuth service, Calendar v3 client, scope registry,
  credential resolver, sync + push) replaces four drifted Google OAuth
  start/callback copies; a `google_calendar` connection type exists on both
  surfaces (base Settings → Integrations, Pro Remote Sites). Six new Pro
  tools in `addons/pro/includes/tools/google-workspace/`:
  `list_google_calendars`, `list_google_calendar_events`,
  `update_google_calendar_event`, `delete_google_calendar_event`,
  `check_google_calendar_availability`, `quick_add_google_calendar_event` —
  credentials resolve from an optional `connection_id` or site-level settings,
  and every write passes scope enforcement. Docs:
  `docs/developer/architecture/integrations/google-calendar-connection.md`.
- **Composio account health + hardening (Pro)** — verified account-health
  engine (`WP_MCP_AI_Composio_Account_Health`) with live catalog-discovered
  probes; new seventh tool `composio_manage_accounts`
  (validate/reconnect/delete/prune); proxied provider 401/403 → reconnect
  guidance; zero-argument calls send `arguments: {}`; Health column +
  Verify/Reconnect in Remote Sites.
- **Log hygiene** — tools declare non-loggable result fields via
  `WP_MCP_AI_Tool_Sensitive_Result_Interface` (`get_sensitive_result_fields()`,
  logging-only masking); credential-bearing URL query params are redacted
  from every logged string; rolling log buffers get per-entry byte budgets
  plus Data Management Compact/Delete.
- **Vision tools timeout** — `analyze_image`, `extract_image_text`,
  `generate_image_alt_text`, `generate_image_caption` accept a 5–300s
  `timeout` and inherit the global `request_timeout` (fixes cURL error 28 on
  large images).

---

## Plugin Internals Reference

### Key Classes

| Class | File | Purpose |
|-------|------|---------|
| `WP_MCP_AI_Tool_Registry` | `includes/class-wp-mcp-ai-tool-registry.php` | Singleton, `get_instance()->get_tools()` |
| `WP_MCP_AI_Credentials` | `includes/class-wp-mcp-ai-credentials.php` | Token issue/validate/revoke (`issue_credential()`, `validate_token()`, `parse_token()`) |
| `WP_MCP_AI_Admin_Settings_Base` | `includes/admin/class-wp-mcp-ai-admin-settings-base.php` | Settings defaults, sensitive field list, `OPTION_NAME = 'wp_mcp_ai_settings'` |
| `WP_MCP_AI_Api_Key_Store` | `includes/security/class-wp-mcp-ai-api-key-store.php` | Encrypted API key storage (AES-256-GCM), transparent plaintext migration |
| `WP_MCP_AI_REST` | `includes/class-wp-mcp-ai-rest.php` | MCP endpoint permission checks, auth methods |

### Key Options

| Option | Content |
|--------|---------|
| `wp_mcp_ai_settings` | All settings including provider API keys, model choices, feature toggles |
| `wp_mcp_ai_credentials` | Credential index (maps `cred_XXXXX` → assistant ID) |
| `wp_mcp_ai_env_keys_checked` | Flag: env var auto-detection has run (prevents re-scan) |
| `wp_mcp_ai_activated_version` | Last activated plugin version (skip heavy re-activation work) |

### Key Post Types

| Post Type | Purpose |
|-----------|---------|
| `mcp_ai_assistant` | AI assistants (meta: `_wp_mcp_ai_tools`, `_wp_mcp_ai_credentials`) |
| `mcp_ai_profession` | Profession templates for creating assistants via admin UI |

### Key Post Meta

| Meta Key | Type | Purpose |
|----------|------|---------|
| `_wp_mcp_ai_tools` | `array` of strings | Tool slugs assigned to the assistant |
| `_wp_mcp_ai_credentials` | `array` of credential records | Issued tokens (hashed) with creation/expiry metadata |

### Toolkit Categories (12 Built-In)

`content_publishing`, `media_processing`, `data_analytics`,
`ecommerce_business`, `developer_technical`, `security_compliance`,
`research_discovery`, `geospatial_location`, `workflow_automation`,
`communication_outreach`, `integration_external`, `ai_model_management`

Additional Pro toolkits are addons under `addons/pro/`.

### Files Changed by Plugin Fix Plan (Aug 2026)

| File | Fix |
|------|-----|
| `docker-compose.yml` | WSL2 comment (relative paths already correct) |
| `includes/integrations/class-wp-mcp-ai-custom-tool-loader.php` | `wp_mkdir_p()` + `is_dir()` guards before writes |
| `includes/paper-store/class-wp-mcp-ai-paper-store-manager.php` | `mkdir()` → `wp_mkdir_p()`, guards before writes |
| `includes/bootstrap/activation.php` | `wp_mcp_ai_auto_detect_env_keys()` + activation hook |

---

## Troubleshooting

### "invalid volume specification" on Docker start

**Cause:** Windows drive-letter path in a custom compose override
(`F:/GITHUB/...`).
**Fix:** Use WSL path (`/mnt/f/GITHUB/...`). The default `docker-compose.yml`
already uses relative paths (`.`), so this only affects custom setups.

### PHP warnings about file_put_contents / mkdir on startup

**Cause (v1.1.46 and earlier):** Plugin tried to write to `uploads/` subdirs
before they existed.
**Fix:** Upgrade to v1.1.47+ (Fix 2 applied). Quick workaround for older versions:
```bash
docker compose exec -T wordpress mkdir -p /var/www/html/wp-content/uploads/wp-mcp-ai-custom-tools
```

### tools/list returns []

**Cause:** Assistant has no tools assigned in `_wp_mcp_ai_tools` post meta.
**Fix:** Assign tools:
```php
update_post_meta( $assistant_id, '_wp_mcp_ai_tools', array( 'web_search', 'create_post', /* ... */ ) );
```

### "No AI providers configured" / tools return errors

**Cause:** No API key for the provider.
**Fix:** Set environment variables before starting Docker (Fix 3 auto-detects
them), or set keys via WordPress admin → oOS → Providers tab.

### Credential token invalid (401)

**Cause:** Token malformed, expired, or revoked.
**Check:** Token format must be `cred_XXXXX.SECRET`. Verify with:
```php
$parsed = WP_MCP_AI_Credentials::parse_token( $token );    // null = invalid format
$result = WP_MCP_AI_Credentials::validate_token( $token ); // WP_Error = invalid/expired
```

### web_search uses DuckDuckGo instead of Brave

**Cause:** `web_search_provider` not set to `brave` AND Brave API key not configured.
**Fix:** Ensure `brave_search_api_key` is set (via env var `BRAVE_API_KEY` or admin UI),
then set provider:
```php
$settings = get_option( 'wp_mcp_ai_settings', array() );
$settings['web_search_provider'] = 'brave';
update_option( 'wp_mcp_ai_settings', $settings );
```

### Env var API keys not detected after activation

**Cause:** Plugin was activated before Fix 3 was applied, or the flag
`wp_mcp_ai_env_keys_checked` is already set.
**Fix:** Reset the flag and re-trigger detection:
```php
delete_option( 'wp_mcp_ai_env_keys_checked' );
wp_mcp_ai_auto_detect_env_keys();
```
Or set keys manually via admin UI → oOS → Providers.

---

## Platform Extraction v2.0.0, Ecosystem Port Wave D + D-UI & Google Workspace Read Tools (v1.1.67+)

- **Content Graph platform extraction (v2.0.0)** — this platform addon now
  carries its own business logic (Waves A–C + Blueprints); see
  `docs/project/plans/content-graph-platform-extraction-plan.md`.
- **Base+Pro → Content Graph ecosystem port (Wave D + D-UI)** — the AI
  runtime lands in `nvoos-content-graph-ai` (chat core, providers, model
  management + analytics/token tracking, security guards, assistant admin
  pages); **Content Graph AI bumps 1.0.3 → 1.0.4**. Tracker:
  `docs/project/ecosystem-port-tracker.md`.
- **Google Workspace Gmail + Drive read tools (Pro)** — six new Pro tools
  (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`,
  `modify_gmail_message` — destructive-ops gated — and `get_drive_file`,
  `list_drive_connections`) with new Gmail/Drive clients on the shared
  `includes/google/` foundation.
- **PHPUnit repair campaign continuation (~95 PRs)** — the
  `mcp-ai-wpoos-test-suite` skill now distills 26 root-cause patterns.
  Tool count: ~303 base + ~1,262 Pro (~1,565 total).

## References

- Plugin repo: `https://github.com/nvdigitalsolutions/mcp-ai-wpoos`
- WordPress admin (Docker): `http://localhost:8000/wp-admin` (admin / password)
- MCP endpoint: `http://localhost:8000/wp-json/mcp-ai/v1/mcp`
- Status endpoint (public): `http://localhost:8000/wp-json/mcp-ai/v1/status`
- Settings option: `wp_mcp_ai_settings`
- Activation logic: `includes/bootstrap/activation.php`
- Credentials: `includes/class-wp-mcp-ai-credentials.php`
- API key store: `includes/security/class-wp-mcp-ai-api-key-store.php`
