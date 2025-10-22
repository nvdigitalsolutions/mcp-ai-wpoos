<?php
/**
 * Tests for assistant access restrictions over REST.
 */
class WP_MCP_AI_REST_Assistant_Access_Test extends WP_UnitTestCase {

    /**
     * Ensure chat requests fail for unpublished assistants owned by other users.
     */
    public function test_chat_request_rejected_for_unpublished_assistant() {
        $owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Draft Assistant',
                'post_status' => 'draft',
                'post_author' => $owner_id,
            )
        );

        $requesting_user = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $requesting_user );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->onlyMethods( array( 'create_chat_completion' ) )
            ->disableOriginalConstructor()
            ->getMock();

        $mock_client
            ->expects( $this->never() )
            ->method( 'create_chat_completion' );

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param(
            'messages',
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Hello',
                ),
            )
        );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 403, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
    }

    /**
     * Ensure requests without explicit credentials return actionable guidance.
     */
    public function test_request_without_credentials_returns_actionable_error() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->onlyMethods( array( 'create_chat_completion' ) )
            ->disableOriginalConstructor()
            ->getMock();

        $mock_client
            ->expects( $this->never() )
            ->method( 'create_chat_completion' );

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param(
            'messages',
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Hello',
                ),
            )
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 401, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'wp_mcp_ai_missing_credentials', $data['code'] );
        $this->assertArrayHasKey( 'actions', $data );
        $this->assertArrayHasKey( 'supply_application_password', $data['actions'] );
    }

    /**
     * Ensure chat requests succeed for published assistants.
     */
    public function test_chat_request_allows_published_assistant() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $user_id );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->onlyMethods( array( 'create_chat_completion' ) )
            ->disableOriginalConstructor()
            ->getMock();

        $mock_client
            ->expects( $this->once() )
            ->method( 'create_chat_completion' )
            ->willReturn(
                array(
                    'id'      => 'chatcmpl-test',
                    'choices' => array(),
                )
            );

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param(
            'messages',
            array(
                array(
                    'role'    => 'user',
                    'content' => 'Hello',
                ),
            )
        );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
    }

    /**
     * Ensure application password requests bypass the nonce requirement.
     */
    public function test_application_password_request_bypasses_nonce_requirement() {
        global $wp_rest_application_password_uuid;

        $previous_user = get_current_user_id();
        $previous_uuid = isset( $wp_rest_application_password_uuid ) ? $wp_rest_application_password_uuid : null;

        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $user_id );
        $wp_rest_application_password_uuid = 'test-uuid';

        try {
            $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
                ->onlyMethods( array( 'create_chat_completion' ) )
                ->disableOriginalConstructor()
                ->getMock();

            $mock_client
                ->expects( $this->once() )
                ->method( 'create_chat_completion' )
                ->willReturn(
                    array(
                        'id'      => 'chatcmpl-test',
                        'choices' => array(),
                    )
                );

            $this->bootstrap_rest_controller( $mock_client );

            $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
            $request->set_param( 'assistant_id', $assistant_id );
            $request->set_param(
                'messages',
                array(
                    array(
                        'role'    => 'user',
                        'content' => 'Hello',
                    ),
                )
            );
            $request->set_header( 'Authorization', 'Basic dGVzdDpwd2Q=' );

            $response = rest_get_server()->dispatch( $request );

            $this->assertInstanceOf( WP_REST_Response::class, $response );
            $this->assertSame( 200, $response->get_status() );
        } finally {
            $wp_rest_application_password_uuid = $previous_uuid;
            wp_set_current_user( $previous_user );
        }
    }

    /**
     * Ensure requests authenticated with insufficient capabilities return a guidance error.
     */
    public function test_application_password_request_with_insufficient_permissions_returns_error() {
        global $wp_rest_application_password_uuid;

        $previous_user = get_current_user_id();
        $previous_uuid = isset( $wp_rest_application_password_uuid ) ? $wp_rest_application_password_uuid : null;

        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );
        $wp_rest_application_password_uuid = 'test-uuid';

        try {
            $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
                ->onlyMethods( array( 'create_chat_completion' ) )
                ->disableOriginalConstructor()
                ->getMock();

            $mock_client
                ->expects( $this->never() )
                ->method( 'create_chat_completion' );

            $this->bootstrap_rest_controller( $mock_client );

            $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
            $request->set_param( 'assistant_id', $assistant_id );
            $request->set_param(
                'messages',
                array(
                    array(
                        'role'    => 'user',
                        'content' => 'Hello',
                    ),
                )
            );
            $request->set_header( 'Authorization', 'Basic dGVzdDpwd2Q=' );

            $response = rest_get_server()->dispatch( $request );

            $this->assertInstanceOf( WP_REST_Response::class, $response );
            $this->assertSame( 403, $response->get_status() );

            $data = $response->get_data();
            $this->assertIsArray( $data );
            $this->assertSame( 'wp_mcp_ai_insufficient_permissions', $data['code'] );
            $this->assertArrayHasKey( 'actions', $data );
        } finally {
            $wp_rest_application_password_uuid = $previous_uuid;
            wp_set_current_user( $previous_user );
        }
    }

    /**
     * Ensure tool requests fail for unpublished assistants owned by other users.
     */
    public function test_tool_request_rejected_for_unpublished_assistant() {
        $owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Draft Assistant',
                'post_status' => 'draft',
                'post_author' => $owner_id,
            )
        );

        $requesting_user = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $requesting_user );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'tool', 'dummy_tool' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 403, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'wp_mcp_ai_assistant_forbidden', $data['code'] );
    }

    /**
     * Ensure tool requests succeed for published assistants.
     */
    public function test_tool_request_allows_published_assistant() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'wp_mcp_ai_dummy_tool' ) );

        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $user_id );

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $registry->register_tool( new WP_MCP_AI_Dummy_Tool() );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'tool', 'wp_mcp_ai_dummy_tool' );
        $request->set_param( 'arguments', array() );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'wp_mcp_ai_dummy_tool', $data['tool'] );
        $this->assertSame( array( 'success' => true ), $data['result'] );
    }

    /**
     * Bootstrap the REST controller with a mocked client.
     *
     * @param WP_MCP_AI_OpenAI_Client $client Client mock instance.
     */
    protected function bootstrap_rest_controller( $client ) {
        if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
            remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();
        $GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

        rest_get_server();
        do_action( 'rest_api_init' );
    }
}

/**
 * Simple tool implementation for testing tool execution.
 */
class WP_MCP_AI_Dummy_Tool implements WP_MCP_AI_Tool_Interface {
    public function get_slug() {
        return 'wp_mcp_ai_dummy_tool';
    }

    public function get_name() {
        return 'Dummy Tool';
    }

    public function get_description() {
        return 'Test tool.';
    }

    public function get_parameters_schema() {
        return array();
    }

    public function execute( array $arguments = array(), array $context = array() ) {
        return array( 'success' => true );
    }
}
