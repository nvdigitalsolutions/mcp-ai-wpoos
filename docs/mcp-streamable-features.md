# MCP Streamable Features

This document explains how to use the streamable features enabled in WP oOS MCP implementation.

## Overview

WP oOS now supports the following MCP streamable features:

1. **Streamable HTTP** - HTTP-based streaming transport
2. **Progress Notifications** - Real-time progress updates for long-running operations
3. **Session Management** - Persistent session state across requests
4. **Tool Annotations** - Metadata about tool behavior and safety

## Capabilities Declaration

These features are declared in the MCP `initialize` response under the `experimental` capabilities:

```json
{
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "subscribe": false, "listChanged": true },
    "prompts": { "listChanged": true },
    "experimental": {
      "streamableHttp": true,
      "sessionManagement": true
    }
  }
}
```

## For MCP Clients

### Using Progress Notifications

To receive progress updates for long-running operations, include a `progressToken` in the `_meta` field:

```json
{
  "jsonrpc": "2.0",
  "id": 42,
  "method": "tools/call",
  "params": {
    "name": "run_crawl4ai_job",
    "arguments": {
      "url": "https://example.com"
    },
    "_meta": {
      "progressToken": "unique-token-123"
    }
  }
}
```

**Note:** Progress notifications require streaming transport (SSE). The current HTTP/REST implementation extracts and stores the progress token but doesn't stream progress updates during execution. This infrastructure is in place for future streaming enhancements.

### Understanding Tool Annotations

Tools may include annotations in their definitions to indicate their behavior:

```json
{
  "name": "check_site_security",
  "description": "Checks site security",
  "inputSchema": { ... },
  "annotations": {
    "readOnly": true
  }
}
```

**Annotation Types:**

- `readOnly: true` - Tool only reads data, doesn't modify anything
- `destructive: true` - Tool modifies or deletes data  
- `requiresConfirmation: true` - Client should prompt user before executing

## For Plugin Developers

### Adding Annotations to Your Tool

To add annotations to a tool, implement the `WP_MCP_AI_Tool_Annotations_Interface`:

```php
<?php

class My_Custom_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Annotations_Interface {
    
    // ... other interface methods ...
    
    /**
     * Get MCP tool annotations.
     *
     * @return array
     */
    public function get_annotations() {
        return array(
            'readOnly' => true,  // This tool doesn't modify data
        );
    }
}
```

**Example: Destructive Tool**

```php
public function get_annotations() {
    return array(
        'destructive' => true,
        'requiresConfirmation' => true,
    );
}
```

### Using Progress Notifications in Tools

Tools can report progress during long-running operations:

```php
<?php

public function execute( array $arguments = array(), array $context = array() ) {
    // Check if a progress token was provided
    $progress_token = isset( $context['request'] ) 
        ? $context['request']->get_param( '_mcp_progress_token' ) 
        : null;
    
    if ( $progress_token ) {
        // Report progress at 25%
        WP_MCP_AI_REST::send_progress_notification(
            $progress_token,
            25,
            100,
            'Started processing'
        );
    }
    
    // Do work...
    
    if ( $progress_token ) {
        // Report progress at 50%
        WP_MCP_AI_REST::send_progress_notification(
            $progress_token,
            50,
            100,
            'Halfway done'
        );
    }
    
    // More work...
    
    return $result;
}
```

**Note:** The `send_progress_notification()` method currently triggers a WordPress action hook but doesn't stream to clients. This infrastructure is in place for future streaming enhancements. You can hook into the `wp_mcp_ai_progress_notification` action to log or store progress updates.

### Hooking into Progress Notifications

```php
<?php

add_action( 'wp_mcp_ai_progress_notification', function( $token, $progress, $total, $message ) {
    error_log( sprintf(
        'Progress [%s]: %d/%d - %s',
        $token,
        $progress,
        $total ?: '?',
        $message
    ) );
}, 10, 4 );
```

## Implementation Notes

### Current State

- ✅ **Capabilities Declared**: `streamableHttp` and `sessionManagement` are advertised
- ✅ **Progress Token Extraction**: `_meta.progressToken` is extracted and stored
- ✅ **Tool Annotations**: Fully functional, annotations included in tools/list
- ⚠️ **Progress Streaming**: Infrastructure in place but not yet streaming to clients

### Future Enhancements

The following enhancements are planned for full streaming support:

1. **SSE Transport**: Server-Sent Events for real-time progress streaming
2. **Session Tracking**: Persistent session state with session IDs
3. **Progress Queueing**: Buffer and stream progress notifications to clients

### Backwards Compatibility

All streamable features are:
- Backwards compatible with existing tools
- Optional (tools work without implementing annotations)
- Non-breaking (progress tokens are extracted but don't affect tool behavior)

## Testing

Test files demonstrating the features:

- `tests/test-mcp-endpoint.php::test_streamable_features_enabled` - Tests capabilities declaration
- `tests/test-mcp-endpoint.php::test_tool_annotations_support` - Tests annotation support
- `tests/test-mcp-endpoint.php::test_progress_token_extraction` - Tests progress token handling

## References

- [MCP Endpoint Documentation](mcp-endpoint.md)
- [MCP Specification](https://modelcontextprotocol.io/specification/2024-11-05/)
- [Tool Interface](../includes/tools/class-wp-mcp-ai-tool-interface.php)

## Example Tool

See `includes/tools/class-wp-mcp-ai-tool-check-site-security.php` for a complete example of a tool implementing the annotations interface.
