# Chat

## Purpose

Orchestrates the AI chat experience — a tool-calling loop service that routes user messages to AI providers, executes tool calls via the core `ToolRegistry`, and assembles final responses. Supports both streaming (SSE) and non-streaming modes.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-graphify-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosGraphifyAi\Plugin::register()` |
| **Optional dependencies** | `nvoos-graphify` (required — provides `ToolRegistry`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphifyAi\Chat\ChatService` | `ChatService.php` | REST ChatController, Action Scheduler jobs |

## Inputs / Outputs / Neighbors

- **Reads from:** Conversation messages (array), `NvoosGraphifyAi\ProviderRegistry` (AI client), `NvoosGraphify\ToolRegistry` (tool execution)
- **Writes to:** AI provider APIs, tool execution results, final response arrays
- **Upstream callers:** `src/Rest/ChatController` (REST), `nvoos_graphify_ai/continue_chat` action (async)
- **Downstream collaborators:** `src/Contracts/ProviderClient` (provider interface), `nvoos-graphify` core `ToolRegistry`
- **Events fired:** None (returns results directly)
- **Events listened to:** `nvoos_graphify_ai/continue_chat`

## Conventions

- `ChatService::process()` is static — no instantiation needed.
- Maximum 5 tool-calling iterations to prevent infinite loops.
- Tool definitions are built in OpenAI-compatible format from the core tool registry.
- Streaming uses the `ProviderClient::stream()` method with a callback; non-streaming uses `ProviderClient::chat()`.

## Tests

```bash
vendor/bin/phpunit --filter '/Chat/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — API key handling

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../Contracts/ProviderClient.php`](../Contracts/ProviderClient.php), [`../Providers/`](../Providers/)
- Core dependency: [`../../nvoos-graphify/src/ToolRegistry.php`](../../nvoos-graphify/src/ToolRegistry.php)
