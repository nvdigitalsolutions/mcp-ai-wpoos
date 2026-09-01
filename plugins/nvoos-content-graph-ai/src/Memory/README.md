# Memory

## Purpose

Agent-memory layer for the AI addon: the graph-backed memory engine (`AgentMemory`, pre-extraction) plus the Wave D7 durable-store trio — the JetEngine `ai_agent_memories` CCT registration (`AgentMemoriesCct`), the lifecycle bridge that mirrors memory events into the CCT (`AgentMemoryCctBridge`), the idempotent schema migrator (`AgentMemoryCctMigrator`), and the read-side recall hydrator (`AgentMemoryCctReader`). All four D7 classes are byte-identical ports of the base plugin's equivalents.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::register()` — D7 bootstraps standalone-only |
| **Optional dependencies** | JetEngine (CCT provisioning + item handler) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Memory\AgentMemory` | `AgentMemory.php` | Chat flow (mining prompt, recall context) |
| `NvoosContentGraphAi\Memory\AgentMemoriesCct` | `AgentMemoriesCct.php` | `Plugin::register()` (standalone-only CCT registration on `init`) |
| `NvoosContentGraphAi\Memory\AgentMemoryCctBridge` | `AgentMemoryCctBridge.php` | `Plugin::register()` (standalone-only `wp_mcp_ai_memory_stored/deleted` listeners) |
| `NvoosContentGraphAi\Memory\AgentMemoryCctMigrator` | `AgentMemoryCctMigrator.php` | `Plugin::register()` (standalone-only schema migrator) |
| `NvoosContentGraphAi\Memory\AgentMemoryCctReader` | `AgentMemoryCctReader.php` | `Plugin::register()` (standalone-only `wp_mcp_ai_recall_memory_candidates` filter) |

## Inputs / Outputs / Neighbors

- **Reads from:** memory events (`wp_mcp_ai_memory_stored` / `wp_mcp_ai_memory_deleted`), the `{prefix}jet_cct_ai_agent_memories` table, `wp_mcp_ai_memory_cct_schema_version` option, JetEngine module state
- **Writes to:** the JetEngine CCT (via its item handler), the schema-version option, `wp_mcp_ai_recall_memory_candidates` filter output
- **Upstream callers:** the memory/tools wave (emits the lifecycle events — not ported yet, so the bridge is dormant standalone), the recall tools (consume the candidates filter — not ported yet)
- **Downstream collaborators:** JetEngine CCT module, base `WP_MCP_AI_JetEngine_Agent_Memories_CCT` in monolith installs
- **Events fired:** none
- **Events listened to:** `init`, `wp_mcp_ai_memory_stored`, `wp_mcp_ai_memory_deleted`, `wp_mcp_ai_recall_memory_candidates` (filter), `admin_init` (migrator, when enabled)

## Conventions

- CCT slug `ai_agent_memories`, field-ID base 30000, schema version option + filters are byte-identical to the base so monolith and standalone installs produce the same durable schema.
- The CCT class resolves per install mode: base `WP_MCP_AI_JetEngine_Agent_Memories_CCT` monolith / the ported `AgentMemoriesCct` standalone.
- `bootstrap()` registration is standalone-only — the base plugin owns the same hooks/CCT/filter in monolith installs.
- Dormant-until gaps are documented in the class docblocks (memory event emission, recall consumers).

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitization + escaping

## See Also

- Parent: [`../`](../) — src root
- Chat flow: [`../Chat/`](../Chat/)
- Ecosystem tracker: [`../../../../docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — Wave D7
