<?php
/**
 * Tests for the Artifact Governance metabox (Phase G.2).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the admin governance surface rendering.
 */
class Test_Metabox_Artifact_Governance extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_Metabox_Artifact_Governance' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Metabox_Artifact_Governance class not available.' );
		}

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		global $post;
		$post = get_post( $this->assistant_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		wp_set_current_user( 1 );
	}

	/**
	 * Reset globals and the current user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Build the metabox instance.
	 *
	 * @return WP_MCP_AI_Metabox_Artifact_Governance
	 */
	private function build_metabox() {
		$cpt = new stdClass();
		return new WP_MCP_AI_Metabox_Artifact_Governance( $cpt );
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
	 * Capture the rendered metabox output.
	 *
	 * @return string
	 */
	private function render_output() {
		$metabox = $this->build_metabox();

		ob_start();
		$metabox->render( get_post( $this->assistant_id ) );
		return (string) ob_get_clean();
	}

	/**
	 * The render includes the governor report and an empty queue notice.
	 */
	public function test_render_shows_governor_and_empty_queue() {
		$output = $this->render_output();

		$this->assertStringContainsString( 'Evolution Governor', $output );
		$this->assertStringContainsString( 'Approval Queue', $output );
		$this->assertStringContainsString( 'No pending approvals.', $output );
	}

	/**
	 * Pending items render with nonce'd approve/reject endpoints.
	 */
	public function test_render_lists_pending_items_with_protected_actions() {
		WP_MCP_AI_Artifact_Approval_Queue::enqueue(
			$this->assistant_id,
			'promote',
			'prompt',
			'Awaiting review.',
			array(
				'candidate_hash' => 'abc123def456',
				'verification'   => $this->passing_verification(),
				'reason'         => 'Evolved candidate.',
			)
		);

		$output = $this->render_output();

		$this->assertStringContainsString( 'PROMOTE', $output );
		$this->assertStringContainsString( 'prompt', $output );
		$this->assertStringContainsString( 'abc123de', $output );
		$this->assertStringContainsString( 'Evolved candidate.', $output );
		$this->assertStringContainsString( 'admin-post.php?action=wp_mcp_ai_artifact_queue_approve', $output );
		$this->assertStringContainsString( 'admin-post.php?action=wp_mcp_ai_artifact_queue_reject', $output );
		$this->assertStringContainsString( '_wpnonce=', $output );
	}

	/**
	 * A deployed prompt with population lineage renders the tree.
	 */
	public function test_render_shows_deployed_prompt_lineage() {
		WP_MCP_AI_Artifact_Deploy::promote(
			$this->assistant_id,
			'prompt',
			'Deployed lineage prompt.',
			array( 'verification' => $this->passing_verification() )
		);

		WP_MCP_AI_Artifact_Population::archive( 'prompt', (string) $this->assistant_id, array( 'prompt' => 'Deployed lineage prompt.' ), 0.8 );

		$output = $this->render_output();

		$this->assertStringContainsString( 'Prompt Lineage', $output );
		$this->assertStringContainsString( 'score', $output );
	}

	/**
	 * Users without edit capability get the permission notice.
	 */
	public function test_render_permission_denied_for_non_editors() {
		wp_set_current_user( 0 );

		$output = $this->render_output();

		$this->assertStringContainsString( 'do not have permission', $output );
		$this->assertStringNotContainsString( 'Approval Queue', $output );
	}
}
