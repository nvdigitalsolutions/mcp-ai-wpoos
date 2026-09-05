<?php
/**
 * Approval queue port tests (Wave E3).
 *
 * Characterization suite for the ported `ApprovalQueue`: byte-identical
 * constants, CPT registration args, enqueue validation + meta roundtrip
 * + TTL floor, approve/deny transitions with audit meta + actions, the
 * permission model, record mapping + status mapping, pending filters,
 * cron scheduling idempotence, and the expiry cleanup (real posts).
 * Runs in both matrices: the CPT is registered by the base loader
 * monolith and by `Plugin::registerApprovals()` standalone.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Approvals\ApprovalQueue;

/**
 * @group approvals
 */
class Test_Approval_Queue extends \WP_UnitTestCase {

	/**
	 * Captured action payloads.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Current admin user ID.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	public function setUp(): void {
		parent::setUp();

		$this->captured = array();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		ApprovalQueue::register_cpt();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		wp_clear_scheduled_hook( ApprovalQueue::CRON_CLEANUP_HOOK );
		parent::tearDown();
	}

	/**
	 * Enqueue a pending approval and return the post ID.
	 *
	 * @param array $overrides Data overrides.
	 * @return int Post ID.
	 */
	private function enqueue( array $overrides = array() ): int {
		$data = array_merge(
			array(
				'tool'      => 'dangerous_tool',
				'arguments' => array( 'site_id' => 12 ),
			),
			$overrides
		);

		$result = ApprovalQueue::get_instance()->enqueue( $data );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		return (int) $result;
	}

	// ─── Constants ────────────────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_approval', ApprovalQueue::CPT );
		$this->assertSame( '_wp_mcp_ai_approval_context', ApprovalQueue::META_CONTEXT );
		$this->assertSame( '_wp_mcp_ai_approval_tool', ApprovalQueue::META_TOOL );
		$this->assertSame( '_wp_mcp_ai_approval_arguments', ApprovalQueue::META_ARGUMENTS );
		$this->assertSame( '_wp_mcp_ai_approval_session', ApprovalQueue::META_SESSION );
		$this->assertSame( '_wp_mcp_ai_approval_assistant_id', ApprovalQueue::META_ASSISTANT );
		$this->assertSame( '_wp_mcp_ai_approval_requester_id', ApprovalQueue::META_REQUESTER );
		$this->assertSame( '_wp_mcp_ai_approval_expires_at', ApprovalQueue::META_EXPIRES );
		$this->assertSame( 'wp_mcp_ai_approval_cleanup', ApprovalQueue::CRON_CLEANUP_HOOK );
		$this->assertSame( 86400, ApprovalQueue::DEFAULT_TTL_SECONDS );
	}

	// ─── CPT registration ─────────────────────────────────────────────

	public function test_register_cpt_registers_hidden_post_type(): void {
		$this->assertTrue( post_type_exists( ApprovalQueue::CPT ) );

		$cpt = get_post_type_object( ApprovalQueue::CPT );
		$this->assertFalse( $cpt->public );
		$this->assertFalse( $cpt->show_ui );
		$this->assertFalse( $cpt->show_in_menu );
		$this->assertFalse( $cpt->show_in_rest );
		$this->assertFalse( $cpt->rewrite );
		$this->assertFalse( $cpt->query_var );
		// WP 6.9 consumes the supports arg during registration (add_supports
		// unsets the object property) — probe the feature registry instead.
		$this->assertTrue( post_type_supports( ApprovalQueue::CPT, 'title' ) );
		$this->assertTrue( post_type_supports( ApprovalQueue::CPT, 'custom-fields' ) );
		$this->assertFalse( post_type_supports( ApprovalQueue::CPT, 'editor' ) );
	}

	public function test_wiring_registers_cpt_in_current_matrix(): void {
		// The base loader owns the CPT monolith; Plugin::registerApprovals()
		// owns it standalone — either way the slug must exist after boot.
		$this->assertTrue( post_type_exists( ApprovalQueue::CPT ) );
	}

	// ─── Enqueue ──────────────────────────────────────────────────────

	public function test_enqueue_creates_pending_post_with_meta(): void {
		$before    = time();
		$session   = 'sess-' . uniqid();
		$requester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id   = $this->enqueue(
			array(
				'assistant_id' => 42,
				'requester_id' => $requester,
				'session_id'   => $session,
				'reason'       => 'This deletes a production site.',
			)
		);
		$after     = time();

		$post = get_post( $post_id );
		$this->assertSame( ApprovalQueue::CPT, $post->post_type );
		$this->assertSame( 'pending', $post->post_status );
		$this->assertSame( $requester, (int) $post->post_author );
		$this->assertStringContainsString( 'dangerous_tool', $post->post_title );

		$this->assertSame( 'dangerous_tool', get_post_meta( $post_id, ApprovalQueue::META_TOOL, true ) );
		$this->assertSame( array( 'site_id' => 12 ), json_decode( (string) get_post_meta( $post_id, ApprovalQueue::META_ARGUMENTS, true ), true ) );
		$this->assertSame( 42, (int) get_post_meta( $post_id, ApprovalQueue::META_ASSISTANT, true ) );
		$this->assertSame( $requester, (int) get_post_meta( $post_id, ApprovalQueue::META_REQUESTER, true ) );
		$this->assertSame( $session, get_post_meta( $post_id, ApprovalQueue::META_SESSION, true ) );

		$expires = (int) get_post_meta( $post_id, ApprovalQueue::META_EXPIRES, true );
		$this->assertGreaterThanOrEqual( $before + ApprovalQueue::DEFAULT_TTL_SECONDS, $expires );
		$this->assertLessThanOrEqual( $after + ApprovalQueue::DEFAULT_TTL_SECONDS, $expires );

		$context = json_decode( (string) get_post_meta( $post_id, ApprovalQueue::META_CONTEXT, true ), true );
		$this->assertSame( 'This deletes a production site.', $context['reason'] );
		$this->assertGreaterThanOrEqual( $before, $context['created_at'] );
	}

	public function test_enqueue_requires_tool(): void {
		$result = ApprovalQueue::get_instance()->enqueue( array( 'reason' => 'no tool here' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'approval_missing_tool', $result->get_error_code() );
	}

	public function test_enqueue_applies_ttl_floor(): void {
		$floor_id = $this->enqueue( array( 'ttl' => 10 ) );
		$expires  = (int) get_post_meta( $floor_id, ApprovalQueue::META_EXPIRES, true );
		$this->assertGreaterThanOrEqual( time() + 60, $expires );

		$before    = time();
		$custom_id = $this->enqueue( array( 'ttl' => 5000 ) );
		$expires   = (int) get_post_meta( $custom_id, ApprovalQueue::META_EXPIRES, true );
		$this->assertGreaterThanOrEqual( $before + 5000, $expires );
		$this->assertLessThanOrEqual( time() + 5000, $expires );
	}

	public function test_enqueue_fires_queued_action(): void {
		$payload = array(
			'tool'      => 'deploy_tool',
			'arguments' => array( 'env' => 'prod' ),
		);

		add_action(
			'wp_mcp_ai_approval_queued',
			function ( $post_id, $data ) {
				$this->captured['queued'] = array( $post_id, $data );
			},
			10,
			2
		);

		$post_id = $this->enqueue( $payload );

		$this->assertSame( $post_id, $this->captured['queued'][0] );
		$this->assertSame( $payload, $this->captured['queued'][1] );
	}

	// ─── Transitions ──────────────────────────────────────────────────

	public function test_approve_transitions_and_records_audit_trail(): void {
		$post_id = $this->enqueue();

		add_action(
			'wp_mcp_ai_approval_approved',
			function ( $approval_id, $actor_id, $note ) {
				$this->captured['approved'] = array( $approval_id, $actor_id, $note );
			},
			10,
			3
		);

		$result = ApprovalQueue::get_instance()->approve( $post_id, $this->admin_id, 'Looks safe.' );

		$this->assertTrue( $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( $this->admin_id, (int) get_post_meta( $post_id, ApprovalQueue::META_RESOLVED_BY, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, ApprovalQueue::META_RESOLVED_AT, true ) );
		$this->assertSame( 'Looks safe.', get_post_meta( $post_id, ApprovalQueue::META_NOTE, true ) );

		$this->assertSame( $post_id, $this->captured['approved'][0] );
		$this->assertSame( $this->admin_id, $this->captured['approved'][1] );
		$this->assertSame( 'Looks safe.', $this->captured['approved'][2] );
	}

	public function test_deny_transitions_and_fires_denied_action(): void {
		$post_id = $this->enqueue();

		add_action(
			'wp_mcp_ai_approval_denied',
			function ( $approval_id, $actor_id, $note ) {
				$this->captured['denied'] = array( $approval_id, $actor_id, $note );
			},
			10,
			3
		);

		$result = ApprovalQueue::get_instance()->deny( $post_id, $this->admin_id, 'Too risky.' );

		$this->assertTrue( $result );
		$this->assertSame( 'private', get_post_status( $post_id ) );
		$this->assertSame( $post_id, $this->captured['denied'][0] );
		$this->assertSame( 'Too risky.', $this->captured['denied'][2] );
	}

	public function test_transition_rejects_unknown_id(): void {
		$result = ApprovalQueue::get_instance()->approve( 99999999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'approval_not_found', $result->get_error_code() );
	}

	public function test_transition_rejects_already_resolved(): void {
		$post_id = $this->enqueue();
		ApprovalQueue::get_instance()->approve( $post_id, $this->admin_id );

		$result = ApprovalQueue::get_instance()->deny( $post_id, $this->admin_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'approval_already_resolved', $result->get_error_code() );
	}

	public function test_transition_forbidden_for_unrelated_subscriber(): void {
		$requester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id   = $this->enqueue( array( 'requester_id' => $requester ) );

		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $other );

		// Byte-identical base contract: a non-admin may not resolve an
		// approval while claiming another user as the actor.
		$result = ApprovalQueue::get_instance()->approve( $post_id, $requester );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'approval_forbidden', $result->get_error_code() );
		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	public function test_transition_allows_requester_to_resolve_own_request(): void {
		$requester = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id   = $this->enqueue( array( 'requester_id' => $requester ) );

		wp_set_current_user( $requester );

		// Byte-identical base contract: current user === actor bypasses the
		// manage_options gate.
		$result = ApprovalQueue::get_instance()->approve( $post_id, $requester );

		$this->assertTrue( $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	// ─── Reads ────────────────────────────────────────────────────────

	public function test_get_returns_record_or_null(): void {
		$post_id = $this->enqueue(
			array(
				'assistant_id' => 7,
				'requester_id' => $this->admin_id,
				'session_id'   => 'chat-1',
			)
		);

		$record = ApprovalQueue::get_instance()->get( $post_id );
		$this->assertSame( $post_id, $record['id'] );
		$this->assertSame( 'pending', $record['status'] );
		$this->assertSame( 'dangerous_tool', $record['tool'] );
		$this->assertSame( array( 'site_id' => 12 ), $record['arguments'] );
		$this->assertSame( 7, $record['assistant_id'] );
		$this->assertSame( $this->admin_id, $record['requester_id'] );
		$this->assertSame( 'chat-1', $record['session_id'] );

		$this->assertNull( ApprovalQueue::get_instance()->get( 99999999 ) );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertNull( ApprovalQueue::get_instance()->get( $page_id ) );
	}

	public function test_status_mapping_covers_all_lifecycle_statuses(): void {
		$approved_id = $this->enqueue();
		$denied_id   = $this->enqueue();
		$expired_id  = $this->enqueue();

		ApprovalQueue::get_instance()->approve( $approved_id, $this->admin_id );
		ApprovalQueue::get_instance()->deny( $denied_id, $this->admin_id );
		wp_trash_post( $expired_id );

		$this->assertSame( 'approved', ApprovalQueue::get_instance()->get( $approved_id )['status'] );
		$this->assertSame( 'denied', ApprovalQueue::get_instance()->get( $denied_id )['status'] );
		$this->assertSame( 'expired', ApprovalQueue::get_instance()->get( $expired_id )['status'] );
	}

	// ─── Pending queries ──────────────────────────────────────────────

	public function test_get_pending_applies_filters_and_limit(): void {
		$assistant_a = $this->enqueue(
			array(
				'assistant_id' => 1,
				'session_id'   => 's1',
			)
		);
		$assistant_b = $this->enqueue(
			array(
				'assistant_id' => 2,
				'session_id'   => 's2',
			)
		);
		$this->enqueue(
			array(
				'assistant_id' => 1,
				'session_id'   => 's3',
			)
		);

		$by_assistant = ApprovalQueue::get_instance()->get_pending( array( 'assistant_id' => 2 ) );
		$this->assertCount( 1, $by_assistant );
		$this->assertSame( $assistant_b, $by_assistant[0]['id'] );

		$by_session = ApprovalQueue::get_instance()->get_pending( array( 'session_id' => 's1' ) );
		$this->assertCount( 1, $by_session );
		$this->assertSame( $assistant_a, $by_session[0]['id'] );

		// Resolved records drop out of the pending list.
		ApprovalQueue::get_instance()->approve( $assistant_b, $this->admin_id );
		$remaining = ApprovalQueue::get_instance()->get_pending( array( 'assistant_id' => 2 ) );
		$this->assertCount( 0, $remaining );

		$limited = ApprovalQueue::get_instance()->get_pending( array( 'limit' => 1 ) );
		$this->assertCount( 1, $limited );
	}

	// ─── Cron + cleanup ───────────────────────────────────────────────

	public function test_register_cron_schedules_weekly_once(): void {
		wp_clear_scheduled_hook( ApprovalQueue::CRON_CLEANUP_HOOK );

		ApprovalQueue::register_cron();
		$first = wp_next_scheduled( ApprovalQueue::CRON_CLEANUP_HOOK );
		$this->assertNotFalse( $first );
		$this->assertSame( 'weekly', wp_get_schedule( ApprovalQueue::CRON_CLEANUP_HOOK ) );

		ApprovalQueue::register_cron();
		$events = get_option( 'cron', array() );
		$count  = 0;
		foreach ( $events as $batch ) {
			if ( is_array( $batch ) && isset( $batch[ ApprovalQueue::CRON_CLEANUP_HOOK ] ) ) {
				++$count;
			}
		}
		$this->assertSame( 1, $count );
	}

	public function test_run_cleanup_trashes_only_expired_pending_approvals(): void {
		$expired_pending_id = $this->enqueue();
		update_post_meta( $expired_pending_id, ApprovalQueue::META_EXPIRES, time() - 3600 );

		$live_pending_id = $this->enqueue();
		update_post_meta( $live_pending_id, ApprovalQueue::META_EXPIRES, time() + 3600 );

		$expired_resolved_id = $this->enqueue();
		update_post_meta( $expired_resolved_id, ApprovalQueue::META_EXPIRES, time() - 3600 );
		ApprovalQueue::get_instance()->approve( $expired_resolved_id, $this->admin_id );

		// A non-approval post sharing the meta key — byte-identical contract:
		// the probe finds it, but the CPT/status guard leaves it alone.
		$foreign_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $foreign_id, ApprovalQueue::META_EXPIRES, time() - 3600 );

		add_action(
			'wp_mcp_ai_approvals_cleanup_done',
			function ( $ids ) {
				$this->captured['cleanup_ids'] = $ids;
			}
		);

		ApprovalQueue::run_cleanup();

		$this->assertSame( 'trash', get_post_status( $expired_pending_id ) );
		$this->assertSame( 'pending', get_post_status( $live_pending_id ) );
		$this->assertSame( 'publish', get_post_status( $expired_resolved_id ) );
		$this->assertSame( 'publish', get_post_status( $foreign_id ) );

		// Byte-identical payload: every expiry-probe hit, not just the
		// trashed subset.
		$ids = array_map( 'intval', $this->captured['cleanup_ids'] );
		$this->assertContains( $expired_pending_id, $ids );
		$this->assertContains( $expired_resolved_id, $ids );
		$this->assertContains( $foreign_id, $ids );
		$this->assertNotContains( $live_pending_id, $ids );
	}
}
