<?php
/**
 * Test Embedded Provider Diagnostics Page Visibility
 *
 * Tests that the embedded provider section on the diagnostics page is visible
 * when the Pro addon is active, using the correct detection pattern.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider diagnostics visibility.
 */
class WP_MCP_AI_Embedded_Provider_Diagnostics_Visibility_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );

		// Ensure the diagnostic class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Provider_Diagnostics' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		}
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that the embedded provider section is visible when Pro is active.
	 *
	 * This test verifies that the diagnostics page uses the correct Pro version
	 * detection pattern (WP_MCP_AI_PRO_VERSION) instead of the old base version
	 * pattern.
	 */
	public function test_embedded_section_visible_with_pro_version() {
		// Skip if Pro addon is not active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Get the diagnostics page output.
		ob_start();
		WP_MCP_AI_Provider_Diagnostics::render_page();
		$output = ob_get_clean();

		// Verify that the embedded provider section is present.
		$this->assertStringContainsString(
			'Embedded LLM (Local AI - Pro)',
			$output,
			'Embedded LLM section should be visible when Pro addon is active'
		);

		// Verify that the test button is present.
		$this->assertStringContainsString(
			'Test Embedded LLM Connection',
			$output,
			'Test Embedded LLM Connection button should be present'
		);

		// Verify that the embedded test result div is present.
		$this->assertStringContainsString(
			'embedded-test-result',
			$output,
			'embedded-test-result div should be present'
		);
	}

	/**
	 * Test that the embedded provider test endpoint works with Pro version.
	 *
	 * This test verifies that the test_embedded method correctly checks for
	 * WP_MCP_AI_PRO_VERSION instead of WP_MCP_AI_BASE_VERSION.
	 */
	public function test_embedded_test_endpoint_requires_pro_version() {
		// Create an admin user.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Simulate AJAX request.
		$_POST['action']   = 'wp_mcp_ai_test_provider';
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-provider-diagnostic' );
		$_POST['provider'] = 'embedded';

		// If Pro is not active, the test should fail with a specific message.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			try {
				$this->_handleAjax( 'wp_mcp_ai_test_provider' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected exception.
			}

			$response = json_decode( $this->_last_response, true );
			$this->assertFalse( $response['success'], 'Request should fail when Pro is not active' );
			$this->assertStringContainsString(
				'only available in the Pro version',
				$response['data']['message'],
				'Error message should mention Pro version requirement'
			);
		} else {
			// If Pro is active, the test should proceed (may fail due to other reasons).
			try {
				$this->_handleAjax( 'wp_mcp_ai_test_provider' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected exception.
			}

			$response = json_decode( $this->_last_response, true );

			// The test may succeed or fail, but it should NOT fail with "Pro version required".
			if ( ! $response['success'] ) {
				$this->assertStringNotContainsString(
					'only available in the Pro version',
					$response['data']['message'],
					'Error message should NOT mention Pro version requirement when Pro is active'
				);
			}
		}

		// Clean up.
		wp_set_current_user( 0 );
	}

	/**
	 * Test that the correct constant is used for detection.
	 *
	 * This is a regression test to ensure we're using WP_MCP_AI_PRO_VERSION
	 * and not WP_MCP_AI_BASE_VERSION for embedded provider detection.
	 */
	public function test_diagnostic_uses_correct_pro_detection_constant() {
		// Read the diagnostics file.
		$diagnostics_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		$file_content     = file_get_contents( $diagnostics_file );

		// Search for the embedded LLM section visibility check.
		$pattern = '/Embedded LLM.*?if\s*\(\s*defined\s*\(\s*[\'"]WP_MCP_AI_PRO_VERSION[\'"]\s*\)\s*\)/s';
		$this->assertMatchesRegularExpression(
			$pattern,
			$file_content,
			'Diagnostics page should use WP_MCP_AI_PRO_VERSION for embedded provider visibility check'
		);

		// Verify that the old BASE_VERSION pattern is NOT used for embedded visibility.
		// Note: BASE_VERSION may still be used elsewhere in the file, so we check the specific section.
		$embedded_section_pattern = '/Embedded LLM.*?endif;/s';
		preg_match( $embedded_section_pattern, $file_content, $matches );
		
		if ( ! empty( $matches[0] ) ) {
			$embedded_section = $matches[0];
			
			// The embedded section should NOT use the old base version check pattern.
			$this->assertStringNotContainsString(
				'! defined( \'WP_MCP_AI_BASE_VERSION\' ) || ! WP_MCP_AI_BASE_VERSION',
				$embedded_section,
				'Embedded section should NOT use the old base version check pattern'
			);
		}
	}

	/**
	 * Test that test_embedded method uses correct Pro detection.
	 *
	 * This test verifies the test_embedded private method uses WP_MCP_AI_PRO_VERSION.
	 */
	public function test_embedded_test_method_uses_pro_version_check() {
		// Read the diagnostics file.
		$diagnostics_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-provider-diagnostics.php';
		$file_content     = file_get_contents( $diagnostics_file );

		// Search for the test_embedded method and its Pro version check.
		$pattern = '/function\s+test_embedded.*?if\s*\(\s*!\s*defined\s*\(\s*[\'"]WP_MCP_AI_PRO_VERSION[\'"]\s*\)\s*\)/s';
		$this->assertMatchesRegularExpression(
			$pattern,
			$file_content,
			'test_embedded method should check for ! defined( WP_MCP_AI_PRO_VERSION )'
		);

		// Verify that the old BASE_VERSION pattern is NOT used in test_embedded.
		$test_embedded_pattern = '/function\s+test_embedded.*?(?=function\s+\w+|\z)/s';
		preg_match( $test_embedded_pattern, $file_content, $matches );
		
		if ( ! empty( $matches[0] ) ) {
			$test_embedded_method = $matches[0];
			
			// The test_embedded method should NOT use the old base version check.
			$this->assertStringNotContainsString(
				'defined( \'WP_MCP_AI_BASE_VERSION\' ) && WP_MCP_AI_BASE_VERSION',
				$test_embedded_method,
				'test_embedded method should NOT use the old base version check pattern'
			);
		}
	}
}
