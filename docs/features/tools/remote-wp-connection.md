# Remote WordPress/WooCommerce Connection Tool

## Overview

The Remote WordPress/WooCommerce Connection Tool enables AI assistants to access data from external WordPress and WooCommerce sites through their REST APIs. This Pro feature provides read-only access to posts, media, products, orders, and other resources from configured remote sites.

## Features

- **Multiple Site Connections**: Configure and manage multiple remote WordPress/WooCommerce sites
- **Read-Only Access**: Secure, read-only operations to prevent accidental modifications
- **Multiple Authentication Methods**: Support for Application Passwords, Basic Auth, JWT, and no-auth
- **Per-Assistant Configuration**: Enable/disable specific connections for individual assistants
- **WooCommerce Support**: Full access to products, orders, customers, and categories
- **Connection Testing**: Test connectivity and authentication before use

## Configuration

### Adding a Remote Site Connection

1. Navigate to **NV oOS → Remote Sites** in the WordPress admin
2. Click **Add New Connection**
3. Fill in the connection details:
   - **Connection Name**: A friendly identifier (e.g., "Production Store")
   - **Site URL**: Full URL including https:// (e.g., https://example.com)
   - **Authentication Type**: Choose from:
     - **None**: Public REST API access
     - **Application Password**: WordPress Application Password (recommended)
     - **Basic Auth**: HTTP Basic Authentication
     - **JWT Token**: JSON Web Token authentication
   - **WooCommerce**: Check if the site has WooCommerce installed
   - **Status**: Enable or disable the connection

### Authentication Setup

#### Application Passwords (Recommended)

1. On the remote WordPress site, go to **Users → Profile**
2. Scroll to **Application Passwords** section
3. Enter an application name (e.g., "AI Assistant")
4. Click **Add New Application Password**
5. Copy the generated password
6. In NV oOS, enter the username and paste the application password

#### Basic Auth

Requires the [Basic Auth plugin](https://github.com/WP-API/Basic-Auth) on the remote site.

#### JWT Token

Requires JWT authentication plugin on the remote site (e.g., [JWT Authentication for WP REST API](https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/)).

### Enabling Connections for Assistants

1. Edit an assistant post
2. Find the **Remote Site Connections** metabox in the sidebar
3. Check the connections you want this assistant to access
4. Save the assistant

## Available Actions

### Connection Management

#### `list_connections`
List all configured remote site connections.

**Parameters:** None

**Returns:**
```json
{
  "summary": "Found 2 remote site connection(s)",
  "connections": [
    {
      "id": "conn_abc123",
      "name": "Production Store",
      "url": "https://store.example.com",
      "has_woocommerce": true,
      "enabled": true
    }
  ],
  "count": 2
}
```

#### `test_connection`
Test connectivity and authentication for a connection.

**Parameters:**
- `connection_id` (string, required): Connection ID

**Returns:**
```json
{
  "success": true,
  "wordpress": true,
  "woocommerce": true,
  "site_name": "My Store",
  "site_url": "https://store.example.com",
  "message": "Connection successful."
}
```

### WordPress Data

#### `get_posts`
Retrieve posts from the remote site.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `post_type` (string): Post type to query (default: "post")
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)
- `search` (string): Search term
- `status` (string): Post status filter

**Example:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_posts",
  "post_type": "post",
  "per_page": 5,
  "status": "publish"
}
```

#### `get_post`
Get a single post by ID.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `post_id` (integer, required): Post ID
- `post_type` (string): Post type (default: "post")

#### `get_pages`
Get pages from the remote site. Same parameters as `get_posts`.

#### `get_media`
Get media library items.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)

### WooCommerce Data

#### `get_wc_products`
Get WooCommerce products with **AUTOMATIC variation support** - variations are included by default.

**IMPORTANT:** When `include_variations` is enabled (default), variable products are represented **ONLY by their variations** (not the parent product) to provide accurate stock quantities and avoid confusion. Each variation includes `parent_id` and `parent_name` for reference.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)
- `search` (string): Search term (searches product titles)
- `sku` (string): Product SKU filter
- `status` (string): Product status filter
- `category` (string): Filter by category slug or ID
- `type` (string): Filter by product type (simple, variable, grouped, external)
- `include_variations` (boolean): Include product variations in results (**default: true** - when enabled, variable products are replaced with their variations)

**Example:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_products",
  "per_page": 20,
  "status": "publish"
}
```
Note: Variations are automatically included - no need to specify `include_variations`!

**Example - Basic search (variations automatically included):**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_products",
  "search": "shirt"
}
```
Note: No need to specify `include_variations: true` - variations are fetched automatically!

**Example - Exclude variations:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_products",
  "search": "shirt",
  "include_variations": false
}
```

**Returns:**
```json
{
  "summary": "Retrieved 3 product(s) with 12 variation(s). Note: Variable products are represented by their variations only, not the parent product.",
  "products": [
    {
      "id": 101,
      "name": "Simple Product",
      "type": "simple",
      "sku": "SIMPLE-001",
      "price": "15.99",
      "stock_quantity": 25,
      "stock_status": "instock"
    },
    {
      "id": 456,
      "parent_id": 123,
      "parent_name": "T-Shirt",
      "attributes": [
        {"name": "Size", "option": "Medium"},
        {"name": "Color", "option": "Blue"}
      ],
      "sku": "TSH-001-M-BLUE",
      "price": "19.99",
      "stock_quantity": 50,
      "stock_status": "instock"
    },
    {
      "id": 457,
      "parent_id": 123,
      "parent_name": "T-Shirt",
      "attributes": [
        {"name": "Size", "option": "Large"},
        {"name": "Color", "option": "Red"}
      ],
      "sku": "TSH-001-L-RED",
      "price": "19.99",
      "stock_quantity": 30,
      "stock_status": "instock"
    }
  ],
  "count": 13,
  "parent_count": 3,
  "variation_count": 12
}
```

**IMPORTANT:** When `include_variations` is true (the default), variable products are **NOT included** in the results - only their variations are returned. This prevents stock confusion since parent variable products typically have `stock_quantity: null` (stock is managed at the variation level). Each variation includes `parent_id`, `parent_name`, `stock_quantity`, `stock_status`, `sku`, `price`, and `attributes` fields. 

**Note:** Simple products and other non-variable product types are always included in results regardless of the `include_variations` setting. Only variable product parents are replaced with their variations.

You do NOT need to make a separate call to `get_wc_product_variations` unless you want to get variations for a specific product ID only.

#### `get_wc_product`
Get a single product by ID.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `post_id` (integer, required): Product ID

#### `get_wc_product_variations`
Get all variations for a specific variable product.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `post_id` (integer, required): Product ID of the parent variable product

**Example:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_product_variations",
  "post_id": 123
}
```

**Returns:**
```json
{
  "summary": "Retrieved 8 variation(s) for product ID 123",
  "variations": [
    {
      "id": 456,
      "attributes": [
        {"name": "Size", "option": "Small"},
        {"name": "Color", "option": "Red"}
      ],
      "sku": "TSH-001-S-RED",
      "price": "19.99",
      "regular_price": "19.99",
      "sale_price": "",
      "stock_quantity": 25,
      "stock_status": "instock",
      "manage_stock": true
    }
  ],
  "count": 8,
  "product_id": 123
}
```

#### `get_wc_orders`
Get WooCommerce orders.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)
- `status` (string): Order status filter (e.g., "completed", "processing")

**Example:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_orders",
  "status": "completed",
  "per_page": 10
}
```

#### `get_wc_order`
Get a single order by ID.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `order_id` (integer, required): Order ID

#### `get_wc_customers`
Get WooCommerce customers.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)

#### `get_wc_categories`
Get WooCommerce product categories.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)

## Use Cases

### Check Product Stock with Variations

**Prompt:** "Check the current stock for all sizes and colors of our blue T-shirt on the production store"

**Tool Call - Single call gets everything:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_prod_store",
    "action": "get_wc_products",
    "search": "blue t-shirt"
  }
}
```

**Response:** Returns all variations with individual stock levels for each size/color combination. The parent variable product is NOT included - only the variations are returned with accurate stock quantities. Each variation includes `parent_id` and `parent_name` for reference. No second call needed!

### Check Product Stock by SKU

**Prompt:** "Check the current stock quantity for SKU 'TSH-001' on our production store"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_prod_store",
    "action": "get_wc_products",
    "sku": "TSH-001"
  }
}
```

Note: Variations are automatically included with their stock quantities.

### Get Variations for a Specific Product

**Prompt:** "Show me all available variations and stock levels for product ID 123"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_prod_store",
    "action": "get_wc_product_variations",
    "post_id": 123
  }
}
```

### Search Products by Category

**Prompt:** "Show me all products in the 'shirts' category"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_prod_store",
    "action": "get_wc_products",
    "category": "shirts",
    "include_variations": false
  }
}
```

### Get Recent Orders

**Prompt:** "Show me the last 5 completed orders from the main store"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_main_store",
    "action": "get_wc_orders",
    "status": "completed",
    "per_page": 5
  }
}
```

### Check Order Status

**Prompt:** "What's the status of order #12345 on the production site?"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_prod_store",
    "action": "get_wc_order",
    "order_id": 12345
  }
}
```

### Get Latest Blog Posts

**Prompt:** "Show me the 3 most recent blog posts from our company blog"

**Tool Call:**
```json
{
  "tool": "remote_wp_connection",
  "arguments": {
    "connection_id": "conn_company_blog",
    "action": "get_posts",
    "per_page": 3,
    "status": "publish"
  }
}
```

## Security

### Credential Storage

- Passwords and tokens are encrypted using WordPress auth salt
- Credentials are never exposed in API responses
- Only administrators can configure connections

### Access Control

- Requires `edit_posts` capability to use the tool
- Per-assistant connection restrictions enforced
- Read-only operations prevent accidental modifications
- Disabled connections cannot be used

### Best Practices

1. **Use Application Passwords**: Most secure method for WordPress authentication
2. **Limit Permissions**: Only enable connections for assistants that need them
3. **Regular Audits**: Review and test connections periodically
4. **Disable Unused**: Disable connections that are no longer needed
5. **Secure Transit**: Always use HTTPS URLs

## Troubleshooting

### Connection Test Fails

1. Verify the remote site URL is correct and accessible
2. Check authentication credentials are valid
3. Ensure REST API is enabled on remote site
4. Check for firewall or security plugins blocking API access
5. Verify SSL certificate is valid

### WooCommerce Not Available

1. Ensure WooCommerce is installed and active on remote site
2. Check "This site has WooCommerce" is enabled in connection settings
3. Verify WooCommerce REST API is accessible

### Permission Denied

1. Check the assistant has the connection enabled in its metabox
2. Verify the connection is enabled globally
3. Ensure user has `edit_posts` capability

### Empty Results

1. Check pagination parameters (page, per_page)
2. Verify filters (status, search) are correct
3. Confirm data exists on the remote site
4. Test the connection to verify authentication

## Technical Details

- **Tool Slug:** `remote_wp_connection`
- **Capability Flags:** `pro`, `read-only`, `external-api`, `requires-capability`
- **Tool Group:** `external-tools`
- **Minimum Capability:** `edit_posts`

## Related Documentation

- [Tool Reference](../../reference/tools/tool-reference.md)
- [Tool Grouping](../../reference/tools/tool-grouping.md)
- [Security Best Practices](../security/SECURITY_HARDENING.md)
- [WP All Import/Export Integration](../integrations/WP_ALL_IMPORT_EXPORT_INTEGRATION.md)
