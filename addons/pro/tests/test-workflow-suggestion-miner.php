<?php
/**
 * Tests for the workflow suggestion miner and its tool.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.97
 */

/**
 * Workflow suggestion miner test suite.
 */
class Test_Workflow_Suggestion_Miner extends WP_UnitTestCase {

	/**
	 * Assistant ID used for trace fixtures.
	 */
	const FIXTURE_ASSISTANT = 42;

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Suggest_Workflows_From_History
	 */
	protected $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-harness-trace-store.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Workflow_Suggestion_Miner' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/harness/class-wp-mcp-ai-workflow-suggestion-miner.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Suggest_Workflows_From_History' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/harness/class-wp-mcp-ai-tool-suggest-workflows-from-history.php';
		}

		$this->tool = new WP_MCP_AI_Tool_Suggest_Workflows_From_History();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( self::FIXTURE_ASSISTANT );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );

		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( self::FIXTURE_ASSISTANT );

		parent::tearDown();
	}

	/**
	 * Create a trace run with the given tool-call sequence.
	 *
	 * @param array $chains Tool slug chains; each chain is a list of slugs.
	 * @param bool  $ok     Whether every call succeeded.
	 * @return void
	 */
	private function create_run( $chains, $ok = true ) {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( self::FIXTURE_ASSISTANT, array( 'started_at' => time() ) );

		$seq = 0;
		foreach ( $chains as $chain ) {
			foreach ( $chain as $slug ) {
				++$seq;
				WP_MCP_AI_Harness_Trace_Store::append_jsonl(
					$run_id,
					'tool_calls.jsonl',
					array(
						'seq'            => $seq,
						'slug'           => $slug,
						'args_summary'   => '',
						'result_success' => $ok,
						'result_type'    => 'array',
						'result_summary' => '1 keys',
						'duration_ms'    => 1,
						'timestamp'      => time(),
					)
				);
			}
		}

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id, array() );
	}

	/**
	 * A recurring chain is suggested with the right shape and metadata.
	 */
	public function test_mine_finds_recurring_chain() {
		$this->create_run( array( array( 'get_post', 'save_post' ) ) );
		$this->create_run( array( array( 'get_post', 'save_post' ) ) );

		$result = WP_MCP_AI_Workflow_Suggestion_Miner::mine( self::FIXTURE_ASSISTANT );

		$this->assertSame( 1, $result['assistants_scanned'] );
		$this->assertSame( 2, $result['runs_scanned'] );
		$this->assertNotEmpty( $result['suggestions'] );

		$suggestion = $result['suggestions'][0];
		$this->assertSame( 'get_post > save_post', $suggestion['chain'] );
		$this->assertSame( array( 'get_post', 'save_post' ), $suggestion['steps'] );
		$this->assertSame( 2, $suggestion['occurrences'] );
		$this->assertSame( 1.0, $suggestion['success_rate'] );
		$this->assertGreaterThan( 0, $suggestion['score'] );
		$this->assertSame( 'Get Post → Save Post', $suggestion['suggested_name'] );
		$this->assertContains( self::FIXTURE_ASSISTANT, $suggestion['assistant_ids'] );
	}

	/**
	 * Chains below the occurrence floor are filtered out.
	 */
	public function test_min_occurrences_filters_singletons() {
		$this->create_run( array( array( 'get_post', 'save_post' ) ) );
		$this->create_run( array( array( 'search_content', 'get_post' ) ) );

		$result = WP_MCP_AI_Workflow_Suggestion_Miner::mine( self::FIXTURE_ASSISTANT );

		$this->assertEmpty( $result['suggestions'] );
	}

	/**
	 * Failing runs drag the success rate (and therefore the score) down.
	 */
	public function test_success_rate_reflects_failures() {
		$this->create_run( array( array( 'get_post', 'save_post' ) ), true );
		$this->create_run( array( array( 'get_post', 'save_post' ) ), false );

		$result = WP_MCP_AI_Workflow_Suggestion_Miner::mine( self::FIXTURE_ASSISTANT );

		$this->assertNotEmpty( $result['suggestions'] );
		$this->assertSame( 0.5, $result['suggestions'][0]['success_rate'] );
	}

	/**
	 * Slugs are extracted in order and malformed records are skipped.
	 */
	public function test_extract_slugs_skips_malformed_records() {
		$slugs = WP_MCP_AI_Workflow_Suggestion_Miner::extract_slugs(
			array(
				array( 'slug' => 'get_post' ),
				array( 'nope' => 'missing-slug' ),
				array( 'slug' => '' ),
				array( 'slug' => 'save_post' ),
			)
		);

		$this->assertSame( array( 'get_post', 'save_post' ), $slugs );
	}

	/**
	 * Window success requires every record in the window to succeed.
	 */
	public function test_window_all_success() {
		$records = array(
			array(
				'slug'           => 'get_post',
				'result_success' => true,
			),
			array(
				'slug'           => 'save_post',
				'result_success' => false,
			),
		);

		$this->assertFalse( WP_MCP_AI_Workflow_Suggestion_Miner::window_all_success( $records, 0, 2 ) );
		$this->assertTrue( WP_MCP_AI_Workflow_Suggestion_Miner::window_all_success( $records, 0, 1 ) );
	}

	/**
	 * Names are derived from slugs.
	 */
	public function test_suggest_name() {
		$this->assertSame(
			'Search Content → Get Post',
			WP_MCP_AI_Workflow_Suggestion_Miner::suggest_name( array( 'search_content', 'get_post' ) )
		);
	}

	/**
	 * The tool denies non-admins.
	 */
	public function test_tool_denies_non_admin() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * With no history the tool returns an empty suggestion set in the
	 * canonical envelope.
	 */
	public function test_tool_reports_empty_history() {
		$result = $this->tool->execute( array( 'assistant_id' => self::FIXTURE_ASSISTANT ), array( 'user_id' => $this->admin_id ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['data']['suggestions'] );
		$this->assertSame( 0, $result['data']['runs_scanned'] );
	}

	/**
	 * With recorded history the tool surfaces suggestions in the canonical
	 * envelope.
	 */
	public function test_tool_returns_suggestions() {
		$this->create_run( array( array( 'get_post', 'save_post' ) ) );
		$this->create_run( array( array( 'get_post', 'save_post' ) ) );

		$result = $this->tool->execute(
			array( 'assistant_id' => self::FIXTURE_ASSISTANT ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['data']['suggestions'] );
		$this->assertSame( 'get_post > save_post', $result['data']['suggestions'][0]['chain'] );
	}

	/**
	 * Tool metadata is coherent.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'suggest_workflows_from_history', $this->tool->get_slug() );
		$this->assertSame( 'manage_options', $this->tool->get_required_capability() );

		$schema = $this->tool->get_parameters_schema();
		$this->assertArrayHasKey( 'assistant_id', $schema['properties'] );
		$this->assertArrayHasKey( 'min_occurrences', $schema['properties'] );
	}
}
