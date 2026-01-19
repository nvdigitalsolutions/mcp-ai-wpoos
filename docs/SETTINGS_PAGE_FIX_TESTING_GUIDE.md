# Testing Guide for Settings Page Fix

## Overview
This document provides manual testing instructions for the settings page data loss fix.

## Problem Fixed
Previously, when saving settings on one subtab (e.g., Providers → OpenAI), settings from other subtabs or sections could be accidentally deleted if empty values were present in the sanitized data.

## Testing Scenarios

### Test 1: Provider Subtab Isolation
**Objective**: Verify that saving one provider's settings doesn't clear another provider's settings.

1. Navigate to **NV oOS → Providers → OpenAI**
2. Set an OpenAI API key (e.g., `sk-test-openai-123`)
3. Click **Save Changes**
4. Navigate to **NV oOS → Providers → Gemini**
5. Set a Gemini API key (e.g., `AIza-test-gemini-456`)
6. Click **Save Changes**
7. Navigate back to **NV oOS → Providers → OpenAI**
8. Verify the OpenAI API key is still `sk-test-openai-123` ✓

**Expected Result**: Both API keys should be preserved.

### Test 2: Checkbox Preservation Across Subtabs
**Objective**: Verify that checkboxes on one subtab aren't affected when saving another subtab.

1. Navigate to **NV oOS → Providers → OpenAI**
2. Check **Enable OpenAI Provider** checkbox
3. Click **Save Changes**
4. Navigate to **NV oOS → Providers → Anthropic**
5. Check **Enable Anthropic Provider** checkbox
6. Click **Save Changes**
7. Navigate back to **NV oOS → Providers → OpenAI**
8. Verify **Enable OpenAI Provider** is still checked ✓

**Expected Result**: Both providers should remain enabled.

### Test 3: Cross-Tab Protection
**Objective**: Verify that saving General tab settings doesn't clear Provider tab settings.

1. Navigate to **NV oOS → Providers → OpenAI**
2. Set an OpenAI API key (e.g., `sk-test-key-789`)
3. Click **Save Changes**
4. Navigate to **NV oOS → General → Core Settings**
5. Change the **Default Provider** setting
6. Click **Save Changes**
7. Navigate back to **NV oOS → Providers → OpenAI**
8. Verify the OpenAI API key is still `sk-test-key-789` ✓

**Expected Result**: The OpenAI API key should be preserved.

### Test 4: Priority Order Doesn't Affect Provider Settings
**Objective**: Verify that reordering providers doesn't clear provider configurations.

1. Set up multiple providers with API keys:
   - OpenAI: `sk-openai-test`
   - Gemini: `AIza-gemini-test`
   - Anthropic: `sk-ant-test`
2. Navigate to **NV oOS → Providers → Priority Order**
3. Reorder the providers (drag and drop)
4. Click **Save Changes**
5. Check each provider subtab (OpenAI, Gemini, Anthropic)
6. Verify all API keys are still present ✓

**Expected Result**: All provider settings should be preserved.

### Test 5: Intentional Field Clearing (Edge Case)
**Objective**: Verify that users can still intentionally clear optional fields.

1. Navigate to **NV oOS → Providers → OpenAI**
2. Set both **OpenAI API Key** and **Organization ID**
3. Click **Save Changes**
4. Return to the same page and clear the **Organization ID** field (leave it empty)
5. Click **Save Changes**
6. Verify the **Organization ID** is cleared BUT **API Key** is preserved ✓

**Note**: Empty sensitive keys (like API keys) are protected and won't be saved as empty. To remove a key, you need to delete it from the database directly or use a different UI pattern.

## Protection Mechanisms

### 1. Sensitive Keys Filter
The following keys are protected and will NEVER be saved with empty values:
- All provider API keys (OpenAI, Gemini, Anthropic, HuggingFace, etc.)
- All provider endpoints (Ollama, LM Studio, HuggingFace)
- OAuth credentials (Auth0, Google OAuth)
- External service keys (Cloudways, Brave Search, Mubert, Google Maps)

### 2. General Empty Value Protection
Any empty string that would overwrite an existing non-empty value is filtered out UNLESS:
- The setting belongs to the active tab being saved

### 3. Checkbox Handling
Checkboxes are handled specially:
- When checked: saved as `true`
- When unchecked: saved as `false`
- When on an inactive subtab: not processed (preserves existing value)

## Logging for Debugging

To enable diagnostic logging:
1. Navigate to **NV oOS → General → Log Management**
2. Check **Enable Logging**
3. Click **Save Changes**

Logs will show:
- Which keys are being filtered out
- Why they're being filtered (empty sensitive key, cross-tab protection, etc.)
- The active tab during save operations

View logs in your PHP error log or use a plugin like Query Monitor.

## Common Issues and Solutions

### Issue: Settings appear to save but revert on page reload
**Cause**: Empty value protection is working correctly - it's preventing an empty value from overwriting your existing setting.
**Solution**: This is expected behavior. Make sure you're on the correct tab when saving settings.

### Issue: Can't clear an API key
**Cause**: Sensitive keys are protected from being saved as empty.
**Solution**: This is intentional. To remove an API key, either:
- Use a different provider
- Delete the option from the database directly
- Or leave the key field with a placeholder value

### Issue: Checkbox always unchecks on other subtabs
**Cause**: This was the original bug, now fixed.
**Solution**: Update to the latest version with this fix.

## Technical Details

### Files Modified
- `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
  - Expanded sensitive keys list (10 → 32 keys)
  - Added general empty value protection loop
  - Added `is_setting_in_tab()` helper method

### Test Files Added
- `tests/test-empty-key-protection.php`
  - 6 comprehensive tests for the new protection mechanisms

### Existing Tests Validated
- `tests/test-settings-checkbox-clearing.php` (10 tests)
- `tests/test-providers-subtab-checkbox-issue.php` (7 tests)
- `tests/test-section-subtab-sanitization.php`
- `tests/test-subtab-cross-contamination.php`

## Rollback Instructions

If you need to rollback this fix:
```bash
git revert <commit-hash>
```

However, note that rolling back will reintroduce the data loss bug.

## Support

If you encounter issues with this fix, please:
1. Enable logging (see above)
2. Reproduce the issue
3. Capture the relevant log entries
4. Report the issue with:
   - Which tab/subtab you were saving
   - What settings were affected
   - Log entries showing the protection mechanisms in action
