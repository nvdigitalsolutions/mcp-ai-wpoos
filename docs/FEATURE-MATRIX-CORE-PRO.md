# WP oOS - Feature Matrix (Core vs Pro)

This document outlines the features available in the free Core plugin versus the commercial Pro add-on.

## Overview

| Aspect | Core (Free) | Pro (Commercial) |
|--------|-------------|------------------|
| License | GPL-3.0-or-later | Proprietary |
| Distribution | WordPress.org | Direct download |
| Support | GitHub issues | Email/helpdesk |
| Updates | WordPress.org | Direct updates |

## MCP Server Features

| Feature | Core | Pro |
|---------|------|-----|
| MCP Protocol Implementation | ✅ | ✅ |
| REST API Endpoints | ✅ | ✅ |
| Tool Registry | ✅ | ✅ |
| Tool Execution | ✅ | ✅ |
| Basic Authentication | ✅ | ✅ |
| Rate Limiting (Basic) | ✅ | ✅ |
| Rate Limiting (Advanced) | ❌ | ✅ |
| Field-level Permissions | ❌ | ✅ |
| Analytics Dashboard | ❌ | ✅ |

## Baseline Tools (Core)

These tools are included in the free Core plugin:

| Tool | Description |
|------|-------------|
| `posts` | CRUD operations for WordPress posts |
| `media` | Query and upload media attachments |
| `users` | Query WordPress users (read-only) |
| `taxonomies` | Query taxonomies and terms |

## Pro Tools

These tools require the Pro add-on:

### WooCommerce Integration

| Tool | Description |
|------|-------------|
| `woo_products` | CRUD operations for WooCommerce products |
| `woo_orders` | Query WooCommerce orders |
| `woo_customers` | Query WooCommerce customers |
| `woo_coupons` | Manage WooCommerce coupons |

### JetEngine Integration

| Tool | Description |
|------|-------------|
| `jetengine` | CRUD operations for JetEngine CCT items |
| `jetengine_routes` | List and invoke JetEngine REST routes |

### Elementor Integration

| Tool | Description |
|------|-------------|
| `elementor` | Query Elementor templates |
| `elementor_widgets` | AI-powered Elementor widgets |

### Advanced Features

| Feature | Description |
|---------|-------------|
| Rate Limiting | Per-user, per-tool rate limits with burst control |
| Permissions | Role-based access control for tools |
| Analytics | Usage statistics and cost tracking |
| Multisite | Network-wide settings and per-site overrides |

## Extension Points

Both Core and Pro use the same extension API:

### Actions

```php
// Register custom tools
add_action( 'wp_mcp_ai_register_tools', function( $server ) {
    $server->register_tool( new My_Custom_Tool() );
}, 10, 1 );

// After Core initialization
add_action( 'wp_mcp_ai_core_init', function() {
    // Core is ready
});

// After Pro initialization
add_action( 'wp_mcp_ai_pro_init', function() {
    // Pro is ready
});
```

### Filters

```php
// Control tool access
add_filter( 'wp_mcp_ai_can_access_tools', function( $can, $request ) {
    return $can;
}, 10, 2 );

// Control tool execution
add_filter( 'wp_mcp_ai_can_run_tool', function( $can, $tool, $args, $user ) {
    return $can;
}, 10, 4 );

// Rate limiting
add_filter( 'wp_mcp_ai_rate_limit_allow', function( $allow, $slug, $user, $context ) {
    return $allow;
}, 10, 4 );
```

## Requirements

### Core Plugin

- WordPress 6.0+
- PHP 7.4+
- No additional plugins required

### Pro Add-on

- WordPress 6.0+
- PHP 7.4+
- WP oOS Core plugin (required)
- Optional: WooCommerce, JetEngine, Elementor (for respective tools)

## Pricing

Core is free forever. Pro pricing is available at [nvdigitalsolutions.com](https://nvdigitalsolutions.com).

## Migration Path

### Existing Single Plugin Users

1. Install WP oOS Core (new free plugin)
2. Install WP oOS Pro (for advanced features)
3. Settings and data migrate automatically

### New Users

1. Start with WP oOS Core
2. Upgrade to Pro when you need advanced features

## Support

| Type | Core | Pro |
|------|------|-----|
| GitHub Issues | ✅ | ✅ |
| Email Support | ❌ | ✅ |
| Priority Response | ❌ | ✅ |
| Custom Development | ❌ | Available |
