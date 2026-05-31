# Domain Events

## Purpose

Typed PSR-14 domain events that replace WordPress action hooks (`do_action` / `apply_filters`) in the extracted core. Each event carries structured context so subscribers receive typed data instead of string hook names.

## Tier

| | |
|---|---|
| **Distribution** | `oos/core` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | PSR-14 (EventDispatcherInterface) |

## Public Surface

| Event | Replaces | Fired by |
|---|---|---|
| `BeforeToolExecution` | `wp_mcp_ai_before_tool_execution` | `ToolRegistry::execute()` |
| `AfterToolExecution` | `wp_mcp_ai_after_tool_execution` | `ToolRegistry::execute()` |
| `BeforeChatRequest` | `wp_mcp_ai_before_chat_request` | `ChatOrchestrator::handleChat()` |
| `AfterChatResponse` | `wp_mcp_ai_after_chat_response` | `ChatOrchestrator::handleChat()` |
| `AgenticIterationComplete` | `wp_mcp_ai_agentic_iteration_complete` | Agentic loop |
| `AgenticLoopCompleted` | `wp_mcp_ai_agentic_loop_completed` | Agentic loop |
| `CostCalculated` | `wp_mcp_ai_cost_calculated` | `ChatOrchestrator` |
| `ToolsRegistered` | `wp_mcp_ai_register_tools` | `ToolRegistry::notifyRegistered()` |

## Conventions

- All events are immutable — `public readonly` properties only.
- `BeforeChatRequest` has mutable `messages` and `options` for filter subscribers.
- The WordPress adapter's `EventDispatcher` bridges these to `do_action`/`apply_filters`.

## Also Load

- [`../Contract/EventDispatcherInterface.php`](../Contract/EventDispatcherInterface.php)
