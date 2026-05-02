<?php
/**
 * Markup subsystem test.
 *
 * Markup loop-interceptor integration test.
 *
 * Defines a tiny in-test markup-aware tool, registers it with the
 * registry, fires the `wp_mcp_ai_pre_execute_tool` filter, and asserts
 * the interceptor short-circuits the result with a markup_elicitation
 * payload.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/class-test-markup-aware-tool.php';


/**
 * Test case for Test_Markup_Loop_Interceptor.
 *
 * @group markup
 */
class Test_Markup_Loop_Interceptor extends WP_UnitTestCase {

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Markup_Store::INDEX_OPTION );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_filter_short_circuits_with_widget_payload() {
		$tool        = new Test_Markup_Aware_Tool();
		$interceptor = new WP_MCP_AI_Markup_Loop_Interceptor();
		$interceptor->register();

		$payload = apply_filters(
			'wp_mcp_ai_pre_execute_tool',
			null,
			$tool,
			array( 'prompt' => 'Replace logo' ),
			array( 'assistant_id' => 5 )
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'markup_elicitation', $payload['type'] );
		$this->assertSame( 'test_markup_aware_tool', $payload['tool'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_filter_returns_null_when_tool_skips() {
		$tool        = new Test_Markup_Aware_Tool();
		$interceptor = new WP_MCP_AI_Markup_Loop_Interceptor();
		$interceptor->register();

		$payload = apply_filters(
			'wp_mcp_ai_pre_execute_tool',
			null,
			$tool,
			array( 'skip_markup' => true ),
			array()
		);

		$this->assertNull( $payload );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_disabled_setting_skips_interception() {
		update_option( 'wp_mcp_ai_settings', array( 'markup_enabled' => false ) );

		$tool        = new Test_Markup_Aware_Tool();
		$interceptor = new WP_MCP_AI_Markup_Loop_Interceptor();
		$interceptor->register();

		$payload = apply_filters(
			'wp_mcp_ai_pre_execute_tool',
			null,
			$tool,
			array(),
			array()
		);

		$this->assertNull( $payload );
	}
}
