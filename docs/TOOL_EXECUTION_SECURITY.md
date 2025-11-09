# Tool Execution Security

This document describes the security validation implemented for direct tool execution via the `POST /tools` endpoint.

## Overview

The WP oOS plugin provides a powerful direct tool execution endpoint (`POST /mcp-ai/v1/tools`) that allows authenticated users and AI assistants to execute tools programmatically. To ensure this functionality is secure, a centralized security validation layer has been implemented.

## Security Architecture

### Layered Security Approach

The plugin implements defense-in-depth with multiple security layers:

1. **REST API Authentication** - WordPress nonce or bearer token required
2. **Assistant Scope Validation** - Tool must be enabled for the assistant
3. **Centralized Security Validator** - Pre-execution security checks
4. **Tool-Level Validation** - Individual tool capability and logic checks

### Centralized Security Validator

Located in `includes/class-wp-mcp-ai-tool-security-validator.php`, this class provides comprehensive pre-execution validation for all tools.

#### Validation Steps

1. **Authentication Validation**
   - Ensures user is authenticated via WordPress login or bearer token
   - Blocks all unauthenticated requests
   - Error code: `wp_mcp_ai_authentication_required`

2. **Capability Validation**
   - Enforces appropriate WordPress capabilities based on tool category
   - Different tools have different capability requirements
   - Error code: `wp_mcp_ai_insufficient_permissions`

3. **Input Sanitization**
   - Detects and blocks malicious payloads in tool arguments
   - Protects against SQL injection, path traversal, command injection
   - Error codes: `wp_mcp_ai_sql_injection_detected`, `wp_mcp_ai_path_traversal_detected`, `wp_mcp_ai_command_injection_detected`

4. **Document Access Validation**
   - Validates user permissions for referenced WordPress attachments
   - Ensures users can only access their own or public attachments
   - Error codes: `wp_mcp_ai_invalid_attachment`, `wp_mcp_ai_attachment_forbidden`

5. **Custom Validation Hook**
   - Allows third-party extensions to add additional validation
   - Filter: `wp_mcp_ai_validate_tool_execution`

## Tool Categories

### Public Tools

These tools are accessible to any authenticated user with the `read` capability (all registered users).

**Tools:**
- `count_tokens` - Token estimation
- `web_search` - Web search functionality
- `get_recent_posts` - Retrieve recent posts
- `search_content` - Search site content
- `get_open_meteo_forecast` - Weather forecasting
- `get_gdacs_events` - Disaster events
- `get_nhc_active_storms` - Hurricane tracking
- `reliefweb_reports` - Relief web reports
- `get_import_duty` - Import duty calculations

**Security:** Requires authentication only.

### Credential Generation Tools

These tools generate authentication credentials and require the `manage_options` capability (administrators only).

**Tools:**
- `generate_simple_jwt_token` - Generate JWT tokens
- `generate_auth0_token` - Generate Auth0 tokens

**Security:** Requires `manage_options` capability.

### Proxy Tools

These tools proxy requests to other systems and delegate security to those systems.

**Tools:**
- `invoke_jetengine_route` - JetEngine REST API proxy

**Security:** Validated by the target system (JetEngine).

### Document Tools

These tools access or manipulate user documents and require attachment access validation.

**Tools:**
- `submit_document_prompt` - Submit documents with prompts

**Security:** Validates attachment ownership or public access.

### All Other Tools

Tools not in the above categories must implement capability checks in their `execute()` method. The security validator ensures a minimum `read` capability as a safety net.

## Attack Prevention

### SQL Injection Prevention

The validator detects common SQL injection patterns:

```php
// Blocked patterns:
' OR '1'='1
'; DROP TABLE wp_posts; --
1' UNION SELECT NULL--
admin'--
```

**Detection Method:** Pattern matching against known SQL injection signatures.

**Error:** `wp_mcp_ai_sql_injection_detected`

### Path Traversal Prevention

The validator blocks directory traversal attempts:

```php
// Blocked patterns:
../../../etc/passwd
..\..\..\..\windows\system32
%2e%2e%2f%2e%2e%2fconfig
```

**Detection Method:** Pattern matching for `../`, `..\`, and URL-encoded variants.

**Error:** `wp_mcp_ai_path_traversal_detected`

### Command Injection Prevention

The validator blocks shell command injection attempts:

```php
// Blocked characters in command arguments:
; & | ` $ ( ) \n \r
```

**Detection Method:** Character class matching for shell metacharacters.

**Error:** `wp_mcp_ai_command_injection_detected`

## Attachment Access Control

For tools that access WordPress attachments (`submit_document_prompt`), the validator enforces access control:

### Access Rules

1. **Owner Access** - Users can access attachments they uploaded
2. **Public Access** - Users can access public attachments (publish/inherit status)
3. **Capability Access** - Users with `read_post` capability can access specific attachments
4. **Blocked** - All other access is denied

### Validation Points

- `attachment_id` parameter
- `attachment_ids` array parameter
- Structured `attachments` array (with `id` or `attachment_id` fields)

## Custom Validation

Third-party code can add additional validation using the filter hook:

```php
add_filter( 'wp_mcp_ai_validate_tool_execution', function( $result, $tool_slug, $arguments, $context, $tool ) {
    // Add custom validation logic
    if ( some_condition( $arguments ) ) {
        return new WP_Error(
            'custom_validation_failed',
            'Custom validation error message'
        );
    }
    
    return $result; // Pass through if validation succeeds
}, 10, 5 );
```

**Parameters:**
- `$result` - Current validation result (true or WP_Error)
- `$tool_slug` - Tool identifier
- `$arguments` - Tool arguments being validated
- `$context` - Execution context (user_id, assistant_id, etc.)
- `$tool` - Tool instance

## Security Logging

All security violations are logged using the plugin's logging system:

```php
WP_MCP_AI_Logger::log_security_event(
    'sql_injection_attempt',
    array(
        'tool'     => $tool_slug,
        'argument' => $key,
        'user_id'  => $user_id,
    )
);
```

**Event Types:**
- `sql_injection_attempt`
- `path_traversal_attempt`
- `command_injection_attempt`

## Integration Point

The security validator is called in `class-wp-mcp-ai-rest.php` before tool execution:

```php
// Perform centralized security validation before tool execution.
$security_check = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution( 
    $tool, 
    $prepared_arguments, 
    $context 
);

if ( is_wp_error( $security_check ) ) {
    WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $security_check, $context );
    return $security_check;
}
```

This ensures all tool executions pass through security validation before reaching the tool's `execute()` method.

## Testing

Comprehensive test coverage is provided in:

- `tests/security/test-tool-execution-security.php` - Core security validation tests
- `tests/security/test-tool-execution-complex.php` - Complex scenario and edge case tests

**Test Coverage:**
- Authentication requirements
- Capability enforcement
- SQL injection detection
- Path traversal prevention
- Command injection prevention
- Document access control
- Custom validation hooks
- Edge cases and complex scenarios

## Best Practices for Tool Developers

When creating new tools, follow these security guidelines:

### 1. Always Implement Capability Checks

```php
public function execute( array $arguments = array(), array $context = array() ) {
    $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
    
    if ( ! $user_id || ! user_can( $user_id, 'appropriate_capability' ) ) {
        return new WP_Error(
            'wp_mcp_ai_forbidden',
            __( 'You do not have permission to use this tool.', 'wp-mcp-ai' )
        );
    }
    
    // Tool logic...
}
```

### 2. Sanitize All Input

```php
$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
$title   = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
$content = isset( $arguments['content'] ) ? wp_kses_post( $arguments['content'] ) : '';
```

### 3. Validate Object Existence

```php
$post = get_post( $post_id );
if ( ! $post ) {
    return new WP_Error(
        'wp_mcp_ai_invalid_post',
        __( 'The specified post does not exist.', 'wp-mcp-ai' )
    );
}
```

### 4. Check Object-Specific Permissions

```php
if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
    return new WP_Error(
        'wp_mcp_ai_forbidden',
        __( 'You do not have permission to edit this post.', 'wp-mcp-ai' )
    );
}
```

### 5. Escape All Output

```php
return array(
    'title'   => esc_html( $post->post_title ),
    'content' => wp_kses_post( $post->post_content ),
    'url'     => esc_url( get_permalink( $post_id ) ),
);
```

## Audit Checklist

Use this checklist when auditing tools for security:

- [ ] Tool implements authentication check
- [ ] Tool implements appropriate capability check
- [ ] All input parameters are sanitized
- [ ] Object existence is validated before use
- [ ] Object-specific permissions are checked
- [ ] All output is properly escaped
- [ ] No direct SQL queries (use WordPress DB abstraction)
- [ ] No direct file system access outside allowed directories
- [ ] No shell command execution
- [ ] External API calls are validated and sanitized
- [ ] Multisite support is handled correctly

## Security Reporting

If you discover a security vulnerability in the tool execution system:

1. **Do not** open a public GitHub issue
2. Email security details to the maintainers (see SECURITY.md)
3. Include:
   - Tool name
   - Attack vector
   - Steps to reproduce
   - Impact assessment

## References

- [WordPress Coding Standards - Security](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/#security)
- [Data Validation - WordPress Plugin Handbook](https://developer.wordpress.org/plugins/security/data-validation/)
- [Securing Input - WordPress Plugin Handbook](https://developer.wordpress.org/plugins/security/securing-input/)
- [Securing Output - WordPress Plugin Handbook](https://developer.wordpress.org/plugins/security/securing-output/)
