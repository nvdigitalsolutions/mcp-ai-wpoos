# Gemini Scalar Property Fix - Visual Guide

## The Problem (Before Fix)

```
Tool Definition
      ↓
[Malformed Schema]
{
  "properties": {
    "field1": "string",  ← SCALAR VALUE (WRONG!)
    "field2": "number"   ← SCALAR VALUE (WRONG!)
  }
}
      ↓
translate_tools()
      ↓
sanitize_parameters_for_gemini()
      ↓
[Sends malformed schema to Gemini]
{
  "properties": {
    "field1": "string",  ← Still scalar!
    "field2": "number"   ← Still scalar!
  }
}
      ↓
Gemini API
      ↓
❌ ERROR: "Invalid value... expecting Schema object but got string"
```

## The Solution (After Fix)

```
Tool Definition
      ↓
[Malformed Schema]
{
  "properties": {
    "field1": "string",  ← SCALAR VALUE
    "field2": "number"   ← SCALAR VALUE
  }
}
      ↓
translate_tools()
      ↓
sanitize_parameters_for_gemini()
      ↓
normalize_property_schemas() ← NEW METHOD!
      ↓
[Detects scalars and converts]
{
  "properties": {
    "field1": {          ← CONVERTED TO SCHEMA OBJECT ✓
      "type": "string"
    },
    "field2": {          ← CONVERTED TO SCHEMA OBJECT ✓
      "type": "number"
    }
  }
}
      ↓
Gemini API
      ↓
✅ SUCCESS: Valid schema accepted
```

## Code Flow

### Old Flow (Error)
```
sanitize_parameters_for_gemini()
├── Filter unsupported keywords
├── Handle composition keywords
├── Convert union types
├── Recurse into nested structures
│   └── properties
│       └── field1: "string" ← Scalar passes through unchanged
└── Return malformed schema ❌
```

### New Flow (Fixed)
```
sanitize_parameters_for_gemini()
├── Filter unsupported keywords
├── Handle composition keywords
├── Convert union types
├── Recurse into nested structures
│   └── properties (recursive call)
│       └── ... nested processing ...
├── normalize_property_schemas() ← NEW STEP!
│   ├── Check each property value
│   ├── If scalar → convert to {"type": "..."}
│   └── If object → keep as-is
└── Return valid schema ✅
```

## Nested Properties Example

### Before Fix (Nested Error)
```json
{
  "properties": {
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "name": "string",    ← Nested scalar (ERROR!)
          "value": "number"    ← Nested scalar (ERROR!)
        }
      }
    }
  }
}
```

### After Fix (Nested Fixed)
```json
{
  "properties": {
    "items": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "name": {           ← Converted to schema object ✓
            "type": "string"
          },
          "value": {          ← Converted to schema object ✓
            "type": "number"
          }
        }
      }
    }
  }
}
```

## Method Responsibility (SoC)

```
┌─────────────────────────────────────────────────┐
│ sanitize_parameters_for_gemini()                │
│                                                 │
│ Responsibilities:                               │
│ • Orchestrate sanitization steps               │
│ • Filter unsupported keywords                  │
│ • Handle composition keywords                  │
│ • Convert union types                          │
│ • Recurse into nested structures               │
│ • Delegate to specialized methods ✓            │
└─────────────────────────────────────────────────┘
                    ↓ delegates to
┌─────────────────────────────────────────────────┐
│ normalize_property_schemas()                    │
│                                                 │
│ Single Responsibility:                          │
│ • Ensure property values are schema objects    │
│ • Convert scalars to {"type": "..."} format    │
│ • Log conversions for debugging                │
└─────────────────────────────────────────────────┘
```

## Error Path Analysis

The error message path:
```
tools[0].function_declarations[0].parameters.properties[5].value.items.properties[3].value
```

Maps to our JSON structure:
```
tools
└── [0]
    └── functionDeclarations
        └── [0]
            └── parameters
                └── properties
                    └── [property #5]  ← .value in protobuf
                        └── items
                            └── properties
                                └── [property #3]  ← .value in protobuf = "string" (ERROR!)
```

The `.value` notation is Gemini's internal protobuf representation where each property in a map has a `key` and `value` field.

## Prevention

To prevent this issue in the future:

1. **Tool Definitions**: Always use proper schema objects
   ```php
   // ❌ WRONG
   'properties' => array(
       'field1' => 'string',
   )
   
   // ✅ CORRECT
   'properties' => array(
       'field1' => array('type' => 'string'),
   )
   ```

2. **Schema Transformations**: Preserve schema object structure
   
3. **External Integrations**: Validate incoming schemas

4. **Monitor Logs**: Check for `gemini_schema_fix` events
   ```php
   // If you see these logs, fix the source!
   WP_MCP_AI_Logger::log_event('gemini_schema_fix', ...)
   ```

## Testing

Run the test suite to verify the fix:
```bash
vendor/bin/phpunit tests/test-gemini-scalar-property-fix.php
```

Expected results:
- ✓ Scalar property values converted to schema objects
- ✓ Nested scalar values handled correctly
- ✓ Non-string scalars default to string type
- ✓ Properly formed schemas preserved unchanged
