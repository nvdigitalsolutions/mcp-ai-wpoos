# Fix: Speech Generation 403 Error - Cookie Check Failed

## Issue

Speech generation was failing with a 403 Forbidden error when calling the REST API tools endpoint:

```
chat-audio-service.js:483 [WP oOS] Speech generation failed: 
Object
  body: {code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: {…}}
  endpoint: "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools"
  message: "Cookie check failed"
  status: 403
```

This affected the text-to-speech functionality where users could click a button to have AI responses read aloud.

## Root Cause

WordPress's default REST API cookie authentication handler (in `wp-includes/rest-api.php`) includes a check that validates cookies are present when an `X-WP-Nonce` header is sent. This check runs via the `rest_authentication_errors` filter at priority 100.

The problem occurred because:

1. **Client-side**: The chat audio service uses `credentials: 'omit'` in fetch requests (as documented in `FIX_SPEECH_BUTTON_403_ERROR.md`)
2. **Client-side**: The request includes the `X-WP-Nonce` header for authentication
3. **Server-side**: WordPress's default cookie auth handler sees the nonce header and expects cookies to be present
4. **Server-side**: Since no cookies are sent (due to `credentials: 'omit'`), WordPress returns `rest_cookie_invalid_nonce` error

Even though the plugin has its own authentication handler (`WP_MCP_AI_REST_Authenticator::authenticate()`), WordPress's default cookie check runs first and rejects the request before our authentication can be evaluated.

## Solution

Added a `rest_authentication_errors` filter at priority 5 (before WordPress's default cookie check at priority 100) to bypass the cookie check for our plugin's endpoints.

### Implementation

**File**: `includes/class-wp-mcp-ai-rest.php`

#### 1. Added Filter Registration (line 193)

```php
add_filter( 'rest_authentication_errors', array( $this, 'bypass_cookie_check_for_plugin_endpoints' ), 5 );
```

#### 2. Added Method Implementation (lines 199-242)

```php
/**
 * Bypass WordPress's default cookie check for plugin endpoints.
 *
 * WordPress's REST API includes a default cookie authentication handler that
 * checks for cookies when an X-WP-Nonce header is present. This causes issues
 * when using `credentials: 'omit'` in fetch requests (no cookies sent) but
 * including the nonce header for authentication.
 *
 * Since we handle authentication ourselves via WP_MCP_AI_REST_Authenticator,
 * we need to bypass WordPress's default cookie check for our endpoints.
 *
 * This filter runs at priority 5, before WordPress's default cookie check
 * (priority 100), allowing us to indicate that authentication is already
 * handled for our endpoints.
 *
 * @since 1.0.0
 *
 * @param WP_Error|null|bool $result  WP_Error if authentication error, null if not checked yet, true if authenticated.
 * @return WP_Error|null|bool Modified result - true to bypass cookie check for our endpoints.
 */
public function bypass_cookie_check_for_plugin_endpoints( $result ) {
	// If authentication already failed with an error, don't interfere.
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Only process requests to our REST namespace.
	$rest_prefix = rest_get_url_prefix();
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	// Parse and validate URI safely.
	$parsed_uri = wp_parse_url( $request_uri, PHP_URL_PATH );
	if ( ! $parsed_uri || false === strpos( $parsed_uri, '/' . $rest_prefix . '/' . self::REST_NAMESPACE ) ) {
		// Not our endpoint, let WordPress handle it normally.
		return $result;
	}

	// This is our endpoint - return true to indicate authentication is handled.
	// This prevents WordPress's default cookie check from running and throwing
	// the "rest_cookie_invalid_nonce" error when cookies aren't present.
	// Our actual authentication happens in the permission_callback via
	// WP_MCP_AI_REST_Authenticator::authenticate().
	return true;
}
```

## How It Works

### Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│ Client sends request to REST endpoint                        │
│ Headers: X-WP-Nonce (no cookies due to credentials: 'omit') │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ rest_authentication_errors filter (priority 5)               │
│ → bypass_cookie_check_for_plugin_endpoints()                │
│   - Checks if request is to /mcp-ai/v1/* namespace          │
│   - Returns true (authentication handled)                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ WordPress's default cookie check (priority 100)              │
│ → SKIPPED (filter already returned true)                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ REST endpoint permission_callback                            │
│ → WP_MCP_AI_REST_Authenticator::authenticate()             │
│   - Validates X-WP-Nonce, bearer tokens, guest tokens, etc. │
│   - Returns auth context or WP_Error                        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Request processed if authenticated                           │
└─────────────────────────────────────────────────────────────┘
```

### Key Points

1. **Filter Priority**: Runs at priority 5, well before WordPress's default cookie check (priority 100)
2. **Scope**: Only affects our plugin's REST namespace (`mcp-ai/v1`)
3. **Preservation**: Preserves existing `WP_Error` objects if authentication already failed
4. **Compatibility**: Works with subdirectory WordPress installations via `rest_get_url_prefix()`
5. **Security**: Our own authentication still runs via `WP_MCP_AI_REST_Authenticator::authenticate()`

## Files Changed

- **includes/class-wp-mcp-ai-rest.php**
  - Line 193: Added filter registration
  - Lines 199-242: Added `bypass_cookie_check_for_plugin_endpoints()` method

- **tests/test-rest-bypass-cookie-check.php** (new)
  - Unit tests for the bypass method
  - Tests various endpoint scenarios
  - Tests error preservation
  - Tests subdirectory installations

## Testing

### Unit Tests

Run the test suite:
```bash
composer run test tests/test-rest-bypass-cookie-check.php
```

### Manual Testing

1. Open a page with the chat widget
2. Send a message to the assistant
3. Click the speech button (play icon) on an AI response
4. Verify the audio is generated and plays successfully

### Expected Behavior

- **Before Fix**: 403 error with "Cookie check failed" message
- **After Fix**: Audio generates successfully

## Security Considerations

✅ **No security impact:**

- The bypass only skips WordPress's default cookie check
- Our own authentication still runs via `WP_MCP_AI_REST_Authenticator::authenticate()`
- All authentication methods (nonce, bearer tokens, guest tokens) continue to work
- The bypass only affects our plugin's REST namespace
- Permission callbacks still validate all requests

## Backward Compatibility

✅ **Fully backward compatible:**

- Only changes server-side authentication flow
- No changes to client-side code
- No changes to REST API contract
- No changes to authentication methods
- Works with all existing authentication modes

## Related Issues

This fix resolves the speech generation functionality that was broken due to WordPress's cookie authentication interfering with our header-based authentication when using `credentials: 'omit'`.

This complements the previous fix in `FIX_SPEECH_BUTTON_403_ERROR.md` which changed the client-side to use `credentials: 'omit'` instead of `credentials: 'same-origin'`.

## References

- WordPress REST API: [rest_authentication_errors filter](https://developer.wordpress.org/reference/hooks/rest_authentication_errors/)
- WordPress REST API: [Cookie Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/#cookie-authentication)
- Related fix: `FIX_SPEECH_BUTTON_403_ERROR.md` - Client-side credentials mode change
- Related fix: `FIX_403_TOOLS_ENDPOINT.md` - Server-side authentication handler

## Date

December 3, 2024
