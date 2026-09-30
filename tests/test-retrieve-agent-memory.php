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

	/**
	 * Create a real user with the given role and return its ID.
	 *
	 * @param string $role WordPress role slug.
	 * @return int User ID.
	 */
	private function create_user( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Seed one memory record + index entry for an agent via the transient
	 * layer, so retrieval tests exercise the real read path.
	 *
	 * @param int|string $agent_id Agent id.
	 * @param string     $title    Record title.
	 * @param string     $content  Record content.
	 * @param array      $extra    Extra top-level record keys.
	 * @return string Context id.
	 */
	private function seed_memory( $agent_id, $title, $content, array $extra = array() ) {
		$context_id = 'ctx_' . wp_generate_password( 12, false );

		$record = array_merge(
			array(
				'context_id'   => $context_id,
				'agent_id'     => $agent_id,
				'context_type' => 'fact',
				'data'         => array(
					'title'      => $title,
					'content'    => $content,
					'importance' => 'medium',
					'tags'       => array(),
				),
				'stored_at'    => current_time( 'mysql' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				'wing'         => '',
				'room'         => '',
				'verbatim'     => false,
			),
			$extra
		);

		set_transient( 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id ), $record, HOUR_IN_SECONDS );

		$index = get_transient( 'mcp_ai_ctx_index_' . md5( (string) $agent_id ) );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index[ $context_id ] = array(
			'type'       => 'fact',
			'title'      => $title,
			'stored_at'  => current_time( 'mysql' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		);
		set_transient( 'mcp_ai_ctx_index_' . md5( (string) $agent_id ), $index, HOUR_IN_SECONDS );

		return $context_id;
	}

	/**
	 * When agent_id is omitted the tool resolves the caller's own identity
	 * from the execution context and returns that agent's contexts.
	 */
	public function test_omitted_agent_id_resolves_own_identity_from_context() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );
		$this->seed_memory( $own, 'Own fact', 'Memory belonging to the caller.' );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'limit' => 10 ),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $own, $result['resolved_agent_id'] );
		$this->assertSame( 'context', $result['resolution_source'] );
		$this->assertNotEmpty( $result['contexts'] );
		$this->assertSame( 'Own fact', $result['contexts'][0]['title'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $own ) );
	}

	/**
	 * Explicit agent_id of another agent without manage_options → 403, no data.
	 */
	public function test_cross_agent_read_denied_without_manage_options() {
		$subscriber = $this->create_user( 'subscriber' );
		$own        = wp_rand( 3000000, 9000000 );
		$other      = wp_rand( 3000000, 9000000 );
		$this->seed_memory( $other, 'Secret', 'Another agent\'s memory.' );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'agent_id' => $other ),
			array(
				'user_id'      => $subscriber,
				'assistant_id' => $own,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_scope_denied', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $other ) );
	}

	/**
	 * Explicit agent_id of another agent with manage_options → allowed.
	 */
	public function test_cross_agent_read_allowed_for_administrator() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );
		$other = wp_rand( 3000000, 9000000 );
		$this->seed_memory( $other, 'Shared', 'Readable by administrators.' );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'agent_id' => $other ),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $other, $result['resolved_agent_id'] );
		$this->assertSame( 'parameter', $result['resolution_source'] );
		$this->assertNotEmpty( $result['contexts'] );
		$this->assertSame( 'Shared', $result['contexts'][0]['title'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $other ) );
	}

	/**
	 * Neither an argument nor a context identity → loud 400, never a guess.
	 */
	public function test_missing_identity_errors_400() {
		$admin = $this->create_user( 'administrator' );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'limit' => 5 ),
			array( 'user_id' => $admin )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_ai_memory_no_agent', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Write-then-read round trip with agent_id omitted on both sides must
	 * agree on the same scope and find the record.
	 */
	public function test_round_trip_with_omitted_agent_id() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );

		$store  = new WP_MCP_AI_Tool_Store_Agent_Context();
		$stored = $store->execute(
			array(
				'context_type' => 'decision',
				'context_data' => array(
					'title'   => 'Round trip',
					'content' => 'Stored without an explicit agent id.',
				),
			),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertIsArray( $stored );
		$this->assertTrue( ! empty( $stored['success'] ) );
		$this->assertSame( $own, $stored['agent_id'] );
		$this->assertSame( 'context', $stored['resolution_source'] );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array(),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertSame( $own, $result['resolved_agent_id'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( $stored['context_id'], $result['contexts'][0]['context_id'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $own ) );
		delete_transient( 'mcp_ai_ctx_' . md5( $own . '_' . $stored['context_id'] ) );
	}

	/**
	 * Results carry expiry signalling so near-expiry records are visible
	 * instead of silently dropping out of retrieval.
	 */
	public function test_results_carry_expiry_signalling() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );
		$this->seed_memory( $own, 'Expiring', 'A record near its expiry.' );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'agent_id' => $own ),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$first = $result['contexts'][0];
		$this->assertArrayHasKey( 'expires_in', $first );
		$this->assertIsInt( $first['expires_in'] );
		$this->assertGreaterThan( 0, $first['expires_in'] );
		$this->assertLessThanOrEqual( 3600, $first['expires_in'] );
		$this->assertTrue( $first['expires_soon'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $own ) );
	}

	/**
	 * A record stored with credential-like content is flagged on store and
	 * the flag survives retrieval. Uses the plugin's own `cred_….<secret>`
	 * token format — the one credential shape the privacy-filter redaction
	 * pipeline does not strip, so it reaches the scan.
	 */
	public function test_sensitive_pattern_flag_round_trip() {
		$admin = $this->create_user( 'administrator' );
		$own   = wp_rand( 3000000, 9000000 );

		$store  = new WP_MCP_AI_Tool_Store_Agent_Context();
		$stored = $store->execute(
			array(
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Assistant token',
					'content' => 'The token is cred_ab12cd34ef56.AbcDefGhIjKlMnOpQrStUvWxYz1234.',
				),
			),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$this->assertTrue( ! empty( $stored['success'] ) );
		$this->assertTrue( $stored['contains_sensitive'] );
		$this->assertContains( 'credential_token', $stored['sensitive_patterns'] );

		$tool   = new WP_MCP_AI_Tool_Retrieve_Agent_Memory();
		$result = $tool->execute(
			array( 'agent_id' => $own ),
			array(
				'user_id'      => $admin,
				'assistant_id' => $own,
			)
		);

		$first = $result['contexts'][0];
		$this->assertTrue( $first['contains_sensitive'] );
		$this->assertContains( 'credential_token', $first['sensitive_patterns'] );

		delete_transient( 'mcp_ai_ctx_index_' . md5( (string) $own ) );
		delete_transient( 'mcp_ai_ctx_' . md5( $own . '_' . $stored['context_id'] ) );
	}
}
