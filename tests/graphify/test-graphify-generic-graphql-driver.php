<?php
/**
 * Tests for the Generic GraphQL Remote Driver (Phase 4 batch 1).
 *
 * No live HTTP — exercises config schema, capability flags, registry
 * registration, and the variables-resolution path that injects the
 * incremental cursor from the state store.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Generic_GraphQL_Driver
 */
class Test_Graphify_Generic_GraphQL_Driver extends WP_UnitTestCase {

	/**
	 * Driver advertises the expected ID and capability flags.
	 */
	public function test_driver_id_and_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$this->assertSame( 'generic_graphql', $driver->get_driver_id() );
		$flags = $driver->get_capability_flags();
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_relationships'] );
		$this->assertFalse( $flags['supports_oauth'] );
		$this->assertFalse( $flags['supports_webhooks'] );
	}

	/**
	 * Config schema declares the fields a Pro consumer needs to bind
	 * a query, variables, auth and mapping.
	 */
	public function test_config_schema_keys() {
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$schema = $driver->get_config_schema();
		foreach (
			array(
				'endpoint_url',
				'query',
				'variables_json',
				'auth_type',
				'auth_value',
				'node_path',
				'edge_path',
				'node_id_field',
				'node_label_field',
				'incremental_var',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $schema, "schema key missing: {$key}" );
		}
	}

	/**
	 * test_connection without endpoint_url returns a structured failure
	 * (no fatal, no remote call).
	 */
	public function test_connection_returns_failure_when_endpoint_missing() {
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$driver->set_config( array() );
		$result = $driver->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}

	/**
	 * fetch_nodes / fetch_edges return [] when not configured rather than
	 * raising — matches generic_rest behaviour.
	 */
	public function test_fetch_returns_empty_when_not_configured() {
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$driver->set_config( array() );
		$this->assertSame( array(), $driver->fetch_nodes() );
		$this->assertSame( array(), $driver->fetch_edges() );
	}

	/**
	 * The variables-resolution path merges variables_json with the
	 * incremental cursor stored under `incremental_var`. This exercises
	 * the public `resolve_variables` flow indirectly through reflection,
	 * keeping the test true to the implementation.
	 */
	public function test_variables_inject_incremental_cursor_from_state_store() {
		$slug   = 'test_graphql_cursor';
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$driver->set_config(
			array(
				'_slug'           => $slug,
				'endpoint_url'    => 'https://api.example.com/graphql',
				'query'           => 'query($since: String){ items(since:$since){ id name } }',
				'variables_json'  => wp_json_encode( array( 'first' => 25 ) ),
				'incremental_var' => 'since',
			)
		);

		// Seed the cursor as if a previous run completed.
		NV_oOS_Graphify_Remote_State_Store::set( $slug, 'last_run_iso', '2026-04-01T00:00:00+00:00' );

		// Reach into resolve_variables() — it is private but stable for tests.
		$ref = new ReflectionClass( $driver );
		$m   = $ref->getMethod( 'resolve_variables' );
		$m->setAccessible( true );
		$vars = $m->invoke( $driver, $slug );

		$this->assertSame( 25, $vars['first'] );
		$this->assertSame( '2026-04-01T00:00:00+00:00', $vars['since'] );
	}

	/**
	 * Reconcile is a no-op (matches generic_rest).
	 */
	public function test_reconcile_is_noop() {
		$driver = new NV_oOS_Graphify_Remote_Generic_GraphQL();
		$out    = $driver->reconcile( null );
		$this->assertFalse( $out['matched'] );
		$this->assertSame( 0.0, $out['confidence'] );
	}

	/**
	 * The driver registers cleanly through the shared registry.
	 */
	public function test_driver_registers_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_Generic_GraphQL() );
		$ids = array();
		foreach ( $registry->get_drivers() as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 'generic_graphql', $ids );
	}
}
