# OpenAI API Stabilization Features

This document describes the OpenAI API stabilization features implemented to ensure reliable, cost-effective, and efficient usage of OpenAI services.

## Overview

The stabilization features include:

1. **Token Limits & Validation** - Enforce 12k token input limits
2. **Intelligent Model Routing** - Automatically select gpt-4o-mini or gpt-4o based on task complexity
3. **Rate Limiting & Backoff** - Exponential backoff (2s, 4s, 8s, up to 30s) for 429 errors
4. **Document Chunking** - Split large documents into 6-8k token chunks
5. **Output Token Management** - Explicit max_tokens settings for predictable responses

## Token Limits & Validation

### Input Token Limit

All requests are automatically validated to ensure they don't exceed 12,000 input tokens:

```php
$messages = array(
    array(
        'role'    => 'user',
        'content' => $user_message,
    ),
);

$validation = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, 'gpt-4o-mini' );

if ( is_wp_error( $validation ) ) {
    // Handle error - message exceeds 12k tokens
    $error_data = $validation->get_error_data();
    echo $error_data['used_tokens']; // Actual token count
    echo $error_data['max_tokens'];  // 12000
}
```

### Document Chunking

For large documents, use the recommended chunking strategy (6-8k tokens per chunk with 200 token overlap):

```php
$enhanced_client = new WP_MCP_AI_Enhanced_OpenAI_Client();

// Automatically uses 7k token chunks with 200 token overlap
$chunks = $enhanced_client->split_document( $large_content, 'gpt-4o-mini' );

// Custom chunk size
$chunks = $enhanced_client->split_document( $large_content, 'gpt-4o-mini', 8000, 300 );

// Process each chunk
foreach ( $chunks as $index => $chunk ) {
    $messages = array(
        array(
            'role'    => 'user',
            'content' => "Summarize part " . ( $index + 1 ) . ": " . $chunk,
        ),
    );
    
    $result = $enhanced_client->create_chat_completion( $messages );
}
```

## Intelligent Model Routing

The system automatically routes requests to the most appropriate model:

### Automatic Routing

```php
$enhanced_client = new WP_MCP_AI_Enhanced_OpenAI_Client();

// Simple query → routes to gpt-4o-mini
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'What is 2+2?',
    ),
);
$result = $enhanced_client->create_chat_completion( $messages );

// Complex query → routes to gpt-4o
$messages = array(
    array(
        'role'    => 'user',
        'content' => 'Please provide a detailed analysis of quantum computing trends.',
    ),
);
$result = $enhanced_client->create_chat_completion( $messages );
```

### Routing Criteria

Requests are routed to **gpt-4o** when:

1. **Token count > 4,000** - Long-form content
2. **Complex keywords detected**:
   - analyze, analysis, detailed, comprehensive, in-depth
   - thorough, research, complex, sophisticated, advanced
   - expert, professional, "write a long", "write an article"
3. **Multiple tools** - More than 3 tools in the request
4. **Structured output** - `response_format` is specified
5. **Explicit flag** - `use_advanced_model` option is true

All other requests route to **gpt-4o-mini** for cost efficiency.

### Manual Control

```php
// Disable auto-routing
$options = array(
    'disable_auto_routing' => true,
);
$result = $enhanced_client->create_chat_completion( $messages, $options );

// Force advanced model
$options = array(
    'use_advanced_model' => true,
);
$result = $enhanced_client->create_chat_completion( $messages, $options );

// Specify exact model
$options = array(
    'model' => 'gpt-4',
);
$result = $enhanced_client->create_chat_completion( $messages, $options );
```

## Rate Limiting & Exponential Backoff

The system automatically handles rate limits (429 errors) with exponential backoff:

### Default Configuration

- **Initial delay**: 2 seconds
- **Max delay**: 30 seconds
- **Max retries**: 3
- **Backoff multiplier**: 2x (exponential)
- **Progression**: 2s → 4s → 8s → (16s capped at 30s)

### Automatic Retry

```php
$enhanced_client = new WP_MCP_AI_Enhanced_OpenAI_Client();

// Automatically retries on 429 errors
$result = $enhanced_client->create_chat_completion( $messages );

if ( is_wp_error( $result ) ) {
    // Only returns error if all retries exhausted or non-retriable error
    echo $result->get_error_message();
}
```

### Custom Retry Configuration

```php
$options = array(
    'max_retries'   => 5,
    'initial_delay' => 3,
    'max_delay'     => 60,
);

$result = $enhanced_client->create_chat_completion( $messages, $options );
```

### Rate Limit State

Check if a service is currently rate-limited:

```php
$model = 'gpt-4o-mini';

if ( $enhanced_client->is_rate_limited( $model ) ) {
    $retry_after = $enhanced_client->get_retry_after( $model );
    echo "Rate limited until: " . date( 'Y-m-d H:i:s', $retry_after );
}
```

## Output Token Management

The system automatically calculates and sets `max_tokens` for predictable responses:

### Automatic Calculation

```php
// Automatically sets max_tokens based on:
// - Available token budget
// - 20% of remaining tokens (after input)
// - Capped at 4,096 tokens
// - Minimum of 512 tokens
$result = $enhanced_client->create_chat_completion( $messages );
```

### Manual Override

```php
$options = array(
    'max_tokens' => 2000,
);

$result = $enhanced_client->create_chat_completion( $messages, $options );
```

## Embeddings Configuration

The default embedding model is set to `text-embedding-3-small` for cost-effectiveness:

```php
$settings = WP_MCP_AI_Admin_Settings::get_settings();
$embedding_model = $settings['openai_embedding_model']; // 'text-embedding-3-small'
```

To use the higher quality model:

```php
// In wp-admin → Settings → WP oOS
// Set OpenAI Embedding Model to: text-embedding-3-large
```

## Filters & Hooks

### Customize Light Model

```php
add_filter( 'wp_mcp_ai_default_light_model', function( $model ) {
    return 'gpt-3.5-turbo'; // Use GPT-3.5 instead of gpt-4o-mini
} );
```

### Customize Advanced Model

```php
add_filter( 'wp_mcp_ai_default_advanced_model', function( $model ) {
    return 'gpt-4-turbo'; // Use GPT-4 Turbo instead of gpt-4o
} );
```

### Customize Chunk Size

```php
add_filter( 'wp_mcp_ai_recommended_chunk_size', function( $size, $model ) {
    return 8000; // Use 8k token chunks
}, 10, 2 );
```

### Customize Retry Settings

```php
add_filter( 'wp_mcp_ai_max_retries', function( $retries, $options ) {
    return 5; // Increase max retries to 5
}, 10, 2 );

add_filter( 'wp_mcp_ai_initial_retry_delay', function( $delay, $options ) {
    return 3; // Start with 3 second delay
}, 10, 2 );

add_filter( 'wp_mcp_ai_max_retry_delay', function( $delay, $options ) {
    return 60; // Allow up to 60 second delay
}, 10, 2 );
```

## Best Practices

### 1. Use Enhanced Client

Always use `WP_MCP_AI_Enhanced_OpenAI_Client` instead of the base client to get stabilization features:

```php
// Good
$client = new WP_MCP_AI_Enhanced_OpenAI_Client();
$result = $client->create_chat_completion( $messages );

// Less optimal
$client = new WP_MCP_AI_OpenAI_Client();
$result = $client->create_chat_completion( $messages );
```

### 2. Pre-validate Large Inputs

Before processing large documents, validate token counts:

```php
$validation = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, $model );

if ( is_wp_error( $validation ) ) {
    // Split into chunks before sending
    $chunks = $enhanced_client->split_document( $content, $model );
}
```

### 3. Let Auto-routing Work

Trust the intelligent routing unless you have specific requirements:

```php
// Good - Let the system decide
$result = $enhanced_client->create_chat_completion( $messages );

// Only when necessary
$result = $enhanced_client->create_chat_completion( $messages, array(
    'model' => 'gpt-4o', // Explicit override
) );
```

### 4. Monitor Token Usage

Use the token budget manager to understand your usage:

```php
$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( $model, $messages );

WP_MCP_AI_Logger::log_event( 'token_usage', 'Token budget info', array(
    'used'      => $budget['used'],
    'available' => $budget['available'],
    'limit'     => $budget['limit'],
) );
```

## Monitoring & Debugging

### Log Events

The system logs important events for monitoring:

- `model_routing_light` - Request routed to gpt-4o-mini
- `model_routing_complex` - Request routed to gpt-4o (with reason)
- `max_tokens_calculated` - Output token limit calculated
- `api_retry_scheduled` - Retry scheduled after error
- `rate_limit_stored` - Rate limit state saved
- `token_budget_calculated` - Token budget computed
- `token_budget_optimization` - Messages optimized for budget

### Check Logs

```php
// View logs in wp-admin → Tools → WP oOS Logs
// Or programmatically
$logs = WP_MCP_AI_Logger::get_recent_logs();
```

## Constants Reference

### Token Budget Manager

```php
WP_MCP_AI_Token_Budget_Manager::MAX_INPUT_TOKENS      // 12000
WP_MCP_AI_Token_Budget_Manager::DEFAULT_CHUNK_SIZE    // 7000
WP_MCP_AI_Token_Budget_Manager::DEFAULT_CHUNK_OVERLAP // 200
WP_MCP_AI_Token_Budget_Manager::MIN_CHUNK_SIZE        // 1000
WP_MCP_AI_Token_Budget_Manager::DEFAULT_SAFETY_MARGIN // 0.1
```

### Rate Limit Manager

```php
WP_MCP_AI_Rate_Limit_Manager::DEFAULT_INITIAL_DELAY  // 2
WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_DELAY      // 30
WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_RETRIES    // 3
WP_MCP_AI_Rate_Limit_Manager::BACKOFF_MULTIPLIER     // 2
```

### Model Selector

```php
WP_MCP_AI_Model_Selector::LONG_FORM_TOKEN_THRESHOLD  // 4000
```

## Troubleshooting

### Input Token Limit Errors

**Error**: `wp_mcp_ai_input_tokens_exceeded`

**Solution**: Split your content into chunks:

```php
$chunks = $enhanced_client->split_document( $content, $model );
foreach ( $chunks as $chunk ) {
    $result = $enhanced_client->create_chat_completion( array(
        array( 'role' => 'user', 'content' => $chunk ),
    ) );
}
```

### Unexpected Model Selection

**Issue**: System not routing to expected model

**Solution**: Check routing criteria and use explicit model if needed:

```php
// Debug routing decision
$messages = array( /* your messages */ );
$selected_model = WP_MCP_AI_Model_Selector::select_model( $messages, array() );
echo "Selected: " . $selected_model;

// Override if necessary
$options = array( 'model' => 'gpt-4o' );
```

### Rate Limit Exhaustion

**Issue**: Continuous 429 errors

**Solution**: Check rate limit state and wait:

```php
if ( $enhanced_client->is_rate_limited( $model ) ) {
    $retry_after = $enhanced_client->get_retry_after( $model );
    $wait_seconds = $retry_after - time();
    echo "Please wait {$wait_seconds} seconds";
}
```

## Performance Impact

The stabilization features add minimal overhead:

- **Token validation**: ~1-5ms per request
- **Model routing**: ~1-3ms per request
- **Rate limit check**: ~0.5ms per request
- **Total overhead**: ~2-10ms per request

This is negligible compared to typical API response times (500ms-5s).

## Migration Guide

### From Base Client

```php
// Before
$client = new WP_MCP_AI_OpenAI_Client();
$result = $client->create_chat_completion( $messages, array(
    'model' => 'gpt-4o-mini',
) );

// After
$client = new WP_MCP_AI_Enhanced_OpenAI_Client();
$result = $client->create_chat_completion( $messages );
// Model auto-selected, max_tokens auto-set, retries automatic
```

### No Breaking Changes

All existing code continues to work. The enhancements are additive and backwards-compatible.
