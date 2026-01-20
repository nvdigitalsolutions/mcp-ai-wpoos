# Settings Cross-Tab Data Loss Fix - Summary

## Problem

Users reported that when saving settings in one tab (e.g., Providers), values from other tabs (e.g., General) were being reset to their default values.

**Example Scenario:**
1. User saves `request_timeout = 120` on the General tab
2. User navigates to Providers tab and saves `openai_api_key`
3. User returns to General tab and sees `request_timeout = 60` (the default value)

This created a frustrating user experience where settings appeared to be randomly resetting.

## Root Cause

The issue was traced to how number fields handle empty string values during sanitization:

1. When a form is submitted, only fields from the active tab are included in the POST data
2. However, during sanitization, if any section encountered a number field with an empty value, it would save it as an empty string (`''`) in the database
3. When the field was later rendered, the code would:
   - Retrieve the empty string from the database via `get_setting($key, $default)`
   - The `isset()` check would return true (because the key exists)
   - But the empty string value would be treated as falsy
   - The field's default value would be shown in the UI

This created a vicious cycle where:
- Field is saved as empty string
- Field displays as default value (60)
- User doesn't notice and saves again
- Empty string persists

## Solution

Updated the number field sanitization logic in `abstract-wp-mcp-ai-settings-section.php` (lines 276-291) to intelligently handle empty strings:

### New Behavior

```php
case 'number':
    if ( '' === $value ) {
        // Check if field explicitly allows empty strings
        if ( isset( $fields[ $key ]['default'] ) && '' === $fields[ $key ]['default'] ) {
            // Field intentionally uses empty string (e.g., filter fields for auto-detection)
            $sanitized[ $key ] = '';
        }
        // Otherwise skip - don't overwrite existing value with empty string
        break;
    }
    $sanitized[ $key ] = absint( $value );
    break;
```

### Key Improvements

1. **Regular Number Fields**: Empty values are now skipped entirely, preserving existing database values
   - Example: `request_timeout` won't be overwritten when saving other tabs

2. **Filter Fields**: Fields that explicitly use empty string as default (like `filter_resource_max_tokens`) continue to work
   - These fields use empty string to mean "use auto-detection"
   - The fix checks if the field's default is `''` and preserves this functionality

3. **Cross-Tab Safety**: Settings from one tab are never affected when saving another tab
   - Only fields actually submitted in the form are processed
   - Empty/missing fields don't overwrite existing values

## Testing

The fix was validated with test cases covering:

✅ **Regular number fields** - Empty values are skipped, existing values preserved
✅ **Filter fields** - Empty strings are preserved for auto-detection functionality  
✅ **Cross-tab scenarios** - Saving one tab doesn't affect another tab's settings
✅ **Backward compatibility** - All existing functionality continues to work

### Test Scenario Output

```
Existing settings:
    request_timeout => 120
    filter_resource_max_tokens => ''

Sanitized from Providers tab:
    openai_api_key => 'sk-test123'
    enable_openai => true

Merged settings:
    request_timeout => 120                  ✓ Preserved
    filter_resource_max_tokens => ''        ✓ Preserved (auto-detection)
    openai_api_key => 'sk-test123'         ✓ New value
    enable_openai => true                   ✓ New value
```

## Impact

- **Risk Level**: Low - surgical change to a specific sanitization case
- **Backward Compatibility**: 100% - no breaking changes
- **Performance**: No impact - same processing flow
- **User Experience**: Significantly improved - settings no longer mysteriously reset

## Files Changed

- `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php` - Updated number field sanitization (lines 276-291)

## Manual Testing Checklist

To verify the fix works in your environment:

1. **Test Regular Number Field:**
   - [ ] Set `request_timeout` to a non-default value (e.g., 120) on General tab
   - [ ] Save the General tab
   - [ ] Navigate to Providers tab
   - [ ] Save some provider settings (e.g., OpenAI API key)
   - [ ] Return to General tab
   - [ ] Verify `request_timeout` still shows 120 (not 60)

2. **Test Filter Fields:**
   - [ ] Leave a filter field empty (e.g., `filter_resource_max_tokens`)
   - [ ] Save the settings
   - [ ] Verify the field shows "Auto" placeholder (empty string preserved)

3. **Test Cross-Tab:**
   - [ ] Set values in multiple tabs (General, Providers, Advanced)
   - [ ] Save each tab individually
   - [ ] Verify all values persist correctly

## Related Issues

This fix addresses the core issue described in the problem statement where:
- Timeout settings were resetting when saving provider settings
- Users had to re-enter values multiple times
- Settings appeared to save but would revert on page reload

## Next Steps

1. Deploy this fix to your environment
2. Test with the checklist above
3. Monitor for any edge cases or regressions
4. Close the issue if verified working

## Questions?

If you encounter any issues or have questions about this fix:
1. Check the test scenario matches your use case
2. Enable logging (`WP_MCP_AI_DEBUG`) to see what's being saved
3. Verify the field definitions in your sections match the expected patterns
4. Review the console logs during form submission

The fix is designed to be minimal, surgical, and backward-compatible while solving the core issue of cross-tab data loss.
