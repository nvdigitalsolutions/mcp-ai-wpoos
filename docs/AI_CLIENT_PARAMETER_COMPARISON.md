# AI Provider Client Parameter Support Comparison

## Summary

This document compares parameter support across all AI provider clients in WP oOS.

**Last Updated**: November 2025 (with 2025 API updates for all providers)

## Parameter Support Matrix

| Parameter | OpenAI | Anthropic | Gemini | Ollama | LM Studio |
|-----------|--------|-----------|--------|--------|-----------|
| `temperature` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `response_format` | ✅ | ❌ | ✅ | ❌ | ✅ |
| `top_p` | ❌ | ✅ | ✅ | ❌ | ✅ |
| `frequency_penalty` | ❌ | ❌ | ✅ | ❌ | ✅ |
| `presence_penalty` | ❌ | ❌ | ✅ | ❌ | ✅ |
| `seed` | ❌ | ❌ | ❌ | ❌ | ✅ |
| `stop` / `stop_sequences` | ❌ | ✅ | ✅ | ❌ | ✅ |
| `top_k` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `candidate_count` | ❌ | ❌ | ✅ | ❌ | ❌ |
| `metadata` | ❌ | ✅ | ❌ | ❌ | ❌ |

### Status

- ✅ = Fully supported (as of November 2025)
- ❌ = Not currently implemented
- 🟡 = Partially supported

## Detailed Analysis

### 1. OpenAI Client

**File**: `includes/class-wp-mcp-ai-openai-client.php`

**Supported Parameters**:
- ✅ `temperature` - Temperature sampling (0-2)
- ✅ `response_format` - For images only (b64_json, url)
- ✅ `max_tokens` / `max_completion_tokens` / `max_output_tokens`
- ✅ `tools` - Function calling
- ✅ `system_prompt` - System messages

**Missing Parameters** (that OpenAI API supports):
- ❌ `top_p` - Nucleus sampling
- ❌ `frequency_penalty` - Token frequency penalty
- ❌ `presence_penalty` - Token presence penalty  
- ❌ `seed` - Reproducible outputs
- ❌ `stop` - Stop sequences
- ❌ `response_format` for chat completions (json_object, json_schema)
- ❌ `logit_bias` - Token bias
- ❌ `logprobs` - Log probabilities
- ❌ `top_logprobs` - Top log probabilities
- ❌ `n` - Number of completions
- ❌ `user` - End-user identifier

### 2. Anthropic Client (ENHANCED - November 2025)

**File**: `includes/class-wp-mcp-ai-anthropic-client.php`

**Supported Parameters**:
- ✅ `temperature` - Temperature sampling (0-1)
- ✅ `max_tokens` - Maximum output tokens
- ✅ `tools` - Function calling (via Anthropic format)
- ✅ `system_prompt` - System messages
- ✅ **`top_p`** - Nucleus sampling (0-1) **[NEW]**
- ✅ **`top_k`** - Top-K sampling **[NEW]**
- ✅ **`stop_sequences`** - Stop sequences (array) **[NEW]**
- ✅ **`metadata`** - Request metadata (object) **[NEW]**

**Note**: Anthropic recommends changing either `temperature` OR `top_p`, not both.

### 3. Gemini Client (ENHANCED - November 2025)

**File**: `includes/class-wp-mcp-ai-gemini-client.php`

**Supported Parameters**:
- ✅ `temperature` - Temperature sampling
- ✅ `maxOutputTokens` (via max_tokens)
- ✅ `tools` - Function calling (via Gemini format)
- ✅ `system_prompt` - System instructions
- ✅ `responseMimeType` - Response format
- ✅ `responseSchema` / `responseJsonSchema` - Structured output
- ✅ **`topP`** - Nucleus sampling (0-1) **[NEW]**
- ✅ **`topK`** - Top-K sampling **[NEW]**
- ✅ **`stopSequences`** - Stop sequences (up to 5) **[NEW]**
- ✅ **`frequencyPenalty`** - Frequency penalty (-2 to 2) **[NEW]**
- ✅ **`presencePenalty`** - Presence penalty (-2 to 2) **[NEW]**
- ✅ **`candidateCount`** - Number of response variations (1-8) **[NEW]**

### 4. Ollama Client

**File**: `includes/class-wp-mcp-ai-ollama-client.php`

**Supported Parameters**:
- ✅ `temperature` - Temperature sampling
- ✅ `max_tokens` (mapped to num_predict)
- ✅ `tools` - Function calling
- ✅ `system_prompt` - System messages

**Missing Parameters** (that Ollama API supports):
- ❌ `top_p` - Nucleus sampling
- ❌ `top_k` - Top-K sampling
- ❌ `repeat_penalty` - Repetition penalty
- ❌ `seed` - Reproducible outputs
- ❌ `stop` - Stop sequences
- ❌ `num_ctx` - Context window size
- ❌ `num_keep` - Tokens to keep
- ❌ `tfs_z` - Tail free sampling

### 5. LM Studio Client (ENHANCED - November 2025)

**File**: `includes/class-wp-mcp-ai-lm-studio-client.php`

**Supported Parameters**:
- ✅ `temperature` - Temperature sampling (0-2)
- ✅ `top_p` - Nucleus sampling (0-1)
- ✅ `frequency_penalty` - Token frequency penalty (-2 to 2)
- ✅ `presence_penalty` - Token presence penalty (-2 to 2)
- ✅ `seed` - Reproducible outputs
- ✅ `stop` - Stop sequences (string or array)
- ✅ `response_format` - Structured output (text, json_object, json_schema)
- ✅ `max_tokens`
- ✅ `tools` - Function calling
- ✅ `system_prompt` - System messages

**Note**: LM Studio has the MOST comprehensive OpenAI-compatible parameter support!

## Summary of November 2025 Enhancements

### Gemini Client
Added 6 new parameters from Gemini 3 API:
- `topP` - Nucleus sampling for controlled randomness
- `topK` - Top-K sampling (Google's preferred method)
- `stopSequences` - Up to 5 custom stop sequences
- `frequencyPenalty` - Reduce token repetition
- `presencePenalty` - Encourage topic diversity
- `candidateCount` - Generate multiple response variations

### Anthropic Client  
Added 4 new parameters from Claude 4.1 Messages API:
- `top_p` - Nucleus sampling (use instead of temperature for fine control)
- `top_k` - Top-K sampling (Anthropic's advanced sampling)
- `stop_sequences` - Custom stop sequences
- `metadata` - Request tracking and analytics

### LM Studio Client
Already enhanced with full parameter support (completed earlier)

## Recommendations

### High Priority Enhancements

#### 1. OpenAI Client
Add missing OpenAI-compatible parameters:
- `top_p` - Widely used for controlling randomness
- `frequency_penalty` - Common for reducing repetition
- `presence_penalty` - Common for topic diversity
- `seed` - Useful for testing and reproducibility
- `stop` - Important for controlling output
- `response_format` for chat (not just images) - Critical for structured output

#### 2. Anthropic Client
Add Anthropic-specific parameters:
- `top_p` - Supported by Anthropic API
- `top_k` - Anthropic's preferred sampling method
- `stop_sequences` - Important for controlling output

#### 3. Gemini Client
Add Gemini generationConfig parameters:
- `topP` - Nucleus sampling
- `topK` - Top-K sampling
- `stopSequences` - Stop sequences
- `responseMimeType` - For structured output
- `responseSchema` - For JSON schema validation

#### 4. Ollama Client
Add Ollama-specific parameters:
- `top_p` - Common sampling parameter
- `top_k` - Common sampling parameter
- `seed` - For reproducibility
- `stop` - Stop sequences
- `repeat_penalty` - Useful for reducing repetition

### Implementation Priority

**Phase 1: Critical Parameters** (All providers)
1. `top_p` - Most widely used after temperature
2. `stop` / `stop_sequences` - Important for output control
3. `seed` - Needed for reproducibility/testing

**Phase 2: Quality Parameters** (OpenAI, Anthropic compatible)
1. `frequency_penalty` - For reducing repetition
2. `presence_penalty` - For topic diversity
3. `top_k` - For Anthropic and Gemini

**Phase 3: Advanced Features**
1. `response_format` / `response_schema` - For all providers that support it
2. Provider-specific optimizations
3. Advanced sampling parameters

## Parameter Mapping Guide

Different providers use different parameter names for similar functionality:

| Function | OpenAI/LM Studio | Anthropic | Gemini | Ollama |
|----------|------------------|-----------|--------|--------|
| Temperature | `temperature` | `temperature` | `temperature` | `temperature` |
| Nucleus Sampling | `top_p` | `top_p` | `topP` | `top_p` |
| Top-K Sampling | N/A | `top_k` | `topK` | `top_k` |
| Max Output | `max_tokens` | `max_tokens` | `maxOutputTokens` | `num_predict` |
| Stop Sequences | `stop` (array) | `stop_sequences` (array) | `stopSequences` (array) | `stop` (array) |
| Repetition Control | `frequency_penalty` | N/A | `frequencyPenalty` | `repeat_penalty` |
| Diversity | `presence_penalty` | N/A | `presencePenalty` | N/A |
| Reproducibility | `seed` | N/A | N/A | `seed` |
| Structured Output | `response_format` | N/A | `responseMimeType` | N/A |

## Code Examples

### Example: Adding top_p to OpenAI Client

```php
// In build_payload() method
if ( isset( $options['top_p'] ) && '' !== $options['top_p'] && null !== $options['top_p'] ) {
    $top_p = (float) $options['top_p'];
    // Validate top_p is between 0 and 1
    if ( $top_p >= 0 && $top_p <= 1 ) {
        $payload['top_p'] = $top_p;
    }
}
```

### Example: Adding stop sequences to Anthropic Client

```php
// In build_payload() method
if ( ! empty( $options['stop'] ) || ! empty( $options['stop_sequences'] ) ) {
    $stop = $options['stop_sequences'] ?? $options['stop'] ?? array();
    
    if ( is_string( $stop ) ) {
        $stop = array( $stop );
    }
    
    if ( is_array( $stop ) && ! empty( $stop ) ) {
        $payload['stop_sequences'] = array_map( 'sanitize_text_field', $stop );
    }
}
```

### Example: Adding topP to Gemini Client

```php
// In generation_config
if ( isset( $options['top_p'] ) && '' !== $options['top_p'] && null !== $options['top_p'] ) {
    $top_p = (float) $options['top_p'];
    if ( $top_p >= 0 && $top_p <= 1 ) {
        $generation_config['topP'] = $top_p;
    }
}
```

## Testing Checklist

When adding parameters to a client:

- [ ] Validate parameter values (ranges, types)
- [ ] Add to logging context for debugging
- [ ] Update client documentation
- [ ] Add tests for parameter handling
- [ ] Verify provider API documentation
- [ ] Test with actual provider API
- [ ] Check backward compatibility
- [ ] Update parameter support matrix

## Related Documentation

- [OpenAI API Parameters](https://platform.openai.com/docs/api-reference/chat/create)
- [Anthropic API Parameters](https://docs.anthropic.com/en/api/messages)
- [Gemini API Parameters](https://ai.google.dev/api/generate-content)
- [Ollama API Parameters](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [LM Studio API](https://lmstudio.ai/docs/developer/openai-compat)

## Version History

- **2025-11-22**: LM Studio client enhanced with full OpenAI-compatible parameter support
- **Previous**: All clients had basic temperature and max_tokens support
