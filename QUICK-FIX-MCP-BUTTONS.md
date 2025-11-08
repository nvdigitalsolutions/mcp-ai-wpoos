# Quick Reference: MCP Diagnostic Button Fix

## Problem
Buttons with class `test-mcp-method` on the MCP Server Diagnostic page (Tools → WP oOS MCP Test) were not working.

## Root Cause
JavaScript timing issue: `wpMcpAiMcpDiagnostic` variable was undefined when the click handlers tried to use it.

## Solution
Changed from `wp_add_inline_script()` to `wp_localize_script()` in `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php` line 77.

## The Fix (6 lines changed)

```php
// BEFORE (broken):
$localized_data = array(
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'nonce'   => wp_create_nonce( 'wp-mcp-ai-mcp-diagnostic' ),
);
$inline_script = 'var wpMcpAiMcpDiagnostic = ' . wp_json_encode( $localized_data ) . ';';
wp_add_inline_script( 'jquery', $inline_script );

// AFTER (fixed):
wp_localize_script(
    'jquery',
    'wpMcpAiMcpDiagnostic',
    array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'wp-mcp-ai-mcp-diagnostic' ),
    )
);
```

## How to Test
1. Go to WordPress Admin → Tools → WP oOS MCP Test
2. Scroll to "3. MCP Methods Testing"
3. Click any "Test" button (e.g., "Test Initialize")
4. Should see AJAX request execute and results display

## Commits
- `3f527c0` - Main fix
- `4e132d9` - Documentation

## Files Changed
- `includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php` (6 lines modified)
- `tests/test-mcp-diagnostic-endpoints.php` (33 lines added)
- Documentation files (2 files created)

## WordPress Best Practice
✅ Always use `wp_localize_script()` to pass PHP data to JavaScript, not `wp_add_inline_script()`.

## Related Documentation
- See `MCP-DIAGNOSTIC-BUTTON-FIX.md` for detailed technical analysis
- See `MCP-DIAGNOSTIC-BUTTON-FIX-VISUAL.md` for visual before/after comparison
