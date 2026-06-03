<?php
/**
 * Graphify Tool — Get Neighbors
 *
 * Returns all directly connected nodes for a given graph node.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_get_neighbors
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Get_Neighbors implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_get_neighbors';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get Node Neighbors', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Return all nodes directly connected to a given node in the knowledge graph. Optionally filter by relationship type (e.g. LINKS_TO, CATEGORIZED_BY, discusses_topic). Results include each neighbor\'s label, type, URL, degree, and the connecting relation.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'node_id'       => array(
					'type'        => 'string',
					'description' => __( 'Node identifier (e.g. "post_123"). Use graphify_get_node to look up a node_id.', 'nvoos-graphify' ),
				),
				'label'         => array(
					'type'        => 'string',
					'description' => __( 'Node label (alternative to node_id, uses fuzzy search).', 'nvoos-graphify' ),
					'maxLength'   => 255,
				),
				'relation'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by relation type, e.g. "LINKS_TO", "CATEGORIZED_BY", "discusses_topic". Leave empty for all relations.', 'nvoos-graphify' ),
					'maxLength'   => 128,
				),
				'max_neighbors' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of neighbor edges to return (default: 100, max: 500).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$node = null;
		if ( ! empty( $arguments['node_id'] ) ) {
			$node = NV_oOS_Graphify_DB::get_node( sanitize_text_field( $arguments['node_id'] ) );
		} elseif ( ! empty( $arguments['label'] ) ) {
			$results = NV_oOS_Graphify_DB::search_nodes( sanitize_text_field( $arguments['label'] ), '', 1 );
			$node    = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $node ) {
			return array(
				'success' => false,
				'error'   => __( 'Node not found.', 'nvoos-graphify' ),
			);
		}

		$relation      = isset( $arguments['relation'] ) ? sanitize_text_field( $arguments['relation'] ) : '';
		$max_neighbors = isset( $arguments['max_neighbors'] ) ? max( 1, min( 500, absint( $arguments['max_neighbors'] ) ) ) : 100;
		$edges         = NV_oOS_Graphify_DB::get_edges_for_node( $node->node_id, $relation, $max_neighbors );

		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid         = ( $edge->source_node_id === $node->node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr_node    = NV_oOS_Graphify_DB::get_node( $nid );
			$neighbors[] = array(
				'node_id'    => $nid,
				'label'      => $nbr_node ? $nbr_node->label : $nid,
				'type'       => $nbr_node ? $nbr_node->type : '',
				'url'        => $nbr_node ? $nbr_node->url : '',
				'degree'     => $nbr_node ? (int) $nbr_node->degree : 0,
				'relation'   => $edge->relation,
				'confidence' => floatval( $edge->confidence ),
				'provenance' => $edge->provenance,
				'direction'  => ( $edge->source_node_id === $node->node_id ) ? 'outgoing' : 'incoming',
			);
		}

		return array(
			'success'         => true,
			'node'            => array(
				'node_id' => $node->node_id,
				'label'   => $node->label,
				'type'    => $node->type,
			),
			'relation_filter' => $relation,
			'neighbor_count'  => count( $neighbors ),
			'neighbors'       => $neighbors,
		);
	}
}
