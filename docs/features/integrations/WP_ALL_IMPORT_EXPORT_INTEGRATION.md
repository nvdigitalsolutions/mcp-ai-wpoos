# WP All Import & WP All Export Integration

**Status:** ✅ COMPLETE - December 24, 2025  
**Tool Count:** 9 tools (5 base + 4 Pro addon)  
**Plugin Dependencies:** WP All Import (free), WP All Export (free)

## Overview

WP oOS now provides comprehensive integration with WP All Import and WP All Export plugins, enabling AI assistants to automate WordPress data import/export workflows. The integration includes both base tools for essential operations and Pro-level tools for advanced scheduling and management.

## Base Tools (5 tools)

### Export Management

**List Export Templates** (`list_all_export_templates`)  
Lists all WP All Export templates configured on the site with metadata including:
- Export ID and name
- Export type (post type being exported)
- Creation and modification dates
- Scheduled status
- Template status

**Requirements:**
- WP All Export plugin active
- User must be logged in
- Requires `manage_options` capability
- Returns up to 50 templates (configurable via `limit` parameter)

**Use cases:**
- Assistants can discover available export templates
- Users can ask "What export templates do I have?"
- Automation workflows can list exports before triggering

---

**Trigger Export** (`trigger_all_export`)  
Executes a WP All Export template immediately, generating the export file.

**Parameters:**
- `export_id` (required): The ID of the export template to execute

**Requirements:**
- WP All Export plugin active
- User must be logged in
- Requires `manage_options` capability
- Export template must exist

**Returns:**
- Success status
- Export ID and name
- File path and URL of generated export file

**Use cases:**
- "Export all my products to CSV"
- "Run the customers export template"
- Scheduled export automation via assistants

### Import Management

**List Import Templates** (`list_all_import_templates`)  
Lists all WP All Import templates configured on the site with metadata including:
- Import ID and name
- Import type (custom post type being imported)
- Creation and modification dates
- Scheduled status
- Processing status

**Requirements:**
- WP All Import plugin active
- User must be logged in
- Requires `manage_options` capability
- Returns up to 50 templates (configurable via `limit` parameter)

**Use cases:**
- Assistants can discover available import templates
- Users can ask "What import templates are configured?"
- Automation workflows can check import availability

---

**Trigger Import** (`trigger_all_import`)  
Executes a WP All Import template immediately using the cron URL method (recommended by WP All Import).

**Parameters:**
- `import_id` (required): The ID of the import template to execute

**Requirements:**
- WP All Import plugin active
- User must be logged in
- Requires `manage_options` capability
- Import template must exist
- Import must not already be processing

**Returns:**
- Success status
- Import ID and name
- Processing status

**Technical notes:**
- Uses non-blocking HTTP request to trigger import via cron URL
- Import runs in background to avoid timeouts
- Returns immediately with processing status

**Use cases:**
- "Import the latest product data"
- "Run the customer import now"
- Scheduled import automation

---

**Get Import Status** (`get_all_import_status`)  
Checks the status and progress of a WP All Import operation.

**Parameters:**
- `import_id` (required): The ID of the import to check

**Requirements:**
- WP All Import plugin active
- User must be logged in
- Requires `manage_options` capability

**Returns:**
- Import ID and name
- Status: idle, processing, or completed
- Statistics:
  - Total imported count
  - Created count
  - Updated count
  - Skipped count
  - Deleted count
- Current iteration number
- Last activity timestamp

**Use cases:**
- "Check if my import is done"
- "What's the status of import #123?"
- Monitoring long-running imports

## Pro Tools (4 tools)

### Advanced Export Scheduling

**Schedule Export** (`schedule_all_export`)  
Schedules a WP All Export to run automatically at specified intervals.

**Parameters:**
- `export_id` (required): The export template to schedule
- `interval` (optional): Schedule frequency - hourly, twicedaily, daily, or weekly (default: daily)
- `start_time` (optional): First run time in Y-m-d H:i:s format (default: next interval)

**Requirements:**
- WP All Export plugin active
- Pro addon active
- User must be logged in
- Requires `manage_options` capability

**Returns:**
- Success status
- Export ID and name
- Scheduled interval
- Next scheduled run time

**Technical notes:**
- Uses WordPress cron system
- Automatically clears any existing schedules for the export
- Stores schedule metadata in post meta
- Registers weekly interval if needed

**Use cases:**
- "Schedule my products export to run daily at 2 AM"
- "Run the customers export weekly"
- Automated recurring exports for data integration

---

**Delete Export Template** (`delete_all_export`)  
Permanently deletes a WP All Export template and its associated files.

**Parameters:**
- `export_id` (required): The export template to delete

**Requirements:**
- WP All Export plugin active
- Pro addon active
- User must be logged in
- Requires `manage_options` capability

**Returns:**
- Success status
- Deleted export ID and name

**Side effects:**
- Clears any scheduled events for the export
- Deletes export files if they exist
- Permanently removes template and all metadata

**Use cases:**
- "Delete the old products export"
- "Remove unused export templates"
- Cleanup of obsolete exports

### Advanced Import Scheduling

**Schedule Import** (`schedule_all_import`)  
Schedules a WP All Import to run automatically at specified intervals.

**Parameters:**
- `import_id` (required): The import template to schedule
- `interval` (optional): Schedule frequency - hourly, twicedaily, daily, or weekly (default: daily)
- `start_time` (optional): First run time in Y-m-d H:i:s format (default: next interval)

**Requirements:**
- WP All Import plugin active
- Pro addon active
- User must be logged in
- Requires `manage_options` capability

**Returns:**
- Success status
- Import ID and name
- Scheduled interval
- Next scheduled run time

**Technical notes:**
- Uses WordPress cron system
- Automatically clears any existing schedules for the import
- Stores schedule metadata in post meta
- Uses cron URL method to trigger imports

**Use cases:**
- "Schedule my product feed import to run hourly"
- "Run the customer import daily at midnight"
- Automated recurring imports for data synchronization

---

**Delete Import Template** (`delete_all_import`)  
Permanently deletes a WP All Import template and its associated files.

**Parameters:**
- `import_id` (required): The import template to delete

**Requirements:**
- WP All Import plugin active
- Pro addon active
- User must be logged in
- Requires `manage_options` capability

**Returns:**
- Success status
- Deleted import ID and name

**Side effects:**
- Clears any scheduled events for the import
- Recursively deletes import files directory
- Permanently removes template and all metadata

**Use cases:**
- "Delete the test import"
- "Remove unused import templates"
- Cleanup of obsolete imports

## Tool Registration

### Base Tools
Registered in `includes/class-wp-mcp-ai-tool-registry.php` as part of the extended tools array:

```php
'WP_MCP_AI_Tool_List_All_Export_Templates' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-all-export-templates.php',
'WP_MCP_AI_Tool_Trigger_All_Export'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-trigger-all-export.php',
'WP_MCP_AI_Tool_List_All_Import_Templates' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-all-import-templates.php',
'WP_MCP_AI_Tool_Trigger_All_Import'        => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-trigger-all-import.php',
'WP_MCP_AI_Tool_Get_All_Import_Status'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-all-import-status.php',
```

### Pro Tools
Registered in `addons/pro/mcp-ai-wpoos-pro.php` as part of the Pro tools array:

```php
'WP_MCP_AI_Pro_Tool_Schedule_All_Export' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-export.php',
'WP_MCP_AI_Pro_Tool_Delete_All_Export'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-export.php',
'WP_MCP_AI_Pro_Tool_Schedule_All_Import' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-schedule-all-import.php',
'WP_MCP_AI_Pro_Tool_Delete_All_Import'   => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-delete-all-import.php',
```

## Capability Flags

All tools implement `WP_MCP_AI_Tool_Capability_Flags_Interface` with appropriate flags:

**Read-only tools** (List templates, Get status):
- `requires-plugin`
- `read-only`
- `local-only`
- `cacheable`
- `requires-capability`

**State-changing tools** (Trigger, Schedule):
- `requires-plugin`
- `state-changing`
- `local-only`
- `requires-capability`

**Destructive tools** (Delete):
- `requires-plugin`
- `state-changing`
- `destructive`
- `local-only`
- `requires-capability`

## Availability Checks

All tools implement static `is_available()` and `get_unavailable_reason()` methods:

```php
public static function is_available() {
    return class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' );
}

public static function get_unavailable_reason() {
    return __( 'The WP All Export tool is disabled because WP All Export plugin is not active.', 'wp-mcp-ai' );
}
```

## Testing

Comprehensive test coverage in `tests/test-all-import-export-tools.php`:
- Plugin dependency checks
- Authentication requirements
- Permission validation
- Tool slug uniqueness
- Interface implementation
- Capability flags declaration

## Example Conversations

### Basic Export
**User:** "Export all my products to CSV"  
**Assistant:** *Lists export templates* → *Triggers product export* → Returns download URL

### Scheduled Import
**User:** "Schedule my product feed import to run every hour"  
**Assistant:** *Lists import templates* → *Schedules hourly import* → Confirms schedule

### Status Check
**User:** "Is my import still running?"  
**Assistant:** *Checks import status* → Reports progress with statistics

## Plugin Compatibility

**WP All Export:**
- Version: Any (free version supported)
- Post types: pmxe_exports
- Hooks used: pmxe_after_export (Pro)
- Classes: PMXE_Export_Record (Pro)

**WP All Import:**
- Version: Any (free version supported)
- Post types: import
- Hooks used: pmxi_after_xml_import (Pro)
- Classes: PMXI_Import_Record
- Trigger method: Cron URL (recommended)

## Security Considerations

1. **Capability checks:** All tools require `manage_options` capability
2. **User validation:** All tools check for logged-in user
3. **Multisite support:** All tools verify site membership in multisite
4. **Input sanitization:** All IDs are sanitized with `absint()`
5. **File validation:** Export/Import file paths are validated before deletion
6. **Background execution:** Imports use non-blocking HTTP to prevent timeout attacks

## References

- [WP All Export Documentation](https://www.wpallimport.com/documentation/export-overview/)
- [WP All Import Documentation](https://www.wpallimport.com/documentation/)
- [WP All Import Action Reference](https://www.wpallimport.com/documentation/action-reference/)
- [Tool Registry](../../includes/class-wp-mcp-ai-tool-registry.php)
- [Pro Addon Registration](../../addons/pro/mcp-ai-wpoos-pro.php)
