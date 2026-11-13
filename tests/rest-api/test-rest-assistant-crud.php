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
	 * The full record exposes the advanced object (090-B parity).
	 */
	public function test_admin_get_returns_advanced_fields() {
		$assistant_id = $this->create_assistant();

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_FALLBACK_CHAIN, array( 'openai', 'anthropic' ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, 'vs_test' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROMPT_CACHING, true );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY, 'manage_options' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request();
		$request->set_param( 'id', $assistant_id );

		$result = $this->controller->handle_assistant_get( $request );
		$this->assertNotWPError( $result );

		$data     = $result->get_data();
		$advanced = $data['data']['advanced'];

		$this->assertSame( array( 'openai', 'anthropic' ), $advanced['fallback_chain'] );
		$this->assertSame( 'vs_test', $advanced['vector_store_id'] );
		$this->assertTrue( $advanced['prompt_caching'] );
		$this->assertSame( 'manage_options', $advanced['required_capability'] );

		// Unset keys read as their type default, never null.
		$this->assertSame( array(), $advanced['memory_files'] );
		$this->assertSame( '', $advanced['corpus_name'] );
		$this->assertFalse( $advanced['disable_tool_shortcuts'] );
	}

	/**
	 * The update handler applies the advanced object through the CPT
	 * sanitizers and echoes it back.
	 */
	public function test_admin_update_applies_advanced_fields() {
		$assistant_id = $this->create_assistant();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param(
			'advanced',
			array(
				'fallback_chain'       => array( 'ollama', 'openai' ),
				'vector_store_id'      => 'vs_new',
				'prompt_caching'       => true,
				'adaptive_tool_cap'    => 'off',
				'external_action_type' => 'workflow',
				'required_capability'  => 'edit_posts',
				'unknown_key'          => 'ignored',
			)
		);

		$result = $this->controller->handle_assistant_update( $request );
		$this->assertNotWPError( $result );

		$this->assertSame( array( 'ollama', 'openai' ), get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_FALLBACK_CHAIN, true ) );
		$this->assertSame( 'vs_new', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, true ) );
		$this->assertTrue( (bool) get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROMPT_CACHING, true ) );
		$this->assertSame( 'off', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP, true ) );
		$this->assertSame( 'workflow', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_EXTERNAL_ACTION_TYPE, true ) );
		$this->assertSame( 'edit_posts', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY, true ) );

		$data = $result->get_data();
		$this->assertSame( array( 'ollama', 'openai' ), $data['data']['advanced']['fallback_chain'] );
	}

	/**
	 * Empty advanced values delete the meta row (metabox semantics).
	 */
	public function test_update_clears_advanced_fields_on_empty() {
		$assistant_id = $this->create_assistant();

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_FALLBACK_CHAIN, array( 'openai' ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, 'vs_test' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROMPT_CACHING, true );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param(
			'advanced',
			array(
				'fallback_chain'  => array(),
				'vector_store_id' => '',
				'prompt_caching'  => false,
			)
		);

		$result = $this->controller->handle_assistant_update( $request );
		$this->assertNotWPError( $result );

		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_FALLBACK_CHAIN, true ) );
		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_VECTOR_STORE_ID, true ) );
		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PROMPT_CACHING, true ) );
	}

	/**
	 * Credentials are never part of the advanced surface.
	 */
	public function test_advanced_never_exposes_credentials() {
		$assistant_id = $this->create_assistant();

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_CREDENTIALS, array( array( 'token' => 'cred_test.secret' ) ) );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request();
		$request->set_param( 'id', $assistant_id );

		$result   = $this->controller->handle_assistant_get( $request );
		$advanced = $result->get_data()['data']['advanced'];

		$this->assertArrayNotHasKey( 'credentials', $advanced );
		$this->assertArrayNotHasKey( '_wp_mcp_ai_credentials', $advanced );

		$map = WP_MCP_AI_REST::get_assistant_advanced_field_map();
		$this->assertArrayNotHasKey( 'credentials', $map );
		foreach ( $map as $definition ) {
			$this->assertNotSame( WP_MCP_AI_Assistant_CPT::META_CREDENTIALS, $definition['meta_key'] );
		}
	}

	/**
	 * Skills ride the advanced object: sanitized, deduped and echoed back.
	 */
	public function test_advanced_skills_round_trip() {
		$assistant_id = $this->create_assistant();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param(
			'advanced',
			array(
				'skills'             => array( 'alpha-skill', 'beta-skill', 'alpha-skill' ),
				'skills_progressive' => true,
			)
		);

		$result = $this->controller->handle_assistant_update( $request );
		$this->assertNotWPError( $result );

		$this->assertSame(
			array( 'alpha-skill', 'beta-skill' ),
			get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, true )
		);
		$this->assertSame( 1, (int) get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS_PROGRESSIVE, true ) );

		$advanced = $result->get_data()['data']['advanced'];

		$this->assertSame( array( 'alpha-skill', 'beta-skill' ), $advanced['skills'] );
		$this->assertTrue( $advanced['skills_progressive'] );

		// The full record read path serves the same shape.
		$get = $this->nonce_request();
		$get->set_param( 'id', $assistant_id );

		$read = $this->controller->handle_assistant_get( $get )->get_data()['data']['advanced'];

		$this->assertSame( array( 'alpha-skill', 'beta-skill' ), $read['skills'] );
		$this->assertTrue( $read['skills_progressive'] );
	}

	/**
	 * Invalid skill slugs are dropped by the CPT sanitizer, and an empty list
	 * deletes the meta row.
	 */
	public function test_advanced_skills_drop_invalid_slugs_and_clear_on_empty() {
		$assistant_id = $this->create_assistant();

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, array( 'alpha-skill' ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS_PROGRESSIVE, 1 );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = $this->nonce_request( 'PUT' );
		$request->set_param( 'id', $assistant_id );
		$request->set_param(
			'advanced',
			array(
				'skills' => array( 'Valid-Slug', 'bad skill', 'double--hyphen', 'good-skill' ),
			)
		);

		$this->controller->handle_assistant_update( $request );

		$this->assertSame(
			array( 'good-skill' ),
			get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, true )
		);

		// Unticking progressive disclosure removes the row, not stores 0.
		$second = $this->nonce_request( 'PUT' );
		$second->set_param( 'id', $assistant_id );
		$second->set_param(
			'advanced',
			array(
				'skills'             => array(),
				'skills_progressive' => false,
			)
		);

		$this->controller->handle_assistant_update( $second );

		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, true ) );
		$this->assertSame( '', get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS_PROGRESSIVE, true ) );
	}
}
