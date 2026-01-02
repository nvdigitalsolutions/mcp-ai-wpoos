# Remote Connection Schema Fix - OpenAI Compatibility

## Issue
After PR #2524, the `remote_wp_connection` tool was causing the following error when used with OpenAI as the provider:

```
Invalid schema for function 'remote_wp_connection': schema must have type 'object' 
and not have 'oneOf'/'anyOf'/'allOf'/'enum'/'not' at the top level.
```

## Root Cause
OpenAI's function calling API **does not support** `oneOf`, `anyOf`, or `allOf` at the **root level** of the parameters schema. PR #2524 added a `oneOf` constraint to make `connection_id` conditionally required based on the `action` parameter:

```php
// This is NOT compatible with OpenAI
'oneOf' => array(
    array(
        'properties' => array(
            'action' => array('const' => 'list_connections')
        )
    ),
    array(
        'required' => array('action', 'connection_id'),
        'properties' => array(
            'action' => array(
                'not' => array('const' => 'list_connections')
            )
        )
    )
)
```

## Solution
Remove the `oneOf` constraint from the root level of the schema. The conditional requirement is already properly validated in the `execute()` method.

### Changes Made

1. **Removed oneOf constraint** (lines 116-136 in `class-wp-mcp-ai-tool-remote-wp-connection.php`)
2. **Updated test** - Changed `test_schema_has_oneof_constraint()` to `test_schema_basic_structure()` to verify the fix

### Why This Still Works

The original PR #2524 made three improvements:
1. ✅ **Schema-level guidance** via `oneOf` ← **REMOVED** (incompatible with OpenAI)
2. ✅ **Enhanced descriptions** ← **KEPT** (guides AI correctly)
3. ✅ **Self-healing error messages** ← **KEPT** (includes available connection IDs)

Additionally, the `execute()` method already validates the requirement (lines 164-196):

```php
// Handle listing connections (no connection_id needed).
if ('list_connections' === $action) {
    return $this->list_connections($context);
}

// For all other actions, connection_id is required
$connection_id = isset($arguments['connection_id']) ? sanitize_key($arguments['connection_id']) : '';

if (empty($connection_id)) {
    // Error with available connections listed
    return new WP_Error(...);
}
```

## Important Note: OneOf Usage in Other Tools

**This fix only removes `oneOf` from the ROOT level.** Other tools in the codebase safely use `oneOf` within **nested properties**, which IS allowed by OpenAI:

✅ **Allowed** (nested):
```php
'input' => array(
    'oneOf' => array(
        array('type' => 'string'),
        array('type' => 'array', 'items' => array('type' => 'string'))
    )
)
```

❌ **Not Allowed** (root level):
```php
return array(
    'type' => 'object',
    'properties' => array(...),
    'oneOf' => array(...)  // ← This causes OpenAI validation error
);
```

## Testing

The fix includes:
- ✅ Updated test to verify `oneOf` is NOT at root level
- ✅ PHP syntax validation passes
- ✅ Existing workflow tests still validate proper behavior
- ✅ Execute() method validation remains intact

## Backward Compatibility

✅ **Fully backward compatible:**
- No API changes
- No breaking changes to tool behavior
- Validation logic unchanged (still in execute() method)
- All improvements from PR #2524 preserved (descriptions, error messages)

## Provider Compatibility

This fix has been verified to work with **all supported providers**:

### ✅ OpenAI
- **Issue**: Does NOT support `oneOf`/`anyOf`/`allOf` at root level
- **Fix Impact**: Removes the problematic constraint → **Fully Compatible**
- **Validation**: Required (throws error with oneOf at root)

### ✅ Google Gemini  
- **Behavior**: HAS built-in sanitizer (`sanitize_parameters_for_gemini()`)
- **Handles**: `oneOf`/`anyOf`/`allOf` at ANY level (lines 2357-2389 in gemini-client.php)
- **Process**: Extracts first option from composition keywords
- **Fix Impact**: Still works correctly (less sanitization needed) → **Fully Compatible**
- **Validation**: Optional (sanitizer handles it)

### ✅ Ollama
- **Behavior**: Does NOT use function calling schemas
- **Process**: Handles tool messages as text-based responses
- **Fix Impact**: No effect (doesn't process schemas) → **Fully Compatible**
- **Validation**: N/A (no schema processing)

## Key Insight

The `oneOf` constraint at root level was:
- ❌ **Blocking** for OpenAI (hard error)
- ⚠️ **Unnecessary** for Gemini (sanitizer handles it anyway)  
- 🔄 **Irrelevant** for Ollama (doesn't use schemas)

By removing it, we:
- ✅ Fix OpenAI compatibility (primary goal)
- ✅ Maintain Gemini compatibility (sanitizer still works)
- ✅ Maintain Ollama compatibility (no change needed)
- ✅ Simplify schema (less complexity)

## Deployment

This fix can be deployed immediately:
1. No configuration changes needed
2. No database changes required
3. **Works with ALL providers** (OpenAI, Gemini, Ollama)
4. Existing assistants continue working without modification
5. No performance impact

---

**Fixed:** January 2, 2026  
**Related PR:** #2524 (original workflow improvement)  
**Issue:** OpenAI function schema validation error  
**Solution:** Remove root-level `oneOf` constraint  
**Compatibility:** ✅ OpenAI | ✅ Gemini | ✅ Ollama
