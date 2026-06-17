# Memory

## Purpose

Bridges agent memory events into the knowledge graph — subscribes to memory-stored events and links memories to graph nodes. Full implementation deferred to the `nvoos-graphify-ai` addon.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin — stubs only; full logic in AI addon |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosGraphify\Plugin::register()` |
| **Optional dependencies** | `nvoos-graphify-ai` (for full memory functionality) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Memory\Bridge` | `Bridge.php` | `Plugin::register()` |
| `NvoosGraphify\Memory\EmbeddingsOnIngest` | `EmbeddingsOnIngest.php` | `Plugin::register()` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_graphify/memory_stored` action payloads
- **Writes to:** Custom DB tables (`nvoos_graphify_embeddings`, `_nodes`)
- **Upstream callers:** `NvoosGraphify\Plugin` (composition root)
- **Downstream collaborators:** `src/Graph/Db` (DB layer)
- **Events fired:** `nvoos_graphify/memory_stored`
- **Events listened to:** `nvoos_graphify/memory_stored`

## Conventions

- Core memory classes are stubs — they register hooks but delegate actual processing to the AI addon.
- `Bridge` is idempotent (safe to call `register()` multiple times).

## Tests

```bash
vendor/bin/phpunit --filter '/Memory|Bridge|Embeddings/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style

## See Also

- Parent: [`../`](../) — src root
- Addon memory: [`../../nvoos-graphify-ai/`](../../nvoos-graphify-ai/)
