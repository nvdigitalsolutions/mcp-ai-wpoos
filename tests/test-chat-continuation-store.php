<?php
/**
 * Tests for the Chat Continuation Store.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * @group continuation
 */
class Test_Chat_Continuation_Store extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Chat_Continuation_Store::reset_for_tests();
	}

	public function tearDown(): void {
		WP_MCP_AI_Chat_Continuation_Store::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * Verify a normal store / get round-trip.
	 */
	public function test_store_and_get_round_trip() {
		$job_id  = 'job_test_' . wp_generate_uuid4();
		$session = 'sess_' . wp_generate_uuid4();

		$result = WP_MCP_AI_Chat_Continuation_Store::store(
			$job_id,
			array(
				'chat_session_id' => $session,
				'assistant_id'    => 42,
				'user_id'         => 7,
				'tool_call_id'    => 'call_abc',
				'tool_name'       => 'generate_veo_video',
				'provider'        => 'openai',
				'model'           => 'gpt-4o-mini',
				'options'         => array( 'temperature' => 0.7 ),
				'messages'        => array(
					array( 'role' => 'user', 'content' => 'Make a video.' ),
					array(
						'role'       => 'assistant',
						'tool_calls' => array( array( 'id' => 'call_abc' ) ),
					),
				),
			)
		);
		$this->assertTrue( $result );

		$row = WP_MCP_AI_Chat_Continuation_Store::get( $job_id );
		$this->assertIsArray( $row );
		$this->assertEquals( $job_id, $row['job_id'] );
		$this->assertEquals( $session, $row['chat_session_id'] );
		$this->assertEquals( 42, $row['assistant_id'] );
		$this->assertEquals( 7, $row['user_id'] );
		$this->assertEquals( 'call_abc', $row['tool_call_id'] );
		$this->assertEquals( 'generate_veo_video', $row['tool_name'] );
		$this->assertCount( 2, $row['messages'] );
	}

	/**
	 * Missing chat_session_id should be rejected.
	 */
	public function test_store_rejects_missing_session_id() {
		$result = WP_MCP_AI_Chat_Continuation_Store::store(
			'job_x',
			array(
				'messages' => array(),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_chat_session_id', $result->get_error_code() );
	}

	/**
	 * Empty job_id should be rejected.
	 */
	public function test_store_rejects_empty_job_id() {
		$result = WP_MCP_AI_Chat_Continuation_Store::store(
			'',
			array(
				'chat_session_id' => 'sess_x',
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_job_id', $result->get_error_code() );
	}

	/**
	 * The secondary session index should return all job_ids for a session.
	 */
	public function test_session_index_lookup() {
		$session = 'sess_' . wp_generate_uuid4();

		WP_MCP_AI_Chat_Continuation_Store::store(
			'job_a',
			array( 'chat_session_id' => $session, 'messages' => array() )
		);
		WP_MCP_AI_Chat_Continuation_Store::store(
			'job_b',
			array( 'chat_session_id' => $session, 'messages' => array() )
		);

		$jobs = WP_MCP_AI_Chat_Continuation_Store::get_jobs_for_session( $session );
		$this->assertEqualSets( array( 'job_a', 'job_b' ), $jobs );
	}

	/**
	 * delete() should remove the row and clean the indices.
	 */
	public function test_delete_removes_row_and_index() {
		$session = 'sess_' . wp_generate_uuid4();
		WP_MCP_AI_Chat_Continuation_Store::store(
			'job_del',
			array( 'chat_session_id' => $session, 'messages' => array() )
		);

		$this->assertNotNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_del' ) );

		WP_MCP_AI_Chat_Continuation_Store::delete( 'job_del' );

		$this->assertNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_del' ) );
		$this->assertEquals(
			array(),
			WP_MCP_AI_Chat_Continuation_Store::get_jobs_for_session( $session )
		);
	}

	/**
	 * The processing-lock should be exclusive.
	 */
	public function test_processing_lock_is_exclusive() {
		WP_MCP_AI_Chat_Continuation_Store::store(
			'job_lock',
			array(
				'chat_session_id' => 'sess_lock',
				'messages'        => array(),
			)
		);

		$this->assertTrue( WP_MCP_AI_Chat_Continuation_Store::acquire_processing_lock( 'job_lock' ) );
		$this->assertFalse( WP_MCP_AI_Chat_Continuation_Store::acquire_processing_lock( 'job_lock' ) );

		WP_MCP_AI_Chat_Continuation_Store::release_processing_lock( 'job_lock' );

		$this->assertTrue( WP_MCP_AI_Chat_Continuation_Store::acquire_processing_lock( 'job_lock' ) );
	}

	/**
	 * The global LRU cap evicts oldest rows.
	 */
	public function test_global_lru_evicts_oldest() {
		add_filter(
			'wp_mcp_ai_chat_continuation_max_total',
			function () {
				return 3;
			}
		);

		for ( $i = 0; $i < 5; $i++ ) {
			WP_MCP_AI_Chat_Continuation_Store::store(
				'job_lru_' . $i,
				array(
					'chat_session_id' => 'sess_lru',
					'messages'        => array(),
				)
			);
		}

		// First two should have been evicted; remaining three (2, 3, 4) kept.
		$this->assertNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_lru_0' ) );
		$this->assertNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_lru_1' ) );
		$this->assertNotNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_lru_2' ) );
		$this->assertNotNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_lru_3' ) );
		$this->assertNotNull( WP_MCP_AI_Chat_Continuation_Store::get( 'job_lru_4' ) );

		remove_all_filters( 'wp_mcp_ai_chat_continuation_max_total' );
	}

	/**
	 * The messages-size guard rejects oversized payloads.
	 */
	public function test_oversize_messages_rejected() {
		add_filter(
			'wp_mcp_ai_chat_continuation_max_messages_size',
			function () {
				return 200;
			}
		);

		$huge = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$huge[] = array( 'role' => 'user', 'content' => str_repeat( 'A', 50 ) );
		}

		$result = WP_MCP_AI_Chat_Continuation_Store::store(
			'job_big',
			array(
				'chat_session_id' => 'sess_big',
				'messages'        => $huge,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'continuation_messages_too_large', $result->get_error_code() );

		remove_all_filters( 'wp_mcp_ai_chat_continuation_max_messages_size' );
	}

	/**
	 * generate_session_id() produces non-empty IDs and runs the filter.
	 */
	public function test_generate_session_id_runs_filter() {
		$called = false;
		add_filter(
			'wp_mcp_ai_chat_session_id_generated',
			function ( $id, $context ) use ( &$called ) {
				$called = true;
				$this->assertIsString( $id );
				return $id;
			},
			10,
			2
		);

		$id = WP_MCP_AI_Chat_Continuation_Store::generate_session_id( array() );
		$this->assertNotEmpty( $id );
		$this->assertTrue( $called );

		remove_all_filters( 'wp_mcp_ai_chat_session_id_generated' );
	}

	/**
	 * The `wp_mcp_ai_chat_continuation_stored` action should fire.
	 */
	public function test_stored_action_fires() {
		$captured = null;
		add_action(
			'wp_mcp_ai_chat_continuation_stored',
			function ( $job_id, $payload ) use ( &$captured ) {
				$captured = array( 'job_id' => $job_id, 'payload' => $payload );
			},
			10,
			2
		);

		WP_MCP_AI_Chat_Continuation_Store::store(
			'job_action',
			array(
				'chat_session_id' => 'sess_action',
				'messages'        => array(),
			)
		);

		$this->assertIsArray( $captured );
		$this->assertEquals( 'job_action', $captured['job_id'] );
		$this->assertEquals( 'sess_action', $captured['payload']['chat_session_id'] );

		remove_all_actions( 'wp_mcp_ai_chat_continuation_stored' );
	}

	/**
	 * Per-session cap evicts oldest job_ids when exceeded.
	 */
	public function test_per_session_cap_evicts_oldest() {
		add_filter(
			'wp_mcp_ai_chat_continuation_max_per_session',
			function () {
				return 2;
			}
		);

		$session = 'sess_cap';
		WP_MCP_AI_Chat_Continuation_Store::store( 'job_p_1', array( 'chat_session_id' => $session, 'messages' => array() ) );
		WP_MCP_AI_Chat_Continuation_Store::store( 'job_p_2', array( 'chat_session_id' => $session, 'messages' => array() ) );
		WP_MCP_AI_Chat_Continuation_Store::store( 'job_p_3', array( 'chat_session_id' => $session, 'messages' => array() ) );

		$remaining = WP_MCP_AI_Chat_Continuation_Store::get_jobs_for_session( $session );
		$this->assertCount( 2, $remaining );
		$this->assertContains( 'job_p_3', $remaining );
		$this->assertNotContains( 'job_p_1', $remaining );

		remove_all_filters( 'wp_mcp_ai_chat_continuation_max_per_session' );
	}
}
