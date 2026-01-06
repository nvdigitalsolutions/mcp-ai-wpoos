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
Get WooCommerce products.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `per_page` (integer): Results per page (1-100, default: 10)
- `page` (integer): Page number (default: 1)
- `search` (string): Search term
- `sku` (string): Product SKU filter
- `status` (string): Product status filter

**Example:**
```json
{
  "connection_id": "conn_abc123",
  "action": "get_wc_products",
  "per_page": 20,
  "status": "publish"
}
```

**Returns:**
```json
{
  "summary": "Retrieved 20 product(s)",
  "products": [
    {
      "id": 123,
      "name": "T-Shirt",
      "sku": "TSH-001",
      "price": "19.99",
      "stock_quantity": 50,
      "stock_status": "instock"
    }
  ],
  "count": 20
}
```

#### `get_wc_product`
Get a single product by ID.

**Parameters:**
- `connection_id` (string, required): Connection ID
- `post_id` (integer, required): Product ID

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

### Check Product Stock

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
