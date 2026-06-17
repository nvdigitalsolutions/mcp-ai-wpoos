# `/mcp-app` — MCP App Management

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability:** `manage_options`  
> **Alias:** `mcp-apps`

## Synopsis

```
/mcp-app [--list] [--assistant-id=<n>] [--test=<label>] [--discover=<label>] [--json]
```

List, test, and discover tools for MCP App connections configured on an assistant.

## Flags

| Flag | Description |
|------|-------------|
| `--list` | List MCP apps for the current assistant (default) |
| `--assistant-id=<n>` | Override assistant ID (falls back to conversation context) |
| `--test=<label>` | Test the connection for the named app |
| `--discover=<label>` | Discover available tools for the named app |
| `--json` | Return JSON envelope |

## Examples

```bash
# List apps for the current assistant
/mcp-app

# List apps for assistant 5
/mcp-app --assistant-id=5

# Test connection for the "GitHub" app
/mcp-app --test="GitHub"

# Discover tools for the "GitHub" app
/mcp-app --discover="GitHub"

# JSON output
/mcp-app --json
```

## How It Works

1. `assistant_id` is resolved from `--assistant-id` flag or `$context['assistant_id']`.
2. `WP_MCP_AI_MCP_App_Registry::get_instance()->get_apps( $assistant_id )` retrieves app configs.
3. Apps are matched by `label` (case-insensitive) for `--test` and `--discover`.

## Notes

- Requires `WP_MCP_AI_MCP_App_Registry` (loaded by `includes/mcp-apps/mcp-apps-init.php`).
- Requires `manage_options` for all operations.
- Requires Pro addon.
