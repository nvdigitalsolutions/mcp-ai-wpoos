# Profession Default Tools Pre-Selection Fix

## Issue Description

**Problem:** When viewing a profession edit page after performing a reseed/refresh from Settings → Advanced → Data Management, the default tools checkboxes were not being pre-selected, even though the JSON files contained proper default_tools definitions.

**Location:** Professions custom post type edit page (`/wp-admin/post.php?post=XXX&action=edit`)

**Symptom:** The "Default Tools" section with `id="profession-default-tools-list"` showed all checkboxes unchecked despite the profession having default_tools defined in its metadata.

## Root Cause

The issue was caused by inconsistent array handling between the repository save method and the metabox render method:

### Problem 1: Non-Sequential Array Keys

```php
// Repository (BEFORE FIX):
update_post_meta( $post_id, META_DEFAULT_TOOLS, array_map( 'sanitize_key', $data['default_tools'] ) );

// Metabox save (ALREADY CORRECT):
update_post_meta( $post_id, META_DEFAULT_TOOLS, array_values( $default_tools ) );
```

When `array_map()` is used without `array_values()`, it preserves the original array keys. If the input array had non-sequential keys (e.g., `[0 => 'tool1', 2 => 'tool3']`), the saved array would maintain those keys. This could cause subtle issues with array comparisons.

### Problem 2: No Empty Value Filtering

Empty strings or null values in the default_tools array weren't being filtered out during save, which could cause `in_array()` checks to fail or behave unexpectedly.

### Problem 3: Inconsistent Sanitization

The tool slugs weren't being sanitized consistently:
- Repository: Applied `sanitize_key()` during save
- Metabox render: No sanitization of retrieved values
- Metabox comparison: No sanitization of tool slugs from registry

This meant that if there were any whitespace or case differences, the `in_array()` check would fail.

## Solution

### 1. Repository Save Method

**File:** `includes/repositories/class-wp-mcp-ai-profession-repository.php`

```php
// AFTER FIX:
if ( isset( $data['default_tools'] ) && is_array( $data['default_tools'] ) ) {
    $sanitized_tools = array_map( 'sanitize_key', $data['default_tools'] );
    $sanitized_tools = array_filter( $sanitized_tools ); // Remove empty values
    update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, array_values( $sanitized_tools ) );
}
```

**Changes:**
- Added `array_filter()` to remove empty values
- Added `array_values()` to ensure sequential keys
- Now matches metabox save behavior

### 2. Metabox Render Method

**File:** `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-expertise.php`

```php
// AFTER FIX:
// Ensure default_tools is always an array and filter out empty values
if ( ! is_array( $default_tools ) ) {
    $default_tools = array();
}
$default_tools = array_filter( array_map( 'sanitize_key', $default_tools ) );
```

**Changes:**
- Added sanitization of retrieved default_tools array
- Added filtering of empty values
- Ensures clean data for comparisons

### 3. Tool Slug Sanitization

```php
// AFTER FIX:
$tool_slug = method_exists( $tool, 'get_slug' ) ? sanitize_key( trim( $tool->get_slug() ) ) : '';
```

**Changes:**
- Added `sanitize_key()` to tool slugs during comparison
- Added `trim()` to remove any whitespace
- Ensures consistent comparison with saved tools

## Testing

### Automated Tests

Added three new test methods to `tests/test-profession-reseeding.php`:

1. **`test_default_tools_persistence()`**
   - Verifies tools are saved and retrieved correctly
   - Checks array structure (sequential keys)
   - Validates tool presence

2. **`test_default_tools_update()`**
   - Tests updating existing profession tools
   - Verifies old tools are replaced
   - Confirms new tools are present

3. **`test_default_tools_filter_empty()`**
   - Ensures empty values are filtered out
   - Tests with mixed valid/empty values
   - Validates final array integrity

Run tests with:
```bash
composer run test -- tests/test-profession-reseeding.php
```

### Manual Testing Script

A CLI test script is provided at `bin/test-profession-tools-display.php`:

```bash
php bin/test-profession-tools-display.php
```

This script:
- Creates a test profession with default_tools
- Verifies data persistence
- Tests update operations
- Loads and validates JSON professions
- Cleans up after itself

### UI Verification Steps

1. **Initial Setup:**
   ```
   Navigate to: Settings → WP oOS → Advanced → Data Management
   ```

2. **Perform Reseed:**
   ```
   Click: "Update Existing (Preserve Custom Changes)"
   Wait for success message
   ```

3. **Verify Pre-Selection:**
   ```
   Navigate to: Professions → Edit any profession
   Scroll to: "Default Tools" section
   Expected: Checkboxes for profession's default tools are CHECKED
   ```

4. **Test Data Persistence:**
   ```
   On profession edit page:
   - Click "Update" button (without changing anything)
   - Reload the page
   - Verify checkboxes remain checked
   ```

5. **Test Reset Button:**
   ```
   - Uncheck all tools
   - Click "Reset to Initial" button
   - Verify tools are re-checked to their saved state
   ```

## Technical Details

### Array Handling

**Before Fix:**
- Repository used `array_map()` → Non-sequential keys possible
- Metabox used `array_values()` → Sequential keys
- Mismatch could cause issues

**After Fix:**
- Both use `array_values()` → Sequential keys guaranteed
- Both use `array_filter()` → No empty values
- Consistent handling throughout

### Why This Matters

PHP's `in_array()` with strict type checking (`true` as third parameter) requires exact matches:

```php
// This would fail:
$saved_tools = [0 => 'web_search', 2 => 'save_post'];  // Non-sequential
$tool_slug = 'save_post';
in_array($tool_slug, $saved_tools, true);  // FALSE (different key structure)

// This works:
$saved_tools = [0 => 'web_search', 1 => 'save_post'];  // Sequential
$tool_slug = 'save_post';
in_array($tool_slug, $saved_tools, true);  // TRUE
```

### Data Flow

```
JSON Files
    ↓
Loader (validates & sanitizes)
    ↓
AJAX Handler (processes reseed)
    ↓
Repository.save() [FIX APPLIED HERE]
    ↓
Database (wp_postmeta)
    ↓
get_post_meta()
    ↓
Metabox.render() [FIX APPLIED HERE]
    ↓
UI (checkboxes)
```

## Files Changed

1. **`includes/repositories/class-wp-mcp-ai-profession-repository.php`**
   - Lines 307-309: Added array_filter() and array_values()

2. **`includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-expertise.php`**
   - Lines 55-56: Added sanitization/filtering of retrieved tools
   - Line 130: Added sanitization of tool slugs

3. **`tests/test-profession-reseeding.php`**
   - Added 123 lines: Three new test methods

4. **`bin/test-profession-tools-display.php`**
   - New file: CLI testing script (189 lines)

## Prevention

To prevent similar issues in the future:

1. **Always use `array_values()`** when saving arrays to postmeta that will be compared with `in_array()`
2. **Always use `array_filter()`** to remove empty values before saving
3. **Sanitize consistently** at both save and retrieve points
4. **Test array operations** with non-sequential keys to catch edge cases

## Related Issues

This fix also improves:
- Array consistency across the codebase
- Data integrity during updates
- Predictable behavior for UI elements
- Easier debugging of array-related issues

## References

- WordPress `update_post_meta()`: https://developer.wordpress.org/reference/functions/update_post_meta/
- PHP `array_values()`: https://www.php.net/manual/en/function.array-values.php
- PHP `array_filter()`: https://www.php.net/manual/en/function.array-filter.php
- PHP `in_array()`: https://www.php.net/manual/en/function.in-array.php
