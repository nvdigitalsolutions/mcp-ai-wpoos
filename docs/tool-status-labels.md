# Tool Status Labels

## Overview

The Tool Status Labels feature provides a simple, file-based system for managing and displaying the development status of tools in the NV oOS Tools Manager. This allows maintainers to quickly communicate tool maturity without requiring code changes or database updates.

Status labels are displayed as **3-letter abbreviations** for a compact UI (e.g., "STA" for stable, "BET" for beta).

## Features

### Status Types

| Status | Display | Description | Color | Auto-Disable |
|--------|---------|-------------|-------|--------------|
| `stable` | **STA** | Production-ready, fully tested | Green (#46b450) | No |
| `beta` | **BET** | Testing phase, mostly stable | Blue (#5bc0de) | No |
| `dev` | **DEV** | In active development | Orange (#f0ad4e) | No |
| `experimental` | **EXP** | New features that may change | Purple (#9b59b6) | No |
| `bug` | **BUG** | Known issues exist | Red (#dc3545) | **Yes** |
| `deprecated` | **DEP** | Will be removed in future | Gray (#6c757d) | No |

### Automatic Bug Tool Disabling

Tools marked with the `bug` status are **automatically disabled** when the plugin initializes. This provides:

- **Safety**: Prevents problematic tools from being used in production
- **Quick Response**: Maintainers can immediately disable problematic tools
- **Easy Recovery**: Administrators can manually re-enable for testing if needed

## Configuration

### File Location

Status labels are managed in the `tool-status.txt` file in the documentation directory:

```
/mcp-ai-wpoos/docs/tool-status.txt
```

### File Format

```
# Comments start with #
# Format: tool_slug = status_label

create_post = stable
web_search = beta
problematic_tool = bug
```

**Rules:**
- One tool per line
- Format: `tool_slug = status_label`
- Comments start with `#`
- Empty lines are ignored
- Status labels must be alphanumeric with hyphens/underscores only

## Implementation Details

### Frontend Display

Status labels are displayed in the Tools Manager (`Settings → NV oOS → Tools → Tools Manager`) as colored badges beside tool names, similar to the "Pro" badge.

**Location:** `includes/admin/sections/class-wp-mcp-ai-section-tools.php`

**Methods:**
- `load_tool_status_labels()` - Parses docs/tool-status.txt file
- `get_tool_status_label($slug)` - Gets status for a specific tool
- `get_status_label_config($status)` - Returns display config (color, text, CSS class)

### Backend Processing

The Tool Registry automatically disables tools with "bug" status during initialization.

**Location:** `includes/class-wp-mcp-ai-tool-registry.php`

**Methods:**
- `auto_disable_bug_tools()` - Called during `init()` to disable buggy tools
- `load_tool_status_labels()` - Parses docs/tool-status.txt file (duplicate of frontend method)

### Caching

Both implementations use static caching to avoid reading the file multiple times:

```php
static $status_labels = null;
if ( null !== $status_labels ) {
    return $status_labels;
}
```

## Usage Examples

### Adding a Status Label

1. Open `docs/tool-status.txt`
2. Add a line with the tool slug and desired status:
   ```
   my_custom_tool = beta
   ```
3. Save the file
4. Reload the Tools Manager page - the label appears immediately

### Marking a Tool as Buggy

When a critical bug is discovered:

1. Edit `docs/tool-status.txt`:
   ```
   problematic_tool = bug
   ```
2. Save and commit
3. On next plugin load, the tool is automatically disabled
4. The tool shows a red "Bug" badge in the Tools Manager
5. The tool cannot be used until manually re-enabled or status changed

### Removing a Status Label

Simply delete or comment out the line:
```
# problematic_tool = bug
```

The tool will no longer display a status badge.

## Security Considerations

### File Validation

- Status labels are validated with regex: `/^[a-zA-Z0-9_-]+$/`
- Invalid labels are silently ignored
- File is read with `file_get_contents()` (local file, safe)
- Appropriate phpcs:ignore comment added for WordPress Coding Standards

### Auto-Disable Security

- Auto-disable runs during plugin initialization
- Only affects tools explicitly marked with "bug" status
- Administrators can override via Tools Manager UI
- No database writes occur unless tool state changes

## Developer Notes

### Extending Status Types

To add new status types:

1. Update `docs/tool-status.txt` documentation header
2. Add new status config in `get_status_label_config()`:
   ```php
   'new_status' => array(
       'class' => 'wp-mcp-ai-status-new',
       'text'  => __( 'New Status', 'wp-mcp-ai' ),
       'color' => '#hexcolor',
   ),
   ```
3. Update README.md documentation
4. Consider if auto-disable logic is needed

### Testing Status Labels

1. Add test entries to `docs/tool-status.txt`
2. Navigate to Tools Manager
3. Verify badges display correctly
4. Test "bug" status disables tool
5. Verify manual re-enable works

### Internationalization

Status label text is translatable:
```php
'text' => __( 'Stable', 'wp-mcp-ai' )
```

All status labels support WordPress translation system.

## Troubleshooting

### Status Label Not Appearing

1. Check tool slug is correct (matches `get_slug()` method)
2. Verify file format is correct (no extra spaces)
3. Check status label is valid (alphanumeric, hyphens, underscores only)
4. Clear any page caching

### Tool Not Auto-Disabled

1. Verify status is exactly `bug` (case-sensitive)
2. Check tool is registered before `auto_disable_bug_tools()` runs
3. Verify plugin initialization completed
4. Check WordPress error logs for issues

### File Changes Not Reflected

The file is read on every page load (with static caching per request). If changes don't appear:

1. Hard refresh browser (Ctrl+F5)
2. Clear WordPress object cache if enabled
3. Check file permissions (must be readable)
4. Verify file is in correct location

## Future Enhancements

Potential improvements for consideration:

- [ ] Add admin notice when tools are auto-disabled
- [ ] Log auto-disable events for audit trail
- [ ] Add filter hook to override auto-disable behavior
- [ ] Support for tool-specific messages in status file
- [ ] Bulk status update UI in admin
- [ ] Status history tracking

## Related Files

- `docs/tool-status.txt` - Status label configuration
- `includes/admin/sections/class-wp-mcp-ai-section-tools.php` - Frontend display
- `includes/class-wp-mcp-ai-tool-registry.php` - Backend auto-disable logic
- `README.md` - User documentation
- `docs/tool-status-labels.md` - This file

## References

- [Tools Manager UI](../includes/admin/sections/class-wp-mcp-ai-section-tools.php)
- [Tool Registry](../includes/class-wp-mcp-ai-tool-registry.php)
- [Tool Reference](reference/tools/tool-reference.md)
