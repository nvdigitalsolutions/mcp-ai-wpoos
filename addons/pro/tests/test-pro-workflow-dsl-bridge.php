<?php
/**
 * Tests for the Pro workflow DSL bridge (Proposal 091, Track A — Pro side).
 *
 * @package WP_MCP_AI
 */

/**
 * Pro workflow DSL bridge test case.
 */
class Test_Pro_Workflow_DSL_Bridge extends WP_UnitTestCase {

	/**
	 * Ensure the bridge (and presets class) are loaded even when the Pro
	 * workflow module is not enabled in the test matrix.
	 *
	 * @return void
	 */
	private function ensure_bridge_loaded() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_DSL_Bridge' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pro-workflow-dsl-bridge.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pro-workflow-presets.php';
		}
	}

	/**
	 * Preset resolution normalizes a bundled preset to the canonical shape.
	 *
	 * @return void
	 */
	public function test_resolve_preset_normalizes_shape() {
		$this->ensure_bridge_loaded();

		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();
		if ( empty( $presets ) ) {
			$this->markTestSkipped( 'No workflow presets bundled in this build.' );
		}

		$slug     = array_keys( $presets )[0];
		$resolved = apply_filters( 'wp_mcp_ai_workflow_preset_export', null, $slug );

		$this->assertIsArray( $resolved );
		$this->assertSame( $presets[ $slug ]['name'], $resolved['name'] );
		$this->assertArrayHasKey( 'graph', $resolved );
		$this->assertArrayHasKey( 'nodes', $resolved['graph'] );
		$this->assertNotEmpty( $resolved['graph']['nodes'] );

		$first_node = $resolved['graph']['nodes'][0];
		$this->assertArrayHasKey( 'id', $first_node );
		$this->assertArrayHasKey( 'type', $first_node );
		$this->assertArrayHasKey( 'label', $first_node );
		$this->assertArrayHasKey( 'params', $first_node );
	}

	/**
	 * Unknown preset slugs pass through unresolved.
	 *
	 * @return void
	 */
	public function test_resolve_preset_unknown_slug_returns_null() {
		$this->ensure_bridge_loaded();

		$resolved = apply_filters( 'wp_mcp_ai_workflow_preset_export', null, 'definitely-not-a-preset' );
		$this->assertNull( $resolved );
	}

	/**
	 * The Dify adapter maps known node kinds and stubs unknown ones honestly.
	 *
	 * @return void
	 */
	public function test_adapt_dify_maps_kinds_and_foreign_stubs() {
		$this->ensure_bridge_loaded();

		$dify = array(
			'app'      => array(
				'name'        => 'Support triage',
				'description' => 'Classify and answer.',
			),
			'workflow' => array(
				'graph' => array(
					'nodes' => array(
						array(
							'id'   => 'node1',
							'data' => array(
								'type'  => 'llm',
								'title' => 'Answer question',
							),
						),
						array(
							'id'   => 'node2',
							'data' => array(
								'type'  => 'some-future-dify-node',
								'title' => 'Unknown thing',
							),
						),
					),
					'edges' => array(
						array(
							'source' => 'node1',
							'target' => 'node2',
						),
					),
				),
			),
		);

		$adapted = apply_filters( 'wp_mcp_ai_workflow_dify_import', null, $dify );

		$this->assertIsArray( $adapted );
		$this->assertSame( 'Support triage', $adapted['name'] );
		$this->assertSame( 'tool', $adapted['graph']['nodes'][0]['type'] );
		$this->assertSame( 'action', $adapted['graph']['nodes'][1]['type'] );
		$this->assertTrue( $adapted['graph']['nodes'][1]['foreign'] );
		$this->assertSame( 'some-future-dify-node', $adapted['graph']['nodes'][1]['params']['dify_type'] );
		$this->assertSame( 'node1', $adapted['graph']['edges'][0]['source'] );
	}

	/**
	 * The adapter declines non-array payloads.
	 *
	 * @return void
	 */
	public function test_adapt_dify_rejects_non_array() {
		$this->ensure_bridge_loaded();

		$this->assertNull( apply_filters( 'wp_mcp_ai_workflow_dify_import', null, 'not-an-array' ) );
	}

	/**
	 * REST integration: preset export round-trips through the codec.
	 *
	 * @return void
	 */
	public function test_rest_preset_export_through_codec() {
		$this->ensure_bridge_loaded();

		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();
		if ( empty( $presets ) ) {
			$this->markTestSkipped( 'No workflow presets bundled in this build.' );
		}

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/orchestration/workflows/export' );
		$request->set_body_params(
			array(
				'source'      => 'preset',
				'preset_slug' => array_keys( $presets )[0],
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['dsl_version'] );
	}
}
