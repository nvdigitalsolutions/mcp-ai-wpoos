# Infrastructure Tools

> Cross-cutting Pro infrastructure tools shared by multiple toolkits.

## Purpose

Generic CRUD and utility operations that serve as building blocks for domain-specific toolkits. These tools are always available (not gated behind a per-toolkit setting).

## Tools

| Tool class | Slug | Domain |
|---|---|---|
| `WP_MCP_AI_Pro_Tool_CPT` | `pro_tool_cpt` | Generic CPT CRUD (list, get, create, update, delete, bulk-create) for any Pro toolkit CPT |

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` |
| **Always available** | Yes — no per-toolkit gate |

## Conventions

- Canonical return envelope enforced.
- Two-gate sanitisation rule applies.
- Implements `WP_MCP_AI_Tool_Interface`.

## See Also

- Sibling folders: [`tools/ecommerce/`](../ecommerce/), [`tools/eca-management/`](../eca-management/), all other domain toolkits
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md)
