# Remote WordPress/WooCommerce Site Connections

This feature allows AI assistants to access data from external WordPress and WooCommerce sites through their REST APIs in read-only mode. It also supports chat channel integrations for Telegram, WhatsApp, Slack, Discord, Microsoft Teams, Facebook Messenger, and WebChat.

## Quick Start

1. **Add a Connection**
   - Go to NV oOS → Remote Sites
   - Click "Add New Connection"
   - Select connection type (WordPress, Chat Channel, API, etc.)
   - Fill in authentication details
   - Test the connection

2. **Enable for Assistant**
   - Edit an assistant
   - Check desired connections in "Remote Site Connections" metabox
   - Save

3. **Use in Chat**
   - Ask: "Check stock for SKU ABC-123 on production store"
   - The assistant will use the remote_wp_connection tool

## Connection Types

### WordPress / WooCommerce Sites
Standard WordPress REST API connections with optional WooCommerce support.

### Chat Channels
Direct integrations with popular chat platforms. Each channel type requires platform-specific credentials:

#### Telegram
- **Bot Token**: From @BotFather
- **Bot Username**: Optional, for reference
- **Webhook URL**: Auto-generated

#### WhatsApp Business
- **Access Token**: From Facebook Business
- **Phone Number ID**: From WhatsApp Business API
- **Business Account ID**: Optional
- **Verify Token**: For webhook verification
- **Webhook URL**: Auto-generated

#### Slack
- **Bot Token**: OAuth token (xoxb-)
- **Signing Secret**: For request verification
- **Workspace ID**: Optional, for reference
- **Webhook URL**: Auto-generated

#### Discord
- **Bot Token**: From Discord Developer Portal
- **Application ID**: Your Discord app ID
- **Guild/Server ID**: Optional default server
- **Webhook URL**: Auto-generated

#### Microsoft Teams
- **App ID**: From Azure AD
- **App Password**: Bot registration secret
- **Tenant ID**: Optional, for reference
- **Messaging Endpoint**: Auto-generated

#### Facebook Messenger
- **Page Access Token**: From Facebook App
- **App Secret**: For signature verification
- **Page ID**: Optional, for reference
- **Verify Token**: For webhook verification
- **Webhook URL**: Auto-generated

#### WebChat P2P
- **P2P Connection ID**: Unique identifier
- **Encryption Key**: Optional, for secure communication
- **WebSocket Endpoint**: Auto-generated

### Generic REST APIs
Custom API connections with flexible authentication.

### Business Systems
Pre-configured integrations for iSAMS, Flowhub, PayHere, QuickBooks, EZuite ERP.

### Google Services
OAuth-based connections for Gmail and Google Drive.

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
- Read-only operations only (for WordPress/WooCommerce connections)
- Per-assistant access control
- Capability checks enforced
- Chat channel tokens stored securely
- Webhook URLs include security tokens
