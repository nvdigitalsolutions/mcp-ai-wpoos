# AI Provider Parameter Guide - November 2025 Update

## Overview

This guide documents all supported parameters for each AI provider in WP oOS, including the November 2025 enhancements for Gemini and Anthropic clients.

## Quick Reference

### Universal Parameters (All Providers)

| Parameter | Type | Description | Support |
|-----------|------|-------------|---------|
| `temperature` | float | Controls randomness (0-2) | All providers |
| `max_tokens` | integer | Maximum output length | All providers |
| `system_prompt` | string | System instructions | All providers |
| `tools` | array | Function calling definitions | All providers |

### Provider-Specific Parameters

| Parameter | Gemini | Anthropic | LM Studio | OpenAI | Ollama |
|-----------|--------|-----------|-----------|--------|--------|
| `top_p` | ✅ topP | ✅ | ✅ | ❌ | ❌ |
| `top_k` | ✅ topK | ✅ | ❌ | ❌ | ❌ |
| `frequency_penalty` | ✅ | ❌ | ✅ | ❌ | ❌ |
| `presence_penalty` | ✅ | ❌ | ✅ | ❌ | ❌ |
| `stop` / `stop_sequences` | ✅ stopSequences | ✅ | ✅ | ❌ | ❌ |
| `seed` | ❌ | ❌ | ✅ | ❌ | ❌ |
| `response_format` | ✅ | ❌ | ✅ | 🟡 | ❌ |
| `candidate_count` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `metadata` | ❌ | ✅ | ❌ | ❌ | ❌ |

Legend:
- ✅ = Fully supported
- 🟡 = Partially supported (images only for OpenAI)
- ❌ = Not supported

## Detailed Parameter Documentation

### 1. Sampling Parameters

#### temperature
Controls the randomness of the model's output.

**Type**: `float`  
**Range**: 
- OpenAI/LM Studio: 0.0 - 2.0
- Anthropic: 0.0 - 1.0
- Gemini: 0.0 - 2.0
- Ollama: 0.0 - 2.0

**Usage**:
- Lower values (0.0-0.3): More deterministic, focused responses
- Medium values (0.4-0.7): Balanced creativity and coherence
- Higher values (0.8-2.0): More creative, diverse responses

**Example**:
```php
$options = array(
    'temperature' => 0.7,
);
```

#### top_p (Nucleus Sampling)
Controls diversity by selecting from tokens whose cumulative probability exceeds top_p.

**Type**: `float`  
**Range**: 0.0 - 1.0  
**Providers**: Gemini (topP), Anthropic, LM Studio

**Best Practice**: Use either `temperature` OR `top_p`, not both (especially for Anthropic).

**Example**:
```php
$options = array(
    'top_p' => 0.9, // Consider top 90% probability mass
);
```

**Provider-specific notes**:
- **Gemini**: Use `topP` (camelCase)
- **Anthropic**: Recommends changing either temperature or top_p, not both
- **LM Studio**: OpenAI-compatible, use `top_p`

#### top_k (Top-K Sampling)
Limits selection to the K most probable tokens.

**Type**: `integer`  
**Range**: 0 - ∞ (typically 1-100)  
**Providers**: Gemini (topK), Anthropic

**Usage**:
- Lower values (1-10): More focused, deterministic
- Medium values (20-40): Balanced (Gemini default: 40)
- Higher values (50-100): More diverse
- 0: No restriction

**Example**:
```php
$options = array(
    'top_k' => 40,
);
```

### 2. Repetition Control

#### frequency_penalty
Reduces repetition by penalizing frequently used tokens.

**Type**: `float`  
**Range**: -2.0 to 2.0  
**Providers**: Gemini (frequencyPenalty), LM Studio

**Usage**:
- Positive values: Reduce repetition
- Negative values: Encourage repetition
- 0.0: No penalty

**Example**:
```php
$options = array(
    'frequency_penalty' => 0.5, // Reduce repetitive words
);
```

#### presence_penalty
Encourages new topics by penalizing any token that has appeared.

**Type**: `float`  
**Range**: -2.0 to 2.0  
**Providers**: Gemini (presencePenalty), LM Studio

**Usage**:
- Positive values: Encourage topic diversity
- Negative values: Stay on topic
- 0.0: No penalty

**Example**:
```php
$options = array(
    'presence_penalty' => 0.6, // Encourage new topics
);
```

### 3. Output Control

#### stop / stop_sequences
Sequences that will stop generation when encountered.

**Type**: `string` or `array` of strings  
**Providers**: Gemini (stopSequences, max 5), Anthropic (stop_sequences), LM Studio (stop)

**Example**:
```php
// Single stop sequence
$options = array(
    'stop' => "\n\nHuman:",
);

// Multiple stop sequences
$options = array(
    'stop_sequences' => array(
        "\n\nHuman:",
        "###",
        "END",
    ),
);
```

**Provider limits**:
- **Gemini**: Maximum 5 stop sequences
- **Anthropic**: No specific limit
- **LM Studio**: No specific limit

#### max_tokens / maxOutputTokens
Maximum number of tokens to generate.

**Type**: `integer`  
**All providers support this** (with different names)

**Provider mapping**:
- OpenAI: `max_tokens`, `max_completion_tokens`, or `max_output_tokens`
- Anthropic: `max_tokens`
- Gemini: `max_tokens` (mapped to `maxOutputTokens`)
- Ollama: `max_tokens` (mapped to `num_predict`)
- LM Studio: `max_tokens`

**Example**:
```php
$options = array(
    'max_tokens' => 2048,
);
```

### 4. Structured Output

#### response_format (LM Studio, Gemini)
Controls the format of the response.

**Type**: `object`  
**Providers**: LM Studio, Gemini (as responseMimeType/responseSchema)

**LM Studio Example** (OpenAI-compatible):
```php
$options = array(
    'response_format' => array(
        'type' => 'json_schema',
        'json_schema' => array(
            'name' => 'product_info',
            'strict' => true,
            'schema' => array(
                'type' => 'object',
                'properties' => array(
                    'name' => array('type' => 'string'),
                    'price' => array('type' => 'number'),
                    'in_stock' => array('type' => 'boolean'),
                ),
                'required' => array('name', 'price'),
            ),
        ),
    ),
);
```

**Gemini Example**:
```php
$options = array(
    'response_mime_type' => 'application/json',
    'response_schema' => array(
        'type' => 'object',
        'properties' => array(
            'name' => array('type' => 'string'),
            'price' => array('type' => 'number'),
        ),
    ),
);
```

### 5. Advanced Parameters

#### seed (LM Studio only)
For reproducible outputs.

**Type**: `integer`  
**Provider**: LM Studio only

**Example**:
```php
$options = array(
    'seed' => 12345,
    'temperature' => 0.7,
);
// Same seed + same temperature = same output
```

#### candidate_count (Gemini only)
Number of response variations to generate.

**Type**: `integer`  
**Range**: 1-8  
**Provider**: Gemini only (candidateCount)

**Example**:
```php
$options = array(
    'candidate_count' => 3, // Generate 3 variations
);
```

#### metadata (Anthropic only)
Request metadata for tracking and analytics.

**Type**: `object`  
**Provider**: Anthropic only

**Example**:
```php
$options = array(
    'metadata' => array(
        'user_id' => 'user_12345',
        'session_id' => 'sess_abcde',
        'request_source' => 'chat_widget',
    ),
);
```

## Usage in REST API

All parameters are passed via the `options` object in the request body:

```json
POST /wp-json/mcp-ai/v1/chat
{
  "assistant_id": 123,
  "messages": [
    {
      "role": "user",
      "content": "Write a creative story about robots."
    }
  ],
  "options": {
    "temperature": 0.8,
    "top_p": 0.95,
    "frequency_penalty": 0.5,
    "presence_penalty": 0.6,
    "max_tokens": 2048,
    "stop_sequences": ["###", "END"]
  }
}
```

## Provider Selection

The plugin automatically routes to the correct provider based on the assistant configuration:

```php
// In assistant configuration
'provider' => 'gemini',  // or 'anthropic', 'lm_studio', 'openai', 'ollama'
```

Parameters are automatically mapped to the provider's format:
- `top_p` → `topP` for Gemini
- `stop` → `stopSequences` for Gemini
- `stop` → `stop_sequences` for Anthropic
- etc.

## Best Practices

### 1. Temperature vs Top-P
**Don't use both together** (especially for Anthropic). Choose one:
- Use `temperature` for general randomness control
- Use `top_p` for fine-grained probability control

### 2. Repetition Control
Combine frequency and presence penalties for best results:
```php
$options = array(
    'frequency_penalty' => 0.5,  // Reduce word repetition
    'presence_penalty' => 0.6,   // Encourage topic variety
);
```

### 3. Stop Sequences
Use stop sequences to enforce structure:
```php
$options = array(
    'stop_sequences' => array(
        "\n\nUser:",
        "\n\nAssistant:",
    ),
);
```

### 4. Structured Output
Always provide clear schemas for JSON responses:
```php
$options = array(
    'response_format' => array(
        'type' => 'json_schema',
        'json_schema' => array(
            'strict' => true,  // Enforce strict validation
            'schema' => $your_schema,
        ),
    ),
);
```

## Migration Guide

### From Old to New Parameters

If you were using custom code to add parameters:

**Before** (manual):
```php
add_filter( 'wp_mcp_ai_chat_options', function( $options ) {
    // This no longer needed - built-in now!
    return $options;
});
```

**After** (built-in):
```php
// Just pass the parameters in options
$options = array(
    'top_p' => 0.9,
    'frequency_penalty' => 0.5,
    'stop_sequences' => array('###'),
);
```

### Updating Assistant Configurations

You can now store these parameters in assistant configurations:

```php
update_post_meta( $assistant_id, '_wp_mcp_ai_config', array(
    'provider' => 'gemini',
    'model' => 'gemini-3-pro',
    'temperature' => 0.7,
    'top_p' => 0.95,
    'frequency_penalty' => 0.3,
    'stop_sequences' => array('###'),
));
```

## Validation

All parameters are validated:

- **Range checks**: Values must be within valid ranges
- **Type checks**: Correct data types enforced
- **Provider checks**: Parameters are only sent if supported by the provider

Invalid parameters are **silently ignored** to prevent API errors.

## Logging

All parameters are logged for debugging (when logging is enabled):

```
LM_STUDIO_REQUEST: {
  "model": "qwen/qwen3-coder-30b",
  "temperature": 0.7,
  "top_p": 0.9,
  "frequency_penalty": 0.5,
  "presence_penalty": 0.6,
  "stream": false
}
```

## Security

All parameters are properly sanitized:
- Numeric values: Type cast and range validated
- Strings: `sanitize_text_field()`
- Arrays: `array_map('sanitize_text_field', $array)`
- Objects: Recursive sanitization

## Performance Considerations

- **Higher temperatures/top_p**: May require more tokens
- **Multiple candidates**: Increases costs (Gemini)
- **Stop sequences**: Can reduce token usage
- **Structured output**: May require more processing

## Troubleshooting

### Parameter Not Working?

1. **Check provider support**: Not all providers support all parameters
2. **Check parameter name**: Gemini uses camelCase (topP, topK, etc.)
3. **Check value range**: Values outside valid ranges are ignored
4. **Enable logging**: See what's actually being sent to the API

### Example Debug Check:
```php
// Enable logging
add_filter( 'wp_mcp_ai_enable_logging', '__return_true' );

// Make request
$response = $client->create_chat_completion( $messages, $options );

// Check logs in WP Admin → Settings → WP oOS
```

## Version History

- **November 2025**: Added Gemini 3 and Claude 4.1 parameter support
  - Gemini: topP, topK, stopSequences, frequencyPenalty, presencePenalty, candidateCount
  - Anthropic: top_p, top_k, stop_sequences, metadata
- **November 2025**: Added LM Studio comprehensive parameter support
- **Previous**: Basic temperature and max_tokens support

## References

- [Gemini API GenerationConfig](https://ai.google.dev/api/generate-content)
- [Anthropic Messages API](https://docs.anthropic.com/en/api/messages)
- [LM Studio OpenAI Compatibility](https://lmstudio.ai/docs/developer/openai-compat)
- [OpenAI Chat Completions](https://platform.openai.com/docs/api-reference/chat/create)
- [Ollama API](https://github.com/ollama/ollama/blob/main/docs/api.md)
