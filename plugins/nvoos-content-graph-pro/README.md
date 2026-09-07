# NV oOS Content Graph — Pro

Pro toolkit layer for the NV oOS Content Graph ecosystem. This addon is the
standalone port of the base plugin's Pro addon (`addons/pro/`) per the
ecosystem port plan (`docs/project/plans/base-pro-ecosystem-port-plan.md`,
Wave F). It requires the core (`nvoos-content-graph`), AI
(`nvoos-content-graph-ai`), and Platform (`nvoos-content-graph-ai-platform`)
plugins.

## Waves

| Wave | Scope | Status |
|---|---|---|
| F1 | pro-core: module registry, privacy, vault, vector-storage, skills-manager, toolkit data-store factory | In progress |
| F2–F6 | Pro toolkits (business/media/dev/healthcare/legal/education/content/data/platform) | Planned |
| F7 | Node-service bridges | Planned |

## Modes

- **Monolith mode** — the base plugin (`mcp-ai-wpoos`) is active. Its Pro
  addon defines `WP_MCP_AI_PRO_PATH` and owns every Pro class/hook. This
  addon's entry detects the constant and does nothing (its `WP_MCP_AI_*`
  autoloader fallback also refuses to serve classes in this mode).
- **Standalone mode** — the base plugin is absent. This addon boots the
  ported `WP_MCP_AI_Pro_Module_Registry` on `plugins_loaded` priority 15
  (after the AI addon at 5 and the Platform addon at 10).

## Architecture

The base Pro addon's architecture is preserved: every subsystem loads through
the module registry. `Plugin::register()` only calls
`WP_MCP_AI_Pro_Module_Registry::get_instance()->boot()`; each module's factory
wires its own CPTs, REST routes, tools, and hooks.

The registry's `define_modules()` currently defines only the Wave F1 pro-core
modules — `privacy`, `toolkit_data_store`, `pro_skills_manager`,
`toolkit_vault`, `vector_storage` — each with a `files` guard so `boot()`
degrades gracefully while a module's files are still in flight. F2–F7 modules
are added to `define_modules()` as their waves land.

## Porting rules (for later waves)

1. **Keep global names.** Ported classes keep their `WP_MCP_AI_*` global
   class names and `wp_mcp_ai_*` function/hook names — ecosystem code
   (e.g. `NvoosContentGraphAi\Engine\PaperStore\PaperStoreRemoteTrait`) and
   the byte-identical-surface principle depend on them. Files live under
   `src/` and autoload via Composer classmap (primary) or the entry's spl
   fallback (`WP_MCP_AI_Foo_Bar` → `src/class-wp-mcp-ai-foo-bar.php`).
2. **Path constant swap.** Every `WP_MCP_AI_PRO_PATH . 'includes/...'`
   reference becomes `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/...'`. F2+ ported
   consumers that `require_once` the data-store factory on demand must use
   the new path (or rely on the `class_exists()` guard + eager module load).
3. **Strict types.** `declare(strict_types=1);` is added to every ported
   file (phpcs `Generic.PHP.RequireStrictTypes`). Spot-check array
   parameters that the monolith fed loosely-typed values.
4. **Test seams.** `private` members that tests must drive become
   `protected`; `final` is dropped where a test subclass is needed.
   Document both deviations in the file docblock.
5. **Monolith ownership.** Never `require` a ported file unconditionally —
   the entry bails in monolith mode and the autoloader refuses `WP_MCP_AI_*`
   classes when `WP_MCP_AI_PRO_PATH` is defined. The base Pro addon keeps
   owning the same classes monolith.

## Documented deviations (Wave F1)

See the class docblock in `src/class-wp-mcp-ai-pro-module-registry.php`:
strict-types coercion guard in `boot()`, protected test seams, F1-only
`define_modules()`, `src/` path root, two new standalone modules
(`toolkit_data_store`, `vector_storage`), additive `get_modules()` /
`is_bootstrapped()` accessors.

## Development

```bash
# Lint
vendor/bin/phpcs --standard=phpcs.xml.dist .

# Test (from the monorepo root; see the ecosystem-port-cluster-loop docs)
PORT_SUITE_DIR=plugins/nvoos-content-graph-pro PORT_STANDALONE_VAR=WP_MCP_AI_PRO_STANDALONE bin/port-cluster.sh gates
```
