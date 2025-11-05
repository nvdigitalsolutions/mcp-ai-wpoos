# TPM Limit Validation and Model Fallback

## Overview

The WP oOS plugin now includes intelligent TPM (Tokens Per Minute) limit validation and automatic model fallback to prevent API rate limit errors and optimize model selection based on request size.

## Problem

API providers like OpenAI have TPM limits that vary by model and account tier. For example, `gpt-4o-mini` has a 200,000 TPM limit on Tier 1 accounts. When a request attempts to use more tokens than the limit allows, the API returns an error:

```
Request too large for gpt-4o-mini in organization org-XXX on tokens per min (TPM): 
Limit 200000, Requested 1172001. The input or output tokens must be reduced 
in order to run successfully.
```

This wastes time, costs money (failed API calls may still incur charges), and provides a poor user experience.

## Solution

WP oOS now performs **preemptive TPM validation** and **automatic model fallback** to prevent these errors.

### Key Features

1. **Preemptive Validation**: Checks TPM limits before making API calls
2. **Automatic Model Fallback**: Routes to higher-capacity models when needed
3. **Clear Error Messages**: Provides actionable guidance with suggested models
4. **Provider Agnostic**: Works with OpenAI, Gemini, Claude, and local models
5. **Cost Optimization**: Routes to appropriate models based on request size

## How It Works

### TPM Validation Flow

```
Request → Calculate Total Tokens (Input + Reserved Output)
       → Check Model's TPM Limit
       → If within limit: Proceed
       → If exceeds limit: Return error with suggestions OR fallback automatically
```

### Model Fallback Chain

When automatic routing is enabled, WP oOS follows this intelligent fallback chain:

#### For OpenAI Models

```
gpt-4o-mini (200k TPM)
  ↓ (if request > 200k tokens)
gpt-4o (30k TPM*)
  ↓ (if request > 30k tokens)
gemini-2.0-flash (1M TPM)
```

*Note: gpt-4o has only 30k TPM on Tier 1, so very large requests bypass it and go directly to Gemini.

#### For Gemini Models

Gemini models have very high TPM limits (1M), so fallback is rarely needed. If a request exceeds even Gemini's limits, the plugin returns an error with guidance to split the request.

#### For Claude Models

```
claude-3-haiku (50k TPM)
  ↓ (if request > 50k tokens)
gemini-2.0-flash (1M TPM)
```

### Token Budget Calculation

The plugin estimates total tokens as:

```
Total Tokens = Input Tokens + Reserved Output Tokens
```

- **Input Tokens**: Estimated from message content (~4 chars per token)
- **Reserved Output Tokens**: Based on `max_tokens` setting or 20% of available budget

## Usage

### Automatic Mode (Recommended)

By default, the plugin automatically handles TPM limits:

```php
$messages = array(
    array(
        'role'    => 'user',
        'content' => $large_content, // e.g., 500k tokens
    ),
);

$options = array(
    'model' => 'gpt-4o-mini', // Will auto-fallback to gemini-2.0-flash
);

$client = new WP_MCP_AI_Enhanced_OpenAI_Client();
$result = $client->create_chat_completion( $messages, $options );
```

The plugin will:
1. Detect that 500k tokens exceeds gpt-4o-mini's 200k limit
2. Check gpt-4o (30k) - still too large
3. Fallback to gemini-2.0-flash (1M TPM)
4. Log the fallback decision
5. Proceed with the request successfully

### Manual Validation

You can also validate TPM limits manually:

```php
$validation = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit(
    $messages,
    $model,
    $max_output_tokens
);

if ( is_wp_error( $validation ) ) {
    $error_data = $validation->get_error_data();
    $suggested  = $error_data['suggested_models'];
    
    // Retry with a suggested model
    $options['model'] = $suggested[0];
}
```

### Disabling Auto-Fallback

If you want strict model adherence without fallback:

```php
$options = array(
    'model'                => 'gpt-4o-mini',
    'disable_auto_routing' => true,
);

$result = $client->create_chat_completion( $messages, $options );

// If TPM limit exceeded, returns WP_Error with suggestions
// but does NOT automatically fallback
```

## Configuration

### Model TPM Limits

TPM limits are stored in the JetEngine CCT `ai_model_rate_limits` and include:

| Model | Provider | TPM Limit (Tier 1) | Context Window |
|-------|----------|-------------------|----------------|
| gpt-4o | OpenAI | 30,000 | 128k |
| gpt-4o-mini | OpenAI | 200,000 | 128k |
| gpt-4.1-mini | OpenAI | 400,000* | 1M |
| gpt-5-mini | OpenAI | 500,000* | 128k |
| gemini-1.5-flash | Google | 1,000,000 | 1M |
| gemini-2.0-flash | Google | 1,000,000 | 1M |
| claude-3-haiku | Anthropic | 50,000 | 200k |
| claude-3.5-sonnet | Anthropic | 40,000 | 200k |

*Future models (estimated limits)

### Updating TPM Limits

You can update TPM limits via:

1. **Admin UI**: Edit entries in the AI Model Rate Limits CCT
2. **Code Filter**: Use the `wp_mcp_ai_model_tpm_limit` filter
3. **Settings API**: Update model configuration programmatically

```php
add_filter( 'wp_mcp_ai_model_tpm_limit', function( $limit, $model ) {
    if ( $model === 'gpt-4o-mini' && is_premium_account() ) {
        return 400000; // Scale tier has higher limits
    }
    return $limit;
}, 10, 2 );
```

## Error Messages

### When TPM Limit Exceeded

```
Request too large for gpt-4o-mini. Limit: 200000 TPM, Requested: 1172001 tokens. 
Please reduce the input size, use a smaller max_tokens value, or switch to a 
model with higher limits.

Suggested models: gpt-4.1-mini, gemini-1.5-flash, gemini-2.0-flash
```

The error includes:
- **Model name**: Which model's limit was exceeded
- **TPM limit**: The maximum allowed
- **Requested tokens**: How much was attempted
- **Suggested models**: Alternative models that can handle the request
- **Error data**: Additional context for programmatic handling

## Logging

All TPM validation events are logged for monitoring:

```php
// Successful fallback
WP_MCP_AI_Logger::log_event(
    'model_routing_fallback',
    'Routing to alternative model due to TPM constraints.',
    array(
        'original_model' => 'gpt-4o-mini',
        'fallback_model' => 'gemini-2.0-flash',
        'reason'         => 'tpm_limit_exceeded',
    )
);

// TPM limit exceeded
WP_MCP_AI_Logger::log_error(
    'Request exceeds TPM limit for model.',
    array(
        'model'            => 'gpt-4o-mini',
        'tpm_limit'        => 200000,
        'requested_tokens' => 1172001,
        'input_tokens'     => 1156001,
        'reserved_output'  => 16000,
    )
);
```

View logs in: **Settings → WP oOS → Recent Activity / Recent Errors**

## Best Practices

### 1. Let Auto-Fallback Handle It

The default behavior is smart and cost-effective. Don't disable auto-routing unless you have specific requirements.

### 2. Set Appropriate max_tokens

The plugin reserves tokens for output based on `max_tokens`. Setting it too high can trigger unnecessary fallbacks:

```php
// Bad: Reserves 16k tokens even for short responses
$options['max_tokens'] = 16384;

// Good: Reserves appropriate amount
$options['max_tokens'] = 2048; // For most responses
```

### 3. Split Very Large Documents

For documents exceeding 1M tokens (even Gemini's limit), split them into chunks:

```php
$chunks = WP_MCP_AI_Token_Budget_Manager::split_document(
    $content,
    $chunk_size = 7000, // tokens per chunk
    $overlap = 200      // overlap between chunks
);

foreach ( $chunks as $chunk ) {
    $result = $client->create_chat_completion( ... );
}
```

### 4. Monitor Fallback Logs

Frequent fallbacks may indicate:
- Users submitting very large requests
- Need to upgrade to higher tier accounts
- Opportunity to optimize prompts

### 5. Use Gemini for Large Documents

Gemini has both high TPM limits (1M) and large context windows (up to 2M tokens). For document processing:

```php
$options = array(
    'model' => 'gemini-1.5-pro', // 2M context, 1M TPM
);
```

## Troubleshooting

### "Request too large" error persists

**Cause**: Auto-routing is disabled or all available models are too small

**Solution**:
1. Check if `disable_auto_routing` is set to `true`
2. Reduce input size or `max_tokens` value
3. Split request into multiple chunks
4. Use a model with higher limits manually (e.g., Gemini)

### Unexpected model used

**Cause**: Auto-fallback selected a different model

**Solution**: Check logs for `model_routing_fallback` events to understand why. If you need a specific model, set `disable_auto_routing: true`.

### Local models (Ollama) showing errors

**Cause**: Local models don't have TPM limits but validation may still run

**Solution**: The plugin automatically skips TPM validation for local models (those with `tpm_limit: 0` in the CCT). Ensure your local models are properly configured with `tpm_limit: 0`.

## API Reference

### WP_MCP_AI_Token_Budget_Manager

#### `validate_tpm_limit( $messages, $model, $max_output_tokens = 0 )`

Validates that a request is within the model's TPM limit.

**Parameters**:
- `$messages` (array): Chat messages array
- `$model` (string): Model identifier
- `$max_output_tokens` (int): Optional maximum output tokens

**Returns**: `true` on success, `WP_Error` if limit exceeded

#### `get_model_tpm_limit( $model )`

Retrieves the TPM limit for a model.

**Parameters**:
- `$model` (string): Model identifier

**Returns**: `int|null` - TPM limit or null if not configured

### WP_MCP_AI_Model_Selector

#### `select_model( $messages, $options = array(), $base_model = '' )`

Selects the appropriate model with TPM-aware fallback.

**Parameters**:
- `$messages` (array): Chat messages array
- `$options` (array): Request options
- `$base_model` (string): Default model

**Returns**: `string` - Selected model identifier

## Related Documentation

- [Token Budget Management](./token-budget-management.md)
- [Model Selection](./model-selection.md)
- [Rate Limiting](./rate-limiting.md)
- [Cost Optimization](./cost-optimization.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: See `docs/` directory
- Logs: Check Settings → WP oOS → Recent Activity
