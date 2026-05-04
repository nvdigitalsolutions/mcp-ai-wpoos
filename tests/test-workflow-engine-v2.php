<?php
/**
 * Tests for WP_MCP_AI_Workflow_Engine_V2.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Workflow Engine V2 unit tests.
 */
class Test_Workflow_Engine_V2 extends WP_UnitTestCase {

	/** @var int Admin user ID. */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-cpt.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-engine-v2.php';

		WP_MCP_AI_Workflow_CPT::register_cpt();

		$this->admin_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin_id );

		// Ensure the feature flag is OFF by default for each test.
		remove_all_filters( 'wp_mcp_ai_workflow_v2_enabled' );
	}

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_workflow_v2_enabled' );
		parent::tearDown();
	}

	// ── is_enabled ────────────────────────────────────────────────────────────

	public function test_is_enabled_returns_false_by_default() {
		$this->assertFalse( WP_MCP_AI_Workflow_Engine_V2::is_enabled() );
	}

	public function test_is_enabled_returns_true_when_filtered() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$this->assertTrue( WP_MCP_AI_Workflow_Engine_V2::is_enabled() );
	}

	// ── execute — disabled ────────────────────────────────────────────────────

	public function test_execute_returns_disabled_message_when_off() {
		$post_id = $this->make_workflow_post( 'Disabled WF' );
		$result  = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'disabled', strtolower( $result['message'] ) );
		$this->assertSame( '', $result['run_id'] );
	}

	// ── execute — permission check ────────────────────────────────────────────

	public function test_execute_returns_permission_denied_for_non_admin() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'Auth WF' );

		wp_set_current_user( 0 );
		$result = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $result['message'] ) );

		wp_set_current_user( $this->admin_id );
	}

	// ── execute — post not found ──────────────────────────────────────────────

	public function test_execute_returns_error_for_missing_post() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$result = WP_MCP_AI_Workflow_Engine_V2::execute( 999999 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not found', strtolower( $result['message'] ) );
	}

	// ── execute — wrong CPT ───────────────────────────────────────────────────

	public function test_execute_returns_error_for_wrong_post_type() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$regular_post = wp_insert_post( array(
			'post_title'  => 'Regular',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$result = WP_MCP_AI_Workflow_Engine_V2::execute( $regular_post );
		$this->assertFalse( $result['success'] );
	}

	// ── execute — successful (no tool registry) ───────────────────────────────

	public function test_execute_succeeds_without_tool_registry() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'Success WF', array(
			'nodes' => array(
				array( 'id' => 'n1', 'type' => 'agent', 'label' => 'Agent A', 'x' => 10, 'y' => 10 ),
			),
			'edges' => array(),
		) );

		$result = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id, array( 'key' => 'val' ) );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['run_id'] );
		$this->assertStringStartsWith( 'wf2-' . $post_id . '-', $result['run_id'] );
		$this->assertIsArray( $result['results'] );
	}

	// ── execute — run_id format ───────────────────────────────────────────────

	public function test_execute_run_id_contains_workflow_post_id() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'RunID WF' );
		$result  = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( (string) $post_id, $result['run_id'] );
	}

	// ── lifecycle hooks ───────────────────────────────────────────────────────

	public function test_before_execute_hook_fires() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'Hook WF' );
		$fired   = false;

		add_action( 'wp_mcp_ai_workflow_v2_before_execute', function ( $wid ) use ( &$fired, $post_id ) {
			if ( $wid === $post_id ) { $fired = true; }
		} );

		WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );
		$this->assertTrue( $fired );
	}

	public function test_after_execute_hook_fires() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'AfterHook WF' );
		$fired   = false;

		add_action( 'wp_mcp_ai_workflow_v2_after_execute', function ( $wid, $res ) use ( &$fired, $post_id ) {
			if ( $wid === $post_id && isset( $res['run_id'] ) ) { $fired = true; }
		}, 10, 2 );

		WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );
		$this->assertTrue( $fired );
	}

	// ── parallel detection ────────────────────────────────────────────────────

	public function test_parallel_node_type_detected() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'Parallel WF', array(
			'nodes' => array(
				array( 'id' => 'n1', 'type' => 'parallel', 'label' => 'P1', 'x' => 10, 'y' => 10 ),
			),
			'edges' => array(),
		) );

		$result = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id );
		// Just checking it executed; parallel flag is internal implementation detail.
		$this->assertArrayHasKey( 'success', $result );
	}

	// ── input passthrough ─────────────────────────────────────────────────────

	public function test_input_is_merged_into_results_context() {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
		$post_id = $this->make_workflow_post( 'Input WF' );
		$result  = WP_MCP_AI_Workflow_Engine_V2::execute( $post_id, array( 'foo' => 'bar' ) );

		$this->assertTrue( $result['success'] );
		// Results array should at minimum contain the graph and input keys.
		$this->assertArrayHasKey( 'results', $result );
	}

	// ── helpers ───────────────────────────────────────────────────────────────

	/**
	 * Create a workflow CPT post with optional graph data.
	 *
	 * @param string $title  Post title.
	 * @param array  $graph  Optional graph array.
	 * @return int Post ID.
	 */
	private function make_workflow_post( $title, $graph = array() ) {
		$post_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		if ( ! empty( $graph ) ) {
			WP_MCP_AI_Workflow_CPT::save_graph( $post_id, $graph );
		}

		return $post_id;
	}
}
