<?php
/**
 * Memory-graph bridge stubs for the RRF fusion seam tests.
 *
 * Declares two fake retrieval bridges so
 * `test-memory-rrf-graph-seam.php` can exercise the
 * `wp_mcp_ai_memory_rrf_graph_bridge` filter seam: the Graphify-style
 * snake_case method and the standalone Content Graph plugin's camelCase
 * method (the camelCase name is intentional duck-typing — this file is
 * excluded from the WPCS scan).
 *
 * @package WP_MCP_AI
 * @since 1.2.9
 */

/**
 * Fake bridge exposing the Graphify-style snake_case method.
 *
 * @since 1.2.9
 */
class WP_MCP_AI_Test_RRF_Graph_Bridge_Snake {

	/**
	 * Duck-typed retrieval.
	 *
	 * @param array $args Retrieval args.
	 * @return array
	 */
	public static function retrieve_graph( $args ) {
		return array(
			array(
				'context_id' => 'ctx_snake',
				'score'      => 0.9,
				'via'        => array( 'agent' ),
			),
		);
	}
}

/**
 * Fake bridge exposing only the standalone-plugin camelCase method.
 *
 * @since 1.2.9
 */
class WP_MCP_AI_Test_RRF_Graph_Bridge_Camel {

	/**
	 * Duck-typed retrieval (camelCase by design — mirrors the standalone
	 * Content Graph plugin's bridge method name).
	 *
	 * @param array $args Retrieval args.
	 * @return array
	 */
	public static function retrieveGraph( array $args ) {
		return array(
			array(
				'context_id' => 'ctx_camel',
				'score'      => 0.8,
				'via'        => array( 'wing' ),
			),
		);
	}
}
