# /diagnose

> **Added in:** v2.1.0 · **Capability:** `manage_options`

## Synopsis

```
/diagnose [--json]
```

Generate a diagnostic bundle for support — plugin version, WordPress version, PHP version, recent errors, recent activity, async health, and tool count. Output is wrapped in a ` ```diagnostic ` code block for easy copy-paste into issue reports.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--json` | Return raw JSON bundle | Off |

## Examples

```
/diagnose
/diagnose --json
```

## Required Capability

`manage_options`

## Bundle Contents

| Field | Source |
|-------|--------|
| `plugin_version` | `WP_MCP_AI_VERSION` constant or `wp_mcp_ai_version` option |
| `wp_version` | `get_bloginfo('version')` |
| `php_version` | `phpversion()` |
| `recent_errors` | `wp_mcp_ai_recent_errors` option (last 5) |
| `recent_activity` | `wp_mcp_ai_recent_activity` option (last 5) |
| `async_health` | `WP_MCP_AI_Async_Health_Monitor::check_async_health()` |
| `tool_count` | `count( WP_MCP_AI_Tool_Registry::get_instance()->get_tools() )` |

## Notes

- All service calls are guarded with `class_exists()` for graceful degradation.
