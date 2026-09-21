<?php
/**
 * L2 deterministic round-trip suite for ID-handoff contracts.
 *
 * Drives each manifest family flagged `round_trip => true` through the
 * exact multi-step flow that fails in real agentic workflows: create the
 * record, take the identifier from the success envelope, feed it to the
 * consuming tools, and assert the identifier survives the handoff.
 *
 * No LLM is involved — this suite is the deterministic regression guard
 * for the failure mode where the model loses the record ID between calls.
 *
 * Part of the P3 data-contract rollout
 * (docs/project/proposals/P3-data-contract-rollout-plan-2026-09.md).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @group tools
 * @group unix-theory
 */
class Test_Tool_Id_Handoff_Round_Trip extends WP_UnitTestCase {

	use WP_MCP_AI_Tool_Id_Handoff_Test_Helper;

	/**
	 * Administrator context for tool execution.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Reset shared cron state and act as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		// Cron state is shared; start every test from a clean slate.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up cron state and reset the current user.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Data provider: every manifest family flagged for round-trip testing.
	 *
	 * @return array[] List of ( family_key ) cases.
	 */
	public static function manifest_round_trip_families() {
		$manifest = require __DIR__ . '/fixtures/tool-contract-manifest.php';

		$cases = array();
		foreach ( $manifest['families'] as $key => $family ) {
			if ( ! empty( $family['round_trip'] ) ) {
				$cases[ $key ] = array( $key );
			}
		}

		return $cases;
	}

	/**
	 * Drive a full create → fetch → update → delete round trip per family.
	 *
	 * @dataProvider manifest_round_trip_families
	 *
	 * @param string $family_key The identifier key under test.
	 */
	public function test_id_handoff_round_trip( $family_key ) {
		$context = array( 'user_id' => $this->admin_id );

		switch ( $family_key ) {
			case 'job_id':
				$this->run_cron_round_trip( $context );
				break;
			case 'post_id':
				$this->run_post_round_trip( $context );
				break;
			case 'term_id':
				$this->run_term_round_trip( $context );
				break;
			case 'assistant_id':
				$this->run_assistant_round_trip( $context );
				break;
			default:
				$this->fail( 'No round-trip driver for family: ' . $family_key );
		}
	}

	/**
	 * Cron family: create_cron_job → get_cron_job → delete_cron_job.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_cron_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$created     = $create_tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_handoff_test',
				'timestamp' => time() + HOUR_IN_SECONDS,
			),
			$context
		);
		$job_id      = $this->assert_produces_key( $created, 'job_id', 'create_cron_job' );

		$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
		$fetched  = $get_tool->execute( array( 'job_id' => $job_id ), $context );
		$this->assert_id_round_trip( $fetched, 'job_id', $job_id, 'get_cron_job' );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$deleted     = $delete_tool->execute( array( 'job_id' => $job_id ), $context );
		$this->assert_id_round_trip( $deleted, 'job_id', $job_id, 'delete_cron_job' );

		// Negative path: a fabricated ID must fail cleanly, not silently.
		$missing = $get_tool->execute( array( 'job_id' => 'does-not-exist' ), $context );
		$this->assert_id_not_found( $missing, 'get_cron_job', 'job_id' );
	}

	/**
	 * Post family: create_post → get_post → save_post (update) → delete_post.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_post_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Post();
		$created     = $create_tool->execute(
			array(
				'title'   => 'ID handoff round-trip post',
				'content' => 'Round-trip content.',
				'status'  => 'draft',
			),
			$context
		);
		$post_id     = $this->assert_produces_key( $created, 'post_id', 'create_post' );

		$get_tool = new WP_MCP_AI_Tool_Get_Post();
		$fetched  = $get_tool->execute( array( 'post_id' => $post_id ), $context );
		$this->assert_id_round_trip( $fetched, 'post_id', $post_id, 'get_post' );
		$this->assertSame( 'ID handoff round-trip post', $fetched['title'] );

		$save_tool = new WP_MCP_AI_Tool_Save_Post();
		$saved     = $save_tool->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'ID handoff round-trip post (updated)',
				'content' => 'Updated round-trip content.',
			),
			$context
		);
		$this->assert_id_round_trip( $saved, 'post_id', $post_id, 'save_post' );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Post();
		$deleted     = $delete_tool->execute(
			array(
				'post_id'      => $post_id,
				'force_delete' => true,
			),
			$context
		);
		$this->assertNotWPError( $deleted, 'delete_post should succeed for the produced post_id.' );

		// Negative path: a fabricated ID must fail cleanly, not silently.
		$missing = $get_tool->execute( array( 'post_id' => PHP_INT_MAX - 1 ), $context );
		$this->assert_id_not_found( $missing, 'get_post', 'post_id' );
	}

	/**
	 * Term family: create_term → update_term, plus a clean negative path.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_term_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Term();
		$created     = $create_tool->execute(
			array(
				'name'     => 'ID handoff category',
				'taxonomy' => 'category',
			),
			$context
		);
		$term_id     = $this->assert_produces_key( $created, 'term_id', 'create_term' );

		$update_tool = new WP_MCP_AI_Tool_Update_Term();
		$updated     = $update_tool->execute(
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'category',
				'name'     => 'ID handoff category (updated)',
			),
			$context
		);
		$this->assert_id_round_trip( $updated, 'term_id', $term_id, 'update_term' );

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $update_tool->execute(
			array(
				'term_id'  => PHP_INT_MAX - 1,
				'taxonomy' => 'category',
				'name'     => 'nope',
			),
			$context
		);
		$this->assert_id_not_found( $missing, 'update_term', 'term_id' );

		wp_delete_term( $term_id, 'category' );
	}

	/**
	 * Assistant family: create_assistant → duplicate_assistant.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_assistant_round_trip( $context ) {
		$create_tool  = new WP_MCP_AI_Tool_Create_Assistant();
		$created      = $create_tool->execute(
			array( 'title' => 'ID handoff assistant' ),
			$context
		);
		$assistant_id = $this->assert_produces_key( $created, 'assistant_id', 'create_assistant' );

		$duplicate_tool = new WP_MCP_AI_Tool_Duplicate_Assistant();
		$duplicated     = $duplicate_tool->execute(
			array( 'assistant_id' => $assistant_id ),
			$context
		);
		$this->assertNotWPError( $duplicated, 'duplicate_assistant should succeed for the produced assistant_id.' );
		$this->assertIsArray( $duplicated );
		$this->assertArrayHasKey( 'assistant_id', $duplicated, 'duplicate_assistant must return the new assistant_id.' );
		$this->assertNotSame(
			$assistant_id,
			$duplicated['assistant_id'],
			'duplicate_assistant must produce a NEW assistant_id.'
		);

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $duplicate_tool->execute(
			array( 'assistant_id' => PHP_INT_MAX - 1 ),
			$context
		);
		$this->assert_id_not_found( $missing, 'duplicate_assistant', 'assistant_id' );

		wp_delete_post( $assistant_id, true );
		wp_delete_post( $duplicated['assistant_id'], true );
	}
}
