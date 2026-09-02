<?php
/**
 * ChatKit integration port tests (Wave D1g).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ChatKitIntegration`. Assertions mirror the
 * base plugin's `tests/test-chatkit-integration.php` so the two
 * implementations stay behaviourally locked (ecosystem port plan,
 * principle: behaviour-preserving), plus characterization of the
 * documented standalone deviations (omitted download route, empty
 * surfaces, no `assistant_id` field, `guest_access => false`).
 *
 * Matrix note: in monolith runs the base plugin registers its own
 * `mcp-ai-wpoos` addon through the same ChatKit hooks, so assertions are
 * scoped to this addon's key only and never assume exclusivity.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\ChatKitIntegration;

/**
 * @group chat
 */
class Test_ChatKit_Integration extends \WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		ChatKitIntegration::reset_state_for_testing();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		\remove_filter( 'wp_mcp_ai_chatkit_is_available', '__return_true' );
		\remove_filter( 'wp_mcp_ai_chatkit_is_available', '__return_false' );
		\remove_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ), 10 );
		\remove_filter( 'wp_mcp_ai_chatkit_addon_definition', array( $this, 'filter_definition' ), 10 );

		ChatKitIntegration::reset_state_for_testing();

		parent::tearDown();
	}

	/**
	 * Ensure the add-on registers automatically when no filters run.
	 */
	public function test_addon_registered_by_default(): void {
		ChatKitIntegration::maybe_bootstrap();

		$addons = \apply_filters( 'chatkit_register_addons', array() );

		$this->assertArrayHasKey( ChatKitIntegration::ADDON_ID, $addons );

		$addon = $addons[ ChatKitIntegration::ADDON_ID ];

		$this->assertSame( 'nvoos-content-graph-ai', $addon['id'] );
		$this->assertSame( NVOOS_CONTENT_GRAPH_AI_VERSION, $addon['version'] );
		$this->assertStringContainsString( 'assets/images/ai-icon.svg', $addon['icon'] );
		$this->assertArrayHasKey( 'rest_namespace', $addon );
		$this->assertSame( 'nvoos-content-graph/v1', $addon['rest_namespace'] );
		$this->assertArrayHasKey( 'rest_routes', $addon );
		$this->assertArrayHasKey( 'chat', $addon['rest_routes'] );
		$this->assertSame( '/ai/chat', $addon['rest_routes']['chat']['path'] );
		$this->assertSame( 'POST', $addon['rest_routes']['chat']['method'] );
		$this->assertArrayHasKey( 'tools', $addon['rest_routes'] );
		$this->assertSame( '/ai/tools', $addon['rest_routes']['tools']['path'] );
		$this->assertSame( 'GET', $addon['rest_routes']['tools']['method'] );
		$this->assertArrayHasKey( 'supports', $addon );
		$this->assertTrue( $addon['supports']['attachments'] );
		$this->assertTrue( $addon['supports']['tool_invocations'] );
		$this->assertArrayHasKey( 'fields', $addon );
		$this->assertArrayHasKey( 'system_prompt', $addon['fields'] );
		$this->assertArrayHasKey( 'tool_shortcuts', $addon['fields'] );
	}

	/**
	 * Ensure the add-on can be disabled via the availability filter.
	 */
	public function test_addon_can_be_disabled_via_filter(): void {
		\add_filter( 'wp_mcp_ai_chatkit_is_available', '__return_false' );

		ChatKitIntegration::maybe_bootstrap();

		$addons = \apply_filters( 'chatkit_register_addons', array() );

		$this->assertArrayNotHasKey( ChatKitIntegration::ADDON_ID, $addons );
	}

	/**
	 * Ensure the ChatKit capability inherits the chat capability filter.
	 */
	public function test_addon_capability_honours_filter(): void {
		\add_filter( 'wp_mcp_ai_chat_capability', array( $this, 'filter_chat_capability' ), 10, 3 );

		ChatKitIntegration::maybe_bootstrap();

		$addons = \apply_filters( 'chatkit_register_addons', array() );
		$addon  = $addons[ ChatKitIntegration::ADDON_ID ];

		$this->assertSame( 'read', $addon['capability'] );
	}

	/**
	 * Ensure the action-style registration path calls the manager.
	 */
	public function test_register_via_action_invokes_manager(): void {
		ChatKitIntegration::maybe_bootstrap();

		$manager = new class() {
			public $received = null;

			public function register_addon( $definition ) {
				$this->received = $definition;
			}
		};

		\do_action( 'chatkit/register_addons', $manager );

		$this->assertIsArray( $manager->received );
		$this->assertSame( 'nvoos-content-graph-ai', $manager->received['id'] );
	}

	/**
	 * Ensure the action-style registration path without a manager fires the
	 * byte-identical hook the base plugin uses.
	 */
	public function test_register_via_action_without_manager_fires_hook(): void {
		ChatKitIntegration::maybe_bootstrap();

		$received = null;
		$capture  = static function ( $definition, $manager ) use ( &$received ) {
			$received = array( $definition, $manager );
		};
		\add_action( 'wp_mcp_ai_chatkit_addon_registered', $capture, 10, 2 );

		// WP 6.9 core pushes '' as the sole argument when do_action() is
		// fired without args (wp-includes/plugin.php); the port forwards it
		// verbatim — base-identical behaviour.
		\do_action( 'chatkit/register_addons' );

		$this->assertIsArray( $received );
		$this->assertSame( 'nvoos-content-graph-ai', $received[0]['id'] );
		$this->assertSame( '', $received[1] );

		\remove_action( 'wp_mcp_ai_chatkit_addon_registered', $capture, 10 );
	}

	/**
	 * Ensure non-array input to the filter path is coerced like the base.
	 */
	public function test_register_via_filter_coerces_non_array_input(): void {
		$addons = ChatKitIntegration::register_via_filter( null );

		$this->assertIsArray( $addons );
		$this->assertArrayHasKey( ChatKitIntegration::ADDON_ID, $addons );
	}

	/**
	 * Ensure the definition filter is honoured (hook name matches base).
	 */
	public function test_definition_filter_is_applied(): void {
		\add_filter( 'wp_mcp_ai_chatkit_addon_definition', array( $this, 'filter_definition' ), 10 );

		ChatKitIntegration::maybe_bootstrap();

		$addons = \apply_filters( 'chatkit_register_addons', array() );
		$addon  = $addons[ ChatKitIntegration::ADDON_ID ];

		$this->assertSame( 'overridden', $addon['name'] );
	}

	/**
	 * Ensure a second bootstrap call is a no-op (single registration).
	 *
	 * Counts this class's own `chatkit_register_addons` callback attachment
	 * rather than the filter result — in monolith runs the base plugin's
	 * addon is legitimately present in the same filter.
	 */
	public function test_bootstrap_is_idempotent(): void {
		ChatKitIntegration::maybe_bootstrap();
		ChatKitIntegration::maybe_bootstrap();

		global $wp_filter;

		$count = 0;
		if ( isset( $wp_filter['chatkit_register_addons'] ) ) {
			foreach ( $wp_filter['chatkit_register_addons']->callbacks as $priority_callbacks ) {
				foreach ( $priority_callbacks as $cb ) {
					if (
						is_array( $cb['function'] )
						&& ChatKitIntegration::class === $cb['function'][0]
						&& 'register_via_filter' === $cb['function'][1]
					) {
						++$count;
					}
				}
			}
		}

		$this->assertSame( 1, $count );
	}

	/**
	 * Characterize the documented standalone deviations from the base
	 * definition (restored as the D2/D5/D-UI waves land).
	 */
	public function test_standalone_definition_deviations(): void {
		ChatKitIntegration::maybe_bootstrap();

		$addons = \apply_filters( 'chatkit_register_addons', array() );
		$addon  = $addons[ ChatKitIntegration::ADDON_ID ];

		// No files download route yet (D2 file APIs).
		$this->assertArrayNotHasKey( 'download', $addon['rest_routes'] );

		// No assistant CPT yet (base declares a required assistant_id).
		$this->assertArrayNotHasKey( 'assistant_id', $addon['fields'] );

		// No guest-token surface yet (D5).
		$this->assertFalse( $addon['supports']['guest_access'] );

		// UI surfaces land with the D-UI wave; the key stays present so
		// ChatKit consumers always see the same top-level shape.
		$this->assertArrayHasKey( 'surfaces', $addon );
		$this->assertSame( array(), $addon['surfaces'] );
	}

	/**
	 * Filter callback to override the required capability during tests.
	 *
	 * @param string $capability   Current capability.
	 * @param int    $assistant_id Assistant identifier.
	 * @param string $context      Context provided by the plugin.
	 * @return string
	 */
	public function filter_chat_capability( $capability, $assistant_id, $context ) {
		if ( 'chatkit' === $context ) {
			return 'read';
		}

		return $capability;
	}

	/**
	 * Filter callback to override the definition during tests.
	 *
	 * @param array $definition ChatKit integration definition.
	 * @return array
	 */
	public function filter_definition( $definition ) {
		$definition['name'] = 'overridden';

		return $definition;
	}
}
