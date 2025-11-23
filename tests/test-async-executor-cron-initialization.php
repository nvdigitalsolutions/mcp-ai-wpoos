<?php
/**
 * Tests for async executor initialization during cron execution with root security key.
 *
 * Verifies that the WP_MCP_AI_Tool_Async_Executor properly initializes during
 * WordPress cron execution even when the root security key is required.
 *
 * @package WP_MCP_AI
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';

/**
 * Test async executor initialization during cron with security key.
 */
class WP_MCP_AI_Async_Executor_Cron_Initialization_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure DOING_CRON is defined for cron context tests.
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}

		// Reset the executor's static hooks flag before each test.
		// This is safe because @runInSeparateProcess isolates each test.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Async_Executor' );
		$property   = $reflection->getProperty( 'hooks_registered' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up options.
		delete_option( 'wp_mcp_ai_require_root_key' );

		parent::tearDown();
	}

	/**
	 * Test that bootstrap proceeds during cron execution when security key is required.
	 */
	public function test_bootstrap_allowed_during_cron_with_security_key() {
		// Enable root security key requirement.
		update_option( 'wp_mcp_ai_require_root_key', true );

		// Get security key instance.
		$security_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Verify that initialization is allowed during cron.
		$can_initialize = $security_key->can_initialize();
		
		$this->assertTrue(
			$can_initialize,
			'Plugin should be allowed to initialize during cron execution even when root security key is required'
		);
	}

	/**
	 * Test that async executor can be initialized during cron execution.
	 */
	public function test_async_executor_initializes_during_cron() {
		global $wp_filter;

		// Enable root security key requirement.
		update_option( 'wp_mcp_ai_require_root_key', true );

		// Create and initialize executor (simulates what happens during bootstrap).
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();

		// Verify the cron hook is registered.
		$hook_name = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;
		$this->assertTrue(
			isset( $wp_filter[ $hook_name ] ),
			'Async executor cron hook should be registered during cron execution'
		);
	}

	/**
	 * Test that executor init is idempotent.
	 */
	public function test_executor_init_is_idempotent() {
		global $wp_filter;

		// Create executor.
		$executor = new WP_MCP_AI_Tool_Async_Executor();

		// Call init multiple times.
		$executor->init();
		$executor->init();
		$executor->init();

		// Verify the hook is registered only once (not duplicated).
		$hook_name = WP_MCP_AI_Tool_Async_Executor::CRON_HOOK;
		$callback_count = 0;

		if ( isset( $wp_filter[ $hook_name ] ) ) {
			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) &&
					     $callback['function'][0] instanceof WP_MCP_AI_Tool_Async_Executor &&
					     'execute_async_tool' === $callback['function'][1] ) {
						++$callback_count;
					}
				}
			}
		}

		$this->assertSame(
			1,
			$callback_count,
			'Executor callback should be registered exactly once, even when init() is called multiple times'
		);
	}
}
