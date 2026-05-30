# JetEngine Tools

> Pro integration tools for Crocoblock JetEngine — CCT management, post-type/taxonomy/meta-field creation, relations, MCP bridge, prompts, and site context.

## Purpose

Programmatic CRUD and configuration of JetEngine components from AI assistants. Bridges JetEngine's own REST API into the MCP tool contract.

## Tools

| Tool class | Slug | Domain |
|---|---|---|
| `WP_MCP_AI_Pro_Tool_JetEngine` | `jetengine` | Generic JetEngine CRUD |
| `WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge` | `jetengine_mcp_bridge` | MCP protocol bridge |
| `WP_MCP_AI_Pro_Tool_JetEngine_Create_Post_Type` | `jetengine_create_post_type` | Register CCT-based CPT |
| `WP_MCP_AI_Pro_Tool_JetEngine_Create_Taxonomy` | `jetengine_create_taxonomy` | Register CCT-based taxonomy |
| `WP_MCP_AI_Pro_Tool_JetEngine_Create_Meta_Field` | `jetengine_create_meta_field` | Add meta field to CCT |
| `WP_MCP_AI_Pro_Tool_JetEngine_Manage_Relations` | `jetengine_manage_relations` | Parent-child relations |
| `WP_MCP_AI_Pro_Tool_JetEngine_Site_Context` | `jetengine_site_context` | Site schema introspection |
| `WP_MCP_AI_Pro_Tool_JetEngine_Prompts` | `jetengine_prompts` | MCP prompt templates |

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` |
| **Optional dependency** | JetEngine plugin (`function_exists('jet_engine')`) |

## Conventions

- Canonical return envelope enforced.
- Two-gate sanitisation rule applies.
- Every tool implements `WP_MCP_AI_Tool_Interface`.
- Gated behind `enable_jetengine_tools` setting.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-jetengine-mcp-bridge-tool.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-mcp-client.php
vendor/bin/phpunit addons/pro/tests/test-jetengine-cpt-taxonomy-integration.php
```

## See Also

- Sibling: [`tools/ecommerce/`](../ecommerce/)
- Sibling: [`tools/site-creator-toolkit/`](../site-creator-toolkit/)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md)
