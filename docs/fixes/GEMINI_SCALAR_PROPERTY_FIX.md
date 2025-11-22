# Gemini Scalar Property Value Fix

## Problem

The Gemini API was returning errors when tool schemas contained scalar property values instead of proper Schema objects:

```
Invalid value at 'tools[0].function_declarations[0].parameters.properties[5].value.items.properties[3].value' 
(type.googleapis.com/google.ai.generativelanguage.v1beta.Schema), "string"

Invalid value at 'tools[0].function_declarations[18].parameters.properties[4].value' 
(type.googleapis.com/google.ai.generativelanguage.v1beta.Schema), "string"
```

## Root Cause

Gemini's API expects all property values in a `properties` object to be full Schema objects (arrays/objects), not scalar values. 

**Incorrect format (causes error):**
```json
{
  "type": "object",
  "properties": {
    "field1": "string",
    "field2": "number"
  }
}
```

**Correct format (works):**
```json
{
  "type": "object",
  "properties": {
    "field1": {
      "type": "string"
    },
    "field2": {
      "type": "number"
    }
  }
}
```

## Solution

Added a new `normalize_property_schemas()` method to the Gemini client that:

1. Detects scalar property values in `properties` objects
2. Converts them to proper schema objects with a `type` field
3. Logs conversions for debugging
4. Works recursively for nested `properties` (e.g., in `items.properties`)

### Implementation Details

**File:** `includes/class-wp-mcp-ai-gemini-client.php`

**New Method:** `normalize_property_schemas()` (lines 1869-1907)
```php
protected function normalize_property_schemas( array $properties ) {
    $normalized = array();

    foreach ( $properties as $prop_name => $prop_value ) {
        if ( ! is_array( $prop_value ) && ! is_object( $prop_value ) ) {
            // Convert scalar to schema object
            $normalized[ $prop_name ] = array(
                'type' => is_string( $prop_value ) ? $prop_value : 'string',
            );
            
            // Log for debugging
            WP_MCP_AI_Logger::log_event( 'gemini_schema_fix', ... );
        } else {
            $normalized[ $prop_name ] = $prop_value;
        }
    }

    return $normalized;
}
```

**Modified Method:** `sanitize_parameters_for_gemini()` (line 2024)
```php
// Apply normalization to properties objects
if ( isset( $sanitized['properties'] ) && is_array( $sanitized['properties'] ) ) {
    $sanitized['properties'] = $this->normalize_property_schemas( $sanitized['properties'] );
}
```

## Separation of Concerns (SoC)

The fix follows SoC principles by:

- **Extracting specialized logic** into a dedicated method (`normalize_property_schemas()`)
- **Single responsibility** - the new method only normalizes property values
- **Clear naming** - method name clearly indicates its purpose
- **Testability** - isolated logic is easier to test independently
- **Maintainability** - changes to normalization logic are localized to one method

## Testing

Created comprehensive test suite in `tests/test-gemini-scalar-property-fix.php`:

1. **Test simple scalar property values** - Ensures top-level scalars are converted
2. **Test nested scalar values** - Ensures deeply nested properties are handled
3. **Test non-string scalars** - Ensures numbers, booleans default to string type

All tests verify that:
- Scalar values are converted to schema objects
- Properly formed schemas are preserved unchanged
- The resulting JSON structure is valid for Gemini API

## Integration Test Results

```
✓ PASS: Top-level scalar properties converted correctly
✓ PASS: Nested scalar properties converted correctly  
✓ PASS: JSON structure is valid (no scalar property values)
```

## Impact

This fix prevents Gemini API errors when:
- Tool definitions contain malformed schemas (though none found in current codebase)
- External tool registrations provide incorrect schema formats
- Schema transformations accidentally produce scalar property values
- Future tools are added with improper schema definitions

## Monitoring

The fix logs conversions with event type `gemini_schema_fix`. To monitor:

```php
// Check logs for schema corrections
$logs = get_option( 'wp_mcp_ai_recent_activity', array() );
$schema_fixes = array_filter( $logs, function( $log ) {
    return isset( $log['event_type'] ) && $log['event_type'] === 'gemini_schema_fix';
} );
```

If you see these events frequently, it indicates a tool or integration is providing malformed schemas and should be fixed at the source.

## Related Files

- `includes/class-wp-mcp-ai-gemini-client.php` - Implementation
- `tests/test-gemini-scalar-property-fix.php` - Test suite
- `tests/test-gemini-tool-sanitization.php` - Related tests for other sanitization features

## References

- Gemini API Schema Documentation: https://ai.google.dev/api/rest/v1beta/Schema
- Related fix for schema composition keywords: `GEMINI_COMPOSITION_FIX.md`
- Related fix for type inference: `GEMINI_SCHEMA_TYPE_INFERENCE_FIX.md`
