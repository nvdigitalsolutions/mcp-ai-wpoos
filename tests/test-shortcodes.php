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
}
