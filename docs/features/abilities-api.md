# Abilities API

**Version:** 1.1.46+
**Category:** Base feature
**Proposal:** [019-abilities-api-selective-adoption](../project/proposals/019-abilities-api-selective-adoption-proposal.md)
**Framework:** `includes/abilities/` (5 classes, 5 test files)

## Overview

The Abilities API provides machine-readable plugin operations with JSON Schema contracts for AI agent and MCP tool discovery. It bridges WordPress tools to the MCP adapter so external AI agents can discover available operations programmatically.

## Architecture

```
includes/abilities/
├── abilities-init.php              # Bootstrap (49 lines)
├── class-wp-mcp-ai-ability-registrar.php        # Registration & lifecycle (188 lines)
├── class-wp-mcp-ai-ability-bridge.php           # Tool registry → MCP bridge (235 lines)
├── class-wp-mcp-ai-ability-category-registrar.php  # Hierarchical grouping (92 lines)
└── class-wp-mcp-ai-ability-security-bridge.php     # Capability-based access (289 lines)

includes/
└── interface-wp-mcp-ai-tool-ability-interface.php  # Tool contract (61 lines)
```

## Key Classes

### WP_MCP_AI_Ability_Registrar
- Registers abilities with unique IDs, JSON Schema definitions, and capability requirements
- Handles lifecycle: registration → discovery → deregistration
- Supports bulk registration from tool arrays

### WP_MCP_AI_Ability_Bridge
- Connects the ability registry to the tool registry
- Exposes abilities via MCP `tools/list` and `tools/call`
- Handles tool-to-ability mapping for agent discovery

### WP_MCP_AI_Ability_Category_Registrar
- Groups abilities into hierarchical categories
- 5 built-in categories: `nvoos-site`, `nvoos-content`, `nvoos-media`, `nvoos-ai`, `nvoos-operations`

### WP_MCP_AI_Ability_Security_Bridge
- Enforces capability-based access control per ability
- Integrates with WordPress role/capability system
- Validates `required_capability` before execution

## Categories & Abilities (41 total)

| Category | Count | Example Abilities |
|---|---|---|
| `nvoos-site` — Site Information | 6 | `get-site-summary`, `get-user-info`, `get-environment-status` |
| `nvoos-content` — Content & Publishing | 12 | `get-post`, `get-recent-posts`, `search-content` |
| `nvoos-media` — Media & Generation | 8 | `generate-image`, `extract-image-text`, `analyze-image` |
| `nvoos-ai` — AI & Research | 9 | `chat`, `deep-research`, `web-search` |
| `nvoos-operations` — Site Operations | 6 | `get-system-logs`, `get-update-status`, `manage-cron` |

See [Abilities Registry Reference](../reference/abilities-registry.md) for the complete auto-generated catalog.

## Tool-Ability Interface

```php
interface WP_MCP_AI_Tool_Ability_Interface {
    /**
     * Get the ability ID for this tool.
     * @return string Unique ability identifier (e.g., 'nvoos/get-post').
     */
    public function get_ability_id(): string;

    /**
     * Get the JSON Schema for this ability's arguments.
     * @return array JSON Schema definition.
     */
    public function get_ability_schema(): array;

    /**
     * Get the category this ability belongs to.
     * @return string Category slug.
     */
    public function get_ability_category(): string;
}
```

## MCP Integration

Abilities are discoverable through the MCP adapter:

```json
// tools/list response includes abilities metadata
{
  "tools": [
    {
      "name": "get_post",
      "description": "Retrieve a WordPress post by ID",
      "inputSchema": { ... },
      "annotations": {
        "ability_id": "nvoos/get-post",
        "category": "nvoos-content",
        "required_capability": "edit_posts"
      }
    }
  ]
}
```

## Verified Interop with the Official MCP Adapter

**Verified 2026-11-02** in the Docker test env: WP 7.1.4 + mcp-adapter 0.7.1
(installed from wp.org) + NV oOS 1.2.15. `discover-abilities` on the adapter's
default server returns **33 `nvoos/*` abilities**; `get-ability-info` returns
full input/output schemas; `execute-ability` runs tools with the canonical
envelope and `structuredContent`.

**Verified facts:**

- **Exposure model.** The adapter's default server exposes abilities through
  three meta-tools (`discover-abilities`, `get-ability-info`,
  `execute-ability`) — not as individual `tools/list` entries. The bridge's
  `meta.mcp.public => true` flag is exactly what the default server requires.
- **Elementor conflict.** Elementor 4.3+ adds `__return_false` on the
  `mcp_adapter_create_default_server` filter — its built-in MCP composer
  disables the adapter's default server. On Elementor sites, NV oOS abilities
  are only reachable through a **custom server** registered on
  `mcp_adapter_init` (the Elementor-proof pattern, verified working) or by
  re-enabling the filter.
- **WP_Error semantics.** A raw `WP_Error` from `execute_callback` is handled
  natively on both paths: the adapter marks the tool result `isError: true`,
  and the core Abilities REST run controller returns a REST error body. The
  bridge therefore passes `WP_Error` through unconverted (filter:
  `wp_mcp_ai_ability_wp_error_passthrough`, default on). The legacy
  `{success, message, code}` envelope remains available behind the filter.
- **Annotation pessimism.** Per the MCP spec and the official annotation
  guidance (set all four hints explicitly; assume destructive when unproven),
  un-flagged tools advertise `destructiveHint: true` instead of making an
  unverified non-destructive claim. Read-only and `reversible` tools are the
  only ones advertised as non-destructive.
- **Transports.** HTTP sessions require `initialize` → `Mcp-Session-Id` header;
  the adapter also serves a WP-CLI STDIO transport
  (`wp mcp-adapter serve --user=admin --server=...`), verified working.

**Repeatable verification recipe** (Docker env, port 8000):

```bash
# App password for Basic auth (dev-application-passwords mu-plugin enables it).
APP_PW=$(docker compose run --rm wp-cli user application-password create 1 interop --porcelain)

# 1. Initialize and capture the session.
curl -s -D /tmp/mcp-h.txt -o /dev/null -X POST \
  http://localhost:8000/wp-json/mcp/mcp-adapter-default-server \
  -u "admin:$APP_PW" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"probe","version":"1.0"}}}'
SESSION=$(grep -i mcp-session-id /tmp/mcp-h.txt | awk '{print $2}' | tr -d '\r')

# 2. Discover abilities (expect nvoos/* entries).
curl -s -X POST http://localhost:8000/wp-json/mcp/mcp-adapter-default-server \
  -u "admin:$APP_PW" -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}'

# 3. Execute an ability (isError:true marks failures since the WP_Error fix).
curl -s -X POST http://localhost:8000/wp-json/mcp/mcp-adapter-default-server \
  -u "admin:$APP_PW" -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"nvoos/get-site-summary","parameters":{}}}}'
```

**Custom-server pattern (Elementor-proof)** — register on `mcp_adapter_init`:

```php
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		return;
	}
	add_action( 'mcp_adapter_init', function ( $adapter ) {
		$adapter->create_server(
			'nvoos-server', 'mcp', 'nvoos', 'NV oOS Tools',
			'Direct NV oOS tool exposure', '1.0.0',
			array( WP\MCP\Transport\HttpTransport::class ),
			WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			null,
			array( 'nvoos/get-site-summary', 'nvoos/get-post' ),
			array(), array()
		);
	} );
} );
```

This exposes `nvoos-get-site-summary` and `nvoos-get-post` as first-class MCP
network tools at `/wp-json/mcp/nvoos` with full schemas — independent of the
adapter's default-server toggle.

## Selective Adoption

The Abilities API is designed for **selective adoption** — tools opt in by implementing `WP_MCP_AI_Tool_Ability_Interface`. Existing tools continue to work without modification. New tools can register abilities alongside their existing `get_definition()` method.

## Testing

5 PHPUnit test files cover:
- Backward compatibility (139 lines)
- Bridge integration (279 lines)
- Registrar lifecycle (190 lines)
- Category grouping (96 lines)
- Mock tool implementation (158 lines)

## Related

- [Abilities Registry](../reference/abilities-registry.md) — auto-generated catalog
- [includes/abilities/README.md](../../includes/abilities/README.md) — framework README
- [Proposal 019](../project/proposals/019-abilities-api-selective-adoption-proposal.md)
- [Implementation Plan](../project/proposals/019-abilities-api-selective-adoption-implementation-plan.md)
