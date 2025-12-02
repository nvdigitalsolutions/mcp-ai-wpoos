# Fix for 403 Forbidden Error on Tools Endpoint

## Issue Summary

When the chat-audio-service.js attempted to call the `/wp-json/mcp-ai/v1/tools` endpoint to generate speech audio using the `generate_openai_speech` tool, it resulted in a 403 Forbidden error:

```
POST https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/tools 403 (Forbidden)
```

## Root Cause

The `WP_MCP_AI_REST_Authenticator` class was missing an `authenticate()` method that is called by `WP_MCP_AI_REST_Controller_Base::permissions_check_authenticated()` at line 119:

```php
$auth_result = $this->authenticator->authenticate( $request );
```

This caused a fatal error when REST endpoints tried to authenticate requests, resulting in the 403 Forbidden response.

## Solution

### 1. Added `authenticate()` Method to WP_MCP_AI_REST_Authenticator

**File**: `includes/rest/class-wp-mcp-ai-rest-authenticator.php`

Added a comprehensive `authenticate()` method that handles multiple authentication methods in order of precedence:

1. **Mesh Network API Keys** (`X-WP-MCP-AI-Mesh-Key` header)
2. **Bearer Tokens** (`Authorization: Bearer` header)
   - Local assistant credentials (format: `cred_xxxxx.SECRET`)
   - Auth0 JWT tokens
3. **WordPress Nonces** (`X-WP-Nonce` header)
4. **Guest Tokens** (`X-WP-MCP-AI-Guest` header)

The method returns:
- **Success**: Authentication context array with `user_id`, `token_authenticated`, etc.
- **Failure**: `WP_Error` object with appropriate error code and message

### 2. Fixed Token Context Passing in WP_MCP_AI_REST_Tools_Controller

**File**: `includes/rest/class-wp-mcp-ai-rest-tools-controller.php`

The tools controller now:
1. Passes the `token_authenticated` flag from `$this->auth_context` to the tool execution context
2. Updates validation logic to allow token-authenticated requests without a `user_id`

**Before**:
```php
if ( empty( $user_id ) && ! $this->is_guest_request() ) {
    return $this->error(...);
}
```

**After**:
```php
if ( empty( $user_id ) && ! $this->is_guest_request() && empty( $context['token_authenticated'] ) ) {
    return $this->error(...);
}
```

This allows tools like `generate_openai_speech` that require authentication to work with bearer tokens.

## Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│ Client sends request to REST endpoint                        │
│ (e.g., POST /wp-json/mcp-ai/v1/tools)                       │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ REST Controller calls permissions_check_authenticated()      │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Base Controller calls $authenticator->authenticate($request) │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Authenticator checks headers in order:                       │
│ 1. X-WP-MCP-AI-Mesh-Key (mesh API key)                      │
│ 2. Authorization: Bearer (local token or Auth0)             │
│ 3. X-WP-Nonce (WordPress nonce)                             │
│ 4. X-WP-MCP-AI-Guest (guest token)                          │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Returns auth context or WP_Error                             │
│ {                                                            │
│   user_id: 123,                                              │
│   token_authenticated: true,                                 │
│   token_type: 'bearer',                                      │
│   ...                                                        │
│ }                                                            │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Base Controller stores context in $this->auth_context        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Tools Controller passes context to tool->execute()           │
│ Including token_authenticated flag                           │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ Tool validates authentication:                               │
│ - Checks user_id OR token_authenticated                      │
│ - Executes if authorized                                     │
└─────────────────────────────────────────────────────────────┘
```

## Files Changed

1. **includes/rest/class-wp-mcp-ai-rest-authenticator.php**
   - Added `authenticate()` method (lines 122-201)
   - Implements multi-method authentication
   - Returns auth context or WP_Error

2. **includes/rest/class-wp-mcp-ai-rest-tools-controller.php**
   - Added token_authenticated flag to execution context (lines 510-512)
   - Updated validation to allow token authentication (line 516)

3. **tests/test-rest-authenticator-method.php** (new)
   - Unit tests for authenticate() method
   - Tests multiple authentication scenarios

## Testing

### Manual Testing
1. Verify `authenticate()` method exists:
   ```bash
   grep -n "public function authenticate" includes/rest/class-wp-mcp-ai-rest-authenticator.php
   ```

2. Check PHP syntax:
   ```bash
   php -l includes/rest/class-wp-mcp-ai-rest-authenticator.php
   php -l includes/rest/class-wp-mcp-ai-rest-tools-controller.php
   ```

### Automated Testing
Run the test suite:
```bash
composer run test tests/test-rest-authenticator-method.php
```

## Security Considerations

- All authentication methods properly validate credentials before granting access
- Nonce verification uses WordPress core `wp_verify_nonce()`
- Bearer tokens are validated through existing secure validation methods
- Mesh keys use constant-time comparison (`hash_equals()`)
- Guest tokens are validated through the shortcode system

## Backward Compatibility

✅ This change is **fully backward compatible**:
- Existing authentication methods continue to work
- Main REST class already had similar logic (this just moves it to the authenticator)
- All REST controllers extending the base controller automatically benefit

## Related Issues

This fix resolves the speech button functionality in the chat client that was broken when trying to generate audio files via the tools endpoint.

## References

- `includes/class-wp-mcp-ai-rest.php` lines 1443-1590 - Original authentication logic in main REST class
- `includes/rest/class-wp-mcp-ai-rest-controller-base.php` line 119 - Where authenticate() is called
- `includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php` lines 146-165 - Tool authentication requirements
