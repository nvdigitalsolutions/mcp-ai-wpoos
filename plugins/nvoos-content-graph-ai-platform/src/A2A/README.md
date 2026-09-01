# A2A

## Purpose

Agent-to-Agent (A2A) protocol subsystem — Agent Card discovery, task state machine, outbound client, message translation, push notifications, inbound webhooks, and the `/.well-known/agent.json` endpoint.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\A2A\A2AService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its A2A copy owns runtime wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\A2A\A2AService` | `A2AService.php` | `Plugin::register()` |
| `NvoosContentGraphAiPlatform\A2A\AgentCard` | `AgentCard.php` | `WellKnown`, `Client` |
| `NvoosContentGraphAiPlatform\A2A\TaskManager` | `TaskManager.php` | `PushNotifications`, REST, slash commands |
| `NvoosContentGraphAiPlatform\A2A\Client` | `Client.php` | Outbound task delegation |
| `NvoosContentGraphAiPlatform\A2A\MessageTranslator` | `MessageTranslator.php` | REST, chat pipeline |
| `NvoosContentGraphAiPlatform\A2A\PushNotifications` | `PushNotifications.php` | Task state changes |
| `NvoosContentGraphAiPlatform\A2A\WebhookHandler` | `WebhookHandler.php` | REST webhook receive |
| `NvoosContentGraphAiPlatform\A2A\WellKnown` | `WellKnown.php` | `A2AService` (standalone mode) |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`, `wp_mcp_ai_federation_peers`, `wp_mcp_ai_a2a_tasks`, `wp_mcp_ai_a2a_push_configs` options, assistant post meta (`_wp_mcp_ai_tools`, `_wp_mcp_ai_skills`, `_wp_mcp_ai_agent_role`)
- **Writes to:** `wp_mcp_ai_a2a_tasks`, `wp_mcp_ai_a2a_push_configs` options, transients (agent card cache), outbound HTTP
- **Upstream callers:** `Plugin::register()`, REST controllers, slash commands
- **Downstream collaborators:** `AgentCard` ↔ `WellKnown` ↔ `Client` ↔ `TaskManager` ↔ `PushNotifications`
- **Events fired:** `wp_mcp_ai_a2a_before_task_create`, `wp_mcp_ai_a2a_task_state_change`, `wp_mcp_ai_a2a_webhook_task_update`, `wp_mcp_ai_a2a_webhook_status_update`, `wp_mcp_ai_a2a_webhook_artifact_update`, `wp_mcp_ai_a2a_webhook_message`
- **Events listened to:** `init`, `template_redirect`, `query_vars`, `redirect_canonical`

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/a2a/` (extraction Wave A). Option keys, hook names, and protocol behaviour are unchanged — data stability is sacred (extraction plan §3).
- Standalone mode (base plugin absent): `A2AService` owns the `WellKnown` wiring. Monolith mode: the base plugin's own A2A classes own runtime wiring — never register twice.
- Text domain: `nvoos-content-graph-ai-platform`.

## Deferred (tracked in MIGRATION-GAPS.md)

- A2A REST receive routes (`message/send`, `tasks/get`, `tasks/cancel`, webhook) still live in the base plugin's `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`. Standalone parity requires porting or re-implementing that controller.

## Tests

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter A2A
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Admin UI: [`Admin/`](Admin/)
