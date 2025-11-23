# Performance Test Runner Widget Error Display Fix

## Issue
The Elementor "Performance Test Runner" widget was displaying "[object Object]" instead of actual error messages when tests failed (particularly security tests).

## Root Cause
When the AJAX endpoint `wp_mcp_ai_run_performance_test` returns an error via `wp_send_json_error()`, it sends an object with multiple properties:

```php
wp_send_json_error(
    array(
        'message'       => 'Performance tests require development dependencies.',
        'details'       => 'These tests are designed for local development environments...',
        'setup_command' => 'composer install',
        'cli_command'   => './bin/run-performance-tests.sh --suite=security',
    )
);
```

The original JavaScript code tried to concatenate this entire object as a string:
```javascript
statusDiv.html('<p class="wp-mcp-ai-error">Test failed: ' + response.data + '</p>');
```

This resulted in the string "[object Object]" being displayed instead of the actual error message.

## Solution
The fix now:

1. **Checks the type** of `response.data` before processing it
2. **Extracts the message** property when it's an object
3. **Displays additional helpful information** when available (details, CLI command, setup command)
4. **Maintains backward compatibility** with string error responses

### Before (Broken)
```javascript
statusDiv.html('<p class="wp-mcp-ai-error">Test failed: ' + (response.data || 'Unknown error') + '</p>');
```

Result: "Test failed: [object Object]"

### After (Fixed)
```javascript
// Handle both string and object error responses
var errorMessage = 'Unknown error';
if (response.data) {
    if (typeof response.data === 'object') {
        // Extract message from object
        errorMessage = response.data.message || errorMessage;
        
        // Build detailed error HTML with all available info
        var errorHtml = '<p class="wp-mcp-ai-error">Test failed: ' + errorMessage + '</p>';
        
        if (response.data.details) {
            errorHtml += '<p class="wp-mcp-ai-error-details">' + response.data.details + '</p>';
        }
        if (response.data.cli_command) {
            errorHtml += '<p class="wp-mcp-ai-cli-command"><strong>CLI Command:</strong> <code>' + response.data.cli_command + '</code></p>';
        }
        if (response.data.setup_command) {
            errorHtml += '<p class="wp-mcp-ai-setup-command"><strong>Setup Command:</strong> <code>' + response.data.setup_command + '</code></p>';
        }
        
        statusDiv.html(errorHtml);
    } else {
        // Handle string error
        statusDiv.html('<p class="wp-mcp-ai-error">Test failed: ' + response.data + '</p>');
    }
} else {
    statusDiv.html('<p class="wp-mcp-ai-error">Test failed: ' + errorMessage + '</p>');
}
```

Result: Properly formatted error with message, details, and actionable commands.

## Example Output

### Error Object Response
When a test fails with detailed information:

**Input (from PHP):**
```php
array(
    'message'       => 'Performance tests require development dependencies.',
    'details'       => 'These tests are designed for local development environments. On production or managed hosting (like Cloudways), performance monitoring is available through the dashboard metrics above.',
    'setup_command' => 'composer install',
    'cli_command'   => './bin/run-performance-tests.sh --suite=security',
)
```

**Output (displayed to user):**
```
Test failed: Performance tests require development dependencies.

These tests are designed for local development environments. On production or managed hosting (like Cloudways), 
performance monitoring is available through the dashboard metrics above.

Setup Command: composer install

CLI Command: ./bin/run-performance-tests.sh --suite=security
```

### String Response (Backward Compatibility)
When a simple string error is returned:

**Input:**
```php
wp_send_json_error( 'Invalid test type' );
```

**Output:**
```
Test failed: Invalid test type
```

## CSS Styling
The fix also adds appropriate CSS styling for the new error detail elements:

- `.wp-mcp-ai-error-details` - Secondary text styling for additional details
- `.wp-mcp-ai-cli-command` - Highlighted box for CLI commands with monospace code
- `.wp-mcp-ai-setup-command` - Highlighted box for setup commands with monospace code

## Testing
Created comprehensive test suite in `tests/test-elementor-performance-test-runner-error-handling.php` that validates:

- Widget contains proper error object handling
- Widget handles string errors (backward compatibility)
- Widget requires manage_options capability
- Widget renders all enabled test types correctly

## Files Changed
1. `includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php` - Main fix
2. `tests/test-elementor-performance-test-runner-error-handling.php` - Test suite
