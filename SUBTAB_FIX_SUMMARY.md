# Subtab Settings Save Fix - Summary

## Problem
When saving settings in one subtab (e.g., OpenAI provider settings), settings in other subtabs (e.g., Gemini, Anthropic) were being cleared.

## Root Cause Analysis
The issue could occur if:
1. POST data somehow included fields from inactive subtabs (browser autofill, JavaScript, etc.)
2. Those fields were processed and saved with empty values
3. The merge logic couldn't distinguish between "not submitted" and "submitted as empty"

## Solution Implemented

### 1. Defensive Input Filtering
**File:** `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`
**Method:** `sanitize_fields()`

Added pre-processing filter that:
- Creates a `$filtered_input` array containing ONLY fields defined for the current subtab
- Excludes any fields from POST data that don't belong to the current subtab
- All subsequent processing uses `$filtered_input` instead of raw `$input`

**Example:**
```php
// Before: Could process gemini_api_key if it was in POST
if ( ! isset( $input[ $key ] ) ) {
    continue;
}

// After: Only processes if field is in $fields AND in POST
$filtered_input = array();
foreach ( $fields as $key => $field ) {
    if ( isset( $input[ $key ] ) ) {
        $filtered_input[ $key ] = $input[ $key ];
    }
}
// ... later ...
if ( ! isset( $filtered_input[ $key ] ) ) {
    continue;
}
```

### 2. Enhanced Diagnostic Logging
**Files:** 
- `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
- `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`

Added detailed logging (enabled when WP_DEBUG is true and logging is enabled in settings):
- Which subtab is active vs. which subtab is being submitted
- How many fields are being processed
- Which provider keys exist before and after sanitization
- Whether a section considers the submission a valid form submit

## Testing Instructions

### Manual Testing
1. **Setup:**
   - Go to Settings → NV oOS → Providers → OpenAI
   - Enter an OpenAI API key
   - Click "Save Changes"
   
2. **Test Cross-Subtab Preservation:**
   - Go to Settings → NV oOS → Providers → Gemini
   - Enter a Gemini API key
   - Click "Save Changes"
   
3. **Verify:**
   - Go back to Providers → OpenAI
   - **OpenAI API key should still be present**
   - Go back to Providers → Gemini
   - **Gemini API key should still be present**

4. **Test Multiple Providers:**
   - Repeat for Anthropic, Ollama, etc.
   - Each provider's settings should be preserved when saving other providers

### Testing with Logging (Recommended for Diagnosis)
If the issue persists, enable logging to see exactly what's happening:

1. **Enable Logging:**
   - Go to Settings → NV oOS → General → Logging
   - Check "Enable Logging"
   - Click "Save Changes"

2. **Reproduce the Issue:**
   - Make a change in any provider subtab
   - Save
   - Check if other subtabs are cleared

3. **Check Logs:**
   - Access your WordPress debug log (usually `wp-content/debug.log`)
   - Or check your server's error log
   - Look for entries starting with `[NV oOS`

4. **Log Entries to Look For:**
   ```
   [NV oOS Settings] Save attempt - Tab: providers, Subtab: openai, ...
   [NV oOS Subtab Sanitize] Section: providers, Active: openai, Submitted: openai, ...
   [NV oOS Settings] Provider keys - Existing: {...}, Sanitized: {...}
   ```

5. **Share Logs:**
   - If issue persists, share the log entries
   - They will show exactly which fields are being processed and saved

## Expected Behavior After Fix

### When Saving OpenAI Subtab:
- **Posted:** 23 OpenAI-specific fields
- **Sanitized:** ONLY those 23 fields
- **Merged:** Those 23 fields + ALL existing fields from other providers
- **Result:** OpenAI settings updated, Gemini/Anthropic/etc. preserved

### When Saving Gemini Subtab:
- **Posted:** Gemini-specific fields
- **Sanitized:** ONLY those fields
- **Merged:** Those fields + ALL existing fields including OpenAI
- **Result:** Gemini settings updated, OpenAI/Anthropic/etc. preserved

## Security Benefits
This fix also provides:
- Protection against POST data manipulation
- Prevention of unauthorized field injection
- Whitelist-based field validation
- Clear separation of subtab concerns

## Files Changed
1. `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
   - Added subtab parameter to logging

2. `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`
   - Added defensive input filtering
   - Added diagnostic logging for subtab operations

## Rollback Instructions
If this fix causes any issues, you can revert by:
```bash
git revert HEAD
```

## Additional Notes
- The fix is defensive and should not break existing functionality
- It only affects how POST data is filtered before processing
- Existing password field protection remains in place
- Checkbox handling for inactive subtabs remains unchanged
- The merge logic (array_merge) is unchanged

## Support
If the issue persists after this fix:
1. Enable logging as described above
2. Reproduce the issue
3. Share the log entries showing:
   - Which fields were posted
   - Which fields were sanitized
   - Which fields existed before/after merge
4. This will help identify the exact point where data is being lost
