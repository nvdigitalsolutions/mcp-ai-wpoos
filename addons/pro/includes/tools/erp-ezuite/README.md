# ERP EZUite Toolkit

> EZUite ERP integration tools for product and data retrieval.

## Purpose

Tools for connecting to and querying EZUite ERP systems to retrieve products, inventory, and related business data.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| EZUite ERP | `ezuite_erp` | General EZUite ERP query and operations |
| EZUite ERP Get Products | `ezuite_erp_get_products` | Retrieve product catalog from EZUite ERP |

## Dependencies

- WordPress 6.0+
- EZUite ERP API credentials
- `WP_MCP_AI_ERP_EZUite` connector class

## Registration

Registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [ERP Connector: `addons/pro/includes/class-wp-mcp-ai-erp-ezuite.php`](../../class-wp-mcp-ai-erp-ezuite.php)
- [ERP Interface: `addons/pro/includes/interface-wp-mcp-ai-erp-connector.php`](../../interface-wp-mcp-ai-erp-connector.php)
