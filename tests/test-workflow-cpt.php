<?php
/**
 * Tests for WP_MCP_AI_Workflow_CPT.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

/**
 * Workflow CPT unit tests.
 */
class Test_Workflow_CPT extends WP_UnitTestCase {

	/** @var int Admin user ID. */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-cpt.php';
		WP_MCP_AI_Workflow_CPT::register_cpt();

		$this->admin_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin_id );
	}

	// ── register_cpt ──────────────────────────────────────────────────────────

	public function test_cpt_is_registered() {
		$this->assertTrue( post_type_exists( WP_MCP_AI_Workflow_CPT::CPT ) );
	}

	public function test_cpt_slug_is_mcp_ai_workflow() {
		$this->assertSame( 'mcp_ai_workflow', WP_MCP_AI_Workflow_CPT::CPT );
	}

	// ── get_graph / save_graph ────────────────────────────────────────────────

	public function test_get_graph_returns_empty_for_new_post() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Test WF',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		$graph = WP_MCP_AI_Workflow_CPT::get_graph( $post_id );

		$this->assertIsArray( $graph );
		$this->assertArrayHasKey( 'nodes', $graph );
		$this->assertArrayHasKey( 'edges', $graph );
		$this->assertEmpty( $graph['nodes'] );
		$this->assertEmpty( $graph['edges'] );
	}

	public function test_save_and_get_graph_roundtrip() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Roundtrip WF',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		$graph = array(
			'nodes' => array(
				array( 'id' => 'n1', 'type' => 'agent', 'label' => 'My Agent', 'x' => 100, 'y' => 50 ),
				array( 'id' => 'n2', 'type' => 'tool',  'label' => 'My Tool',  'x' => 300, 'y' => 50 ),
			),
			'edges' => array(
				array( 'id' => 'e1', 'from' => 'n1', 'to' => 'n2' ),
			),
		);

		$result = WP_MCP_AI_Workflow_CPT::save_graph( $post_id, $graph );
		$this->assertTrue( $result );

		$loaded = WP_MCP_AI_Workflow_CPT::get_graph( $post_id );
		$this->assertCount( 2, $loaded['nodes'] );
		$this->assertCount( 1, $loaded['edges'] );
		$this->assertSame( 'n1', $loaded['nodes'][0]['id'] );
		$this->assertSame( 'e1', $loaded['edges'][0]['id'] );
	}

	public function test_save_graph_returns_false_for_non_admin() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Auth Test WF',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		wp_set_current_user( 0 );
		$result = WP_MCP_AI_Workflow_CPT::save_graph( $post_id, array( 'nodes' => array(), 'edges' => array() ) );
		$this->assertFalse( $result );

		// Restore admin.
		wp_set_current_user( $this->admin_id );
	}

	public function test_get_graph_with_invalid_json_returns_empty() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Bad JSON WF',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_GRAPH, wp_slash( 'NOT_JSON' ) );
		$graph = WP_MCP_AI_Workflow_CPT::get_graph( $post_id );

		$this->assertEmpty( $graph['nodes'] );
		$this->assertEmpty( $graph['edges'] );
	}

	// ── export_json ───────────────────────────────────────────────────────────

	public function test_export_json_returns_correct_schema() {
		$post_id = wp_insert_post( array(
			'post_title'   => 'Export WF',
			'post_content' => 'A description',
			'post_status'  => 'publish',
			'post_type'    => WP_MCP_AI_Workflow_CPT::CPT,
		) );
		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, '2.1.0' );

		$exported = WP_MCP_AI_Workflow_CPT::export_json( $post_id );

		$this->assertSame( '1.0', $exported['schema_version'] );
		$this->assertSame( 'Export WF', $exported['name'] );
		$this->assertSame( 'A description', $exported['description'] );
		$this->assertSame( '2.1.0', $exported['version'] );
		$this->assertArrayHasKey( 'graph', $exported );
		$this->assertArrayHasKey( 'exported_at', $exported );
	}

	public function test_export_json_returns_empty_for_wrong_cpt() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Regular Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$result = WP_MCP_AI_Workflow_CPT::export_json( $post_id );
		$this->assertEmpty( $result );
	}

	// ── import_json ───────────────────────────────────────────────────────────

	public function test_import_json_creates_new_post() {
		$data = array(
			'name'    => 'Imported WF',
			'version' => '1.2.3',
			'graph'   => array(
				'nodes' => array( array( 'id' => 'n1', 'type' => 'tool', 'label' => 'T1', 'x' => 10, 'y' => 10 ) ),
				'edges' => array(),
			),
			'tags' => array( 'ci', 'test' ),
		);

		$post_id = WP_MCP_AI_Workflow_CPT::import_json( $data );
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertSame( 'Imported WF', $post->post_title );
		$this->assertSame( WP_MCP_AI_Workflow_CPT::CPT, $post->post_type );

		$version = get_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, true );
		$this->assertSame( '1.2.3', $version );

		$graph = WP_MCP_AI_Workflow_CPT::get_graph( $post_id );
		$this->assertCount( 1, $graph['nodes'] );
	}

	public function test_import_json_overwrites_existing_post() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Old Title',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		$data = array( 'name' => 'New Title', 'version' => '2.0.0' );
		$result = WP_MCP_AI_Workflow_CPT::import_json( $data, $post_id );

		$this->assertSame( $post_id, $result );
		$this->assertSame( 'New Title', get_post( $post_id )->post_title );
		$this->assertSame( '2.0.0', get_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, true ) );
	}

	public function test_import_json_returns_wp_error_without_name() {
		$result = WP_MCP_AI_Workflow_CPT::import_json( array( 'version' => '1.0.0' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	public function test_import_json_returns_forbidden_for_non_admin() {
		wp_set_current_user( 0 );
		$result = WP_MCP_AI_Workflow_CPT::import_json( array( 'name' => 'WF' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		wp_set_current_user( $this->admin_id );
	}

	// ── bump_version ──────────────────────────────────────────────────────────

	public function test_bump_version_patch() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Version WF',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );
		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, '1.2.3' );

		$new = WP_MCP_AI_Workflow_CPT::bump_version( $post_id, 'patch' );
		$this->assertSame( '1.2.4', $new );
		$this->assertSame( '1.2.4', get_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, true ) );
	}

	public function test_bump_version_minor() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Version WF Minor',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );
		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, '1.2.3' );

		$new = WP_MCP_AI_Workflow_CPT::bump_version( $post_id, 'minor' );
		$this->assertSame( '1.3.0', $new );
	}

	public function test_bump_version_major() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Version WF Major',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );
		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, '1.2.3' );

		$new = WP_MCP_AI_Workflow_CPT::bump_version( $post_id, 'major' );
		$this->assertSame( '2.0.0', $new );
	}

	public function test_bump_version_initialises_from_empty() {
		$post_id = wp_insert_post( array(
			'post_title'  => 'Version WF Empty',
			'post_status' => 'publish',
			'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
		) );

		$new = WP_MCP_AI_Workflow_CPT::bump_version( $post_id, 'patch' );
		$this->assertSame( '1.0.1', $new );
	}

	// ── export → import roundtrip ─────────────────────────────────────────────

	public function test_export_import_roundtrip() {
		$post_id = wp_insert_post( array(
			'post_title'   => 'Roundtrip',
			'post_content' => 'desc',
			'post_status'  => 'publish',
			'post_type'    => WP_MCP_AI_Workflow_CPT::CPT,
		) );
		update_post_meta( $post_id, WP_MCP_AI_Workflow_CPT::META_VERSION, '3.1.0' );
		WP_MCP_AI_Workflow_CPT::save_graph( $post_id, array(
			'nodes' => array( array( 'id' => 'nA', 'type' => 'agent', 'label' => 'A', 'x' => 0, 'y' => 0 ) ),
			'edges' => array(),
		) );

		$exported  = WP_MCP_AI_Workflow_CPT::export_json( $post_id );
		$new_id    = WP_MCP_AI_Workflow_CPT::import_json( $exported );

		$this->assertIsInt( $new_id );
		$this->assertNotSame( $post_id, $new_id );

		$new_graph = WP_MCP_AI_Workflow_CPT::get_graph( $new_id );
		$this->assertCount( 1, $new_graph['nodes'] );
		$this->assertSame( '3.1.0', get_post_meta( $new_id, WP_MCP_AI_Workflow_CPT::META_VERSION, true ) );
	}
}
