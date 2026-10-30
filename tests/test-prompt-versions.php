<?php
/**
 * Tests for assistant prompt versioning (Proposal 091, Track B).
 *
 * @package WP_MCP_AI
 */

/**
 * Prompt versions test case.
 */
class Test_Prompt_Versions extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for capability checks.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Helper: create a published assistant and return its ID.
	 *
	 * @return int
	 */
	private function create_assistant() {
		return wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Versioned Assistant',
			)
		);
	}

	/**
	 * Baseline: first save with a non-empty prompt snapshots v1 and
	 * auto-promotes it.
	 *
	 * @return void
	 */
	public function test_baseline_snapshot_on_first_save() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'You are a helpful assistant.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Renamed',
			)
		);

		$versions = WP_MCP_AI_Prompt_Versions::get_versions( $post_id );
		$this->assertCount( 1, $versions );
		$this->assertSame( 1, $versions[0]['v'] );
		$this->assertSame( 'You are a helpful assistant.', $versions[0]['prompt'] );
		$this->assertSame( 1, WP_MCP_AI_Prompt_Versions::get_production( $post_id ) );
	}

	/**
	 * An empty first save never creates a baseline.
	 *
	 * @return void
	 */
	public function test_no_snapshot_when_prompt_empty() {
		$post_id = $this->create_assistant();
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Renamed',
			)
		);

		$this->assertEmpty( WP_MCP_AI_Prompt_Versions::get_versions( $post_id ) );
		$this->assertSame( 0, WP_MCP_AI_Prompt_Versions::get_production( $post_id ) );
	}

	/**
	 * Unchanged saves do not create duplicates.
	 *
	 * @return void
	 */
	public function test_no_snapshot_when_unchanged() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'Stable prompt.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'First',
			)
		);
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Second',
			)
		);

		$this->assertCount( 1, WP_MCP_AI_Prompt_Versions::get_versions( $post_id ) );
	}

	/**
	 * Restore writes the exact prompt bytes back and versions the restore
	 * itself so it can be undone.
	 *
	 * @return void
	 */
	public function test_restore_writes_exact_prompt_and_creates_undo() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, "Original\nprompt." );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save 1',
			)
		);
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'Overwritten.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save 2',
			)
		);

		$result = WP_MCP_AI_Prompt_Versions::restore( $post_id, 1 );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['restored'] );
		$this->assertSame( 3, $result['version'] );

		$this->assertSame( "Original\nprompt.", get_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, true ) );
		$this->assertCount( 3, WP_MCP_AI_Prompt_Versions::get_versions( $post_id ) );
	}

	/**
	 * Promote moves the production label; nothing else does.
	 *
	 * @return void
	 */
	public function test_promote_moves_production_label_only() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'v1 prompt.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save 1',
			)
		);
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'v2 prompt.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save 2',
			)
		);

		// v2 exists but production is still v1 until promoted.
		$this->assertSame( 1, WP_MCP_AI_Prompt_Versions::get_production( $post_id ) );

		$result = WP_MCP_AI_Prompt_Versions::promote( $post_id, 2 );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, WP_MCP_AI_Prompt_Versions::get_production( $post_id ) );
	}

	/**
	 * Restoring or promoting a missing version returns a WP_Error.
	 *
	 * @return void
	 */
	public function test_missing_version_errors() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'Only prompt.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save',
			)
		);

		$this->assertWPError( WP_MCP_AI_Prompt_Versions::restore( $post_id, 99 ) );
		$this->assertWPError( WP_MCP_AI_Prompt_Versions::promote( $post_id, 99 ) );
	}

	/**
	 * The version list is capped at MAX_VERSIONS with oldest entries evicted.
	 *
	 * @return void
	 */
	public function test_version_cap_evicts_oldest() {
		$post_id = $this->create_assistant();
		$cap     = WP_MCP_AI_Prompt_Versions::MAX_VERSIONS;

		for ( $i = 1; $i <= $cap + 5; $i++ ) {
			update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'Prompt iteration ' . $i . '.' );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => 'Save ' . $i,
				)
			);
		}

		$versions = WP_MCP_AI_Prompt_Versions::get_versions( $post_id );
		$this->assertCount( $cap, $versions );
		$this->assertSame( 6, $versions[0]['v'] ); // Versions 1–5 evicted.
		$this->assertSame( $cap + 5, $versions[ $cap - 1 ]['v'] );
	}

	/**
	 * REST: an administrator can list versions; restore/promote round-trip.
	 *
	 * @return void
	 */
	public function test_rest_routes_admin_round_trip() {
		$post_id = $this->create_assistant();
		update_post_meta( $post_id, WP_MCP_AI_Prompt_Versions::META_SYSTEM_PROMPT, 'REST prompt.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Save',
			)
		);

		$list = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants/' . $post_id . '/prompt-versions' ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertCount( 1, $list->get_data()['versions'] );
		$this->assertTrue( $list->get_data()['versions'][0]['production'] );

		$promote = rest_do_request( new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/' . $post_id . '/prompt-versions/1/promote' ) );
		$this->assertSame( 200, $promote->get_status() );
		$this->assertSame( 1, $promote->get_data()['version'] );
	}

	/**
	 * REST: subscribers are rejected on every route.
	 *
	 * @return void
	 */
	public function test_rest_routes_reject_subscribers() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$post_id = $this->create_assistant();

		$list = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants/' . $post_id . '/prompt-versions' ) );
		$this->assertSame( 403, $list->get_status() );

		$restore = rest_do_request( new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/' . $post_id . '/prompt-versions/1/restore' ) );
		$this->assertSame( 403, $restore->get_status() );

		$promote = rest_do_request( new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/' . $post_id . '/prompt-versions/1/promote' ) );
		$this->assertSame( 403, $promote->get_status() );
	}
}
