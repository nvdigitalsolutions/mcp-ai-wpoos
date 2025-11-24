<?php
/**
 * Tests covering authentication paths for the MCP REST layer.
 */
class WP_MCP_AI_REST_Authentication_Test extends WP_UnitTestCase {

	/**
	 * REST controller instance under test.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	protected function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );

		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->rest_controller = new WP_MCP_AI_REST( $registry, $mock_client );
	}

	protected function tearDown(): void {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
		remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
		remove_all_filters( 'wp_mcp_ai_map_bearer_to_user_id' );
		remove_all_filters( 'wp_mcp_ai_chat_capability' );

		parent::tearDown();
	}

	/**
	 * Ensure authors with a valid nonce can access the API.
	 */
	public function test_permissions_check_allows_author_with_valid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Ensure users without edit capabilities are rejected.
	 */
	public function test_permissions_check_blocks_subscriber_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Requests without credentials should surface actionable guidance.
	 */
	public function test_permissions_check_requires_credentials() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Invalid nonces are rejected before the request reaches the MCP layer.
	 */
	public function test_permissions_check_rejects_invalid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', 'invalid' );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_nonce', $result->get_error_code() );
	}

	/**
	 * Public chat capability should allow unauthenticated requests without a nonce.
	 */
	public function test_permissions_check_allows_public_access_without_nonce() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Public Assistant',
				'post_status' => 'publish',
			)
		);

		add_filter(
			'wp_mcp_ai_chat_capability',
			static function ( $capability, $filtered_assistant_id, $context ) use ( $assistant_id ) {
				if ( 'rest' === $context && (int) $filtered_assistant_id === (int) $assistant_id ) {
					return 'public';
				}

				return $capability;
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'assistant_id', $assistant_id );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Stored assistant credentials grant access when supplied via bearer token.
	 */
	public function test_permissions_check_accepts_valid_local_token() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Tokenised Assistant',
				'post_status' => 'publish',
			)
		);

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );

		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Revoked credentials should not be able to authenticate future requests.
	 */
	public function test_permissions_check_rejects_revoked_local_token() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Revocable Assistant',
				'post_status' => 'publish',
			)
		);

		$issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $issuer_id );

		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

		WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $issued['credential']['id'], $issuer_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_revoked_token', $result->get_error_code() );
	}

	/**
	 * Bearer tokens that do not resemble JWTs are rejected early.
	 */
	public function test_permissions_check_rejects_invalid_bearer_token_format() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer not-a-jwt' );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_bearer_token', $result->get_error_code() );
	}

	/**
	 * Mapping a bearer token to a WordPress user should update the current user context.
	 */
	public function test_bearer_token_mapping_sets_current_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		wp_set_current_user( 0 );

		add_filter(
			'wp_mcp_ai_pre_validate_bearer_token',
			static function () {
				return true;
			}
		);

		add_filter(
			'wp_mcp_ai_map_bearer_to_user_id',
			static function ( $mapped, $payload, $request ) use ( $user_id ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
				return $user_id;
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer placeholder' );

		$result = $this->rest_controller->permissions_check( $request );

		$this->assertTrue( $result );
		$this->assertSame( $user_id, get_current_user_id() );
	}
}
