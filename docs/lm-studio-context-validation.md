# LM Studio Context Window Validation

## Overview

This document describes the context window validation feature for the LM Studio provider, which prevents context overflow errors by validating message token counts before sending requests to LM Studio.

## Problem

When using LM Studio as an AI provider, models are loaded with specific context window sizes (e.g., 4096 tokens). If the WordPress plugin attempts to send more tokens than the model supports, LM Studio returns an error:

```
Trying to keep the first 5415 tokens when context overflows. 
However, the model is loaded with context length of only 4096 tokens, 
which is not enough. Try to load the model with a larger context length, 
or provide a shorter input.
```

This error occurs at runtime after the request has already been sent, wasting time and resources.

## Solution

The WP oOS plugin now validates context windows **before** sending requests to LM Studio:

1. **Automatic Detection**: Queries LM Studio's `/v1/models` endpoint to detect each model's `context_length`
2. **Token Estimation**: Estimates total token usage including input messages, output tokens, and tool definitions
3. **Pre-flight Validation**: Rejects requests that would exceed the context window before sending to LM Studio
4. **Clear Error Messages**: Provides actionable suggestions when validation fails

## Architecture

### 1. Context Length Detection

The `list_models()` method now captures the `context_length` field from LM Studio's model listing:

```php
// LM Studio API Response
{
  "data": [
    {
      "id": "openai/gpt-oss-20b",
      "object": "model",
      "created": 1234567890,
      "owned_by": "lm-studio",
      "context_length": 4096  // <-- Captured by the client
    }
  ]
}
```

### 2. Context Window Lookup

The `get_model_context_window($model)` method:
- Checks WordPress cache first (5-minute TTL)
- Queries `/v1/models` if not cached
- Returns the model's `context_length` value
- Falls back to 4096 tokens if unavailable
- Logs events for debugging

```php
$context_window = $client->get_model_context_window('openai/gpt-oss-20b');
// Returns: 4096
```

### 3. Token Estimation

The `validate_context_window()` method estimates total tokens:

```php
// For each message:
- Count characters in content (UTF-8 safe)
- Divide by 4 (avg ~4 chars per token)
- Add 10 tokens message overhead

// Add response budget:
+ max_tokens parameter (default: 2048)

// Add tool definitions:
+ Estimated tokens for tools JSON (if present)

// Safety margin:
× 1.1 (10% buffer for tokenization differences)
```

### 4. Validation Flow

```
User Request
    ↓
create_chat_completion() or create_completion()
    ↓
get_model_context_window(model)
    ↓
validate_context_window(messages, model, options)
    ↓
    ├─ Within Limit → Proceed with request
    └─ Exceeds Limit → Return WP_Error with suggestions
```

## Error Response

When context validation fails, the user receives a `WP_Error`:

```php
WP_Error {
  code: 'wp_mcp_ai_context_overflow'
  message: 'The request requires approximately 5500 tokens, but the model 
            (openai/gpt-oss-20b) only supports 4096 tokens. Please reduce 
            the message history or use a model with a larger context window.'
  data: {
    status: 400,
    estimated_tokens: 3400,
    max_output_tokens: 2048,
    total_tokens: 5500,
    context_window: 4096,
    overflow_by: 1404,
    actions: {
      reduce_message_history: 'Reduce the number of messages in the conversation history.',
      reduce_max_tokens: 'Reduce the max_tokens parameter to allow more input.',
      use_larger_context_model: 'Use a model with a larger context window (e.g., load the model with --ctx-size 8192 or higher).'
    }
  }
}
```

## Configuration

### Loading Models with Larger Context Windows

To increase the context window in LM Studio, load models with the `--ctx-size` parameter:

```bash
# Default (often 2048 or 4096)
lm-studio-cli load openai/gpt-oss-20b

# With 8192 token context window
lm-studio-cli load openai/gpt-oss-20b --ctx-size 8192

# With 16384 token context window  
lm-studio-cli load openai/gpt-oss-20b --ctx-size 16384
```

**Note**: Larger context windows require more VRAM. Check your hardware limitations.

### Adjusting max_tokens

Reduce the response token budget to allow more input tokens:

```php
// PHP Example
$options = array(
  'max_tokens' => 1024,  // Reduced from 2048
);
$client->create_chat_completion($messages, $options);
```

```javascript
// JavaScript Example (REST API)
fetch('/wp-json/mcp-ai/v1/chat', {
  method: 'POST',
  body: JSON.stringify({
    messages: messages,
    max_tokens: 1024  // Reduced from 2048
  })
});
```

## Filters

### `wp_mcp_ai_lm_studio_max_tokens`

Filter the maximum output tokens for chat completions:

```php
add_filter('wp_mcp_ai_lm_studio_max_tokens', function($max_tokens, $options) {
  // Reduce for long conversations
  if (count($options['messages']) > 10) {
    return 512;
  }
  return $max_tokens;
}, 10, 2);
```

### `wp_mcp_ai_lm_studio_completion_max_tokens`

Filter the maximum output tokens for text completions:

```php
add_filter('wp_mcp_ai_lm_studio_completion_max_tokens', function($max_tokens, $options) {
  // Custom logic for text completions
  return 1024;
}, 10, 2);
```

## Caching

Context window sizes are cached for 5 minutes to minimize API calls:

```php
// Cache key format
$cache_key = 'lm_studio_context_' . md5($model_id);

// Cache group
$cache_group = 'wp_mcp_ai_lm_studio';

// TTL: 5 minutes (300 seconds)
```

To clear the cache programmatically:

```php
wp_cache_flush();
// or
wp_cache_delete('lm_studio_context_' . md5('model-id'), 'wp_mcp_ai_lm_studio');
```

## Logging

Context validation events are logged when WP oOS logging is enabled:

```php
// Successful validation
[lm_studio_context_validation] Validating context window.
{
  model: 'openai/gpt-oss-20b',
  context_window: 4096,
  estimated_input_tokens: 450,
  max_output_tokens: 2048,
  total_with_margin: 2748,
  is_within_limit: true
}

// Context window lookup fallback
[lm_studio_context_window_unknown] Context window not found for model, using conservative default.
{
  model: 'unknown-model'
}

// API error during lookup
[error] Failed to fetch LM Studio models for context window lookup.
{
  model: 'model-id',
  error: 'Connection refused'
}
```

## Testing

The implementation includes comprehensive test coverage:

```bash
# Run all LM Studio client tests
vendor/bin/phpunit tests/test-lm-studio-client.php

# Run specific test
vendor/bin/phpunit tests/test-lm-studio-client.php --filter test_chat_completion_context_overflow_error
```

Test coverage includes:
- ✓ Context length capture in `list_models()`
- ✓ Context window retrieval via `get_model_context_window()`
- ✓ Default fallback for unknown models
- ✓ Chat completion rejection when context exceeded
- ✓ Chat completion success within context limits
- ✓ Text completion context overflow handling
- ✓ Caching mechanism validation

## Troubleshooting

### Issue: Context validation is too conservative

**Symptom**: Requests are rejected even though they should fit.

**Cause**: The 4-character-per-token heuristic may overestimate for some content.

**Solution**: 
1. Reduce `max_tokens` to provide more headroom
2. Use a model with a larger context window
3. Consider implementing a custom tokenizer filter

### Issue: Cache is stale after model reload

**Symptom**: Plugin uses old context window size after reloading model with different `--ctx-size`.

**Solution**: Clear the cache:
```php
wp_cache_flush();
```

Or wait 5 minutes for automatic cache expiration.

### Issue: Validation fails with WP_Error from `get_model_context_window()`

**Symptom**: LM Studio API is temporarily unavailable.

**Solution**: The validation automatically falls back to a conservative 4096 token default and logs the error. The request proceeds unless it exceeds even the conservative limit.

## Performance Impact

- **Cache Hit**: Near-zero overhead (WordPress object cache lookup)
- **Cache Miss**: One additional API call to `/v1/models` (cached for 5 minutes)
- **Token Estimation**: Minimal CPU overhead (character counting and division)

The implementation prioritizes correctness over performance, but uses caching to minimize the impact on subsequent requests.

## Future Enhancements

Potential improvements for future versions:

1. **Accurate Tokenizer**: Integrate a proper tokenizer library (e.g., tiktoken port) for exact token counts
2. **Message Trimming**: Automatically trim older messages when approaching context limit
3. **Adaptive max_tokens**: Dynamically adjust response budget based on input length
4. **Model Context Override**: Allow manual context window configuration in settings
5. **Context Window Analytics**: Track and report context usage over time

## References

- [LM Studio Documentation](https://lmstudio.ai/docs)
- [OpenAI Tokenization Guide](https://platform.openai.com/docs/guides/tokenization)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- [WP oOS Documentation Index](../DOCUMENTATION_INDEX.md)

## See Also

- [LM Studio Client Implementation](../includes/class-wp-mcp-ai-lm-studio-client.php)
- [Token Budget Manager](../includes/services/class-wp-mcp-ai-token-budget-service.php)
- [LM Studio Client Tests](../tests/test-lm-studio-client.php)
