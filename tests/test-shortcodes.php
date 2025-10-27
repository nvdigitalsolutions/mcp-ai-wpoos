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
     * Ensure that rendering both shortcodes does not enqueue duplicate scripts.
     */
    public function test_shortcodes_enqueue_scripts_once() {
        $assistant_id = self::factory()->post->create(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Test Assistant',
            )
        );

        $chat_markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Shortcode::SHORTCODE, $assistant_id ) );
        $deep_markup = do_shortcode( sprintf( '[%s assistant="%d"]', WP_MCP_AI_Deep_Chat_Shortcode::SHORTCODE, $assistant_id ) );

        $this->assertStringContainsString( 'data-wp-mcp-ai-chat', $chat_markup );
        $this->assertStringContainsString( 'data-wp-mcp-ai-deep-chat', $deep_markup );

        $this->assertTrue( wp_script_is( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'wp-mcp-ai-deep-chat', 'enqueued' ) );

        if ( wp_script_is( 'wp-mcp-ai-deep-chat-app', 'registered' ) ) {
            $this->assertTrue( wp_script_is( 'wp-mcp-ai-deep-chat-app', 'enqueued' ) );
        }

        $script_counts = array_count_values( wp_scripts()->queue );
        foreach ( array( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'wp-mcp-ai-deep-chat', 'wp-mcp-ai-deep-chat-app' ) as $handle ) {
            if ( wp_script_is( $handle, 'enqueued' ) ) {
                $this->assertArrayHasKey( $handle, $script_counts );
                $this->assertSame( 1, $script_counts[ $handle ], sprintf( '%s should be enqueued exactly once.', $handle ) );
            }
        }
    }
}
