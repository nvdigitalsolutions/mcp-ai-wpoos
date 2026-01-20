# FlowHub 403 Forbidden Error Troubleshooting Guide

## Overview

This guide helps diagnose and resolve 403 Forbidden errors when connecting to the FlowHub cannabis dispensary API. The error typically appears as an nginx HTML error page instead of a proper JSON response.

## What Was Fixed

**Previous Behavior**: When FlowHub returned 403 Forbidden with an HTML error page (nginx), the plugin reported "Flowhub returned malformed JSON" instead of the actual HTTP error.

**Current Behavior**: The plugin now:
1. Checks HTTP status code FIRST before parsing JSON
2. Reports the actual HTTP error (e.g., "Flowhub API returned HTTP 403 error")
3. Logs the full HTML body for debugging
4. Provides clear error messages based on status codes

**Fix Location**: `includes/class-wp-mcp-ai-flowhub-client.php:218-285`

## Common Causes of 403 Errors

### 1. Missing or Invalid Credentials (Most Common)

**Symptoms**:
- 403 Forbidden error
- "Flowhub API returned HTTP 403 error" message
- Credentials appear to be set but still fail

**Root Causes**:
- Empty credentials after decryption
- Incorrect Client ID or API Key
- Expired or revoked credentials on FlowHub's side
- Credentials not properly saved during connection setup

**FlowHub API Authentication**:
FlowHub uses **header-based authentication** (not OAuth2). Required headers:
- `clientId`: Your client identifier (plain text, not encrypted)
- `key`: Your API key (encrypted in database, decrypted before sending)
- `Accept`: application/json

**Diagnostic Steps**:
```php
// Enable logging in WordPress admin: Settings → NV oOS → Enable Logging

// Check if credentials exist
$client = new WP_MCP_AI_Flowhub_Client( 'conn_flowhub123' );
$client_id = $client->get_client_id();
$key = $client->get_key();

// Check logs for these events:
// - 'flowhub_credentials_check' - Shows credential lengths
// - 'Flowhub credentials missing or empty' - Indicates empty credentials
```

**Resolution**:
1. Verify credentials in Remote Sites or Settings
2. Test connection using "Test Connection" button
3. Re-enter credentials if decryption may have failed
4. Contact FlowHub support at api@flowhub.com to verify credentials are active

### 2. Encryption/Decryption Issues

**Symptoms**:
- Credentials saved successfully but API calls fail
- Works initially, then stops working
- Different results on staging vs production

**Root Cause**:
The plugin uses WordPress authentication salt for encryption:
```php
$key = wp_salt( 'auth' );  // From wp-config.php
```

If `wp_salt('auth')` changes, all encrypted credentials become invalid.

**Common Scenarios**:
- WordPress auth salt regenerated (security hardening)
- Migration from staging to production with different salts
- wp-config.php restored from backup with different salts
- Multisite with different salts per site

**Resolution**:
1. Check if auth salt changed: `wp_salt('auth')`
2. Re-enter FlowHub credentials in Remote Sites
3. Test connection again
4. If migrating sites, ensure wp-config.php salts are preserved OR re-enter credentials

### 3. Proxy or Firewall Blocking

**Symptoms**:
- 403 errors with HTML nginx page
- Works locally but fails on production server
- Works for some requests but not others

**Root Causes**:
- nginx reverse proxy stripping custom headers
- Corporate firewall blocking FlowHub API domain
- Hosting provider blocking cannabis-related APIs
- IP-based rate limiting or geographic restrictions
- Web Application Firewall (WAF) rules (Cloudflare, Sucuri, etc.)

**Diagnostic Steps**:
```bash
# Test from server command line
curl -H "clientId: YOUR_CLIENT_ID" \
     -H "key: YOUR_API_KEY" \
     -H "Accept: application/json" \
     https://api.flowhub.co/v0/inventoryNonZero?limit=1

# If this works but WordPress doesn't, check:
# - WordPress HTTP API filters
# - Security plugins blocking requests
# - Server firewall rules
```

**Resolution**:
1. Whitelist `api.flowhub.co` in server firewall
2. Check nginx configuration for header stripping
3. Disable WAF temporarily to test
4. Contact hosting provider if issue persists
5. Check WordPress HTTP filters: `pre_http_request`, `http_request_args`

### 4. Connection Type Mismatch

**Symptoms**:
- Connection exists in Remote Sites but tools fail
- Error: "This connection is not a Flowhub connection"
- Error: "Connection disabled"

**Root Causes**:
- Wrong `connection_type` field (set to 'wordpress' instead of 'flowhub')
- Connection disabled (`enabled = false`)
- Connection ID misspelled or changed

**Resolution**:
1. Navigate to NV oOS → Remote Sites
2. Edit the FlowHub connection
3. Verify "Connection Type" is set to "Flowhub (POS/Retail)"
4. Ensure "Enabled" checkbox is checked
5. Save and test connection

### 5. Location ID Issues

**Symptoms**:
- Some endpoints work, others return 403
- Multi-location dispensaries have issues
- Inventory returns empty or 403

**Root Cause**:
FlowHub supports multi-location dispensaries. Some API endpoints may require or filter by `location_id`.

**Current Implementation**:
- Location ID is stored but NOT sent as a query parameter
- Using endpoint: `/v0/inventoryNonZero`
- According to docs: "Non-Zero Inventory **By Location**"

**Resolution**:
1. Obtain your location ID from FlowHub
2. Add to Remote Sites connection or settings
3. May need code update to send location_id as query param

## API Documentation Research

### Endpoint Being Used
```
GET https://api.flowhub.co/v0/inventoryNonZero
```

### Expected Headers
```
clientId: <your-client-id>
key: <your-api-key>
Accept: application/json
```

### Official Documentation
- Base URL: `https://api.flowhub.co`
- Docs: https://flowhub.stoplight.io/docs/public-developer-portal/
- Support: api@flowhub.com
- Request Access: https://flowhub.com/api-integration-request

### Potential Missing Parameter
The endpoint is documented as "Non-Zero Inventory **By Location**" which suggests location_id might be required or strongly recommended.

**Current Code**:
```php
// Line 317-322 in includes/class-wp-mcp-ai-flowhub-client.php
public function get_inventory( $options = array() ) {
    // ... builds query params: limit, offset, room_id
    return $this->make_request(
        '/v0/inventoryNonZero',
        'GET',
        array(),
        array( 'query' => $query_params )
    );
}
```

**Missing**: location_id is stored but not used in the query.

## Diagnostic Checklist

Run through this checklist when encountering 403 errors:

- [ ] Check WordPress logs: Settings → NV oOS → View Logs
- [ ] Look for event: `flowhub_credentials_check`
- [ ] Verify `client_id_length` and `key_length` are > 0
- [ ] Test connection: Remote Sites → Edit → Test Connection
- [ ] Verify connection type is "flowhub"
- [ ] Verify connection is enabled
- [ ] Check if credentials work via curl (see above)
- [ ] Review nginx/server logs for blocked requests
- [ ] Check if WordPress auth salt changed recently
- [ ] Verify location_id is set (if multi-location)
- [ ] Contact FlowHub support to verify API access

## Enhanced Logging

The plugin now logs detailed credential information (without exposing values):

```php
WP_MCP_AI_Logger::log_event(
    'flowhub_credentials_check',
    'Flowhub credentials retrieved.',
    array(
        'connection_id'    => $connection_id,
        'using_connection' => true/false,
        'client_id_length' => 24,  // Length without exposing value
        'key_length'       => 64,
    )
);
```

Check these logs to verify credentials are being retrieved properly.

## Recommended Code Enhancement

**Add location_id to inventory requests** (if required by FlowHub):

```php
// In includes/class-wp-mcp-ai-flowhub-client.php:get_inventory()
public function get_inventory( $options = array() ) {
    $query_params = array();

    // ... existing limit, offset, room_id code ...

    // ADD THIS:
    $location_id = $this->get_location_id();
    if ( ! empty( $location_id ) ) {
        $query_params['location_id'] = $location_id;
    }

    return $this->make_request(
        '/v0/inventoryNonZero',
        'GET',
        array(),
        array( 'query' => $query_params )
    );
}
```

## Testing Your Fix

After implementing changes:

1. Clear WordPress object cache
2. Test connection in Remote Sites
3. Call a FlowHub tool manually
4. Check logs for proper credential retrieval
5. Verify API response is JSON, not HTML

## Getting Help

If you continue to experience 403 errors after trying these steps:

1. **Enable Logging**: Settings → NV oOS → Enable Logging
2. **Reproduce Error**: Trigger the 403 error again
3. **Collect Logs**: Settings → NV oOS → View Logs
4. **Contact Support**: Include:
   - Error message
   - Log entries with 'flowhub' in them
   - Connection configuration (without credentials)
   - Whether test connection succeeds/fails
   - Whether credentials work via curl

## Related Files

- `includes/class-wp-mcp-ai-flowhub-client.php` - Main FlowHub API client
- `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` - Connection management
- `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php` - Admin UI
- `includes/tools/class-wp-mcp-ai-tool-flowhub-*.php` - FlowHub tools
- `docs/integrations/flowhub-integration.md` - Integration documentation

## Security Notes

- Never log or expose actual API credentials
- API keys are encrypted at rest using WordPress auth salt
- Client IDs are stored in plain text (they identify, not authenticate)
- Use placeholder values in tests and documentation
- Rotate API keys if exposed or compromised
