# Fix for Default Assistant Not Saving Issue

## Problem Summary

The **Default Assistant** field and other select fields with integer keys (like post IDs, user IDs, etc.) were not saving properly in the WP oOS settings dashboard.

## Root Cause

The issue was in the `sanitize()` method of `abstract-wp-mcp-ai-settings-section.php`:

### Before the Fix
```php
case 'select':
    $options = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
    if ( in_array( $value, $options, true ) ) {  // ❌ Strict comparison
        $sanitized[ $key ] = $value;
    }
    break;
```

### The Problem
1. HTML forms always send values as **strings** (e.g., `"123"`)
2. Option keys for fields like `default_assistant` are **integers** (post IDs from the database)
3. The strict type comparison `in_array($value, $options, true)` compares `"123" === 123`
4. This returns `false` because the types don't match (string vs integer)
5. The value is rejected as invalid and not included in the sanitized output
6. When settings are saved, the field is omitted or reverts to default

## Solution

### After the Fix
```php
case 'select':
    $options = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
    // Convert value to match the type of option keys for proper comparison.
    // Form submissions send all values as strings, but option keys might be integers.
    $typed_value = $value;
    if ( ! empty( $options ) ) {
        // Check if we have numeric option keys - if so, convert value to int for comparison.
        $first_key = $options[0];
        if ( is_int( $first_key ) && is_numeric( $value ) ) {
            $typed_value = absint( $value );  // ✅ Convert to integer
        }
    }
    // Use non-strict comparison to handle string/int type juggling.
    if ( in_array( $typed_value, $options, false ) ) {  // ✅ Non-strict comparison
        $sanitized[ $key ] = $typed_value;
    }
    break;
```

### How It Works
1. Check if option keys are numeric (integers)
2. If yes, convert the submitted string value to an integer using `absint()`
3. Use non-strict comparison (`false` parameter) to allow type juggling
4. Store the properly typed value in the sanitized output

## Examples

### Example 1: Default Assistant (Integer Keys)
```php
// Form submission
$_POST['wp_mcp_ai_settings']['default_assistant'] = '123'; // string

// Option keys from database
$assistant_options = array(
    0   => 'None',
    123 => 'Test Assistant',  // integer key
    456 => 'Another Assistant',
);

// Before fix: "123" !== 123 → rejected
// After fix:  123 === 123 → accepted ✅
```

### Example 2: Default Provider (String Keys)
```php
// Form submission
$_POST['wp_mcp_ai_settings']['default_provider'] = 'gemini'; // string

// Option keys
$provider_options = array(
    'openai' => 'OpenAI',
    'gemini' => 'Google Gemini',  // string key
);

// Before fix: 'gemini' === 'gemini' → accepted ✅
// After fix:  'gemini' === 'gemini' → accepted ✅
// (No change for string-based options)
```

## Testing

### Automated Tests Added
Added two comprehensive test cases to `tests/test-settings-checkbox-clearing.php`:

1. **`test_select_field_with_integer_keys_saves_correctly()`**
   - Creates a mock assistant post
   - Submits form with string value
   - Verifies value is converted to integer
   - Confirms correct assistant ID is saved

2. **`test_select_field_with_string_keys_saves_correctly()`**
   - Tests string-based select (default_provider)
   - Verifies string values still work correctly
   - Ensures backward compatibility

### Manual Verification
Created and ran standalone test script validating:
- ✅ Integer keys with string value
- ✅ String keys with string value  
- ✅ Invalid value rejection
- ✅ Zero value handling (None option)
- ✅ Non-numeric string rejection

All tests pass successfully.

## Impact

### Fixed Issues
- ✅ Default Assistant field now saves properly
- ✅ Any select field with integer keys (post IDs, user IDs, term IDs, etc.) now works correctly
- ✅ Settings dashboard tabs can be saved without losing values from other tabs

### Backward Compatibility
- ✅ No breaking changes
- ✅ String-based select fields continue to work
- ✅ Old settings page (WP_MCP_AI_Admin_Settings_Base) already handles this correctly
- ✅ Only affects new dashboard settings sections

## Files Changed

1. **includes/admin/sections/abstract-wp-mcp-ai-settings-section.php**
   - Enhanced select field sanitization logic
   - Added type detection and conversion
   - Lines 123-139 modified

2. **tests/test-settings-checkbox-clearing.php**
   - Added 2 new test methods
   - 63 lines added
   - Validates both integer and string select fields

## Related Issues

This fix resolves the core problem described in the issue: "Default Assistant not saving, make all fields can be save properly"

The root cause was type mismatch in select field validation, which affected not just the default_assistant field, but potentially any select field with non-string option keys.
