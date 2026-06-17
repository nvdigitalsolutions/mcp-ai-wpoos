# Fix: Configuration Preset Active State Update

## Issue
The configuration presets could be saved, but the active preset indicator did not update to reflect what was selected when clicking "Apply" on a preset card.

## Root Cause
When a preset was applied via AJAX:
1. The AJAX handler successfully applied the preset and updated all settings on the backend
2. The page was scheduled to reload after a 1-second delay
3. During that delay window, the hidden `orchestration_preset` field still contained the old value
4. If the user clicked "Save Changes" before the reload completed, the old preset value would be submitted and saved, overwriting the newly applied preset

## Solution
Updated `assets/js/settings-dashboard.js` to immediately update the DOM when a preset is successfully applied:

```javascript
success: function(response) {
    if (response.success) {
        // Update the hidden field value immediately
        $('#orchestration_preset').val(presetId);
        
        // Update the active preset indicator text
        const presetName = $('.preset-card[data-preset="' + presetId + '"] h4').text();
        $('.current-preset-name').text(presetName);
        
        // Then proceed with reload...
    }
}
```

This ensures:
1. The hidden field has the correct value immediately
2. If user clicks "Save Changes" before reload, correct preset is saved
3. Visual feedback is instant - user sees which preset is active
4. After page reload, everything is consistent

## Files Changed
- `assets/js/settings-dashboard.js` - Added 8 lines to update DOM immediately
- `bin/test-active-preset-update.php` - New test script to validate the fix

## Testing
All tests pass:
- ✓ Existing preset persistence test (bin/test-preset-fix.php)
- ✓ New active preset update test (bin/test-active-preset-update.php)
- ✓ JavaScript linting (no new errors)

## Verification Steps
1. Navigate to Settings → Orchestration Layer
2. Note the currently active preset
3. Click "Apply" on a different preset
4. Immediately observe:
   - "Currently Active" indicator updates to show new preset name
   - Hidden field value is updated (can verify in browser devtools)
5. Click "Save Changes" before page reload completes
6. After save/reload, verify the new preset is still active

## Impact
- Minimal change (8 lines of JavaScript)
- No breaking changes
- Improves user experience with instant visual feedback
- Prevents data loss if user clicks "Save Changes" during reload delay
