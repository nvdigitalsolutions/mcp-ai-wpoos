<?php
/**
 * Tests for the RRF fusion graph-stream bridge seam.
 *
 * Covers:
 *  - `wp_mcp_ai_memory_rrf_graph_bridge` filter swapping the duck-typed
 *    bridge class.
 *  - Snake_case (`retrieve_graph`) and camelCase (`retrieveGraph`) method
 *    resolution (Graphify vs the standalone Content Graph plugin).
 *  - Graceful empty results when no bridge is available.
 *
 * @package WP_MCP_AI
 * @since 1.2.9
 */

if ( ! class_exists( 'WP_MCP_AI_Memory_RRF_Fusion_Service' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-rrf-fusion-service.php';
}

require_once __DIR__ . '/helpers/rrf-graph-bridge-stubs.php';

/**
 * Test case for the RRF graph-stream bridge seam.
 *
 * @since 1.2.9
 */
class Test_Memory_RRF_Graph_Seam extends WP_UnitTestCase {

	/**
	 * Tear down: remove filters installed by the tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_rrf_graph_bridge' );
		parent::tearDown();
	}

	/**
	 * Without a loaded bridge the graph stream silently returns empty.
	 */
	public function test_absent_bridge_returns_empty() {
		if ( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graphify bridge is loaded — this test only applies to Base builds.' );
		}

		$this->assertSame( array(), WP_MCP_AI_Memory_RRF_Fusion_Service::get_graph_candidates( 'hello', 7, array(), 5 ) );
	}

	/**
	 * The filter seam resolves a duck-typed bridge with the Graphify-style
	 * snake_case method.
	 */
	public function test_filter_seam_resolves_snake_case_bridge() {
		add_filter(
			'wp_mcp_ai_memory_rrf_graph_bridge',
			static function () {
				return 'WP_MCP_AI_Test_RRF_Graph_Bridge_Snake';
			}
		);

		$rows = WP_MCP_AI_Memory_RRF_Fusion_Service::get_graph_candidates( 'activation energy', 7, array(), 5 );

		$this->assertNotEmpty( $rows );
		$this->assertSame( 'ctx_snake', $rows[0]['context_id'] );
	}

	/**
	 * The filter seam resolves a duck-typed bridge exposing only the
	 * standalone-plugin camelCase method.
	 */
	public function test_filter_seam_resolves_camel_case_bridge() {
		add_filter(
			'wp_mcp_ai_memory_rrf_graph_bridge',
			static function () {
				return 'WP_MCP_AI_Test_RRF_Graph_Bridge_Camel';
			}
		);

		$rows = WP_MCP_AI_Memory_RRF_Fusion_Service::get_graph_candidates( 'activation energy', 7, array(), 5 );

		$this->assertNotEmpty( $rows );
		$this->assertSame( 'ctx_camel', $rows[0]['context_id'] );
	}
}
