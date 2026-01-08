# Ollama Model Selection Fix

**Date:** 2026-01-08  
**Issue:** Ollama model selection AJAX handler returning strings instead of objects  
**Status:** ✅ Fixed

## Problem Description

The Ollama provider had an issue where the AJAX handler for fetching models (`handle_fetch_ollama_models()`) was returning a simple array of model name strings, but the JavaScript code expected an array of model objects with `name`, `size`, and `family` properties.

### Symptoms
- JavaScript code tried to access `model.name` on a string value
- Model display would break or not show proper information
- Model selection might not work correctly

## Root Cause

In `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (lines 222-227), the handler was extracting only the model name:

```php
foreach ( $data['models'] as $model ) {
    if ( isset( $model['name'] ) ) {
        $models[] = $model['name'];  // ❌ Just the string
    }
}
```

But the JavaScript in `assets/js/admin-settings.js` (lines 149-158) expected objects:

```javascript
response.data.models.forEach(function (model) {
    const sizeInfo = model.size ? ' (' + formatBytes(model.size) + ')' : '';
    html += '<a href="#" class="wp-mcp-ai-select-ollama-model" data-model="' + model.name + '">';
    html += model.name + sizeInfo;
    html += '</a>';
    if (model.family) {
        html += ' - ' + model.family;
    }
});
```

The `WP_MCP_AI_Ollama_Client::list_models()` method in `includes/class-wp-mcp-ai-ollama-client.php` (lines 90-102) already returns properly structured objects, but the AJAX handler wasn't using this structure.

## Solution

Modified the AJAX handler to return model objects with all necessary properties:

```php
foreach ( $data['models'] as $model ) {
    if ( isset( $model['name'] ) ) {
        $models[] = array(
            'name'   => $model['name'],
            'size'   => isset( $model['size'] ) ? $model['size'] : 0,
            'family' => isset( $model['details']['family'] ) ? $model['details']['family'] : '',
        );
    }
}
```

This matches the structure expected by the JavaScript and aligns with the Ollama client's `list_models()` method.

## Files Changed

1. **includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php**
   - Modified `handle_fetch_ollama_models()` to return model objects (lines 222-231)

2. **tests/test-ollama-ajax-handlers.php** (new file)
   - Added comprehensive tests to verify model objects are returned correctly
   - Tests verify structure includes name, size, and family properties
   - Tests ensure models are not just strings

## Testing

Created new test file `tests/test-ollama-ajax-handlers.php` with two test methods:

1. `test_fetch_ollama_models_returns_model_objects()` - Verifies the correct structure is returned
2. `test_fetch_ollama_models_not_just_strings()` - Ensures the bug is fixed (models are not strings)

### Test Coverage
- Mocks Ollama API response with typical model data
- Verifies response structure (success, data, models array)
- Checks each model has name, size, and family properties
- Validates specific model data matches expected values
- Ensures models without family details get empty string

## Backward Compatibility

This change **improves** the data structure but may affect any custom code that was:
- Expecting a simple string array from the AJAX handler
- Directly consuming the raw AJAX response

However, this is unlikely because:
1. The JavaScript in the core plugin already expected objects
2. This AJAX endpoint is primarily used by the admin settings page
3. The fix aligns with what the Ollama client already returns

## Related Code

- **Ollama Client:** `includes/class-wp-mcp-ai-ollama-client.php`
  - `list_models()` method (lines 67-103) - Returns properly structured model objects
  
- **JavaScript:** `assets/js/admin-settings.js`
  - Ollama model fetching (lines 126-175)
  - Model selection handler (lines 177-184)

- **Settings:** `includes/admin/class-wp-mcp-ai-admin-settings.php`
  - Model field rendering (lines 3424-3432)
  - Model sanitization (line 2135)

## Benefits

1. **Consistent data structure** - Aligns AJAX handler with Ollama client
2. **Better UX** - Model size and family information displayed correctly
3. **Maintainable** - Single source of truth for model data structure
4. **Tested** - Comprehensive tests prevent regression

## Verification

To verify the fix works:

1. Navigate to **Settings → NV oOS → Providers → Ollama**
2. Enter an Ollama endpoint URL (e.g., `http://localhost:11434`)
3. Click **"Fetch Models"** button
4. Verify models are displayed with:
   - Model names (e.g., `llama3:latest`)
   - File sizes (e.g., `(4.3 GB)`)
   - Family information (e.g., `- llama`)
5. Click on a model name link
6. Verify the model name is correctly populated in the input field

## Notes

- The fix handles cases where models don't have `size` or `family` data (defaults to 0 and empty string)
- Maintains proper sanitization of all data
- No changes needed to the JavaScript code as it was already written correctly
- The issue was purely in the PHP AJAX handler

## Security Considerations

- All data continues to be properly sanitized
- Nonce verification remains in place
- Capability checks remain unchanged
- No new security risks introduced
