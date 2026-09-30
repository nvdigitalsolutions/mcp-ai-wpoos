<?php
/**
 * Test the physical-table storage gate on the orchestration CCT helpers.
 *
 * The orchestration dashboard, the agent command center, and the Ralph Loop
 * tools gate CCT access on `is_available()` / `get_item_handler()`. Those
 * gates must also fail when the content type is registered in JetEngine but
 * its physical table is missing (failed provisioning, missing DB privileges,
 * partial migrations). Without the gate, every query hits a non-existent
 * table and `$wpdb` prints an HTML error into the response body, corrupting
 * JSON AJAX payloads such as the orchestration dashboard's.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once dirname( __DIR__, 3 ) . '/tests/helpers/jetengine-stubs.php';

/**
 * Test class for the orchestration CCT storage gate.
 *
 * @group pro
 * @group jetengine
 * @group cct
 */
class Test_Orchestration_CCT_Storage_Gate extends WP_UnitTestCase {

	/**
	 * Fully-qualified probe table names, built from the test prefix.
	 *
	 * @var array
	 */
	private $tables = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->tables = array(
			$wpdb->prefix . 'jet_cct_mcp_autonomous_sessions',
			$wpdb->prefix . 'jet_cct_mcp_execution_history',
		);

		// The CCT classes are loaded lazily by the Pro module registry.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-autonomous-sessions-cct.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-execution-history-cct.php';

		// Clear the per-request storage readiness cache so each test re-probes
		// against the fixture tables it just (re)created.
		WP_MCP_AI_Autonomous_Sessions_CCT::reset_storage_cache();
		WP_MCP_AI_Execution_History_CCT::reset_storage_cache();

		// Ensure the probe tables start absent so tests are independent of
		// residue from other suites or earlier runs in the same process.
		foreach ( $this->tables as $table ) {
			$this->drop_table( $table );
		}

		wp_mcp_ai_jetengine_stub_reset();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->tables as $table ) {
			$this->drop_table( $table );
		}

		wp_mcp_ai_jetengine_stub_reset();

		if ( class_exists( 'WP_MCP_AI_Autonomous_Sessions_CCT' ) ) {
			WP_MCP_AI_Autonomous_Sessions_CCT::reset_storage_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Execution_History_CCT' ) ) {
			WP_MCP_AI_Execution_History_CCT::reset_storage_cache();
		}

		global $wpdb;
		$wpdb->show_errors     = false;
		$wpdb->suppress_errors = false;

		parent::tearDown();
	}

	/**
	 * Drop a table if it exists.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function drop_table( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture hygiene against fixed literal names.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * The gate reports false when the physical table does not exist.
	 */
	public function test_table_exists_false_when_table_missing() {
		$this->assertFalse( WP_MCP_AI_Autonomous_Sessions_CCT::table_exists() );
		$this->assertFalse( WP_MCP_AI_Execution_History_CCT::table_exists() );
	}

	/**
	 * The gate reports true when the physical table exists, but storage is
	 * not ready while the schema is incomplete.
	 */
	public function test_table_exists_true_when_table_present() {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_mcp_autonomous_sessions';
		$this->create_table( $table );

		$this->assertTrue( WP_MCP_AI_Autonomous_Sessions_CCT::table_exists() );
		$this->assertFalse( WP_MCP_AI_Autonomous_Sessions_CCT::is_storage_ready() );
	}

	/**
	 * Reports false when the table exists but required columns are missing
	 * (schema drift, partial migration).
	 */
	public function test_is_storage_ready_false_when_columns_missing() {
		global $wpdb;

		foreach ( $this->tables as $table ) {
			$this->create_table( $table );
		}

		$this->assertFalse( WP_MCP_AI_Autonomous_Sessions_CCT::is_storage_ready() );
		$this->assertFalse( WP_MCP_AI_Execution_History_CCT::is_storage_ready() );
	}

	/**
	 * Reports true when the table carries the full expected schema.
	 */
	public function test_is_storage_ready_true_with_full_schema() {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_mcp_autonomous_sessions';
		$this->create_full_schema_table( 'WP_MCP_AI_Autonomous_Sessions_CCT', $table );

		$this->assertTrue( WP_MCP_AI_Autonomous_Sessions_CCT::is_storage_ready() );
	}

	/**
	 * Reports false when the content type is registered and the table exists
	 * but its schema is incomplete.
	 */
	public function test_is_available_false_when_table_missing_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_mcp_autonomous_sessions';
		$this->create_table( $table );

		$this->install_jetengine_cct_graph();

		$this->assertFalse( WP_MCP_AI_Autonomous_Sessions_CCT::is_available() );
	}

	/**
	 * The probe must not leak $wpdb error-display state.
	 */
	public function test_table_exists_restores_error_display_state() {
		global $wpdb;

		$wpdb->show_errors = true;
		$wpdb->suppress_errors( false );

		WP_MCP_AI_Autonomous_Sessions_CCT::table_exists();

		$this->assertTrue( $wpdb->show_errors );
		$this->assertFalse( $wpdb->suppress_errors );
	}

	/**
	 * Reports false when the content type is registered in JetEngine but
	 * its physical table is missing.
	 */
	public function test_is_available_false_when_registered_but_table_missing() {
		$this->install_jetengine_cct_graph();

		$this->assertFalse( WP_MCP_AI_Autonomous_Sessions_CCT::is_available() );
	}

	/**
	 * Returns null when the content type is registered in JetEngine but
	 * its physical table is missing.
	 */
	public function test_execution_history_handler_null_when_registered_but_table_missing() {
		$this->install_jetengine_cct_graph();

		$this->assertNull( WP_MCP_AI_Execution_History_CCT::get_item_handler() );
	}

	/**
	 * Create a minimal CCT table.
	 *
	 * @param string $table Fully-qualified table name.
	 */
	private function create_table( $table ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL against fixed literal names.
		$wpdb->query(
			"CREATE TABLE `{$table}` (
				`_ID` bigint(20) NOT NULL AUTO_INCREMENT,
				`cct_status` varchar(50) DEFAULT NULL,
				PRIMARY KEY (`_ID`)
			) DEFAULT CHARSET=utf8mb4"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create a table carrying the full expected schema for a CCT class.
	 *
	 * The column list is derived from the class's own meta-field definitions
	 * (via reflection) so the test stays in sync with the production schema.
	 *
	 * @param string $class_name CCT class to derive the schema from.
	 * @param string $table      Fully-qualified table name.
	 */
	private function create_full_schema_table( $class_name, $table ) {
		global $wpdb;

		$reflection = new ReflectionClass( $class_name );
		$method     = $reflection->getMethod( 'get_meta_fields' );
		$method->setAccessible( true );
		$fields = $method->invoke( null );
		$names  = wp_list_pluck( $fields, 'name' );

		$columns = array( '`_ID` bigint(20) NOT NULL AUTO_INCREMENT' );
		foreach ( array( 'cct_status', 'cct_created', 'cct_modified', 'cct_author_id' ) as $builtin ) {
			$columns[] = "`{$builtin}` varchar(255) DEFAULT NULL";
		}
		foreach ( $names as $name ) {
			$columns[] = "`{$name}` varchar(255) DEFAULT NULL";
		}
		$columns[] = 'PRIMARY KEY (`_ID`)';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture DDL; names come from the fixed plugin schema, not user input.
		$wpdb->query( 'CREATE TABLE `' . $table . '` (' . implode( ', ', $columns ) . ') DEFAULT CHARSET=utf8mb4' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Install a fake JetEngine object graph in which the orchestration
	 * content types are registered (the manager resolves both slugs to a
	 * content-type instance) while no physical table exists. Exercises the
	 * gate placement between the registration lookup and the handler return.
	 */
	private function install_jetengine_cct_graph() {
		// The content-type registry resolves both orchestration CCT slugs to
		// a registered (but table-less) content-type instance. A real method
		// is required: PHP does not invoke Closure properties via `->method()`.
		$manager = new class() {
			/**
			 * Resolve a content-type instance by slug.
			 *
			 * @param string $slug Content-type slug.
			 * @return object|null
			 */
			public function get_content_types( $slug ) {
				return in_array( $slug, array( 'mcp_autonomous_sessions', 'mcp_execution_history' ), true ) ? new stdClass() : null;
			}
		};

		$module_instance          = new stdClass();
		$module_instance->manager = $manager;

		$module_wrapper           = new stdClass();
		$module_wrapper->instance = $module_instance;

		$engine = jet_engine();
		$engine->modules->activate_module( 'custom-content-types' );

		$reflection = new ReflectionProperty( Jet_Engine_Modules::class, 'modules' );
		$reflection->setAccessible( true );
		$modules                         = $reflection->getValue( $engine->modules );
		$modules['custom-content-types'] = $module_wrapper;
		$reflection->setValue( $engine->modules, $modules );

		wp_mcp_ai_jetengine_stub_set_instance( $engine );
	}
}
