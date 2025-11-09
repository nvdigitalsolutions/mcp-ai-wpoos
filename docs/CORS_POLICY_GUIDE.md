# CORS Policy Configuration Guide

**Document Version:** 1.0  
**Last Updated:** November 9, 2025  
**Plugin:** WP Open Operator System (WP oOS)

## Overview

This document explains the Cross-Origin Resource Sharing (CORS) policy implementation in WP oOS and provides configuration guidance for secure cross-origin requests.

## Table of Contents

1. [CORS Implementation](#cors-implementation)
2. [Default Configuration](#default-configuration)
3. [Security Considerations](#security-considerations)
4. [Configuration Examples](#configuration-examples)
5. [Troubleshooting](#troubleshooting)
6. [Best Practices](#best-practices)

---

## CORS Implementation

### How CORS Works in WP oOS

WP oOS leverages WordPress's built-in REST API CORS support with additional security layers:

1. **WordPress Core CORS:**
   - WordPress REST API handles basic CORS by default
   - Sends appropriate `Access-Control-Allow-Origin` headers
   - Supports pre-flight OPTIONS requests

2. **WP oOS Enhancements:**
   - Additional origin validation for sensitive endpoints
   - Configurable allowed origins via filters
   - SSE-specific CORS handling
   - Request method restrictions

### CORS Flow

```
Client (Browser)                  Server (WP oOS)
     |                                   |
     |----  OPTIONS (Preflight) -------->|
     |                                   | Check allowed origin
     |                                   | Check allowed methods
     |                                   | Check allowed headers
     |<--- Access-Control-* Headers -----|
     |                                   |
     |----  POST /wp-json/mcp-ai/v1 ---->|
     |      Authorization: Bearer token  |
     |                                   | Validate auth
     |                                   | Process request
     |<--- Response + CORS Headers ------|
     |     X-Correlation-ID: uuid        |
```

---

## Default Configuration

### Default Behavior

By default, WP oOS follows WordPress REST API CORS policies:

- **Allowed Origins:** Any origin (controlled by WordPress)
- **Allowed Methods:** GET, POST, PUT, PATCH, DELETE, OPTIONS
- **Allowed Headers:** Authorization, Content-Type, X-WP-Nonce
- **Credentials:** Allowed (for cookie-based auth)
- **Max Age:** 600 seconds (WordPress default)

### Response Headers

Standard CORS headers sent by WP oOS:

```http
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Correlation-ID
Access-Control-Allow-Credentials: true
Access-Control-Max-Age: 600
X-Correlation-ID: <uuid>
```

---

## Security Considerations

### Origin Validation

**Default (Permissive):**
WordPress allows all origins by default for public REST endpoints.

**Recommended (Restrictive):**
Restrict origins for production deployments using the filter below.

### Authentication Requirements

CORS does NOT bypass authentication:

- All endpoints still require valid authentication
- CORS only controls browser access, not API access
- Authenticated endpoints protected regardless of origin

### SSE-Specific Security

Server-Sent Events endpoints have additional protections:

1. **No Credentials for SSE:**
   - SSE connections use token-based auth only
   - Cookies are not sent with SSE requests
   - Bearer tokens required in URL or headers

2. **Long-Lived Connections:**
   - Connection timeout: 5 minutes default
   - Auto-reconnect with session ID
   - Origin validation on each reconnect

---

## Configuration Examples

### 1. Restrict to Specific Origins

**Production deployment with known client domains:**

```php
// In wp-config.php or custom plugin

add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_restrict_cors_origins', 10, 4 );

function wp_mcp_ai_restrict_cors_origins( $served, $result, $request, $server ) {
    $allowed_origins = array(
        'https://app.example.com',
        'https://dashboard.example.com',
        'https://admin.example.com',
    );

    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

    if ( in_array( $origin, $allowed_origins, true ) ) {
        header( 'Access-Control-Allow-Origin: ' . $origin );
        header( 'Access-Control-Allow-Credentials: true' );
    } else {
        // Block cross-origin for unknown origins
        header( 'Access-Control-Allow-Origin: null' );
    }

    return $served;
}
```

### 2. Restrict to Single Origin

**For single-page applications:**

```php
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_single_origin_cors', 10, 4 );

function wp_mcp_ai_single_origin_cors( $served, $result, $request, $server ) {
    $allowed_origin = 'https://app.example.com';

    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

    if ( $allowed_origin === $origin ) {
        header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
        header( 'Access-Control-Allow-Credentials: true' );
    }

    return $served;
}
```

### 3. Dynamic Origin Validation

**Validate against database or environment:**

```php
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_dynamic_cors', 10, 4 );

function wp_mcp_ai_dynamic_cors( $served, $result, $request, $server ) {
    // Get allowed origins from settings
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    $allowed_origins = isset( $settings['cors_allowed_origins'] ) 
        ? $settings['cors_allowed_origins'] 
        : array();

    // Or from environment variable
    if ( defined( 'WP_MCP_AI_CORS_ORIGINS' ) ) {
        $env_origins = explode( ',', WP_MCP_AI_CORS_ORIGINS );
        $allowed_origins = array_merge( $allowed_origins, $env_origins );
    }

    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

    if ( in_array( $origin, $allowed_origins, true ) ) {
        header( 'Access-Control-Allow-Origin: ' . $origin );
        header( 'Access-Control-Allow-Credentials: true' );
    }

    return $served;
}
```

### 4. Restrict Specific Endpoints

**Tighten CORS for sensitive endpoints only:**

```php
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_endpoint_specific_cors', 10, 4 );

function wp_mcp_ai_endpoint_specific_cors( $served, $result, $request, $server ) {
    $route = $request->get_route();

    // Sensitive endpoints require strict CORS
    $sensitive_endpoints = array(
        '/mcp-ai/v1/chat',
        '/mcp-ai/v1/tools',
    );

    $is_sensitive = false;
    foreach ( $sensitive_endpoints as $endpoint ) {
        if ( strpos( $route, $endpoint ) === 0 ) {
            $is_sensitive = true;
            break;
        }
    }

    if ( $is_sensitive ) {
        $allowed_origin = 'https://secure-app.example.com';
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

        if ( $allowed_origin === $origin ) {
            header( 'Access-Control-Allow-Origin: ' . $allowed_origin );
        } else {
            header( 'Access-Control-Allow-Origin: null' );
        }
    }

    return $served;
}
```

### 5. Development vs Production

**Different CORS policies per environment:**

```php
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_environment_cors', 10, 4 );

function wp_mcp_ai_environment_cors( $served, $result, $request, $server ) {
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'production' === WP_ENVIRONMENT_TYPE ) {
        // Production: Strict
        $allowed_origins = array(
            'https://app.example.com',
        );
    } else {
        // Development/Staging: Permissive
        $allowed_origins = array(
            'http://localhost:3000',
            'http://localhost:8000',
            'https://staging.example.com',
        );
    }

    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

    if ( in_array( $origin, $allowed_origins, true ) ) {
        header( 'Access-Control-Allow-Origin: ' . $origin );
        header( 'Access-Control-Allow-Credentials: true' );
    }

    return $served;
}
```

---

## Troubleshooting

### Common Issues

#### 1. CORS Errors in Browser Console

**Error:**
```
Access to fetch at 'https://example.com/wp-json/mcp-ai/v1/chat' from origin 
'https://app.example.com' has been blocked by CORS policy
```

**Solutions:**
- Add your origin to allowed origins list
- Check that CORS headers are being sent (use browser DevTools Network tab)
- Verify pre-flight OPTIONS request succeeds

#### 2. Credentials Not Sent

**Error:**
```
The value of the 'Access-Control-Allow-Credentials' header in the response is 
'' which must be 'true' when the request's credentials mode is 'include'.
```

**Solutions:**
- Ensure `Access-Control-Allow-Credentials: true` is sent
- When using credentials, cannot use `Access-Control-Allow-Origin: *`
- Must specify exact origin when credentials are required

#### 3. Pre-Flight Request Fails

**Error:**
```
Response to preflight request doesn't pass access control check
```

**Solutions:**
- Ensure OPTIONS method is allowed
- Check that required headers are in `Access-Control-Allow-Headers`
- Verify WordPress is handling OPTIONS requests properly

#### 4. SSE Connection Issues

**Error:**
```
EventSource's response has a MIME type that is not text/event-stream
```

**Solutions:**
- Use dedicated `/sse` endpoint for Server-Sent Events
- Don't use SSE on JSON-RPC endpoints
- Check Content-Type header is `text/event-stream`

### Debugging CORS

**Enable Debug Mode:**

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Log CORS requests
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_debug_cors', 10, 4 );

function wp_mcp_ai_debug_cors( $served, $result, $request, $server ) {
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : 'none';
    $method = $_SERVER['REQUEST_METHOD'];
    $route = $request->get_route();

    error_log( sprintf(
        'CORS Request: Origin=%s, Method=%s, Route=%s',
        $origin,
        $method,
        $route
    ) );

    return $served;
}
```

**Check Headers:**

```bash
# Test CORS with curl
curl -I -X OPTIONS \
  -H "Origin: https://app.example.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Authorization, Content-Type" \
  https://example.com/wp-json/mcp-ai/v1/chat

# Look for Access-Control-* headers in response
```

---

## Best Practices

### 1. Use Allowlist, Not Denylist

✅ **Good:**
```php
$allowed_origins = array( 'https://app.example.com' );
if ( in_array( $origin, $allowed_origins, true ) ) {
    // Allow
}
```

❌ **Bad:**
```php
$blocked_origins = array( 'https://evil.com' );
if ( ! in_array( $origin, $blocked_origins, true ) ) {
    // Allow (blocks only specific origins, allows all others)
}
```

### 2. Never Use Wildcard with Credentials

❌ **Bad:**
```php
header( 'Access-Control-Allow-Origin: *' );
header( 'Access-Control-Allow-Credentials: true' );
// This will not work!
```

✅ **Good:**
```php
header( 'Access-Control-Allow-Origin: https://app.example.com' );
header( 'Access-Control-Allow-Credentials: true' );
```

### 3. Validate Origin Format

✅ **Good:**
```php
$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
$origin = esc_url_raw( $origin );

// Validate it's a proper URL
if ( filter_var( $origin, FILTER_VALIDATE_URL ) ) {
    // Use origin
}
```

### 4. Use Environment Variables

Store allowed origins in environment variables, not code:

```php
// In wp-config.php
define( 'WP_MCP_AI_CORS_ORIGINS', 'https://app.example.com,https://dashboard.example.com' );
```

### 5. Log Rejected Origins

Track rejected CORS attempts for security monitoring:

```php
add_filter( 'rest_pre_serve_request', 'wp_mcp_ai_log_cors_rejections', 10, 4 );

function wp_mcp_ai_log_cors_rejections( $served, $result, $request, $server ) {
    $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed_origins = array( 'https://app.example.com' );

    if ( $origin && ! in_array( $origin, $allowed_origins, true ) ) {
        WP_MCP_AI_Logger::log_event(
            'cors_rejected',
            'CORS request from unauthorized origin',
            array(
                'origin' => $origin,
                'route'  => $request->get_route(),
                'method' => $_SERVER['REQUEST_METHOD'],
            ),
            'warning'
        );
    }

    return $served;
}
```

### 6. Different Policies for Different Routes

Separate public endpoints from authenticated endpoints:

- **Public Endpoints:** Relaxed CORS (assistants list)
- **Authenticated Endpoints:** Strict CORS (chat, tools)
- **SSE Endpoints:** Token-only authentication

### 7. Test in All Browsers

CORS behavior can vary:
- Chrome/Edge (Chromium-based)
- Firefox
- Safari
- Mobile browsers

### 8. Document Your Policy

Always document your CORS configuration:
- Which origins are allowed
- Why they are allowed
- Who approved them
- When to review

---

## Security Checklist

Before deploying to production:

- [ ] Remove wildcard (`*`) origins
- [ ] Specify exact allowed origins
- [ ] Enable credentials only when needed
- [ ] Validate origin format
- [ ] Log rejected CORS attempts
- [ ] Test pre-flight requests
- [ ] Document CORS policy
- [ ] Set appropriate max-age
- [ ] Review allowed headers
- [ ] Test SSE connections
- [ ] Monitor CORS errors in production

---

## Additional Resources

### WordPress Documentation
- [REST API Handbook - CORS](https://developer.wordpress.org/rest-api/using-the-rest-api/frequently-asked-questions/#is-the-rest-api-subject-to-the-same-security-policies-as-the-rest-of-wordpress)

### W3C Specifications
- [CORS Specification](https://www.w3.org/TR/cors/)
- [Fetch Standard](https://fetch.spec.whatwg.org/#http-cors-protocol)

### MDN Documentation
- [CORS - MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS)

### Security Resources
- [OWASP - CORS Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html#cross-origin-resource-sharing)

---

## Support

For CORS-related issues:
- Check browser console for specific error messages
- Review server error logs
- Test with curl to isolate browser-specific issues
- Enable WordPress debug logging
- Review this documentation

For security concerns:
- See `SECURITY.md` for responsible disclosure
- Contact security team before relaxing CORS policies

---

**Document End**

*Last Updated: November 9, 2025*  
*Version: 1.0*  
*Plugin: WP Open Operator System (WP oOS)*
