# /preset

> **Added in:** v2.1.0 · **Capability:** `edit_posts` (list/show/active); `manage_options` (apply)

## Synopsis

```
/preset [--list] [--show=<id>] [--apply=<id>] [--active] [--json]
```

List, inspect, and apply orchestration presets via `WP_MCP_AI_Orchestration_Preset_Service`.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--list` | List all 13 presets with id, name, description (default action) | On |
| `--show=<id>` | Show full config for a preset ID | — |
| `--apply=<id>` | Apply a preset (`manage_options` required) | — |
| `--active` | Show the currently active preset | — |
| `--json` | Return raw JSON output | Off |

## Examples

```
/preset
/preset --active
/preset --show=balanced
/preset --apply=research-focused
/preset --json
```

## Required Capability

`edit_posts` for listing and inspecting; `manage_options` for `--apply`

## Notes

- Requires `WP_MCP_AI_Orchestration_Preset_Service`. Returns a graceful message if unavailable.
- The currently active preset is marked with ✅ in the table view.
