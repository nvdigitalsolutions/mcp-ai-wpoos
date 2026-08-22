<?php
/**
 * Tests for the Artifact Approval Queue (Phase G.2).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the human approval queue for promotions and rollbacks.
 */
class Test_Artifact_Approval_Queue extends WP_UnitTestCase {

	/**
	 * Assistant post ID used across tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up an assistant post and the current user.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Artifact_Approval_Queue' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Approval_Queue class not available.' );
		}

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( 1 );
	}

	/**
	 * Reset the current user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A passing holdout payload for promote items.
	 *
	 * @return array<string,mixed>
	 */
	private function passing_verification() {
		return array(
			'decision'            => 'accept',
			'regressed_cases'     => 0,
			'candidate_pass_rate' => 1.0,
		);
	}

	/**
	 * Enqueue stores a pending item with its payload and evidence.
	 */
	public function test_enqueue_creates_pending_item() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Queued candidate.',
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertNotWPError( $item_id );

		$item = WP_MCP_AI_Artifact_Approval_Queue::get_item( $item_id );
		$this->assertNotNull( $item );
		$this->assertSame( 'pending', $item['status'] );
		$this->assertSame( 'Queued candidate.', $item['payload'] );
		$this->assertSame( $this->assistant_id, (int) $item['assistant_id'] );

		$pending = WP_MCP_AI_Artifact_Approval_Queue::list_items( $this->assistant_id, 'pending' );
		$this->assertCount( 1, $pending );
	}

	/**
	 * Approving a promote item executes the deployment.
	 */
	public function test_approve_promote_deploys() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Approved candidate.',
			array( 'verification' => $this->passing_verification() )
		);

		$result = WP_MCP_AI_Artifact_Approval_Queue::approve( $item_id, 1, 'LGTM' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'approved', $result['decided'] );

		$this->assertSame(
			'Approved candidate.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);

		$item = WP_MCP_AI_Artifact_Approval_Queue::get_item( $item_id );
		$this->assertSame( 'approved', $item['status'] );
		$this->assertSame( 1, (int) $item['decided_by'] );
		$this->assertSame( 'LGTM', $item['decision_note'] );
	}

	/**
	 * Approving a rollback item executes the rollback.
	 */
	public function test_approve_rollback_restores_incumbent() {
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', 'Old prompt.' );

		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'New prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'rollback',
			'prompt',
			array( 'drift' => array( 'actionable' => true ) )
		);

		$result = WP_MCP_AI_Artifact_Approval_Queue::approve( $item_id );

		$this->assertNotWPError( $result );
		$this->assertSame(
			'Old prompt.',
			get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);
	}

	/**
	 * Rejecting never executes the action.
	 */
	public function test_reject_does_not_deploy() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Rejected candidate.',
			array( 'verification' => $this->passing_verification() )
		);

		$result = WP_MCP_AI_Artifact_Approval_Queue::reject( $item_id, 1, 'Too risky.' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'rejected', $result['decided'] );
		$this->assertSame(
			'',
			(string) get_post_meta( $this->assistant_id, '_wp_mcp_ai_evolved_system_prompt', true )
		);

		$item = WP_MCP_AI_Artifact_Approval_Queue::get_item( $item_id );
		$this->assertSame( 'rejected', $item['status'] );
	}

	/**
	 * Double decisions are rejected.
	 */
	public function test_item_cannot_be_decided_twice() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Once only.',
			array( 'verification' => $this->passing_verification() )
		);

		WP_MCP_AI_Artifact_Approval_Queue::reject( $item_id );

		$second = WP_MCP_AI_Artifact_Approval_Queue::approve( $item_id );

		$this->assertWPError( $second );
		$this->assertSame( 'wp_mcp_ai_artifact_queue_already_decided', $second->get_error_code() );
	}

	/**
	 * Users without edit capability cannot decide items.
	 */
	public function test_decision_requires_capability() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Forbidden.',
			array( 'verification' => $this->passing_verification() )
		);

		wp_set_current_user( 0 );

		$result = WP_MCP_AI_Artifact_Approval_Queue::approve( $item_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_queue_forbidden', $result->get_error_code() );
	}

	/**
	 * The per-assistant pending cap is enforced.
	 */
	public function test_per_assistant_pending_cap() {
		for ( $i = 0; $i < WP_MCP_AI_Artifact_Approval_Queue::MAX_PENDING_PER_ASSISTANT; $i++ ) {
			$result = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
				$this->assistant_id,
				'promote',
				'prompt',
				'Candidate ' . $i,
				array( 'verification' => $this->passing_verification() )
			);
			$this->assertNotWPError( $result );
		}

		$overflow = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Overflow.',
			array( 'verification' => $this->passing_verification() )
		);

		$this->assertWPError( $overflow );
		$this->assertSame( 'wp_mcp_ai_artifact_queue_per_assistant_cap', $overflow->get_error_code() );
	}

	/**
	 * Expired pending items are purged.
	 */
	public function test_purge_expired_removes_stale_items() {
		$item_id = WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Stale.',
			array( 'verification' => $this->passing_verification() )
		);

		// Force the expiry into the past.
		$queue = get_option( WP_MCP_AI_Artifact_Approval_Queue::OPTION_KEY, array() );
		foreach ( $queue as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $item_id ) {
				$queue[ $index ]['expires_at'] = time() - 1;
			}
		}
		update_option( WP_MCP_AI_Artifact_Approval_Queue::OPTION_KEY, $queue, false );

		$this->assertSame( 1, WP_MCP_AI_Artifact_Approval_Queue::purge_expired() );
		$this->assertNull( WP_MCP_AI_Artifact_Approval_Queue::get_item( $item_id ) );
	}

	/**
	 * Invalid actions are rejected.
	 */
	public function test_invalid_action_rejected() {
		$result = WP_MCP_AI_Artifact_Approval_Queue::enqueue( $this->assistant_id, 'explode', 'prompt', 'x' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_queue_invalid_action', $result->get_error_code() );
	}
}
