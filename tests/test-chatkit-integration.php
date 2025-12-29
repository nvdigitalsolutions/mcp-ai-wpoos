<?php
/**
 * ChatKit integration tests.
 *
 * @package WP_MCP_AI\Tests
 */
class Test_ChatKit_Integration extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_ChatKit_Integration::reset_state_for_testing();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );
		remove_filter( 'wp_mcp_ai_chatkit_is_available', '__return_false' );
		remove_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ) );

		WP_MCP_AI_ChatKit_Integration::reset_state_for_testing();

		parent::tearDown();
	}

	/**
	 * Ensure the add-on registers automatically when no filters run.
	 */
	public function test_addon_registered_by_default() {
		WP_MCP_AI_ChatKit_Integration::maybe_bootstrap();

		$addons = apply_filters( 'chatkit_register_addons', array() );

		$this->assertArrayHasKey( WP_MCP_AI_ChatKit_Integration::ADDON_ID, $addons );

		$addon = $addons[ WP_MCP_AI_ChatKit_Integration::ADDON_ID ];

		$this->assertSame( 'wp-mcp-ai', $addon['id'] );
		$this->assertArrayHasKey( 'rest_namespace', $addon );
		$this->assertSame( WP_MCP_AI_REST::REST_NAMESPACE, $addon['rest_namespace'] );
		$this->assertArrayHasKey( 'rest_routes', $addon );
		$this->assertArrayHasKey( 'chat', $addon['rest_routes'] );
		$this->assertSame( '/chat', $addon['rest_routes']['chat']['path'] );
		$this->assertArrayHasKey( 'supports', $addon );
		$this->assertTrue( $addon['supports']['attachments'] );
		$this->assertArrayHasKey( 'surfaces', $addon );

		$this->assertArrayHasKey( 'shortcode', $addon['surfaces'] );
		$shortcode_surface = $addon['surfaces']['shortcode'];
		$this->assertSame( 'shortcode', $shortcode_surface['type'] );
		$this->assertSame( 'wp_mcp_ai_chat', $shortcode_surface['tag'] );
		$this->assertArrayHasKey( 'attributes', $shortcode_surface );
		$this->assertArrayHasKey( 'assistant', $shortcode_surface['attributes'] );
		$this->assertTrue( $shortcode_surface['attributes']['assistant']['required'] );

		$this->assertArrayHasKey( 'elementor_widget', $addon['surfaces'] );
		$elementor_surface = $addon['surfaces']['elementor_widget'];
		$this->assertSame( 'elementor_widget', $elementor_surface['type'] );
		$this->assertSame( 'wp_mcp_ai_chat', $elementor_surface['widget'] );
		$this->assertArrayHasKey( 'attributes', $elementor_surface );
		$this->assertArrayHasKey( 'allow_guests', $elementor_surface['attributes'] );
	}

	/**
	 * Ensure the add-on can be disabled via the availability filter.
	 */
	public function test_addon_can_be_disabled_via_filter() {
		add_filter( 'wp_mcp_ai_chatkit_is_available', '__return_false' );

		WP_MCP_AI_ChatKit_Integration::maybe_bootstrap();

		$addons = apply_filters( 'chatkit_register_addons', array() );

		$this->assertArrayNotHasKey( WP_MCP_AI_ChatKit_Integration::ADDON_ID, $addons );
	}

	/**
	 * Ensure the ChatKit capability inherits the chat capability filter.
	 */
	public function test_addon_capability_honours_filter() {
		add_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ), 10, 3 );

		WP_MCP_AI_ChatKit_Integration::maybe_bootstrap();

		$addons = apply_filters( 'chatkit_register_addons', array() );
		$addon  = $addons[ WP_MCP_AI_ChatKit_Integration::ADDON_ID ];

		$this->assertSame( 'read', $addon['capability'] );
	}

	/**
	 * Ensure the action-style registration path calls the manager.
	 */
	public function test_register_via_action_invokes_manager() {
		WP_MCP_AI_ChatKit_Integration::maybe_bootstrap();

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
	 * Ensure the legacy class alias remains available for backwards compatibility.
	 */
	public function test_legacy_class_alias_remains_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_ChatKit_Addon' ) );
		$this->assertTrue( is_a( 'WP_MCP_AI_ChatKit_Addon', WP_MCP_AI_ChatKit_Integration::class, true ) );
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
