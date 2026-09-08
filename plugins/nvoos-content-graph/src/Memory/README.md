# Memory

## Purpose

Bridges agent memory events into the knowledge graph — subscribes to memory-stored events, projects each memory as a graph node with associative edges, and serves graph-ranked retrieval to the NV oOS `wake_up_context` tool.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosContentGraph\Plugin::register()` |
| **Optional dependencies** | NV oOS base+Pro plugin (emits `wp_mcp_ai_memory_stored`); `nvoos-content-graph-ai` (embedding backend for `EmbeddingsOnIngest`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraph\Memory\Bridge` | `Bridge.php` | `Plugin::register()`; NV oOS `wake_up_context` (via the `wp_mcp_ai_wake_up_context_graph_retriever` filter) |
| `NvoosContentGraph\Memory\EmbeddingsOnIngest` | `EmbeddingsOnIngest.php` | `Plugin::register()` (cron stub — real backend ships with the AI addon) |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_memory_stored` (NV oOS base+Pro) and `nvoos_content_graph/memory_stored` (ecosystem-native) action payloads
- **Writes to:** Custom DB tables (`nvoos_content_graph_nodes`, `_edges`) via `src/Graph/Db`
- **Upstream callers:** `NvoosContentGraph\Plugin` (composition root)
- **Downstream collaborators:** `src/Graph/Db` (DB layer), `src/Schema` (hook constants)
- **Events listened to:** `wp_mcp_ai_memory_stored`, `nvoos_content_graph/memory_stored`
- **Filters registered:** `wp_mcp_ai_wake_up_context_graph_retriever` (graph-ranked retrieval for the base plugin's `wake_up_context`), `wp_mcp_ai_graph_score_weights` (consumed by `retrieveGraph()`)

## Conventions

- The bridge is **advisory** — projection failures, malformed payloads, and a missing schema must never break the memory write. All projection paths run inside a defensive try/catch and gate on `Db::tablesInstalled()`.
- Node-id prefixes follow the bundled Graphify addon: `memory:`, `agent:`, `wing:`, `room:` (wing+room composite). Edges: `OBSERVED_BY` (memory → agent), `MEMBER_OF` (memory → wing/room, room → wing), `DERIVED_FROM` (memory → post node).
- `Bridge::register()` is idempotent (safe to call multiple times).
- `EmbeddingsOnIngest` remains a stub in core — the embedding backend ships with `nvoos-content-graph-ai`.

## Tests

```bash
vendor/bin/phpunit --filter '/Memory|Bridge|Embeddings/'
```

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style

## See Also

- Parent: [`../`](../) — src root
- Addon memory: [`../../../nvoos-content-graph-ai/`](../../../nvoos-content-graph-ai/)
- Bundled Graphify bridge reference: [`../../../../../addons/graphify/includes/class-nvoos-graphify-memory-bridge.php`](../../../../../addons/graphify/includes/class-nvoos-graphify-memory-bridge.php)
