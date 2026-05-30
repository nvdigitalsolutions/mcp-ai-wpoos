# WP All Import / Export Toolkit

> Scheduled and bulk data import/export operations via WP All Import / WP All Export.

## Purpose

Tools for scheduling, triggering, and managing bulk data import and export operations through the WP All Import and WP All Export plugins.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Delete All Export | `delete_all_export` | Remove a WP All Export configuration |
| Delete All Import | `delete_all_import` | Remove a WP All Import configuration |
| Schedule All Export | `schedule_all_export` | Schedule a recurring data export |
| Schedule All Import | `schedule_all_import` | Schedule a recurring data import |

## Dependencies

- WordPress 6.0+
- WP All Import Pro (for import operations)
- WP All Export Pro (for export operations)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
