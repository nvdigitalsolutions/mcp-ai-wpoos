# JetEngine MCP Server Integration Architecture

## Overview

JetEngine 3.8.0 introduces a native **MCP (Model Context Protocol) Server** at `/wp-json/jet-engine/v1/mcp/` using **JSON-RPC 2.0** protocol. This document describes how NV oOS integrates with this server.

## Protocol

JetEngine's MCP server uses JSON-RPC 2.0 over HTTP POST:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list",
  "params": {}
}
```

Response:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [
      {
        "name": "create_post_type",
        "description": "Create a new custom post type",
        "inputSchema": { ... }
      }
    ]
  }
}
```

### Supported Methods

| Method | Description |
|--------|-------------|
| `initialize` | Server handshake, returns capabilities |
| `tools/list` | Enumerate available tools with schemas |
| `tools/call` | Execute a tool by name with arguments |
| `resources/list` | List site resources (post types, taxonomies, meta boxes, glossaries, macros, relations) |
| `prompts/list` | List available prompt templates |
| `prompts/get` | Render a prompt with arguments |

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                  NV oOS Plugin (AI Assistant)            │
│                                                         │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ MCP Bridge   │  │ Convenience  │  │  Prompts     │  │
│  │ Tool         │  │ Tools (5)    │  │  Tool        │  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  │
│         │                 │                 │           │
│  ┌──────┴─────────────────┴─────────────────┴───────┐  │
│  │         WP_MCP_AI_JetEngine_MCP_Client           │  │
│  │  (JSON-RPC 2.0 client with caching & auth)       │  │
│  └──────────────────────┬───────────────────────────┘  │
│                         │                               │
│  ┌──────────────────────┴───────────────────────────┐  │
│  │      WP_MCP_AI_JetEngine_Compat (updated)        │  │
│  │  has_mcp_server() → MCP path                     │  │
│  │  !has_mcp_server() → Legacy REST v2 path         │  │
│  └──────────────────────┬───────────────────────────┘  │
│                         │                               │
└─────────────────────────┼───────────────────────────────┘
                          │
            ┌─────────────┼──────────────┐
            │             │              │
    ┌───────▼──────┐ ┌───▼────┐ ┌───────▼──────┐
    │ tools/list   │ │resources│ │ prompts/list │
    │ tools/call   │ │ /list  │ │ prompts/get  │
    └──────────────┘ └────────┘ └──────────────┘
            │             │              │
    ┌───────┴─────────────┴──────────────┴──────┐
    │    JetEngine 3.8+ MCP Server              │
    │    /wp-json/jet-engine/v1/mcp/            │
    │    (JSON-RPC 2.0)                         │
    └───────────────────────────────────────────┘
```

## Key Classes

### `WP_MCP_AI_JetEngine_MCP_Client`

**File**: `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-client.php`

JSON-RPC 2.0 client that communicates with JetEngine's MCP server.

**Transport**:
- **Internal** (same site): Uses `rest_do_request()` to avoid HTTP overhead
- **Remote** (multisite/external): Uses `wp_remote_post()` with auth headers

**Methods**:
- `initialize($use_cache)` — Server handshake
- `tools_list($use_cache)` — List tools
- `tools_call($name, $arguments)` — Execute a tool
- `resources_list($use_cache)` — List resources
- `prompts_list($use_cache)` — List prompts
- `prompts_get($name, $arguments)` — Get a prompt
- `clear_cache()` — Clear all cached responses
- `is_reachable()` — Check if server is responsive

### `WP_MCP_AI_JetEngine_Compat` (Updated)

**File**: `addons/pro/includes/class-wp-mcp-ai-jetengine-compat.php`

New methods added for MCP support:
- `has_mcp_server()` — Checks JetEngine 3.8+ AND MCP module availability
- `get_mcp_endpoint()` — Returns the MCP server REST URL
- `get_mcp_capabilities()` — Returns cached server capabilities

### `WP_MCP_AI_JetEngine_MCP_Resources`

**File**: `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-resources.php`

Singleton class that fetches and normalizes MCP resources.

**Methods**:
- `get_post_types()`, `get_taxonomies()`, `get_meta_boxes()`
- `get_glossaries()`, `get_macros()`, `get_relations()`
- `inject_site_context($context)` — Filter callback for `wp_mcp_ai_build_system_context`

### `WP_MCP_AI_JetEngine_MCP_Prompts`

**File**: `addons/pro/includes/class-wp-mcp-ai-jetengine-mcp-prompts.php`

Singleton class for accessing JetEngine prompt templates.

**Methods**:
- `list_prompts()` — Get available prompts
- `get_prompt($name, $arguments)` — Get a specific prompt
- `render_prompt($name, $arguments)` — Render prompt text

## Tool Discovery and Caching

MCP discovery responses are cached using WordPress transients:

| Transient Key | Content | TTL |
|---------------|---------|-----|
| `wp_mcp_ai_je_mcp_init` | Server capabilities | Configurable (default 300s) |
| `wp_mcp_ai_je_mcp_tools_list` | Available tools | Configurable |
| `wp_mcp_ai_je_mcp_resources_list` | Site resources | Configurable |
| `wp_mcp_ai_je_mcp_prompts_list` | Prompt templates | Configurable |

The TTL is configurable via `Settings → NV oOS → Tools → JetEngine Integration → MCP Cache TTL` (min 60s, max 3600s, default 300s).

## Fallback Strategy

```
1. Check: Is JetEngine 3.8+ active with MCP module?
   ├── YES → Use MCP Server (JSON-RPC 2.0)
   │         If MCP call fails → Fall back to REST v2
   └── NO  → Check: Is JetEngine 3.7+ active?
             ├── YES → Use REST v2 API (legacy path)
             └── NO  → Graceful degradation (features disabled)
```

The `WP_MCP_AI_JetEngine_Tool_Handlers::dispatch()` method implements this:
1. Checks `jetengine_mcp_enabled` setting
2. Checks `WP_MCP_AI_JetEngine_Compat::has_mcp_server()`
3. Attempts MCP dispatch via `maybe_dispatch_via_mcp()`
4. On failure, falls through to legacy REST v2 dispatch

## Security Model

### Authentication

| Scenario | Auth Method |
|----------|-------------|
| Same-site internal | Current user context via `rest_do_request()` |
| Remote with app passwords | HTTP Basic Auth header |
| Remote with token | Bearer token header |
| Proxy (existing mechanism) | `X-WP-MCP-AI-Proxy` header |

### Capability Requirements

All MCP tools require `manage_options` capability because they manage site structure.

| Risk Level | Tools | Rationale |
|------------|-------|-----------|
| `elevated` | Bridge, Create CPT, Create Taxonomy, Create Meta Field, Manage Relations | Can modify site structure |
| `standard` | Site Context, Prompts | Read-only operations |

## Settings

| Setting Key | Type | Default | Description |
|-------------|------|---------|-------------|
| `jetengine_mcp_enabled` | boolean | true | Enable/disable MCP integration |
| `jetengine_mcp_cache_ttl` | number | 300 | Discovery cache TTL (60-3600 seconds) |
| `jetengine_mcp_context_injection` | boolean | false | Auto-inject site context into AI prompts |

Settings are stored in the `wp_mcp_ai_settings` WordPress option.

## New Pro Tools

| Slug | MCP Method | Description |
|------|-----------|-------------|
| `jetengine_mcp` | `tools/list`, `tools/call` | Bridge for all MCP tools |
| `jetengine_create_post_type` | `tools/call(create_post_type)` | Create CPTs with validated parameters |
| `jetengine_create_taxonomy` | `tools/call(create_taxonomy)` | Create taxonomies |
| `jetengine_create_meta_field` | `tools/call(create_meta_field)` | Add meta fields |
| `jetengine_manage_relations` | `tools/call(get_relations)` | List/create relations |
| `jetengine_site_context` | `tools/call(site_context)` | Site structure overview |
| `jetengine_prompts` | `prompts/list`, `prompts/get` | Prompt templates |

## Testing

Tests are located in:
- `addons/pro/tests/test-jetengine-mcp-client.php` — Client unit tests
- `addons/pro/tests/test-jetengine-mcp-bridge-tool.php` — Tool tests
- `addons/pro/tests/test-jetengine-mcp-resources.php` — Resources tests
- `addons/pro/tests/test-jetengine-mcp-prompts.php` — Prompts tests
- `addons/pro/tests/test-jetengine-mcp-integration.php` — Integration tests
- `tests/test-jetengine-tool-handlers.php` — Fallback behavior tests

## Related Documentation

- [JetEngine Integration Guide](../../jetengine-integration-guide.md)
- [JetEngine API Compatibility](./jetengine-api-compatibility.md)
- [Tool Reference](../../reference/tools/tool-reference.md)
- [JetEngine MCP Guide (Crocoblock)](https://crocoblock.com/blog/jetengine-ai-command-center-mcp-guide/)
