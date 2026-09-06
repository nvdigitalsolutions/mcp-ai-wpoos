<?php
/**
 * Workflow trigger registry port tests (Wave E1, sub-cluster 3).
 *
 * Characterization suite for the ported `TriggerRegistry`:
 * byte-identical singleton lifecycle, the registration action fired on
 * first construction, the `register()` contract (sanitized type +
 * wp_parse_args defaults), the seven built-in trigger definitions with
 * their labels/descriptions/schemas, and the lookup helpers. Runs in
 * both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\TriggerRegistry;

/**
 * @group workflows
 */
class Test_Trigger_Registry extends \WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_actions( 'wp_mcp_ai_register_workflow_triggers' );
		parent::tearDown();
	}

	// ─── Singleton + registration action (first — the constructor runs once) ──

	public function test_singleton_construction_fires_registration_action(): void {
		$seen = null;
		add_action(
			'wp_mcp_ai_register_workflow_triggers',
			function ( $registry ) use ( &$seen ) {
				$seen = $registry;
			}
		);

		$instance = TriggerRegistry::get_instance();

		$this->assertInstanceOf( TriggerRegistry::class, $seen );
		$this->assertSame( $instance, TriggerRegistry::get_instance() );
	}

	// ─── Built-ins ────────────────────────────────────────────────────

	public function test_built_in_triggers_are_registered(): void {
		$registry = TriggerRegistry::get_instance();
		$triggers = $registry->get_triggers();

		$this->assertArrayHasKey( 'post_status_change', $triggers );
		$this->assertArrayHasKey( 'cron_schedule', $triggers );
		$this->assertArrayHasKey( 'rest_webhook', $triggers );
		$this->assertArrayHasKey( 'a2a_inbound', $triggers );
		$this->assertArrayHasKey( 'user_registration', $triggers );
		$this->assertArrayHasKey( 'comment_published', $triggers );
		$this->assertArrayHasKey( 'file_upload', $triggers );

		$this->assertSame( 'Post Status Change', $triggers['post_status_change']['label'] );
		$this->assertSame( 'Fires when a new user registers on the site.', $triggers['user_registration']['description'] );

		// Schema shape for the configurable built-ins.
		$status_schema = $triggers['post_status_change']['schema'];
		$this->assertArrayHasKey( 'post_type', $status_schema );
		$this->assertArrayHasKey( 'from_status', $status_schema );
		$this->assertArrayHasKey( 'to_status', $status_schema );

		$cron_schema = $triggers['cron_schedule']['schema'];
		$this->assertSame(
			array( 'hourly', 'twicedaily', 'daily', 'weekly' ),
			$cron_schema['schedule']['enum']
		);
	}

	// ─── Custom registration ─────────────────────────────────────────

	public function test_register_custom_trigger_with_full_config(): void {
		$type     = 'custom_trigger_' . uniqid();
		$registry = TriggerRegistry::get_instance();

		$registry->register(
			$type,
			array(
				'label'         => 'Custom Trigger',
				'description'   => 'A custom one.',
				'handler_class' => 'SomeHandler',
				'schema'        => array(
					'limit' => array( 'type' => 'integer' ),
				),
			)
		);

		$definition = $registry->get_trigger( $type );
		$this->assertSame( 'Custom Trigger', $definition['label'] );
		$this->assertSame( 'A custom one.', $definition['description'] );
		$this->assertSame( 'SomeHandler', $definition['handler_class'] );
		$this->assertSame( array( 'limit' => array( 'type' => 'integer' ) ), $definition['schema'] );
	}

	public function test_register_fills_defaults(): void {
		$type = 'defaults_trigger_' . uniqid();
		TriggerRegistry::get_instance()->register( $type, array() );

		$definition = TriggerRegistry::get_instance()->get_trigger( $type );
		$this->assertSame( $type, $definition['label'] );
		$this->assertSame( '', $definition['description'] );
		$this->assertSame( '', $definition['handler_class'] );
		$this->assertSame( array(), $definition['schema'] );
	}

	public function test_register_sanitizes_type_and_skips_empty(): void {
		$registry = TriggerRegistry::get_instance();

		// Sanitized on write AND lookup: registering a mixed-case key stores
		// the sanitized lowercase form.
		$registry->register( 'My_Custom-Type', array( 'label' => 'Sanitized' ) );
		$this->assertSame( 'Sanitized', $registry->get_trigger( 'my_custom-type' )['label'] );

		// Empty sanitized type is a no-op.
		$before = count( $registry->get_triggers() );
		$registry->register( '!!!', array( 'label' => 'Nope' ) );
		$this->assertSame( $before, count( $registry->get_triggers() ) );
	}

	// ─── Lookups ──────────────────────────────────────────────────────

	public function test_get_trigger_lookup_contract(): void {
		$registry = TriggerRegistry::get_instance();

		$this->assertIsArray( $registry->get_trigger( 'file_upload' ) );
		$this->assertFalse( $registry->get_trigger( 'does_not_exist' ) );
	}
}
