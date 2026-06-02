# Gemini Schema Validation Fix - Affected Tools

## Problem Summary

The Gemini API was rejecting tool schemas with errors:
```
Invalid value at 'tools[0].function_declarations[0].parameters.properties[5].value.items.properties[3].value' (type.googleapis.com/google.ai.generativelanguage.v1beta.Schema), "string"
Invalid value at 'tools[0].function_declarations[18].parameters.properties[4].value' (type.googleapis.com/google.ai.generativelanguage.v1beta.Schema), "string"
```

## Root Cause

Tools using JSON Schema composition keywords (`oneOf`, `anyOf`, `allOf`) had these keywords stripped during sanitization for Gemini API compatibility, leaving empty or invalid schema objects.

## Affected Tools

Based on code analysis, the following tools use composition keywords and were affected:

### Tools Using `oneOf`

1. **create-google-calendar-event** (`class-wp-mcp-ai-tool-create-google-calendar-event.php`)
   - Location: `attendees.items.oneOf`
   - Issue: Items could be either string (email) or object (email + name + optional)
   - Fix: Now uses first option (string type)

2. **get-open-meteo-forecast** (`class-wp-mcp-ai-tool-get-open-meteo-forecast.php`)
   - Location: Parameter level `oneOf`
   - Issue: Flexible parameter types
   - Fix: Now uses first option

3. **run-openai-external-action** (`class-wp-mcp-ai-tool-run-openai-external-action.php`)
   - Location: Multiple `oneOf` in nested properties
   - Issue: Complex schema composition
   - Fix: Now uses first options throughout

4. **send-group-email** (`class-wp-mcp-ai-tool-send-group-email.php`)
   - Location: Parameter level `oneOf`
   - Issue: Flexible recipient types
   - Fix: Now uses first option

5. **send-mailjet-email** (`class-wp-mcp-ai-tool-send-mailjet-email.php`)
   - Location: Multiple parameters with `oneOf`
   - Issue: Multiple flexible field types
   - Fix: Now uses first options

### Tools Using `anyOf`

1. **create-cron-job** (`class-wp-mcp-ai-tool-create-cron-job.php`)
   - Location: Multiple nested `anyOf`
   - Issue: Flexible scheduling parameters
   - Fix: Now uses first options

2. **get-import-duty** (`class-wp-mcp-ai-tool-get-import-duty.php`)
   - Location: Parameter level `anyOf`
   - Issue: Flexible input types
   - Fix: Now uses first option

3. **get-rankmath-seo** (`class-wp-mcp-ai-tool-get-rankmath-seo.php`)
   - Location: Parameter level `anyOf`
   - Issue: Flexible query parameters
   - Fix: Now uses first option

### Tools Using `allOf`

1. **get-import-duty** (`class-wp-mcp-ai-tool-get-import-duty.php`)
   - Location: Root level `allOf`
   - Issue: Schema constraints composition
   - Fix: Now uses first option with merged constraints

## Solution

Modified `sanitize_parameters_for_gemini()` in `includes/class-wp-mcp-ai-gemini-client.php`:

1. **Before stripping composition keywords**, extract the first schema option
2. **Merge** the first option's properties into the parent schema
3. **Remove** the composition keyword
4. **Preserve** existing schema properties (don't override)

### Example Transformation

**Before:**
```php
'items' => array(
    'oneOf' => array(
        array('type' => 'string'),
        array('type' => 'object', 'properties' => [...])
    )
)
```

**After sanitization:**
```php
'items' => array(
    'type' => 'string'  // First option extracted
)
```

## Impact

- **Positive**: All affected tools can now be used with Gemini API
- **Limitation**: Only the first type option from composition keywords is preserved
- **Acceptable**: This maintains type safety and Gemini compatibility

## Testing

Created comprehensive test suite in `tests/test-gemini-composition-keywords.php`:
- ✓ oneOf conversion
- ✓ anyOf conversion  
- ✓ allOf conversion with constraint preservation
- ✓ Complex nested composition handling

## Monitoring

Added logging to track composition keyword conversions:
- Event: `gemini_schema_composition`
- Data: keyword type, parent context, type extracted, option count

This helps identify which tools are being transformed during runtime.
