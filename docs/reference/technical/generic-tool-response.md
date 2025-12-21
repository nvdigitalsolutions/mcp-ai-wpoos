# Generic Tool Response System

## Overview

The Generic Tool Response system provides a standardized way to handle responses from different AI providers (OpenAI, Gemini, Anthropic, Ollama, LM Studio) without writing provider-specific code.

## Problem Statement

Different AI providers return responses in different formats:
- **OpenAI**: `choices[0].message.content`
- **Gemini**: `candidates[0].content.parts[0].text`
- **Anthropic**: `content[0].text`

This required maintaining separate parsing logic for each provider, making code difficult to maintain and error-prone.

## Solution

The Generic Tool Response system provides:

1. **Interface** (`WP_MCP_AI_Generic_Tool_Response`) - Defines the standard contract
2. **Implementation** (`WP_MCP_AI_Generic_Tool_Response_Impl`) - Concrete implementation
3. **Adapter** (`WP_MCP_AI_Tool_Response_Adapter`) - Provider-specific transformations
4. **Helper Functions** - Easy-to-use extraction functions

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│  Application Code (REST API, Shortcodes, etc.)         │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│  wp_mcp_ai_extract_generic_tool_response()              │
│  (Helper Function - Main Entry Point)                   │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│  WP_MCP_AI_Tool_Response_Adapter                        │
│  ├─ from_openai()                                       │
│  ├─ from_gemini()                                       │
│  ├─ from_anthropic()                                    │
│  ├─ from_ollama()                                       │
│  └─ from_lm_studio()                                    │
└────────────────────┬────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────┐
│  WP_MCP_AI_Generic_Tool_Response_Impl                   │
│  (implements WP_MCP_AI_Generic_Tool_Response)           │
└─────────────────────────────────────────────────────────┘
```

## Usage

### Basic Example

```php
// Get response from any provider
$raw_response = $client->create_chat_completion( $messages, $options );

// Extract generic response
$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

// Use unified interface
if ( $generic_response->is_success() ) {
    $content = $generic_response->get_content();
    $usage   = $generic_response->get_usage();
    $model   = $generic_response->get_model();
} else {
    $error = $generic_response->get_error();
    // Handle error
}
```

### Working with Tool Calls

```php
$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'gemini' );

$tool_calls = $generic_response->get_tool_calls();
if ( null !== $tool_calls ) {
    foreach ( $tool_calls as $tool_call ) {
        $function_name = $tool_call['function']['name'];
        $arguments     = json_decode( $tool_call['function']['arguments'], true );
        
        // Execute the tool
        $result = execute_tool( $function_name, $arguments );
    }
}
```

### Checking Provider Support

```php
if ( wp_mcp_ai_is_provider_supported( 'openai' ) ) {
    // Provider is supported
}
```

## API Reference

### Interface: `WP_MCP_AI_Generic_Tool_Response`

#### Methods

**`get_content(): string|null`**
- Returns the main text content from the response
- Handles both string content and content segments automatically
- Returns `null` if no content is available

**`get_error(): array|null`**
- Returns error information with `code` and `message` keys
- Returns `null` if the response was successful
- Example: `array( 'code' => 500, 'message' => 'API request failed' )`

**`get_tool_calls(): array|null`**
- Returns array of tool call objects
- Each tool call has `id`, `type`, and `function` properties
- Returns `null` if no tool calls present

**`get_usage(): array|null`**
- Returns token usage information
- Keys: `prompt_tokens`, `completion_tokens`, `total_tokens`
- Returns `null` if usage data not available

**`get_finish_reason(): string|null`**
- Returns the finish reason: `'stop'`, `'length'`, `'tool_calls'`, etc.
- Returns `null` if not available

**`get_original_response(): array`**
- Returns the raw, unprocessed response from the provider
- Useful for debugging or accessing provider-specific fields

**`is_success(): bool`**
- Returns `true` if the response was successful
- Returns `false` if an error occurred

**`get_provider(): string`**
- Returns the provider identifier: `'openai'`, `'gemini'`, `'anthropic'`, etc.

**`get_model(): string|null`**
- Returns the model identifier used for the response
- Returns `null` if not available

### Helper Functions

**`wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider_identifier )`**

Main entry point for extracting a generic response.

**Parameters:**
- `$raw_response` (array|WP_Error): Raw response from AI provider
- `$provider_identifier` (string): Provider name ('openai', 'gemini', 'anthropic', 'ollama', 'lm-studio')

**Returns:** `WP_MCP_AI_Generic_Tool_Response`

---

**`wp_mcp_ai_is_provider_supported( $provider_identifier )`**

Check if a provider is supported.

**Parameters:**
- `$provider_identifier` (string): Provider name to check

**Returns:** `bool` - True if supported, false otherwise

## Supported Providers

| Provider | Identifier | Alternative ID |
|----------|-----------|----------------|
| OpenAI | `'openai'` | - |
| Gemini | `'gemini'` | - |
| Anthropic | `'anthropic'` | - |
| Ollama | `'ollama'` | - |
| LM Studio | `'lm-studio'` | `'lm_studio'` |

## Error Handling

The system handles errors in two ways:

### 1. WP_Error Responses

If the provider client returns a `WP_Error`, it's automatically converted to a generic response:

```php
$error_response = new WP_Error( 'api_error', 'Failed to connect' );
$generic = wp_mcp_ai_extract_generic_tool_response( $error_response, 'openai' );

$generic->is_success(); // false
$generic->get_error();  // array( 'code' => 500, 'message' => 'Failed to connect' )
```

### 2. API Error Responses

If the provider returns an error in its response structure:

```php
$api_error = array(
    'error' => array(
        'code' => 'rate_limit_exceeded',
        'message' => 'Rate limit exceeded',
    ),
);

$generic = wp_mcp_ai_extract_generic_tool_response( $api_error, 'openai' );
$generic->is_success(); // false
```

## Backward Compatibility

The existing client classes already normalize responses to a common format. This new system:

1. **Does not modify existing client behavior** - Clients continue to return the same normalized format
2. **Provides a formal interface** - Makes the implicit contract explicit
3. **Adds convenience methods** - Easier access to common response fields
4. **Standardizes error handling** - Consistent error format across providers

Existing code continues to work without modification. New code can use the generic interface for cleaner, more maintainable implementations.

## Migration Guide

### Old Approach

```php
$response = $client->create_chat_completion( $messages, $options );

if ( is_wp_error( $response ) ) {
    return $response->get_error_message();
}

$content = isset( $response['choices'][0]['message']['content'] ) 
    ? $response['choices'][0]['message']['content'] 
    : '';
    
if ( is_array( $content ) ) {
    // Extract text from segments...
}
```

### New Approach

```php
$raw_response = $client->create_chat_completion( $messages, $options );
$generic_response = wp_mcp_ai_extract_generic_tool_response( $raw_response, 'openai' );

if ( ! $generic_response->is_success() ) {
    $error = $generic_response->get_error();
    return $error['message'];
}

$content = $generic_response->get_content(); // Always a string or null
```

## Benefits

1. **Type Safety** - Clear interface contract
2. **Maintainability** - Single place to update response parsing logic
3. **Testability** - Easy to mock and test
4. **Extensibility** - Easy to add new providers
5. **Consistency** - Same code works for all providers
6. **Error Handling** - Unified error format
7. **Debugging** - Original response always accessible

## Examples

See `assets/examples/generic-tool-response-usage.php` for complete working examples.

## Testing

The test suite includes 18+ test cases covering:
- Successful responses from all providers
- Error handling (WP_Error and API errors)
- Tool call extraction
- Token usage extraction
- Edge cases (missing data, null values)
- Provider validation

Run tests with:
```bash
vendor/bin/phpunit tests/test-generic-tool-response.php
```

## Future Enhancements

Potential future improvements:

1. **Streaming Support** - Interface for streaming responses
2. **Response Validation** - Schema validation for responses
3. **Response Caching** - Built-in caching layer
4. **Metrics Collection** - Automatic usage tracking
5. **Response Transformation** - Custom transformers for specific use cases
