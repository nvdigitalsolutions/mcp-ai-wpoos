<?php
/**
 * Graphify Tool — Get Node
 *
 * Look up full node details by label search or post ID.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_get_node
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Get_Node implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_get_node';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get Graph Node', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Retrieve full details for a single knowledge graph node including its metadata, properties, degree count, community assignment, and direct neighbor edges. Lookup by label (fuzzy search) or by WordPress post ID.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'   => array(
					'type'        => 'string',
					'description' => __( 'Node label to search for (case-insensitive, partial match). Use this or post_id.', 'nvoos-graphify' ),
					'maxLength'   => 255,
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID. Use this or label.', 'nvoos-graphify' ),
					'minimum'     => 1,
				),
				'node_id' => array(
					'type'        => 'string',
					'description' => __( 'Exact node identifier (e.g. "post_123"). Use this for precise lookup.', 'nvoos-graphify' ),
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
		} elseif ( ! empty( $arguments['post_id'] ) ) {
			$node = NV_oOS_Graphify_DB::get_node_by_post_id( absint( $arguments['post_id'] ) );
		} elseif ( ! empty( $arguments['label'] ) ) {
			$results = NV_oOS_Graphify_DB::search_nodes( sanitize_text_field( $arguments['label'] ), '', 1 );
			$node    = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $node ) {
			return array(
				'success' => false,
				'error'   => __( 'Node not found. Build the graph first with graphify_build_graph.', 'nvoos-graphify' ),
			);
		}

		$edges     = NV_oOS_Graphify_DB::get_edges_for_node( $node->node_id );
		$neighbors = array();
		foreach ( $edges as $edge ) {
			$nid         = ( $edge->source_node_id === $node->node_id ) ? $edge->target_node_id : $edge->source_node_id;
			$nbr_node    = NV_oOS_Graphify_DB::get_node( $nid );
			$neighbors[] = array(
				'node_id'   => $nid,
				'label'     => $nbr_node ? $nbr_node->label : $nid,
				'type'      => $nbr_node ? $nbr_node->type : '',
				'relation'  => $edge->relation,
				'direction' => ( $edge->source_node_id === $node->node_id ) ? 'outgoing' : 'incoming',
			);
		}

		return array(
			'success'    => true,
			'node'       => $node,
			'neighbors'  => $neighbors,
			'edge_count' => count( $edges ),
		);
	}
}
