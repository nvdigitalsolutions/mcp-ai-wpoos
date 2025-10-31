<?php
/**
 * ChatKit add-on integration tests.
 *
 * @package WP_MCP_AI\Tests
 */

class Test_ChatKit_Addon extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();

        WP_MCP_AI_ChatKit_Addon::reset_state_for_testing();
    }

    public function tearDown(): void {
        remove_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );
        remove_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ) );

        WP_MCP_AI_ChatKit_Addon::reset_state_for_testing();

        parent::tearDown();
    }

    /**
     * Ensure the add-on is not registered when ChatKit is unavailable.
     */
    public function test_addon_not_registered_when_chatkit_unavailable() {
        WP_MCP_AI_ChatKit_Addon::maybe_bootstrap();

        $addons = apply_filters( 'chatkit_register_addons', array() );

        $this->assertArrayNotHasKey( WP_MCP_AI_ChatKit_Addon::ADDON_ID, $addons );
    }

    /**
     * Ensure the add-on is registered when ChatKit is forced available.
     */
    public function test_addon_registered_when_chatkit_available() {
        add_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );

        WP_MCP_AI_ChatKit_Addon::maybe_bootstrap();

        $addons = apply_filters( 'chatkit_register_addons', array() );

        $this->assertArrayHasKey( WP_MCP_AI_ChatKit_Addon::ADDON_ID, $addons );

        $addon = $addons[ WP_MCP_AI_ChatKit_Addon::ADDON_ID ];

        $this->assertSame( 'wp-mcp-ai', $addon['id'] );
        $this->assertArrayHasKey( 'rest_namespace', $addon );
        $this->assertSame( WP_MCP_AI_REST::REST_NAMESPACE, $addon['rest_namespace'] );
        $this->assertArrayHasKey( 'rest_routes', $addon );
        $this->assertArrayHasKey( 'chat', $addon['rest_routes'] );
        $this->assertSame( '/chat', $addon['rest_routes']['chat']['path'] );
        $this->assertArrayHasKey( 'supports', $addon );
        $this->assertTrue( $addon['supports']['attachments'] );
    }

    /**
     * Ensure the ChatKit capability inherits the chat capability filter.
     */
    public function test_addon_capability_honours_filter() {
        add_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );
        add_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ), 10, 3 );

        WP_MCP_AI_ChatKit_Addon::maybe_bootstrap();

        $addons = apply_filters( 'chatkit_register_addons', array() );
        $addon  = $addons[ WP_MCP_AI_ChatKit_Addon::ADDON_ID ];

        $this->assertSame( 'read', $addon['capability'] );
    }

    /**
     * Ensure the action-style registration path calls the manager.
     */
    public function test_register_via_action_invokes_manager() {
        add_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );

        WP_MCP_AI_ChatKit_Addon::maybe_bootstrap();

        $manager = new class() {
            public $received = null;

            public function register_addon( $definition ) {
                $this->received = $definition;
            }
        };

        do_action( 'chatkit/register_addons', $manager );

        $this->assertIsArray( $manager->received );
        $this->assertSame( 'wp-mcp-ai', $manager->received['id'] );
    }

    /**
     * Filter callback to override the required capability during tests.
     *
     * @param string $capability Current capability.
     * @param int    $assistant_id Assistant identifier.
     * @param string $context Context provided by the plugin.
     * @return string
     */
    public function filter_chat_capability( $capability, $assistant_id, $context ) {
        if ( 'chatkit' === $context ) {
            return 'read';
        }

        return $capability;
    }
}

