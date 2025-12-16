<?php
/**
 * Tests for Elementor chat usage timer widget.
 *
 * Verifies that the widget properly initializes the totals array with all required keys,
 * including cached_prompt_tokens.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor chat usage timer widget.
 */
class WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget_Test extends WP_UnitTestCase {

	/**
	 * Widget instance for testing.
	 *
	 * @var WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget
	 */
	private $widget;

	/**
	 * Required keys that should exist in the totals array.
	 *
	 * @var array
	 */
	private $required_totals_keys = array(
		'prompt_tokens',
		'completion_tokens',
		'cached_prompt_tokens',
		'total_tokens',
		'cached_tokens',
	);

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Load the trait first, as the widget depends on it.
		$trait_file = dirname( __DIR__ ) . '/includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		if ( file_exists( $trait_file ) && ! trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
			require_once $trait_file;
		}

		// Include the widget file if it exists.
		$widget_file = dirname( __DIR__ ) . '/includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php';
		if ( file_exists( $widget_file ) && trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
			require_once $widget_file;
		}

		// Check if the class exists before creating instance.
		if ( class_exists( 'WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget' ) ) {
			$this->widget = new WP_MCP_AI_Elementor_Chat_Usage_Timer_Widget();
		}
	}

	/**
	 * Test that get_usage_summary returns array with all required keys when Usage_Tracker is not available.
	 */
	public function test_get_usage_summary_without_usage_tracker() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Widget class not available' );
		}

		// Get the method using reflection to test protected method.
		$reflection = new ReflectionClass( $this->widget );
		$method     = $reflection->getMethod( 'get_usage_summary' );
		$method->setAccessible( true );

		$summary = $method->invoke( $this->widget );

		// Verify the structure.
		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertArrayHasKey( 'totals', $summary, 'Summary should have totals key' );
		$this->assertIsArray( $summary['totals'], 'Totals should be an array' );

		// Verify all required keys exist in totals.
		foreach ( $this->required_totals_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$summary['totals'],
				"Totals array should have '{$key}' key"
			);
		}
	}

	/**
	 * Test that get_usage_summary returns array with all required keys when user is not logged in.
	 */
	public function test_get_usage_summary_not_logged_in() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Widget class not available' );
		}

		// Make sure user is not logged in.
		wp_set_current_user( 0 );

		// Get the method using reflection.
		$reflection = new ReflectionClass( $this->widget );
		$method     = $reflection->getMethod( 'get_usage_summary' );
		$method->setAccessible( true );

		$summary = $method->invoke( $this->widget );

		// Verify the structure.
		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertArrayHasKey( 'totals', $summary, 'Summary should have totals key' );
		$this->assertIsArray( $summary['totals'], 'Totals should be an array' );

		// Verify all required keys exist in totals.
		foreach ( $this->required_totals_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$summary['totals'],
				"Totals array should have '{$key}' key"
			);
		}
	}

	/**
	 * Test that all keys in totals array have integer values.
	 */
	public function test_totals_values_are_integers() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Widget class not available' );
		}

		// Make sure user is not logged in for predictable test case.
		wp_set_current_user( 0 );

		// Get the method using reflection.
		$reflection = new ReflectionClass( $this->widget );
		$method     = $reflection->getMethod( 'get_usage_summary' );
		$method->setAccessible( true );

		$summary = $method->invoke( $this->widget );

		// Verify all values are integers.
		foreach ( $summary['totals'] as $key => $value ) {
			$this->assertIsInt(
				$value,
				"Value for '{$key}' should be an integer"
			);
		}
	}

	/**
	 * Test that cached_prompt_tokens can be accessed without PHP warnings.
	 */
	public function test_cached_prompt_tokens_no_undefined_key_warning() {
		if ( ! $this->widget ) {
			$this->markTestSkipped( 'Widget class not available' );
		}

		// Set up a custom error handler to catch any warnings.
		$warning_occurred = false;
		$warning_message  = '';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Used in test to verify no warnings occur.
		set_error_handler(
			function ( $errno, $errstr ) use ( &$warning_occurred, &$warning_message ) {
				if ( E_WARNING === $errno || E_NOTICE === $errno ) {
					$warning_occurred = true;
					$warning_message  = $errstr;
				}
				// Return true to suppress the default error handler.
				return true;
			},
			E_ALL
		);

		// Make sure user is not logged in for predictable test case.
		wp_set_current_user( 0 );

		// Get the method using reflection.
		$reflection = new ReflectionClass( $this->widget );
		$method     = $reflection->getMethod( 'get_usage_summary' );
		$method->setAccessible( true );

		// This should not produce any PHP warnings about undefined array keys.
		$summary = $method->invoke( $this->widget );

		// Restore the original error handler.
		restore_error_handler();

		// Assert no warnings occurred.
		$this->assertFalse(
			$warning_occurred,
			"No warnings should occur when accessing cached_prompt_tokens. Got: {$warning_message}"
		);

		// Verify the key exists and is an integer.
		$this->assertIsInt(
			$summary['totals']['cached_prompt_tokens'],
			'cached_prompt_tokens should be an integer'
		);
	}
}
