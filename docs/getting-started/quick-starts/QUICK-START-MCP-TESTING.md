# Quick Start: Testing MCP Endpoints

If you're unable to run tests from the WP oOS MCP Diagnostic page, follow these steps:

## 1. Verify System Requirements

- ✅ WordPress 6.0 or later
- ✅ PHP 7.4 or later  
- ✅ Permalinks NOT set to "Plain" (Settings → Permalinks)
- ✅ User has Administrator role

## 2. Quick Test (Browser Console)

1. Open WordPress admin
2. Press F12 to open Developer Tools
3. Go to Console tab
4. Paste and run:

```javascript
fetch('/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 1,
    method: 'initialize',
    params: {}
  })
})
.then(r => r.json())
.then(data => {
  console.log('✅ MCP Endpoint Working!');
  console.log('Protocol Version:', data.result.protocolVersion);
  console.log('Server:', data.result.serverInfo.name);
})
.catch(err => {
  console.error('❌ MCP Endpoint Failed:', err);
});
```

### Expected Output:
```
✅ MCP Endpoint Working!
Protocol Version: 2024-11-05
Server: WP oOS
```

## 3. Run Automated Tests

```bash
cd /path/to/mcp-ai-wpoos
composer test -- tests/test-mcp-diagnostic-endpoints.php
```

## 4. Common Issues & Fixes

### Issue: "Cannot read property 'nonce' of undefined"

**Fix:** You're not in WordPress admin. Navigate to any admin page first.

### Issue: "Network request failed" or "Connection refused"

**Possible Causes:**
- Permalink structure is "Plain" → Change to "Post name"
- REST API is disabled → Check with plugin that may disable it
- Server blocks loopback requests → Contact hosting provider

**Fix Permalinks:**
1. Go to Settings → Permalinks
2. Select "Post name" or "Day and name"
3. Click "Save Changes"
4. Try test again

### Issue: Test buttons don't respond

**Fixes:**
1. Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
2. Check browser console (F12) for JavaScript errors
3. Try in incognito/private mode
4. Disable other plugins temporarily

### Issue: "Insufficient permissions"

**Fix:** Ensure you're logged in as Administrator

## 5. Test All MCP Methods

Once basic connectivity works, test other methods:

```javascript
// Test tools/list
fetch('/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 2,
    method: 'tools/list',
    params: {}
  })
})
.then(r => r.json())
.then(data => {
  console.log('✅ Tools found:', data.result.tools.length);
  console.log('Tools:', data.result.tools.map(t => t.name));
});

// Test resources/list
fetch('/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 3,
    method: 'resources/list',
    params: {}
  })
})
.then(r => r.json())
.then(data => {
  console.log('✅ Resources found:', data.result.resources.length);
});

// Test prompts/list
fetch('/wp-json/mcp-ai/v1/mcp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpApiSettings.nonce
  },
  body: JSON.stringify({
    jsonrpc: '2.0',
    id: 4,
    method: 'prompts/list',
    params: {}
  })
})
.then(r => r.json())
.then(data => {
  console.log('✅ Prompts found:', data.result.prompts.length);
});
```

## 6. Get Full Details

For comprehensive troubleshooting, see:
- `docs/mcp-diagnostic-troubleshooting.md` - Complete troubleshooting guide
- `tests/test-mcp-diagnostic-endpoints.php` - Automated test examples

## 7. Still Having Issues?

1. Enable debug logging:
   - Go to Settings → WP oOS
   - Enable "Logging"
   - Reproduce the issue
   - Check logs for error messages

2. Check system info on diagnostic page:
   - Go to Tools → WP oOS MCP Test
   - Review "System Requirements" section
   - Review "Recent MCP Activity" section

3. Contact support with:
   - Browser console error messages
   - PHP error log entries
   - System information from diagnostic page
   - Steps that reproduce the issue

## Quick Reference

**Diagnostic Page:** `/wp-admin/tools.php?page=wp-mcp-ai-mcp-diagnostic`

**MCP Endpoint:** `/wp-json/mcp-ai/v1/mcp`

**Supported Methods:**
- `initialize` - Get server info and capabilities
- `tools/list` - List available tools
- `resources/list` - List available resources
- `prompts/list` - List available prompts

**Expected Response Format:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    // Method-specific result data
  }
}
```
