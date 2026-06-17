# Fix: Embedded Provider Breaking Multiple Chat Widgets

## Problem Statement

When one chat widget uses the embedded provider, it breaks ALL other chat widgets on the same page with various errors:

1. **"Assistant configuration was not found"** - Other widgets lose their configuration
2. **"Uncaught SyntaxError: Unexpected token 'export' web-llm:13"** - ES module loading error
3. **"Uncaught (in promise) Error: Embedded LLM client not available"** - Client initialization failure
4. **SSE cron status connection established** - Only one widget connects properly

## Root Causes

### 1. Script Re-Registration Affects All Widgets
```php
// PROBLEM: This affects ALL widgets on the page
wp_deregister_script( self::SCRIPT_HANDLE );
wp_register_script( self::SCRIPT_HANDLE, ... );
```
When the script is deregistered, previously attached inline scripts (configurations) are lost.

### 2. Inline Configurations Lost
Each widget has its own inline configuration:
```javascript
window.wpMcpAiChatInstances['wp-mcp-ai-chat-1'] = {...};
window.wpMcpAiChatInstances['wp-mcp-ai-chat-2'] = {...};
```
When script is re-registered, these inline scripts get discarded.

### 3. ES Module Loading Error
WebLLM is an ES module from CDN that can't be loaded with standard `wp_register_script()`:
```php
// WRONG: Causes SyntaxError
wp_register_script('webllm', 'https://esm.run/@mlc-ai/web-llm', ...);
```

### 4. Timing Issues
The embedded client tries to use WebLLM before it's fully loaded.

## Solution

### 1. Prevent Unnecessary Re-Registration
```php
// Only re-register if dependencies actually need to change
$should_register_with_embedded = $needs_embedded_provider;
$already_registered            = wp_script_is( self::SCRIPT_HANDLE, 'registered' );

if ( ! $already_registered || ( $should_register_with_embedded && $already_registered ) ) {
    // Check if script has the embedded provider dependency
    if ( $already_registered ) {
        global $wp_scripts;
        $has_embedded_dep = isset( $wp_scripts->registered[ self::SCRIPT_HANDLE ] )
            && isset( $wp_scripts->registered[ self::SCRIPT_HANDLE ]->deps )
            && in_array( 'wp-mcp-ai-embedded-llm-client', $wp_scripts->registered[ self::SCRIPT_HANDLE ]->deps, true );
        
        // Only re-register if we need embedded dep but don't have it
        if ( $should_register_with_embedded && ! $has_embedded_dep ) {
            wp_deregister_script( self::SCRIPT_HANDLE );
            $already_registered = false;
        }
    }
    
    // Register with appropriate dependencies
    if ( ! $already_registered ) {
        $script_deps = $should_register_with_embedded ? array( 'wp-mcp-ai-embedded-llm-client' ) : array();
        wp_register_script( self::SCRIPT_HANDLE, $script_path, $script_deps, $script_version, true );
        
        // Apply localization after registration (only once per page load)
        if ( ! $is_elementor_editor && ! wp_scripts()->get_data( self::SCRIPT_HANDLE, 'data' ) ) {
            $this->apply_script_localization( $settings );
        }
    }
}
```

### 2. Use Dynamic Import() for ES Modules
Created dedicated `webllm-loader.js`:
```javascript
// Best Practice: Use dynamic import() for ES modules
import('https://esm.run/@mlc-ai/web-llm')
    .then(function(webLLM) {
        window.webLLM = webLLM;
        window.wpMcpAiWebLLMLoaded = true;
        window.dispatchEvent(new Event('webllm-ready'));
    })
    .catch(function(error) {
        console.error('[NV oOS] Failed to load WebLLM:', error);
        window.wpMcpAiWebLLMError = error;
        window.dispatchEvent(new CustomEvent('webllm-error', { detail: error }));
    });
```

### 3. Event-Based Synchronization
Updated `embedded-llm-client.js`:
```javascript
function waitForWebLLM() {
    return new Promise(function(resolve, reject) {
        // Check if already loaded
        if (window.webLLM) {
            webLLM = window.webLLM;
            webLLMReady = true;
            resolve(webLLM);
            return;
        }

        // Wait for webllm-ready event
        var timeoutId = setTimeout(function() {
            reject(new Error('Timeout waiting for WebLLM to load'));
        }, 30000);

        function onReady() {
            clearTimeout(timeoutId);
            webLLM = window.webLLM;
            webLLMReady = true;
            window.removeEventListener('webllm-ready', onReady);
            window.removeEventListener('webllm-error', onError);
            resolve(webLLM);
        }

        function onError(event) {
            clearTimeout(timeoutId);
            window.removeEventListener('webllm-ready', onReady);
            window.removeEventListener('webllm-error', onError);
            reject(event.detail || new Error('Unknown error loading WebLLM'));
        }

        window.addEventListener('webllm-ready', onReady);
        window.addEventListener('webllm-error', onError);
    });
}
```

### 4. Proper Script Registration
```php
// Register loader as separate file (best practice)
wp_register_script(
    'webllm-loader',
    WP_MCP_AI_URL . 'assets/js/webllm-loader.js',
    array(),
    $webllm_loader_version,
    true
);

// Register embedded client with loader as dependency
wp_register_script(
    'wp-mcp-ai-embedded-llm-client',
    $embedded_script_path,
    array( 'webllm-loader' ),
    $embedded_script_version,
    true
);

// Enqueue (WordPress handles deduplication)
wp_enqueue_script( 'webllm-loader' );
wp_enqueue_script( 'wp-mcp-ai-embedded-llm-client' );
```

## Best Practices Implemented

### WordPress Script Enqueuing
- **Always use `wp_enqueue_script()`** - Never hardcode scripts
- **Register once, enqueue multiple times** - WordPress deduplicates automatically
- **Use dependencies properly** - Ensures correct loading order
- **Separate JS files** - No inline scripts for complex logic
- **Version assets** - Use `filemtime()` for cache busting

References:
- https://wpwinners.com/guides/wp-enqueue-scripts-best-practices-for-developers/
- https://yourwpweb.com/2025/09/26/how-to-enqueue-scripts-and-styles-correctly-with-wp_enqueue_-in-wordpress/

### ES Module Loading in WordPress
- **Use dynamic import()** - Proper way to load ES modules
- **Event-based waiting** - Handle async module loading
- **Error handling** - Provide user-friendly error messages
- **Timeout protection** - Don't wait forever

References:
- https://yourwpweb.com/2025/09/26/how-to-enqueue-es-module-scripts-and-use-dynamic-import-in-wp-in-wordpress/
- https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/import
- https://make.wordpress.org/core/2023/11/21/exploration-to-support-modules-and-import-maps/

### WebLLM Integration
- **Load from CDN** - Ensures latest version with bug fixes
- **Check WebGPU support** - Graceful degradation
- **Progress callbacks** - User feedback during model loading
- **Error categorization** - Helpful troubleshooting messages

References:
- https://webllm.mlc.ai/
- https://deepwiki.com/mlc-ai/web-llm/6.2-integration-examples
- https://blog.mozilla.ai/3w-for-in-browser-ai-webllm-wasm-webworkers/

## Testing

Created comprehensive test suite in `tests/test-multiple-widgets-embedded-provider.php`:

1. **Multiple widgets render correctly** - Unique IDs for each widget
2. **Script registration with dependencies** - Embedded client depends on loader
3. **Configurations stored correctly** - All widgets have their configs
4. **Inline scripts added** - Each instance ID in inline scripts

## Files Changed

1. **includes/class-wp-mcp-ai-shortcode.php**
   - Fixed script re-registration logic
   - Improved dependency management
   - Proper WebLLM loader registration

2. **assets/js/webllm-loader.js** (NEW)
   - Dedicated ES module loader
   - Event-based notification system
   - Comprehensive error handling

3. **assets/js/embedded-llm-client.js**
   - Event-based waiting for WebLLM
   - Improved error handling
   - Better timeout messages

4. **tests/test-multiple-widgets-embedded-provider.php** (NEW)
   - Multiple widget scenarios
   - Mixed provider types
   - Configuration validation

## Security Summary

No security vulnerabilities introduced:
- Dynamic import() is safe for loading external ES modules
- Event-based communication uses standard browser APIs
- No user input in dynamic imports (CDN URL is hardcoded)
- Proper WordPress nonces and capability checks remain in place
- Code review passed with no issues
- PHPCS linting passed (only pre-existing warnings)

## Recommendations

1. **Monitor WebLLM loading** - Add analytics for load success/failure rates
2. **Consider fallback** - Server-side processing if WebLLM fails to load
3. **Document requirements** - WebGPU browser support needed for embedded provider
4. **User guidance** - Better onboarding for embedded provider configuration

## Migration Notes

No breaking changes. This is a bug fix that:
- Preserves existing functionality
- Improves multi-widget scenarios
- Maintains backward compatibility
- Requires no configuration changes

## References

### WordPress Best Practices
- [wp_enqueue_scripts Best Practices](https://wpwinners.com/guides/wp-enqueue-scripts-best-practices-for-developers/)
- [Enqueue Scripts and Styles Correctly](https://yourwpweb.com/2025/09/26/how-to-enqueue-scripts-and-styles-correctly-with-wp_enqueue_-in-wordpress/)
- [WordPress ES Module Exploration](https://make.wordpress.org/core/2023/11/21/exploration-to-support-modules-and-import-maps/)

### ES Modules & Dynamic Import
- [MDN: Dynamic import()](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/import)
- [ES Module Scripts in WordPress](https://yourwpweb.com/2025/09/26/how-to-enqueue-es-module-scripts-and-use-dynamic-import-in-wp-in-wordpress/)
- [Dynamic Import Examples](https://www.slingacademy.com/article/dynamic-import-in-javascript-tutorial-examples/)

### WebLLM Integration
- [WebLLM Official Docs](https://webllm.mlc.ai/)
- [WebLLM Integration Examples](https://deepwiki.com/mlc-ai/web-llm/6.2-integration-examples)
- [Mozilla AI: WebLLM + WASM + WebWorkers](https://blog.mozilla.ai/3w-for-in-browser-ai-webllm-wasm-webworkers/)
- [Solving WebLLM Bundler Issues](https://labs.thinktecture.com/solving-bundler-issues-when-adding-webllm-to-your-app/)
