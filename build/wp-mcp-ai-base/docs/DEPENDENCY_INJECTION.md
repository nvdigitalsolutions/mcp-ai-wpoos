# Dependency Injection Container - Developer Guide

## Overview

WP oOS uses a dependency injection (DI) container to manage all service instantiation. This eliminates hard-coded dependencies and makes the codebase fully testable.

## Quick Start

### Getting Services from the Container

```php
// Get the container
$container = wp_mcp_ai_container();

// Or use the helper function
$container = wp_mcp_ai();

// Get a service
$router = wp_mcp_ai( 'router' );

// Or use the container directly
$router = wp_mcp_ai_container()->get( 'router' );
```

### Creating Instances with Dependency Resolution

```php
// Create an instance with automatic dependency resolution
$instance = wp_mcp_ai_make( 'WP_MCP_AI_Some_Class' );

// Create with additional parameters
$instance = wp_mcp_ai_make( 'WP_MCP_AI_Some_Class', array(
    'param_name' => 'value',
) );
```

## Registered Services

### Language Model Clients

- `client.openai` - OpenAI API client
- `client.gemini` - Google Gemini API client
- `client.ollama` - Ollama local LLM client
- `client.lm_studio` - LM Studio client
- `client.anthropic` - Anthropic Claude client

### Core Components

- `router` - Language model router (has all clients injected)
- `assistant_cpt` - Assistant custom post type manager
- `rest_controller` - REST API controller
- `shortcodes` - WordPress shortcodes handler
- `federation` - Federation manager
- `crawl4ai_local_api` - Crawl4AI integration
- `tool_registry` - Tool registry singleton

### Admin Components

- `admin.cron_manager` - Cron job manager
- `admin.test_assistant` - Test assistant page
- `admin.ajax_handlers` - AJAX request handlers
- `admin.settings_base` - Base settings class
- `admin.settings_renderer` - Settings page renderer
- `admin.oauth_manager` - OAuth authentication manager
- `admin.settings_dashboard` - Settings dashboard controller
- `admin.auth0_setup` - Auth0 setup wizard
- `admin.jetengine_integration` - JetEngine admin integration
- `admin.woocommerce_integration` - WooCommerce admin integration
- `admin.elementor_integration` - Elementor admin integration
- `admin.gmail_crawl_integration` - Gmail/Crawl4AI admin integration
- `admin.custom_filters_applicator` - Custom filters applicator

### Settings Sections

- `section.overview` - Overview settings section
- `section.general` - General settings section
- `section.custom_filters` - Custom filters section
- `section.providers` - AI providers section
- `section.authentication` - Authentication section
- `section.tools` - Tools configuration section
- `section.orchestration` - Orchestration settings section
- `section.jetengine_integration` - JetEngine integration section
- `section.woocommerce_integration` - WooCommerce integration section
- `section.elementor_integration` - Elementor integration section
- `section.token_manager` - Token manager section
- `section.security` - Security settings section
- `section.performance` - Performance settings section
- `section.advanced` - Advanced settings section

### Services

- `service.chat` - Chat service
- `service.assistant` - Assistant service
- `service.tool` - Tool service
- `service.file` - File handling service

### Repositories

- `repository.assistant` - Assistant data repository
- `repository.credential` - Credential repository
- `repository.settings` - Settings repository

## Writing Testable Code

### Before (Hard-coded Dependencies)

```php
class WP_MCP_AI_My_Class {
    private $router;
    
    public function __construct() {
        // Hard-coded - cannot inject mock for testing
        $this->router = new WP_MCP_AI_Language_Model_Router();
    }
}
```

### After (Dependency Injection)

```php
class WP_MCP_AI_My_Class {
    private $router;
    
    public function __construct( $router = null ) {
        // Use injected dependency or get from container
        $this->router = $router ?? wp_mcp_ai( 'router' );
    }
}
```

### Testing with Mocks

```php
class Test_My_Class extends WP_UnitTestCase {
    public function test_my_method() {
        // Create a mock router
        $mock_router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
        
        // Inject mock into class
        $my_class = new WP_MCP_AI_My_Class( $mock_router );
        
        // Now you can test without hitting real APIs
        $result = $my_class->do_something();
        
        $this->assertEquals( 'expected', $result );
    }
}
```

## Registering New Services

### Singleton (Shared Instance)

```php
// In includes/class-wp-mcp-ai-container.php
$this->singleton(
    'my_service',
    function ( $container ) {
        return new WP_MCP_AI_My_Service(
            $container->get( 'dependency' )
        );
    }
);
```

### Transient (New Instance Each Time)

```php
$this->transient(
    'my_transient',
    function () {
        return new WP_MCP_AI_My_Transient();
    }
);
```

### Using Dependencies

```php
$this->singleton(
    'complex_service',
    function ( $container ) {
        return new WP_MCP_AI_Complex_Service(
            $container->get( 'router' ),
            $container->get( 'tool_registry' ),
            $container->get( 'rate_limiter' )
        );
    }
);
```

## Container API

### Methods

#### `get( $id )`
Get a service from the container. Returns cached instance for singletons.

```php
$router = $container->get( 'router' );
```

#### `has( $id )`
Check if a service is registered.

```php
if ( $container->has( 'router' ) ) {
    $router = $container->get( 'router' );
}
```

#### `set( $id, $instance )`
Set a pre-created instance.

```php
$container->set( 'my_service', $my_instance );
```

#### `register( $id, callable $factory, $shared = true )`
Register a service definition.

```php
$container->register(
    'my_service',
    function () {
        return new WP_MCP_AI_My_Service();
    },
    true // shared singleton
);
```

#### `singleton( $id, callable $factory )`
Register a singleton service.

#### `transient( $id, callable $factory )`
Register a transient service (new instance each time).

#### `make( $class_name, array $params = [] )`
Create an instance with automatic dependency resolution.

```php
$instance = $container->make( 'WP_MCP_AI_Some_Class', array(
    'param' => 'value',
) );
```

#### `clear()`
Clear all cached instances (useful for testing).

```php
$container->clear();
```

#### `get_registered_services()`
Get array of all registered service IDs.

```php
$services = $container->get_registered_services();
// Returns: ['router', 'client.openai', ...]
```

## Best Practices

### 1. Constructor Injection

Always prefer constructor injection over creating dependencies directly:

```php
// Good
public function __construct( $router = null ) {
    $this->router = $router ?? wp_mcp_ai( 'router' );
}

// Bad
public function __construct() {
    $this->router = new WP_MCP_AI_Language_Model_Router();
}
```

### 2. Type Hints

Use type hints for better IDE support and error detection:

```php
public function __construct( 
    WP_MCP_AI_Language_Model_Router $router = null 
) {
    $this->router = $router ?? wp_mcp_ai( 'router' );
}
```

### 3. Optional Dependencies

Make dependencies optional with defaults to maintain backward compatibility:

```php
public function __construct( $router = null, $registry = null ) {
    $container      = wp_mcp_ai_container();
    $this->router   = $router ?? $container->get( 'router' );
    $this->registry = $registry ?? $container->get( 'tool_registry' );
}
```

### 4. Service Location vs Dependency Injection

Prefer dependency injection over service location:

```php
// Good - Dependency Injection
public function __construct( $router ) {
    $this->router = $router;
}

// Acceptable - Service Location with fallback
public function __construct( $router = null ) {
    $this->router = $router ?? wp_mcp_ai( 'router' );
}

// Avoid - Pure Service Location
public function __construct() {
    $this->router = wp_mcp_ai( 'router' );
}
```

### 5. Testing

Clear the container between tests:

```php
public function setUp(): void {
    parent::setUp();
    wp_mcp_ai_container()->clear();
}
```

## Migration Guide

### Migrating Existing Code

1. **Identify hard-coded instantiations:**
   ```bash
   grep -r "new WP_MCP_AI_" includes/
   ```

2. **Register in container:**
   ```php
   $this->singleton( 'my_service', function() {
       return new WP_MCP_AI_My_Service();
   });
   ```

3. **Update consumers:**
   ```php
   // Before
   $service = new WP_MCP_AI_My_Service();
   
   // After
   $service = wp_mcp_ai( 'my_service' );
   ```

4. **Update classes to accept injection:**
   ```php
   public function __construct( $dependency = null ) {
       $this->dependency = $dependency ?? wp_mcp_ai( 'dependency' );
   }
   ```

## Troubleshooting

### Service Not Found

```
Service "my_service" not found in container.
```

**Solution:** Register the service in `includes/class-wp-mcp-ai-container.php`:

```php
$this->singleton( 'my_service', function() {
    return new WP_MCP_AI_My_Service();
});
```

### Circular Dependencies

If you get infinite recursion, you have circular dependencies.

**Solution:** Refactor to break the cycle, or use lazy loading:

```php
$this->singleton( 'service_a', function( $container ) {
    return new WP_MCP_AI_Service_A(
        function() use ( $container ) {
            return $container->get( 'service_b' );
        }
    );
});
```

### Cannot Resolve Parameter

```
Cannot resolve parameter "param_name" for class "WP_MCP_AI_My_Class".
```

**Solution:** Either provide a default value or pass the parameter when creating:

```php
$instance = wp_mcp_ai_make( 'WP_MCP_AI_My_Class', array(
    'param_name' => $value,
) );
```

## References

- Container implementation: `includes/class-wp-mcp-ai-container.php`
- Helper functions: `includes/container-helpers.php`
- Test examples: `tests/test-container-dependency-injection.php`
- SOLID Principles: https://en.wikipedia.org/wiki/SOLID
- Dependency Injection: https://en.wikipedia.org/wiki/Dependency_injection
