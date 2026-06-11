# Model Dropdown Fix - December 30, 2025

## Issue Summary

The model dropdown in assistant/profession/team post editors only displayed the saved model instead of all available models for the selected provider. Users had to change providers (converting the field to text input) to manually type a different model name.

## Problem Details

### Symptoms
- Model dropdown showed only 2 options:
  - `— Select Model —` (empty placeholder)
  - Previously saved model (e.g., `gpt-4.1`)
- Full list of available models (20+ for OpenAI, 9+ for Gemini, etc.) was not displayed
- Changing models required:
  1. Change provider to a different one
  2. Field converts to text input
  3. Type model name manually
  4. Change provider back

### Root Cause

The `WP_MCP_AI_Model_Service` class was not being loaded when metaboxes rendered. The code checked if the class exists but didn't require the file:

```php
// Before fix - Model Service not loaded
$models = array();
if ( class_exists( 'WP_MCP_AI_Model_Service' ) ) { // Returns false
    $model_service = new WP_MCP_AI_Model_Service();
    $models = $model_service->get_models_for_provider( $provider );
}
// $models remains empty []
```

Since `$models` was empty, the metabox PHP only rendered the saved model as a fallback:

```php
<?php if ( $model && ( empty( $models ) || ! isset( $models[ $model ] ) ) ) : ?>
    <option value="<?php echo esc_attr( $model ); ?>" selected="selected">
        <?php echo esc_html( $model ); ?>
    </option>
<?php endif; ?>
```

## Solution

Added `require_once` for the Model Service class in three metabox files before attempting to use it:

```php
// After fix - Model Service explicitly loaded
$models = array();
if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
    require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
}
if ( class_exists( 'WP_MCP_AI_Model_Service' ) ) {
    $model_service = new WP_MCP_AI_Model_Service();
    $models = $model_service->get_models_for_provider( $provider );
}
// $models now contains full list of available models
```

## Files Modified

1. **`includes/assistants/metaboxes/class-wp-mcp-ai-metabox-defaults.php`**
   - Lines 131-134: Added require_once before class check

2. **`includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-defaults.php`**
   - Lines 115-118: Added require_once before class check

3. **`includes/teams/class-wp-mcp-ai-team-cpt.php`**
   - Lines 376-382: Added require_once before class check

## Expected Behavior After Fix

### On Page Load (Editing Assistant/Profession/Team)

**Provider: OpenAI**
```html
<select id="wp-mcp-ai-model" name="wp_mcp_ai_model" class="widefat">
    <option value="">— Select Model —</option>
    <option value="gpt-5.2">GPT-5.2 (Flagship)</option>
    <option value="gpt-5.2-pro">GPT-5.2 Pro (Advanced Reasoning)</option>
    <option value="gpt-5.2-instant">GPT-5.2 Instant (High Throughput)</option>
    <option value="gpt-5.1">GPT-5.1</option>
    <option value="gpt-5">GPT-5</option>
    <option value="gpt-4.1" selected="selected">GPT-4.1</option> <!-- Saved model -->
    <option value="gpt-4.1-mini">GPT-4.1 Mini</option>
    <option value="gpt-4o">GPT-4o</option>
    <option value="gpt-4o-mini">GPT-4o Mini</option>
    <!-- ... 20+ total models -->
</select>
```

**Provider: Gemini**
```html
<select id="wp-mcp-ai-model" name="wp_mcp_ai_model" class="widefat">
    <option value="">— Select Model —</option>
    <option value="gemini-2.5-flash" selected="selected">Gemini 2.5 Flash</option> <!-- Saved model -->
    <option value="gemini-2.5-pro">Gemini 2.5 Pro</option>
    <option value="gemini-2.5-pro-vision">Gemini 2.5 Pro Vision</option>
    <option value="gemini-2.0-flash">Gemini 2.0 Flash (Latest)</option>
    <!-- ... 9+ total models -->
</select>
```

**Custom Model Scenario**
```html
<select id="wp-mcp-ai-model" name="wp_mcp_ai_model" class="widefat">
    <option value="">— Select Model —</option>
    <option value="gpt-5.2">GPT-5.2 (Flagship)</option>
    <!-- ... standard models ... -->
    <option value="custom-fine-tuned-model" selected="selected">custom-fine-tuned-model (custom)</option>
</select>
```

### JavaScript Behavior

The existing JavaScript (`assets/js/admin-model-selector.js`) correctly handles the populated dropdown:

1. **On Page Load**: `needsModelsLoad()` checks if models are already loaded
2. **Detection**: Counts non-empty options in the select dropdown
3. **Result**: Finds 20+ options, returns `false` (models already loaded)
4. **Action**: Skips AJAX call, no loading spinner shown
5. **Performance**: Faster page load, no unnecessary network requests

### Provider Change Behavior

When user changes provider dropdown:
1. JavaScript detects provider change
2. Makes AJAX call to fetch models for new provider
3. Replaces select dropdown with new model options
4. Preserves existing model selection if available in new provider

## Technical Notes

### Why This Wasn't Caught Earlier

1. **Class Autoloading**: The plugin doesn't use PSR-4 autoloading for all classes
2. **AJAX Context**: The Model Service was being loaded in AJAX handlers only
3. **Silent Failure**: Empty `$models` array didn't cause errors, just showed fallback behavior

### Why It Works Now

The Model Service is now guaranteed to be available in three contexts:
1. **Initial Page Load**: Metaboxes explicitly load it
2. **AJAX Requests**: Admin AJAX handler loads it
3. **Provider Changes**: JavaScript triggers AJAX which loads it

### Backward Compatibility

- ✅ Custom models still work (shown with "(custom)" label)
- ✅ Empty provider selections handled gracefully
- ✅ Missing API keys result in empty model list (expected behavior)
- ✅ JavaScript optimization preserved (no unnecessary AJAX calls)

## Testing Verification

### Manual Testing Checklist

- [ ] Edit an Assistant post
  - [ ] See all models for default provider
  - [ ] Previously saved model is selected
  - [ ] Can change to different model without changing provider
- [ ] Edit a Profession post
  - [ ] See all models for default provider
  - [ ] Model dropdown works correctly
- [ ] Edit a Team post
  - [ ] See all models when provider is set
  - [ ] Model dropdown works correctly
- [ ] Change provider dropdown
  - [ ] Field updates with new provider's models
  - [ ] No persistent loading spinner
- [ ] Custom model scenario
  - [ ] Custom model shows with "(custom)" label
  - [ ] Can still select standard models

### Code Verification

```bash
# Verify fix is in place
grep -n "require_once WP_MCP_AI_PATH.*model-service" \
  includes/assistants/metaboxes/class-wp-mcp-ai-metabox-defaults.php \
  includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-defaults.php \
  includes/teams/class-wp-mcp-ai-team-cpt.php
```

Expected output shows the require_once statements in all three files.

## Related Issues

- Issue reported: Model dropdown only shows saved model
- Related to: JavaScript model selector optimization (issue #2326)
- Complements: Provider-specific model filtering

## Commit

- **Commit**: 279513e
- **Date**: December 30, 2025
- **Branch**: copilot/fix-model-selection-issue
- **Files Changed**: 3
- **Lines Added**: 14
- **Lines Removed**: 3

## Author

GitHub Copilot Workspace
