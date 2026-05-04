<?php
/**
 * Tests for the `transcripts` source on the mine_agent_memory tool.
 *
 * Step 1 of the "Mine Transcripts → Memories" feature: lets power users
 * and agents extract memories from existing chat transcript sessions
 * without requiring a live JetEngine CCT in the test environment by
 * routing the session and message lookups through filter hooks.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for mine_agent_memory's `transcripts` source.
 */
class Test_Mine_Transcripts_Source extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Mock session list returned by the `wp_mcp_ai_mine_transcripts_sessions` filter.
	 *
	 * @var array
	 */
	private $mock_sessions = array();

	/**
	 * Mock messages keyed by session_key, returned by
	 * `wp_mcp_ai_mine_transcripts_session_messages`.
	 *
	 * @var array<string,array>
	 */
	private $mock_messages = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry      = WP_MCP_AI_Tool_Registry::get_instance();
		$this->mock_sessions = array();
		$this->mock_messages = array();

		add_filter(
			'wp_mcp_ai_mine_transcripts_sessions',
			array( $this, 'inject_sessions' ),
			10,
			2
		);
		add_filter(
			'wp_mcp_ai_mine_transcripts_session_messages',
			array( $this, 'inject_messages' ),
			10,
			3
		);
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_mine_transcripts_sessions', array( $this, 'inject_sessions' ), 10 );
		remove_filter( 'wp_mcp_ai_mine_transcripts_session_messages', array( $this, 'inject_messages' ), 10 );

		global $wpdb;
		// Clean transient context store between tests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%'
			)
		);

		parent::tearDown();
	}

	/**
	 * Filter callback: inject mock sessions.
	 *
	 * @param array $sessions Existing sessions.
	 * @param array $args     Resolved query args.
	 * @return array
	 */
	public function inject_sessions( $sessions, $args ) {
		unset( $sessions, $args );
		return $this->mock_sessions;
	}

	/**
	 * Filter callback: inject mock messages keyed by session_key.
	 *
	 * @param array  $messages    Existing messages.
	 * @param string $session_key Session key.
	 * @param array  $args        Resolved query args.
	 * @return array
	 */
	public function inject_messages( $messages, $session_key, $args ) {
		unset( $messages, $args );
		return isset( $this->mock_messages[ $session_key ] ) ? $this->mock_messages[ $session_key ] : array();
	}

	/**
	 * Build a default session+messages fixture.
	 *
	 * @param string $key   Session key.
	 * @param int    $turns Number of turns.
	 */
	private function seed_session( $key, $turns = 2 ) {
		$this->mock_sessions[] = array(
			'session_key'  => $key,
			'assistant_id' => '42',
			'turn_count'   => $turns,
			'started_at'   => '2026-01-01 00:00:00',
			'last_created' => '2026-01-01 00:10:00',
		);
		$messages              = array();
		for ( $i = 0; $i < $turns; $i++ ) {
			$messages[] = array(
				'role'          => 'user',
				'content'       => 'Hello turn ' . $i,
				'message_index' => $i * 2,
			);
			$messages[] = array(
				'role'          => 'assistant',
				'content'       => 'Hi there from turn ' . $i,
				'message_index' => $i * 2 + 1,
			);
		}
		$this->mock_messages[ $key ] = $messages;
	}

	/**
	 * Schema should expose `transcripts` as a valid source enum value.
	 */
	public function test_schema_advertises_transcripts_source() {
		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'source', $schema['properties'] );
		$this->assertContains( 'transcripts', $schema['properties']['source']['enum'] );

		$this->assertArrayHasKey( 'transcript_query', $schema['properties'] );
		$transcript_query = $schema['properties']['transcript_query'];
		$this->assertSame( 'object', $transcript_query['type'] );
		foreach ( array( 'assistant_id', 'user_id', 'since', 'until', 'session_keys', 'min_messages', 'only_unextracted', 'posts_per_page' ) as $key ) {
			$this->assertArrayHasKey( $key, $transcript_query['properties'], 'Missing transcript_query field: ' . $key );
		}
	}

	/**
	 * Mining from a single session should produce a verbatim memory record
	 * carrying full provenance metadata.
	 */
	public function test_mine_from_transcripts_creates_verbatim_record_with_provenance() {
		$this->seed_session( 'sess_AAA', 2 );

		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id'         => 7001,
				'source'           => 'transcripts',
				'wing'             => 'chat/assistant-42',
				'transcript_query' => array(
					'assistant_id' => 42,
					'min_messages' => 1,
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'transcripts', $result['source'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute( array( 'agent_id' => 7001 ), array() );
		$this->assertSame( 1, $lookup['count'] );

		$ctx = $lookup['contexts'][0];
		$this->assertSame( 'chat/assistant-42', $ctx['wing'] );
		$this->assertTrue( $ctx['verbatim'] );
		$this->assertContains( 'transcript', $ctx['tags'] );
		$this->assertContains( 'session:sess_AAA', $ctx['tags'] );
		$this->assertSame( 'sess_AAA', $ctx['metadata']['transcript_session_key'] );
		$this->assertSame( '42', $ctx['metadata']['transcript_assistant_id'] );
		$this->assertNotEmpty( $ctx['metadata']['transcript_content_hash'] );
		$this->assertSame( 64, strlen( $ctx['metadata']['transcript_content_hash'] ), 'sha256 hex hash should be 64 chars' );
		$this->assertSame( 0, $ctx['metadata']['transcript_message_range']['start'] );
		$this->assertSame( 3, $ctx['metadata']['transcript_message_range']['end'] );

		// Conversation rendering preserves both user and assistant turns.
		$this->assertStringContainsString( 'USER: Hello turn 0', $ctx['data']['content'] );
		$this->assertStringContainsString( 'ASSISTANT: Hi there from turn 0', $ctx['data']['content'] );
	}

	/**
	 * Re-running the mine against the same session must skip duplicates via
	 * the content-hash dedupe path (Mem0 / LangMem "memory upsert").
	 */
	public function test_mine_dedupes_by_content_hash_on_rerun() {
		$this->seed_session( 'sess_BBB', 1 );

		$tool = $this->registry->get_tool( 'mine_agent_memory' );

		$first = $tool->execute(
			array(
				'agent_id'         => 7002,
				'source'           => 'transcripts',
				'transcript_query' => array(
					'only_unextracted' => false,
				),
			),
			array()
		);
		$this->assertSame( 1, $first['count'] );

		$second = $tool->execute(
			array(
				'agent_id'         => 7002,
				'source'           => 'transcripts',
				'transcript_query' => array(
					'only_unextracted' => false,
				),
			),
			array()
		);
		$this->assertSame( 0, $second['count'], 'Duplicate hash must be skipped' );
		$this->assertSame( 1, $second['skipped'], 'Skipped count must surface in result' );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute( array( 'agent_id' => 7002 ), array() );
		$this->assertSame( 1, $lookup['count'], 'Only one memory record persists after rerun' );
	}

	/**
	 * `only_unextracted=true` (default) should skip sessions that already
	 * have any memory carrying the same transcript_session_key.
	 */
	public function test_only_unextracted_filter_skips_already_processed_sessions() {
		$this->seed_session( 'sess_CCC', 1 );

		$tool = $this->registry->get_tool( 'mine_agent_memory' );

		// First pass — writes one memory.
		$first = $tool->execute(
			array(
				'agent_id' => 7003,
				'source'   => 'transcripts',
			),
			array()
		);
		$this->assertSame( 1, $first['count'] );

		// Second pass with default only_unextracted=true should yield 0 items
		// because the session_key is now recorded on memory metadata.
		$second = $tool->execute(
			array(
				'agent_id' => 7003,
				'source'   => 'transcripts',
			),
			array()
		);
		$this->assertSame( 0, $second['count'] );
	}

	/**
	 * `min_messages` should drop sessions below the threshold.
	 */
	public function test_min_messages_filter_drops_short_sessions() {
		$this->mock_sessions[]             = array(
			'session_key'  => 'sess_short',
			'turn_count'   => 1,
			'last_created' => '2026-01-01 00:00:00',
		);
		$this->mock_messages['sess_short'] = array(
			array(
				'role'          => 'user',
				'content'       => 'one liner',
				'message_index' => 0,
			),
		);

		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id'         => 7004,
				'source'           => 'transcripts',
				'transcript_query' => array(
					'min_messages' => 5,
				),
			),
			array()
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * `dry_run` should preview without writing anything.
	 */
	public function test_dry_run_does_not_persist_transcript_records() {
		$this->seed_session( 'sess_DDD', 1 );

		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id'         => 7005,
				'source'           => 'transcripts',
				'dry_run'          => true,
				'transcript_query' => array(
					'only_unextracted' => false,
				),
			),
			array()
		);
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['count'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute( array( 'agent_id' => 7005 ), array() );
		$this->assertSame( 0, $lookup['count'], 'Dry run must not persist anything' );
	}

	/**
	 * `session_keys` filter should restrict to the named keys only.
	 */
	public function test_session_keys_filter_restricts_to_named_keys() {
		$this->seed_session( 'sess_keep', 1 );
		$this->seed_session( 'sess_drop', 1 );

		$tool   = $this->registry->get_tool( 'mine_agent_memory' );
		$result = $tool->execute(
			array(
				'agent_id'         => 7006,
				'source'           => 'transcripts',
				'transcript_query' => array(
					'session_keys' => array( 'sess_keep' ),
				),
			),
			array()
		);
		$this->assertSame( 1, $result['count'] );
		$this->assertContains( 'session:sess_keep', $result['mined'][0]['tags'] );
	}
}
