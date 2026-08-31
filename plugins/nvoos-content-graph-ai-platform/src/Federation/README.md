# Federation

## Purpose

Federation subsystem — AI peer discovery via `/.well-known/ai-peer` and `/.well-known/jwks.json`, peer health verification, and rate limiting for public discovery endpoints.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Federation\FederationService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its federation bootstrap owns all wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Federation\FederationService` | `FederationService.php` | `Plugin::register()` |
| `NvoosContentGraphAiPlatform\Federation\Federation` | `Federation.php` | `FederationService` (standalone mode) |
| `NvoosContentGraphAiPlatform\Federation\Settings` | `Settings.php` | `Federation`, admin |
| `NvoosContentGraphAiPlatform\Federation\WellKnown` | `WellKnown.php` | `Federation` |
| `NvoosContentGraphAiPlatform\Federation\PeerCpt` | `PeerCpt.php` | `Federation`, `DirectoryRest`, `PeerVerifier` |
| `NvoosContentGraphAiPlatform\Federation\DirectoryRest` | `DirectoryRest.php` | `Federation` |
| `NvoosContentGraphAiPlatform\Federation\PeerVerifier` | `PeerVerifier.php` | Cron, `DirectoryRest` |
| `NvoosContentGraphAiPlatform\Federation\RateLimiter` | `RateLimiter.php` | `DirectoryRest` |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option, tool registry (`get_tools()`), peer post meta (`_wp_mcp_ai_peer_*`)
- **Writes to:** JSON responses, peer post meta, transients (`wp_mcp_ai_fed_rate_limit_*`)
- **Upstream callers:** WordPress front-end (well-known rewrites), `Plugin::register()`
- **Events listened to:** `init`, `template_redirect`, `query_vars`, `redirect_canonical`

## Conventions

- Ported from `mcp-ai-wpoos/includes/class-wp-mcp-ai-federation*.php` (extraction Wave A). Rewrite rules, query vars, option keys, and meta keys are unchanged.
- **Registry-agnostic `WellKnown`:** accepts the base plugin's tool registry (monolith) or the Content Graph core registry (standalone); null registry degrades to an empty capabilities list.
- Standalone mode: `FederationService` owns the well-known wiring when `enable_federation` or `enable_federation_directory` is set. Monolith mode: the base plugin's federation bootstrap owns wiring — never register twice.
- Text domain: `nvoos-content-graph-ai-platform`.

## Extraction status (partial)

The federation **server surface and directory service** are ported. The following remain in the base plugin during the transition (tracked in `MIGRATION-GAPS.md`):

- Mesh networking (`mesh-router`, `mesh-peer-sync`, `mesh-peer-tester`, `mesh-peer-test-rest`) — unavailable in standalone mode until ported.
- `WP_MCP_AI_Federation_Settings` admin settings UI (registers into the base settings page); the Platform addon's `FederationAdmin` provides its own dashboard surface, and `Settings` reads the same `wp_mcp_ai_settings` option.

## Tests

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Federation
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Admin UI: [`Admin/`](Admin/)
