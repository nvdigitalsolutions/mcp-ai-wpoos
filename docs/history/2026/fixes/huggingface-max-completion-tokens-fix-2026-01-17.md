# Hugging Face max_completion_tokens Fix

## Issue
When using Hugging Face as a provider with the `qwen3-coder-30b-a3b-instruct` model, users encountered the following error:

```
ERROR: Hugging Face returned an error response.
Context details
{
    "code": 400,
    "body": {
        "status": 400,
        "error": "BAD REQUEST",
        "message": "payload validation: max_completion_tokens is limited to 8192 for qwen3-coder-30b-a3b-instruct"
    }
}
```

## Root Cause
1. The plugin was using the older `max_tokens` parameter instead of the OpenAI-compatible `max_completion_tokens` parameter that Hugging Face Inference API expects
2. The Resource Manager could return values up to 32,000 tokens for high-tier systems, which exceeded the model's limit of 8,192 tokens
3. Model-specific output token limits were not being enforced in the model configuration

## Solution

### 1. Updated Hugging Face Client (`includes/class-wp-mcp-ai-huggingface-client.php`)
Changed the `build_payload()` method to:
- Use `max_completion_tokens` parameter instead of `max_tokens` (OpenAI-compatible)
- Check model configuration for `max_completion_tokens` limit
- Enforce model-specific limits using `min()` function
- Apply limits to both explicit and Resource Manager-derived token values

```php
// Get model-specific limit from model config.
$model_config = WP_MCP_AI_Model_Config::get_model_config( $model );
if ( $model_config && isset( $model_config['max_completion_tokens'] ) ) {
    $model_limit = absint( $model_config['max_completion_tokens'] );
    // Respect model limit.
    $max_tokens = min( $max_tokens, $model_limit );
}

// Hugging Face Inference API uses max_completion_tokens (OpenAI-compatible).
$payload['max_completion_tokens'] = $max_tokens;
```

### 2. Updated Model Configuration (`includes/class-wp-mcp-ai-model-config.php`)
- Added new model entry: `Qwen/Qwen3-Coder-30B-A3B-Instruct` with `max_completion_tokens: 8192`
- Updated all Qwen models to include `max_completion_tokens: 8192`:
  - `Qwen/Qwen2.5-72B-Instruct`
  - `Qwen/Qwen2.5-32B-Instruct`
  - `Qwen/Qwen2.5-7B-Instruct`
- Added `max_completion_tokens` to the sanitize_config integer fields list

### 3. Created Comprehensive Tests (`tests/test-huggingface-max-completion-tokens.php`)
Added 5 test cases to ensure:
1. `max_completion_tokens` is used instead of `max_tokens`
2. Model limit (8192) is enforced when requesting more tokens
3. Limit is enforced even with Resource Manager defaults
4. All Qwen models have `max_completion_tokens` in their config
5. Explicit lower values are respected

## Technical Details

### Parameter Mapping
- **Old**: `max_tokens` (Hugging Face-specific)
- **New**: `max_completion_tokens` (OpenAI-compatible)

Hugging Face Inference API has adopted the OpenAI-compatible `max_completion_tokens` parameter, which specifically determines the maximum number of tokens the model can generate for the completion, excluding the prompt.

### Model Limits
Based on research and Hugging Face documentation:
- **Qwen3-Coder-30B-A3B-Instruct**: 8,192 max completion tokens
- **Context Window**: 32,768 tokens (total including prompt and output)
- **Output Limit**: 8,192 tokens (enforced by the API)

### Token Budget Hierarchy
1. **User-specified** (via options): Highest priority if explicitly set
2. **Model limit** (from model config): Enforced cap
3. **Resource Manager** (system-based): Default when not specified

The fix ensures: `final_tokens = min(requested_tokens, model_limit)`

## Testing
Run the test suite to verify the fix:
```bash
composer run test -- --filter=test_huggingface_max_completion_tokens
```

Or run all Hugging Face client tests:
```bash
composer run test -- --group=huggingface-client
```

## Migration Notes
- **Backward Compatible**: Existing code using `max_tokens` option will automatically be capped at model limits
- **No Breaking Changes**: The fix only adds enforcement, doesn't remove functionality
- **Automatic**: No configuration changes needed by users

## Future Enhancements
Consider adding `max_completion_tokens` limits for other Hugging Face models:
- Llama models
- Mistral models
- Other Qwen variants

## References
- [Hugging Face Inference API Documentation](https://huggingface.co/docs/api-inference)
- [Qwen3-Coder-30B-A3B-Instruct Model Card](https://huggingface.co/Qwen/Qwen3-Coder-30B-A3B-Instruct)
- [OpenAI API max_completion_tokens Parameter](https://platform.openai.com/docs/api-reference/chat/create#chat-create-max_completion_tokens)

## Date
January 17, 2026
