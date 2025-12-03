# WP oOS Core

Core MCP (Model Context Protocol) server framework for WordPress. Provides a stable API for AI tool integration.

## Overview

WP oOS Core is an open-source MCP server implementation for WordPress. It allows WordPress sites to act as MCP servers, exposing tools that AI assistants can use to interact with your site's content and functionality.

## Features

- **MCP Server Engine** - Full MCP protocol implementation with REST API endpoints
- **Tool Registry** - Extensible tool registration system for AI assistants
- **Baseline Tools** - Posts, Media, Users, and Taxonomies tools included
- **Authentication** - Flexible auth hooks for custom authentication schemes
- **Rate Limiting** - Filterable rate limiting for API protection
- **Extension API** - Stable public API for add-ons and third-party plugins

## Installation

1. Download the latest release from the [releases page](https://github.com/nvdigitalsolutions/wp-mcp-ai/releases)
2. Upload to `/wp-content/plugins/wp-mcp-ai-core/`
3. Activate through WordPress admin

## Quick Start

### Registering a Custom Tool

```php
add_action( 'wp_mcp_ai_register_tools', function( $server ) {
    $server->register_tool( new My_Custom_Tool() );
});
```

### Implementing a Tool

```php
class My_Custom_Tool implements WP_MCP_AI_Core_Tool_Interface {
    public function get_slug() {
        return 'my_tool';
    }
    
    public function get_name() {
        return 'My Tool';
    }
    
    public function get_description() {
        return 'Does something useful.';
    }
    
    public function get_parameters_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'message' => array(
                    'type' => 'string',
                    'description' => 'A message to process',
                ),
            ),
        );
    }
    
    public function execute( array $arguments = array(), array $context = array() ) {
        return array(
            'result' => 'Processed: ' . $arguments['message'],
        );
    }
}
```

## API Reference

### Public Functions

```php
// Check if Core is loaded
wp_mcp_ai_core_loaded() : bool

// Register a tool
wp_mcp_ai_register_tool( WP_MCP_AI_Core_Tool_Interface $tool ) : bool

// Get a tool by slug
wp_mcp_ai_get_tool( string $slug ) : ?WP_MCP_AI_Core_Tool_Interface

// Get all registered tools
wp_mcp_ai_get_registered_tools() : array

// Execute a tool
wp_mcp_ai_execute_tool( string $slug, array $arguments, array $context ) : mixed
```

### Filters

```php
// Control tool access
add_filter( 'wp_mcp_ai_can_access_tools', function( $can, $request ) {
    // Custom access logic
    return $can;
}, 10, 2 );

// Control individual tool execution
add_filter( 'wp_mcp_ai_can_run_tool', function( $can, $tool, $args, $user ) {
    // Custom permission logic
    return $can;
}, 10, 4 );

// Implement rate limiting
add_filter( 'wp_mcp_ai_rate_limit_allow', function( $allow, $slug, $user, $context ) {
    // Custom rate limiting logic
    return $allow;
}, 10, 4 );
```

### Actions

```php
// Register tools when Core initializes
add_action( 'wp_mcp_ai_register_tools', function( $server ) {
    // Register your tools here
}, 10, 1 );

// After Core initialization
add_action( 'wp_mcp_ai_core_init', function() {
    // Core is ready
});
```

## REST API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/mcp-ai-core/v1/tools` | GET | List all registered tools |
| `/wp-json/mcp-ai-core/v1/tools/{slug}` | POST | Execute a tool |

## Baseline Tools

### Posts Tool (`posts`)

CRUD operations for WordPress posts.

**Actions:**
- `get` - Get a single post by ID
- `list` - List posts with filtering and pagination
- `create` - Create a new post
- `update` - Update an existing post
- `delete` - Delete (trash) a post
- `search` - Search posts

### Media Tool (`media`)

Operations for WordPress media attachments.

**Actions:**
- `get` - Get a single attachment by ID
- `list` - List media with filtering
- `upload` - Upload media from URL
- `search` - Search media

### Users Tool (`users`)

Read operations for WordPress users.

**Actions:**
- `get` - Get user by ID
- `list` - List users (requires `list_users` capability)
- `current` - Get current user info
- `search` - Search users

### Taxonomies Tool (`taxonomies`)

Operations for taxonomies and terms.

**Actions:**
- `list_taxonomies` - List all public taxonomies
- `list_terms` - List terms for a taxonomy
- `get_term` - Get a single term by ID
- `search_terms` - Search terms

## Pro Add-ons

Commercial add-ons are available for advanced features:

- **WP oOS Pro** - WooCommerce, JetEngine, advanced permissions, rate limiting, analytics

See [nvdigitalsolutions.com](https://nvdigitalsolutions.com) for details.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](../CONTRIBUTING.md) for guidelines.

## License

GPL-3.0-or-later - See [LICENSE](LICENSE) file.

## Support

- **Issues**: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- **Documentation**: https://nvdigitalsolutions.com/docs
