<?php
/**
 * Memory-graph stubs for the provenance-tracer seam tests.
 *
 * Declares a fake bridge (node-prefix constants only) and a fake DB walker
 * (fixture edge map) so `test-memory-provenance-tracer.php` can exercise the
 * `wp_mcp_ai_memory_provenance_graph_bridge` / `_graph_db` /
 * `_graph_relations` filter seams without the bundled Graphify addon.
 *
 * @package WP_MCP_AI
 * @since 1.2.9
 */

/**
 * Fake memory-graph bridge for the seam tests.
 *
 * @since 1.2.9
 */
class WP_MCP_AI_Test_Memory_Graph_Bridge_Stub {
	const NODE_PREFIX_MEMORY = 'memory:';
	const NODE_PREFIX_AGENT  = 'agent:';
	const NODE_PREFIX_WING   = 'wing:';
	const NODE_PREFIX_ROOM   = 'room:';
}

/**
 * Fake graph DB walker for the seam tests.
 *
 * @since 1.2.9
 */
class WP_MCP_AI_Test_Memory_Graph_Db_Stub {

	/**
	 * Fixture edge map: node_id => relation => array of neighbour ids.
	 *
	 * @var array
	 */
	public static $edges = array();

	/**
	 * Duck-typed walker (Graphify snake_case shape).
	 *
	 * @param string $node_id  Node id.
	 * @param string $relation Relation filter.
	 * @return string[]
	 */
	public static function get_neighbor_ids( $node_id, $relation = '' ) {
		if ( '' === $relation ) {
			return array();
		}
		return isset( self::$edges[ (string) $node_id ][ $relation ] ) ? self::$edges[ (string) $node_id ][ $relation ] : array();
	}
}
