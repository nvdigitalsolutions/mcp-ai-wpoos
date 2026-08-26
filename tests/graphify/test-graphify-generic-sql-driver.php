<?php
/**
 * Tests for the Generic SQL (read-only) Remote Driver — Phase 4 batch 2.
 *
 * Exercises the SELECT-only safety guard, DSN allow-list, capability flags,
 * config schema, empty-config behaviour and registry registration. No live
 * database connection is required — the safety logic and configuration
 * surface are pure-PHP and fully testable in isolation.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/bootstrap.php';

/**
 * Test_Graphify_Generic_SQL_Driver
 */
class Test_Graphify_Generic_SQL_Driver extends WP_UnitTestCase {

	/**
	 * Driver advertises the expected ID and capability flags.
	 */
	public function test_driver_id_and_capability_flags() {
		$driver = new NV_oOS_Graphify_Remote_Generic_SQL();
		$this->assertSame( 'generic_sql', $driver->get_driver_id() );
		$flags = $driver->get_capability_flags();
		$this->assertTrue( $flags['supports_incremental'] );
		$this->assertTrue( $flags['supports_relationships'] );
		$this->assertFalse( $flags['supports_oauth'] );
		$this->assertFalse( $flags['supports_webhooks'] );
	}

	/**
	 * Config schema declares everything a Pro consumer needs to bind a
	 * connection, query, and column mapping.
	 */
	public function test_config_schema_keys() {
		$driver = new NV_oOS_Graphify_Remote_Generic_SQL();
		$schema = $driver->get_config_schema();
		foreach (
			array(
				'dsn',
				'username',
				'password',
				'node_query',
				'edge_query',
				'node_id_column',
				'node_label_column',
				'node_type_column',
				'node_url_column',
				'edge_source_column',
				'edge_target_column',
				'edge_relation_column',
				'batch_limit',
				'connection_timeout',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $schema, "schema key missing: {$key}" );
		}
	}

	/**
	 * SELECT-only guard accepts a single SELECT (with or without trailing
	 * semicolon and comments) and rejects anything else.
	 */
	public function test_is_select_only_accepts_safe_queries() {
		$ok = array(
			'SELECT id, name FROM users',
			'  select * from t  ',
			"-- header comment\nSELECT 1",
			"/* leading */\nSELECT a FROM b WHERE x = :since",
			'WITH cte AS (SELECT 1) SELECT * FROM cte',
			'SELECT 1;',
		);
		foreach ( $ok as $q ) {
			$this->assertTrue( NV_oOS_Graphify_Remote_Generic_SQL::is_select_only( $q ), "should accept: {$q}" );
		}
	}

	/**
	 * The guard rejects writes, multi-statement payloads, and empty input.
	 */
	public function test_is_select_only_rejects_dangerous_queries() {
		$bad = array(
			'',
			'   ',
			'INSERT INTO t VALUES (1)',
			'UPDATE t SET x = 1',
			'DELETE FROM t',
			'DROP TABLE t',
			'TRUNCATE t',
			'SELECT 1; DELETE FROM t',
			'SELECT 1; SELECT 2',
			"SELECT 1; -- still rejected\nSELECT 2",
			'CALL evil()',
			'GRANT ALL ON *.* TO bob',
		);
		foreach ( $bad as $q ) {
			$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_select_only( $q ), 'should reject: ' . $q );
		}
	}

	/**
	 * DSN allow-list refuses unknown schemes and malformed input. Loaded
	 * driver schemes are accepted only when PDO actually has them.
	 */
	public function test_is_dsn_allowed_rejects_unknown_schemes() {
		$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_dsn_allowed( '' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_dsn_allowed( ':host=foo' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_dsn_allowed( 'odbc:DRIVER={SQL Server}' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_dsn_allowed( 'oci:dbname=//host/sid' ) );
		$this->assertFalse( NV_oOS_Graphify_Remote_Generic_SQL::is_dsn_allowed( 'firebird:host=foo' ) );
	}

	/**
	 * Test_connection without DSN returns a structured failure (no fatal,
	 * no remote call).
	 */
	public function test_connection_returns_failure_when_dsn_missing() {
		$driver = new NV_oOS_Graphify_Remote_Generic_SQL();
		$driver->set_config( array() );
		$result = $driver->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['message'] );
	}

	/**
	 * Fetch_nodes / fetch_edges short-circuit to [] when the configured
	 * query is empty or non-SELECT — never reaches PDO.
	 */
	public function test_fetch_returns_empty_when_query_unsafe_or_missing() {
		$driver = new NV_oOS_Graphify_Remote_Generic_SQL();
		$driver->set_config( array() );
		$this->assertSame( array(), $driver->fetch_nodes() );
		$this->assertSame( array(), $driver->fetch_edges() );

		// Even with a DSN, a non-SELECT query must short-circuit.
		$driver->set_config(
			array(
				'_slug'      => 'x',
				'dsn'        => 'sqlite::memory:',
				'node_query' => 'DROP TABLE users',
				'edge_query' => 'DELETE FROM rel',
			)
		);
		$this->assertSame( array(), $driver->fetch_nodes() );
		$this->assertSame( array(), $driver->fetch_edges() );
	}

	/**
	 * Reconcile is a no-op (matches generic_rest / generic_graphql).
	 */
	public function test_reconcile_is_noop() {
		$driver = new NV_oOS_Graphify_Remote_Generic_SQL();
		$out    = $driver->reconcile( null );
		$this->assertFalse( $out['matched'] );
		$this->assertSame( 0.0, $out['confidence'] );
	}

	/**
	 * The driver registers cleanly through the shared registry.
	 */
	public function test_driver_registers_through_registry() {
		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$registry->register_driver( new NV_oOS_Graphify_Remote_Generic_SQL() );
		$ids = array();
		foreach ( $registry->get_drivers() as $d ) {
			$ids[] = $d->get_driver_id();
		}
		$this->assertContains( 'generic_sql', $ids );
	}
}
