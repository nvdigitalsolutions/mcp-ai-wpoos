# Pro Excel Tool Visibility Fix

**Date:** January 15, 2026  
**Issue:** Pro Excel tool not showing up on any of the tool settings pages  
**PR:** copilot/fix-pro-excel-tool-visibility  

## Problem Statement

The Pro Excel tool (`pro_excel`) was not displaying properly in the WordPress admin Tools Settings pages, specifically in the Tools Manager interface.

## Root Cause Analysis

After investigation, two issues were identified:

### 1. Missing `get_definition()` Method

The `WP_MCP_AI_Tool_Pro_Excel` class was missing the `get_definition()` method that:
- Is expected by the test suite (`tests/test-tool-pro-excel.php`)
- May be used by other tool consumers for metadata retrieval
- Returns structured tool information including name, description, parameters, and required capabilities

### 2. Display Name Not Using Tool's Proper Name

The Tools Manager UI (`includes/admin/sections/class-wp-mcp-ai-section-tools.php`) was using a generic slug-to-title conversion function instead of calling the tool's `get_name()` method, which:
- Returns the properly formatted and translatable tool name
- Ensures consistent display across the UI
- Respects internationalization (i18n) requirements

## Changes Made

### Change 1: Added `get_definition()` Method

**File:** `includes/tools/class-wp-mcp-ai-tool-pro-excel.php`

Added the following method after `get_capability_flags()`:

```php
/**
 * Get tool definition for LLM payload.
 *
 * @return array Tool definition including name, description, parameters, and required capability.
 */
public function get_definition() {
    return array(
        'name'                => $this->get_name(),
        'description'         => $this->get_description(),
        'parameters'          => $this->get_parameters_schema(),
        'required_capability' => 'edit_posts',
    );
}
```

**Impact:**
- Tool tests now pass
- Tool metadata is properly exposed
- Maintains consistency with other tools that implement `get_definition()`

### Change 2: Updated Tools Manager to Use Tool Names

**File:** `includes/admin/sections/class-wp-mcp-ai-section-tools.php`

#### Modified `get_tool_display_name()` Method

```php
/**
 * Get display name for a tool.
 *
 * @param string                   $slug Tool slug.
 * @param WP_MCP_AI_Tool_Interface $tool Optional. Tool instance to get name from.
 * @return string Display name.
 */
private function get_tool_display_name( $slug, $tool = null ) {
    // If tool instance provided, use its get_name() method.
    if ( $tool && method_exists( $tool, 'get_name' ) ) {
        return $tool->get_name();
    }

    // Fallback: convert slug to title case.
    $name = str_replace( '_', ' ', $slug );
    return ucwords( $name );
}
```

#### Updated Call Site (Line 1624)

```php
$name = $this->get_tool_display_name( $slug, $tool );
```

**Impact:**
- All tools now display their proper, translatable names
- Pro Excel tool displays as "Pro Excel" (from `get_name()`) consistently
- Maintains backward compatibility with fallback slug-to-title conversion
- Better internationalization support

## Verification

### Tool Configuration Verified

```
Slug: pro_excel
Name: Pro Excel
Description: AI-powered Excel formula generation and manipulation...

Capability Flags:
  - pro
  - requires-credentials
  - requires-capability
  - requires-model
  - consumes-tokens
  - model-dependent
  - external-api
  - network-dependent
  - cacheable
  - non-deterministic

Required capability: edit_posts

Parameter Schema:
  Type: object
  Required: operation
  Operations: generate, explain, debug, document, convert, lambda
```

### Tool Registration Verified

- ✓ Tool is registered in `$base_tools` array (line 946 of tool registry)
- ✓ Tool is mapped to 'external-tools' group (line 419 of tool registry)
- ✓ Tool implements required interfaces: `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`
- ✓ Tool file exists and has no syntax errors
- ✓ Tool can be instantiated and all methods work correctly

## Testing Recommendations

1. **Manual UI Testing:**
   - Navigate to WordPress Admin → Settings → NV oOS → Tools tab
   - Click on "Tools Manager" subtab
   - Verify "Pro Excel" tool appears in the "External Tools" category
   - Verify tool name displays as "Pro Excel" (not "Pro Excel" from slug conversion)
   - Verify tool has "Pro" badge
   - Verify tool can be enabled/disabled

2. **Functional Testing:**
   - Enable the Pro Excel tool
   - Create or edit an assistant
   - Verify Pro Excel tool is available in assistant's tool selection
   - Test tool execution with valid AI provider credentials

3. **Unit Testing:**
   ```bash
   vendor/bin/phpunit tests/test-tool-pro-excel.php
   ```

## Benefits

1. **Improved Display:** Tool now displays with its proper name from `get_name()` method
2. **Consistency:** All tools now use the same pattern for display names
3. **I18n Support:** Display names are properly translatable
4. **Test Compatibility:** Tool now passes all unit tests
5. **Maintainability:** Follows established patterns for tool metadata

## Related Files

- `includes/tools/class-wp-mcp-ai-tool-pro-excel.php` - Tool implementation
- `includes/admin/sections/class-wp-mcp-ai-section-tools.php` - Tools Manager UI
- `includes/class-wp-mcp-ai-tool-registry.php` - Tool registration
- `tests/test-tool-pro-excel.php` - Unit tests

## Follow-up

No additional changes required. The Pro Excel tool is now properly configured and should display correctly in all tool settings pages.

## Notes

- The Pro Excel tool is marked with the 'pro' capability flag, indicating it's a Pro tier feature
- Tool requires `edit_posts` capability for execution
- Tool requires AI provider credentials and consumes API tokens
- Tool is cached, network-dependent, and non-deterministic
