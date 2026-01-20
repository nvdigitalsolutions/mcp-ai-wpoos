# Remote WordPress/WooCommerce Site Connections

This feature allows AI assistants to access data from external WordPress and WooCommerce sites through their REST APIs in read-only mode.

## Quick Start

1. **Add a Connection**
   - Go to NV oOS → Remote Sites
   - Click "Add New Connection"
   - Fill in site URL and authentication details
   - Test the connection

2. **Enable for Assistant**
   - Edit an assistant
   - Check desired connections in "Remote Site Connections" metabox
   - Save

3. **Use in Chat**
   - Ask: "Check stock for SKU ABC-123 on production store"
   - The assistant will use the remote_wp_connection tool

## Authentication Methods

### Application Passwords (Recommended)
Most secure for WordPress REST API. Generate on remote site: Users → Profile → Application Passwords

### WooCommerce API Keys (Recommended for WooCommerce)
Use WooCommerce REST API consumer keys. Generate on remote site: WooCommerce → Settings → Advanced → REST API
- Consumer Key format: `ck_xxxxxxxxxx`
- Consumer Secret format: `cs_xxxxxxxxxx`
- Works over HTTPS by passing keys as query parameters

### Basic Auth
Requires Basic Auth plugin on remote site.

### JWT Token
Requires JWT authentication plugin on remote site.

### None
For public REST API access (limited functionality).

## Files

- `class-wp-mcp-ai-pro-remote-site-manager.php` - Connection manager
- `class-wp-mcp-ai-pro-remote-sites-admin.php` - Admin UI
- `class-wp-mcp-ai-pro-metabox-remote-connections.php` - Assistant metabox
- `../tools/class-wp-mcp-ai-tool-remote-wp-connection.php` - Tool implementation

## Security

- Credentials encrypted with WordPress auth salt
- Read-only operations only
- Per-assistant access control
- Capability checks enforced
