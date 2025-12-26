# Model Selector Persistent Spinner Fix

## Issue
The model selector in the WordPress admin interface (used in Assistants, Professions, and Teams) had a persistent spinner that remained visible even after model data was loaded successfully. This issue occurred in the frontend JavaScript visibility management.

## Root Cause
The issue was caused by the timing of DOM cleanup operations. Specifically:

1. When `loadModels()` was called, it would:
   - Call `showLoadingState()` to disable the field and add a spinner element after it
   - Make an AJAX request to fetch available models
   - On success/error, call `convertToSelect()` or `convertToTextInput()` to replace the field

2. The problem occurred in `convertToSelect()` and `convertToTextInput()`:
   ```javascript
   // OLD CODE (PROBLEMATIC):
   const $container = $modelField.parent();
   // ... create new field ...
   $modelField.replaceWith($newField);  // Replace field in DOM
   
   // Try to remove spinner - but DOM structure may have changed!
   $container.find('.wp-mcp-ai-model-loading').remove();
   ```

3. The `replaceWith()` operation could cause jQuery to lose proper references to sibling elements, making the subsequent `find()` operation unreliable in some cases.

## Solution
Move the cleanup operations to occur BEFORE the field replacement:

```javascript
// NEW CODE (FIXED):
const $container = $modelField.parent();

// Remove spinner and errors BEFORE replacing field
$container.find('.wp-mcp-ai-model-loading').remove();
$container.find('.wp-mcp-ai-model-error').remove();

// ... create new field ...
$modelField.replaceWith($newField);  // Now safe to replace
```

This ensures:
- The `$container` reference is valid when we try to find and remove the spinner
- The DOM structure is stable during cleanup operations
- No timing issues with element references after replacement

## Changes Made

### File: `assets/js/admin-model-selector.js`

#### 1. `convertToSelect()` function (lines 163-203)
**Before:**
- Created new select element
- Replaced field with `replaceWith()`
- Attempted to remove spinner after replacement

**After:**
- Gets container reference
- Removes spinner and errors BEFORE replacement
- Creates new select element
- Replaces field (cleanup already done)

#### 2. `convertToTextInput()` function (lines 214-247)
**Before:**
- Created new input element
- Replaced field with `replaceWith()`
- Attempted to remove spinner after replacement
- Early return path had no cleanup

**After:**
- Gets container reference
- Removes spinner and errors BEFORE replacement
- Creates new input element
- Replaces field (cleanup already done)
- Early return path now also cleans up spinner and errors

## Testing
To manually test this fix:

1. Go to WordPress Admin → Assistants → Edit any Assistant
2. Change the Provider dropdown (e.g., from "Gemini" to "OpenAI")
3. Observe the model field:
   - ✅ Spinner should appear briefly while loading models
   - ✅ Spinner should disappear once models are loaded
   - ✅ Model dropdown should be populated and functional
   - ✅ No persistent spinner should remain visible

4. Test error scenarios:
   - With invalid API configuration, change provider
   - ✅ Spinner should disappear and show error message
   - ✅ Field should revert to text input
   - ✅ Field should be enabled (not disabled)

5. Test all three contexts:
   - Assistants metabox
   - Professions metabox
   - Teams administration

## Impact
- **Low Risk**: Changes only affect timing of cleanup operations, not functionality
- **No Breaking Changes**: Same operations, just reordered
- **Improved UX**: Users no longer see persistent spinners
- **Consistent Behavior**: All code paths now properly clean up UI elements

## Related Files
The JavaScript is enqueued in three locations:
- `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-defaults.php`
- `includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-defaults.php`
- `includes/teams/class-wp-mcp-ai-team-cpt.php`

## Previous Fixes
This builds on PR #2329 which addressed a similar issue with the initial load scenario. This fix ensures spinner cleanup works correctly in all scenarios, including:
- Initial page load with provider already selected
- Provider change via dropdown
- AJAX success with model data
- AJAX error scenarios
- Early return paths when field is already correct type

## Date
December 22, 2025
