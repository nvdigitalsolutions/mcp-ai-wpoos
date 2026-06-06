# Tools

## Purpose

Houses every built-in tool implementation for NV oOS Graphify — one PHP class per tool, all implementing `NvoosGraphify\Contracts\Tool`, registered with `NvoosGraphify\ToolRegistry`.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosGraphify\Plugin::registerBuiltinTools()` on `plugins_loaded` priority 11 |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Tools\AbstractTool` | `AbstractTool.php` | All 14 concrete tools |
| `NvoosGraphify\Tools\BuildGraph` | `BuildGraph.php` | Tool registry |
| `NvoosGraphify\Tools\ContentGaps` | `ContentGaps.php` | Tool registry |
| `NvoosGraphify\Tools\GetCommunity` | `GetCommunity.php` | Tool registry |
| `NvoosGraphify\Tools\GetNeighbors` | `GetNeighbors.php` | Tool registry |
| `NvoosGraphify\Tools\GetNode` | `GetNode.php` | Tool registry |
| `NvoosGraphify\Tools\GodNodes` | `GodNodes.php` | Tool registry |
| `NvoosGraphify\Tools\GraphStats` | `GraphStats.php` | Tool registry |
| `NvoosGraphify\Tools\ListRemoteSources` | `ListRemoteSources.php` | Tool registry |
| `NvoosGraphify\Tools\QueryGraph` | `QueryGraph.php` | Tool registry |
| `NvoosGraphify\Tools\ResolveExternal` | `ResolveExternal.php` | Tool registry |
| `NvoosGraphify\Tools\RetrieveContext` | `RetrieveContext.php` | Tool registry |
| `NvoosGraphify\Tools\ShortestPath` | `ShortestPath.php` | Tool registry |
| `NvoosGraphify\Tools\SuggestLinks` | `SuggestLinks.php` | Tool registry |
| `NvoosGraphify\Tools\SyncRemoteSource` | `SyncRemoteSource.php` | Tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** Tool arguments (validated), custom DB tables (via `src/Graph/Db`), WordPress posts/terms data
- **Writes to:** Custom DB tables (graph mutations), WordPress posts (suggested links)
- **Upstream callers:** `NvoosGraphify\ToolRegistry`, REST controller, AI addon `ChatService` (tool-calling loop)
- **Downstream collaborators:** `src/Graph/Db` (DB layer), `src/Contracts/Tool` (interface)
- **Events fired:** None (tools return results directly)
- **Events listened to:** None (called directly via registry)

## Conventions

- One tool per file — file name matches `{ToolName}.php`, class name matches `NvoosGraphify\Tools\{ToolName}`.
- Every tool implements `NvoosGraphify\Contracts\Tool`.
- `AbstractTool` provides default capability (`edit_posts`) and flags (`read-only`).
- Tools are registered via `nvoos_graphify/register_tools` action (addons hook into this).
- Tool slugs use `nvoos_graphify_` prefix (e.g. `nvoos_graphify_get_node`).

## Tests

```bash
vendor/bin/phpunit --filter '/Tools/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — sanitiser/escaper rules

## See Also

- Parent: [`../`](../) — src root
- Interface: [`../Contracts/Tool.php`](../Contracts/Tool.php)
- Registry: [`../ToolRegistry.php`](../ToolRegistry.php)
- AI addon tools: [`../../nvoos-graphify-ai/src/Tools/`](../../nvoos-graphify-ai/src/Tools/)
