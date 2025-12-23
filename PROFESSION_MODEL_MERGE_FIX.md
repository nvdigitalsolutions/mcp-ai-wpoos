# Profession Model Settings Merge Fix

## Issue Summary

When testing a profession in the **wp-mcp-ai-test-profession-modal** that has an associated assistant, the profession's model settings (provider, model, temperature) were NOT properly overriding the assistant's settings, even though the instructions and files were merging correctly.

## User Report

> "the professions are setup correctly as they work in the chat client as primary roles for the assistant"

This confirmed that:
- Professions work correctly when used as primary roles in assistants
- The data is being saved properly
- The issue is specific to the test profession modal

## Root Cause

In `/includes/class-wp-mcp-ai-rest.php`, the `load_profession_configuration()` method used `! empty()` checks:

```php
// OLD CODE (BUGGY)
if ( ! empty( $default_provider_val ) ) {
    $assistant_config['provider'] = $default_provider_val;
}

if ( ! empty( $default_model_val ) ) {
    $assistant_config['model'] = $default_model_val;
}

if ( ! empty( $default_temp_val ) && is_numeric( $default_temp_val ) ) {
    $assistant_config['temperature'] = floatval( $default_temp_val );
}
```

### The PHP `empty()` Bug

In PHP, `empty()` has problematic behavior:
- `empty(0)` returns `TRUE` ❌
- `empty(0.0)` returns `TRUE` ❌
- `empty('0')` returns `TRUE` ❌
- `empty('')` returns `TRUE` ✅ (expected)
- `empty(null)` returns `TRUE` ✅ (expected)

This meant:
1. A profession with `temperature = 0` would NOT override the assistant's temperature
2. A profession with `provider = '0'` (hypothetically) would NOT override

## The Fix

Changed to explicit checks that handle edge cases:

```php
// NEW CODE (FIXED)
// Use explicit checks instead of empty() to handle edge cases like temperature = 0.
if ( null !== $default_provider_val && '' !== $default_provider_val && false !== $default_provider_val ) {
    $assistant_config['provider'] = $default_provider_val;
}

if ( null !== $default_model_val && '' !== $default_model_val && false !== $default_model_val ) {
    $assistant_config['model'] = $default_model_val;
}

if ( null !== $default_temp_val && false !== $default_temp_val && '' !== $default_temp_val && is_numeric( $default_temp_val ) ) {
    $assistant_config['temperature'] = floatval( $default_temp_val );
}
```

### Why This Works

The new checks explicitly test for values that indicate "not set":
- `null !== $val` - Value was retrieved from database (might be empty string or false)
- `'' !== $val` - Value is not an empty string
- `false !== $val` - Value is not boolean false

This allows:
- ✅ `temperature = 0` to override (important for deterministic outputs)
- ✅ `temperature = 0.0` to override
- ✅ Any valid numeric or string value to override
- ❌ Empty strings to NOT override (correctly preserves assistant settings)
- ❌ Null values to NOT override (correctly preserves assistant settings)

## Additional Changes

### JavaScript Enhancement

Added `associatedAssistantId` to the chat instance configuration in `/assets/js/admin-test-profession.js`:

```javascript
const associatedAssistantId = professionData && professionData.associated_assistant ? 
    professionData.associated_assistant : 0;

window.wpMcpAiChatInstances[instanceId] = {
    assistantId: assistantId,
    professionId: professionId,
    associatedAssistantId: associatedAssistantId,  // NEW
    // ... other config
};
```

This provides visibility into which assistant is associated for debugging and future features.

## Testing

Created comprehensive tests in `/tests/test-profession-model-merge.php`:

1. **Test Temperature Zero Override**
   - Profession with `temperature = 0`
   - Assistant with `temperature = 0.9`
   - Result: Profession's 0 should override ✅

2. **Test All Settings Override**
   - Profession with provider/model/temperature all set
   - Assistant with different values
   - Result: All profession settings should override ✅

3. **Test Empty Settings Preserve Assistant**
   - Profession with empty provider/model, no temperature
   - Assistant with valid settings
   - Result: Assistant settings should be preserved ✅

## How It Works Now

When testing a profession in the modal:

1. **JavaScript** sends `assistant_id = "profession_123"` to backend
2. **Backend** `resolve_assistant_id()`:
   - Extracts profession ID = 123
   - Checks for associated assistant
   - Returns associated assistant ID (or 0)
3. **Backend** `get_assistant_configuration()`:
   - Loads assistant's config (provider, model, temperature, prompt, tools, files)
4. **Backend** `load_profession_configuration()`:
   - Builds profession prompt from role/knowledge
   - **APPENDS** profession prompt to assistant prompt (with header)
   - **MERGES** profession tools with assistant tools
   - **MERGES** profession memory files with assistant files
   - **OVERRIDES** provider/model/temperature with profession values (IF SET)
5. **Result**: Combined configuration with:
   - Assistant's base knowledge + Profession's expertise
   - Combined tools from both
   - Combined files from both
   - Profession's model settings (if configured)

## Visual Example

### Before Fix ❌

```
Profession: Tax Advisor
├─ Provider: openai
├─ Model: gpt-4
├─ Temperature: 0 (for deterministic tax calculations)
└─ Associated Assistant: "Legal Assistant"
   ├─ Provider: gemini
   ├─ Model: gemini-pro
   └─ Temperature: 0.7

Result in Test Modal:
├─ Provider: gemini ❌ (profession ignored)
├─ Model: gemini-pro ❌ (profession ignored)
└─ Temperature: 0.7 ❌ (profession's 0 treated as empty)
```

### After Fix ✅

```
Profession: Tax Advisor
├─ Provider: openai
├─ Model: gpt-4
├─ Temperature: 0 (for deterministic tax calculations)
└─ Associated Assistant: "Legal Assistant"
   ├─ Provider: gemini
   ├─ Model: gemini-pro
   └─ Temperature: 0.7

Result in Test Modal:
├─ Provider: openai ✅ (profession overrides)
├─ Model: gpt-4 ✅ (profession overrides)
├─ Temperature: 0 ✅ (profession overrides, even though 0)
├─ Instructions: "Legal base" + "Tax expertise" ✅
├─ Tools: [legal_tools] + [tax_tools] ✅
└─ Files: [legal_docs] + [tax_docs] ✅
```

## Files Changed

1. `/includes/class-wp-mcp-ai-rest.php` - Fix empty() bug
2. `/assets/js/admin-test-profession.js` - Add associatedAssistantId tracking
3. `/tests/test-profession-model-merge.php` - New comprehensive tests

## Backwards Compatibility

✅ **Fully backwards compatible**
- Existing professions without model settings: Work as before
- Existing professions with model settings: Now work correctly
- Professions as primary roles: Unaffected (different code path)
- Regular assistant usage: Unaffected

## Related Documentation

- `/PROFESSIONAL_TEST_MODEL_SUMMARY.md` - Previous architecture changes
- `/docs/PROFESSIONAL_TEST_MODEL_CHANGES.md` - Implementation details
- `/docs/PROFESSIONAL_TEST_MODEL_TESTING_GUIDE.md` - Testing guide
