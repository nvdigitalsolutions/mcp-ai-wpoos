# Dynamic Configuration Filters

This document describes all WordPress filters available in WP oOS that allow dynamic configuration of previously hardcoded values. These filters enable advanced customization without modifying core plugin files.

## Overview

All hardcoded constants, timeouts, delays, and default URLs in the plugin can now be overridden using WordPress filters. This allows for:

- Dynamic adjustment to different hosting environments
- Custom configuration for specific use cases
- Easy testing with different values
- Environment-specific settings

## Table of Contents

- [SSE Stream Configuration](#sse-stream-configuration)
- [Rate Limit Manager Configuration](#rate-limit-manager-configuration)
- [Token Budget Manager Configuration](#token-budget-manager-configuration)
- [Default Endpoint URLs](#default-endpoint-urls)
- [Gmail OAuth Endpoints](#gmail-oauth-endpoints)
- [Other Timing Filters](#other-timing-filters)
- [Usage Examples](#usage-examples)

---

## SSE Stream Configuration

### `wp_mcp_ai_sse_max_duration`

Filter the maximum SSE connection duration.

**Default:** `300` (5 minutes)  
**Since:** 1.0.0

**Parameters:**
- `$max_duration` (int) - Maximum connection duration in seconds.

**Example:**
```php
// Increase max duration to 10 minutes
add_filter( 'wp_mcp_ai_sse_max_duration', function( $duration ) {
    return 600;
} );
```

---

### `wp_mcp_ai_sse_poll_interval`

Filter the SSE polling interval.

**Default:** `2` (2 seconds)  
**Since:** 1.0.0

**Parameters:**
- `$poll_interval` (int) - Polling interval in seconds.

**Example:**
```php
// Poll every 5 seconds for reduced server load
add_filter( 'wp_mcp_ai_sse_poll_interval', function( $interval ) {
    return 5;
} );
```

---

### `wp_mcp_ai_sse_heartbeat_interval`

Filter the SSE heartbeat interval.

**Default:** `15` (15 seconds)  
**Since:** 1.0.0

**Parameters:**
- `$heartbeat_interval` (int) - Heartbeat interval in seconds.

**Example:**
```php
// Send heartbeat every 30 seconds
add_filter( 'wp_mcp_ai_sse_heartbeat_interval', function( $interval ) {
    return 30;
} );
```

---

## Rate Limit Manager Configuration

### `wp_mcp_ai_rate_limit_max_retries`

Filter the default maximum number of retry attempts.

**Default:** `3`  
**Since:** 1.0.0

**Parameters:**
- `$max_retries` (int) - Maximum number of retries.

**Example:**
```php
// Increase retries for more robust error handling
add_filter( 'wp_mcp_ai_rate_limit_max_retries', function( $retries ) {
    return 5;
} );
```

---

### `wp_mcp_ai_rate_limit_initial_delay`

Filter the default initial retry delay in seconds.

**Default:** `2` (2 seconds)  
**Since:** 1.0.0

**Parameters:**
- `$initial_delay` (int) - Initial delay in seconds.

**Example:**
```php
// Start with a 5-second delay
add_filter( 'wp_mcp_ai_rate_limit_initial_delay', function( $delay ) {
    return 5;
} );
```

---

### `wp_mcp_ai_rate_limit_max_delay`

Filter the default maximum retry delay in seconds.

**Default:** `30` (30 seconds)  
**Since:** 1.0.0

**Parameters:**
- `$max_delay` (int) - Maximum delay in seconds.

**Example:**
```php
// Allow up to 60 seconds delay
add_filter( 'wp_mcp_ai_rate_limit_max_delay', function( $delay ) {
    return 60;
} );
```

---

### `wp_mcp_ai_rate_limit_backoff_multiplier`

Filter the exponential backoff multiplier.

**Default:** `2`  
**Since:** 1.0.0

**Parameters:**
- `$backoff_multiplier` (int) - Backoff multiplier.

**Example:**
```php
// Use a 3x multiplier for faster exponential growth
add_filter( 'wp_mcp_ai_rate_limit_backoff_multiplier', function( $multiplier ) {
    return 3;
} );
```

---

## Token Budget Manager Configuration

### `wp_mcp_ai_token_budget_safety_margin`

Filter the default token budget safety margin.

**Default:** `0.1` (10%)  
**Since:** 1.0.0

**Parameters:**
- `$safety_margin` (float) - Safety margin percentage (0-1).

**Example:**
```php
// Use a 20% safety margin
add_filter( 'wp_mcp_ai_token_budget_safety_margin', function( $margin ) {
    return 0.2;
} );
```

---

### `wp_mcp_ai_token_budget_min_chunk_size`

Filter the minimum chunk size for document splitting.

**Default:** `1000`  
**Since:** 1.0.0

**Parameters:**
- `$min_chunk_size` (int) - Minimum chunk size in tokens.

**Example:**
```php
// Increase minimum chunk size
add_filter( 'wp_mcp_ai_token_budget_min_chunk_size', function( $size ) {
    return 2000;
} );
```

---

### `wp_mcp_ai_token_budget_max_input_tokens`

Filter the maximum input tokens limit.

**Default:** `12000`  
**Since:** 1.0.0

**Parameters:**
- `$max_input_tokens` (int) - Maximum input tokens.

**Example:**
```php
// Increase max input tokens for larger models
add_filter( 'wp_mcp_ai_token_budget_max_input_tokens', function( $tokens ) {
    return 20000;
} );
```

---

### `wp_mcp_ai_token_budget_default_limit`

Filter the default token limit fallback for unknown models.

**Default:** `8192`  
**Since:** 1.0.0

**Parameters:**
- `$default_limit` (int) - Default token limit.
- `$model` (string) - Model identifier.

**Example:**
```php
// Set a higher default for unknown models
add_filter( 'wp_mcp_ai_token_budget_default_limit', function( $limit, $model ) {
    // Use higher limit for custom models
    if ( strpos( $model, 'custom-' ) === 0 ) {
        return 16000;
    }
    return $limit;
}, 10, 2 );
```

---

## Default Endpoint URLs

### `wp_mcp_ai_default_ollama_endpoint_url`

Filter the default Ollama endpoint URL.

**Default:** `http://localhost:11434`  
**Since:** 1.0.0

**Parameters:**
- `$url` (string) - Default URL.

**Example:**
```php
// Use a remote Ollama server
add_filter( 'wp_mcp_ai_default_ollama_endpoint_url', function( $url ) {
    return 'http://ollama-server.local:11434';
} );
```

---

### `wp_mcp_ai_default_lm_studio_endpoint_url`

Filter the default LM Studio endpoint URL.

**Default:** `http://localhost:1234/v1`  
**Since:** 1.0.0

**Parameters:**
- `$url` (string) - Default URL.

**Example:**
```php
// Use a remote LM Studio server
add_filter( 'wp_mcp_ai_default_lm_studio_endpoint_url', function( $url ) {
    return 'http://lm-studio-server.local:1234/v1';
} );
```

---

### `wp_mcp_ai_default_wpcom_userinfo_endpoint`

Filter the default WordPress.com userinfo endpoint URL.

**Default:** `https://public-api.wordpress.com/oauth2/userinfo`  
**Since:** 1.0.0

**Parameters:**
- `$url` (string) - Default URL.

**Example:**
```php
// Use a custom WordPress.com API endpoint
add_filter( 'wp_mcp_ai_default_wpcom_userinfo_endpoint', function( $url ) {
    return 'https://custom-wpcom-api.example.com/userinfo';
} );
```

---

## Gmail OAuth Endpoints

### `wp_mcp_ai_gmail_oauth_scope`

Filter the Gmail OAuth scope.

**Default:** `https://www.googleapis.com/auth/gmail.readonly`  
**Since:** 1.0.0

**Parameters:**
- `$scope` (string) - OAuth scope.

**Example:**
```php
// Request modify permissions
add_filter( 'wp_mcp_ai_gmail_oauth_scope', function( $scope ) {
    return 'https://www.googleapis.com/auth/gmail.modify';
} );
```

---

### `wp_mcp_ai_gmail_oauth_authorize_endpoint`

Filter the Gmail OAuth authorize endpoint.

**Default:** `https://accounts.google.com/o/oauth2/v2/auth`  
**Since:** 1.0.0

**Parameters:**
- `$endpoint` (string) - OAuth authorize endpoint.

**Example:**
```php
// Use a custom OAuth provider
add_filter( 'wp_mcp_ai_gmail_oauth_authorize_endpoint', function( $endpoint ) {
    return 'https://custom-oauth.example.com/authorize';
} );
```

---

### `wp_mcp_ai_gmail_oauth_token_endpoint`

Filter the Gmail OAuth token endpoint.

**Default:** `https://oauth2.googleapis.com/token`  
**Since:** 1.0.0

**Parameters:**
- `$endpoint` (string) - OAuth token endpoint.

**Example:**
```php
// Use a custom token endpoint
add_filter( 'wp_mcp_ai_gmail_oauth_token_endpoint', function( $endpoint ) {
    return 'https://custom-oauth.example.com/token';
} );
```

---

### `wp_mcp_ai_gmail_profile_endpoint`

Filter the Gmail profile endpoint.

**Default:** `https://gmail.googleapis.com/gmail/v1/users/me/profile`  
**Since:** 1.0.0

**Parameters:**
- `$endpoint` (string) - Gmail profile endpoint.

**Example:**
```php
// Use a custom profile endpoint
add_filter( 'wp_mcp_ai_gmail_profile_endpoint', function( $endpoint ) {
    return 'https://custom-gmail-api.example.com/profile';
} );
```

---

## Other Timing Filters

### `wp_mcp_ai_federation_peer_verification_delay`

Filter the delay between peer verification requests.

**Default:** `100000` (100ms in microseconds)  
**Since:** 1.0.0

**Parameters:**
- `$delay_microseconds` (int) - Delay in microseconds.

**Example:**
```php
// Increase delay to 200ms to reduce server load
add_filter( 'wp_mcp_ai_federation_peer_verification_delay', function( $delay ) {
    return 200000;
} );
```

---

## Usage Examples

### Environment-Specific Configuration

```php
// In your theme's functions.php or a custom plugin

// Production: Use conservative settings
if ( defined( 'WP_ENV' ) && 'production' === WP_ENV ) {
    add_filter( 'wp_mcp_ai_rate_limit_max_retries', function() {
        return 5;
    } );
    
    add_filter( 'wp_mcp_ai_token_budget_safety_margin', function() {
        return 0.15; // 15% safety margin
    } );
}

// Development: Use faster polling for testing
if ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) {
    add_filter( 'wp_mcp_ai_sse_poll_interval', function() {
        return 1; // Poll every second
    } );
    
    add_filter( 'wp_mcp_ai_sse_max_duration', function() {
        return 120; // 2 minutes for testing
    } );
}
```

### Custom Model Support

```php
// Add custom model limits dynamically
add_filter( 'wp_mcp_ai_token_budget_default_limit', function( $limit, $model ) {
    // Define limits for custom models
    $custom_limits = array(
        'claude-3-opus-20240229'   => 200000,
        'claude-3-sonnet-20240229' => 200000,
        'llama-3-70b'              => 8192,
    );
    
    if ( isset( $custom_limits[ $model ] ) ) {
        return $custom_limits[ $model ];
    }
    
    return $limit;
}, 10, 2 );
```

### Remote Server Configuration

```php
// Use remote AI servers instead of localhost
add_filter( 'wp_mcp_ai_default_ollama_endpoint_url', function() {
    return 'http://ai-server-01.internal:11434';
} );

add_filter( 'wp_mcp_ai_default_lm_studio_endpoint_url', function() {
    return 'http://ai-server-02.internal:1234/v1';
} );
```

### Performance Tuning

```php
// Optimize for high-traffic sites
add_filter( 'wp_mcp_ai_sse_poll_interval', function() {
    return 5; // Reduce polling frequency
} );

add_filter( 'wp_mcp_ai_rate_limit_initial_delay', function() {
    return 3; // Slower initial retry
} );

add_filter( 'wp_mcp_ai_federation_peer_verification_delay', function() {
    return 250000; // 250ms delay
} );
```

---

## Best Practices

1. **Add filters early**: Use `init` or earlier hooks to ensure filters are applied before the plugin runs.

2. **Use constants for configuration**: Define configuration in `wp-config.php` and reference in filters:
   ```php
   // In wp-config.php
   define( 'WP_MCP_AI_OLLAMA_SERVER', 'http://ollama.local:11434' );
   
   // In functions.php
   add_filter( 'wp_mcp_ai_default_ollama_endpoint_url', function() {
       return WP_MCP_AI_OLLAMA_SERVER;
   } );
   ```

3. **Validate input**: Always validate and sanitize values in your filters:
   ```php
   add_filter( 'wp_mcp_ai_rate_limit_max_retries', function( $retries ) {
       $retries = absint( $retries );
       return max( 1, min( 10, $retries ) ); // Clamp between 1 and 10
   } );
   ```

4. **Document your changes**: Comment why you're overriding defaults to help future maintainers.

5. **Test thoroughly**: Always test filter changes in a development environment first.

---

## Related Documentation

- [Best Practices](BEST_PRACTICES.md)
- [Advanced SSE Budget Management](ADVANCED-SSE-BUDGET-MANAGEMENT.md)
- [Model Rate Limits CCT](MODEL-RATE-LIMITS-CCT.md)

---

## Changelog

- **1.0.0** - Initial release with 18 dynamic configuration filters
