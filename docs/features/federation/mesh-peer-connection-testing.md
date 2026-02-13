# Mesh Peer Connection Testing

## Overview

The mesh peer connection testing feature allows administrators to verify that mesh peer configurations are working correctly before relying on them for distributed AI workload processing. Test functionality is available directly from the mesh settings page with a simple button click.

## User Guide

### Testing a Mesh Peer Connection

1. **Navigate to Mesh Settings**:
   - Go to **Settings → Advanced → Federation & Mesh** tab
   - Or direct URL: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`

2. **Configure Mesh Peer** (if not already done):
   - Click "Add Peer Site"
   - Enter:
     - **Name**: Friendly name (e.g., "Production Server")
     - **Site URL**: Full URL (e.g., `https://production.example.com`)
     - **API Key**: Mesh inbound API key from the remote site (e.g., `mesh_xxxxx...`)

3. **Test Connection**:
   - Click the **"Test"** button next to the peer
   - A spinner appears with "Testing..." message
   - Wait 5-10 seconds for results

4. **View Results**:
   - ✅ **Success**: Green notice shows:
     - "Connection test successful!"
     - Site name (if detected)
     - Reachability status
     - Federation discovery status
     - Authentication status
   - ❌ **Failure**: Red notice shows specific error:
     - "Site is not reachable: [reason]"
     - "Authentication failed"
     - "MCP endpoint not found"
     - etc.

5. **Check AI Peers**:
   - Navigate to **AI Peers** menu
   - Find your mesh peer
   - Health status is updated based on test results:
     - 🟢 **Healthy**: All tests passed
     - 🟡 **Degraded**: Some tests failed
     - 🔴 **Down**: Connection failed

### Example Workflow

```
1. Add mesh peer:
   Name: Production Site
   URL: https://prod.example.com
   API Key: mesh_abc123xyz...

2. Click "Test" button

3. See result:
   ✅ Connection test successful! (Production Site)
   • Site is reachable
   • Federation enabled
   • Authentication successful

4. Click "Save Changes" to save the configuration

5. Check AI Peers menu:
   Production Site [MESH] ● Healthy 2 tools global
```

## What Gets Tested

### 1. Reachability Test
**Purpose**: Verify the remote site is accessible over the network.

**Method**: HTTP GET request to the peer URL with 10-second timeout.

**Success Criteria**:
- HTTP status code 200-399
- No network errors

**Common Failures**:
- "Site is not reachable: Connection timed out" → Firewall or DNS issue
- "Site returned HTTP status 404" → Wrong URL
- "Site returned HTTP status 500" → Remote site error

### 2. Federation Discovery Test
**Purpose**: Check if the remote site supports federation.

**Method**: GET request to `/.well-known/ai-peer` endpoint.

**Success Criteria**:
- Endpoint returns 200 status
- Valid JSON response
- Contains required fields: `mcp`, `jwks_uri`, `capabilities`

**Common Failures**:
- "Well-known endpoint not accessible" → Federation not enabled
- "Well-known endpoint returned status 404" → Plugin not installed or federation disabled
- "Well-known endpoint returned invalid JSON" → Configuration error

### 3. MCP Authentication Test
**Purpose**: Verify API key authentication works for mesh communication.

**Method**: GET request to `/wp-json/mcp-ai/v1/assistants` with Bearer token.

**Success Criteria**:
- Returns 200 status with valid API key
- Endpoint exists and responds

**Common Failures**:
- "API key authentication failed" → Wrong API key or peer not configured
- "MCP endpoint not found" → Plugin not installed or REST API disabled
- "MCP authentication failed: Connection refused" → Network/firewall issue

**Note**: If no API key is provided, this test is skipped with status "Authentication not tested (no API key)".

## Test Results Structure

```json
{
  "success": true,
  "url": "https://prod.example.com",
  "reachable": true,
  "wellknown": true,
  "authenticated": true,
  "site_name": "Production Site",
  "capabilities": ["query_remote_site", "distributed_processing"],
  "message": "Connection test successful!",
  "details": {
    "reachability": {
      "status": "success",
      "message": "Site is reachable."
    },
    "wellknown": {
      "status": "success",
      "message": "Federation well-known endpoint accessible."
    },
    "authentication": {
      "status": "success",
      "message": "API key authentication successful."
    }
  }
}
```

## Health Status Updates

Based on test results, the ai_peer CPT health status is automatically updated:

| Test Results | Health Status | Meaning |
|--------------|---------------|---------|
| All tests pass | **Healthy** 🟢 | Peer is fully functional |
| Reachable but auth fails | **Degraded** 🟡 | Connection works but authentication issue |
| Not reachable | **Down** 🔴 | Cannot connect to peer |

## Troubleshooting

### Test Button Not Working

**Check 1**: JavaScript loaded?
- Open browser console (F12)
- Look for errors
- Verify `mesh-peer-test.js` is loaded

**Check 2**: On correct page?
- Must be on Advanced tab
- Must be on Federation & Mesh subtab
- URL should contain `&tab=advanced&subtab=federation_mesh`

**Check 3**: Permissions?
- User must have `manage_options` capability
- Log in as administrator

### "Connection test failed" Generic Error

This usually means the AJAX request to WordPress failed, not the peer connection.

**Debugging Steps**:
1. Open browser console (F12)
2. Look for AJAX errors
3. Check Network tab for failed requests
4. Verify REST API is accessible: `/wp-json/mcp-ai/v1/mesh/test-peer`

### "Site is not reachable"

**Possible Causes**:
1. **Wrong URL**: Check for typos, http vs https
2. **DNS Issues**: Verify domain resolves (`ping example.com`)
3. **Firewall**: Remote site blocking your server's IP
4. **SSL Certificate**: If HTTPS, certificate must be valid
5. **Server Down**: Remote site may be offline

**Solutions**:
- Test URL in browser from server: `curl https://example.com`
- Check firewall rules on both sides
- Verify SSL certificate: `openssl s_client -connect example.com:443`

### "Authentication failed"

**Possible Causes**:
1. **Wrong API Key**: Double-check the mesh inbound API key
2. **Expired Key**: Key may have been regenerated on remote site
3. **Permissions**: Key may lack required permissions

**Solutions**:
1. Get fresh API key from remote site:
   - Remote site → Settings → Advanced → Federation & Mesh
   - Copy "Mesh Inbound API Key"
2. Verify key format starts with `mesh_`
3. Test key with curl:
   ```bash
   curl -H "Authorization: Bearer mesh_xxxxx..." \
        https://example.com/wp-json/mcp-ai/v1/assistants
   ```

### "MCP endpoint not found"

**Possible Causes**:
1. Plugin not installed on remote site
2. REST API disabled on remote site
3. Permalink structure not set up
4. .htaccess blocking REST API

**Solutions**:
1. Verify plugin is installed and active on remote site
2. Check REST API works: `https://example.com/wp-json/`
3. Go to Settings → Permalinks and click "Save" (flushes rewrites)
4. Check .htaccess doesn't block `/wp-json/`

## Technical Details

### REST Endpoint

**URL**: `POST /wp-json/mcp-ai/v1/mesh/test-peer`

**Authentication**: WordPress REST API nonce (wp_rest)

**Permission**: `manage_options` capability required

**Parameters**:
```json
{
  "name": "Peer Name",           // Optional
  "url": "https://example.com",  // Required
  "api_key": "mesh_xxxxx...",    // Optional
  "peer_id": "mesh_abc123..."    // Optional - for CPT update
}
```

**Response Success (200)**:
```json
{
  "success": true,
  "url": "https://example.com",
  "reachable": true,
  "wellknown": true,
  "authenticated": true,
  "site_name": "Remote Site",
  "capabilities": [...],
  "message": "Connection test successful!",
  "details": {...}
}
```

**Response Error (400)**:
```json
{
  "code": "invalid_url",
  "message": "Invalid peer URL.",
  "data": {
    "status": 400
  }
}
```

### PHP Classes

**WP_MCP_AI_Mesh_Peer_Tester**: Core testing logic
- `test_connection($peer)` - Main test method
- `test_reachability($url)` - Check site accessibility
- `test_wellknown_endpoint($url)` - Federation discovery
- `test_mcp_authentication($url, $api_key)` - Auth check
- `update_peer_test_status($mesh_peer_id, $results)` - Update CPT

**WP_MCP_AI_Mesh_Peer_Test_REST**: REST API endpoint
- Registers `/mcp-ai/v1/mesh/test-peer`
- Validates permissions
- Calls tester and returns results

### JavaScript Handler

**File**: `assets/js/mesh-peer-test.js`

**Functions**:
- `init()` - Initialize event handlers
- `handleTestClick(e)` - Process test button clicks
- `handleTestSuccess()` - Display success message
- `handleTestError()` - Display error message
- `setButtonLoading()` - Show/hide loading state

**Localized Strings**: `wpMcpAiMeshTest` object
- `restUrl` - WordPress REST API base URL
- `nonce` - wp_rest nonce for authentication
- `testing` - "Testing..." loading message
- `successMessage` - Success message template
- Various error messages and status labels

## Security

### Authentication

- **Admin Only**: Requires `manage_options` capability
- **Nonce Protection**: Uses WordPress REST API nonce
- **AJAX Origin**: Checks request origin via REST API
- **No Data Exposure**: Test results don't expose sensitive data

### API Key Handling

- **Not Logged**: API keys are not logged to JavaScript console
- **Transmitted Securely**: POST request over HTTPS (enforced by WordPress)
- **Not Stored**: Keys only read from settings, never stored by test function
- **Error Messages**: Don't expose key details

### Rate Limiting

- **WordPress REST**: Standard REST API rate limiting applies
- **No Brute Force**: Single test per button click
- **Timeouts**: All HTTP requests have 5-10 second timeouts
- **No Retry**: Failed tests don't automatically retry

## Performance

### Resource Usage

- **Memory**: < 1 MB per test
- **Time**: 5-15 seconds typical
- **Network**: 3 HTTP requests maximum
- **Database**: 3 post meta updates on success

### Optimization

- **Parallel Tests**: Each test button operates independently
- **Cached Results**: Results stored in CPT for reference
- **No Polling**: One-time test on demand
- **Lazy Loading**: JavaScript only loaded on mesh settings page

## Integration with AI Peers

### Automatic Updates

When a test completes successfully:

1. **Health Status Updated**:
   - `_wp_mcp_ai_peer_health_status` → healthy/degraded/down

2. **Last Verified Timestamp**:
   - `_wp_mcp_ai_peer_last_verified` → current UTC time

3. **Test Results Stored**:
   - `_wp_mcp_ai_last_test_result` → JSON encoded results

### Viewing Results

**AI Peers List**:
- Health column shows color-coded status
- Last Check column shows time since test
- Type column shows MESH badge

**AI Peer Edit Page**:
- Connection Type shows MESH with description
- Health Status box shows current status
- Last verified timestamp displayed

## Future Enhancements

### Planned Features

1. **Scheduled Testing**: Automatic periodic connection tests
2. **Alert Notifications**: Email alerts when peers go down
3. **Test History**: Log of past test results
4. **Batch Testing**: Test all peers at once
5. **Performance Metrics**: Latency and response time tracking

### Pro Integration

1. **Mesh Connection Type**: Add to Remote Sites dropdown
2. **Unified Testing**: Test mesh peers from Remote Sites page
3. **Bidirectional Sync**: Sync between mesh_peer_sites and remote_sites
4. **Advanced Diagnostics**: Detailed connection analytics

## Related Documentation

- [Mesh Peer CPT Synchronization](mesh-peer-cpt-sync.md)
- [Mesh Compute Pooling](mesh-compute-pooling.md)
- [Federation Discovery](federation-discovery.md)
- [Federation Setup Guide](../../guides/admin/FEDERATION_SETUP_GUIDE.md)

## Support

For issues or questions about mesh peer connection testing:

1. Check [Troubleshooting](#troubleshooting) section above
2. Review error messages in browser console
3. Check WordPress debug.log for server errors
4. Test connection manually with curl
5. Report issues on GitHub with:
   - Test results
   - Browser console output
   - PHP error logs (if any)
