# `/schedule-preset` — Schedule Preset Browser

> **Pro Required:** Yes  
> **Since:** 2.1.0  
> **Capability (browse):** `edit_posts`  
> **Capability (install):** `manage_options`  
> **Alias:** `sched-preset`

## Synopsis

```
/schedule-preset [--list] [--toolkit=<cat>] [--show=<id>] [--install=<id>] [--categories] [--json]
```

Browse, inspect, and install Pro schedule presets.

## Flags

| Flag | Description |
|------|-------------|
| `--list` | List all presets (default) |
| `--toolkit=<cat>` | Filter presets by toolkit/category |
| `--show=<id>` | Show full details for a preset |
| `--install=<id>` | Install preset as a new schedule (requires `manage_options`) |
| `--categories` | List available categories/toolkits |
| `--json` | Return JSON envelope |

## Examples

```bash
# List all available presets
/schedule-preset

# Filter by toolkit
/schedule-preset --toolkit=ecommerce

# Show details for a specific preset
/schedule-preset --show=daily-product-sync

# Install a preset (creates a new schedule)
/schedule-preset --install=daily-product-sync

# List categories
/schedule-preset --categories

# JSON output
/schedule-preset --json
```

## Notes

- Requires `WP_MCP_AI_Pro_Schedule_Presets` and (for install) `WP_MCP_AI_Pro_Schedule_Manager`.
- Aliases: `sched-preset`.
- Requires Pro addon.
