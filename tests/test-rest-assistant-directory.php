<?php
/**
 * Tests for the assistant directory REST endpoint.
 */
class WP_MCP_AI_REST_Assistant_Directory_Test extends WP_UnitTestCase {

    /**
     * Administrator user ID used for authenticated requests.
     *
     * @var int
     */
    protected $admin_id;

    public function setUp(): void {
        parent::setUp();

        $this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->admin_id );
    }

    public function tearDown(): void {
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Ensure the directory returns published assistants and marks the default.
     */
    public function test_directory_returns_accessible_assistants_with_metadata() {
        $first_assistant  = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Alpha Assistant',
            )
        );
        $second_assistant = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Beta Assistant',
            )
        );

        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['default_assistant'] = $first_assistant;
        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'assistants', $data );
        $this->assertCount( 2, $data['assistants'] );

        $ids = wp_list_pluck( $data['assistants'], 'id' );
        sort( $ids );
        $this->assertSame( array( $first_assistant, $second_assistant ), $ids );

        $this->assertSame( $first_assistant, $data['default_assistant'] );
        $this->assertArrayHasKey( 'rest', $data );
        $this->assertArrayHasKey( 'chat', $data['rest'] );

        $assistants_by_id = array();
        foreach ( $data['assistants'] as $assistant ) {
            $assistants_by_id[ $assistant['id'] ] = $assistant;
        }

        $this->assertTrue( $assistants_by_id[ $first_assistant ]['is_default'] );
        $this->assertFalse( $assistants_by_id[ $second_assistant ]['is_default'] );
        $this->assertIsArray( $assistants_by_id[ $first_assistant ]['tools'] );
    }

    /**
     * Ensure assistant-issued credentials scope the directory to a single assistant.
     */
    public function test_directory_scopes_results_for_local_token() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'draft',
                'post_title'  => 'Scoped Assistant',
            )
        );

        $issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $issuer_id );
        $issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

        wp_set_current_user( 0 );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bootstrap_rest_controller( $mock_client );

        $request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
        $request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertCount( 1, $data['assistants'] );
        $this->assertSame( $assistant_id, $data['assistants'][0]['id'] );
        $this->assertArrayHasKey( 'token_scope', $data );
        $this->assertSame( 'local_token', $data['token_scope']['type'] );
        $this->assertSame( $assistant_id, $data['token_scope']['assistant_id'] );
    }

    /**
     * Ensure public capability overrides still respect publication status.
     */
    public function test_directory_respects_public_capability_and_omits_unpublished() {
        $published = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Public Directory Assistant',
            )
        );
        wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'draft',
                'post_title'  => 'Hidden Directory Assistant',
            )
        );

        $public_filter = function( $capability, $assistant_id, $context ) {
            if ( 'rest' === $context ) {
                return 'public';
            }

            return $capability;
        };

        add_filter( 'wp_mcp_ai_chat_capability', $public_filter, 10, 3 );

        $mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->bootstrap_rest_controller( $mock_client );

        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'wp_mcp_ai_chat_capability', $public_filter, 10 );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertCount( 1, $data['assistants'] );
        $this->assertSame( $published, $data['assistants'][0]['id'] );
    }

    /**
     * Helper to bootstrap the REST controller with a mocked router.
     *
     * @param WP_MCP_AI_Language_Model_Router $client Router instance.
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

