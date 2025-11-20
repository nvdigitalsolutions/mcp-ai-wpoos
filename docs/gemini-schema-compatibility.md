# Gemini API Schema Compatibility

## Overview

The Gemini API uses a **restricted subset of OpenAPI 3.0 Schema Object** for function declaration parameters. This is more restrictive than other providers like OpenAI, which support full JSON Schema Draft 2020-12.

## Unsupported Keywords

The following JSON Schema keywords are **NOT supported** by Gemini API and are automatically filtered out by `WP_MCP_AI_Gemini_Client::sanitize_parameters_for_gemini()`:

### Schema Validation Keywords
- `default` - Default values for parameters
- `examples` - Example values array
- `const` - Constant value constraints
- `format` - Format validators (email, uri, date-time, etc.)

### Type Keywords
- `nullable` - Nullable type indicator (use type array instead, which we convert to single type)
- Union types (type as array) - Converted to first type (e.g., `['string', 'null']` becomes `'string'`)

### Schema Composition
- `oneOf` - One of multiple schemas
- `anyOf` - Any of multiple schemas
- `allOf` - All of multiple schemas

### Meta Keywords
- `additionalProperties` - Additional property constraints
- `$ref` - JSON Schema references
- `$schema` - JSON Schema version identifier
- `$id` - Schema identifier

## Supported Keywords

The following keywords **ARE supported** and preserved:

- `type` - Data type (as single value, not array)
- `description` - Human-readable description
- `enum` - Enumerated values
- `properties` - Object properties
- `items` - Array item schema
- `required` - Required properties array
- `minimum` / `maximum` - Numeric constraints
- `minLength` / `maxLength` - String length constraints
- `minItems` / `maxItems` - Array length constraints
- `pattern` - Regex pattern for strings

## Impact on Tool Definitions

### Before Sanitization
```php
'parameters' => array(
    'type' => 'object',
    'properties' => array(
        'count' => array(
            'type' => 'integer',
            'description' => 'Number of items',
            'minimum' => 1,
            'maximum' => 100,
            'default' => 10,  // ❌ Removed for Gemini
        ),
        'email' => array(
            'type' => 'string',
            'description' => 'Email address',
            'format' => 'email',  // ❌ Removed for Gemini
        ),
    ),
    'additionalProperties' => false,  // ❌ Removed for Gemini
)
```

### After Sanitization
```php
'parameters' => array(
    'type' => 'object',
    'properties' => array(
        'count' => array(
            'type' => 'integer',
            'description' => 'Number of items',
            'minimum' => 1,
            'maximum' => 100,
            // 'default' removed
        ),
        'email' => array(
            'type' => 'string',
            'description' => 'Email address',
            // 'format' removed
        ),
    ),
    // 'additionalProperties' removed
)
```

## Provider Comparison

| Keyword | OpenAI | Gemini | Anthropic | Ollama | LM Studio |
|---------|--------|--------|-----------|--------|-----------|
| `default` | ✅ | ❌ | N/A* | ✅ | ✅ |
| `examples` | ✅ | ❌ | N/A* | ✅ | ✅ |
| `format` | ✅ | ❌ | N/A* | ✅ | ✅ |
| `const` | ✅ | ❌ | N/A* | ✅ | ✅ |
| `oneOf/anyOf/allOf` | ✅ | ❌ | N/A* | ✅ | ✅ |
| `additionalProperties` | ✅ | ❌ | N/A* | ✅ | ✅ |
| Union types | ✅ | ❌ | N/A* | ✅ | ✅ |

*Anthropic client does not currently support tool calling in this implementation.

## Implementation Details

### Automatic Sanitization

Schema sanitization happens automatically in `WP_MCP_AI_Gemini_Client::translate_tools()` which is called during `build_payload()` before sending requests to Gemini.

### No Changes Required for Other Providers

- **OpenAI**: No sanitization needed (full JSON Schema support)
- **LM Studio**: No sanitization needed (OpenAI-compatible)
- **Ollama**: Limited tool support, no sanitization needed
- **Anthropic**: Tool calling not implemented yet

### Tool Developers

When creating new tools, you can freely use all JSON Schema keywords. The sanitization will automatically handle Gemini compatibility. However, be aware that:

1. **Default values** won't work with Gemini - handle defaults in your tool's `execute()` method
2. **Format validation** won't be enforced by Gemini - validate formats in your tool's `execute()` method
3. **Schema composition** (oneOf, anyOf, allOf) won't work - use simple schemas for Gemini compatibility

## References

- [Gemini API Function Calling Documentation](https://ai.google.dev/gemini-api/docs/function-calling)
- [OpenAPI 3.0 Schema Object Specification](https://spec.openapis.org/oas/v3.0.3#schema-object)
- [JSON Schema Specification](https://json-schema.org/specification.html)

## Related Files

- `includes/class-wp-mcp-ai-gemini-client.php` - Gemini client with sanitization
- `tests/test-gemini-tool-sanitization.php` - Comprehensive sanitization tests
