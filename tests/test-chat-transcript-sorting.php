<?php
/**
 * Tests for chat transcript sorting to ensure most recent conversations appear first.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Chat_Transcript_Sorting_Test extends WP_UnitTestCase {
	/**
	 * Administrator user ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Sorting Test Assistant',
			)
		);

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that conversations are sorted by most recent activity (cct_created timestamp).
	 *
	 * This test verifies that when retrieving transcript sessions:
	 * 1. The most recent conversation appears first (top of page 1)
	 * 2. The oldest conversation appears last (bottom of last page)
	 * 3. Sorting is applied globally across all conversations before pagination
	 */
	public function test_conversations_sorted_by_most_recent_first() {
		global $wpdb;

		// Skip if JetEngine CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'JetEngine CCT is not available' );
			return;
		}

		$repository = wp_mcp_ai_get_transcript_repository();
		$table      = $repository->get_table_name();

		// Skip if table doesn't exist.
		if ( ! $repository->table_exists() ) {
			$this->markTestSkipped( 'Transcript table does not exist' );
			return;
		}

		// Clean up any existing test data.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND session_key LIKE %s",
				$this->admin_id,
				'test_sort_%'
			)
		);

		// Insert 5 conversations with different timestamps.
		// We'll use cct_created as the primary timestamp for sorting.
		$base_time = strtotime( '2024-01-01 12:00:00' );

		$test_sessions = array(
			// Session 3 - Most recent (should be first).
			array(
				'session_key'           => 'test_sort_session_3',
				'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time + 7200 ), // +2 hours.
				'user_id'         => $this->admin_id,
				'assistant_id'          => $this->assistant_id,
				'assistant_model'       => 'gpt-4',
				'request_started_at'    => $base_time + 7200,
				'response_completed_at' => $base_time + 7210,
				'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test 3' ) ) ) ),
				'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Response 3' ) ) ) ) ),
			),
			// Session 1 - Second most recent.
			array(
				'session_key'           => 'test_sort_session_1',
				'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time + 3600 ), // +1 hour.
				'user_id'         => $this->admin_id,
				'assistant_id'          => $this->assistant_id,
				'assistant_model'       => 'gpt-4',
				'request_started_at'    => $base_time + 3600,
				'response_completed_at' => $base_time + 3610,
				'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test 1' ) ) ) ),
				'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Response 1' ) ) ) ) ),
			),
			// Session 4 - Middle.
			array(
				'session_key'           => 'test_sort_session_4',
				'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time + 1800 ), // +30 minutes.
				'user_id'         => $this->admin_id,
				'assistant_id'          => $this->assistant_id,
				'assistant_model'       => 'gpt-4',
				'request_started_at'    => $base_time + 1800,
				'response_completed_at' => $base_time + 1810,
				'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test 4' ) ) ) ),
				'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Response 4' ) ) ) ) ),
			),
			// Session 2 - Second oldest.
			array(
				'session_key'           => 'test_sort_session_2',
				'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time + 900 ), // +15 minutes.
				'user_id'         => $this->admin_id,
				'assistant_id'          => $this->assistant_id,
				'assistant_model'       => 'gpt-4',
				'request_started_at'    => $base_time + 900,
				'response_completed_at' => $base_time + 910,
				'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test 2' ) ) ) ),
				'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Response 2' ) ) ) ) ),
			),
			// Session 5 - Oldest (should be last).
			array(
				'session_key'           => 'test_sort_session_5',
				'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time ), // Base time (oldest).
				'user_id'         => $this->admin_id,
				'assistant_id'          => $this->assistant_id,
				'assistant_model'       => 'gpt-4',
				'request_started_at'    => $base_time,
				'response_completed_at' => $base_time + 10,
				'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Test 5' ) ) ) ),
				'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'Response 5' ) ) ) ) ),
			),
		);

		foreach ( $test_sessions as $session ) {
			$wpdb->insert( $table, $session );
		}

		// Retrieve sessions using the repository.
		$result = $repository->get_sessions( $this->admin_id, 10, 1, $this->assistant_id );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'items', $result, 'Result should have items key' );
		$this->assertArrayHasKey( 'total', $result, 'Result should have total key' );

		$sessions = $result['items'];

		$this->assertCount( 5, $sessions, 'Should retrieve 5 sessions' );

		// Verify the order: Most recent first, oldest last.
		$this->assertEquals( 'test_sort_session_3', $sessions[0]['session_key'], 'First session should be the most recent (session_3)' );
		$this->assertEquals( 'test_sort_session_1', $sessions[1]['session_key'], 'Second session should be session_1' );
		$this->assertEquals( 'test_sort_session_4', $sessions[2]['session_key'], 'Third session should be session_4' );
		$this->assertEquals( 'test_sort_session_2', $sessions[3]['session_key'], 'Fourth session should be session_2' );
		$this->assertEquals( 'test_sort_session_5', $sessions[4]['session_key'], 'Last session should be the oldest (session_5)' );

		// Clean up test data.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND session_key LIKE %s",
				$this->admin_id,
				'test_sort_%'
			)
		);
	}

	/**
	 * Test that pagination maintains correct sort order across pages.
	 *
	 * This verifies that when conversations are split across multiple pages,
	 * the global sort order is maintained (not sorted per-page).
	 */
	public function test_pagination_maintains_global_sort_order() {
		global $wpdb;

		// Skip if JetEngine CCT is not available.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			$this->markTestSkipped( 'JetEngine CCT is not available' );
			return;
		}

		$repository = wp_mcp_ai_get_transcript_repository();
		$table      = $repository->get_table_name();

		// Skip if table doesn't exist.
		if ( ! $repository->table_exists() ) {
			$this->markTestSkipped( 'Transcript table does not exist' );
			return;
		}

		// Clean up any existing test data.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND session_key LIKE %s",
				$this->admin_id,
				'test_page_%'
			)
		);

		// Insert 25 conversations to test pagination (e.g., 3 pages with 10 per page).
		$base_time = strtotime( '2024-01-01 12:00:00' );

		for ( $i = 1; $i <= 25; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'session_key'           => 'test_page_session_' . $i,
					'cct_created'           => gmdate( 'Y-m-d H:i:s', $base_time + ( $i * 60 ) ), // Each session 1 minute apart.
					'user_id'         => $this->admin_id,
					'assistant_id'          => $this->assistant_id,
					'assistant_model'       => 'gpt-4',
					'request_started_at'    => $base_time + ( $i * 60 ),
					'response_completed_at' => $base_time + ( $i * 60 ) + 10,
					'request_payload'       => wp_json_encode( array( 'messages' => array( array( 'role' => 'user', 'content' => "Test $i" ) ) ) ),
					'response_payload'      => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => "Response $i" ) ) ) ) ),
				)
			);
		}

		// Get page 1 (should have sessions 25-16, most recent first).
		$page1 = $repository->get_sessions( $this->admin_id, 10, 1, $this->assistant_id );

		// Get page 2 (should have sessions 15-6).
		$page2 = $repository->get_sessions( $this->admin_id, 10, 2, $this->assistant_id );

		// Get page 3 (should have sessions 5-1, oldest last).
		$page3 = $repository->get_sessions( $this->admin_id, 10, 3, $this->assistant_id );

		// Verify page 1 has the most recent conversations.
		$this->assertEquals( 'test_page_session_25', $page1['items'][0]['session_key'], 'Page 1 should start with the most recent session (25)' );
		$this->assertEquals( 'test_page_session_16', $page1['items'][9]['session_key'], 'Page 1 should end with session 16' );

		// Verify page 2 has the middle conversations.
		$this->assertEquals( 'test_page_session_15', $page2['items'][0]['session_key'], 'Page 2 should start with session 15' );
		$this->assertEquals( 'test_page_session_6', $page2['items'][9]['session_key'], 'Page 2 should end with session 6' );

		// Verify page 3 has the oldest conversations.
		$this->assertEquals( 'test_page_session_5', $page3['items'][0]['session_key'], 'Page 3 should start with session 5' );
		$this->assertEquals( 'test_page_session_1', $page3['items'][4]['session_key'], 'Page 3 should end with the oldest session (1)' );

		// Clean up test data.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND session_key LIKE %s",
				$this->admin_id,
				'test_page_%'
			)
		);
	}
}
