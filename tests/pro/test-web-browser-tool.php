<?php
/**
 * Tests for Web Browser Pro Tool.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Web Browser tool.
 */
class Test_Web_Browser_Tool extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the Pro addon if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$pro_file = dirname( dirname( __DIR__ ) ) . '/addons/pro/mcp-ai-wpoos-pro.php';
			if ( file_exists( $pro_file ) ) {
				require_once $pro_file;
			}
		}
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool = new WP_MCP_AI_Tool_Web_Browser();

		$this->assertEquals( 'web_browser', $tool->get_slug() );
		$this->assertEquals( 'Web Browser Automation', $tool->get_name() );
		$this->assertStringContainsString( 'automate web browsers', strtolower( $tool->get_description() ) );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool   = new WP_MCP_AI_Tool_Web_Browser();
		$schema = $tool->get_parameters_schema();

		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'selector', $schema['properties'] );
		$this->assertArrayHasKey( 'timeout', $schema['properties'] );
		$this->assertContains( 'url', $schema['required'] );
		$this->assertContains( 'action', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool  = new WP_MCP_AI_Tool_Web_Browser();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'rate-limited', $flags );
		$this->assertContains( 'may-timeout', $flags );
		$this->assertContains( 'pro-only', $flags );
		$this->assertContains( 'resource-intensive', $flags );
	}

	/**
	 * Test tool availability check.
	 */
	public function test_is_available() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		// By default, local fallback should be enabled, so tool should be available.
		$this->assertTrue( WP_MCP_AI_Tool_Web_Browser::is_available() );
	}

	/**
	 * Test permission check without proper capability.
	 */
	public function test_execute_without_permission() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool = new WP_MCP_AI_Tool_Web_Browser();

		// Create user without manage_options capability.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$result = $tool->execute(
			array(
				'url'    => 'https://example.com',
				'action' => 'navigate',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test URL validation - missing URL.
	 */
	public function test_execute_missing_url() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool = new WP_MCP_AI_Tool_Web_Browser();

		// Create admin user.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$result = $tool->execute(
			array(
				'action' => 'navigate',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_url', $result->get_error_code() );
	}

	/**
	 * Test internal URL blocking.
	 */
	public function test_execute_blocks_internal_urls() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool = new WP_MCP_AI_Tool_Web_Browser();

		// Create admin user.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$internal_urls = array(
			'http://localhost/',
			'http://127.0.0.1/',
			'http://192.168.1.1/',
			'http://10.0.0.1/',
		);

		foreach ( $internal_urls as $url ) {
			$result = $tool->execute(
				array(
					'url'    => $url,
					'action' => 'navigate',
				),
				array(
					'user_id' => $user_id,
				)
			);

			$this->assertWPError( $result, "Failed to block internal URL: $url" );
			$this->assertEquals( 'wp_mcp_ai_forbidden_url', $result->get_error_code() );
		}
	}

	/**
	 * Test unsupported action in local fallback.
	 */
	public function test_local_fallback_unsupported_action() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Browser' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded' );
		}

		$tool = new WP_MCP_AI_Tool_Web_Browser();

		// Create admin user.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Ensure no Playwright service is configured.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'playwright_service_url' => '',
			)
		);

		// Try screenshot action (not supported in fallback).
		$result = $tool->execute(
			array(
				'url'    => 'https://example.com',
				'action' => 'screenshot',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_unsupported_action', $result->get_error_code() );
	}
}
