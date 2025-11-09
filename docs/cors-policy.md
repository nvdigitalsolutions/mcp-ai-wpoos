# CORS Policy Documentation

## Overview

This document provides comprehensive information about Cross-Origin Resource Sharing (CORS) configuration in the WP Open Operator System (WP oOS) plugin.

## What is CORS?

Cross-Origin Resource Sharing (CORS) is a security mechanism that allows or restricts web applications running at one origin (domain) to access resources from a different origin. CORS is enforced by web browsers to prevent malicious websites from making unauthorized requests to your API.

## CORS Implementation in WP oOS

The WP oOS plugin implements CORS headers for all REST API endpoints under the `/wp-json/mcp-ai/v1/` namespace to enable:

1. **OpenAI Agent Builder** - Allowing OpenAI's platform to communicate with your WordPress site
2. **Remote MCP Clients** - Enabling third-party applications to access your assistants
3. **Cross-domain Chat Widgets** - Embedding chat interfaces on different domains
4. **Federation** - Allowing peer-to-peer communication between WP oOS instances

## Default CORS Configuration

By default, WP oOS applies the following CORS headers to all MCP endpoints:

```http
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest
Access-Control-Max-Age: 3600
```

### Header Explanations

- **Access-Control-Allow-Origin**: Controls which domains can access your API
  - `*` = Allow all origins (default, most permissive)
  - Specific domain = Only allow requests from that domain
  
- **Access-Control-Allow-Methods**: HTTP methods allowed for cross-origin requests
  - `GET` - Reading data (list assistants, etc.)
  - `POST` - Sending data (chat messages, tool calls)
  - `OPTIONS` - Preflight requests (browser checks before actual request)

- **Access-Control-Allow-Headers**: Headers that can be sent in cross-origin requests
  - `Authorization` - Bearer tokens for authentication
  - `Content-Type` - Request content type (usually application/json)
  - `X-WP-Nonce` - WordPress nonce for same-origin authenticated requests
  - `X-WP-MCP-AI-Mesh-Key` - Federation mesh authentication
  - `X-WP-MCP-AI-Guest` - Guest token for public access

- **Access-Control-Max-Age**: How long (in seconds) the browser can cache preflight responses
  - `3600` = 1 hour cache

## Customizing CORS Policy

### Method 1: Using Filters (Recommended)

You can customize CORS headers using WordPress filters in your theme's `functions.php` or a custom plugin:

#### Restrict to Specific Domains

```php
/**
 * Restrict CORS to specific trusted domains.
 */
add_filter( 'wp_mcp_ai_cors_allow_origin', function( $allow_origin ) {
    // Allow only your domains
    $allowed_origins = array(
        'https://yourdomain.com',
        'https://app.yourdomain.com',
        'https://chat.yourdomain.com',
    );
    
    // Get the origin of the current request
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( $_SERVER['HTTP_ORIGIN'] ) : '';
    
    // Check if origin is in allowed list
    if ( in_array( $origin, $allowed_origins, true ) ) {
        return $origin;
    }
    
    // Default: deny (no CORS header = browser blocks)
    return null;
} );
```

#### Add Custom Headers

```php
/**
 * Add custom CORS headers.
 */
add_filter( 'wp_mcp_ai_cors_headers', function( $headers ) {
    // Add credentials support (for cookies)
    $headers['Access-Control-Allow-Credentials'] = 'true';
    
    // Add custom headers
    $headers['Access-Control-Expose-Headers'] = 'X-Correlation-ID, X-RateLimit-Remaining';
    
    return $headers;
} );
```

#### Restrict Allowed Methods

```php
/**
 * Restrict CORS to GET requests only.
 */
add_filter( 'wp_mcp_ai_cors_allow_methods', function( $methods ) {
    return 'GET, OPTIONS'; // Only allow reading, no POST
} );
```

### Method 2: WordPress Constants

Define constants in `wp-config.php` for environment-specific configuration:

```php
// Production: Restrict to your domain
define( 'WP_MCP_AI_CORS_ORIGIN', 'https://yourdomain.com' );

// Development: Allow all
define( 'WP_MCP_AI_CORS_ORIGIN', '*' );

// Disable CORS entirely
define( 'WP_MCP_AI_CORS_ENABLED', false );
```

### Method 3: Server Configuration

For advanced control, configure CORS at the web server level:

#### Apache (.htaccess)

```apache
<IfModule mod_headers.c>
    # Only for /wp-json/mcp-ai/ endpoints
    <FilesMatch "\.php$">
        SetEnvIf Request_URI "^/wp-json/mcp-ai/" cors_mcp=1
        Header set Access-Control-Allow-Origin "https://yourdomain.com" env=cors_mcp
        Header set Access-Control-Allow-Methods "GET, POST, OPTIONS" env=cors_mcp
        Header set Access-Control-Allow-Headers "Authorization, Content-Type" env=cors_mcp
    </FilesMatch>
</IfModule>
```

#### Nginx

```nginx
location /wp-json/mcp-ai/ {
    if ($request_method = 'OPTIONS') {
        add_header 'Access-Control-Allow-Origin' 'https://yourdomain.com';
        add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS';
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type';
        add_header 'Access-Control-Max-Age' 3600;
        add_header 'Content-Type' 'text/plain charset=UTF-8';
        add_header 'Content-Length' 0;
        return 204;
    }
    
    add_header 'Access-Control-Allow-Origin' 'https://yourdomain.com' always;
}
```

## Security Considerations

### Risk: Allowing All Origins (`*`)

**Impact**: Any website can make requests to your API

**Mitigation**:
- Use authentication (API keys, nonces, Auth0)
- Enable rate limiting
- Monitor usage logs
- Consider allowing only trusted domains for production

### Risk: Credentials with Wildcard Origin

**Problem**: You cannot use `Access-Control-Allow-Credentials: true` with `Access-Control-Allow-Origin: *`

**Solution**: Specify exact origins when using credentials
```php
add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
    return 'https://yourdomain.com'; // Specific domain
} );

add_filter( 'wp_mcp_ai_cors_headers', function( $headers ) {
    $headers['Access-Control-Allow-Credentials'] = 'true';
    return $headers;
} );
```

### Risk: Exposing Sensitive Headers

Only expose headers that are safe for cross-origin access:

```php
// SAFE: Expose non-sensitive headers
add_filter( 'wp_mcp_ai_cors_headers', function( $headers ) {
    $headers['Access-Control-Expose-Headers'] = 'X-Correlation-ID, Content-Length';
    return $headers;
} );

// UNSAFE: Don't expose sensitive headers
// ❌ X-WP-Nonce, Authorization (internal use only)
```

## Common CORS Issues and Solutions

### Issue 1: "No 'Access-Control-Allow-Origin' header is present"

**Cause**: CORS headers not being sent

**Solution**:
1. Verify the endpoint is under `/wp-json/mcp-ai/v1/`
2. Check that CORS is not disabled via constant
3. Ensure no conflicting server configuration

### Issue 2: "Origin not allowed by Access-Control-Allow-Origin"

**Cause**: Your domain is not in the allowed origins list

**Solution**:
```php
// Add your domain to allowed origins
add_filter( 'wp_mcp_ai_cors_allow_origin', function( $default ) {
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( $_SERVER['HTTP_ORIGIN'] ) : '';
    
    if ( 'https://yourdomain.com' === $origin ) {
        return $origin;
    }
    
    return $default;
} );
```

### Issue 3: "Request header field X-Custom-Header is not allowed"

**Cause**: Custom header not in `Access-Control-Allow-Headers`

**Solution**:
```php
add_filter( 'wp_mcp_ai_cors_allow_headers', function( $headers ) {
    return $headers . ', X-Custom-Header';
} );
```

### Issue 4: Preflight OPTIONS Request Fails

**Cause**: Server not handling OPTIONS requests

**Solution**: The plugin automatically handles OPTIONS requests. If issues persist:
1. Check server logs for errors
2. Verify no .htaccess rules blocking OPTIONS
3. Test with: `curl -X OPTIONS https://yoursite.com/wp-json/mcp-ai/v1/assistants`

## Testing CORS Configuration

### Browser DevTools

1. Open browser console on a different domain
2. Make a test request:
```javascript
fetch('https://yoursite.com/wp-json/mcp-ai/v1/assistants')
  .then(r => r.json())
  .then(data => console.log('Success:', data))
  .catch(err => console.error('CORS Error:', err));
```

### cURL

Test preflight request:
```bash
curl -i -X OPTIONS \
  -H "Origin: https://example.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Authorization, Content-Type" \
  https://yoursite.com/wp-json/mcp-ai/v1/chat
```

Expected response should include:
```http
HTTP/1.1 200 OK
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, ...
```

### Online Tools

- [test-cors.org](https://www.test-cors.org/)
- [CORS Tester Chrome Extension](https://chrome.google.com/webstore)

## Best Practices

### Development Environment
```php
// Allow all origins for testing
define( 'WP_MCP_AI_CORS_ORIGIN', '*' );
```

### Staging Environment
```php
// Allow staging domains only
add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
    $allowed = array(
        'https://staging.yourdomain.com',
        'https://dev.yourdomain.com',
    );
    
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( $_SERVER['HTTP_ORIGIN'] ) : '';
    return in_array( $origin, $allowed, true ) ? $origin : null;
} );
```

### Production Environment
```php
// Strict origin checking
add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( $_SERVER['HTTP_ORIGIN'] ) : '';
    
    // Only allow your production domain
    if ( 'https://yourdomain.com' === $origin || 'https://www.yourdomain.com' === $origin ) {
        return $origin;
    }
    
    // Log rejected origins for monitoring
    if ( $origin ) {
        error_log( 'WP oOS: Rejected CORS request from: ' . $origin );
    }
    
    return null;
} );

// Enable additional security
add_filter( 'wp_mcp_ai_cors_headers', function( $headers ) {
    // Require credentials for production
    $headers['Access-Control-Allow-Credentials'] = 'true';
    
    // Limit cached preflight time
    $headers['Access-Control-Max-Age'] = '600'; // 10 minutes
    
    return $headers;
} );
```

## Monitoring CORS Activity

Enable logging to track CORS requests:

```php
add_action( 'wp_mcp_ai_cors_request', function( $origin, $method, $endpoint ) {
    error_log( sprintf(
        'CORS Request: Origin=%s Method=%s Endpoint=%s',
        $origin,
        $method,
        $endpoint
    ) );
}, 10, 3 );
```

## Related Documentation

- [REST API Documentation](rest-api.md)
- [Authentication Guide](authentication.md)
- [Security Hardening](SECURITY_HARDENING.md)
- [Remote Client Setup](remote-client-setup.md)

## Additional Resources

- [MDN CORS Guide](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)
- [W3C CORS Specification](https://www.w3.org/TR/cors/)
- [OWASP CORS Security](https://owasp.org/www-community/attacks/CORS_OriginHeaderScrutiny)

## Support

For CORS-related issues:
1. Check the [Troubleshooting Guide](deployment-troubleshooting.md)
2. Review browser console for specific error messages
3. Test with the provided cURL commands
4. Open an issue on GitHub with full error details

---

**Last Updated**: November 2024
**Plugin Version**: 1.0.0+
