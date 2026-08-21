# Tools

## Purpose

Houses every built-in tool implementation for NV oOS Content Graph — one PHP class per tool, all implementing `NvoosContentGraph\Contracts\Tool`, registered with `NvoosContentGraph\ToolRegistry`.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosContentGraph\Plugin::registerBuiltinTools()` on `plugins_loaded` priority 11 |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraph\Tools\AbstractTool` | `AbstractTool.php` | All 14 concrete tools |
| `NvoosContentGraph\Tools\BuildGraph` | `BuildGraph.php` | Tool registry |
| `NvoosContentGraph\Tools\ContentGaps` | `ContentGaps.php` | Tool registry |
| `NvoosContentGraph\Tools\GetCommunity` | `GetCommunity.php` | Tool registry |
| `NvoosContentGraph\Tools\GetNeighbors` | `GetNeighbors.php` | Tool registry |
| `NvoosContentGraph\Tools\GetNode` | `GetNode.php` | Tool registry |
| `NvoosContentGraph\Tools\GodNodes` | `GodNodes.php` | Tool registry |
| `NvoosContentGraph\Tools\GraphStats` | `GraphStats.php` | Tool registry |
| `NvoosContentGraph\Tools\ListRemoteSources` | `ListRemoteSources.php` | Tool registry |
| `NvoosContentGraph\Tools\QueryGraph` | `QueryGraph.php` | Tool registry |
| `NvoosContentGraph\Tools\ResolveExternal` | `ResolveExternal.php` | Tool registry |
| `NvoosContentGraph\Tools\RetrieveContext` | `RetrieveContext.php` | Tool registry |
| `NvoosContentGraph\Tools\ShortestPath` | `ShortestPath.php` | Tool registry |
| `NvoosContentGraph\Tools\SuggestLinks` | `SuggestLinks.php` | Tool registry |
| `NvoosContentGraph\Tools\SyncRemoteSource` | `SyncRemoteSource.php` | Tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** Tool arguments (validated), custom DB tables (via `src/Graph/Db`), WordPress posts/terms data
- **Writes to:** Custom DB tables (graph mutations), WordPress posts (suggested links)
- **Upstream callers:** `NvoosContentGraph\ToolRegistry`, REST controller, AI addon chat orchestration (`nvoos/core` `ChatOrchestrator` tool-calling loop)
- **Downstream collaborators:** `src/Graph/Db` (DB layer), `src/Contracts/Tool` (interface)
- **Events fired:** None (tools return results directly)
- **Events listened to:** None (called directly via registry)

## Conventions

- One tool per file — file name matches `{ToolName}.php`, class name matches `NvoosContentGraph\Tools\{ToolName}`.
- Every tool implements `NvoosContentGraph\Contracts\Tool`.
- `AbstractTool` provides default capability (`edit_posts`) and flags (`read-only`).
- Tools are registered via `nvoos_content_graph/register_tools` action (addons hook into this).
- Tool slugs use `nvoos_content_graph_` prefix (e.g. `nvoos_content_graph_get_node`).

## Tests

```bash
vendor/bin/phpunit --filter '/Tools/'
```

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper rules

## See Also

- Parent: [`../`](../) — src root
- Interface: [`../Contracts/Tool.php`](../Contracts/Tool.php)
- Registry: [`../ToolRegistry.php`](../ToolRegistry.php)
- AI addon tools: [`../../../nvoos-content-graph-ai/src/Tools/`](../../../nvoos-content-graph-ai/src/Tools/)
