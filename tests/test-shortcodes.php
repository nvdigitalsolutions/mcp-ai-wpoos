<?php
/**
 * Test coverage for the shortcode front-end integrations.
 *
 * @package WP_MCP_AI\Tests
 */

class Test_Shortcodes extends WP_UnitTestCase {
    /**
     * Administrator user ID used for capability checks.
     *
     * @var int
     */
    protected $admin_id;

    public function setUp(): void {
        parent::setUp();

        if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
            wp_mcp_ai_bootstrap();
        }

        wp_scripts()->reset();
        wp_styles()->reset();

        $this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->admin_id );

        do_action( 'init' );
    }

    public function tearDown(): void {
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Ensure that rendering the chat shortcode enqueues the assets once.
     */
    public function test_chat_shortcode_enqueues_scripts_once() {
        $assistant_id = self::factory()->post->create(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Test Assistant',
            )
        );

        $chat_markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
        $this->assertStringContainsString( 'data-wp-mcp-ai-chat', $chat_markup );

        $this->assertTrue( wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'enqueued' ) );

        $script_counts = array_count_values( wp_scripts()->queue );
        $handle        = WP_MCP_AI_Shortcode::SCRIPT_HANDLE;
        $this->assertArrayHasKey( $handle, $script_counts );
        $this->assertSame( 1, $script_counts[ $handle ], sprintf( '%s should be enqueued exactly once.', $handle ) );

        wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
        $this->assertTrue( wp_style_is( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'enqueued' ) );
    }

    /**
     * Ensure the chat stylesheet can be enqueued when requested.
     */
    public function test_chat_stylesheet_can_be_enqueued() {
        wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );

        $this->assertTrue( wp_style_is( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'enqueued' ) );
    }

    /**
     * Ensure guest access tokens cannot surface non-public attachments via the search tool.
     */
    public function test_guest_token_attachment_search_only_returns_public_files() {
        $assistant_id = self::factory()->post->create(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Guest Knowledge Assistant',
            )
        );

        update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'search_attachments' ) );

        $public_parent = self::factory()->post->create(
            array(
                'post_author' => $this->admin_id,
                'post_status' => 'publish',
            )
        );

        $private_parent = self::factory()->post->create(
            array(
                'post_author' => $this->admin_id,
                'post_status' => 'private',
            )
        );

        $public_upload = wp_upload_bits( 'guest-public-' . uniqid() . '.txt', null, 'Public guest file' );
        $this->assertIsArray( $public_upload );
        $this->assertArrayHasKey( 'file', $public_upload );
        $this->assertFalse( $public_upload['error'] );

        $public_id = self::factory()->attachment->create_upload_object( $public_upload['file'], $public_parent );
        wp_update_post(
            array(
                'ID'            => $public_id,
                'post_title'    => 'Guest Visible File',
                'post_author'   => $this->admin_id,
                'post_mime_type'=> 'text/plain',
            )
        );

        $private_upload = wp_upload_bits( 'guest-private-' . uniqid() . '.txt', null, 'Hidden guest file' );
        $this->assertIsArray( $private_upload );
        $this->assertArrayHasKey( 'file', $private_upload );
        $this->assertFalse( $private_upload['error'] );

        $private_id = self::factory()->attachment->create_upload_object( $private_upload['file'], $private_parent );
        wp_update_post(
            array(
                'ID'            => $private_id,
                'post_title'    => 'Guest Hidden File',
                'post_author'   => $this->admin_id,
                'post_parent'   => $private_parent,
                'post_mime_type'=> 'text/plain',
            )
        );

        $guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
        $this->assertNotEmpty( $guest_token );
        $this->assertSame( $assistant_id, WP_MCP_AI_Shortcode::validate_guest_token( $guest_token, $assistant_id ) );

        wp_set_current_user( 0 );

        rest_get_server();
        do_action( 'rest_api_init' );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'tool', 'search_attachments' );
        $request->set_param( 'guest_token', $guest_token );

        $response = rest_get_server()->dispatch( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 401, $response->get_status(), 'Guest tokens should not gain direct tool access.' );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'code', $data );
        $this->assertSame( 'wp_mcp_ai_anonymous_user', $data['code'] );

        wp_set_current_user( $this->admin_id );
    }
}
