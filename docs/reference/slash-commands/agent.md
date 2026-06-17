# `/agent` — A2A Agent Delegation

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability:** `edit_posts`  
> **Alias:** `a2a`

## Synopsis

```
/agent [--list] [--status=<task_id>] [--cancel=<task_id>] [--send=<url> --message=<text>] [--discover=<url>] [--limit=<n>] [--json]
```

Manage Agent-to-Agent (A2A) tasks, send messages to remote agents, and discover agent capabilities.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--list` | List active A2A tasks (default) | — |
| `--status=<task_id>` | Get status of a specific task | — |
| `--cancel=<task_id>` | Cancel a task | — |
| `--send=<agent_url>` | Send a message to a remote agent | — |
| `--message=<text>` | Message text (used with `--send`) | — |
| `--discover=<agent_url>` | Discover capabilities of a remote agent | — |
| `--limit=<n>` | Max tasks to list | `10` |
| `--json` | Return JSON envelope | Off |

## Examples

```bash
# List active tasks
/agent

# Get status of a specific task
/agent --status=task-abc123

# Cancel a task
/agent --cancel=task-abc123

# Send a message to a remote agent
/agent --send=https://other-site.com/wp-json/a2a/v1/ --message="Process order #42"

# Discover a remote agent's capabilities
/agent --discover=https://other-site.com/wp-json/a2a/v1/

# List up to 25 tasks
/agent --limit=25 --json
```

## Notes

- Requires `WP_MCP_AI_A2A_Task_Manager` for task operations.
- Requires `WP_MCP_AI_A2A_Client` for `--send` and `--discover`.
- Both classes are checked with `class_exists()` before use; graceful degradation if unavailable.
- Requires Pro addon.
