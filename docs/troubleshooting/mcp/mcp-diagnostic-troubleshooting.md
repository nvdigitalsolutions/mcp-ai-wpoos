# MCP Diagnostic Page Troubleshooting Guide

## Overview

The MCP Server Diagnostic page provides an interface for testing the Model Context Protocol (MCP) implementation in WP oOS. If you're unable to run the diagnostic tests, this guide will help you troubleshoot common issues.

## Accessing the Diagnostic Page

The MCP diagnostic page is located at:
```
/wp-admin/tools.php?page=wp-mcp-ai-mcp-diagnostic
```

Or navigate to: **Tools → WP oOS MCP Test** in your WordPress admin dashboard.

## Available Tests

### 1. REST Endpoint Connectivity
Tests basic connectivity to the MCP REST endpoint `/wp-json/mcp-ai/v1/mcp`.

**Expected Response**:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": { ... },
    "serverInfo": { ... }
  }
}
```

### 2. MCP Methods Testing

#### Initialize (`initialize`)
- **Purpose**: Get server capabilities and protocol version
- **Tests**: Server information, protocol version, capabilities structure

#### Tools List (`tools/list`)
- **Purpose**: List all available tools
- **Tests**: Tool registry, tool definitions, MCP format compliance

#### Resources List (`resources/list`)
- **Purpose**: List available resources (memory files, etc.)
- **Tests**: Resource availability, URI format, MIME types

#### Prompts List (`prompts/list`)
- **Purpose**: List available prompts (assistants)
- **Tests**: Assistant directory, prompt definitions

## Common Issues and Solutions

### Issue 1: Test Buttons Don't Respond

**Symptoms**: Clicking test buttons does nothing, no loading indicator appears.

**Possible Causes**:
1. JavaScript not loading
2. jQuery conflict
3. Nonce/AJAX configuration issue

**Solutions**:

1. **Check Browser Console for Errors**
   - Press F12 to open Developer Tools
   - Go to Console tab
   - Look for JavaScript errors
   - Common errors:
     - `wpMcpAiMcpDiagnostic is not defined`
     - `$ is not a function`
     - `Uncaught ReferenceError`

2. **Verify JavaScript is Loaded**
   - Open Developer Tools (F12)
   - Go to Network tab
   - Refresh the page
   - Filter by "JS"
   - Confirm no 404 errors for WordPress admin scripts

3. **Check for Plugin Conflicts**
   - Disable other plugins temporarily
   - Test if diagnostic works
   - Re-enable plugins one by one to find conflicts

4. **Clear Browser Cache**
   - Hard refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac)
   - Clear browser cache completely
   - Try in incognito/private mode

### Issue 2: Tests Fail with Permission Error

**Symptoms**: Test buttons work but return "Insufficient permissions" error.

**Solutions**:

1. **Verify User Role**
   - Must be logged in as Administrator
   - Check user has `manage_options` capability
   
2. **Check Nonce Validity**
   - Nonce may have expired if page was open too long
   - Refresh the page and try again

### Issue 3: Tests Fail with Connection/Network Error

**Symptoms**: Tests return connection errors or timeout.

**Possible Causes**:
1. Permalink structure issues
2. REST API disabled
3. Server blocking loopback requests
4. SSL/HTTPS issues

**Solutions**:

1. **Check Permalink Settings**
   - Go to Settings → Permalinks
   - Ensure NOT set to "Plain"
   - Try "Post name" or "Day and name"
   - Click "Save Changes"

2. **Verify REST API is Enabled**
   - Visit: `/wp-json/`
   - Should see JSON response with API information
   - If you get 404, REST API may be disabled

3. **Test REST API Directly**
   ```bash
   curl -X POST https://your-site.com/wp-json/mcp-ai/v1/mcp \
     -H "Content-Type: application/json" \
     -H "X-WP-Nonce: YOUR_NONCE" \
     -d '{
       "jsonrpc": "2.0",
       "id": 1,
       "method": "initialize",
       "params": {}
     }'
   ```

4. **Check .htaccess Rules**
   - Backup current `.htaccess`
   - Try regenerating permalinks (Settings → Permalinks → Save)
   - Check for rules blocking `/wp-json/` paths

5. **Server Configuration**
   - Some hosts block loopback requests (server calling itself)
   - Contact hosting provider if internal HTTP requests fail
   - May need to whitelist site IP or disable request blocking

### Issue 4: Tests Return Invalid JSON or Parse Error

**Symptoms**: Tests complete but show "Invalid JSON-RPC 2.0 response format" error.

**Solutions**:

1. **Check for PHP Errors/Warnings**
   - Enable WP_DEBUG in wp-config.php:
     ```php
     define('WP_DEBUG', true);
     define('WP_DEBUG_LOG', true);
     define('WP_DEBUG_DISPLAY', false);
     ```
   - Check `wp-content/debug.log` for errors

2. **Disable Output Buffering Plugins**
   - Some caching/optimization plugins may interfere
   - Temporarily disable caching plugins
   - Test again

3. **Check for Theme/Plugin Output**
   - Some themes/plugins may output content on REST requests
   - Check for warnings, notices, or unexpected HTML

### Issue 5: Specific MCP Methods Fail

**Symptoms**: Some tests work, others fail.

**Method-Specific Troubleshooting**:

#### `tools/list` Fails
- Check that tools are registered: `WP_MCP_AI_Tool_Registry::get_instance()->get_tools()`
- Verify tool schema generation doesn't throw errors
- Check tool dependencies are loaded

#### `resources/list` Fails
- Check if assistants have memory files configured
- Verify attachments exist and are accessible
- Check file permissions on uploads directory

#### `prompts/list` Fails
- Check that mcp_ai_assistant post type exists
- Verify assistants are published
- Check assistant meta data is properly stored

##Direct Testing via REST API

If the diagnostic page isn't working, you can test the MCP endpoint directly.

### Using cURL

```bash
# Test initialize method
curl -X POST https://your-site.com/wp-json/mcp-ai/v1/mcp \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");')" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {}
  }'

# Test tools/list method
curl -X POST https://your-site.com/wp-json/mcp-ai/v1/mcp \
  -H "Content-Type": application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list",
    "params": {}
  }'
```

### Using WordPress Admin (via Browser Console)

Open your browser's Developer Tools console and run:

```javascript
// Test initialize
fetch('/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce // Uses WordPress provided nonce
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 1,
    method: 'initialize',
    params: {}
  })
})
.then(r => r.json())
.then(data => console.log('Result:', data))
.catch(err => console.error('Error:', err));
```

### Using PHPUnit Tests

Run the automated MCP endpoint tests:

```bash
# Run all MCP tests
composer test -- --filter MCP

# Run specific test file
vendor/bin/phpunit tests/test-mcp-endpoint.php

# Run diagnostic endpoint tests
vendor/bin/phpunit tests/test-mcp-diagnostic-endpoints.php
```

## Verifying MCP Implementation

### Check Protocol Version
```bash
curl -s https://your-site.com/wp-json/mcp-ai/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  | jq '.result.protocolVersion'
# Should return: "2024-11-05"
```

### Count Available Tools
```bash
curl -s https://your-site.com/wp-json/mcp-ai/v1/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  | jq '.result.tools | length'
# Should return: number of registered tools
```

## System Requirements Check

Before troubleshooting, verify:

- ✅ WordPress 6.0 or later
- ✅ PHP 7.4 or later
- ✅ REST API enabled
- ✅ Pretty permalinks enabled (not "Plain")
- ✅ JSON support in PHP
- ✅ User has `manage_options` capability

## Getting Additional Help

If tests still don't work after trying these solutions:

1. **Enable Debug Logging**
   - Go to Settings → WP oOS
   - Enable "Logging"
   - Reproduce the issue
   - Check logs for error messages

2. **Check Recent Activity**
   - Diagnostic page shows "Recent MCP Activity" section
   - Review for error messages or failures

3. **System Information**
   - Review "System Requirements" section on diagnostic page
   - Verify all requirements are met

4. **Contact Support**
   - Provide error messages from browser console
   - Include PHP error log entries
   - Share WP oOS diagnostic information
   - Describe exact steps that fail

## Related Documentation

- [MCP Endpoint Documentation](../../reference/api/mcp-endpoint.md)
- [MCP Server Authentication](../../reference/api/mcp-server-authentication.md)
- [REST API Reference](../../reference/api/rest-api.md)
- [Tool Reference](../../reference/tools/tool-reference.md)

## Quick Reference

### Test Button IDs (for automation)
- `#test-mcp-endpoint` - Tests MCP endpoint connectivity
- `.test-mcp-method[data-method="initialize"]` - Tests initialize method
- `.test-mcp-method[data-method="tools/list"]` - Tests tools/list method
- `.test-mcp-method[data-method="resources/list"]` - Tests resources/list method
- `.test-mcp-method[data-method="prompts/list"]` - Tests prompts/list method

### AJAX Actions
- `wp_ajax_wp_mcp_ai_test_mcp_endpoint` - Endpoint connectivity test
- `wp_ajax_wp_mcp_ai_test_mcp_method` - Individual method test

### Expected HTTP Status Codes
- `200` - Success
- `400` - Bad Request (invalid JSON-RPC)
- `403` - Forbidden (authentication failed)
- `404` - Not Found (endpoint not registered or method not found)
- `500` - Internal Server Error

