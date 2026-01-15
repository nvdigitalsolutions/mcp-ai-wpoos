<?php
/**
 * Example: Container Instance Filtering
 *
 * Demonstrates how to use the wp_mcp_ai_container_get_{$id} filter
 * to modify or decorate service instances from the DI container.
 *
 * IMPORTANT: Filtered instances must maintain the same interface/methods
 * as the original to avoid fatal errors.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

// =============================================================================
// EXAMPLE 1: Logging Decorator (Safe - maintains interface)
// =============================================================================

/**
 * Add logging to section rendering without breaking functionality.
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance, $id, $container ) {
	// Only decorate in development mode.
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return $instance;
	}

	// Create a logging decorator that extends the original class.
	return new class( $instance ) extends WP_MCP_AI_Section_Tools {
		private $wrapped_instance;

		public function __construct( $instance ) {
			$this->wrapped_instance = $instance;
			// Call parent constructor to register hooks.
			parent::__construct();
		}

		public function render() {
			error_log( 'Rendering Tools section' );
			return $this->wrapped_instance->render();
		}

		// Delegate other methods to wrapped instance.
		public function __call( $method, $args ) {
			return call_user_func_array( array( $this->wrapped_instance, $method ), $args );
		}
	};
}, 10, 3 );

// =============================================================================
// EXAMPLE 2: Caching Decorator (Safe - maintains interface)
// =============================================================================

/**
 * Add caching to expensive section operations.
 */
add_filter( 'wp_mcp_ai_container_get_section.overview', function( $instance ) {
	// Wrap with caching without changing the interface.
	$cached_data = wp_cache_get( 'overview_section_data', 'mcp_ai' );
	
	if ( false !== $cached_data ) {
		// You could set cached data on the instance here.
		// But DON'T replace the instance with incompatible type!
	}

	return $instance; // Always return compatible instance.
}, 10, 1 );

// =============================================================================
// EXAMPLE 3: Feature Flag Control (Safe - returns original or enhanced)
// =============================================================================

/**
 * Conditionally enable enhanced features based on flags.
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
	// Check if enhanced tools are enabled.
	$enable_enhanced = get_option( 'wp_mcp_ai_enable_enhanced_tools', false );

	if ( ! $enable_enhanced ) {
		return $instance; // Return original.
	}

	// Return enhanced version that extends the original.
	if ( class_exists( 'WP_MCP_AI_Section_Tools_Enhanced' ) ) {
		return new WP_MCP_AI_Section_Tools_Enhanced();
	}

	return $instance;
}, 10, 1 );

// =============================================================================
// EXAMPLE 4: Monitoring and Metrics (Safe - transparent decoration)
// =============================================================================

/**
 * Track section usage for analytics without breaking functionality.
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance, $id ) {
	// Track that this section was accessed.
	if ( function_exists( 'wp_mcp_ai_track_event' ) ) {
		wp_mcp_ai_track_event( 'section_accessed', array(
			'section_id' => $id,
			'timestamp'  => time(),
		) );
	}

	// Always return the original instance unmodified.
	return $instance;
}, 10, 2 );

// =============================================================================
// ANTI-PATTERN: DO NOT DO THIS! (Unsafe - breaks interface)
// =============================================================================

/**
 * ❌ BAD EXAMPLE - Returns incompatible instance.
 *
 * This will cause fatal errors when code tries to call expected methods!
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
	// ❌ NEVER return a completely different class!
	return new stdClass(); // This will be rejected by validation.
}, 10, 1 );

/**
 * ❌ BAD EXAMPLE - Returns non-object.
 *
 * This will cause fatal errors!
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
	// ❌ NEVER return scalar values!
	return 'invalid'; // This will be rejected by validation.
}, 10, 1 );

/**
 * ❌ BAD EXAMPLE - Returns object missing required methods.
 *
 * This will cause fatal errors when hooks try to call methods!
 */
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
	// ❌ NEVER return objects without required methods!
	return new class {
		// Missing handle_elementor_kit_import() method!
		// This will be rejected by validation.
	};
}, 10, 1 );

// =============================================================================
// BEST PRACTICES
// =============================================================================

/**
 * Best Practices for Container Instance Filtering:
 *
 * 1. MAINTAIN INTERFACE
 *    - Filtered instances must have all methods of the original
 *    - Extend the original class or implement the same interface
 *
 * 2. TEST THOROUGHLY
 *    - Test that all hooks registered in constructor still work
 *    - Verify all public methods are still callable
 *    - Check for fatal errors in admin and frontend
 *
 * 3. USE DECORATION PATTERN
 *    - Wrap the original instance rather than replacing it
 *    - Delegate to original for core functionality
 *    - Add your enhancements on top
 *
 * 4. HANDLE ERRORS GRACEFULLY
 *    - Return original instance if enhancement fails
 *    - Don't throw exceptions that break the site
 *    - Log errors for debugging
 *
 * 5. DOCUMENT YOUR FILTERS
 *    - Explain why you're filtering the instance
 *    - Document what methods you're overriding
 *    - Note any potential side effects
 *
 * 6. RESPECT VALIDATION
 *    - The container validates filtered instances
 *    - Incompatible instances are rejected automatically
 *    - Original instance is returned on validation failure
 *    - Check error logs if your filter isn't working
 */

// =============================================================================
// SAFE DECORATOR PATTERN EXAMPLE
// =============================================================================

/**
 * Example of a safe decorator that extends functionality.
 */
class WP_MCP_AI_Section_Tools_Decorator extends WP_MCP_AI_Section_Tools {
	/**
	 * The original instance being decorated.
	 *
	 * @var WP_MCP_AI_Section_Tools
	 */
	private $original;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Section_Tools $original Original instance.
	 */
	public function __construct( WP_MCP_AI_Section_Tools $original ) {
		$this->original = $original;
		// Don't call parent::__construct() to avoid duplicate hook registration.
	}

	/**
	 * Override render to add custom functionality.
	 *
	 * @return void
	 */
	public function render() {
		// Add custom content before.
		echo '<div class="custom-tools-notice">Custom Tools Features Enabled</div>';

		// Call original render.
		$this->original->render();

		// Add custom content after.
		echo '<div class="custom-tools-footer">Powered by Enhanced Tools</div>';
	}

	/**
	 * Delegate all other method calls to original instance.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed
	 */
	public function __call( $method, $args ) {
		return call_user_func_array( array( $this->original, $method ), $args );
	}

	/**
	 * Delegate property access to original instance.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->original->$name;
	}

	/**
	 * Delegate property setting to original instance.
	 *
	 * @param string $name  Property name.
	 * @param mixed  $value Property value.
	 */
	public function __set( $name, $value ) {
		$this->original->$name = $value;
	}

	/**
	 * Delegate isset checks to original instance.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( $name ) {
		return isset( $this->original->$name );
	}
}

// Use the decorator:
add_filter( 'wp_mcp_ai_container_get_section.tools', function( $instance ) {
	return new WP_MCP_AI_Section_Tools_Decorator( $instance );
}, 10, 1 );
