<?php
/**
 * Test AJAX handlers use container for section instances
 *
 * Tests that AJAX handlers get section instances from the container
 * instead of creating new instances directly, allowing filters to work.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for AJAX handler container integration.
 */
class Test_AJAX_Container_Integration extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user for capability checks.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		remove_all_filters( 'wp_mcp_ai_container_get_section.tools' );
		remove_all_filters( 'wp_mcp_ai_container_get_section.token_manager' );
		wp_mcp_ai_container()->clear();
	}

	/**
	 * Test that tools manager AJAX handler uses container.
	 */
	public function test_tools_manager_ajax_uses_container() {
		$filter_called = false;

		// Add filter to track if container is used.
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$filter_called ) {
				$filter_called = true;
				return $instance;
			}
		);

		// Simulate AJAX request.
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		// Get AJAX handler.
		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		// Capture output.
		ob_start();
		try {
			$ajax_handler->handle_filter_tools_manager();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_success() throws this in tests.
		}
		$output = ob_get_clean();

		$this->assertTrue( $filter_called, 'Container filter should be called for tools manager AJAX' );
	}

	/**
	 * Test that token manager AJAX handler uses container.
	 */
	public function test_token_manager_ajax_uses_container() {
		$filter_called = false;

		// Add filter to track if container is used.
		add_filter(
			'wp_mcp_ai_container_get_section.token_manager',
			function ( $instance ) use ( &$filter_called ) {
				$filter_called = true;
				return $instance;
			}
		);

		// Simulate AJAX request.
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		// Get AJAX handler.
		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		// Capture output.
		ob_start();
		try {
			$ajax_handler->handle_filter_token_manager_tools();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_success() throws this in tests.
		}
		$output = ob_get_clean();

		$this->assertTrue( $filter_called, 'Container filter should be called for token manager AJAX' );
	}

	/**
	 * Test that AJAX gets same instance as main page (singleton pattern).
	 */
	public function test_ajax_gets_singleton_instance() {
		// Get section from container (simulating main page load).
		$main_section = wp_mcp_ai_container()->get( 'section.tools' );

		// Simulate AJAX request.
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		$ajax_section = null;

		// Capture the section instance used in AJAX.
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$ajax_section ) {
				$ajax_section = $instance;
				return $instance;
			}
		);

		// Get AJAX handler.
		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		// Trigger AJAX handler.
		ob_start();
		try {
			$ajax_handler->handle_filter_tools_manager();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		ob_get_clean();

		// Should be the same instance (singleton).
		$this->assertSame(
			$main_section,
			$ajax_section,
			'AJAX should get the same singleton instance from container'
		);
	}

	/**
	 * Test that container filters apply to AJAX-rendered content.
	 */
	public function test_container_filters_apply_to_ajax_content() {
		// Create a test filter that modifies the section.
		if ( ! class_exists( 'Test_Modified_Tools_Section' ) ) {
			eval( '
				class Test_Modified_Tools_Section extends WP_MCP_AI_Section_Tools {
					public function render_tools_manager_content() {
						echo "FILTERED_CONTENT_MARKER";
						parent::render_tools_manager_content();
					}
				}
			' );
		}

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				return new Test_Modified_Tools_Section();
			}
		);

		// Simulate AJAX request.
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		// Get AJAX handler.
		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		// Capture output.
		ob_start();
		try {
			$ajax_handler->handle_filter_tools_manager();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'FILTERED_CONTENT_MARKER',
			$output,
			'Filtered section content should appear in AJAX response'
		);
	}

	/**
	 * Test that no duplicate hooks are registered when AJAX reuses instance.
	 */
	public function test_no_duplicate_hooks_in_ajax() {
		// Get section from container (registers hooks in constructor).
		$section = wp_mcp_ai_container()->get( 'section.tools' );

		// Count how many times the hook is registered.
		global $wp_filter;
		$hook_count_before = 0;
		if ( isset( $wp_filter['admin_init'] ) ) {
			foreach ( $wp_filter['admin_init']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) &&
					     $callback['function'][0] === $section &&
					     'handle_elementor_kit_import' === $callback['function'][1] ) {
						$hook_count_before++;
					}
				}
			}
		}

		// Simulate AJAX request (should reuse same instance).
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		ob_start();
		try {
			$ajax_handler->handle_filter_tools_manager();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		ob_get_clean();

		// Count hooks after AJAX.
		$hook_count_after = 0;
		if ( isset( $wp_filter['admin_init'] ) ) {
			foreach ( $wp_filter['admin_init']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) &&
					     $callback['function'][0] === $section &&
					     'handle_elementor_kit_import' === $callback['function'][1] ) {
						$hook_count_after++;
					}
				}
			}
		}

		$this->assertEquals(
			$hook_count_before,
			$hook_count_after,
			'AJAX should not register duplicate hooks'
		);
	}

	/**
	 * Test that method exists on section returned to AJAX handler.
	 */
	public function test_ajax_section_has_required_method() {
		$_POST['search']       = '';
		$_POST['filter_group'] = '';
		$_POST['nonce']        = wp_create_nonce( 'wp-mcp-ai-filter-tools' );

		$ajax_section = null;

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$ajax_section ) {
				$ajax_section = $instance;
				return $instance;
			}
		);

		$ajax_handler = wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

		ob_start();
		try {
			$ajax_handler->handle_filter_tools_manager();
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}
		ob_get_clean();

		$this->assertTrue(
			method_exists( $ajax_section, 'handle_elementor_kit_import' ),
			'Section from AJAX should have handle_elementor_kit_import method'
		);

		$this->assertTrue(
			method_exists( $ajax_section, 'render_tools_manager_content' ),
			'Section from AJAX should have render_tools_manager_content method'
		);
	}
}
