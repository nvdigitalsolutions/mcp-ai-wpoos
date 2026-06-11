# Site Builder

## Purpose

WordPress-side infrastructure for the node-graph site building pipeline — the server-side half of Phase 1–7 of the ComfyUI-inspired site-creator architecture. Holds the node interface, registry, pipeline executor, blueprint compiler, and individual node implementations.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/site-creator-init.php` (or equivalent bootstrap) |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Site_Node_Interface` | `class-wp-mcp-ai-site-node-interface.php` | Registry, executor, all node implementations |
| `WP_MCP_AI_Site_Node_Registry` | `class-wp-mcp-ai-site-node-registry.php` | Pipeline executor, front-end (via `get_nodes_for_frontend()`) |
| `WP_MCP_AI_Site_Pipeline_Executor` | `class-wp-mcp-ai-site-pipeline-executor.php` | Blueprint compiler, tools |
| `WP_MCP_AI_Site_Blueprint_Compiler` | `class-wp-mcp-ai-site-blueprint-compiler.php` | Pipeline executor, tools |
| Concrete nodes in `nodes/` | `nodes/class-wp-mcp-ai-site-node-*.php` | Registry (auto-discovered) |

## Inputs / Outputs / Neighbors

- **Reads from:** `nodes/` directory (auto-discovery), blueprint JSON files under `config/site-blueprints/`, transient cache for incremental pipeline caching.
- **Writes to:** transient cache (incremental node results), compiled pipeline output (returned as structured data).
- **Upstream callers:** AI tools that invoke site-building operations, the site-creator REST endpoint.
- **Downstream collaborators:** `includes/tools/` (same registry pattern), `addons/pro/includes/blueprints/` (blueprint installer), `addons/canvas-toolkit/` (React node-graph UI), `addons/document-editor/` (GrapesJS site-creator mode).
- **Events fired:** `wp_mcp_ai_register_site_nodes` (action), `wp_mcp_ai_default_site_nodes` (filter), `wp_mcp_ai_site_blueprint_directories` (filter).
- **Events listened to:** none.

## Conventions

- Every node class MUST implement `WP_MCP_AI_Site_Node_Interface` and declare `get_slug()`, `get_inputs()`, `get_outputs()`, and `execute()`.
- Nodes live in `nodes/` and are auto-discovered by the registry — do not manually register nodes outside the `wp_mcp_ai_register_site_nodes` hook.
- The pipeline executor uses topological sort (DAG walking) — nodes MUST NOT introduce cycles.
- Blueprint JSON files follow the ComfyUI subgraph/template pattern with `{placeholder}` substitution.

## Tests

```bash
vendor/bin/phpunit tests/test-site-builder.php
vendor/bin/phpunit tests/test-site-node-registry.php
vendor/bin/phpunit tests/test-site-pipeline-executor.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitisation, escaping (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — the tool registry pattern this mirrors
- [`.context/testing.md`](../../.context/testing.md) — PHPUnit conventions
- [`CLAUDE.md`](../../CLAUDE.md) — PHP compat, tool patterns

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`tools/`](../tools/), [`rest/`](../rest/)
- Related: `addons/canvas-toolkit/` (React node-graph UI), `addons/document-editor/` (GrapesJS site-creator mode), `config/site-blueprints/` (blueprint JSON files)
