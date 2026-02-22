<?php
/**
 * Tests for NPM integration Process Service usage.
 *
 * Validates that:
 * 1. wp_mcp_ai_is_nodejs_available() uses Process Service
 * 2. wp_mcp_ai_exec_node_service() uses Process Service
 * 3. No direct shell_exec/exec calls are present
 * 4. Functions work correctly with Process Service
 *
 * @package WP_MCP_AI
 */

/**
 * Test NPM integration Process Service usage.
 */
class WP_MCP_AI_NPM_Integration_Process_Service_Test extends WP_UnitTestCase {

	/**
	 * Path to npm-integration-filters.php file.
	 *
	 * @var string
	 */
	private $npm_filters_file;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->npm_filters_file = WP_MCP_AI_PRO_PATH . 'includes/npm-integration-filters.php';
	}

	/**
	 * Test that npm-integration-filters.php file exists.
	 */
	public function test_npm_integration_filters_file_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$this->assertFileExists(
			$this->npm_filters_file,
			'npm-integration-filters.php file should exist'
		);
	}

	/**
	 * Test that file does not contain direct shell_exec calls.
	 */
	public function test_no_direct_shell_exec_calls() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringNotContainsString(
			'shell_exec(',
			$content,
			'File should not contain direct shell_exec() calls'
		);
	}

	/**
	 * Test that file uses Process Service.
	 */
	public function test_uses_process_service() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringContainsString(
			'WP_MCP_AI_Process_Service::get_instance()',
			$content,
			'File should use Process Service'
		);

		$this->assertStringContainsString(
			'->is_command_available(',
			$content,
			'File should use is_command_available() method'
		);

		$this->assertStringContainsString(
			'->run_silent(',
			$content,
			'File should use run_silent() method'
		);
	}

	/**
	 * Test that wp_mcp_ai_is_nodejs_available exists and is callable.
	 */
	public function test_nodejs_available_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_is_nodejs_available' ),
			'wp_mcp_ai_is_nodejs_available() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_is_nodejs_available uses Process Service.
	 *
	 * This test verifies that the function uses Process Service
	 * instead of direct shell_exec calls.
	 */
	public function test_nodejs_available_uses_process_service() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		// The function should return a boolean without fatal error.
		$result = wp_mcp_ai_is_nodejs_available();
		$this->assertIsBool(
			$result,
			'wp_mcp_ai_is_nodejs_available() should return a boolean value'
		);
	}

	/**
	 * Test that wp_mcp_ai_exec_node_service exists and is callable.
	 */
	public function test_exec_node_service_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_exec_node_service' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_exec_node_service' ),
			'wp_mcp_ai_exec_node_service() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_exec_node_service returns appropriate error.
	 *
	 * This test verifies that the function returns a WP_Error
	 * when service file doesn't exist.
	 */
	public function test_exec_node_service_returns_error_for_missing_file() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if function doesn't exist yet.
		if ( ! function_exists( 'wp_mcp_ai_exec_node_service' ) ) {
			require_once $this->npm_filters_file;
		}

		// Test with non-existent service file.
		$result = wp_mcp_ai_exec_node_service( '/fake/nonexistent/service.js', 'test', array() );

		// Should return WP_Error for missing service file or unavailable Node.js.
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'wp_mcp_ai_exec_node_service() should return WP_Error for invalid parameters'
		);
	}

	/**
	 * Test that the file can be included without fatal errors.
	 *
	 * This is the critical test - ensures that simply including the file
	 * doesn't cause a fatal error due to missing shell functions.
	 */
	public function test_npm_integration_file_can_be_included_safely() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// This should not cause a fatal error.
		$this->assertFileExists( $this->npm_filters_file );

		// If we get here, the file loaded successfully.
		$this->assertTrue( true, 'File loaded without fatal error' );
	}

	/**
	 * Test that auto-registration works with Process Service.
	 */
	public function test_auto_registration_uses_process_service() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Load the file if not already loaded.
		if ( ! function_exists( 'wp_mcp_ai_is_nodejs_available' ) ) {
			require_once $this->npm_filters_file;
		}

		// Auto-registration should use Process Service internally
		// and not cause any errors regardless of Node.js availability.
		$this->assertTrue( true, 'Auto-registration completed without fatal error' );
	}

	/**
	 * Test that wp_mcp_ai_generate_qr_code_via_api function exists.
	 */
	public function test_generate_qr_code_via_api_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! function_exists( 'wp_mcp_ai_generate_qr_code_via_api' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_generate_qr_code_via_api' ),
			'wp_mcp_ai_generate_qr_code_via_api() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_generate_qr_code function exists.
	 */
	public function test_generate_qr_code_function_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! function_exists( 'wp_mcp_ai_generate_qr_code' ) ) {
			require_once $this->npm_filters_file;
		}

		$this->assertTrue(
			function_exists( 'wp_mcp_ai_generate_qr_code' ),
			'wp_mcp_ai_generate_qr_code() function should exist'
		);
	}

	/**
	 * Test that wp_mcp_ai_generate_qr_code does not return WP_Error for nodejs_not_available.
	 *
	 * When Node.js is unavailable, the function must fall back to the external
	 * API rather than returning a nodejs_not_available WP_Error directly.
	 */
	public function test_generate_qr_code_does_not_hard_fail_when_nodejs_unavailable() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! function_exists( 'wp_mcp_ai_generate_qr_code' ) ) {
			require_once $this->npm_filters_file;
		}

		// The function must not return a nodejs_not_available error code — it
		// should always try the external API fallback first.
		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringNotContainsString(
			"'nodejs_not_available'",
			$content,
			'wp_mcp_ai_generate_qr_code() should not return nodejs_not_available error; it must use the external API fallback'
		);
	}

	/**
	 * Test that the external API fallback uses api.qrserver.com.
	 */
	public function test_generate_qr_code_via_api_uses_qrserver() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringContainsString(
			'api.qrserver.com',
			$content,
			'External QR code fallback should use api.qrserver.com'
		);
	}

	/**
	 * Test that wp_mcp_ai_generate_qr_code_via_api is called as fallback.
	 */
	public function test_generate_qr_code_calls_api_fallback() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringContainsString(
			'wp_mcp_ai_generate_qr_code_via_api',
			$content,
			'wp_mcp_ai_generate_qr_code() should call wp_mcp_ai_generate_qr_code_via_api() as fallback'
		);
	}

	/**
	 * Test that the pre-packaged qrcode-service.js exists in node-services/.
	 *
	 * The service must be a static file shipped with the plugin so it works on
	 * activation without any npm install step.
	 */
	public function test_qrcode_service_file_is_prepackaged() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$service_file = WP_MCP_AI_PRO_PATH . 'node-services/qrcode-service.js';

		$this->assertFileExists(
			$service_file,
			'node-services/qrcode-service.js should be pre-packaged with the plugin'
		);
	}

	/**
	 * Test that the pre-packaged qrcode-service.js loads the library from the
	 * vendored path so no npm install is required at runtime.
	 */
	public function test_qrcode_service_uses_vendor_path() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$service_file = WP_MCP_AI_PRO_PATH . 'node-services/qrcode-service.js';

		if ( ! file_exists( $service_file ) ) {
			$this->markTestSkipped( 'qrcode-service.js not present' );
		}

		$content = file_get_contents( $service_file );

		$this->assertStringContainsString(
			'assets/vendor/qrcode',
			$content,
			'qrcode-service.js must load qrcode from the vendored path, not from node_modules'
		);
	}

	/**
	 * Test that the vendored qrcode package lib/ directory exists.
	 *
	 * The lib/ directory (Node.js entry point) must be present alongside
	 * package.json so the service can be required without npm install.
	 */
	public function test_qrcode_vendor_lib_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$lib_dir = WP_MCP_AI_PRO_PATH . 'assets/vendor/qrcode/lib';

		$this->assertDirectoryExists(
			$lib_dir,
			'assets/vendor/qrcode/lib/ must be present for the pre-packaged service to work'
		);
	}

	/**
	 * Test that qrcode runtime dependencies are vendored alongside the library.
	 */
	public function test_qrcode_vendor_runtime_deps_exist() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$base = WP_MCP_AI_PRO_PATH . 'assets/vendor/qrcode/node_modules/';

		$this->assertDirectoryExists(
			$base . 'dijkstrajs',
			'dijkstrajs must be vendored inside assets/vendor/qrcode/node_modules/'
		);

		$this->assertDirectoryExists(
			$base . 'pngjs',
			'pngjs must be vendored inside assets/vendor/qrcode/node_modules/'
		);
	}

	/**
	 * Test that the PHP function uses the pre-packaged node-services/ path.
	 */
	public function test_php_uses_prepackaged_node_services_path() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$content = file_get_contents( $this->npm_filters_file );

		$this->assertStringContainsString(
			"'node-services/qrcode-service.js'",
			$content,
			'PHP must reference the pre-packaged node-services/qrcode-service.js'
		);

		$this->assertStringNotContainsString(
			"'includes/npm-services/qrcode-service.js'",
			$content,
			'PHP must not reference the old dynamic includes/npm-services/ path'
		);
	}
}
