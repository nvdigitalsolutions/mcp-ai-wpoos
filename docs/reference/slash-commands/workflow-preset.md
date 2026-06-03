# `/workflow-preset` — Workflow Builder Preset Browser

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability (browse):** `edit_posts`  
> **Capability (install):** `manage_options`  
> **Alias:** `wf-preset`

## Synopsis

```
/workflow-preset [--list] [--category=<cat>] [--categories] [--show=<id>] [--install=<id>] [--json]
```

Browse, inspect, and install Workflow Builder presets.

## Flags

| Flag | Description |
|------|-------------|
| `--list` | List all presets (default) |
| `--category=<cat>` | Filter by category |
| `--categories` | List available categories |
| `--show=<id>` | Show full details for a preset |
| `--install=<id>` | Install preset (requires `manage_options`) |
| `--json` | Return JSON envelope |

## Examples

```bash
# List all workflow presets
/workflow-preset

# Filter by category
/workflow-preset --category=content

# Show details
/workflow-preset --show=seo-optimization-pipeline

# Install a preset
/workflow-preset --install=seo-optimization-pipeline

# List categories
/workflow-preset --categories
```

## Notes

- Requires `WP_MCP_AI_Pro_Workflow_Presets` class.
- Install calls `WP_MCP_AI_Pro_Workflow_Presets::install_preset()`.
- Requires Pro addon.
