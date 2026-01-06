<?php
/**
 * Tests for Pro addon text domain loading.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Pro addon text domain loading.
 */
class Test_Pro_Textdomain_Loading extends WP_UnitTestCase {

	/**
	 * Test that wp_mcp_ai_pro_load_textdomain function exists.
	 */
	public function test_pro_load_textdomain_function_exists() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_pro_load_textdomain' ),
			'wp_mcp_ai_pro_load_textdomain function should exist when Pro addon is loaded'
		);
	}

	/**
	 * Test that Pro addon text domain loading is registered on init hook.
	 */
	public function test_pro_textdomain_registered_on_init() {
		global $wp_filter;

		$this->assertArrayHasKey(
			'init',
			$wp_filter,
			'init hook should exist'
		);

		// Check if wp_mcp_ai_pro_load_textdomain is hooked to init.
		$has_textdomain_hook = false;
		if ( isset( $wp_filter['init'] ) ) {
			foreach ( $wp_filter['init'] as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( isset( $callback['function'] ) ) {
						$function = $callback['function'];
						if ( is_string( $function ) && 'wp_mcp_ai_pro_load_textdomain' === $function ) {
							$has_textdomain_hook = true;
							// Should be priority 1 (before CPT registration at priority 10).
							$this->assertEquals(
								1,
								$priority,
								'Pro textdomain loading should be at priority 1 on init hook'
							);
							break 2;
						}
					}
				}
			}
		}

		$this->assertTrue(
			$has_textdomain_hook,
			'wp_mcp_ai_pro_load_textdomain should be hooked to init action'
		);
	}

	/**
	 * Test that Pro addon constants are defined.
	 */
	public function test_pro_constants_defined() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION constant should be defined'
		);

		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_FILE' ),
			'WP_MCP_AI_PRO_FILE constant should be defined'
		);

		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_PATH' ),
			'WP_MCP_AI_PRO_PATH constant should be defined'
		);
	}

	/**
	 * Test that Pro addon languages directory exists.
	 */
	public function test_pro_languages_directory_exists() {
		$languages_dir = WP_MCP_AI_PRO_PATH . 'languages';

		$this->assertTrue(
			is_dir( $languages_dir ),
			'Pro addon languages directory should exist at: ' . $languages_dir
		);
	}

	/**
	 * Test that Pro CPT registration happens after text domain loading.
	 */
	public function test_pro_cpt_registration_priority() {
		global $wp_filter;

		// Find wp_mcp_ai_register_project_management_post_types priority.
		$cpt_priority         = null;
		$textdomain_priority  = null;

		if ( isset( $wp_filter['init'] ) ) {
			foreach ( $wp_filter['init'] as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( isset( $callback['function'] ) ) {
						$function = $callback['function'];
						if ( is_string( $function ) ) {
							if ( 'wp_mcp_ai_register_project_management_post_types' === $function ) {
								$cpt_priority = $priority;
							}
							if ( 'wp_mcp_ai_pro_load_textdomain' === $function ) {
								$textdomain_priority = $priority;
							}
						}
					}
				}
			}
		}

		// Text domain should load before CPT registration.
		if ( null !== $textdomain_priority && null !== $cpt_priority ) {
			$this->assertLessThan(
				$cpt_priority,
				$textdomain_priority,
				'Text domain loading should happen before CPT registration'
			);
		}
	}
}
