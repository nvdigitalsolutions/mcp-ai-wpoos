# Capability Flags System - Usage Examples

**Last Updated:** December 24, 2025  
**Total Flags Documented:** 45+ unique flags

## Overview

The capability flags system allows tools to declare their characteristics and requirements, enabling the orchestration layer to make intelligent decisions about tool execution.

**Note:** This document has been updated to include all capability flags currently in use across the codebase. Some flags were previously undocumented but are now standardized.

## Implementation Status

✅ **COMPLETE** - All three required methods have been implemented in `WP_MCP_AI_Tool_Registry`:
- `get_tool_capability_flags($slug)`
- `get_all_tool_capability_flags()`
- `get_tools_by_capability_flag($flag)`

## Basic Usage

### Get Flags for a Specific Tool

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$flags = $registry->get_tool_capability_flags( 'web_search' );

// Returns:
// array(
//     'requires-credentials',
//     'read-only',
//     'external-api',
//     'rate-limited',
//     'cacheable',
//     'network-dependent',
//     'non-deterministic',
// )
```

### Get All Tools with Their Flags

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$all_flags = $registry->get_all_tool_capability_flags();

// Returns:
// array(
//     'web_search' => array( 'requires-credentials', 'read-only', ... ),
//     'create_chart' => array( 'local-only', 'write', ... ),
//     ...
// )
```

### Filter Tools by Capability Flag

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();

// Get all read-only tools
$readonly_tools = $registry->get_tools_by_capability_flag( 'read-only' );

// Get all tools requiring credentials
$credential_tools = $registry->get_tools_by_capability_flag( 'requires-credentials' );

// Get all local-only tools (no external API calls)
$local_tools = $registry->get_tools_by_capability_flag( 'local-only' );
```

## Real-World Examples

### Example 1: Pre-Flight Credential Check

Before executing a tool, check if it requires credentials and validate they're configured:

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$flags = $registry->get_tool_capability_flags( $tool_slug );

if ( in_array( 'requires-credentials', $flags, true ) ) {
    // Check if required credentials are configured
    $api_key = get_option( 'wp_mcp_ai_' . $provider . '_api_key' );
    
    if ( empty( $api_key ) ) {
        return new WP_Error(
            'missing_credentials',
            sprintf( 
                __( 'The %s tool requires API credentials to be configured.', 'wp-mcp-ai' ),
                $tool_slug
            )
        );
    }
}
```

### Example 2: Caching Strategy

Determine if tool results should be cached:

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$flags = $registry->get_tool_capability_flags( $tool_slug );

if ( in_array( 'cacheable', $flags, true ) && ! in_array( 'non-deterministic', $flags, true ) ) {
    // Results are cacheable and deterministic - safe to cache
    $cache_key = 'tool_result_' . md5( $tool_slug . serialize( $arguments ) );
    $cached = wp_cache_get( $cache_key, 'wp_mcp_ai_tools' );
    
    if ( false !== $cached ) {
        return $cached; // Return cached result
    }
    
    // Execute tool and cache result
    $result = $tool->execute( $arguments, $context );
    wp_cache_set( $cache_key, $result, 'wp_mcp_ai_tools', 300 ); // 5 min cache
}
```

### Example 3: Offline Mode

Filter tools that work without internet connectivity:

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();

// Get tools that work offline
$offline_capable_tools = array_filter(
    $registry->get_tools(),
    function( $tool ) use ( $registry ) {
        $flags = $registry->get_tool_capability_flags( $tool->get_slug() );
        return ! in_array( 'network-dependent', $flags, true ) 
            && ! in_array( 'external-api', $flags, true );
    }
);
```

### Example 4: Safety Check for State-Changing Tools

Warn before executing tools that modify site state:

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$flags = $registry->get_tool_capability_flags( $tool_slug );

if ( in_array( 'state-changing', $flags, true ) && ! in_array( 'reversible', $flags, true ) ) {
    // This tool makes irreversible changes - require confirmation
    if ( ! $confirmed_by_user ) {
        return new WP_Error(
            'confirmation_required',
            __( 'This action will make permanent changes. Please confirm.', 'wp-mcp-ai' )
        );
    }
}
```

### Example 5: Rate Limit Management

Manage tools subject to rate limiting:

```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$flags = $registry->get_tool_capability_flags( $tool_slug );

if ( in_array( 'rate-limited', $flags, true ) ) {
    // Check rate limit before execution
    $rate_key = 'rate_limit_' . $tool_slug;
    $requests = get_transient( $rate_key );
    
    if ( $requests && $requests >= 10 ) {
        return new WP_Error(
            'rate_limit_exceeded',
            __( 'Rate limit exceeded. Please try again later.', 'wp-mcp-ai' )
        );
    }
    
    // Increment counter
    set_transient( $rate_key, ( $requests ? $requests + 1 : 1 ), MINUTE_IN_SECONDS );
}
```

## Standard Capability Flags

### Requirement Flags
- `requires-credentials` - Requires external API credentials
- `requires-plugin` - Requires specific WordPress plugin
- `requires-capability` - Requires WordPress user capabilities
- `requires-model` - Requires AI model specification
- `requires-vision-model` - Requires vision-capable AI model
- `requires-multimodal-model` - Requires multimodal AI model
- `requires-video-model` - Requires video-capable AI model
- `requires-polling` - Requires polling for async results

### Operational Characteristics
- `read-only` - Only reads data, doesn't modify state
- `read` - Requires read capability (more permissive than specific capabilities)
- `write` - Creates or modifies data
- `state-changing` - Modifies database or site state
- `modifies-state` - Synonym for state-changing
- `modifies-data` - Modifies content data
- `reversible` - Changes can be undone
- `idempotent` - Safe to call multiple times
- `performance-impact` - May affect site performance
- `consumes-tokens` - Uses AI model tokens/credits
- `model-dependent` - Behavior varies by AI model
- `safe` - Tool has no side effects (read-only, no external changes)
- `security` - Tool relates to security functionality

### Network & Performance
- `local-only` - Works entirely locally
- `external-api` - Makes external HTTP requests
- `network-dependent` - Requires internet connectivity
- `async` - May take significant time
- `async-capable` - Can run asynchronously
- `background-only` - Must run in background (prevents HTTP timeouts)
- `background-preferred` - Preferred to run in background
- `rate-limited` - Subject to rate limiting
- `long-running` - Execution may take minutes/hours
- `may-timeout` - May exceed typical HTTP timeout limits
- `deferred-result` - Returns immediately, result available later

### Data Characteristics
- `cacheable` - Results can be cached
- `non-deterministic` - Results may vary over time
- `pii-data` - Returns personally identifiable information
- `large-response` - May return large data sets
- `paginated` - Supports pagination
- `batch-operation` - Operates on multiple items at once

### AI & ML Specific
- `ai-powered` - Uses AI/ML capabilities beyond basic API calls
- `model-dependent` - Behavior varies by AI model

### WordPress Specific Capabilities
These reference actual WordPress capabilities required:
- `manage_options` - Requires administrator-level access
- `edit_posts` - Requires post editing capability
- `manage_woocommerce` - Requires WooCommerce management capability
- `view_woocommerce_reports` - Requires WooCommerce reporting access

## Adding Capability Flags to a Tool

To add capability flags to your tool, implement the `WP_MCP_AI_Tool_Capability_Flags_Interface`:

```php
class My_Custom_Tool implements 
    WP_MCP_AI_Tool_Interface, 
    WP_MCP_AI_Tool_Capability_Flags_Interface {
    
    // ... other required methods ...
    
    public function get_capability_flags() {
        return array(
            'read-only',
            'local-only',
            'cacheable',
        );
    }
}
```

## Testing

Run the test suite to verify capability flags work correctly:

```bash
# Run all tests
composer run test

# Run specific capability flags test
vendor/bin/phpunit tests/test-tool-capability-flags.php

# Quick verification
php verify-capability-flags.php
```

## See Also

- [Tool Grouping Documentation](tool-grouping.md)
- [Orchestration Layer Architecture](../../architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)
- [Tool Interface Documentation](../../../includes/interfaces/interface-wp-mcp-ai-tool.php)
