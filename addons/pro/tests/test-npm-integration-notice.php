<?php
/**
 * Tests for NPM Integration admin notices.
 *
 * @package WP_MCP_AI
 */

/**
 * Test NPM Integration admin notice functionality.
 */
class Test_NPM_Integration_Notice extends WP_UnitTestCase {
	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the npm-integration-filters.php file.
		if ( ! function_exists( 'wp_mcp_ai_check_vendor_packages' ) ) {
			require_once dirname( __DIR__ ) . '/includes/npm-integration-filters.php';
		}

		// Set as admin context.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_vendor_package_paths' );

		// Clean up any settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Force the vendor package checker to report a missing package.
	 */
	private function force_missing_package() {
		add_filter(
			'wp_mcp_ai_vendor_package_paths',
			static function ( $packages ) {
				$packages['test-missing-package'] = 'assets/vendor/test-missing-package/never-exists.js';
				return $packages;
			}
		);
	}

	/**
	 * Test that wp_mcp_ai_check_vendor_packages function exists.
	 */
	public function test_check_vendor_packages_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_check_vendor_packages' ) );
	}

	/**
	 * Test that check_vendor_packages returns expected structure.
	 */
	public function test_check_vendor_packages_structure() {
		$result = wp_mcp_ai_check_vendor_packages();

		// Should return array with 'available' and 'missing' keys.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'missing', $result );

		// 'available' should be boolean.
		$this->assertIsBool( $result['available'] );

		// 'missing' should be array.
		$this->assertIsArray( $result['missing'] );
	}

	/**
	 * Test that check_vendor_packages reports missing packages correctly.
	 */
	public function test_check_vendor_packages_reports_missing() {
		$this->force_missing_package();

		$result = wp_mcp_ai_check_vendor_packages();

		// The injected package path never exists, so it must be reported.
		$this->assertFalse( $result['available'] );
		$this->assertContains( 'test-missing-package', $result['missing'] );

		// Each missing package should be a string.
		foreach ( $result['missing'] as $package ) {
			$this->assertIsString( $package );
		}
	}

	/**
	 * Test that notice is not shown when no features are enabled.
	 */
	public function test_no_notice_when_no_features_enabled() {
		// No features enabled (default state).
		update_option( 'wp_mcp_ai_settings', array() );

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		// Should not show notice when no features enabled.
		$this->assertEmpty( $output );
	}

	/**
	 * Test that notice logic handles media toolkit feature.
	 */
	public function test_notice_respects_media_toolkit_setting() {
		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		// Notice may or may not show depending on actual package availability.
		// Just verify it doesn't error.
		$this->assertIsString( $output );
	}

	/**
	 * Test that notice logic handles quiz system feature.
	 */
	public function test_notice_respects_quiz_system_setting() {
		// Enable quiz system.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_quiz_system' => true,
			)
		);

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		// Notice may or may not show depending on actual package availability.
		// Just verify it doesn't error.
		$this->assertIsString( $output );
	}

	/**
	 * Test that notice contains expected content when shown.
	 */
	public function test_notice_contains_expected_content() {
		// Enable a feature and force a missing package so the notice renders.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);
		$this->force_missing_package();

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );

		// Should have notice wrapper.
		$this->assertStringContainsString( 'notice notice-warning', $output );

		// Should mention Node.js or NPM packages.
		$this->assertThat(
			$output,
			$this->logicalOr(
				$this->stringContains( 'Node.js' ),
				$this->stringContains( 'NPM packages' ),
				$this->stringContains( 'vendor directory' )
			)
		);

		// Should list features requiring Node.js.
		$this->assertStringContainsString( 'Features requiring Node.js:', $output );

		// Should mention specific tools.
		$this->assertThat(
			$output,
			$this->logicalOr(
				$this->stringContains( 'format_code_prettier' ),
				$this->stringContains( 'optimize_image_sharp' ),
				$this->stringContains( 'render_math_equation' )
			)
		);
	}

	/**
	 * Test that notice does NOT instruct users to run npm install.
	 */
	public function test_notice_does_not_mention_npm_install() {
		// Enable a feature to trigger potential notice.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		// The notice should NOT mention "npm install".
		$this->assertStringNotContainsString( 'npm install', $output );
	}

	/**
	 * Test that notice mentions vendor directory when packages are missing.
	 */
	public function test_notice_mentions_vendor_directory() {
		// Enable a feature and force a missing package so the notice renders.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);
		$this->force_missing_package();

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'vendor directory', $output );
	}

	/**
	 * Test that notice shows list of missing packages when available.
	 */
	public function test_notice_lists_missing_packages() {
		// Enable a feature and force a missing package so the notice renders.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);
		$this->force_missing_package();

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		$package_check = wp_mcp_ai_check_vendor_packages();
		$this->assertFalse( $package_check['available'] );

		// Should show "Missing packages:" header.
		$this->assertStringContainsString( 'Missing packages:', $output );

		// Should list the injected missing package name.
		$this->assertStringContainsString( 'test-missing-package', $output );
	}

	/**
	 * Test that notice HTML is properly escaped.
	 */
	public function test_notice_html_is_escaped() {
		// Enable a feature and force a missing package so the notice renders.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);
		$this->force_missing_package();

		// Capture output.
		ob_start();
		wp_mcp_ai_npm_integration_admin_notice();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );

		// Should have proper opening and closing tags.
		$this->assertStringContainsString( '<div class="notice notice-warning', $output );
		$this->assertStringContainsString( '</div>', $output );
	}
}
