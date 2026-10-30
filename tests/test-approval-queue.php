<?php
/**
 * Tests for WP_MCP_AI_Approval_Queue.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

/**
 * HITL Approval Queue tests.
 */
class Test_Approval_Queue extends WP_UnitTestCase {

	/**
	 * Queue under test.
	 *
	 * @var WP_MCP_AI_Approval_Queue
	 */
	private $queue;

	/**
	 * Load the queue and register its CPT.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-approval-queue.php';
		WP_MCP_AI_Approval_Queue::register_cpt();
		$this->queue = WP_MCP_AI_Approval_Queue::get_instance();
	}

	// ── enqueue ───────────────────────────────────────────────────────────────

	/**
	 * Enqueue returns a new post ID for a valid request.
	 */
	public function test_enqueue_returns_post_id() {
		$user_id = $this->factory->user->create();
		$id      = $this->queue->enqueue(
			array(
				'tool'         => 'delete_post',
				'arguments'    => array( 'post_id' => 42 ),
				'assistant_id' => 1,
				'requester_id' => $user_id,
				'reason'       => 'Deleting post 42',
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * A missing tool slug is rejected.
	 */
	public function test_enqueue_missing_tool_returns_wp_error() {
		$result = $this->queue->enqueue( array( 'reason' => 'test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'approval_missing_tool', $result->get_error_code() );
	}

	/**
	 * Context and arguments round-trip through get().
	 */
	public function test_enqueue_stores_metadata() {
		$id = $this->queue->enqueue(
			array(
				'tool'      => 'my_tool',
				'arguments' => array( 'key' => 'value' ),
				'reason'    => 'testing',
			)
		);
		$this->assertIsInt( $id );

		$record = $this->queue->get( $id );
		$this->assertIsArray( $record );
		$this->assertSame( 'my_tool', $record['tool'] );
		$this->assertSame( 'testing', $record['reason'] );
		$this->assertSame( array( 'key' => 'value' ), $record['arguments'] );
		$this->assertSame( 'pending', $record['status'] );
	}

	/**
	 * Enqueue fires wp_mcp_ai_approval_queued.
	 */
	public function test_enqueue_fires_action() {
		$fired = null;
		add_action(
			'wp_mcp_ai_approval_queued',
			function ( $post_id ) use ( &$fired ) {
				$fired = $post_id; }
		);

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'test_tool',
				'reason' => 'hook test',
			)
		);
		$this->assertSame( $id, $fired );

		remove_all_actions( 'wp_mcp_ai_approval_queued' );
	}

	/**
	 * The TTL is floored at 60 seconds.
	 */
	public function test_ttl_minimum_clamped_to_60() {
		$id = $this->queue->enqueue(
			array(
				'tool'   => 'test',
				'reason' => 'ttl test',
				'ttl'    => 5,
			)
		);
		$this->assertIsInt( $id );
		$record = $this->queue->get( $id );
		$this->assertGreaterThanOrEqual( time() + 55, $record['expires_at'] );
	}

	// ── get ───────────────────────────────────────────────────────────────────

	/**
	 * An unknown ID returns null.
	 */
	public function test_get_nonexistent_returns_null() {
		$record = $this->queue->get( 999999 );
		$this->assertNull( $record );
	}

	// ── get_pending ───────────────────────────────────────────────────────────

	/**
	 * Queued items appear in the pending list.
	 */
	public function test_get_pending_lists_queued_items() {
		$this->queue->enqueue(
			array(
				'tool'   => 'tool_a',
				'reason' => 'test',
			)
		);
		$this->queue->enqueue(
			array(
				'tool'   => 'tool_b',
				'reason' => 'test',
			)
		);

		$items = $this->queue->get_pending();
		$this->assertGreaterThanOrEqual( 2, count( $items ) );
	}

	/**
	 * The assistant filter narrows the pending list.
	 */
	public function test_get_pending_filters_by_assistant() {
		$this->queue->enqueue(
			array(
				'tool'         => 'tool_a',
				'assistant_id' => 10,
				'reason'       => 'a',
			)
		);
		$this->queue->enqueue(
			array(
				'tool'         => 'tool_b',
				'assistant_id' => 20,
				'reason'       => 'b',
			)
		);

		$items = $this->queue->get_pending( array( 'assistant_id' => 10 ) );
		foreach ( $items as $item ) {
			$this->assertSame( 10, $item['assistant_id'] );
		}
	}

	/**
	 * Pagination: limit/offset window the queue without changing the depth.
	 */
	public function test_get_pending_supports_limit_and_offset() {
		$this->queue->enqueue(
			array(
				'tool'   => 'tool_a',
				'reason' => 'a',
			)
		);
		$this->queue->enqueue(
			array(
				'tool'   => 'tool_b',
				'reason' => 'b',
			)
		);
		$this->queue->enqueue(
			array(
				'tool'   => 'tool_c',
				'reason' => 'c',
			)
		);

		$page = $this->queue->get_pending(
			array(
				'limit'  => 1,
				'offset' => 1,
			)
		);

		$this->assertCount( 1, $page, 'limit=1 must return a single row.' );
		$this->assertSame( 3, $this->queue->count_pending(), 'count_pending must report the whole queue.' );
	}

	/**
	 * The count must honour the same filters as the list.
	 */
	public function test_count_pending_respects_filters() {
		$this->queue->enqueue(
			array(
				'tool'         => 'tool_a',
				'assistant_id' => 10,
				'reason'       => 'a',
			)
		);
		$this->queue->enqueue(
			array(
				'tool'         => 'tool_b',
				'assistant_id' => 20,
				'reason'       => 'b',
			)
		);

		$this->assertSame( 1, $this->queue->count_pending( array( 'assistant_id' => 10 ) ) );
	}

	// ── approve ───────────────────────────────────────────────────────────────

	/**
	 * Approving records the status, resolver and note.
	 */
	public function test_approve_changes_status() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'delete_post',
				'reason' => 'test',
			)
		);
		$this->assertTrue( $this->queue->approve( $id, $admin, 'LGTM' ) );

		$record = $this->queue->get( $id );
		$this->assertSame( 'approved', $record['status'] );
		$this->assertSame( $admin, $record['resolved_by'] );
		$this->assertSame( 'LGTM', $record['note'] );
	}

	/**
	 * Approve fires wp_mcp_ai_approval_approved.
	 */
	public function test_approve_fires_approved_action() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$fired = null;
		add_action(
			'wp_mcp_ai_approval_approved',
			function ( $id ) use ( &$fired ) {
				$fired = $id;
			}
		);

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'test_tool',
				'reason' => 'test',
			)
		);
		$this->queue->approve( $id, $admin );
		$this->assertSame( $id, $fired );

		remove_all_actions( 'wp_mcp_ai_approval_approved' );
	}

	/**
	 * A second approve on the same record is rejected.
	 */
	public function test_approve_already_resolved_returns_wp_error() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'test',
				'reason' => 'test',
			)
		);
		$this->queue->approve( $id, $admin );

		// Try to approve again.
		$result = $this->queue->approve( $id, $admin );
		$this->assertWPError( $result );
		$this->assertSame( 'approval_already_resolved', $result->get_error_code() );
	}

	// ── deny ──────────────────────────────────────────────────────────────────

	/**
	 * Denying records the status and note.
	 */
	public function test_deny_changes_status() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'delete_post',
				'reason' => 'test',
			)
		);
		$this->assertTrue( $this->queue->deny( $id, $admin, 'Too risky' ) );

		$record = $this->queue->get( $id );
		$this->assertSame( 'denied', $record['status'] );
		$this->assertSame( 'Too risky', $record['note'] );
	}

	/**
	 * Deny fires wp_mcp_ai_approval_denied.
	 */
	public function test_deny_fires_denied_action() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$fired = null;
		add_action(
			'wp_mcp_ai_approval_denied',
			function ( $id ) use ( &$fired ) {
				$fired = $id;
			}
		);

		$id = $this->queue->enqueue(
			array(
				'tool'   => 'test_tool',
				'reason' => 'test',
			)
		);
		$this->queue->deny( $id, $admin );
		$this->assertSame( $id, $fired );

		remove_all_actions( 'wp_mcp_ai_approval_denied' );
	}

	// ── not found ─────────────────────────────────────────────────────────────

	/**
	 * Approving an unknown ID returns WP_Error.
	 */
	public function test_approve_nonexistent_returns_wp_error() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$result = $this->queue->approve( 999999, $admin );
		$this->assertWPError( $result );
		$this->assertSame( 'approval_not_found', $result->get_error_code() );
	}

	/**
	 * The singleton always returns the same instance.
	 */
	public function test_singleton_returns_same_instance() {
		$a = WP_MCP_AI_Approval_Queue::get_instance();
		$b = WP_MCP_AI_Approval_Queue::get_instance();
		$this->assertSame( $a, $b );
	}
}
