<?php
/**
 * Tests for {@see WP_MCP_AI_Agent_Memory_CCT_Reader}.
 *
 * Seeds the real `{prefix}jet_cct_ai_agent_memories` table with raw rows and
 * exercises the read path that hydrates `recall_memory` and acts as a
 * fallback for `retrieve_agent_memory`.
 *
 * @package WP_MCP_AI
 */

/**
 * Reader behaviour against a temp CCT table.
 */
class WP_MCP_AI_Agent_Memory_CCT_Reader_Test extends WP_UnitTestCase {

	/**
	 * Fully-qualified table name in the test database.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Build a temp CCT table that matches the production column shape.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->table = $wpdb->prefix . 'jet_cct_ai_agent_memories';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture: table name is $wpdb->prefix + literal.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$this->table}` (
				`_ID` int(11) NOT NULL AUTO_INCREMENT,
				`cct_status` varchar(20) DEFAULT 'publish',
				`context_id` varchar(190) DEFAULT '',
				`agent_id` varchar(190) DEFAULT '',
				`memory_tier` varchar(40) DEFAULT '',
				`context_type` varchar(40) DEFAULT '',
				`wing` varchar(190) DEFAULT '',
				`room` varchar(190) DEFAULT '',
				`title` varchar(255) DEFAULT '',
				`content` longtext,
				`tags` longtext,
				`importance` varchar(20) DEFAULT '',
				`verbatim` tinyint(1) DEFAULT 0,
				`transaction_time` datetime DEFAULT NULL,
				`valid_from` datetime DEFAULT NULL,
				`valid_until` datetime DEFAULT NULL,
				`expires_at` datetime DEFAULT NULL,
				`source` varchar(190) DEFAULT '',
				`metadata` longtext,
				PRIMARY KEY (`_ID`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the temp table so each test starts clean.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture cleanup
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
		parent::tearDown();
	}

	/**
	 * Insert a row using the production column shape.
	 *
	 * @param array $overrides Column overrides on top of sensible defaults.
	 * @return int Inserted row ID.
	 */
	protected function seed_row( array $overrides = array() ) {
		global $wpdb;
		$defaults = array(
			'cct_status'       => 'publish',
			'context_id'       => 'ctx_' . wp_generate_password( 8, false ),
			'agent_id'         => '42',
			'memory_tier'      => 'semantic',
			'context_type'     => 'fact',
			'wing'             => '',
			'room'             => '',
			'title'            => 'Test memory',
			'content'          => 'The capital of France is Paris.',
			'tags'             => wp_json_encode( array( 'geography', 'europe' ) ),
			'importance'       => 'medium',
			'verbatim'         => 0,
			'transaction_time' => '2026-04-01 12:00:00',
			'valid_from'       => '2026-04-01 12:00:00',
			'valid_until'      => '2099-01-01 00:00:00',
			'expires_at'       => '2099-01-01 00:00:00',
			'source'           => 'store_agent_context',
			'metadata'         => '',
		);
		$row      = array_merge( $defaults, $overrides );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $this->table, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * The filter callback must return rows in the recall_memory candidate
	 * shape for the requested agent_id.
	 */
	public function test_filter_returns_seeded_rows_in_recall_shape() {
		$this->seed_row(
			array(
				'context_id' => 'ctx_recall_001',
				'agent_id'   => '99',
				'wing'       => 'patient/jane',
				'room'       => 'vitals',
				'content'    => 'BP 120/80.',
				'tags'       => wp_json_encode( array( 'vitals' ) ),
			)
		);
		$this->seed_row(
			array(
				'context_id' => 'ctx_other_agent',
				'agent_id'   => '7',
			)
		);

		$candidates = apply_filters(
			'wp_mcp_ai_recall_memory_candidates',
			array(),
			array(
				'agent_id' => '99',
				'wing'     => 'patient/jane',
			)
		);

		$this->assertIsArray( $candidates );
		$this->assertCount( 1, $candidates, 'Only the agent_id=99 row should be returned.' );

		$rec = $candidates[0];
		$this->assertSame( 'ctx_recall_001', $rec['context_id'] );
		$this->assertSame( '99', $rec['agent_id'] );
		$this->assertSame( 'patient/jane', $rec['wing'] );
		$this->assertSame( 'vitals', $rec['room'] );
		$this->assertSame( 'semantic', $rec['tier'] );
		$this->assertSame( 'BP 120/80.', $rec['content'] );
		$this->assertSame( array( 'vitals' ), $rec['tags'] );
		$this->assertSame( '2026-04-01 12:00:00', $rec['valid_from'] );
		$this->assertSame( '2099-01-01 00:00:00', $rec['valid_until'] );
	}

	/**
	 * Pre-existing filter candidates must be preserved alongside CCT rows
	 * (and de-duplicated on context_id).
	 */
	public function test_filter_merges_with_existing_candidates_and_dedupes() {
		$this->seed_row(
			array(
				'context_id' => 'ctx_shared',
				'agent_id'   => '5',
				'content'    => 'cct version',
			)
		);
		$this->seed_row(
			array(
				'context_id' => 'ctx_cct_only',
				'agent_id'   => '5',
			)
		);

		$preseed = array(
			array(
				'context_id' => 'ctx_test_only',
				'agent_id'   => '5',
				'wing'       => '',
				'tier'       => 'semantic',
				'content'    => 'test fixture',
				'importance' => 'medium',
			),
			// Same context_id as a CCT row — the pre-seeded record must win.
			array(
				'context_id' => 'ctx_shared',
				'agent_id'   => '5',
				'content'    => 'fixture version',
				'tier'       => 'semantic',
				'importance' => 'medium',
			),
		);

		$candidates = apply_filters(
			'wp_mcp_ai_recall_memory_candidates',
			$preseed,
			array( 'agent_id' => '5' )
		);

		$by_id = array();
		foreach ( $candidates as $c ) {
			$by_id[ $c['context_id'] ] = $c;
		}

		$this->assertArrayHasKey( 'ctx_test_only', $by_id );
		$this->assertArrayHasKey( 'ctx_cct_only', $by_id );
		$this->assertArrayHasKey( 'ctx_shared', $by_id );
		$this->assertSame( 'fixture version', $by_id['ctx_shared']['content'], 'Pre-seeded candidate must win on collision.' );
	}

	/**
	 * When the CCT table is missing entirely the reader must return the
	 * incoming candidate list unchanged — it should never raise.
	 */
	public function test_filter_returns_input_unchanged_when_table_missing() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );

		$preseed    = array(
			array(
				'context_id' => 'ctx_a',
				'agent_id'   => '1',
				'tier'       => 'semantic',
			),
		);
		$candidates = apply_filters(
			'wp_mcp_ai_recall_memory_candidates',
			$preseed,
			array( 'agent_id' => '1' )
		);
		$this->assertSame( $preseed, $candidates );
	}

	/**
	 * The transient-shaped helper must return records in the
	 * `retrieve_agent_memory` format.
	 */
	public function test_transient_shaped_helper_returns_data_subarray() {
		$this->seed_row(
			array(
				'context_id' => 'ctx_retrieve_001',
				'agent_id'   => '11',
				'title'      => 'Hello',
				'content'    => 'World',
				'tags'       => wp_json_encode( array( 'greeting' ) ),
				'importance' => 'high',
			)
		);

		$records = WP_MCP_AI_Agent_Memory_CCT_Reader::get_transient_shaped_records_for_agent( '11', 10 );

		$this->assertCount( 1, $records );
		$this->assertSame( 'ctx_retrieve_001', $records[0]['context_id'] );
		$this->assertSame( 'fact', $records[0]['context_type'] );
		$this->assertSame( 'Hello', $records[0]['data']['title'] );
		$this->assertSame( 'World', $records[0]['data']['content'] );
		$this->assertSame( array( 'greeting' ), $records[0]['data']['tags'] );
		$this->assertSame( 'high', $records[0]['data']['importance'] );
	}

	/**
	 * Trashed CCT rows must not be returned to recall.
	 */
	public function test_trashed_rows_are_excluded() {
		$this->seed_row(
			array(
				'context_id' => 'ctx_live',
				'agent_id'   => '88',
			)
		);
		$this->seed_row(
			array(
				'context_id' => 'ctx_trashed',
				'agent_id'   => '88',
				'cct_status' => 'trash',
			)
		);

		$candidates = apply_filters(
			'wp_mcp_ai_recall_memory_candidates',
			array(),
			array( 'agent_id' => '88' )
		);

		$ids = array_map(
			static function ( $c ) {
				return $c['context_id'];
			},
			$candidates
		);
		$this->assertContains( 'ctx_live', $ids );
		$this->assertNotContains( 'ctx_trashed', $ids );
	}
}
