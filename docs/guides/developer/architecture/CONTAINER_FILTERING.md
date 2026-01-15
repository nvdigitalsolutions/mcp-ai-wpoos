# Container Instance Filtering Guide

## Overview

The NV oOS plugin provides a powerful filtering system for modifying service instances from the dependency injection container. This allows developers to extend, enhance, or monitor services without modifying core code.

## The Filter Hook

### Hook Name Format

```php
apply_filters( "wp_mcp_ai_container_get_{$service_id}", $instance, $service_id, $container );
```

### Parameters

- **`$instance`** (mixed) - The service instance created by the container
- **`$service_id`** (string) - The service identifier (e.g., 'section.tools', 'client.openai')
- **`$container`** (WP_MCP_AI_Container) - The container instance for accessing other services

### Available Service IDs

Common service IDs you can filter:

#### Settings Sections
- `section.overview` - Overview dashboard section
- `section.general` - General settings section
- `section.providers` - AI providers configuration
- `section.tools` - Tools & features configuration
- `section.orchestration` - Orchestration settings
- `section.security` - Security settings
- `section.advanced` - Advanced settings

#### AI Clients
- `client.openai` - OpenAI API client
- `client.gemini` - Google Gemini client
- `client.ollama` - Ollama local client
- `client.lm_studio` - LM Studio client

#### Core Services
- `router` - Language model router
- `tool_registry` - Tool registry
- `service.chat` - Chat service
- `service.assistant` - Assistant service

See `docs/guides/developer/architecture/DEPENDENCY_INJECTION.md` for full list.

## Type Safety & Validation

The container validates filtered instances to prevent fatal errors. Validation ensures:

1. **Object Type**: Filtered value must be an object
2. **Interface Compatibility**: Must extend/implement original class
3. **Method Existence**: For sections, required methods must exist:
   - `get_id()`
   - `get_title()`
   - `get_tab()`
   - `render()`

### What Happens on Validation Failure?

1. Original unfiltered instance is returned
2. Error is logged to WP_MCP_AI_Logger
3. `_doing_it_wrong()` notice is triggered (in WP_DEBUG mode)
4. Site continues to function normally

## Usage Examples

### Example 1: Add Logging

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance, $id, $container ) {
    if ( WP_DEBUG ) {
        error_log( sprintf( 'Section accessed: %s at %s', $id, current_time( 'mysql' ) ) );
    }
    return $instance;
}, 10, 3 );
```

### Example 2: Decorator Pattern

```php
class My_Enhanced_Tools_Section extends WP_MCP_AI_Section_Tools {
    public function render() {
        echo '<div class="enhanced-notice">Enhanced Tools Active</div>';
        parent::render();
    }
}

add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    return new My_Enhanced_Tools_Section();
}, 10, 1 );
```

### Example 3: Conditional Enhancement

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    // Only enhance for specific users
    if ( current_user_can( 'manage_options' ) ) {
        return new My_Admin_Tools_Section();
    }
    return $instance;
}, 10, 1 );
```

### Example 4: Monitoring Wrapper

```php
add_filter( 'wp_mcp_ai_container_get_client.openai', function( $instance, $id, $container ) {
    // Track API usage
    if ( class_exists( 'My_API_Monitor' ) ) {
        My_API_Monitor::track_client_access( $id );
    }
    return $instance;
}, 10, 3 );
```

## Best Practices

### ✅ DO

1. **Maintain Interface Compatibility**
   ```php
   // Extend the original class
   class My_Section extends WP_MCP_AI_Section_Tools {
       // Your enhancements
   }
   ```

2. **Return Original on Error**
   ```php
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       try {
           return new My_Enhanced_Section();
       } catch ( Exception $e ) {
           error_log( $e->getMessage() );
           return $instance; // Return original
       }
   }, 10, 1 );
   ```

3. **Use Delegation Pattern**
   ```php
   class My_Decorator extends WP_MCP_AI_Section_Tools {
       private $original;
       
       public function __construct( $original ) {
           $this->original = $original;
       }
       
       public function render() {
           // Add before
           $this->original->render();
           // Add after
       }
   }
   ```

4. **Check Dependencies**
   ```php
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       if ( ! class_exists( 'My_Enhancement_Class' ) ) {
           return $instance;
       }
       return new My_Enhancement_Class( $instance );
   }, 10, 1 );
   ```

### ❌ DON'T

1. **Don't Return Incompatible Types**
   ```php
   // ❌ BAD - Returns stdClass
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       return new stdClass(); // Will be rejected
   }, 10, 1 );
   ```

2. **Don't Return Scalars**
   ```php
   // ❌ BAD - Returns string
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       return 'invalid'; // Will be rejected
   }, 10, 1 );
   ```

3. **Don't Remove Required Methods**
   ```php
   // ❌ BAD - Missing methods
   class Broken_Section {
       // Missing get_id(), get_title(), etc.
   }
   
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       return new Broken_Section(); // Will be rejected
   }, 10, 1 );
   ```

4. **Don't Throw Uncaught Exceptions**
   ```php
   // ❌ BAD - Can break site
   add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
       throw new Exception( 'Oops!' ); // Don't do this!
   }, 10, 1 );
   ```

## Debugging

### Check if Filter is Working

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance, $id ) {
    error_log( sprintf( 'Filter called for %s: %s', $id, get_class( $instance ) ) );
    return $instance;
}, 10, 2 );
```

### View Validation Errors

Enable WordPress debug logging:

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check `/wp-content/debug.log` for validation errors.

### Use Diagnostic Page

Navigate to **Tools → NV oOS Diagnostic** to view:
- Recent errors
- Container state
- Registered services
- Active filters

## Performance Considerations

### Caching Filtered Instances

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance, $id ) {
    static $enhanced_instance = null;
    
    if ( null === $enhanced_instance ) {
        $enhanced_instance = new My_Enhanced_Section();
    }
    
    return $enhanced_instance;
}, 10, 2 );
```

### Conditional Filtering

Only apply filters when needed:

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    // Only in admin
    if ( ! is_admin() ) {
        return $instance;
    }
    
    // Only for specific pages
    if ( ! isset( $_GET['page'] ) || 'wp-mcp-ai-dashboard' !== $_GET['page'] ) {
        return $instance;
    }
    
    return new My_Enhanced_Section();
}, 10, 1 );
```

## Advanced Patterns

### Multi-Level Decoration

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    $instance = new Logging_Decorator( $instance );
    $instance = new Caching_Decorator( $instance );
    $instance = new Analytics_Decorator( $instance );
    return $instance;
}, 10, 1 );
```

### Feature Flags

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    $features = get_option( 'my_feature_flags', array() );
    
    if ( ! empty( $features['enhanced_tools'] ) ) {
        $instance = new Enhanced_Tools_Section( $instance );
    }
    
    if ( ! empty( $features['beta_features'] ) ) {
        $instance = new Beta_Tools_Section( $instance );
    }
    
    return $instance;
}, 10, 1 );
```

### A/B Testing

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    $user_id = get_current_user_id();
    $variant = ( $user_id % 2 === 0 ) ? 'A' : 'B';
    
    if ( 'B' === $variant ) {
        return new Tools_Section_Variant_B( $instance );
    }
    
    return $instance;
}, 10, 1 );
```

## Troubleshooting

### Filter Not Being Called

**Check**: Is the service being accessed?
```php
add_action( 'init', function() {
    $section = wp_mcp_ai_container()->get( 'section.tools' );
    // Filter should fire now
} );
```

### Filtered Instance Rejected

**Check**: Does your class extend the original?
```php
// Must extend WP_MCP_AI_Section_Tools for section.tools
class My_Section extends WP_MCP_AI_Section_Tools {
    // Implementation
}
```

**Check**: Are required methods present?
```php
$methods = array( 'get_id', 'get_title', 'get_tab', 'render' );
foreach ( $methods as $method ) {
    if ( ! method_exists( $my_instance, $method ) ) {
        error_log( "Missing method: $method" );
    }
}
```

### Fatal Errors After Filtering

**Solution**: Temporarily disable your filter:
```php
// Comment out your filter
// add_filter( 'wp_mcp_ai_container_get_section.tools', ... );
```

Check debug.log for validation errors.

## Security Considerations

1. **Capability Checks**: Verify user permissions before returning enhanced instances
2. **Input Validation**: Sanitize any data used in filtered instances
3. **XSS Prevention**: Escape output in custom render methods
4. **SQL Injection**: Use prepared statements in custom queries
5. **File Access**: Validate file paths and permissions

```php
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
    // Only for authorized users
    if ( ! current_user_can( 'manage_options' ) ) {
        return $instance;
    }
    
    return new My_Admin_Only_Section();
}, 10, 1 );
```

## See Also

- [Dependency Injection Guide](./DEPENDENCY_INJECTION.md)
- [Custom Filters Guide](./CUSTOM-AI-SETTINGS-FILTERS.md)
- [Plugin Development Guide](../../developer/PLUGIN_DEVELOPMENT.md)
- [Code Examples](../../../assets/examples/container-instance-filtering.php)
