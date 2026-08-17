# Memory

## Purpose

Bridges agent memory events into the knowledge graph — subscribes to memory-stored events and links memories to graph nodes. Full implementation deferred to the `nvoos-content-graph-ai` addon.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin — stubs only; full logic in AI addon |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosContentGraph\Plugin::register()` |
| **Optional dependencies** | `nvoos-content-graph-ai` (for full memory functionality) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraph\Memory\Bridge` | `Bridge.php` | `Plugin::register()` |
| `NvoosContentGraph\Memory\EmbeddingsOnIngest` | `EmbeddingsOnIngest.php` | `Plugin::register()` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_content_graph/memory_stored` action payloads
- **Writes to:** Custom DB tables (`nvoos_content_graph_embeddings`, `_nodes`)
- **Upstream callers:** `NvoosContentGraph\Plugin` (composition root)
- **Downstream collaborators:** `src/Graph/Db` (DB layer)
- **Events fired:** `nvoos_content_graph/memory_stored`
- **Events listened to:** `nvoos_content_graph/memory_stored`

## Conventions

- Core memory classes are stubs — they register hooks but delegate actual processing to the AI addon.
- `Bridge` is idempotent (safe to call `register()` multiple times).

## Tests

```bash
vendor/bin/phpunit --filter '/Memory|Bridge|Embeddings/'
```

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style

## See Also

- Parent: [`../`](../) — src root
- Addon memory: [`../../../nvoos-content-graph-ai/`](../../../nvoos-content-graph-ai/)
