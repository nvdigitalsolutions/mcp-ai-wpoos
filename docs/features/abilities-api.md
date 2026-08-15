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
