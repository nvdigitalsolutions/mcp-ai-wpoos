# SiteBuilder

## Purpose

Wave E4 port surface. The server-side node-graph site building pipeline
from the base plugin's `includes/site-builder/`: the node interface, the
auto-discovering node registry, the topological-sort pipeline executor
with transient caching, the blueprint compiler, and the three built-in
nodes — ported byte-identically so the platform addon carries the
ComfyUI-style site-creator infrastructure without the base plugin. A
dormant library: the base ships no bootstrap for it (consumers call
`SiteNodeRegistry::get_instance()->init()` directly), so the port
registers no hooks either.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | PSR-4 autoload on demand — no `Plugin.php` registration (byte-identical: the base ships no `site-creator-init.php` bootstrap) |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeInterface` | `SiteNodeInterface.php` | Registry, executor, all node implementations |
| `NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeRegistry` | `SiteNodeRegistry.php` | Singleton — pipeline executor, front-end palette consumers |
| `NvoosContentGraphAiPlatform\SiteBuilder\SitePipelineExecutor` | `SitePipelineExecutor.php` | Blueprint compiler consumers, tools |
| `NvoosContentGraphAiPlatform\SiteBuilder\SiteBlueprintCompiler` | `SiteBlueprintCompiler.php` | Tools, front-end blueprint palette |
| `NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeWpQuery` | `Nodes/SiteNodeWpQuery.php` | Registry default map (source) |
| `NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeTextBlock` | `Nodes/SiteNodeTextBlock.php` | Registry default map (layout) |
| `NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeFlexContainer` | `Nodes/SiteNodeFlexContainer.php` | Registry default map (layout) |

## Inputs / Outputs / Neighbors

- **Reads from:** the per-mode default node map, blueprint JSON files
  under `config/site-blueprints/` (base tree monolith / the platform
  addon's byte-identical copies standalone), and the transient cache
  for incremental pipeline caching.
- **Writes to:** transient cache (incremental node results), compiled
  pipeline output (returned as structured data).
- **Upstream callers:** future AI tools that invoke site-building
  operations (the base's only consumer is the Pro
  `import-site-creator-blueprint` example tool).
- **Downstream collaborators:** WordPress options/transients APIs; in
  monolith installs the base `WP_MCP_AI_Site_Node_*` classes (per-mode
  default map + interface seam).
- **Events fired:** `wp_mcp_ai_register_site_nodes` (action),
  `wp_mcp_ai_default_site_nodes` (filter),
  `wp_mcp_ai_site_blueprint_directories` (filter).
- **Events listened to:** none.

## Conventions

- Every node class MUST implement `SiteNodeInterface` (or the base
  interface, per install mode) and declare `get_slug()`,
  `get_inputs()`, `get_outputs()`, and `execute()`.
- Nodes are registered through the per-mode default map or the
  `wp_mcp_ai_register_site_nodes` hook — never outside them.
- The pipeline executor uses topological sort (DAG walking) — nodes
  MUST NOT introduce cycles.
- **Per-mode seams.** The discriminator is always
  `defined( 'WP_MCP_AI_PATH' )`: the built-in node map loads base
  `WP_MCP_AI_Site_Node_*` classes monolith / this package's `Nodes\*`
  classes standalone; `is_node_instance()` accepts the matching
  interface per mode; the blueprint default directory resolves base
  `config/site-blueprints/` monolith / the platform copies standalone
  (`default_dir()` — a compile-time constant cannot express this).
- Byte-identical constants/hooks/error codes/shapes with the base;
  deviations documented in the class docblocks (text domain, PSR-4
  class names, `\WP_Error`/`\WP_Query` qualification, the
  fail-soft `register_node()` validation, the strict-mode `className`
  cast in the text-block node).
- No `Plugin.php` registration — the base ships no bootstrap for this
  subsystem either (documented byte-identical dormancy).

## Tests

- `tests/test-site-builder-registry.php` — singleton, idempotent init,
  default-node loading (per mode), lookups, category filtering,
  front-end palette ordering, custom-node registration (per-mode
  interface), unknown-node envelope, fail-soft non-interface skip (both
  matrices).
- `tests/test-site-builder-executor.php` — Kahn topological sort
  (linear/forked/chain), cycle detection, error envelopes, static +
  edge-overlay input resolution, two-tier caching, per-pipeline cache
  clearing, input-hash invalidation (both matrices).
- `tests/test-site-builder-blueprint-compiler.php` — loading from the
  per-mode directory, validation, listings, summaries, placeholder
  substitution, ID/edge prefixing, empty-graph envelope, in-memory
  cache, and end-to-end compile → execute for both shipped blueprints
  (both matrices).
- `tests/test-site-builder-nodes.php` — node metadata, WP_Query
  execution + 100-post cap, text-block tag whitelist, flex-property
  whitelists (both matrices).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-site-builder-*.php
```

## Also Load

- [`../Google/README.md`](../Google/README.md) — the E4-3 sub-cluster
  (shared per-mode seam + README conventions)
- [`../ContentAssistant/README.md`](../ContentAssistant/README.md) — the E4-4 sub-cluster
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (HTML escaping in nodes)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`includes/site-builder/`](../../../../includes/site-builder/) — the base subsystem (the port's origin)
