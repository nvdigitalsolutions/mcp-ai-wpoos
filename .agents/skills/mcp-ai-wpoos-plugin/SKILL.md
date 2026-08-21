---
name: mcp-ai-wpoos-plugin
description: Complete operational guide for the NV oOS (Open Operator System) WordPress plugin in Docker/WSL2 — setup, assistant creation, credential tokens, MCP tool calling, API key auto-detection, env var bridging, common fixes, and IGCSE study configuration. Use when setting up the plugin for the first time, creating assistants programmatically, generating MCP bridge tokens, troubleshooting Docker path issues, bridging API keys, or calling tools via JSON-RPC over HTTP.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.62"
  plugin-version-tested: "1.1.62"
  last-updated: "2026-08-22"
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
     │  Tool Registry     │  ~303 base / ~1,552 full tools
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
drive letters (`C:/...`, `/F:/...`). See Docker Compose header comment for details.

### 5. Symlinks on WSL2 / Windows

**`ln -s` silently creates copies on NTFS drives.** When you run `ln -s` from
WSL targeting a path on a Windows-mounted drive (e.g. `/mnt/f/...`), it does
NOT error — but it creates a real directory copy instead of a symlink. You
need Windows Developer Mode enabled OR admin privileges for true symlinks.

**Workaround: use NTFS junctions.** Junctions are directory-only reparse points
that work without admin and are transparent to both Windows and WSL:

```powershell
# PowerShell (from WSL):
Remove-Item "F:\path\to\link" -Recurse -Force
New-Item -Path "F:\path\to\link" -ItemType Junction -Target "F:\path\to\target"
```

```bash
# Or via cmd.exe (no admin needed for junctions):
cmd.exe /c "mklink /J F:\path\to\link F:\path\to\target"
```

**Batch creation** is best done via a PowerShell `.ps1` script, not a shell
loop — each `cmd.exe /c` spawns a new process with the copyright banner.

**When to use which:**

| Type | Command | Admin | Scope |
|------|---------|-------|-------|
| Junction | `mklink /J` | No | Directories only, same volume |
| Symlink (file) | `mklink` | Usually | Files, cross-volume OK |
| Symlink (dir) | `mklink /D` | Yes | Directories, cross-volume OK |
| WSL symlink | `ln -s` | Dev Mode | Only on WSL-native filesystems |

> **Design Stack**: `.agents/skills/design-*` and `mcp-ai-wpoos-plugin` use
> junctions pointing to `plugins/mcp-ai-wpoos/.agents/skills/`. The creation
> script is at `bin/create-skill-junctions.ps1`.

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
2. **Raw credential** (`Authorization: cred_xxxxx.SECRET` without `Bearer `) —
   accepted since v1.1.55 for agent configs that forward the header verbatim
   (e.g. Cloudways Agent); disable via filter
   `wp_mcp_ai_accept_raw_credential_header`
3. **OAuth 2.0** (authorization_code grant) — browser-based MCP app flow
4. **Mesh Key** (`X-WP-MCP-AI-Mesh-Key` header) — mesh federation
5. **WordPress nonce** (`X-WP-Nonce` header + auth cookie) — browser admin

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

### HTTP status semantics (v1.1.55+)

JSON-RPC **error envelopes are returned with HTTP 200** so client SDKs that
drop non-2xx bodies (e.g. the TypeScript SDK bundled with `mcp-remote`)
still relay errors to the agent. Pre-1.1.55, errors were returned as
400/404/500 and such SDKs **silently discarded them** — tool calls appeared
to return nothing. Auth failures (401/403) and pre-dispatch guards (429)
keep their HTTP statuses. Revert via filter `wp_mcp_ai_mcp_error_http_status`.

---

## MCP Transports & Client Compatibility

The `/wp-json/mcp-ai/v1/mcp` endpoint supports **two transports**, chosen by
the request shape:

| Transport | How it connects | Response channel |
|-----------|-----------------|------------------|
| **Streamable HTTP** (default) | `POST /mcp` JSON-RPC, `Accept` includes `application/json` | JSON body on the POST response, HTTP 200/202 |
| **Legacy HTTP+SSE** (v1.1.55+, flag `WP_MCP_AI_LEGACY_SSE_ENABLED`) | `GET /mcp` with SSE-only `Accept: text/event-stream` (or `?stream=true`) → `event: endpoint` with `session_id`; then `POST /mcp?session_id=...` | POST returns 202; responses arrive on the GET stream as `event: message` frames |

**Discriminator rule:** `Accept` containing `text/event-stream` **and not**
`application/json` → legacy SSE handshake. Mixed `Accept` headers
(`application/json, text/event-stream`) always get JSON — that is deliberate,
because Zed, Cursor, LM Studio and mcp-remote all send the mixed header but
expect JSON responses.

**Session model (legacy SSE):** sessions are owned by the credential that
opened them (SHA-256 hash of the `Authorization` header); message POSTs with
a different credential get 404. Caps: 5 sessions/credential, 20 global,
30-min TTL (`wp_mcp_ai_sse_session_ttl` / `wp_mcp_ai_sse_max_per_credential` /
`wp_mcp_ai_sse_max_total`). Store: `WP_MCP_AI_SSE_Session_Store`
(`includes/rest/class-wp-mcp-ai-sse-session-store.php`).

**`GET /sse` is NOT a message channel** — it streams the assistant directory
(`event: directory`). Legacy SSE clients cannot complete a session there.
`GET /no-sse` returns the directory as JSON.

### Client compatibility quick reference

| Client | What works |
|--------|-----------|
| Zed / Cursor / LM Studio / new SDKs | Native Streamable HTTP (`url` = `/mcp`) |
| Python MCP SDK | `streamable_http_client(url, headers=...)` works; `sse_client(url)` **requires v1.1.55+** for the handshake |
| mcp-remote bridge (Claude Desktop, older agents) | `npx -y mcp-remote@latest <url> --header "Authorization: Bearer <token>"` — connects via Streamable HTTP, needs Node 24+ |
| Cloudways Agent 0.19.0 / Codex-style agents | A bare `url:` is treated as **SSE transport** — use the mcp-remote stdio bridge, or point at the `/mcp` endpoint on v1.1.55+ |

### mcp-remote stdio bridge pattern (verified)

For agents whose MCP client only speaks legacy SSE over a configured URL
(Cloudways Agent, Claude Desktop):

```yaml
mcp_servers:
  nv-oos-sophie-agent:
    command: npx
    args:
      - "-y"
      - "mcp-remote@latest"
      - "https://<site>/wp-json/mcp-ai/v1/mcp"
      - "--header"
      - "Authorization: Bearer cred_xxxxx.SECRET"
    enabled: true
```

mcp-remote auto-detects the transport (`http-first` strategy) and exposes the
remote tools over local stdio. **Note:** mcp-remote opens a GET SSE stream
for server-initiated messages and retries it on failure — on pre-1.1.55
servers that GET hits the request rate limiter, and the retry loop can burn
the whole hourly quota (see Rate Limiting below).

### SSH-only sites — `bin/mcp-bridge-ssh.js` (Zed stdio over SSH)

For sites with **no public web route** (SSH is the only way in), use the
repo's own bridge, which owns the SSH port-forward and delegates to
`bin/mcp-bridge.js` (newline-delimited stdio ↔ Streamable HTTP relay). No
manual tunnel, no mcp-remote, no npm dependencies — Zed spawns it as a stdio
context server.

**Prerequisite:** SSH key auth (`ssh <user>@<host> -p <port>` must succeed
non-interactively). Password prompts are impossible for a spawned process
with no TTY.

Zed `context_servers` entry (Settings → AI → MCP Servers → Add Local Server):

```json
{
  "command": "node",
  "args": ["bin/mcp-bridge-ssh.js"],
  "env": {
    "MCP_AI_SSH_USER": "user",
    "MCP_AI_SSH_HOST": "203.0.113.10",
    "MCP_AI_SSH_PORT": "2222",
    "MCP_AI_SSH_REMOTE_PORT": "80",
    "MCP_AI_TOKEN": "op_xxxx.SECRET"
  }
}
```

Key env vars: `MCP_AI_SSH_REMOTE_HOST`/`MCP_AI_SSH_REMOTE_PORT` (web server
from the server's perspective; probe with curl over SSH — Cloudways Apache
may sit on 8080), `MCP_AI_HOST_HEADER` (canonical host override when the
web server 301-redirects on Host), `MCP_AI_SSH_EXTRA_ARGS` (e.g.
`"-i C:/keys/id_ed25519 -o ProxyJump=bastion"`), `MCP_AI_ENV_FILE` (default
`~/.nvoos-bridge.env` — keep the token out of settings.json).

Operator-credential audience binding is checked against `home_url()` from
the database, not the request Host header, so `http://127.0.0.1:<port>`
requests through the tunnel validate normally.

Orphan-proofing: the bridge holds ssh's stdin open so a hard-killed bridge
closes the pipe and ssh exits — no stray tunnels. Tests:
`node bin/test-mcp-bridge-ssh.js`.

For driving a **Hermes Agent** (Nous Research) itself rather than the NV oOS
console — its WebUI API over public HTTPS — see `bin/hermes-mcp-server.js`
(tools: `hermes_chat`, `hermes_list_sessions`, `hermes_session_detail`,
`hermes_sync_skills`; runbook: `docs/operations/fleet/hermes-operator-setup.md`
§7). The server also auto-syncs `.agents/skills/` to the agent on startup
(`HERMES_SYNC_SKILLS_ON_START=1`, default); the standalone CLI
`bin/sync-skills-to-hermes.js` does the same from cron or a git post-merge hook.

---

## Rate Limiting & Agent Traffic

Two independent limiters apply to MCP traffic:

1. **General request limiter** — `rate_limit_requests` per
   `rate_limit_window` seconds per user/IP (oOS → Security → Network & Headers).
   GET/HEAD requests are exempt since v1.1.55 (discovery/SSE probes must not
   consume the budget aimed at state-changing traffic).
   **For AI-agent workloads set `rate_limit_requests` to 1000** — the default
   300 is for chat usage, and client retry loops burn requests fast.
2. **Tool execution limiter** — v1.1.55+ settings under oOS → Security →
   **Tool Rate Limiting**: `tool_rate_limit_max` (default 300, 0 = unlimited),
   `tool_rate_limit_window` (default 60s), `tool_rate_limit_exempt_tokens`
   (default **on** — credential-token/agent traffic is exempt because an
   assistant credential is already an explicit grant of its tool set).
   Pre-1.1.55 this was a hardcoded 60 calls/60s with no UI — agents burst
   through it routinely.

**Symptom of a tripped tool limiter on pre-1.1.55 servers:** tools/call
returns HTTP 500 with a `-32603 "Tool rate limit exceeded"` error body —
which mcp-remote-style SDKs silently drop, so the agent sees no response
at all. With v1.1.55+ the same condition returns a visible JSON-RPC error
(and is unlikely to trip for agent tokens).

---

## Restricted Users & Chat Rate Limits (v1.1.60+)

The plugin converts ephemeral rate-limit and token-budget blocks into
**persistent restriction records** (`WP_MCP_AI_Restriction_Registry`) so
admins can review, lift, or add them.

- **Chat rate limit** — the hardcoded 60 req/min chat cap is now filterable:
  `wp_mcp_ai_chat_rate_limit` and `wp_mcp_ai_chat_rate_limit_window`
  (WordPress bridge → `ChatOrchestrator::setChatRateLimit()`).
- **Enforcement hooks** — `wp_mcp_ai_tool_token_limit_exceeded`,
  `wp_mcp_ai_per_session_limit_exceeded`, and the new
  `wp_mcp_ai_rate_limit_exceeded` (fired by the OOS `RateLimiter` adapter)
  feed the registry; records auto-expire on a daily cleanup cron and are
  audit-logged.
- **Admin surfaces** — Token Manager "Restricted Users" panel (Base) with
  one-click lift; Pro Command Center **Restrictions** tab (KPI cards, live
  table, lift actions); dismissible notices toggled from Settings →
  Orchestration → Restriction Notifications (`enable_restriction_admin_notices`).
- **WP-CLI** — `wp mcp-ai restrictions list|lift|add`.
- **REST** — `GET /mcp-ai/v1/restrictions`,
  `GET|POST /mcp-ai/v1/users/{id}/restrictions`,
  `DELETE /mcp-ai/v1/users/{id}/restrictions/{type}`; rate-limited responses
  carry IETF headers (`RateLimit-Policy`, `RateLimit`, `Retry-After`).
- Full reference: `docs/features/security/user-restrictions.md`.

## Conversation Import (v1.1.60+, JetEngine)

Import external AI conversation exports into the JetEngine
`ai_chat_transcripts` CCT (one row per conversation):

- **Formats** — ChatGPT `conversations.json` (incl. ZIP), Google Takeout
  Gemini activity, Claude `conversations.jsonl`, ShareGPT, OpenAI
  fine-tuning JSONL.
- **Tools** — `conversation_import_detect|run|status|delete` (require
  `manage_options`; JetEngine must be active).
- **WP-CLI** — `wp mcp-ai conversation-import detect|import|status|delete`
  (`--dry-run`, `--policy=skip|refresh`, `--resume-token=`).
- **Admin** — upload/preview page with progress reporting; GDPR
  export/erase + retention coverage; optional memory mining via
  `mine_agent_memory`. Guide: `docs/user-guides/conversation-import.md`.

## Agent Identity Bridging & OKF Bundle (v1.1.61+)

- **Agent identity bridging** — `store_agent_context` resolves virtual agent
  keys (e.g. `nvoos-pro-spa-memory-drawer`) to the canonical assistant post
  ID (`WP_MCP_AI_Agent_Identity_Resolver`, alias map in the
  `wp_mcp_ai_agent_id_aliases` option); the envelope echoes
  `original_agent_id` / `agent_id_resolved`. Chat-memory recall merges alias
  buckets (`stored_under` per record) and the memory drawers show
  scope/stored-under chips, the agent ID, and a show-all-scopes toggle.
**OKF bundle.** The `skill-knowledge` bundle is auto-generated from
  bundled skills on bootstrap (and after bundled-skill reinstall), so
  `okf_search` works out of the box (no more "OKF bundle not found").

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

### semantic_content_search returns "No OpenAI API key has been configured"

**Cause (pre-1.2.0):** the tool picked the embedding backend from the
assistant's chat provider and hard-failed on OpenAI. Since v1.2.0 embeddings
are resolved independently of the chat provider — configure **any** embedding
backend:

| Backend | Setting |
|---|---|
| OpenAI | `openai_api_key` |
| Gemini | `gemini_api_key` |
| Ollama (local) | `ollama_endpoint_url` |
| DigitalOcean | `digitalocean_api_key` |

Pin a specific backend with `embedding_provider` (`openai`, `gemini`,
`ollama`, or `digitalocean`). With no backend configured, the tool falls back
to keyword search (`fallback_mode: "keyword"`) instead of failing.

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

### Agent logs "unhandled errors in a TaskGroup (1 sub-exception)" / tools never load

**Cause:** the client is using the legacy SSE transport against `/mcp`, but
the server answers JSON (`SSEError: Expected response with content type
'text/event-stream', got 'application/json'`).
**Fix:** switch the client to Streamable HTTP, use the mcp-remote stdio
bridge (see Transports section), or upgrade the server to v1.1.55+ where
GET `/mcp` with an SSE-only Accept serves a real SSE handshake.

### Tool call responses never come back (agent hangs on tools)

**Cause (pre-v1.1.55):** tool errors are returned as HTTP 400/404/500 with a
JSON-RPC error body; SDKs like the one in mcp-remote **silently drop
non-2xx responses**, so the pending request never resolves. This includes
rate-limit hits and every tool `WP_Error`.
**Fix:** upgrade to v1.1.55+ (errors return HTTP 200 with the JSON-RPC
envelope), or reduce tool-call frequency to avoid the 60/min tool limit.

### Constant "429 ... Failed to open SSE stream: Too Many Requests" from mcp-remote

**Cause:** mcp-remote's GET SSE-stream retry loop consumes the general
`rate_limit_requests` bucket (per user/IP). Each retry counts.
**Fix:** raise `rate_limit_requests` (1000 for agent sites) and upgrade to
v1.1.55+ (GETs exempt from the quota, real SSE stream stops the retry loop).

### `remote_wp_connection` / `ezuite_erp` calls hang, then return 0 bytes

**Cause:** these network-dependent tools route through the async job queue;
`mcp_wait_for_async_tool()` could poll up to 6 minutes (120 polls × 3s),
longer than Cloudflare's ~100s cutoff (524) — the request dies mid-wait.
**Fix:** v1.1.55+ bounds the wait to ~45s (filter `wp_mcp_ai_async_max_polls`,
default 15) and kicks stuck jobs inline (`kick_inline_if_stale`) so hosts
with a dead WP-Cron loopback self-heal; timeouts now surface as visible
errors instead of resets.

### Cloudflare blocks non-browser clients (error 1010) or 524 timeouts

**Cause:** Cloudflare WAF blocks default non-browser user agents (e.g.
Python `urllib`); long synchronous tool calls exceed the ~100s proxy cutoff.
**Fix:** use curl/browser-like user agents for probes, and prefer v1.1.55+
where long tools are bounded or delivered out-of-band (SSE message queue).

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
- MCP controller (transports, SSE handshake): `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`
- Legacy SSE session store: `includes/rest/class-wp-mcp-ai-sse-session-store.php`
- JSON-RPC dispatch & error mapping: `includes/class-wp-mcp-ai-rest-mcp-methods.php`
- Rate limiters: `check_rate_limit()` / `check_tool_rate_limit()` in `includes/class-wp-mcp-ai-rest.php`
- Implementation plans: `docs/developer/implementation-plan-mcp-agent-compat.md`, `docs/developer/legacy-sse-transport-plan.md`
