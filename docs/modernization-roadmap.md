# WP oOS Modernization Roadmap

## Overview

This document outlines how to modernize WP Open Operator System to implement feasible features from modern PHP MCP server libraries, while respecting WordPress platform constraints.

## Constraint Summary

**Must Maintain:**
- PHP 7.4+ compatibility (WordPress requirement)
- WordPress coding standards
- Synchronous request-response model
- WordPress plugin architecture
- Backwards compatibility

**Cannot Implement:**
- PHP 8+ attributes (requires PHP 8.0+)
- ReactPHP async operations (incompatible with WordPress)
- True parallel execution (WordPress limitation)
- stdio transport (limited use case in web context)

## Enhancement Roadmap

### Phase 1: Enhanced Dependency Injection (2-3 weeks)

**Goal:** Improve PSR-11 container with auto-wiring and service providers

**Current State:**
```php
// includes/class-wp-mcp-ai-container.php
// Basic PSR-11 inspired container
```

**Enhancements:**
1. **Auto-wiring Support**
   - Reflection-based constructor injection
   - Automatic dependency resolution
   - Type-hint based service location

2. **Service Providers**
   - Deferred service registration
   - Lazy loading of services
   - Better separation of concerns

3. **Factory Support**
   - Closure-based factories
   - Singleton vs. transient services
   - Contextual binding

**Implementation Example:**
```php
<?php
/**
 * Enhanced PSR-11 Container with Auto-wiring
 */
class WP_MCP_AI_Enhanced_Container implements Psr\Container\ContainerInterface {
    
    /**
     * Auto-wire a class using reflection.
     */
    public function auto_wire( $class_name ) {
        $reflector = new ReflectionClass( $class_name );
        
        if ( ! $reflector->isInstantiable() ) {
            throw new Exception( "Class $class_name is not instantiable" );
        }
        
        $constructor = $reflector->getConstructor();
        
        if ( is_null( $constructor ) ) {
            return new $class_name();
        }
        
        $parameters = $constructor->getParameters();
        $dependencies = array();
        
        foreach ( $parameters as $parameter ) {
            $type = $parameter->getType();
            
            if ( ! $type || $type->isBuiltin() ) {
                if ( $parameter->isDefaultValueAvailable() ) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new Exception( "Cannot auto-wire parameter: " . $parameter->getName() );
                }
            } else {
                $dependencies[] = $this->get( $type->getName() );
            }
        }
        
        return $reflector->newInstanceArgs( $dependencies );
    }
    
    /**
     * Service provider registration.
     */
    public function register_provider( WP_MCP_AI_Service_Provider $provider ) {
        $provider->register( $this );
    }
}
```

**Benefits:**
- Reduced boilerplate code
- Better testability
- More maintainable architecture
- PSR-11 compliance

**Estimated Effort:** 80 hours

---

### Phase 2: JSON-RPC Batch Processing (1-2 weeks)

**Goal:** Implement full JSON-RPC 2.0 batch request support

**Current State:**
```php
// includes/class-wp-mcp-ai-rest.php
// Single request processing only
```

**Enhancements:**
1. **Batch Request Parser**
   - Validate batch request format
   - Handle multiple requests in single HTTP call
   - Maintain request ID mapping

2. **Batch Executor**
   - Sequential execution (WordPress limitation)
   - Error isolation per request
   - Transaction-like rollback support

3. **Batch Response Builder**
   - Maintain request/response correlation
   - Proper error handling per request
   - Streaming batch responses (optional)

**Implementation Example:**
```php
<?php
/**
 * JSON-RPC 2.0 Batch Request Handler
 */
class WP_MCP_AI_Batch_Handler {
    
    /**
     * Process batch of JSON-RPC requests.
     */
    public function process_batch( array $requests ) {
        $responses = array();
        
        foreach ( $requests as $request ) {
            try {
                // Validate individual request
                $this->validate_request( $request );
                
                // Process request
                $result = $this->process_single_request( $request );
                
                $responses[] = array(
                    'jsonrpc' => '2.0',
                    'id'      => $request['id'],
                    'result'  => $result,
                );
                
            } catch ( Exception $e ) {
                $responses[] = array(
                    'jsonrpc' => '2.0',
                    'id'      => $request['id'] ?? null,
                    'error'   => array(
                        'code'    => $e->getCode(),
                        'message' => $e->getMessage(),
                    ),
                );
            }
        }
        
        return $responses;
    }
}
```

**REST Endpoint:**
```php
// POST /wp-json/mcp-ai/v1/batch
register_rest_route( 'mcp-ai/v1', '/batch', array(
    'methods'             => 'POST',
    'callback'            => array( $this, 'handle_batch_request' ),
    'permission_callback' => array( $this, 'check_batch_permission' ),
) );
```

**Benefits:**
- Better MCP spec compliance
- Reduced HTTP overhead
- Better performance for multiple operations
- Standards-compliant implementation

**Estimated Effort:** 40 hours

---

### Phase 3: Reflection-Based Schema Generation (2 weeks)

**Goal:** Automatic tool schema generation from PHPDoc and type hints

**Current State:**
```php
// Each tool manually defines schema
public function get_definition() {
    return array(
        'name' => 'example_tool',
        'description' => 'Manual description',
        'parameters' => array(
            'type' => 'object',
            'properties' => array(
                'param1' => array(
                    'type' => 'string',
                    'description' => 'Manual param description',
                ),
            ),
        ),
    );
}
```

**Enhancements:**
1. **PHPDoc Parser**
   - Extract @param annotations
   - Parse parameter descriptions
   - Extract return type documentation

2. **Type Hint Inspector**
   - Read native PHP type hints
   - Support nullable types
   - Support union types (PHP 7.4+)

3. **Schema Builder**
   - Generate JSON schema from reflection
   - Support validation rules
   - Handle complex types (arrays, objects)

**Implementation Example:**
```php
<?php
/**
 * Automatic Schema Generator
 */
class WP_MCP_AI_Schema_Generator {
    
    /**
     * Generate tool schema from method reflection.
     *
     * @param string $class_name Tool class name.
     * @param string $method_name Method to analyze (default: 'execute').
     * @return array JSON schema.
     */
    public function generate_schema( $class_name, $method_name = 'execute' ) {
        $reflector = new ReflectionMethod( $class_name, $method_name );
        $doc_comment = $reflector->getDocComment();
        
        // Parse PHPDoc
        $phpdoc = $this->parse_phpdoc( $doc_comment );
        
        $properties = array();
        $required = array();
        
        foreach ( $reflector->getParameters() as $param ) {
            $param_name = $param->getName();
            $param_info = $phpdoc['params'][ $param_name ] ?? array();
            
            $property = array(
                'type' => $this->get_json_type( $param ),
                'description' => $param_info['description'] ?? '',
            );
            
            // Handle nullable parameters
            if ( $param->allowsNull() ) {
                $property['nullable'] = true;
            }
            
            // Add to required if no default value
            if ( ! $param->isOptional() ) {
                $required[] = $param_name;
            }
            
            $properties[ $param_name ] = $property;
        }
        
        $schema = array(
            'type' => 'object',
            'properties' => $properties,
        );
        
        if ( ! empty( $required ) ) {
            $schema['required'] = $required;
        }
        
        return $schema;
    }
    
    /**
     * Get JSON schema type from PHP type.
     */
    private function get_json_type( ReflectionParameter $param ) {
        $type = $param->getType();
        
        if ( ! $type ) {
            return 'string'; // Default
        }
        
        $type_name = $type->getName();
        
        $type_map = array(
            'int'    => 'integer',
            'float'  => 'number',
            'bool'   => 'boolean',
            'string' => 'string',
            'array'  => 'array',
            'object' => 'object',
        );
        
        return $type_map[ $type_name ] ?? 'string';
    }
}
```

**Tool Implementation:**
```php
/**
 * Example tool using auto-schema generation.
 *
 * @param string $query Search query for products.
 * @param int $limit Maximum number of results (default: 10).
 * @param bool $include_drafts Whether to include draft products.
 * @return array Product search results.
 */
public function execute( $query, $limit = 10, $include_drafts = false ) {
    // Tool implementation
}
```

**Benefits:**
- DRY principle (don't repeat yourself)
- Automatic schema updates when code changes
- Better consistency
- Less error-prone than manual schemas

**Estimated Effort:** 60 hours

---

### Phase 4: Completion Providers (1 week)

**Goal:** Implement argument and tool completion for better DX

**Enhancements:**
1. **Argument Completion**
   - Suggest valid values for parameters
   - Context-aware suggestions
   - Type-based completion

2. **Tool Completion**
   - List available tools
   - Filter by capability
   - Show tool descriptions

3. **Prompt Completion**
   - Suggest prompt shortcuts
   - Template completion
   - Variable interpolation

**Implementation Example:**
```php
<?php
/**
 * Completion Provider System
 */
class WP_MCP_AI_Completion_Provider {
    
    /**
     * Get argument completions for a tool.
     */
    public function get_argument_completions( $tool_slug, $argument_name, $context = array() ) {
        $tool = wp_mcp_ai_get_tool_registry()->get_tool( $tool_slug );
        
        if ( ! $tool ) {
            return array();
        }
        
        // Allow tools to provide custom completions
        if ( method_exists( $tool, 'get_completions' ) ) {
            return $tool->get_completions( $argument_name, $context );
        }
        
        // Generate basic completions from schema
        return $this->generate_completions_from_schema( $tool, $argument_name );
    }
    
    /**
     * Get tool completions.
     */
    public function get_tool_completions( $filter = '' ) {
        $registry = wp_mcp_ai_get_tool_registry();
        $tools = $registry->get_all_tools();
        
        $completions = array();
        
        foreach ( $tools as $slug => $tool ) {
            $definition = $tool->get_definition();
            
            // Filter by query
            if ( $filter && stripos( $definition['name'], $filter ) === false ) {
                continue;
            }
            
            $completions[] = array(
                'slug' => $slug,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category' => $definition['category'] ?? 'general',
            );
        }
        
        return $completions;
    }
}
```

**REST Endpoints:**
```php
// GET /wp-json/mcp-ai/v1/completions/tools?filter=search
// GET /wp-json/mcp-ai/v1/completions/arguments?tool=get_posts&argument=post_type
```

**Benefits:**
- Better developer experience
- Reduced errors in tool calls
- Faster development
- Better discoverability

**Estimated Effort:** 30 hours

---

### Phase 5: Enhanced Caching System (1 week)

**Goal:** Improve caching with better invalidation and precedence

**Enhancements:**
1. **Layered Caching**
   - Memory cache (runtime)
   - WordPress transients (persistent)
   - Object cache support (Redis, Memcached)

2. **Smart Invalidation**
   - Automatic invalidation on tool changes
   - Dependency-based invalidation
   - Manual override support

3. **Cache Warming**
   - Pre-populate cache for common queries
   - Background cache refresh
   - WP-Cron based warming

**Implementation Example:**
```php
<?php
/**
 * Enhanced Multi-Layer Cache
 */
class WP_MCP_AI_Enhanced_Cache {
    
    private $runtime_cache = array();
    
    /**
     * Get cached value with layered approach.
     */
    public function get( $key, $group = 'default' ) {
        $cache_key = $this->build_key( $key, $group );
        
        // Layer 1: Runtime cache
        if ( isset( $this->runtime_cache[ $cache_key ] ) ) {
            return $this->runtime_cache[ $cache_key ];
        }
        
        // Layer 2: WordPress object cache (Redis, Memcached)
        if ( wp_using_ext_object_cache() ) {
            $value = wp_cache_get( $key, $group );
            if ( $value !== false ) {
                $this->runtime_cache[ $cache_key ] = $value;
                return $value;
            }
        }
        
        // Layer 3: WordPress transients (database)
        $transient_key = 'wp_mcp_ai_' . $cache_key;
        $value = get_transient( $transient_key );
        
        if ( $value !== false ) {
            $this->runtime_cache[ $cache_key ] = $value;
            if ( wp_using_ext_object_cache() ) {
                wp_cache_set( $key, $value, $group );
            }
            return $value;
        }
        
        return null;
    }
    
    /**
     * Set cached value across all layers.
     */
    public function set( $key, $value, $group = 'default', $expiration = 3600 ) {
        $cache_key = $this->build_key( $key, $group );
        
        // Set in all layers
        $this->runtime_cache[ $cache_key ] = $value;
        
        if ( wp_using_ext_object_cache() ) {
            wp_cache_set( $key, $value, $group, $expiration );
        }
        
        $transient_key = 'wp_mcp_ai_' . $cache_key;
        set_transient( $transient_key, $value, $expiration );
        
        return true;
    }
    
    /**
     * Invalidate cache with dependency tracking.
     */
    public function invalidate( $key, $group = 'default', $cascade = true ) {
        $cache_key = $this->build_key( $key, $group );
        
        // Remove from runtime cache
        unset( $this->runtime_cache[ $cache_key ] );
        
        // Remove from object cache
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $key, $group );
        }
        
        // Remove from transients
        $transient_key = 'wp_mcp_ai_' . $cache_key;
        delete_transient( $transient_key );
        
        // Cascade to dependent caches
        if ( $cascade ) {
            $this->invalidate_dependencies( $key, $group );
        }
    }
}
```

**Benefits:**
- Better performance
- Reduced database queries
- Support for enterprise caching (Redis)
- Smarter invalidation

**Estimated Effort:** 30 hours

---

### Phase 6: Expanded Test Coverage (2 weeks)

**Goal:** Comprehensive test suite with better coverage

**Enhancements:**
1. **Unit Tests**
   - Test each tool class
   - Test helper functions
   - Test container/DI system

2. **Integration Tests**
   - Test complete workflows
   - Test REST endpoints
   - Test SSE streaming

3. **Mock Support**
   - Mock AI provider responses
   - Mock WordPress functions
   - Mock external APIs

**Implementation Example:**
```php
<?php
/**
 * Test: Enhanced DI Container
 */
class Test_WP_MCP_AI_Enhanced_Container extends WP_UnitTestCase {
    
    public function test_auto_wiring_simple_class() {
        $container = new WP_MCP_AI_Enhanced_Container();
        
        $instance = $container->auto_wire( 'Simple_Service' );
        
        $this->assertInstanceOf( 'Simple_Service', $instance );
    }
    
    public function test_auto_wiring_with_dependencies() {
        $container = new WP_MCP_AI_Enhanced_Container();
        
        // Register dependency
        $container->set( 'Logger', function() {
            return new Simple_Logger();
        } );
        
        // Auto-wire class that requires Logger
        $instance = $container->auto_wire( 'Complex_Service' );
        
        $this->assertInstanceOf( 'Complex_Service', $instance );
        $this->assertInstanceOf( 'Simple_Logger', $instance->get_logger() );
    }
    
    public function test_batch_request_processing() {
        $batch_handler = new WP_MCP_AI_Batch_Handler();
        
        $requests = array(
            array(
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => array(),
            ),
            array(
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => array(
                    'tool' => 'get_recent_posts',
                    'arguments' => array( 'post_type' => 'post' ),
                ),
            ),
        );
        
        $responses = $batch_handler->process_batch( $requests );
        
        $this->assertCount( 2, $responses );
        $this->assertEquals( 1, $responses[0]['id'] );
        $this->assertEquals( 2, $responses[1]['id'] );
    }
}
```

**Test Coverage Goals:**
- Unit tests: 80%+ coverage
- Integration tests: All major workflows
- E2E tests: Critical user paths

**Benefits:**
- Better code quality
- Catch regressions early
- Easier refactoring
- Better documentation (tests as examples)

**Estimated Effort:** 60 hours

---

## Implementation Timeline

### Total Estimated Effort: 300 hours (7-8 weeks)

**Phase 1:** Enhanced DI Container (80h) - Weeks 1-2
**Phase 2:** Batch Processing (40h) - Week 3
**Phase 3:** Schema Generation (60h) - Weeks 4-5
**Phase 4:** Completion Providers (30h) - Week 6
**Phase 5:** Enhanced Caching (30h) - Week 7
**Phase 6:** Expanded Tests (60h) - Weeks 7-8

### Dependencies
- Phase 2 can start independently
- Phase 3 can start independently
- Phase 4 depends on Phase 3 (schema generation)
- Phase 5 can start independently
- Phase 6 should be done throughout (add tests for each phase)

## Features NOT Recommended

### ❌ PHP 8+ Attributes
**Why:** Requires PHP 8.0+, breaks WordPress compatibility
**Alternative:** Continue with manual registration, improve with reflection-based schema generation

### ❌ ReactPHP Integration
**Why:** Incompatible with WordPress synchronous model
**Alternative:** Continue with WP-Cron for async operations, improve SSE streaming

### ❌ stdio Transport
**Why:** Limited use case in WordPress (web-based platform)
**Alternative:** Focus on HTTP/SSE transports which are more relevant

### ❌ True Parallel Execution
**Why:** WordPress request model doesn't support parallel execution
**Alternative:** Sequential batch processing, optimize with caching

## Success Metrics

### After Implementation, WP oOS Will Have:

1. **Better Architecture**
   - PSR-11 compliant DI container with auto-wiring
   - Reflection-based schema generation
   - Service provider pattern

2. **Better MCP Compliance**
   - JSON-RPC 2.0 batch processing
   - Completion providers
   - Enhanced schema validation

3. **Better Performance**
   - Multi-layer caching
   - Reduced database queries
   - Better cache invalidation

4. **Better Quality**
   - 80%+ test coverage
   - Automated testing
   - Better code maintainability

5. **Better Developer Experience**
   - Auto-wiring reduces boilerplate
   - Completion providers help discovery
   - Better error messages

## Migration Strategy

### Backwards Compatibility
All enhancements must maintain backwards compatibility:
- Existing tools continue to work
- Manual schemas still supported
- Old API endpoints maintained
- Graceful fallbacks

### Feature Flags
Use WordPress options to enable new features:
```php
define( 'WP_MCP_AI_ENABLE_AUTO_WIRING', true );
define( 'WP_MCP_AI_ENABLE_SCHEMA_GENERATION', true );
define( 'WP_MCP_AI_ENABLE_BATCH_PROCESSING', true );
```

### Gradual Rollout
1. Release enhancements as opt-in features
2. Test with early adopters
3. Stabilize and fix issues
4. Enable by default in next major version
5. Deprecate old patterns (with 2+ version notice)

## Conclusion

These enhancements will significantly modernize WP oOS while respecting WordPress platform constraints. The focus is on:

- **Feasibility:** All enhancements work with PHP 7.4+ and WordPress
- **Value:** Each enhancement provides clear benefits
- **Compatibility:** Backwards compatible, opt-in features
- **Quality:** Comprehensive testing for each enhancement

This brings WP oOS closer to modern MCP libraries while maintaining its WordPress-specific strengths.

---

**Last Updated:** 2025-11-10  
**Target Version:** WP oOS 1.1.0+  
**Document Version:** 1.0
