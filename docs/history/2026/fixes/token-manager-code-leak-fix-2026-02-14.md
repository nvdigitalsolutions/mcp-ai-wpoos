# Fix: Code Leaking on Token Manager Page

**Date**: 2026-02-14  
**Status**: ✅ Complete  
**Issue**: JavaScript code was being displayed as raw text on the Token Manager page

## Problem

On the Token Manager page at `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_tool`, JavaScript code from the filter bar was being displayed as plain text on the page instead of being executed by the browser.

The visible code included:
```javascript
(function($) {
    $('#wp-mcp-ai-filter-tools').on('click', function() {
        // Filter functionality code...
    });
})(jQuery);
```

## Root Cause

In `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php` at line 385, the output from `WP_MCP_AI_Tools_Filter_Bar_Renderer::render()` was being passed through `wp_kses_post()`:

```php
echo wp_kses_post(
    WP_MCP_AI_Tools_Filter_Bar_Renderer::render(
        array(
            'tab'          => 'token_manager',
            'view'         => 'per_tool',
            'search'       => esc_attr( $search ),
            'filter_group' => esc_attr( $filter_group ),
            'clear_url'    => esc_url( admin_url( '...' ) ),
        )
    )
);
```

**The Issue**: `wp_kses_post()` is a WordPress sanitization function that strips out disallowed HTML tags, including `<script>` tags. This caused the JavaScript code to be rendered as plain text on the page instead of being executed.

## Solution

The fix was to **remove the `wp_kses_post()` wrapper** and use direct echo output, following the same pattern already used in the orchestration renderer (`class-wp-mcp-ai-tools-orchestration-renderer.php` at line 79).

### Changes Made

**File**: `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`

```php
// BEFORE (lines 384-395)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer outputs escaped HTML.
echo wp_kses_post(
    WP_MCP_AI_Tools_Filter_Bar_Renderer::render(
        array(
            'tab'          => 'token_manager',
            'view'         => 'per_tool',
            'search'       => esc_attr( $search ),
            'filter_group' => esc_attr( $filter_group ),
            'clear_url'    => esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=token_manager&view=per_tool' ) ),
        )
    )
);
```

```php
// AFTER (lines 384-393)
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render() method.
echo WP_MCP_AI_Tools_Filter_Bar_Renderer::render(
    array(
        'tab'          => 'token_manager',
        'view'         => 'per_tool',
        'search'       => $search, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_attr() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
        'filter_group' => $filter_group, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_attr() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
        'clear_url'    => admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=token_manager&view=per_tool' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped with esc_url() in WP_MCP_AI_Tools_Filter_Bar_Renderer::render().
    )
);
```

### Key Changes:
1. **Removed** `wp_kses_post()` wrapper
2. **Removed** redundant `esc_attr()` and `esc_url()` calls (already handled in the renderer)
3. **Updated** phpcs:ignore comments to match the pattern in orchestration renderer
4. **Added** inline phpcs:ignore comments for each parameter explaining where escaping occurs

## Security Analysis

### Why This Fix Is Safe

1. **All user input is properly sanitized**:
   - `$search` is sanitized with `sanitize_text_field()` at line 373
   - `$filter_group` is sanitized with `sanitize_key()` at line 375

2. **All output is properly escaped** in the `WP_MCP_AI_Tools_Filter_Bar_Renderer::render()` method:
   - Search input: `esc_attr()` at line 83
   - Filter group: `selected()` helper at line 101
   - Clear URL: `esc_url()` at line 114
   - Category labels: `esc_html()` at lines 76, 90, 94, 102, etc.

3. **Inline script is intentional and safe**:
   - The script is static code with no user input
   - It uses properly escaped PHP constants via `esc_js()`
   - It has phpcs:ignore comment acknowledging NonEnqueuedScript (line 122)

4. **Pattern already used elsewhere**:
   - Same approach in `class-wp-mcp-ai-tools-orchestration-renderer.php` (line 79)
   - Consistent with other admin sections that include inline scripts

### Why wp_kses_post() Was Wrong Here

`wp_kses_post()` is designed for sanitizing user-generated content (like post content) where script tags should be stripped for security. However, in this case:

- The content is **not user-generated**
- The script tags are **intentional and necessary**
- All user input is **already sanitized** before being passed to the renderer
- The renderer **already escapes** all dynamic content

## Testing

A comprehensive test suite was added in `tests/test-token-manager-filter-bar-rendering.php` that verifies:

1. ✅ Filter bar elements are rendered correctly
2. ✅ Script tags are present and not stripped
3. ✅ JavaScript functionality is included
4. ✅ JavaScript code does not leak outside script tags
5. ✅ Filter bar renderer class is loaded properly

## Verification

### Before Fix
- JavaScript code visible as plain text on the page
- Filter functionality broken (clicking "Filter" button did nothing)
- User experience degraded

### After Fix
- JavaScript code properly executed by browser
- Filter functionality working correctly
- Professional appearance restored

## Related Files

- **Primary Fix**: `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`
- **Renderer**: `includes/admin/class-wp-mcp-ai-tools-filter-bar-renderer.php`
- **Tests**: `tests/test-token-manager-filter-bar-rendering.php`
- **Pattern Reference**: `includes/admin/class-wp-mcp-ai-tools-orchestration-renderer.php`

## Commits

1. `05a2e32` - Fix code leaking on token manager page by removing wp_kses_post wrapper
2. `2bd7b80` - Add test for token manager filter bar rendering
3. `924b457` - Improve test assertions to be less brittle

## Security Summary

✅ **No security vulnerabilities introduced or found**:
- All user input properly sanitized
- All output properly escaped
- CodeQL security scan: Clean (no issues)
- Code review: Passed
- Follows WordPress coding standards
- Matches existing patterns in codebase

## Impact

- **Minimal Change**: Only 18 lines modified in one file
- **No Breaking Changes**: Functionality enhanced, no features removed
- **Improved User Experience**: Filter functionality now works as intended
- **Better Code Quality**: Aligns with patterns used elsewhere in codebase
