# Fix: Regulatory Registration Toolkit Assistant Dropdown

## Problem
The Regulatory Registration Toolkit settings page was not showing the assistant dropdown in the Configuration tab, even though it was documented to have Research & Add functionality enabled.

## Root Cause
In the file `addons/pro/includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php`, line 28 had:

```php
$this->has_research = false;
```

This prevented the base class (`WP_MCP_AI_Toolkit_Settings_Base`) from registering the assistant dropdown field.

## Solution
Changed line 28 to:

```php
$this->has_research = true;
```

This enables:
1. **"Enable Research & Add"** checkbox in the Configuration tab
2. **"Research Assistant"** dropdown showing available AI assistants
3. **"Research & Add"** tab in the settings navigation

## Impact
With `has_research = true`, the Regulatory Registration Toolkit settings page now includes:

### Configuration Tab
The Configuration tab now shows two additional fields before the custom toolkit settings:

```
Configuration
├── Enable Research & Add
│   └── [✓] Enable Research & Add functionality
│       └── Description: "When enabled, you can use AI to create and manage data for this toolkit."
│
├── Research Assistant
│   └── [Dropdown: -- Select Assistant --]
│       └── Description: "Select the AI assistant to use for Research & Add functionality."
│       └── Options: Lists all published assistants (e.g., Sophie, etc.)
```

### New Tab
A new "Research & Add" tab appears in the navigation alongside:
- Overview
- Configuration
- Tools Management
- **Research & Add** ← New
- Help & Documentation

## Files Changed
1. `addons/pro/includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php` - Changed `has_research` from `false` to `true` (1 line)

## Test Added
Created `tests/test-regulatory-toolkit-research-flag.php` to verify:
- The `has_research` property is set to `true`
- The `research_assistant_id` field is properly registered

## Alignment with Documentation
This change aligns with the existing documentation in:
- `docs/implementation-history/2026/january/REGULATORY_TOOLKIT_ENHANCEMENT.md` (line 79)

The documentation explicitly states:
```php
protected $has_research = true;
```

## Before vs After

### Before (has_research = false)
```
Configuration Tab:
┌─────────────────────────────────────────┐
│ Default Regulatory Authority            │
│ Enable Document Expiry Alerts           │
│ Expiry Alert Days                       │
│ Enable PDF Generation                   │
│ ... (other settings)                    │
└─────────────────────────────────────────┘
```

### After (has_research = true)
```
Configuration Tab:
┌─────────────────────────────────────────┐
│ Enable Research & Add                   │  ← NEW
│ Research Assistant                      │  ← NEW (Dropdown)
│ ─────────────────────────────────────── │
│ Default Regulatory Authority            │
│ Enable Document Expiry Alerts           │
│ Expiry Alert Days                       │
│ Enable PDF Generation                   │
│ ... (other settings)                    │
└─────────────────────────────────────────┘

Navigation Tabs:
Overview | Configuration | Tools Management | Research & Add | Help
                                                    ↑
                                                   NEW
```

## Technical Details

The base class `WP_MCP_AI_Toolkit_Settings_Base` has logic in the `register_settings()` method (lines 160-177):

```php
// Research & Add section (if supported).
if ( $this->has_research ) {
    add_settings_field(
        'enable_research',
        __( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
        array( $this, 'render_enable_research_field' ),
        $this->option_name,
        $this->option_name . '_config_section'
    );

    add_settings_field(
        'research_assistant_id',
        __( 'Research Assistant', 'mcp-ai-wpoos-pro' ),
        array( $this, 'render_research_assistant_field' ),
        $this->option_name,
        $this->option_name . '_config_section'
    );
}
```

The `render_research_assistant_field()` method (lines 672-707) renders a dropdown populated with all published assistants from the `mcp_ai_assistant` post type.

## Testing

To test manually:
1. Navigate to WordPress admin
2. Go to `Regulatory Products → Settings`
3. Click the "Configuration" tab
4. Verify the presence of:
   - "Enable Research & Add" checkbox
   - "Research Assistant" dropdown with available assistants

## Related Issues
Fixes the issue where the settings page at:
`/wp-admin/admin.php?page=wp-mcp-ai-regulatory-registration-toolkit-settings&tab=configuration`

was not showing the assistant dropdown as expected.
