# `/run` — Run a Saved Workflow Builder DAG

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability:** `edit_posts`  
> **Alias:** `run-workflow`

## Synopsis

```
/run <workflow-id-or-name> [--dry-run] [--list] [--json]
```

Execute a saved Workflow Builder DAG by its ID (exact) or name (case-insensitive partial match).

## Flags

| Flag | Description |
|------|-------------|
| `--list` | List all available saved workflows |
| `--dry-run` | Preview the workflow without executing |
| `--json` | Return JSON envelope |

## Examples

```bash
# List saved workflows
/run --list

# Run a workflow by exact ID
/run my-workflow-id

# Run a workflow by partial name match
/run "SEO Pipeline"

# Preview without executing
/run "SEO Pipeline" --dry-run

# JSON output
/run --list --json
```

## How It Works

1. Workflows are loaded from `wp_mcp_ai_pro_workflows` option.
2. The command matches by exact ID first, then case-insensitive name substring.
3. On execution, fires `do_action( 'wp_mcp_ai_run_workflow_builder', $workflow_id, $workflow_data, $context )`.
4. Dry-run shows node/edge counts without firing the action.

## Return Shape (JSON)

```json
{
  "success": true,
  "action": "run_workflow",
  "workflow_id": "<id>",
  "message": "Workflow \"<name>\" queued for execution."
}
```

## Notes

- Requires Pro addon.
- Guest requests are blocked.
