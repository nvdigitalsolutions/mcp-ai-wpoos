<?php
/**
 * Test plugin instantiation and bootstrap.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the main plugin class is properly instantiated.
 */
class Test_Plugin_Instantiation extends WP_UnitTestCase {
	/**
	 * Test that WP_MCP_AI class exists.
	 */
	public function test_main_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI' ), 'WP_MCP_AI class should exist' );
	}

	/**
	 * Test that WP_MCP_AI::instance() returns a singleton.
	 */
	public function test_singleton_pattern() {
		$instance1 = WP_MCP_AI::instance();
		$instance2 = WP_MCP_AI::instance();

		$this->assertSame( $instance1, $instance2, 'WP_MCP_AI::instance() should return the same instance' );
		$this->assertInstanceOf( 'WP_MCP_AI', $instance1, 'Instance should be of type WP_MCP_AI' );
	}

	/**
	 * Test that bootstrap function exists.
	 */
	public function test_bootstrap_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_bootstrap' ), 'wp_mcp_ai_bootstrap function should exist' );
	}

	/**
	 * Test that plugin components are initialized after bootstrap.
	 */
	public function test_components_initialized() {
		// Manually call bootstrap to ensure it's executed.
		wp_mcp_ai_bootstrap();

		$plugin = WP_MCP_AI::instance();

		// Verify key components are initialized.
		$this->assertNotNull( $plugin->resource_manager, 'Resource manager should be initialized' );
		$this->assertNotNull( $plugin->assistant_cpt, 'Assistant CPT should be initialized' );
		$this->assertNotNull( $plugin->rest_controller, 'REST controller should be initialized' );
		$this->assertNotNull( $plugin->shortcodes, 'Shortcodes should be initialized' );
		$this->assertNotNull( $plugin->federation, 'Federation should be initialized' );
		$this->assertNotNull( $plugin->crawl4ai_local_api, 'Crawl4AI Local API should be initialized' );

		// Verify admin components when in admin context.
		if ( is_admin() ) {
			$this->assertNotNull( $plugin->admin_cron_manager, 'Admin cron manager should be initialized in admin context' );
		}
	}

	/**
	 * Test that global variables are set.
	 */
	public function test_global_variables_set() {
		// Manually call bootstrap to ensure it's executed.
		wp_mcp_ai_bootstrap();

		// Verify global variables are set for backward compatibility.
		$this->assertArrayHasKey( 'wp_mcp_ai_resource_manager', $GLOBALS, 'Resource manager global should be set' );
		$this->assertArrayHasKey( 'wp_mcp_ai_assistant_cpt', $GLOBALS, 'Assistant CPT global should be set' );
		$this->assertArrayHasKey( 'wp_mcp_ai_rest_controller', $GLOBALS, 'REST controller global should be set' );
		$this->assertArrayHasKey( 'wp_mcp_ai_shortcodes', $GLOBALS, 'Shortcodes global should be set' );
	}

	/**
	 * Test that the bootstrapped action fires.
	 */
	public function test_bootstrapped_action_fires() {
		$action_fired = false;

		add_action(
			'wp_mcp_ai_bootstrapped',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		// Call bootstrap.
		wp_mcp_ai_bootstrap();

		$this->assertTrue( $action_fired, 'wp_mcp_ai_bootstrapped action should fire' );
	}
}
