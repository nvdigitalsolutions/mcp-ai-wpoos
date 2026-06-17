# Remote Connections Toolkit

> Remote WordPress and Shopify site connection management.

## Purpose

Tools for establishing and managing REST API connections to remote WordPress and Shopify instances, enabling cross-site data operations.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Remote Shopify Connection | `remote_shopify_connection` | Connect to a remote Shopify store |
| Remote WP Connection | `remote_wp_connection` | Connect to a remote WordPress site via REST API |

## Dependencies

- WordPress 6.0+
- Remote site credentials (API keys / application passwords)

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [Remote Connection Manager: `addons/pro/includes/class-wp-mcp-ai-remote-connection.php`](../../class-wp-mcp-ai-remote-connection.php)
