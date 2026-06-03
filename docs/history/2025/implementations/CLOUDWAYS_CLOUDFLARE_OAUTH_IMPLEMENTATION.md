# Cloudways and Cloudflare 1-Click OAuth Integration

## Overview

This document describes the 1-click OAuth/connection functionality added for Cloudways and Cloudflare external services, following the pattern established in PR #2538 for Meta and GitHub integrations.

## Implementation Summary

### Cloudways OAuth Handler

**File**: `includes/integrations/class-wp-mcp-ai-cloudways-oauth-handler.php`

Cloudways uses a simplified OAuth 2.0 flow where email + API key are exchanged for an access token via POST to `/oauth/access_token` endpoint.

**Features**:
- One-click connection from settings dashboard
- Automatic token exchange (email + API key → access token)
- Token validation and auto-refresh
- Secure token storage in WordPress options
- Account verification via server list API
- Connection status display with server count

**Methods**:
- `handle_cloudways_connect()` - Validates credentials and obtains access token
- `handle_cloudways_disconnect()` - Clears OAuth tokens while preserving credentials
- `exchange_credentials_for_token()` - Exchanges email/API key for access token
- `get_account_info()` - Verifies token by fetching account information
- `is_token_valid()` - Static method to check if stored token is valid
- `get_access_token()` - Static method to get valid token (auto-refreshes if expired)

**Usage in Tools**:
Tools can now use `WP_MCP_AI_Cloudways_OAuth_Handler::get_access_token()` to obtain a valid access token. The method automatically handles token refresh if expired.

### Cloudflare Connection Handler

**File**: `includes/integrations/class-wp-mcp-ai-cloudflare-connection-handler.php`

Cloudflare uses API tokens (not OAuth), so this provides 1-click connection testing and validation.

**Features**:
- One-click connection test from settings dashboard
- API token validation via `/user/tokens/verify` endpoint
- Zone ID verification and access check
- Connection status display with zone name
- Secure token storage in WordPress options

**Methods**:
- `handle_cloudflare_test_connection()` - Tests API token and zone ID
- `handle_cloudflare_disconnect()` - Clears connection status
- `verify_api_token()` - Validates API token is valid
- `verify_zone_access()` - Verifies zone ID is accessible with token
- `is_connected()` - Static method to check connection status

**Usage in Tools**:
Tools can check connection status with `WP_MCP_AI_Cloudflare_Connection_Handler::is_connected()` and use the stored API token from settings.

## Settings Dashboard Integration

### Location
Settings Dashboard → Tools → Connections → Cloudways/Cloudflare tabs

### UI Components

#### Cloudways Tab
- **Not Connected (No Credentials)**: Info message prompting to enter email and API key
- **Not Connected (Has Credentials)**: Warning message with "Connect Cloudways Account" button (primary)
- **Connected**: Success message showing account info, with "Refresh Connection" and "Disconnect" buttons
- Helper text explaining OAuth flow and credentials requirements

#### Cloudflare Tab
- **Not Connected (No Token)**: Info message prompting to enter API token
- **Not Connected (Has Token)**: Warning message with "Test Connection" button (primary)
- **Connected**: Success message showing zone name, with "Test Connection" and "Disconnect" buttons
- Helper text explaining API token requirements and permissions

## Settings Storage

### Cloudways Settings
```php
array(
    'cloudways_email'               => string,  // Account email
    'cloudways_api_key'             => string,  // API key
    'cloudways_server_id'           => string,  // Optional: Server ID
    'cloudways_app_id'              => string,  // Optional: App ID
    'cloudways_access_token'        => string,  // OAuth access token (auto-managed)
    'cloudways_token_expires_at'    => int,     // Token expiry timestamp (auto-managed)
    'cloudways_connected'           => bool,    // Connection status
    'cloudways_connection_time'     => int,     // Connection timestamp
    'cloudways_account_name'        => string,  // Account info (e.g., "5 servers")
)
```

### Cloudflare Settings
```php
array(
    'cloudflare_api_token'          => string,  // API token
    'cloudflare_zone_id'            => string,  // Zone ID
    'cloudflare_connected'          => bool,    // Connection status
    'cloudflare_connection_time'    => int,     // Connection timestamp
    'cloudflare_token_status'       => string,  // Token status (e.g., "active")
    'cloudflare_zone_name'          => string,  // Zone name (e.g., "example.com")
    'cloudflare_zone_status'        => string,  // Zone status
)
```

## API Endpoints

### Cloudways OAuth Endpoints
- **Token Exchange**: `https://api.cloudways.com/api/v1/oauth/access_token` (POST)
  - Body: `email`, `api_key`
  - Response: `access_token`, `expires_in`
- **Servers List**: `https://api.cloudways.com/api/v1/server` (GET)
  - Headers: `Authorization: Bearer <access_token>`

### Cloudflare API Endpoints
- **Token Verification**: `https://api.cloudflare.com/client/v4/user/tokens/verify` (GET)
  - Headers: `Authorization: Bearer <api_token>`
- **Zone Details**: `https://api.cloudflare.com/client/v4/zones/{zone_id}` (GET)
  - Headers: `Authorization: Bearer <api_token>`

## Admin Actions

### Cloudways Actions
- `admin_post_wp_mcp_ai_cloudways_connect` - Handle connection
- `admin_post_wp_mcp_ai_cloudways_disconnect` - Handle disconnection

### Cloudflare Actions
- `admin_post_wp_mcp_ai_cloudflare_test_connection` - Handle connection test
- `admin_post_wp_mcp_ai_cloudflare_disconnect` - Handle disconnection

## Admin Notices

Both handlers use transient-based notices that display after OAuth/connection actions:

- Success: Green notice with checkmark
- Warning: Yellow notice with warning icon
- Error: Red notice with error message
- Info: Blue notice with info icon

Notices are stored in transients:
- `wp_mcp_ai_cloudways_oauth_notice`
- `wp_mcp_ai_cloudflare_connection_notice`

## Security Considerations

### Cloudways
- API keys and access tokens stored encrypted in WordPress options
- Tokens auto-refresh before expiry (60-second buffer)
- CSRF protection via nonce verification
- Capability checks (requires `manage_options`)
- Secure HTTP requests with timeouts

### Cloudflare
- API tokens stored encrypted in WordPress options
- Token validation before connection status set
- Zone access verified to prevent unauthorized access
- CSRF protection via nonce verification
- Capability checks (requires `manage_options`)

## Testing

### Unit Tests
- `tests/test-cloudways-oauth-handler.php` - Tests Cloudways OAuth functionality
- `tests/test-cloudflare-connection-handler.php` - Tests Cloudflare connection functionality

### Test Coverage
- Handler class existence
- Token validation logic
- Connection status methods
- Container registration
- Action hook registration
- Admin notice registration

## Future Enhancements

### Cloudways
- [ ] OAuth refresh token support (if Cloudways adds it)
- [ ] Multiple account support
- [ ] Server/app selection dropdown after connection
- [ ] Real-time server status monitoring

### Cloudflare
- [ ] Multiple zone support
- [ ] Advanced permission checking
- [ ] Token permission scope display
- [ ] Cache statistics dashboard

## Related Files

### Core Files
- `includes/integrations/class-wp-mcp-ai-cloudways-oauth-handler.php`
- `includes/integrations/class-wp-mcp-ai-cloudflare-connection-handler.php`
- `includes/integrations/cloudways-integration-init.php`
- `includes/integrations/cloudflare-integration-init.php`

### Settings UI
- `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`

### Tests
- `tests/test-cloudways-oauth-handler.php`
- `tests/test-cloudflare-connection-handler.php`

### Main Plugin
- `mcp-ai-wpoos.php` (loads integration init files)

## Changelog

### Version 1.1.1 (Pending)
- Added Cloudways OAuth 1-click connection
- Added Cloudflare API token 1-click testing
- Implemented token auto-refresh for Cloudways
- Added connection status display in settings
- Added comprehensive error handling and user feedback

## References

- PR #2538: Meta OAuth 1-click connection flow
- Cloudways API Documentation: https://developers.cloudways.com/docs/
- Cloudflare API Documentation: https://developers.cloudflare.com/api/
