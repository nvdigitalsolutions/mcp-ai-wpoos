<?php
/**
 * Tests for Capture Webpage Screenshot Pro Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Capture Webpage Screenshot tool.
 */
class Test_Capture_Webpage_Screenshot_Tool extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class directly if Pro addon is not loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Capture_Webpage_Screenshot' ) ) {
			$tool_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/includes/tools/capture/class-wp-mcp-ai-tool-capture-webpage-screenshot.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
		}
	}

	/**
	 * Skip test when tool class is not available.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Capture_Webpage_Screenshot' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Capture_Webpage_Screenshot class not available' );
		}
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->maybe_skip();

		$tool = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();

		$this->assertEquals( 'capture_webpage_screenshot', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameters schema structure.
	 */
	public function test_parameters_schema() {
		$this->maybe_skip();

		$tool   = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$schema = $tool->get_parameters_schema();

		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'viewport', $schema['properties'] );
		$this->assertArrayHasKey( 'full_page', $schema['properties'] );
		$this->assertArrayHasKey( 'format', $schema['properties'] );
		$this->assertArrayHasKey( 'quality', $schema['properties'] );
		$this->assertArrayHasKey( 'save_to_media', $schema['properties'] );
		$this->assertArrayHasKey( 'timeout', $schema['properties'] );
		$this->assertContains( 'url', $schema['required'] );
	}

	/**
	 * Test viewport enum values in schema.
	 */
	public function test_viewport_enum_values() {
		$this->maybe_skip();

		$tool   = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$schema = $tool->get_parameters_schema();

		$viewport_enum = $schema['properties']['viewport']['enum'];
		$this->assertContains( 'desktop', $viewport_enum );
		$this->assertContains( 'laptop', $viewport_enum );
		$this->assertContains( 'tablet', $viewport_enum );
		$this->assertContains( 'mobile_portrait', $viewport_enum );
		$this->assertContains( 'mobile_landscape', $viewport_enum );
		$this->assertContains( 'custom', $viewport_enum );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$this->maybe_skip();

		$tool  = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'rate-limited', $flags );
		$this->assertContains( 'may-timeout', $flags );
		$this->assertContains( 'pro-only', $flags );
	}

	/**
	 * Test is_available() returns true (tool is always available).
	 */
	public function test_is_always_available() {
		$this->maybe_skip();

		$this->assertTrue( WP_MCP_AI_Tool_Capture_Webpage_Screenshot::is_available() );
	}

	/**
	 * Test execution is blocked for non-admin users.
	 */
	public function test_execute_blocked_for_non_admin() {
		$this->maybe_skip();

		$tool    = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $tool->execute(
			array( 'url' => 'https://example.com' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test validation rejects empty URL.
	 */
	public function test_execute_rejects_empty_url() {
		$this->maybe_skip();

		$tool    = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array( 'url' => '' ),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test internal URL blocking (SSRF prevention).
	 */
	public function test_execute_blocks_internal_urls() {
		$this->maybe_skip();

		$tool    = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$internal_urls = array(
			'http://localhost/',
			'http://127.0.0.1/',
			'http://192.168.1.1/',
			'http://10.0.0.1/',
			'http://172.16.0.1/',
		);

		foreach ( $internal_urls as $url ) {
			$result = $tool->execute(
				array( 'url' => $url ),
				array( 'user_id' => $user_id )
			);

			$this->assertWPError( $result, "Should block internal URL: {$url}" );
			$this->assertEquals( 'wp_mcp_ai_forbidden_url', $result->get_error_code(), "Wrong error code for: {$url}" );
		}
	}

	/**
	 * Test viewport resolution for each preset.
	 */
	public function test_viewport_presets_resolve_correctly() {
		$this->maybe_skip();

		$viewports = WP_MCP_AI_Tool_Capture_Webpage_Screenshot::VIEWPORTS;

		$this->assertArrayHasKey( 'desktop', $viewports );
		$this->assertArrayHasKey( 'laptop', $viewports );
		$this->assertArrayHasKey( 'tablet', $viewports );
		$this->assertArrayHasKey( 'mobile_portrait', $viewports );
		$this->assertArrayHasKey( 'mobile_landscape', $viewports );

		// Confirm known dimensions.
		$this->assertEquals( array( 1920, 1080 ), $viewports['desktop'] );
		$this->assertEquals( array( 375, 667 ), $viewports['mobile_portrait'] );
		$this->assertEquals( array( 667, 375 ), $viewports['mobile_landscape'] );
	}

	/**
	 * Test rate limiting enforces per-user hourly cap.
	 */
	public function test_rate_limiting() {
		$this->maybe_skip();

		$tool    = new WP_MCP_AI_Tool_Capture_Webpage_Screenshot();
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Override rate limit to 1 for testing.
		add_filter(
			'wp_mcp_ai_capture_screenshot_rate_limit',
			function () {
				return 1;
			}
		);

		// Seed the transient so the user is already at limit.
		set_transient( 'wp_mcp_ai_capture_screenshot_' . $user_id, 1, HOUR_IN_SECONDS );

		$result = $tool->execute(
			array( 'url' => 'https://example.com' ),
			array( 'user_id' => $user_id )
		);

		remove_all_filters( 'wp_mcp_ai_capture_screenshot_rate_limit' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_rate_limit_exceeded', $result->get_error_code() );
	}
}
