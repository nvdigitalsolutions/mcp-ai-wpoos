<?php
/**
 * Tests for WP_MCP_AI_Destructive_Ops_Gate — confirmation gate.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Destructive operations gate test suite.
 *
 * @group security
 * @group destructive-ops
 */
class WP_MCP_AI_Destructive_Ops_Gate_Tests extends WP_UnitTestCase {

	/**
	 * Test that is_destructive returns true for write-flag tools.
	 */
	public function test_detects_write_flag_as_destructive() {
		// Simulate a tool with write flag by using reflection on the private method.
		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_tool_destructive' );
		$method->setAccessible( true );

		// Create a mock tool that implements the capability flags interface.
		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Capability_Flags_Interface' )
			->disableOriginalConstructor()
			->getMock();

		$tool->method( 'get_capability_flags' )
			->willReturn( array( 'write', 'state-changing' ) );

		$result = $method->invoke( null, $tool );
		$this->assertTrue( $result, 'Tool with write flag should be detected as destructive.' );
	}

	/**
	 * Test that is_destructive returns false for read-only tools.
	 */
	public function test_read_only_tool_not_destructive() {
		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_tool_destructive' );
		$method->setAccessible( true );

		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Capability_Flags_Interface' )
			->disableOriginalConstructor()
			->getMock();

		$tool->method( 'get_capability_flags' )
			->willReturn( array( 'read-only', 'cacheable' ) );

		$result = $method->invoke( null, $tool );
		$this->assertFalse( $result, 'Read-only tool should not be destructive.' );
	}

	/**
	 * Test that is_confirmed accepts various truthy values.
	 */
	public function test_is_confirmed_accepts_truthy_values() {
		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_confirmed' );
		$method->setAccessible( true );

		$truthy = array(
			array( 'confirm_destructive' => true ),
			array( 'confirm_destructive' => 'true' ),
			array( 'confirm_destructive' => 'yes' ),
			array( 'confirm_destructive' => 1 ),
			array( 'confirm_destructive' => '1' ),
		);

		foreach ( $truthy as $args ) {
			$this->assertTrue(
				$method->invoke( null, $args ),
				'Should accept: ' . wp_json_encode( $args )
			);
		}
	}

	/**
	 * Test that is_confirmed rejects when confirmation is missing.
	 */
	public function test_is_confirmed_rejects_missing_confirmation() {
		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_confirmed' );
		$method->setAccessible( true );

		$falsy = array(
			array(),
			array( 'other_param' => 'value' ),
			array( 'confirm_destructive' => false ),
			array( 'confirm_destructive' => 'false' ),
			array( 'confirm_destructive' => 0 ),
		);

		foreach ( $falsy as $args ) {
			$this->assertFalse(
				$method->invoke( null, $args ),
				'Should reject: ' . wp_json_encode( $args )
			);
		}
	}

	/**
	 * Test that the destructive confirmation flags filter is applied.
	 */
	public function test_destructive_flags_are_filterable() {
		$custom_flags = array( 'custom-dangerous-flag' );

		add_filter( 'wp_mcp_ai_destructive_confirmation_flags', function() use ( $custom_flags ) {
			return $custom_flags;
		} );

		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_tool_destructive' );
		$method->setAccessible( true );

		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Capability_Flags_Interface' )
			->disableOriginalConstructor()
			->getMock();

		$tool->method( 'get_capability_flags' )
			->willReturn( array( 'custom-dangerous-flag' ) );

		$result = $method->invoke( null, $tool );
		$this->assertTrue( $result, 'Custom dangerous flag should be detected via filter.' );

		remove_all_filters( 'wp_mcp_ai_destructive_confirmation_flags' );
	}

	/**
	 * Test that is_enabled reads the admin setting correctly.
	 */
	public function test_is_enabled_respects_setting() {
		// Set the setting to false.
		if ( function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			wp_mcp_ai_get_settings_repository()->update( 'require_confirm_destructive_ops', false );
		}

		$reflector = new ReflectionClass( 'WP_MCP_AI_Destructive_Ops_Gate' );
		$method    = $reflector->getMethod( 'is_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( null );
		$this->assertFalse( $result, 'Gate should be disabled when setting is false.' );

		// Reset.
		if ( function_exists( 'wp_mcp_ai_get_settings_repository' ) ) {
			wp_mcp_ai_get_settings_repository()->update( 'require_confirm_destructive_ops', true );
		}
	}
}
