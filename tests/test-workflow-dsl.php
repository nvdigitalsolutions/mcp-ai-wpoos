<?php
/**
 * Tests for the workflow DSL codec + REST upgrade (Proposal 091, Track A).
 *
 * @package WP_MCP_AI
 */

/**
 * Workflow DSL test case.
 */
class Test_Workflow_DSL extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for REST capability checks.
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
	 * Build a canonical workflow array fixture.
	 *
	 * @param bool $with_credentials Whether to include credential params.
	 * @return array
	 */
	private function workflow_fixture( $with_credentials = false ) {
		$params = array( 'url' => 'https://example.com/data' );
		if ( $with_credentials ) {
			$params['api_key'] = 'sk-secret-value';
		}

		return array(
			'name'        => 'Fixture workflow',
			'description' => 'Test description.',
			'version'     => '1.0.0',
			'tags'        => array( 'test' ),
			'graph'       => array(
				'nodes' => array(
					array(
						'id'     => 'n1',
						'type'   => 'tool',
						'label'  => 'Fetch data',
						'params' => $params,
					),
					array(
						'id'    => 'n2',
						'type'  => 'action',
						'label' => 'Save data',
					),
				),
				'edges' => array(
					array(
						'source' => 'n1',
						'target' => 'n2',
					),
				),
			),
		);
	}

	/**
	 * Encode marks the DSL envelope and redacts credential params by default.
	 *
	 * @return void
	 */
	public function test_encode_redacts_credentials_and_marks_flag() {
		$encoded = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow( $this->workflow_fixture( true ) );

		$this->assertSame( 1, $encoded['dsl_version'] );
		$this->assertTrue( $encoded['redacted'] );
		$this->assertSame( '‹redacted›', $encoded['graph']['nodes'][0]['params']['api_key'] );
		$this->assertSame( 'https://example.com/data', $encoded['graph']['nodes'][0]['params']['url'] );
	}

	/**
	 * Include-credentials mode preserves values and omits the redacted flag.
	 *
	 * @return void
	 */
	public function test_encode_include_credentials_keeps_values() {
		$encoded = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow(
			$this->workflow_fixture( true ),
			array( 'include_credentials' => true )
		);

		$this->assertArrayNotHasKey( 'redacted', $encoded );
		$this->assertSame( 'sk-secret-value', $encoded['graph']['nodes'][0]['params']['api_key'] );
	}

	/**
	 * A workflow with blocking findings cannot be encoded.
	 *
	 * @return void
	 */
	public function test_encode_rejects_missing_name() {
		$fixture         = $this->workflow_fixture();
		$fixture['name'] = '';

		$result = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow( $fixture );

		$this->assertWPError( $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * Validation reports unsupported node types and dangling edges.
	 *
	 * @return void
	 */
	public function test_validation_reports_node_type_and_edge_issues() {
		$fixture                              = $this->workflow_fixture();
		$fixture['graph']['nodes'][1]['type'] = 'unknown';
		$fixture['graph']['edges'][]          = array(
			'source' => 'n1',
			'target' => 'ghost',
		);

		$findings = WP_MCP_AI_Workflow_DSL_Codec::validate_workflow( $fixture );

		$severities = wp_list_pluck( $findings, 'severity' );
		$this->assertContains( 'error', $severities );
		$this->assertContains( 'warning', $severities );
	}

	/**
	 * Legacy raw exports (no dsl_version) still decode — backward-compat gate.
	 *
	 * @return void
	 */
	public function test_legacy_array_still_decodes() {
		$legacy = array(
			'schema_version' => '1.0',
			'name'           => 'Legacy workflow',
			'description'    => '',
			'version'        => '1.0.0',
			'tags'           => array(),
			'graph'          => array(
				'nodes' => array(
					array(
						'id'    => 'a',
						'type'  => 'agent',
						'label' => 'Legacy node',
					),
				),
				'edges' => array(),
			),
			'exported_at'    => '2026-10-01T00:00:00+00:00',
		);

		$decoded = WP_MCP_AI_Workflow_DSL_Codec::accept_legacy_json( $legacy );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'Legacy workflow', $decoded['name'] );
		$this->assertEmpty( $decoded['findings'] );
	}

	/**
	 * Full round trip: export → encode → decode → import reproduces the graph.
	 *
	 * @return void
	 */
	public function test_round_trip_preserves_graph() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
				'post_status' => 'publish',
				'post_title'  => 'Round trip workflow',
			)
		);

		$graph = array(
			'nodes' => array(
				array(
					'id'    => 'n1',
					'type'  => 'tool',
					'label' => 'Fetch',
				),
				array(
					'id'    => 'n2',
					'type'  => 'action',
					'label' => 'Save',
				),
			),
			'edges' => array(
				array(
					'source' => 'n1',
					'target' => 'n2',
				),
			),
		);
		WP_MCP_AI_Workflow_CPT::save_graph( $post_id, $graph );

		$exported = WP_MCP_AI_Workflow_CPT::export_json( $post_id );
		$encoded  = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow( $exported );
		$decoded  = WP_MCP_AI_Workflow_DSL_Codec::decode_workflow( $encoded );
		unset( $decoded['findings'] );

		$imported_id = WP_MCP_AI_Workflow_CPT::import_json( $decoded );
		$this->assertNotWPError( $imported_id );

		$re_exported = WP_MCP_AI_Workflow_CPT::export_json( $imported_id );
		$this->assertSame( $graph['nodes'], $re_exported['graph']['nodes'] );
		$this->assertSame( $graph['edges'], $re_exported['graph']['edges'] );
	}

	/**
	 * REST: preset export resolves through the Pro-bridged filter.
	 *
	 * @return void
	 */
	public function test_rest_export_any_preset_via_filter() {
		$preset         = $this->workflow_fixture();
		$preset['name'] = 'Preset from Pro';

		add_filter(
			'wp_mcp_ai_workflow_preset_export',
			function ( $workflow, $slug ) use ( $preset ) {
				return 'sample-preset' === $slug ? $preset : $workflow;
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/workflows/export' );
		$request->set_body_params(
			array(
				'source'      => 'preset',
				'preset_slug' => 'sample-preset',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['dsl_version'] );
		$this->assertSame( 'Preset from Pro', $response->get_data()['name'] );

		remove_all_filters( 'wp_mcp_ai_workflow_preset_export' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/workflows/export' );
		$request->set_body_params(
			array(
				'source'      => 'preset',
				'preset_slug' => 'unknown-preset',
			)
		);
		$missing = rest_do_request( $request );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * REST: dry-run import reports findings and never writes.
	 *
	 * @return void
	 */
	public function test_rest_import_dry_run_does_not_write() {
		$before = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
				'numberposts' => -1,
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/workflows/import' );
		$request->set_body_params(
			array(
				'workflow_json' => $this->workflow_fixture(),
				'dry_run'       => true,
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['dry_run'] );
		$this->assertIsArray( $response->get_data()['findings'] );

		$after = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Workflow_CPT::CPT,
				'numberposts' => -1,
			)
		);
		$this->assertCount( count( $before ), $after );
	}

	/**
	 * REST: a DSL JSON string imports and creates a workflow post.
	 *
	 * @return void
	 */
	public function test_rest_import_dsl_string_creates_post() {
		$encoded = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow( $this->workflow_fixture() );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/workflows/import' );
		$request->set_body_params(
			array(
				'dsl'    => wp_json_encode( $encoded ),
				'source' => 'native',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThan( 0, $response->get_data()['id'] );
		$this->assertSame( 'Fixture workflow', get_the_title( $response->get_data()['id'] ) );
	}
}
