<?php
/**
 * AJAX handler tests for the assistant editor's model-assisted Auto-Select.
 *
 * Covers `wp_ajax_wp_mcp_ai_auto_select_tools`:
 * - the handler is wired to the helper callback;
 * - a missing nonce is rejected;
 * - a low-capability caller is rejected;
 * - a valid admin request returns the documented JSON shape.
 *
 * The model-assisted branch is covered separately in
 * tests/test-tool-presets-auto-select.php; these tests force the deterministic
 * keyword path so they never depend on provider credentials.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for the Auto-Select AJAX endpoint.
 */
class Test_Tool_Presets_Auto_Select_Ajax extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action used by the handler.
	 */
	const NONCE_ACTION = 'wp_mcp_ai_auto_select_tools';

	/**
	 * Bind the handler and force the deterministic selection path.
	 */
	public function setUp(): void {
		parent::setUp();

		// The handler is registered at file load time only under is_admin(),
		// which is false in the CLI test runner. Bind it the same way the
		// production registration block does so dispatch() has a callback.
		if ( ! has_action( 'wp_ajax_wp_mcp_ai_auto_select_tools' ) ) {
			add_action( 'wp_ajax_wp_mcp_ai_auto_select_tools', array( 'WP_MCP_AI_Tool_Presets_Helper', 'handle_auto_select_ajax' ) );
		}

		// Force the keyword path: an empty model config means no provider call.
		add_filter( 'wp_mcp_ai_auto_select_model_config', '__return_empty_array' );
	}

	/**
	 * Remove the filters added in setUp().
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_auto_select_model_config', '__return_empty_array' );

		parent::tearDown();
	}

	/**
	 * The handler is wired to the helper callback.
	 */
	public function test_handler_is_registered() {
		$this->assertNotFalse(
			has_action( 'wp_ajax_wp_mcp_ai_auto_select_tools', array( 'WP_MCP_AI_Tool_Presets_Helper', 'handle_auto_select_ajax' ) ),
			'The wp_mcp_ai_auto_select_tools action should be wired to the helper callback.'
		);
	}

	/**
	 * A request without a nonce is rejected.
	 */
	public function test_rejects_missing_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_auto_select_tools',
			array( 'system_prompt' => 'Search the web.' )
		);

		$this->assertAjaxForbidden( $response );
	}

	/**
	 * A caller without the assistant edit capability is rejected.
	 */
	public function test_rejects_subscriber() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_auto_select_tools',
			array(
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'system_prompt'   => 'Search the web.',
				'available_tools' => array( 'web_search' ),
			)
		);

		$this->assertAjaxError( $response );
	}

	/**
	 * A valid admin request returns the documented selection shape.
	 */
	public function test_keyword_path_returns_selection_shape() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_auto_select_tools',
			array(
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'system_prompt'   => 'Search the web and create blog posts.',
				'available_tools' => array( 'web_search', 'create_post' ),
			)
		);

		$this->assertAjaxSuccess( $response );

		$data = $this->getResponseData( $response );

		$this->assertArrayHasKey( 'tools', $data );
		$this->assertArrayHasKey( 'reason', $data );
		$this->assertArrayHasKey( 'method', $data );
		$this->assertIsArray( $data['tools'] );
		$this->assertSame( 'keyword', $data['method'] );
	}

	/**
	 * Input validation note.
	 *
	 * Every request field is optional and sanitised (absint/sanitize_key/
	 * sanitize_textarea_field), so malformed values are ignored rather than
	 * rejected; there is no missing-field error branch to assert here. The
	 * per-assistant post permission branch is exercised by the capability test.
	 */
}
