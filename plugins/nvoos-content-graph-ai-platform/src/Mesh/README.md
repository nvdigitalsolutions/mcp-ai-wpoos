# Mesh

## Purpose

Mesh networking subsystem — AI-powered peer selection, load balancing, circuit breakers, and retry logic for distributed compute pooling across WordPress sites, plus mesh peer configuration sync to the `ai_peer` CPT and a connection-test REST endpoint.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Federation\Federation` (via `FederationService`, standalone mode) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its loader wires the base mesh classes) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Mesh\MeshRouter` | `MeshRouter.php` | Static consumers (tools, metaboxes) |
| `NvoosContentGraphAiPlatform\Mesh\MeshPeerSync` | `MeshPeerSync.php` | `Federation` (standalone mode) |
| `NvoosContentGraphAiPlatform\Mesh\MeshPeerTester` | `MeshPeerTester.php` | `MeshPeerTestRest` |
| `NvoosContentGraphAiPlatform\Mesh\MeshPeerTestRest` | `MeshPeerTestRest.php` | `Federation` (standalone mode) |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (mesh config), `wp_mcp_ai_mesh_health_metrics`, `wp_mcp_ai_mesh_routing_stats`, `wp_mcp_ai_mesh_circuit_states` options, `ai_peer` posts
- **Writes to:** routing stats/health options, `ai_peer` posts (sync), outbound HTTP
- **Upstream callers:** `Federation` bootstrap, REST (`mcp-ai/v1/mesh/test-peer`)
- **Downstream collaborators:** `NvoosContentGraphAiPlatform\Federation\PeerCpt`, `Settings`
- **Events listened to:** `update_option_wp_mcp_ai_settings`, `rest_api_init`

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/class-wp-mcp-ai-mesh-*.php` (extraction Wave A). Option keys, meta keys, REST namespace (`mcp-ai/v1/mesh/test-peer`), and the wire behaviour are unchanged.
- The router logs through the base plugin's `WP_MCP_AI_Logger` when present (monolith mode) and falls back to `error_log` in standalone mode; the dead-letter queue integration is already `class_exists`-guarded in the ported source.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Mesh
```

## See Also

- Parent: [`../Federation/`](../Federation/) — bootstrap wiring
- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
