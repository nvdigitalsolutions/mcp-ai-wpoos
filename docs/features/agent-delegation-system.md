# Agent Delegation System

**Status:** Stable — v1.1.40
**Category:** Pro Feature — Multi-Agent Orchestration  
**Introduced:** July 2026 (PRs #5640, #5644, #5647, #5654–#5657)  

## Overview

The Agent Delegation system allows AI assistants to delegate tasks to other agents within the NV oOS ecosystem. In v1.1.39, the delegation subsystem underwent a major rework for reliability, performance, and developer experience.

## Architecture

### Execution Model

Delegation now uses **inline execution** instead of async scheduling:

| Aspect | Before (v1.1.38) | After (v1.1.39) |
|--------|-----------------|-----------------|
| Execution | Async via cron | Inline (synchronous) |
| Result delivery | Delayed (next cron tick) | Immediate |
| Error handling | Silent failures | Retry with error reporting |
| Dispatch method | Role executor | REST chat endpoint |
| Timestamp accuracy | Off by cron tick delay | Precise |

### REST-Based Dispatch

Delegated tasks are now dispatched via the REST chat endpoint (`/wp-json/mcp-ai/v1/chat`) instead of a no-op role executor. This means:

- Delegated tasks use the same authentication and authorization as normal chat requests.
- Streaming responses are supported for delegated tasks.
- Cost tracking and token accounting apply uniformly.
- Provider routing respects the delegating agent's configuration.

### Cron Resilience

Background delegation jobs now include:

- **Retry logic** — failed jobs are retried up to 3 times with exponential backoff.
- **Error reporting** — failures are logged to the plugin error log and surfaced in the admin UI.
- **Health checks** — a `wp_mcp_ai_delegation_health` cron job monitors stuck delegations.
- **`spawn_cron()` integration** — `spawn_cron()` is called after `wp_schedule_single_event()` to trigger deferred jobs immediately, avoiding the default WP-Cron tick delay.

### Agent Resolution

The `delegate_to_agent` tool now supports two resolution modes:

1. **ID-based** (existing) — pass `agent_id` parameter.
2. **Name-based** (new in v1.1.39) — pass `agent_name` parameter. The system resolves the name to an ID by searching the assistant registry.

```json
{
  "agent_name": "Content Writer Pro",
  "task": "Write a blog post about AI trends",
  "context": { "topic": "AI in 2026", "tone": "professional" }
}
```

## Configuration

### Enabling Sensitive Tools in Delegation

The `allowSensitiveTools` configuration flag (added in v1.1.39) controls whether delegated agents can use tools marked as sensitive:

```json
{
  "delegate_to_agent": {
    "agent_id": 42,
    "allowSensitiveTools": true
  }
}
```

This flag is propagated through the delegation dispatch chain — from the delegating agent through the REST endpoint to the delegate agent.

## SPA v2 Integration

### Tasks Drawer

The Pro SPA v2 includes a **Tasks Drawer** that displays:

- Pending delegation tasks with status indicators.
- Completed tasks with results.
- Failed tasks with error details and retry button.
- **failedCount badge** on the toolbar button showing the number of errored tasks.

### Wire-Up

Delegation controls are accessible from:

- **Tool Shortcuts drawer** — quick-access `delegate_to_agent` with pre-configured agent profiles.
- **Slash Commands drawer** — `/delegate` command with autocomplete for agent names.
- **Message toolbar** — delegate action on assistant messages.

## WP-CLI Commands

```bash
# List pending delegation tasks
wp mcp-ai delegation list --status=pending

# Retry a failed delegation
wp mcp-ai delegation retry --task-id=123

# Check delegation health
wp mcp-ai delegation health

# Cancel a pending delegation
wp mcp-ai delegation cancel --task-id=123
```

## Hooks & Filters

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_before_delegate` | Action | Fires before delegation dispatch. Receives agent ID and task. |
| `wp_mcp_ai_after_delegate` | Action | Fires after delegation completes. Receives result. |
| `wp_mcp_ai_delegation_failed` | Action | Fires when delegation fails. Receives error and task data. |
| `wp_mcp_ai_delegation_agent_resolution` | Filter | Modify agent name → ID resolution logic. |
| `wp_mcp_ai_delegation_retry_count` | Filter | Override retry count (default: 3). |

## Error Handling

| Error Condition | Behavior |
|----------------|----------|
| Agent not found (by name or ID) | Returns `WP_Error` with descriptive message |
| Agent lacks required capability | Returns `WP_Error` with capability name |
| REST endpoint failure | Retries up to 3 times, then logs and surfaces in Tasks Drawer |
| Timeout during delegation | Marked as failed with timeout code; retry button available |
| Cron job stuck | Health check cron detects and re-queues |

## Related Documentation

- [Meta-Harness Auto-Optimization](meta-harness-auto-optimization.md) — trace capture for delegation telemetry
- [Pro SPA v2](pro-spa-v2.md) — tasks drawer and delegation UI
- [Tool Presets System](tool-presets-system.md) — tool availability in delegated contexts
