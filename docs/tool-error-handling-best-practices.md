# Tool Development Best Practices: Error Handling and Dependency Management

This document provides best practices for developing robust tools in the WP oOS plugin, with emphasis on error handling, dependency management, and creating AI-friendly error messages.

## Table of Contents

1. [Error Handling Principles](#error-handling-principles)
2. [Dependency Management](#dependency-management)
3. [Error Message Guidelines](#error-message-guidelines)
4. [Tool Implementation Checklist](#tool-implementation-checklist)
5. [Testing Your Tool](#testing-your-tool)

## Error Handling Principles

### Always Return WP_Error for Failures

Tools should **never** throw uncaught exceptions. Instead, return `WP_Error` instances:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    if ( ! $some_required_condition ) {
        return new WP_Error(
            'wp_mcp_ai_condition_not_met',
            __( 'Clear, actionable error message', 'wp-mcp-ai' ),
            array( 'status' => 400 )
        );
    }
    
    // ... tool logic
}
```

### Include HTTP Status Codes

Error data should include an appropriate HTTP status code:

- `400` - Bad request (invalid arguments)
- `403` - Forbidden (permission denied)
- `404` - Not found (resource doesn't exist)
- `500` - Server error (unexpected failure)

```php
return new WP_Error(
    'wp_mcp_ai_forbidden',
    __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
    array( 'status' => 403 )
);
```

### Provide Actionable Error Data

When possible, include actionable information in error data:

```php
return new WP_Error(
    'wp_mcp_ai_missing_credentials',
    __( 'API credentials have not been configured.', 'wp-mcp-ai' ),
    array(
        'status'  => 400,
        'actions' => array(
            'configure_credentials' => __(
                'Add API credentials in WP oOS settings.',
                'wp-mcp-ai'
            ),
        ),
    )
);
```

## Dependency Management

### Check Plugin Dependencies

If your tool requires a third-party plugin, implement the `is_available()` and `get_unavailable_reason()` static methods:

```php
class WP_MCP_AI_Tool_Example implements WP_MCP_AI_Tool_Interface {
    
    /**
     * Check if the required plugin is available.
     *
     * @return bool
     */
    public static function is_available() {
        return class_exists( 'Required_Plugin' ) && function_exists( 'required_plugin_function' );
    }
    
    /**
     * Provide reason why the tool is unavailable.
     *
     * @return string
     */
    public static function get_unavailable_reason() {
        return __( 'The Example tool requires the Required Plugin to be installed and active.', 'wp-mcp-ai' );
    }
    
    public function execute( array $arguments = array(), array $context = array() ) {
        // Always check again at execution time.
        if ( ! self::is_available() ) {
            return new WP_Error(
                'wp_mcp_ai_plugin_missing',
                __( 'Required Plugin is not active on this site.', 'wp-mcp-ai' )
            );
        }
        
        // ... tool logic
    }
}
```

### Check API Credentials

For tools that require external API credentials:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    $settings = WP_MCP_AI_Admin_Settings::get_settings();
    
    $api_key = isset( $settings['example_api_key'] ) ? trim( $settings['example_api_key'] ) : '';
    
    if ( '' === $api_key ) {
        return new WP_Error(
            'wp_mcp_ai_missing_api_key',
            __( 'Example API key has not been configured.', 'wp-mcp-ai' ),
            array(
                'status'  => 400,
                'actions' => array(
                    'configure_api_key' => __(
                        'Add an Example API key in the WP oOS settings.',
                        'wp-mcp-ai'
                    ),
                ),
            )
        );
    }
    
    // ... tool logic
}
```

### Verify User Permissions

Always check user capabilities before performing privileged operations:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
    
    if ( ! $user_id ) {
        return new WP_Error(
            'wp_mcp_ai_forbidden',
            __( 'You must be logged in to use this tool.', 'wp-mcp-ai' )
        );
    }
    
    if ( ! user_can( $user_id, 'required_capability' ) ) {
        return new WP_Error(
            'wp_mcp_ai_forbidden',
            __( 'You do not have permission to use this tool.', 'wp-mcp-ai' ),
            array( 'status' => 403 )
        );
    }
    
    // ... tool logic
}
```

### Handle Missing Resources

When accessing WordPress resources (posts, users, etc.), verify they exist:

```php
public function execute( array $arguments = array(), array $context = array() ) {
    $post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
    
    if ( ! $post_id ) {
        return new WP_Error(
            'wp_mcp_ai_missing_parameter',
            __( 'A post ID is required.', 'wp-mcp-ai' ),
            array( 'status' => 400 )
        );
    }
    
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        return new WP_Error(
            'wp_mcp_ai_post_not_found',
            sprintf(
                /* translators: %d: post ID */
                __( 'Post with ID %d does not exist.', 'wp-mcp-ai' ),
                $post_id
            ),
            array( 'status' => 404 )
        );
    }
    
    // ... tool logic
}
```

## Error Message Guidelines

### Write for AI Consumption

Error messages should be clear and structured so AI assistants can understand and communicate them to users:

**Good:**
```php
__( 'WooCommerce is not active on this site. Install and activate WooCommerce to use this tool.', 'wp-mcp-ai' )
```

**Bad:**
```php
__( 'Plugin required!', 'wp-mcp-ai' )  // Too vague
```

### Be Specific

Include specific details about what went wrong:

**Good:**
```php
sprintf(
    /* translators: %s: parameter name */
    __( 'Missing required parameter "%s".', 'wp-mcp-ai' ),
    'email_address'
)
```

**Bad:**
```php
__( 'Invalid parameters.', 'wp-mcp-ai' )  // Not specific enough
```

### Suggest Solutions

When possible, tell the user what they need to do:

**Good:**
```php
__( 'Mailjet API credentials have not been configured. Add API credentials in WP oOS settings to send emails.', 'wp-mcp-ai' )
```

**Bad:**
```php
__( 'Configuration error.', 'wp-mcp-ai' )  // No solution provided
```

### Avoid Technical Jargon

Write messages that non-technical users can understand:

**Good:**
```php
__( 'The email could not be sent because the API key is invalid.', 'wp-mcp-ai' )
```

**Bad:**
```php
__( 'HTTP 401: Unauthorized API authentication token.', 'wp-mcp-ai' )
```

## Tool Implementation Checklist

When implementing a new tool, ensure:

- [ ] Tool implements `WP_MCP_AI_Tool_Interface`
- [ ] All methods return values or `WP_Error` (never throw exceptions)
- [ ] If tool depends on plugins: `is_available()` and `get_unavailable_reason()` are implemented
- [ ] User capabilities are checked in `execute()`
- [ ] Multisite compatibility is handled if relevant
- [ ] Required arguments are validated
- [ ] Resources (posts, users, etc.) are verified to exist before use
- [ ] API credentials are validated before external API calls
- [ ] Error messages are clear, specific, and actionable
- [ ] Error data includes HTTP status codes
- [ ] All strings are internationalized with `__()`
- [ ] Tests are written for:
  - [ ] Successful execution
  - [ ] Missing dependencies
  - [ ] Invalid arguments
  - [ ] Missing resources
  - [ ] Permission failures

## Testing Your Tool

### Unit Tests

Create a test file `tests/test-your-tool-name.php`:

```php
<?php
/**
 * Tests for Your Tool Name
 */
class WP_MCP_AI_Your_Tool_Test extends WP_UnitTestCase {
    
    public function test_tool_executes_successfully() {
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        
        $result = $tool->execute(
            array( 'param' => 'value' ),
            array( 'user_id' => 1 )
        );
        
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'expected_key', $result );
    }
    
    public function test_tool_handles_missing_dependency() {
        if ( class_exists( 'Required_Plugin' ) ) {
            $this->markTestSkipped( 'Required plugin is active' );
        }
        
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        $result = $tool->execute( array(), array( 'user_id' => 1 ) );
        
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( 'Required Plugin', $result->get_error_message() );
    }
    
    public function test_tool_validates_arguments() {
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        
        $result = $tool->execute( array(), array( 'user_id' => 1 ) );
        
        $this->assertInstanceOf( WP_Error::class, $result );
    }
    
    public function test_tool_checks_permissions() {
        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );
        
        $tool = new WP_MCP_AI_Tool_Your_Tool();
        $result = $tool->execute(
            array( 'param' => 'value' ),
            array( 'user_id' => $user_id )
        );
        
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
    }
}
```

### Integration Tests

Test how your tool behaves in the full system:

```php
public function test_tool_error_is_formatted_for_ai() {
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    
    $result = $registry->execute_tool( 'your_tool', array() );
    
    // Verify error can be JSON encoded for AI.
    if ( is_wp_error( $result ) ) {
        $json = wp_json_encode( array( 'error' => $result->get_error_message() ) );
        $this->assertNotFalse( $json );
    }
}
```

## Registry Error Handling

The tool registry provides automatic error handling:

- **Exception Catching**: Any exception thrown by a tool is caught and converted to `WP_Error`
- **Tool Not Found**: Attempting to execute a non-existent tool returns `WP_Error` with status 404
- **Structured Errors**: All errors include error codes, messages, and optional data

Tools can rely on this safety net, but should still avoid throwing exceptions when possible.

## Summary

By following these best practices, your tools will:

1. Provide clear, actionable feedback to AI assistants and users
2. Gracefully handle missing dependencies and configurations
3. Fail safely without breaking chat sessions
4. Be testable and maintainable
5. Integrate seamlessly with the WP oOS plugin architecture
