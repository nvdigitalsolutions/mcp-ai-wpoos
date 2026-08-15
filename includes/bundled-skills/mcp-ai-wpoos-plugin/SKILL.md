---
name: mcp-ai-wpoos-plugin
description: Complete operational guide for the NV oOS (Open Operator System) WordPress plugin in Docker/WSL2 — setup, assistant creation, credential tokens, MCP tool calling, API key auto-detection, env var bridging, common fixes, and IGCSE study configuration. Use when setting up the plugin for the first time, creating assistants programmatically, generating MCP bridge tokens, troubleshooting Docker path issues, bridging API keys, or calling tools via JSON-RPC over HTTP.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.50"
  plugin-version-tested: "1.1.50"
  last-updated: "2026-08-10"
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
     │  Tool Registry     │  ~300 base / ~1,500 full tools
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

## References

- Plugin repo: `https://github.com/nvdigitalsolutions/mcp-ai-wpoos`
- WordPress admin (Docker): `http://localhost:8000/wp-admin` (admin / password)
- MCP endpoint: `http://localhost:8000/wp-json/mcp-ai/v1/mcp`
- Status endpoint (public): `http://localhost:8000/wp-json/mcp-ai/v1/status`
- Settings option: `wp_mcp_ai_settings`
- Activation logic: `includes/bootstrap/activation.php`
- Credentials: `includes/class-wp-mcp-ai-credentials.php`
- API key store: `includes/security/class-wp-mcp-ai-api-key-store.php`
