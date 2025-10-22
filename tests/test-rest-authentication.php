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
        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
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
}
