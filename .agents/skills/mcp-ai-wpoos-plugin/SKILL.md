---
type: Skill
name: mcp-ai-wpoos-plugin
description: Complete operational guide for the NV oOS (Open Operator System) WordPress plugin in Docker/WSL2 — setup, assistant creation, credential tokens, MCP tool calling, API key auto-detection, env var bridging, common fixes, and IGCSE study configuration. Use when setting up the plugin for the first time, creating assistants programmatically, generating MCP bridge tokens, troubleshooting Docker path issues, bridging API keys, or calling tools via JSON-RPC over HTTP.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.77"
  plugin-version-tested: "1.1.77"
  last-updated: "2026-09-11"
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
- WordPress.org submission prep — Plugin Check (PCP) runs, wp.org listing
  screenshots, packaging exclusions, and the compliance checklist are covered
  by the `.agents/skills/mcp-ai-wpoos-wporg-submission` skill instead of this
  one (see that skill for Docker PCP recipes and the CI gate anatomy)

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
     │  Tool Registry     │  ~303 base / ~1,568 full tools
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
3. **Nefarious usage monitor** — a third counter, deliberately namespaced
   (`wp_mcp_ai_nefarious_rate_limit_` transient) away from the chat REST
   limiter (`wp_mcp_ai_rate_limit_`): a shared counter would halve the
   configured chat budget and entangle the two enforcement paths.
   Rate-limit classification uses the dispatching request's real HTTP verb
   (internal dispatches like `rest_do_request`/WP-CLI are classified
   correctly instead of by the ambient request); GET/HEAD stay exempt.
   Custom emitters of `wp_mcp_ai_before_chat_request` may fire the legacy
   2-arg `( $messages, $request_data )` shape — core subscribers tolerate
   both it and the canonical
   `( $assistant_id, $messages, $options, $request )`.

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

## Telegram Delivery Fixes, Memory Bridge & Wave F2 Toolkit Completions (v1.1.75+)

- **Telegram broadcast credentials** (PR #6482) — inline credentials stored as
  JSON strings no longer fatal the array-typed broadcast tool;
  `normalize_channel_credentials()` decodes/rejects, and the Remote Sites
  schema mapping decrypts `api_key`/`token` for the real storage keys.
- **Scheduled delivery credential fallback** (PR #6488) — tier-4 fallback
  resolves the first enabled Remote Sites connection of the channel type for
  cron delivery; `create_pro_schedule`/`update_pro_schedule` and the schedules
  REST endpoint accept `result_delivery`; diagnostics never carry secrets.
- **Content Graph memory bridge + NV oOS Complete checkout** (PR #6486) —
  new `wp_mcp_ai_wake_up_context_graph_retriever` filter seam on
  `wake_up_context`; the checkout sells the Complete bundle (base + Pro) with
  a conflict guard; nvoos-content-graph **1.0.4 → 1.0.6**.
- **Wave F2 port completions** (PRs #6476–#6501) — `nvoos-content-graph-pro`
  (1.0.0) completes the financial-planning, social-media, and mcp-servers
  toolkit ports plus remote-sites/video/analytics/multilingual/cloudways/
  dj-management/image-production slices.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## DeepSeek V4.1 Flash, Base+Pro Gating & Memory CCT Slug (v1.1.77)

- **DeepSeek V4.1 Flash refresh** (PR #6555) — the catalog's DeepSeek lineup
  is now `deepseek-flash` (active, vision) + `deepseek-v4-pro` (deprecated,
  sunset 2026-09-14); V4 Flash + Vision Exp retired; stored references
  migrate on the catalog-version bump. Cost calculator gains peak/off-peak
  (`calculate_cost_at()` with a record timestamp; legacy `calculate_cost()`
  stays time-independent).
- **Base+pro gating** (PR #6561) — Pro toolkits now load in base+pro
  installs (gate escape `! $is_base || defined( 'WP_MCP_AI_PRO_VERSION' )`);
  new `tests/basepro/` matrix + `composer run test:basepro` pins it.
- **Pro WP-CLI load-order guard** (PR #6585) — no more
  `Undefined constant WP_MCP_AI_PATH` when Pro activates before the base
  plugin; the CLI loader defers to `plugins_loaded` 30.
- **Memory CCT canonical slug** (PR #6591) — Graphify bridge + Pro memory
  retention read `ai_agent_memories` (canonical) with a legacy-table
  fallback; dormancy sweeps, per-user caps, expiry pruning, and Memory
  Health stats start working.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Delivery Formats, Checkout Legal Series & Wave F2 Completions (v1.1.76+)

- **Chat delivery full report + per-channel formats** (PR #6525) — chat
  channels support the `full` template (summary + substantive response +
  envelope data); per-channel `format` allowlists (Telegram
  `html`/`markdown`/`markdown_v2`/`plain`; WhatsApp/Slack/Discord/Teams
  `markdown`/`plain`; Messenger/Google Chat `plain`); Telegram delivery
  routes through `send_telegram_message` directly so `parse_mode` works;
  `MarkdownV2` reserved-char escaping; group-mention skips log
  `telegram_group_mention_required_ignored` instead of silently dropping.
- **Duplicate-summary skip** (PR #6548) — assistant-run delivery skips the
  derived summary when the response already opens with it
  (`response_starts_with_summary()` normalizes tags/whitespace + strips
  the trailing ellipsis).
- **Comic Creation toolkit toggle + playbook seeder idempotency** (#6512,
  direct commit) — the `enable_comic_creation_toolkit` toggle is now
  registered (Tools section, Pro Features subtab, memory estimator,
  WP-CLI maps); `hash_playbook_content()` strips the `Generated:`
  timestamp so playbook syncs no longer recreate attachments.
- **Checkout launch-complete series** (#6507/#6520/#6523/#6550) — required
  ToS consent + buyer email, EU country selector + billing-address block,
  Stripe product/price metadata, license DB v3 (`dbDelta`), manual install
  as primary path (`/verify` returns `download_url`), commercial legal
  docs (`docs/legal/`). Checkout API stays **0.1.0**.
- **Security sweeps** (#6515/#6532/#6546/#6504) — 9 Dependabot alerts,
  SVGO CVE-2026-84370 `>=4.1.0`, svgo/hono/vitest bumps, docs-hub ZIP
  excludes `*.md` (keep `readme.txt`).
- **Wave F2 port completions + new port skill** (PRs #6505–#6549) —
  `nvoos-content-graph-pro` (1.0.0) completes ten toolkit ports
  (comic-creation, dj-management, ai-tool-builder, architect-agent,
  architectural-design, site-creator, document-generation,
  regulatory-registration, healthcare, law-firm); new coding-time skill
  `mcp-ai-wpoos-ecosystem-port` (skill count 55 → 56).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Checkout Connectivity Diagnostics & Pro CLI Load-Order Guard (v1.1.77+)

- **Checkout API addon `GET /health`** (PR #6573) — public, no Stripe, no
  rate-limit token, no writes; returns `status`/`service`/`version`/
  `configured`/`server_time`. Live on nvdigitalsolutions.com:
  `GET https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1/health`.
- **Checkout API admin "REST endpoints" section** (PR #6573) — live
  per-route registered/missing markers (from `rest_get_server()->get_routes()`
  endpoint lists) plus a nonce-protected loopback self-check of `GET /health`
  reporting HTTP status + latency.
- **Content Graph client diagnostics** (PR #6573) — `Vendor::health()` and
  the admin-only `GET /payments/health` route (deliberately NOT throttled,
  so diagnostics cannot trigger the "Too many checkout attempts" lockout);
  the purchase modal offers a "Test connection" action when session
  creation fails.
- **Checkout troubleshooting playbook** — two independent throttles: client
  (transients `nvoos_content_graph_commerce_{session,verify}_throttle_{uid}`,
  5 and 15 attempts per 10 min per user) and vendor (per server IP, 20
  session / 30 verify per 10 min on the checkout-api addon). Diagnose from
  the client site's server:

  ```bash
  # Outbound connectivity from the site's server (works even when other
  # plugins fatal under WP-CLI, see below):
  wp --skip-plugins eval '$main = glob( WP_PLUGIN_DIR . "/*nvoos-content-graph*/nvoos-content-graph.php" ); if ( empty( $main ) ) { exit; } require_once $main[0]; var_export( ( new \NvoosContentGraph\Commerce\Vendor( \NvoosContentGraph\Commerce\Payments::vendorApiUrl() ) )->health() );'

  # Clear the client throttles. If the remote shell mangles $variables,
  # ship the PHP base64-encoded instead:
  wp --skip-plugins eval 'for ( $i = 1; $i <= 200; $i++ ) { delete_transient( "nvoos_content_graph_commerce_session_throttle_" . $i ); delete_transient( "nvoos_content_graph_commerce_verify_throttle_" . $i ); }'
  ```

  The vendor-side per-IP bucket only clears with time (10 min).
- **Pro CLI load-order fatal** — when the Pro addon is activated before the
  base plugin, every `wp` command dies with `Undefined constant
  "WP_MCP_AI_PATH"` (Pro requires its CLI files at include time under
  WP_CLI; web requests are unaffected). Site fix: reorder `active_plugins`
  via `wp --skip-plugins eval` (move the `-pro` entry after the base
  entry). Code fix: PR #6585 defers the CLI require loop to
  `plugins_loaded` when the base constant is missing at include time.
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Checkout Purchase-Modal Redirect Bug & Release-Tag URL Fix (PR #6594)

Diagnosed live on victory.nvdigital.solutions (client) + nvdigitalsolutions.com
(vendor) when "the purchase modal says nothing and redirects to the GitHub
releases page" with everything seemingly configured correctly.

- **The silent-redirect failure mode.** `checkoutUnavailable()` — the only
  path that redirects to `fallback_url` — fires when the modal's
  `/payments/session` call returns 404/≥500 **or its `.catch` runs**. The
  `.catch` wraps the ENTIRE `.then` chain, so ANY throw after the session
  call (Stripe element setup included) is mislabelled as "checkout
  unavailable". Status-code errors (424/429/403) stay in-modal with a
  "Test connection" action; a redirect means the response was 404/5xx,
  non-JSON, or a post-session throw. The fallback note is shown only 1.2 s
  — users report it as "says nothing, just redirects".
- **Root cause found: invalid Stripe element name.** The modal called
  `elements.create( 'paymentElement', … )` — the core Stripe.js API name is
  **`payment`** (`paymentElement` is the React component name). Stripe threw
  `IntegrationError: A valid Element name must be provided … you passed:
  paymentElement` AFTER a 200 session, and the catch-all redirected. Fix
  (PR #6594): `create( 'payment' )` plus a try/catch around Stripe element
  setup that surfaces a new `stripe_setup_error` message in the modal
  instead of the misleading redirect. The no-browser contract verifier is
  `node plugins/nvoos-content-graph/scripts/verify-commerce-fallback.js`.
- **Release-tag convention mismatch (same PR).** GitHub releases moved to
  `nvdigital-oos-v*.*.*` tags (`build-nvdigital-oos-wporg.yml` is the
  active path; `release.yml` `v*` tags are the legacy wp.org path). The
  checkout-api `default_zip_source()` and the client `Payments::zipUrl()`
  fallback still built `releases/download/v{VERSION}/…` → post-payment
  downloads 404/502 while payment + license succeed. Symptom: buyer pays,
  then "Could not fetch the addon package: 404 Not Found" (vendor download
  server) or a broken fallback URL. Verify: `curl -I …/releases/download/
  nvdigital-oos-v1.1.76/nvdigital-open-operator-system-oos-complete-
  1.1.76.zip` (200) vs the `v1.1.76` tag shape (404). Live-site remedy
  without a release: edit the vendor's **ZIP source** setting to
  `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvdigital-oos-v{VERSION}/nvdigital-open-operator-system-oos-complete-{VERSION}.zip`
  (keep **Addon version** as the plain `1.1.76` — the tag prefix belongs in
  the ZIP source pattern, not the version field).
- **Browser-side diagnostics that worked** (config lives in
  `window.nvoosContentGraphCommerce` — `rest_url`, `nonce`, `fallback_url` —
  only on the content-graph admin page, admin-logged-in):
  1. Replicate the modal call from the console:
     `fetch( rest_url + '/payments/session', { POST, credentials 'same-origin', X-WP-Nonce: nonce, body '{}' } )`
     — 200 + `client_secret` proves vendor/keys/throttles all healthy
     (each call creates a real live PaymentIntent; cancel it via
     `POST api.stripe.com/v1/payment_intents/{id}/cancel`).
  2. Raw-body variant (`r.text()`) to catch **non-JSON** responses (PHP
     warnings/debug lines, Cloudflare challenge HTML) — those reject
     `response.json()` and trip the redirect.
  3. Monkey-patch `window.fetch` to log every `/payments/` request with
     `r.clone().text()` BEFORE clicking Buy — captures what the modal
     actually sends/receives regardless of the 1.2 s redirect.
  4. To isolate a throw, load the REAL `https://js.stripe.com/v3/` first,
     then wrap the real `window.Stripe` with logging at call → elements →
     create → mount. **Pitfall: replacing `window.Stripe` with a stub
     short-circuits `loadStripeJs` (`if ( window.Stripe )`), so the modal
     never downloads Stripe.js and the stub's undefined return throws — a
     self-inflicted false positive.**
  5. DevTools "pause on caught exceptions" first lands on Stripe's internal
     `ArrayBuffer()` bot-check `try/catch` — harmless noise. Press F8 until
     the pause is in `content-graph-commerce.js` or carries a non-Stripe
     message.
- **Server-side diagnostics from the client site's shell:**
  `curl -sS -X POST https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1/session -H 'Content-Type: application/json' -d '{"product":"nvoos-oos-complete","site_url":"https://victory.nvdigital.solutions"}'`
  tests the exact server→vendor path (outbound firewall/DNS/SSL issues the
  browser can't see); `wp eval '$v = new \NvoosContentGraph\Commerce\Vendor( \NvoosContentGraph\Commerce\Payments::vendorApiUrl() ); …'`
  runs the plugin's own `health()`/`createSession()` on the site's server.
- **Live config facts (verified):** vendor `GET /health` is public;
  `POST /session` requires `product` ∈ {`nvoos-oos-complete`,
  `nvoos-content-graph-ai`} + `site_url`; a webhook signature test =
  HMAC-SHA256 over `{ts}.{payload}` with the dashboard `whsec_` sent as
  `Stripe-Signature: t={ts},v1={hex}` — a benign `charge.succeeded` event
  answering `{"received":true}` proves the dashboard secret matches the
  plugin's stored one; the Stripe webhook endpoint must subscribe
  `payment_intent.succeeded` + `charge.refunded` (+ optional
  `charge.dispute.created`); the client's commerce REST routes are
  **admin-only by design** (anonymous 403/401 is normal); if the base NV oOS
  plugin is already active on the client site (`mcp-ai/v1` in `/wp-json/`),
  the post-payment install correctly 409s (conflict guard) with a
  manual-download link — purchases on such a site need no install.

## Calendar Query Fix, Email Formats & Wave F2 PM/Calendar (v1.1.74+)

- **Google Calendar date queries** (PR #6460) — calendar query values are
  now `rawurlencode()`d before `add_query_arg()` (the raw `+` in RFC3339
  offsets decoded as a space → 400); `calendar.freebusy` in the Standard
  scope profile (new grants only).
- **Result Delivery email formats** (PR #6465) — new
  `WP_MCP_AI_Markdown_Converter` (escaped + `wp_kses` allowlist +
  protocol-allowlisted links) + per-channel `format` setting (`both`
  default | `html` | `markdown`).
- **Schedule Manager assistant prompts** (PR #6469) — the edit modal shows
  and updates `assistant_run` prompts; `update_pro_schedule` accepts
  `assistant_config` via MCP.
- **Wave F2 PM + calendar-booking ports** (PRs #6450–#6472) —
  `nvoos-content-graph-pro` (1.0.0) completes both toolkit ports;
  perf-suite MCP-abilities fix process-wide in `tests/bootstrap.php`
  (#6470); content-graph-pro excluded from the root WPCS gate (#6457).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Woo Tool Upgrades, Queue Bootstrap Fix & Wave F2 (v1.1.73+)

- **`bulk_update_products` variable scope** (PR #6447) — new `scope`
  argument (`all` default, `product` legacy) expands price/stock fields
  from variable parents to variations and grouped parents to children
  (`resolve_update_targets()` + `WC_Product_Variable::sync()`); the
  response reports `targets[]` per input ID + `scope`/`updated_targets`
  keys (acknowledged shape change).
- **`update_woo_product_qty` notify flag** (PR #6448) — `notify` (default
  `true`) suppresses low/no-stock emails for the write via scoped
  `woocommerce_should_send_*` filters removed in a `finally`;
  `woocommerce_*_stock` actions still fire.
- **Async job queue table bootstrap fix** (PR #6423) — the queue class now
  boots in time to create its table (activation + first-load self-heal;
  `get_queue_stats()` fails soft) — no more missing-table SQL floods.
- **Comic Reader 0.5.0** (PR #6402) — Komga-parity upgrade; **Docs Hub
  0.4.3** (PRs #6397/#6403) — wp.org prep; **Wave F2** (PRs #6397–#6445,
  #6449) — new `nvoos-content-graph-pro` v1.0.0 standalone addon (Pro CRM
  + e-commerce ports, 43 e-commerce tools).
- **Tool count** — unchanged: ~303 base + ~1,265 Pro (~1,568 total).

## Woo Price/Qty Tools & Ecosystem Port Waves (v1.1.72+)

- **WooCommerce price & quantity tools** (PR #6388) —
  `update_woo_product_price` (regular/sale, all product types) and
  `update_woo_product_qty` (stock + management) share the new
  `WP_MCP_AI_Woo_Price_Qty_Updater` trait; `bulk_update_products` uses it;
  tool presets register the new slugs.
- **Scheduled sync connection ID** (PR #6386) — EZuite/FlowHub scheduled
  syncs deliver the assigned connection ID (the scheduled action was
  dropping it).
- **Pro update vendor integrity** (PR #6338) — "Update Pro Now" verifies the
  Pro package's `vendor/` before and after updating.
- **Container binding** (PR #6339) — `tool_registry` is registered
  `transient`; every `get()` resolves the live singleton (test-swap safe).
- **Deps + defaults** (PRs #6365, #6332) — browserslist/qs patched (12
  Dependabot alerts); `gpt-image-2` aligned across all three settings
  layers.
- **Ecosystem ports** (PRs #6330–#6387) — Wave D8 closes the standalone
  tool-execution gap in Content Graph AI; Wave E6 engine pieces fold into
  the AI addon; the platform addon closes Waves E2/E3/E5/E1/E4 +
  E-UI-1/2/3. Sub-project versions unchanged (1.0.4 / 1.0.4 / 2.0.0).
- **Tool count** — +2 Pro: ~303 base + ~1,265 Pro (~1,568 total).

## Rate-Limit Unlock, Model Catalog & Ecosystem (v1.1.71+)

- **REST rate-limit unlock** (PR #6322) — `check_rate_limit()` uses fixed-window
  accounting (`{count, first_seen}` payload; TTL never extended past window end)
  with honest remaining-time `retry_after`; the new
  `wp_mcp_ai_rest_request_rate_limit_exceeded` action flags the restriction
  registry, so blocked users appear in Command Center → Restrictions with the
  Lift button (lifting also clears the `wp_mcp_ai_rate_limit_user_{id}`
  transient; guest IP-keyed blocks expire on their own).
- **MemPalace wing scope** (PR #6327) — `wake_up_context` enforces
  `wing`/`room` exclusions (`matches_wake_filters()`); Graphify graph anchors
  only boost scores and never excluded out-of-scope memories.
- **September 2026 model catalog** (PR #6328) — 228 models; new defaults
  `default_gemini_model` → `gemini-3.6-flash`, `openai_image_model` →
  `gpt-image-2`, Kimi default → `kimi-k3`; retired DeepSeek chat/reasoner/coder,
  `gemini-3.1-flash`, `imagen-4` have migration-map successors.
- **Checkout API** (PR #6315) — addon joins the standard build + PHPUnit
  pipeline; token/crypto classes derive `wp_salt()` salts (never raw
  `AUTH_KEY . SECURE_AUTH_KEY`). Connectors links → `options-connectors.php`
  (PR #6314). Coding-time agent skills: 53 → **54** (`mcp-ai-wpoos-updates`).
- **Tool count** — unchanged: ~303 base + ~1,263 Pro (~1,566 total).

## Wave-5 Repair Campaign & Host-Hardening Fixes (v1.1.70+)

- **exec-disabled hosts** — on PHP 8+ a disabled function throws a fatal
  `Error` that `@` cannot suppress; never call `exec`/`shell_exec`/`proc_open`
  unguarded. Use `wp_mcp_ai_check_nodejs_available()` /
  `wp_mcp_ai_get_nodejs_version()` (exec-first, Process Service fallback) and
  the OCR service's `is_cli_tool_available()` / `run_cli_command()` helpers;
  sanitizers clamp with `max( 0, … )`, never `absint()` (it flips negatives).
- **Wave-5 fixes** (PRs #6280–#6312) — transcript `attachments` metadata
  preserved; complexity-based model routing restored; JetEngine gates require
  `JET_ENGINE_VERSION` + a physical CCT-table probe (`is_storage_available()`);
  mesh peer URLs validated before `esc_url_raw()`; Auth0 audiences validated
  structurally (no DNS); image/chart permission checks use the **acting user**;
  markup REST validation errors return `400`; the legacy SSE handshake is
  filterable (`wp_mcp_ai_legacy_sse_enabled`); `wp_mcp_ai_pro_get_tool_map()`
  caches the Pro tool map; settings-repository reads fall back to the canonical
  `wp_mcp_ai_settings` blob so runtime gates see dashboard-saved values;
  agentic events reach the recent-activity feed.
- **Tool count** — unchanged: ~303 base + ~1,263 Pro (~1,566 total).

## Vision Analysis, tagDiv Compat, DeepSeek Schemas & ZipSlip Revival (v1.1.69+)

- **Vision Analysis toolkit (Pro)** — new `analyze_image_objects` tool
  counts objects per category (HF OWLv2 / Ollama `detection`, VLM `vlm`,
  hybrid label normalization; `annotate=true` returns a GD box-annotated
  attachment). Gated by `enable_vision_analysis_toolkit` on the NV oOS →
  Vision Analysis settings page — **off by default**; remote image URLs go
  through the SSRF URL guard. Docs: `docs/toolkits/vision-analysis-toolkit.md`.
- **tagDiv Newspaper admin compat** — the four SiteKit tools return string
  capability-flag arrays (the old `CAPABILITY_CAN_USE_IF_ADMIN` constant
  never existed and fatalled the assistant tools metabox at
  `sitekit_get_adsense`); the sortable shim prints a bundled jQuery UI
  1.14.2 copy whenever `td_wp_admin` is enqueued (detected via the print
  queue — a registered+enqueued core handle can still fail to execute);
  tools metabox CSS is enqueued on `admin_enqueue_scripts` so it prints in
  the head.
- **Tool schemas: `properties` must be an object** — argument-less tools
  encode `"properties": {}` (never `[]`; DeepSeek returns 400 otherwise).
  `LegacyToolAdapter` preserves object maps and upgrades empty arrays; the
  AI Tool Builder scaffold emits `new stdClass()` for parameterless tools.
- **ZipSlip guard** — `ZipArchive::$num_files` does not exist on PHP 8.x;
  the entry loops must use `count( $zip )` (OKF bundle manager + four Pro
  admin pages). The guard was silently dead code.
- **Rate limiting** — `check_rate_limit()` classifies internal dispatches by
  the dispatching request's real HTTP verb; the nefarious monitor keeps its
  own `wp_mcp_ai_nefarious_rate_limit_` counter (never share the chat REST
  limiter's — it halves the chat budget). `wp_mcp_ai_before_chat_request`
  subscribers tolerate the legacy 2-arg emitter shape.
- **Settings save** — capture `wp_suspend_cache_addition()` state *before*
  suspending (the function returns the new state, so reading it after
  suspends re-applies `true` and jams inline-async tick locks on WP 7.1).
- **Tool count** — ~303 base + ~1,263 Pro (~1,566 total).

## Front-End Surfaces, Nonce Self-Heal & Provider Defaults (v1.1.68+)

- **Pro SPA v2 shortcode** — `[nvoos_pro_spa]` embeds the Pro SPA v2 chat
  surface on the front end (chat-first embedded mode: threads, drawers,
  tool shortcuts, OKF drawer; router-free; optional guest mode behind the
  "Allow Guest Access" setting). Proposal 033.
- **Hermes dashboard fleet extensions** — new top-level `extensions/` tree
  (fleet monitoring + control plane, backup-download, external-app-tab,
  mcp-tool-shortcuts; `install.sh` + smoke tests). Plans in
  `docs/developer/integration/`.
- **Stale REST nonce self-heal** — `GET /mcp-ai/v1/session/nonce` mints a
  fresh session-bound nonce from the request's own auth cookie (`no-cache`/
  `no-store`); chat surfaces retry `403 rest_cookie_invalid_nonce` failures
  instead of showing "Cookie check failed" (full-page caching / SPA session
  rotation).
- **Docs Hub 0.4.2** — local-page links resolve to in-app `#/slug` routes,
  TOC anchors match github-slugger, "Accept fix" suggestions are
  directory-relative with `../` validation + skip reasons, sync failures
  surface "Atomic swap failed", and the emoji loader no longer crashes the
  React SPA on docs-browser pages.
- **Provider enable defaults (fresh installs)** — OpenAI/Anthropic/Gemini
  now default to **disabled**; provider dropdowns list only enabled +
  credentialed providers (`get_available_providers()`); the onboarding
  wizard auto-enables the provider whose key you enter. When helping users
  with "no providers available", check both the key **and** the enable
  checkbox.
- **Grouped fixes (third test wave, ~21 PRs)** — calendar granted-scopes
  `%20` normalization; agent-identity canonical-ID int cast; token-budget
  catalog dedup + `wp_mcp_ai_model_tpm_limit` filter seam; assistant
  untrash restores the pre-trash status; presets gain missing tools;
  `wp_mcp_ai_seed_task_templates` AJAX; `fast-uri` >=4.1.4; Tiptap 3.30.4
  pins; jQuery UI sortable shim on post edit screens. Tool count unchanged:
  ~303 base + ~1,262 Pro (~1,565 total).

## Platform Extraction v2.0.0, Ecosystem Port Wave D + D-UI & Google Workspace Read Tools (v1.1.67+)

- **Content Graph platform extraction (v2.0.0)** — the
  `nvoos-content-graph-ai-platform` addon now carries its own business
  logic (Waves A–C + Blueprints: namespace-bridged admin UI, skill/slash-
  command/agent bridges, harness router, 74-skill bundled-skills pack,
  knowledge base, `.github/workflows/phpunit-platform.yml`). Plan:
  `docs/project/plans/content-graph-platform-extraction-plan.md`.
- **Base+Pro → Content Graph ecosystem port (Wave D + D-UI)** — the AI
  runtime lands in `nvoos-content-graph-ai` (chat core, providers beyond
  the 13, model management + analytics/token tracking, security guards,
  assistant admin pages, blocks/widgets/guest tokens/memory/CLI/MCP
  JSON-RPC); **Content Graph AI bumps 1.0.3 → 1.0.4**. Tracker:
  `docs/project/ecosystem-port-tracker.md`.
- **Google Workspace Gmail + Drive read tools (Pro)** — six new Pro tools
  (`get_gmail_message`, `get_gmail_thread`, `list_gmail_connections`,
  `modify_gmail_message` — destructive-ops gated — and `get_drive_file`,
  `list_drive_connections`) with new `WP_MCP_AI_Pro_Gmail_Client` /
  `WP_MCP_AI_Pro_Google_Drive_Client` clients on the shared
  `includes/google/` foundation.
- **PHPUnit repair campaign continuation (~95 PRs, Aug 31 – Sep 2)** — a
  second cluster wave kept the suite green through the extraction/port
  work; the `mcp-ai-wpoos-test-suite` skill now distills 26 root-cause
  patterns. Production seams to know about: `unregister_section()` on the
  settings registry; public `get_tool_multiplier()`; null-safe REST
  tool-error reporting; WhatsApp webhook signature rejection without an
  app secret; destructive-ops gate reads the canonical combined-settings
  array; crawler `base_url` validation + `wp_mcp_ai_crawl4ai_auto_spawn_cron`
  filter. Tool count: ~303 base + ~1,262 Pro (~1,565 total).

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

### Integration contracts (updated Aug 2026)

Notable filter/API contract changes from the recent test-suite repair
clusters — relevant when writing integrations against these seams:

| Seam | Contract |
|------|----------|
| `wp_mcp_ai_vendor_package_paths` | Filter on the NPM vendor package-path map in `wp_mcp_ai_check_vendor_packages()`; lets integrations remap/inject package locations (PR #6097) |
| `wp_mcp_ai_chat_transcript_handler` | Now invoked with **7 args** (`$handler, $assistant_id, $messages, $options, $response, $request, $context`) by `WP_MCP_AI_Chat_Transcript_Recorder::resolve_handler()` |
| Chat message `content` shape | The REST validator stores message content as **segments**: `array( array( 'type' => 'text', 'text' => '…' ) )` — affects `/chat-transcripts` payloads and `extract_request_messages()` consumers |
| `WP_MCP_AI_Profession_CPT::sanitize_memory_files()` | Negative IDs now **clamp to 0 and are dropped** (previously `absint()` coerced e.g. `-999` → `999`) — PR #6105 |
| Graphify `on_plugins_loaded` | Admin classes (`NV_oOS_Graphify_Settings`, `NV_oOS_Graphify_Remote_Admin`) are now required defensively before `init()` when `is_admin()` — PR #6106 |

## Development & Test Suite

For the PHPUnit repair workflow — Docker test commands (WP 6.9 + 7.1),
CI log triage, the recurring root-cause patterns, and the cluster-by-cluster
PR conventions — see the dedicated skill
[`mcp-ai-wpoos-test-suite`](../mcp-ai-wpoos-test-suite/SKILL.md) and
`.context/testing.md` for test-writing patterns and the coverage policy.

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

**Cause:** No API key for the provider, or the provider's enable flag is off.
**Fix:** Set environment variables before starting Docker (Fix 3 auto-detects
them), or set keys via WordPress admin → oOS → Providers tab.

Since the provider-defaults fix (PR #6255), fresh installs keep **all** cloud
providers disabled by default: after adding an API key, also check the
"Enable … Provider" checkbox on Settings → NV oOS → AI Providers (each
provider's subtab), or enter the key via the onboarding wizard, which
auto-enables the provider whose key you provide. Provider dropdowns elsewhere
in admin only list providers that are both **enabled** and **credentialed**
(`WP_MCP_AI_Model_Config::get_available_providers()`), so an enabled-but-keyless
or keyed-but-disabled provider is invisible in dropdowns.

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
