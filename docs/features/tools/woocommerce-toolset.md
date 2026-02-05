# Comprehensive WooCommerce Toolset (Pro Feature)

## Overview

The Pro addon includes a complete WooCommerce toolset providing comprehensive management capabilities for WooCommerce stores. This toolset covers products (including variations), orders, customers, coupons, and remote store connections.

## Available Tools

### 1. WooCommerce Products Tool (`woo_products`)

**Location:** `/addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-products.php`

**Capabilities:**
- Full CRUD operations for products
- Product variation management
- Category, tag, and attribute management
- Image management
- Inventory management
- Support for all product types (simple, variable, grouped, external)

**Actions:**
- `get` - Get a single product by ID
- `list` - List products with filtering
- `create` - Create new products
- `update` - Update existing products
- `search` - Search products
- `delete` - Delete products
- `manage_categories` - Manage product categories
- `manage_tags` - Manage product tags
- `manage_attributes` - Manage product attributes

**Parameters:**
- `product_id` - Product ID for get/update/delete actions
- `per_page` - Number of products per page (1-100)
- `page` - Page number for pagination
- `search` - Search term
- `category` - Filter by category
- `status` - Filter by status (publish, draft, pending, private)
- `type` - Filter by type (simple, variable, grouped, external)
- `name` - Product name for create/update
- `sku` - Product SKU
- `price` - Regular price
- `sale_price` - Sale price
- `description` - Full description
- `short_description` - Short description
- `stock_quantity` - Stock quantity
- `stock_status` - Stock status (instock, outofstock, onbackorder)
- `manage_stock` - Whether to manage stock
- `images` - Product images
- `categories` - Product categories
- `tags` - Product tags
- `attributes` - Product attributes

### 2. WooCommerce Orders Tool (`woo_orders`)

**Location:** `/addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-orders.php`

**Capabilities:**
- List and search orders
- Get order details
- Update order status
- Manage order notes
- Process refunds
- View order items and totals

**Actions:**
- `get` - Get a single order
- `list` - List orders
- `update_status` - Update order status
- `add_note` - Add order note
- `process_refund` - Process refund

**Common Use Cases:**
- Order fulfillment workflows
- Order status tracking
- Customer service support
- Refund processing
- Order analytics

### 3. WooCommerce Customers Tool (`woo_customers`)

**Location:** `/addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-customers.php`

**Capabilities:**
- List and search customers
- Get customer details
- View customer orders
- Update customer information
- Manage customer meta data

**Actions:**
- `get` - Get a single customer
- `list` - List customers
- `update` - Update customer information
- `get_orders` - Get customer orders

**Common Use Cases:**
- Customer support
- Customer analytics
- Loyalty program management
- Personalized marketing

### 4. WooCommerce Coupons Tool (`woo_coupons`)

**Location:** `/addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-woo-coupons.php`

**Capabilities:**
- Create and manage coupons
- Set discount amounts and types
- Configure coupon restrictions
- Set expiration dates
- Usage limits and tracking

**Actions:**
- `get` - Get a single coupon
- `list` - List coupons
- `create` - Create new coupon
- `update` - Update existing coupon
- `delete` - Delete coupon

**Common Use Cases:**
- Promotional campaigns
- Customer loyalty programs
- Seasonal sales
- Abandoned cart recovery

### 5. Remote WooCommerce Connection Tool (`remote_wp_connection`)

**Location:** `/addons/pro/includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php`

**Capabilities:**
- Connect to remote WooCommerce sites
- Query products, orders, and customers from remote sites
- Support for product variations
- Multi-site inventory management
- Read-only access for safety

**Actions:**
- `list_connections` - List configured remote sites
- `test_connection` - Test site connectivity
- `get_wc_products` - Get products (with variations support)
- `get_wc_product` - Get single product
- `get_wc_product_variations` - Get product variations
- `get_wc_orders` - Get orders
- `get_wc_order` - Get single order
- `get_wc_customers` - Get customers
- `get_wc_categories` - Get product categories

**New Enhancement Features (v1.0.0):**
- ✅ Product variation support with `include_variations` parameter
- ✅ Category filtering for products
- ✅ Product type filtering
- ✅ Enhanced stock checking across variations
- ✅ Dedicated `get_wc_product_variations` action

**Common Use Cases:**
- Multi-store inventory management
- Stock level monitoring across sites
- Order synchronization
- Product catalog comparison
- Remote store reporting

## Integration Examples

### Example 1: Stock Check with Variations

```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_main_store",
    "action": "get_wc_products",
    "search": "t-shirt",
    "include_variations": true
  }
}
```

**Returns:** Parent products and all variations with individual stock levels.

### Example 2: Create Variable Product

```json
{
  "tool": "woo_products",
  "arguments": {
    "action": "create",
    "name": "Premium T-Shirt",
    "type": "variable",
    "price": "29.99",
    "sku": "TSH-PREM",
    "categories": ["clothing", "t-shirts"],
    "attributes": [
      {
        "name": "Size",
        "visible": true,
        "variation": true,
        "options": ["S", "M", "L", "XL"]
      },
      {
        "name": "Color",
        "visible": true,
        "variation": true,
        "options": ["Red", "Blue", "Green"]
      }
    ]
  }
}
```

### Example 3: Process Order and Send Notification

```json
{
  "tool": "woo_orders",
  "arguments": {
    "action": "update_status",
    "order_id": 12345,
    "status": "completed"
  }
}
```

### Example 4: Create Promotional Coupon

```json
{
  "tool": "woo_coupons",
  "arguments": {
    "action": "create",
    "code": "SUMMER2026",
    "discount_type": "percent",
    "amount": "20",
    "expiry_date": "2026-09-30",
    "usage_limit": 100
  }
}
```

## Security Features

All WooCommerce tools implement:

1. **Capability Checking**: Requires `manage_woocommerce` capability
2. **Input Sanitization**: All inputs sanitized and validated
3. **Output Escaping**: Safe data output
4. **Rate Limiting**: Prevents abuse
5. **Audit Logging**: All operations logged
6. **Read-Only Remote Access**: Remote connections are read-only by default

## Availability

These tools are **Pro features** and require:
- NV oOS Pro addon to be active
- WooCommerce plugin to be installed and activated
- Appropriate user capabilities

## Technical Details

- **Capability Flags**: `pro`, `requires-woocommerce`, `requires-capability`
- **Tool Group**: `woocommerce-tools`
- **Minimum WooCommerce Version**: 3.5+
- **REST API Version**: WooCommerce REST API v3

## Performance Considerations

- Product queries are paginated (max 100 per page)
- Remote connections have rate limiting (30 requests/min/user)
- Variation fetching is optional and can be disabled for performance
- Results can be cached when appropriate

## Future Enhancements

Potential additions to the WooCommerce toolset:

1. **Analytics Tool** - WooCommerce reports and analytics
2. **Subscription Management** - WooCommerce Subscriptions support
3. **Booking Management** - WooCommerce Bookings support
4. **Membership Management** - WooCommerce Memberships support
5. **Shipping Management** - Shipping zones and methods
6. **Tax Management** - Tax rates and classes
7. **Webhook Management** - Configure and monitor webhooks
8. **Product Import/Export** - Bulk product operations

## Related Documentation

- [Remote WordPress/WooCommerce Connection](./remote-wp-connection.md)
- [Tool Reference](../../tool-reference.md)
- [Security Best Practices](../../security/SECURITY_HARDENING.md)
- [Pro Features Overview](../../PRO_FEATURES.md)

## Support

For issues or feature requests related to the WooCommerce toolset:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Support Email: support@nvdigitalsolutions.com
- Documentation: https://nvdigitalsolutions.com/docs/woocommerce-tools/
