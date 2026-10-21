<?php
/**
 * Tests the single-assistant GET/PUT REST bridge (2.4.0, Proposal 084
 * console-contract parity): GET /mcp-ai/v1/assistants/{id} (full record
 * incl. system prompt) + PUT /mcp-ai/v1/assistants/{id} (console edit).
 *
 * Covers: the admin capability gate, the { success, data } envelope with
 * the system prompt, the update field round-trip (title, tools, system
 * prompt, clearing on empty), and the unknown-assistant 403. Skipped when
 * the REST class is not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Assistant_Crud_Test extends WP_UnitTestCase {

	/**
	 * REST controller under test.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $controller;

	/**
	 * Boot a controller and skip when the class is absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			$this->markTestSkipped( 'REST controller not loaded.' );
		}

		$this->controller = new WP_MCP_AI_REST(
			WP_MCP_AI_Tool_Registry::get_instance(),
			$this->createMock( WP_MCP_AI_Language_Model_Router::class )
		);
	}

	/**
	 * Build a request carrying a valid REST cookie nonce.
	 *
	 * @param string $method HTTP method.
	 * @return WP_REST_Request
	 */
	private function nonce_request( $method = 'GET' ) {
		$request = new WP_REST_Request( $method, '/mcp-ai/v1/assistants/0' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		return $request;
	}

	/**
	 * Create a published assistant post with a stored system prompt + tools.
	 *
	 * @return int Assistant post ID.
	 */
	private function create_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Console Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'You are a test assistant.' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'get_site_health', 'web_search' ) );

		return $assistant_id;
	}

	/**
	 * Non-admins are refused by the get gate even with a valid nonce.
	 */
	public function test_non_admin_get_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $this->controller->permissions_check_assistant_get( $this->nonce_request() );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Admins get the full record envelope including the system prompt.
	 */
	public function test_admin_get_returns_full_record() {
		$assistant_id = $this->create_assistant();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request();
		$request->set_param( 'id', $assistant_id );

		$result = $this->controller->handle_assistant_get( $request );

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $assistant_id, $data['data']['id'] );
		$this->assertSame( 'Console Assistant', $data['data']['title'] );
		$this->assertSame( 'You are a test assistant.', $data['data']['system_prompt'] );
		$this->assertSame( array( 'get_site_health', 'web_search' ), $data['data']['tools'] );
	}

	/**
	 * The update handler applies title, tools and system prompt and returns
	 * the refreshed summary.
	 */
	public function test_admin_update_applies_changes() {
		$assistant_id = $this->create_assistant();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param( 'title', 'Renamed Assistant' );
		$request->set_param( 'system_prompt', 'Updated prompt.' );
		$request->set_param( 'tools', array( 'get_site_health' ) );

		$result = $this->controller->handle_assistant_update( $request );

		$this->assertNotWPError( $result );
		$data = $result->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Renamed Assistant', get_the_title( $assistant_id ) );
		$this->assertSame( 'Renamed Assistant', $data['data']['title'] );
		$this->assertSame( 'Updated prompt.', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true ) );
		$this->assertSame( array( 'get_site_health' ), get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true ) );
	}

	/**
	 * An empty system prompt clears the stored value (the console edit form
	 * can blank it).
	 */
	public function test_update_clears_system_prompt_on_empty() {
		$assistant_id = $this->create_assistant();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param( 'system_prompt', '' );

		$result = $this->controller->handle_assistant_update( $request );

		$this->assertNotWPError( $result );
		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, true ) );
	}

	/**
	 * Unknown assistant IDs are refused.
	 */
	public function test_unknown_assistant_is_refused() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request();
		$request->set_param( 'id', 999999 );

		$result = $this->controller->handle_assistant_get( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_assistant_forbidden', $result->get_error_code() );
	}
}
