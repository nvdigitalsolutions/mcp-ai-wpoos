<?php
/**
 * Tests for WP_MCP_AI_Tool_Service.
 *
 * Covers tool existence checks, capability gating, argument validation,
 * payload building, and available-tool listing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Tool_Service.
 */
class Test_Service_Tool extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Tool_Service
	 */
	private $service;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->service  = new WP_MCP_AI_Tool_Service( $this->registry );

		// Ensure an admin user is logged in for capability checks.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$this->service  = null;
		$this->registry = null;
		parent::tearDown();
	}

	/**
	 * Test that execute_tool returns WP_Error for an unregistered tool.
	 */
	public function test_execute_tool_returns_error_for_unregistered_tool() {
		$result = $this->service->execute_tool(
			'definitely_not_a_real_tool_xyz',
			array(),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}

	/**
	 * Test that validate_tool_arguments returns WP_Error for unknown tool.
	 */
	public function test_validate_tool_arguments_returns_error_for_unknown_tool() {
		$result = $this->service->validate_tool_arguments(
			'definitely_not_a_real_tool_xyz',
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_tool_not_found', $result->get_error_code() );
	}

	/**
	 * Test that build_tools_payload returns empty array when no tools are configured.
	 */
	public function test_build_tools_payload_returns_empty_for_no_tools_configured() {
		$result = $this->service->build_tools_payload( array( 'tools' => array() ) );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that build_tools_payload returns empty array when tools key is absent.
	 */
	public function test_build_tools_payload_returns_empty_for_missing_tools_key() {
		$result = $this->service->build_tools_payload( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_available_tools returns an array.
	 */
	public function test_get_available_tools_returns_array() {
		$result = $this->service->get_available_tools();
		$this->assertIsArray( $result );
	}

	/**
	 * Test that get_tool_statistics returns array with expected keys.
	 */
	public function test_get_tool_statistics_returns_expected_keys() {
		$stats = $this->service->get_tool_statistics( 'any_tool' );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'tool', $stats );
		$this->assertArrayHasKey( 'execution_count', $stats );
		$this->assertArrayHasKey( 'success_count', $stats );
		$this->assertArrayHasKey( 'error_count', $stats );
	}

	/**
	 * Test that is_tool_enabled_for_assistant returns false for zero assistant ID.
	 */
	public function test_is_tool_enabled_for_assistant_returns_false_for_zero_id() {
		$result = $this->service->is_tool_enabled_for_assistant( 'any_tool', 0 );
		$this->assertFalse( $result );
	}

	/**
	 * Test that execute_tool checks user capability and denies subscriber access.
	 *
	 * When the tool capability requirement is 'manage_options', a subscriber
	 * should receive a permission-denied WP_Error.
	 */
	public function test_execute_tool_denies_subscriber_for_capability_gated_tool() {
		// Skip if no tools are registered at all.
		$tools = $this->registry->get_tools();
		if ( empty( $tools ) ) {
			$this->markTestSkipped( 'No tools registered — cannot test capability gating.' );
		}

		// Find a tool that requires manage_options.
		$gated_tool = null;
		foreach ( $tools as $tool ) {
			if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
				continue;
			}
			$slug = $tool->get_slug();
			$cap  = $this->registry->get_tool_capability( $slug );
			if ( $cap && ! empty( $cap ) ) {
				$gated_tool = $slug;
				break;
			}
		}

		if ( null === $gated_tool ) {
			$this->markTestSkipped( 'No capability-gated tools found.' );
		}

		// Switch to subscriber (no manage_options).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $this->service->execute_tool( $gated_tool, array(), array() );

		$this->assertWPError( $result );
	}
}
