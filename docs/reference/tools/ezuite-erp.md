# EZuite ERP Integration Tool

## Overview

The **EZuite ERP** tool provides seamless integration with the EZuite ERP system for inventory management, item lookups, order processing, and customer queries. This Pro feature enables AI assistants to interact with your EZuite ERP system directly, providing real-time access to business data and operations.

## Tool Slug

`ezuite_erp`

## Requirements

### Connection Setup

To use this tool, you need to create an EZuite ERP connection:

1. **API URL** - The EZuite API endpoint: `https://api.ezuite.com/api/External_Api/Action_Api/Invoke`
2. **API Key** - Your unique API key provided by EZuite
3. **Connection Type** - Set to `ezuite_erp`

### How to Configure

1. In WordPress admin, navigate to **Settings → NV oOS → Remote Sites**
2. Click **Add New Connection**
3. Fill in the connection details:
   - **Name**: A friendly name for the connection (e.g., "Main ERP System")
   - **URL**: `https://api.ezuite.com/api/External_Api/Action_Api/Invoke`
   - **Connection Type**: Select "EZuite ERP"
   - **Authentication Type**: Select "None" (API key is sent in request body)
   - **API Key**: Enter your EZuite API key
   - **Enabled**: Check to activate the connection
4. Save the connection

### User Permissions

Users executing this tool must have the `edit_posts` capability, which includes:
- Authors
- Editors  
- Administrators

### API Key Security

- API keys are automatically encrypted when stored in WordPress
- Keys are transmitted securely over HTTPS in the request body
- Never share your API key publicly
- Rotate keys regularly for security

## Connection Actions

### 1. List Connections

Lists all available EZuite ERP connections for the current assistant.

**Action**: `list_connections`

**Parameters**: None required

**Example Usage**:
```
Show me all available ERP connections
```

**Response**:
```json
{
  "summary": "Found 2 EZuite ERP connection(s)",
  "connections": [
    {
      "id": "conn_abc123",
      "name": "Main ERP System",
      "url": "https://api.ezuite.com/api/External_Api/Action_Api/Invoke/",
      "enabled": true
    }
  ],
  "count": 2
}
```

### 2. Test Connection

Verifies that a connection to the EZuite ERP system is working properly.

**Action**: `test_connection`

**Parameters**:
- **connection_id** (required) - The connection ID from list_connections

**Example Usage**:
```
Test the ERP connection conn_abc123
```

**Response on Success**:
```json
{
  "success": true,
  "message": "Connection successful.",
  "url": "https://api.ezuite.com/api/External_Api/Action_Api/Invoke/"
}
```

### 3. Invoke API

Executes an EZuite ERP API action to query or modify data.

**Action**: `invoke_api`

**Parameters**:
- **connection_id** (required) - The connection ID to use
- **api_action** (required) - The ERP API action to invoke
- **api_body** (optional) - Request parameters for the action

## Supported API Actions

**Note**: API action names are **case-insensitive**. You can use `lx_itempull`, `LX_ItemPull`, or any other casing variation. The tool will automatically normalize the action name to the correct format for the EZuite API.

### LX_ItemPull

Retrieves items from the ERP system inventory.

**Example Usage**:
```
Pull all items from the ERP system using connection conn_abc123
```

**Parameters**:
```json
{
  "action": "invoke_api",
  "connection_id": "conn_abc123",
  "api_action": "LX_ItemPull",
  "api_body": [
    {
      "Location_Code": "ALL"
    }
  ]
}
```

**Important Notes**:
- `api_body` is required for all API actions
- API action names are case-insensitive (e.g., `lx_itempull` works the same as `LX_ItemPull`)
- Using `Location_Code: "ALL"` may return large datasets - use specific location codes when possible
- For production use, consider filtering by specific locations to improve performance

**Response Structure**:
```json
{
  "success": true,
  "api_action": "LX_ItemPull",
  "data": {
    "Status_Code": 200,
    "Message": "LX_ItemPull API Executed Successfully.",
    "Response_Body": [
      {
        "Item_Code": "C316/L16/ITM-10",
        "Item_Name": "Bangle 1816 Crystal Gold",
        "Barcode": "170620"
      },
      {
        "Item_Code": "EZCMP316/EZLOC7/ITM-9",
        "Item_Name": "Pure Xs Edt 100ml",
        "Barcode": "3349668576173",
        "Qty": 37.0
      }
    ]
  }
}
```

### LX_ItemUpdate

Updates existing item information in the ERP system.

**Example Usage**:
```
Update item price in ERP system
```

**Parameters Example**:
```json
{
  "api_action": "LX_ItemUpdate",
  "api_body": [
    {
      "Item_Code": "C316/L16/ITM-10",
      "Selling_Price": 12000.0
    }
  ]
}
```

### LX_ItemCreate

Creates a new item in the ERP system.

**Example Usage**:
```
Create a new item in the ERP
```

### LX_InventoryQuery

Queries inventory levels and stock information.

**Example Usage**:
```
Check inventory levels for all locations
```

### LX_OrderCreate

Creates a new order in the ERP system.

**Example Usage**:
```
Create a new sales order
```

### LX_OrderUpdate

Updates an existing order.

**Example Usage**:
```
Update order status
```

### LX_CustomerQuery

Queries customer information from the ERP.

**Example Usage**:
```
Get customer details
```

## Rate Limiting

The tool implements rate limiting to protect both your server and the ERP API:

- **Default Limit**: 30 requests per minute per user
- **Scope**: Per-user, per-action (excluding list_connections)
- **Filter**: `wp_mcp_ai_pro_ezuite_erp_rate_limit` - Customize the limit

**Customizing Rate Limit**:
```php
add_filter( 'wp_mcp_ai_pro_ezuite_erp_rate_limit', function( $limit, $user_id ) {
    // Increase limit for specific users
    if ( user_can( $user_id, 'administrator' ) ) {
        return 100; // Admins get 100 requests/minute
    }
    return $limit; // Others get default
}, 10, 2 );
```

## Response Format

All EZuite API responses follow this structure:

```json
{
  "Status_Code": 200,
  "Message": "API action description",
  "Response_Body": [
    // Array of result objects
  ]
}
```

### Status Codes

- **200** - Success
- **400** - Bad Request (invalid parameters)
- **401** - Unauthorized (invalid API key)
- **500** - Server Error

## Error Handling

The tool provides specific error messages for common issues:

### Connection Errors

- **Missing Connection**: "Connection ID is required for action [action_name]"
- **Not Found**: "Connection [id] not found. Call list_connections to see available connections"
- **Wrong Type**: "This connection is not an EZuite ERP connection"
- **Disabled**: "Connection [name] is disabled"

### Permission Errors

- **No Permission**: "You do not have permission to access EZuite ERP"
- **Wrong Site**: "You do not have access to this site"
- **Not Enabled**: "Connection [name] is not enabled for this assistant"

### API Errors

- **Missing API Key**: "API key is not configured for this connection"
- **Invalid Action**: "Invalid API action: [action_name]"
- **Missing Action**: "API action parameter is required for invoke_api action"
- **Rate Limit**: "EZuite ERP API rate limit exceeded. Maximum X requests per minute allowed"

### HTTP Errors

- **Request Failed**: "Request failed: [error message]"
- **HTTP Error**: "HTTP error [code]: [message]"
- **Invalid JSON**: "Invalid JSON response from EZuite ERP API"
- **ERP Error**: Returns the Message field from EZuite API response

## Security Considerations

1. **API Key Protection**
   - Keys are encrypted at rest using WordPress auth salt
   - Keys are never exposed in logs or client-side code
   - Use HTTPS for all connections
   - Store API keys securely in the connection manager

2. **Access Control**
   - Users must have edit_posts capability
   - Per-assistant connection restrictions supported
   - Multisite support with per-site isolation

3. **Rate Limiting**
   - Prevents API abuse
   - Protects against accidental loops
   - Customizable per user role

4. **Network Security**
   - All requests use HTTPS
   - 30-second timeout prevents hanging requests
   - Connection test validates before use

## Best Practices

### 1. Connection Management

- Use descriptive connection names
- Test connections before enabling
- Disable unused connections
- Regularly rotate API keys

### 2. API Usage

- Always call `list_connections` first
- Test connections periodically
- Handle errors gracefully
- Implement appropriate retry logic

### 3. Performance

- Cache frequently accessed data
- Use specific location codes instead of "ALL" when possible
- Batch operations when appropriate
- Monitor rate limit usage

### 4. Error Recovery

- Implement fallback mechanisms
- Log errors for troubleshooting
- Provide user-friendly error messages
- Retry failed requests with exponential backoff

### 5. Data Validation

- Validate item codes before updates
- Check inventory levels before orders
- Verify customer data completeness
- Handle missing fields gracefully

## Assistant Configuration

### Enabling for Specific Assistants

1. Edit your assistant
2. Scroll to the **Remote Connections** metabox
3. Select the EZuite ERP connections to enable
4. Save the assistant

### Example Assistant Instructions

```
You have access to our ERP system through the ezuite_erp tool.

When asked about inventory:
1. First call list_connections to get available connections
2. Use invoke_api with LX_ItemPull to retrieve items
3. Present the information clearly to the user

Always check the Status_Code in responses. If it's not 200, 
explain the error to the user in plain language.
```

## Capability Flags

This tool declares the following capability flags:

- `pro` - Pro tier feature
- `read-only` - Can be read-only depending on API action
- `write` - Can modify data via create/update actions
- `external-api` - Makes external API calls
- `requires-capability` - Requires edit_posts capability
- `requires-credentials` - Requires API key configuration
- `network-dependent` - Requires internet connectivity
- `may-timeout` - External API calls may timeout
- `rate-limited` - Subject to rate limiting

## Tool Category

**Pro Tool** - Available only in the Pro/Full version of the plugin

**External Integration** - Requires external ERP system and API credentials

## Troubleshooting

### Connection Test Fails

1. Verify API URL is correct
2. Check API key is valid
3. Ensure EZuite API is accessible from your server
4. Check server firewall settings
5. Verify HTTPS certificates are valid

### Rate Limit Exceeded

1. Reduce request frequency
2. Implement caching
3. Increase rate limit via filter (if appropriate)
4. Check for infinite loops in assistant logic

### Invalid JSON Response

1. Check EZuite API status
2. Verify API URL is correct
3. Ensure API key has proper permissions
4. Check for API maintenance windows

### Missing Items in Response

1. Verify Location_Code parameter
2. Check item exists in ERP
3. Ensure API key has read permissions
4. Review EZuite API documentation

## Related Documentation

- [EZuite ERP Official Documentation](https://api.ezuite.com/) (Note: Verify actual documentation URL with EZuite)
- [Remote Site Manager Documentation](../guides/remote-site-manager.md)
- [Tool Development Guide](../guides/tool-development.md)

## Support

For issues related to:
- **Tool functionality**: Create an issue in the plugin repository
- **EZuite API**: Contact EZuite support
- **API credentials**: Check your EZuite account settings
- **Connection setup**: See Remote Site Manager documentation

## Example Workflows

### 1. Check Inventory Levels

```
Assistant: I'll check the current inventory levels for you.

Step 1: List available ERP connections
Step 2: Pull all items from location "MAIN"
Step 3: Filter items with quantity below 10
Step 4: Present low stock items to user
```

### 2. Update Item Price

```
Assistant: I'll update the item price in the ERP system.

Step 1: Verify item exists with LX_ItemPull
Step 2: Confirm price change with user
Step 3: Execute LX_ItemUpdate
Step 4: Verify update was successful
Step 5: Report result to user
```

### 3. Create Sales Order

```
Assistant: I'll create a new sales order.

Step 1: Validate customer exists with LX_CustomerQuery
Step 2: Check item availability with LX_InventoryQuery
Step 3: Confirm order details with user
Step 4: Create order with LX_OrderCreate
Step 5: Return order confirmation
```

## Version History

- **1.0.0** - Initial release
  - Support for LX_ItemPull action
  - Support for 7 core API actions
  - Connection manager integration
  - Rate limiting
  - Comprehensive error handling
