<?php
/**
 * Tests for {@see WP_MCP_AI_Tool_Retrieve_Agent_Memory}.
 *
 * Focused on the CCT fallback path: when the per-agent transient index is
 * empty (e.g. after a Redis flush) the tool must consult the durable JetEngine
 * CCT mirror via {@see WP_MCP_AI_Agent_Memory_CCT_Reader} instead of returning
 * an empty contexts array.
 *
 * @package WP_MCP_AI
 */

/**
 * Fallback behaviour for the retrieve_agent_memory tool.
 */
class WP_MCP_AI_Tool_Retrieve_Agent_Memory_Test extends WP_UnitTestCase {

	/**
	 * Temp CCT table.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Provision the same table shape used in production.
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
	 * Drop the temp table.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test cleanup
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
		parent::tearDown();
	}

	/**
	 * Empty transient index + non-empty CCT must return CCT-backed records.
	 */
	public function test_search_falls_back_to_cct_when_transient_empty() {
		global $wpdb;
		$agent_id = 'agent_evicted_' . wp_generate_password( 6, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table,
			array(
				'cct_status'       => 'publish',
				'context_id'       => 'ctx_fallback_001',
				'agent_id'         => $agent_id,
				'memory_tier'      => 'semantic',
				'context_type'     => 'fact',
				'title'            => 'Resilient',
				'content'          => 'I survived an object-cache flush.',
				'tags'             => wp_json_encode( array( 'durability' ) ),
				'importance'       => 'high',
				'transaction_time' => '2026-04-01 00:00:00',
				'valid_from'       => '2026-04-01 00:00:00',
				'valid_until'      => '2099-01-01 00:00:00',
				'expires_at'       => '2099-01-01 00:00:00',
				'source'           => 'store_agent_context',
			)
		);

		// Ensure transient index is empty for this agent.
		delete_transient( 'mcp_ai_ctx_index_' . md5( $agent_id ) );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array(
				'agent_id' => $agent_id,
				'limit'    => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( ! empty( $result['success'] ), 'Tool must return success=true.' );
		$this->assertArrayHasKey( 'contexts', $result );
		$this->assertNotEmpty( $result['contexts'], 'CCT fallback should populate contexts when transient index is empty.' );
		$this->assertSame( 'ctx_fallback_001', $result['contexts'][0]['context_id'] );
		$this->assertSame( 'Resilient', $result['contexts'][0]['title'] );
		$this->assertSame( 'I survived an object-cache flush.', $result['contexts'][0]['content'] );
		$this->assertSame( 'high', $result['contexts'][0]['importance'] );
	}

	/**
	 * Empty transient index + empty CCT must continue returning the legacy
	 * "no contexts found" envelope so existing callers stay unaffected.
	 */
	public function test_search_returns_empty_envelope_when_both_layers_empty() {
		$agent_id = 'agent_empty_' . wp_generate_password( 6, false );
		delete_transient( 'mcp_ai_ctx_index_' . md5( $agent_id ) );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute( array( 'agent_id' => $agent_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( ! empty( $result['success'] ) );
		$this->assertSame( array(), $result['contexts'] );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * Filters applied by the caller (e.g. wing) must still be honoured on
	 * the CCT fallback path.
	 */
	public function test_search_applies_wing_filter_on_cct_fallback() {
		global $wpdb;
		$agent_id = 'agent_wingfilter_' . wp_generate_password( 6, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table,
			array(
				'cct_status'       => 'publish',
				'context_id'       => 'ctx_wing_a',
				'agent_id'         => $agent_id,
				'memory_tier'      => 'semantic',
				'context_type'     => 'fact',
				'wing'             => 'wing-a',
				'title'            => 'A',
				'content'          => 'aaa',
				'importance'       => 'medium',
				'transaction_time' => '2026-04-01 00:00:00',
				'valid_from'       => '2026-04-01 00:00:00',
				'valid_until'      => '2099-01-01 00:00:00',
				'expires_at'       => '2099-01-01 00:00:00',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table,
			array(
				'cct_status'       => 'publish',
				'context_id'       => 'ctx_wing_b',
				'agent_id'         => $agent_id,
				'memory_tier'      => 'semantic',
				'context_type'     => 'fact',
				'wing'             => 'wing-b',
				'title'            => 'B',
				'content'          => 'bbb',
				'importance'       => 'medium',
				'transaction_time' => '2026-04-02 00:00:00',
				'valid_from'       => '2026-04-02 00:00:00',
				'valid_until'      => '2099-01-01 00:00:00',
				'expires_at'       => '2099-01-01 00:00:00',
			)
		);

		delete_transient( 'mcp_ai_ctx_index_' . md5( $agent_id ) );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array(
				'agent_id' => $agent_id,
				'filters'  => array( 'wing' => 'wing-b' ),
				'limit'    => 10,
			)
		);

		$ids = array_map(
			static function ( $c ) {
				return $c['context_id'];
			},
			$result['contexts']
		);
		$this->assertContains( 'ctx_wing_b', $ids );
		$this->assertNotContains( 'ctx_wing_a', $ids );
	}
}
