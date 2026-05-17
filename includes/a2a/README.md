# A2A — Agent-to-Agent Protocol

## Purpose

Implements the [A2A protocol](https://a2a-protocol.org/) surface so this WordPress site can advertise its assistants as remote agents and exchange tasks, messages, and artifacts with peer agents — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (eager `require_once` block); REST routes wired by [`includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`](../rest/) |
| **Optional dependencies** | none — the `.well-known/agent.json` handler activates only when `enable_a2a_server` is set in plugin settings |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_A2A_Agent_Card` | `class-wp-mcp-ai-a2a-agent-card.php` | `includes/rest/` (A2A controller), `class-wp-mcp-ai-federation.php` |
| `WP_MCP_AI_A2A_Task_Manager` | `class-wp-mcp-ai-a2a-task-manager.php` | `includes/rest/` (A2A controller), `class-wp-mcp-ai-a2a-push-notifications.php` |
| `WP_MCP_AI_A2A_Message_Translator` | `class-wp-mcp-ai-a2a-message-translator.php` | `includes/rest/` (A2A controller) |
| `WP_MCP_AI_A2A_Client` | `class-wp-mcp-ai-a2a-client.php` | outbound peer calls (federation, workflow triggers) |
| `WP_MCP_AI_A2A_Push_Notifications` | `class-wp-mcp-ai-a2a-push-notifications.php` | `includes/rest/` A2A controller after task completion |
| `WP_MCP_AI_A2A_Webhook_Handler` | `class-wp-mcp-ai-a2a-webhook-handler.php` | inbound webhook routes for peer-pushed updates |
| `WP_MCP_AI_A2A_WellKnown` | `class-wp-mcp-ai-a2a-wellknown.php` | `class-wp-mcp-ai-federation.php` (gated on settings) |

Task state constants (`STATE_SUBMITTED`, `STATE_WORKING`, `STATE_COMPLETED`, …) are part of the public surface — peers and Pro federation code branch on them.

## Inputs / Outputs / Neighbors

- **Reads from:** assistant CPT meta (provider/model/skills/capabilities mapped into the Agent Card), `wp_mcp_ai_settings['enable_a2a_server']` and `['default_assistant']`, option `wp_mcp_ai_a2a_push_configs`.
- **Writes to:** option `wp_mcp_ai_a2a_tasks` (task store), outbound HTTP via `WP_MCP_AI_A2A_Client` (peer agents) and `WP_MCP_AI_A2A_Push_Notifications` (webhook delivery), transient Agent Card cache (`wp_mcp_ai_a2a_card_*`).
- **Upstream callers:** [`includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`](../rest/), [`includes/class-wp-mcp-ai-federation.php`](../), [`includes/class-wp-mcp-ai-workflow-trigger-cpt.php`](../) (listens for `wp_mcp_ai_a2a_message_received`).
- **Downstream collaborators:** [`includes/services/`](../services/) chat service (via `Message_Translator` → NV oOS chat shape), assistant CPT in [`includes/assistants/`](../assistants/) for card sourcing.
- **Events fired:** `wp_mcp_ai_a2a_agent_card` (filter), `wp_mcp_ai_a2a_register_extensions` (filter), `wp_mcp_ai_a2a_before_task_create`, `wp_mcp_ai_a2a_task_state_change`, `wp_mcp_ai_a2a_message_received`, `wp_mcp_ai_a2a_webhook_task_update`, `wp_mcp_ai_a2a_webhook_status_update`, `wp_mcp_ai_a2a_webhook_artifact_update`, `wp_mcp_ai_a2a_webhook_message`.
- **Events listened to:** WordPress rewrite/`template_redirect` chain (`WellKnown` handler), `init` (`query_vars` registration).

## Conventions

- Task-state transitions go through `WP_MCP_AI_A2A_Task_Manager::transition_state()` — never write the `status.state` field directly, or push notifications and the `wp_mcp_ai_a2a_task_state_change` hook will skip.
- The `.well-known/agent.json` endpoint must respond with raw JSON only — the `WellKnown` handler clears output buffers before echoing; preserve that behaviour for any new well-known route.
- Agent Cards carry the `A2A-Version` header (`PROTOCOL_VERSION` constant). Bump the constant only as part of a coordinated protocol upgrade — peers cache cards for an hour.
- Webhook delivery uses blocking retry sleeps because it runs after task completion; long-running deployments should offload via the `wp_mcp_ai_a2a_task_state_change` hook rather than relaxing the retry loop here.

## Tests

```bash
vendor/bin/phpunit tests/test-a2a.php
```

The REST surface that wraps this folder is covered separately under `tests/rest/` and `tests/rest-api/`. Outbound `WP_MCP_AI_A2A_Client` calls are exercised through the federation tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — webhook/HTTP egress + nonce rules (always)
- [`.context/rest-api.md`](../../.context/rest-api.md) — the A2A controller lives in `includes/rest/`
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat and tool envelope rules
- A2A protocol spec: <https://a2a-protocol.org/latest/specification/>

## See Also

- Sibling: [`acp/`](../acp/) — different protocol surface (IDE clients), often confused with A2A
- Upstream parent: [`includes/`](../) — `class-wp-mcp-ai-federation.php` gates server activation
- Controller: [`includes/rest/`](../rest/) — `class-wp-mcp-ai-rest-a2a-controller.php`
