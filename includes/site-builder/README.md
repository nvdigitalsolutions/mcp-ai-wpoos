# includes/site-builder

**Purpose:** WordPress-side infrastructure for the node-graph site building
pipeline — the server-side half of Phase 1–7 of the
ComfyUI-inspired site-creator architecture. Holds the node interface,
registry, pipeline executor, blueprint compiler, and individual node
implementations.

**Public surface:**

| File | Purpose |
|------|---------|
| `class-wp-mcp-ai-site-node-interface.php` | Contract every site node must implement (`get_slug()`, `get_inputs()`, `get_outputs()`, `execute()`) |
| `class-wp-mcp-ai-site-node-registry.php` | Singleton auto-discovery registry — loads default nodes, fires `wp_mcp_ai_register_site_nodes`, exposes `get_nodes_for_frontend()` |
| `class-wp-mcp-ai-site-pipeline-executor.php` | **Phase 2** — DAG-walking pipeline executor with topological sort, input resolution, and incremental transient caching (ComfyUI `execution.py` analogue) |
| `class-wp-mcp-ai-site-blueprint-compiler.php` | **Phase 3** — loads subgraph blueprint JSON, substitutes `{placeholders}`, compiles into executable pipeline graphs (ComfyUI subgraph/template system) |
| `nodes/class-wp-mcp-ai-site-node-wp-query.php` | **Source node** — fetches posts via `WP_Query` |
| `nodes/class-wp-mcp-ai-site-node-text-block.php` | **Layout node** — wraps content in a semantic HTML block |
| `nodes/class-wp-mcp-ai-site-node-flex-container.php` | **Layout node** — arranges children with CSS flexbox |

**Key hooks:**

| Hook | Type | Purpose |
|------|------|---------|
| `wp_mcp_ai_register_site_nodes` | Action | Third-party registration point (ComfyUI `custom_nodes/` equivalent) |
| `wp_mcp_ai_default_site_nodes` | Filter | Inject default node class→filepath entries without modifying the registry |
| `wp_mcp_ai_site_blueprint_directories` | Filter | Register additional directories for blueprint JSON auto-discovery |

**Neighbours:**

- `includes/tools/` — AI tools follow the same registry pattern
- `addons/pro/includes/blueprints/` — AI assistant blueprint installer (similar JSON Schema validation)
- `config/site-blueprints/` — reusable section blueprint JSON files (consumed by the compiler)
- `addons/canvas-toolkit/` — React node-graph UI (consumes `get_nodes_for_frontend()`)
- `addons/document-editor/` — GrapesJS site-creator mode (consumes pipeline output)

**Context files to load alongside this README:**

- `.context/tool-registry.md` — the tool registry pattern this mirrors
- `.context/security.md` — sanitisation / capability rules
- `.context/testing.md` — PHPUnit conventions
