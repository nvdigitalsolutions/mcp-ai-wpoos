<?php
/**
 * Tests for the Eval Suite Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Eval Suite Registry.
 */
class Test_WP_MCP_AI_Eval_Suite_Registry extends WP_UnitTestCase {

	/**
	 * Reset the suite registry.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Eval_Suite_Registry::reset_instance();
	}

	/**
	 * Reset the registry and drop the registration hook.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		remove_all_actions( 'wp_mcp_ai_register_eval_suites' );
		parent::tearDown();
	}

	/**
	 * A suite can be registered as an array or as an instance.
	 */
	public function test_register_via_array_or_instance() {
		$reg = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$a   = $reg->register( array( 'slug' => 'a' ) );
		$b   = $reg->register( new WP_MCP_AI_Eval_Suite( array( 'slug' => 'b' ) ) );
		$this->assertInstanceOf( 'WP_MCP_AI_Eval_Suite', $a );
		$this->assertInstanceOf( 'WP_MCP_AI_Eval_Suite', $b );
		$this->assertCount( 2, $reg->all() );
	}

	/**
	 * Registering without a slug returns WP_Error.
	 */
	public function test_register_missing_slug_returns_error() {
		$reg = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$res = $reg->register( array() );
		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertCount( 0, $reg->all() );
	}

	/**
	 * Boot fires the registration hook exactly once.
	 */
	public function test_boot_fires_registration_hook_once() {
		$counter = 0;
		add_action(
			'wp_mcp_ai_register_eval_suites',
			static function () use ( &$counter ) {
				++$counter;
			}
		);
		$reg = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$reg->boot();
		$reg->boot();
		$this->assertSame( 1, $counter );
	}

	/**
	 * Unregister removes a suite and reports whether it existed.
	 */
	public function test_unregister() {
		$reg = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$reg->register( array( 'slug' => 'a' ) );
		$this->assertTrue( $reg->unregister( 'a' ) );
		$this->assertFalse( $reg->unregister( 'a' ) );
		$this->assertNull( $reg->get( 'a' ) );
	}
}
