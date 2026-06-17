# Mesh Peer Authentication Error Message Fix

## Issue
When testing mesh peer connections, users see a generic "Authentication failed" message without any details about WHY authentication failed. This makes troubleshooting difficult.

## Root Cause Analysis
When the remote site's REST API returns an authentication error (401 or 403 status), the error response contains detailed information about the failure:
- `wp_mcp_ai_mesh_disabled` → "Mesh networking is not enabled on this site."
- `wp_mcp_ai_mesh_not_configured` → "Mesh networking inbound API key is not configured."
- `wp_mcp_ai_invalid_mesh_key` → "Invalid mesh API key."

However, the mesh peer tester was **ignoring this detailed error message** and only showing a generic message: "API key authentication failed. Please verify the API key is correct."

## Example Scenario

**Before Fix:**
```
Connection test successful! (Victory Group oOS)
• Site is reachable
• Federation enabled
• Authentication failed  ← Generic, unhelpful
```

User doesn't know if:
- Mesh is disabled on the remote site?
- The API key was never generated?
- The wrong API key is being used?

**After Fix:**
```
Connection test successful! (Victory Group oOS)
• Site is reachable
• Federation enabled
• Authentication failed: Mesh networking is not enabled on this site.
```

Now the user knows exactly what to fix!

## Solution
Modified `/includes/class-wp-mcp-ai-mesh-peer-tester.php` to parse the JSON response body and extract the detailed error message from the remote site:

```php
// Before (INCOMPLETE):
if ( 401 === $status_code || 403 === $status_code ) {
    return new WP_Error(
        'mcp_auth_invalid',
        __( 'API key authentication failed. Please verify the API key is correct.', 'mcp-ai-wpoos' )
    );
}

// After (IMPROVED):
if ( 401 === $status_code || 403 === $status_code ) {
    // Try to get more specific error details from the response body.
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    $error_message = __( 'API key authentication failed. Please verify the API key is correct.', 'mcp-ai-wpoos' );
    
    // If the response contains a more specific error message, use it.
    if ( is_array( $data ) && ! empty( $data['message'] ) ) {
        $error_message = $data['message'];
    }
    
    return new WP_Error(
        'mcp_auth_invalid',
        $error_message
    );
}
```

Also improved error messages for other status codes (500, etc.) to include details when available.

## Common Error Messages Users Will Now See

| Scenario | Error Message Displayed |
|----------|------------------------|
| Mesh disabled on remote site | "Mesh networking is not enabled on this site." |
| API key never generated | "Mesh networking inbound API key is not configured." |
| Wrong API key used | "Invalid mesh API key." |
| Missing API key | "Mesh API key is missing." |
| Generic auth failure | "API key authentication failed. Please verify the API key is correct." (fallback) |

## Impact
- ✅ Users can now diagnose authentication issues immediately
- ✅ Reduces support burden and troubleshooting time
- ✅ Provides actionable error messages
- ✅ Maintains backward compatibility (fallback to generic message if remote site doesn't provide details)

## Additional Fix: API Key Whitespace Trimming
While investigating, also fixed a secondary issue where `mesh_inbound_api_key` wasn't being trimmed during sanitization, which could cause authentication failures if whitespace was accidentally included when copying/pasting API keys.

## Testing
To test the various error messages:
1. **Test with mesh disabled**: Uncheck "Enable Mesh Computing" on remote site, test connection
2. **Test with no API key**: Delete `mesh_inbound_api_key` from remote site settings, test connection
3. **Test with wrong API key**: Use a different/random key, test connection  
4. **Test with correct key**: Use the actual `mesh_inbound_api_key` from remote site, test connection

## Related Files
- **Primary Fix:** `/includes/class-wp-mcp-ai-mesh-peer-tester.php` (lines 251-267, 276-293)
- **Secondary Fix:** `/includes/admin/class-wp-mcp-ai-admin-settings.php` (line 2570 - trim addition)
- **Validator:** `/includes/rest/class-wp-mcp-ai-rest-authenticator.php` (provides the detailed error messages)

## User Experience Improvement
Users testing mesh peer connections at:
- https://victory.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh
- https://victory.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-remote-sites

Will now see specific, actionable error messages instead of generic failures.

## Backward Compatibility
- ✅ No breaking changes
- ✅ Falls back to generic message if remote site doesn't provide details
- ✅ Works with older versions of the plugin on remote sites
- ✅ No migration required

