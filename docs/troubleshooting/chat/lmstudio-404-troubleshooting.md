# LM Studio MCP Connection Troubleshooting

## Common Issue: 404 Error with LM Studio

If you're getting:
```
[ERROR] SSE error: Non-200 status code (404)
```

Or when accessing `https://your-site.com/wp-json/mcp-ai/v1/mcp` in browser:
```json
{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}
```

**This is NORMAL!** The `/mcp` endpoint only accepts POST requests with JSON-RPC payloads, not GET requests from browsers.

## Quick Fix Checklist

### 1. Test the Endpoint Correctly (POST, not GET)

**❌ Wrong way (browser GET request):**
```
https://your-site.com/wp-json/mcp-ai/v1/mcp
```
This will show 404 because the endpoint requires POST.

**✅ Correct way (POST with JSON-RPC):**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: ******" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "clientInfo": {"name": "Test", "version": "1.0"}
    }
  }'
```

**Expected:** JSON response with `"jsonrpc":"2.0"` and server info.

### 2. Verify WordPress REST API is Working

Test this URL in your browser:
```
https://your-site.com/wp-json/
```

You should see JSON output. If you get a 404 or error, your REST API is disabled or blocked.

**Fix:**
- Go to **Settings → Permalinks** in WordPress admin
- Click **Save Changes** (even without changing anything)
- This refreshes the rewrite rules

### 2. Check Plugin is Active

The `/mcp` endpoint only exists when the WP oOS plugin is active.

**Verify:**
- Go to **Plugins** in WordPress admin
- Ensure "Open Operator System (WP oOS)" is **Active**

### 3. Test the MCP Endpoint Directly

Try this in your browser or with curl:
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

**Expected:** JSON response with `"jsonrpc":"2.0"`
**If 404:** The endpoint is not registered - see fixes below

### 4. Use the Correct LM Studio Configuration

LM Studio might be using the wrong endpoint. Use this configuration format:

#### Recommended: JSON-RPC Endpoint (No SSE)

**File: mcp.json**
```json
{
  "mcpServers": {
    "wordpress-mcp": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  }
}
```

**Important:** 
- Use `"mcpServers"` object (not `"servers"` array)
- Use `"url"` pointing directly to `/mcp` endpoint
- Use `"headers"` with `"Authorization": "Bearer TOKEN"`
- Do NOT include SSE configuration

#### Alternative: Assistants Directory (With SSE)

```json
{
  "mcpServers": {
    "wordpress-assistants": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true,
      "timeout": 30000
    }
  }
}
```

### 5. Check Security Plugins

Security plugins might block REST API access.

**Common culprits:**
- Wordfence
- iThemes Security
- All In One WP Security

**Fix:**
- Add `/wp-json/mcp-ai/*` to REST API whitelist
- Or temporarily disable to test

### 6. Verify Authentication Token

Make sure your token is valid:

1. Go to **AI Assistants** in WordPress admin
2. Edit an assistant
3. Find **API Credentials** meta box
4. Check if your credential is listed (not revoked)
5. Generate a new one if needed

### 7. Check .htaccess or Nginx Config

Your web server might be blocking the endpoint.

**Apache (.htaccess):**
```apache
# Make sure REST API is not blocked
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^wp-json/mcp-ai/v1/(.*)$ /index.php?rest_route=/mcp-ai/v1/$1 [QSA,L]
</IfModule>
```

**Nginx:**
```nginx
location ~ ^/wp-json/mcp-ai/v1/ {
    try_files $uri $uri/ /index.php?$args;
}
```

## Settings Check

### Question: Does "Enable REST Assistant Creation" need to be enabled?

**Answer: NO** - That setting is only for creating NEW assistants via REST API (POST to `/assistants`).

The MCP endpoints work regardless of this setting:
- ✅ GET `/assistants` - List assistants (always works)
- ✅ POST `/mcp` - MCP JSON-RPC (always works)
- ✅ POST `/chat` - Send messages (always works)
- ✅ POST `/tools` - Execute tools (always works)

**You only need "Enable REST Assistant Creation" if you want to:**
- Create assistants remotely via API
- Use POST `/assistants` endpoint

**For normal MCP use, this setting can be OFF.**

## Debug Steps

### Step 1: Enable WordPress Debug Mode

Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check `wp-content/debug.log` for errors.

### Step 2: Test with WP-CLI

If you have WP-CLI installed:
```bash
wp rest route list | grep mcp-ai
```

Should show:
```
/mcp-ai/v1/assistants
/mcp-ai/v1/chat
/mcp-ai/v1/mcp
/mcp-ai/v1/sse
/mcp-ai/v1/tools
```

If `/mcp` is missing, the endpoint isn't registered.

### Step 3: Check PHP Error Log

Look for PHP errors that might prevent endpoint registration:
```bash
tail -f /var/log/php_errors.log
```

### Step 4: Re-activate Plugin

Sometimes reactivating fixes registration issues:
1. Deactivate WP oOS plugin
2. Activate it again
3. Test the endpoint

## LM Studio-Specific Issues

### Issue: "Empty request body" Error

If you get:
```json
{ "jsonrpc": "2.0", "id": null, "error": { "code": -32700, "message": "Parse error: Empty request body" } }
```

This means the POST request reached the endpoint but the JSON payload wasn't received.

**Common Causes:**

1. **Missing Content-Type header**
   ```bash
   # ❌ Wrong - no Content-Type
   curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
   
   # ✅ Correct - with Content-Type
   curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
     -H "Content-Type: application/json" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
   ```

2. **Using GET instead of POST**
   - The endpoint requires POST with JSON body
   - GET requests will show 404

3. **Security plugin stripping request body**
   - Some security plugins block or strip POST data
   - Try temporarily disabling security plugins
   - Whitelist `/wp-json/mcp-ai/*` in your security plugin

4. **Web server configuration issue**
   - Apache/Nginx might not be passing request body
   - Check your `.htaccess` or nginx config
   - Ensure `php://input` is accessible

**Solutions:**

**Test with proper headers:**
```bash
curl -X POST "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "clientInfo": {
        "name": "Test Client",
        "version": "1.0"
      }
    }
  }'
```

**If using Postman:**
1. Set method to POST
2. Set URL to `https://your-site.com/wp-json/mcp-ai/v1/mcp`
3. Go to Headers tab, add:
   - `Content-Type: application/json`
   - `Authorization: Bearer cred_xxxxx.SECRET`
4. Go to Body tab, select "raw" and "JSON"
5. Paste the JSON-RPC request

**Expected successful response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": { "listChanged": true },
      "resources": { "subscribe": false, "listChanged": true },
      "prompts": { "listChanged": true }
    },
    "serverInfo": {
      "name": "WP oOS",
      "version": "1.0.0"
    }
  }
}
```

### Issue: LM Studio keeps using SSE

LM Studio might auto-detect SSE even if not configured.

**Solution:**
1. Remove ALL SSE-related config
2. Use direct `/mcp` URL (not base URL)
3. Set transport explicitly if LM Studio allows

### Issue: "Invalid content type"

This means LM Studio is expecting SSE but getting JSON.

**Solution:**
Use the JSON-RPC configuration (Option A above) which returns `application/json`.

### Issue: LM Studio can't find any methods

After connecting, LM Studio should discover methods like `initialize`, `tools/list`, etc.

**Test:**
```bash
curl -X POST "https://your-site.com/wp-json/mcp-ai/v1/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "clientInfo": {"name": "Test", "version": "1.0"}
    }
  }'
```

Should return server capabilities.

## Still Not Working?

### Check WordPress Multisite

If running multisite, make sure:
- Plugin is network activated OR activated on the specific site
- You're using the correct site URL
- REST API namespace isn't conflicting

### Check Permalink Structure

Go to **Settings → Permalinks** and try different structures:
- Post name (recommended)
- Day and name
- Month and name

Save and test after each change.

### Contact Support

If none of these work, provide:
1. WordPress version
2. PHP version
3. Web server (Apache/Nginx)
4. Output of: `curl https://your-site.com/wp-json/`
5. Output of: `curl https://your-site.com/wp-json/mcp-ai/v1/mcp`
6. Any error messages in WordPress debug log

## Working Configuration Example

This is a confirmed working configuration for LM Studio:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://example.com/wp-json/mcp-ai/v1/mcp",
      "headers": {
        "Authorization": "Bearer cred_abc123.secretkey456"
      },
      "timeout": 30000
    }
  }
}
```

**Checklist:**
- ✅ Uses `"mcpServers"` object (not `"servers"` array)
- ✅ Uses `"url"` pointing to `/mcp` endpoint directly
- ✅ Has `"headers"` with `"Authorization": "Bearer TOKEN"`
- ✅ Token starts with `cred_`
- ✅ No SSE configuration
- ✅ Reasonable timeout (30 seconds)

## Summary

**Most common causes of 404:**
1. REST API disabled or blocked → Fix permalinks
2. Plugin not active → Activate plugin
3. Wrong URL in config → Use `/mcp` endpoint
4. Security plugin blocking → Whitelist endpoint
5. Web server blocking → Check .htaccess/nginx

**The "Enable REST Assistant Creation" setting is NOT required for MCP to work.**
