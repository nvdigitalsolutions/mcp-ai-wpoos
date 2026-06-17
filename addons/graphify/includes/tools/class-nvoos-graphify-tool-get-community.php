<?php
/**
 * Graphify Tool — Get Community
 *
 * Returns all nodes belonging to a topic cluster/community.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_get_community
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Get_Community implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_get_community';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get Knowledge Community', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Return all nodes that belong to a specific topic cluster (community) in the knowledge graph. Communities are detected via modularity-based clustering. Provide a community_id (from graphify_graph_stats or graphify_god_nodes) or a node_id/label to look up which community a node belongs to.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'community_id' => array(
					'type'        => 'string',
					'description' => __( 'Community identifier (e.g. "c_ab12cd34"). From graphify_graph_stats.', 'nvoos-graphify' ),
				),
				'node_id'      => array(
					'type'        => 'string',
					'description' => __( 'Look up the community that contains this node.', 'nvoos-graphify' ),
				),
				'label'        => array(
					'type'        => 'string',
					'description' => __( 'Find the community for the node matching this label.', 'nvoos-graphify' ),
					'maxLength'   => 255,
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum nodes to return (default: 50).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
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
		$community_id = '';
		$limit        = isset( $arguments['limit'] ) ? max( 1, min( 200, absint( $arguments['limit'] ) ) ) : 50;

		if ( ! empty( $arguments['community_id'] ) ) {
			$community_id = sanitize_text_field( $arguments['community_id'] );
		} elseif ( ! empty( $arguments['node_id'] ) ) {
			$n = NV_oOS_Graphify_DB::get_node( sanitize_text_field( $arguments['node_id'] ) );
			if ( $n ) {
				$community_id = $n->community_id;
			}
		} elseif ( ! empty( $arguments['label'] ) ) {
			$results = NV_oOS_Graphify_DB::search_nodes( sanitize_text_field( $arguments['label'] ), '', 1 );
			if ( ! empty( $results ) ) {
				$community_id = $results[0]->community_id;
			}
		}

		if ( ! $community_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Community not found. Provide a valid community_id, node_id, or label.', 'nvoos-graphify' ),
			);
		}

		$nodes = NV_oOS_Graphify_DB::list_nodes(
			array(
				'community_id' => $community_id,
				'limit'        => $limit,
				'order_by'     => 'degree',
				'order'        => 'DESC',
			)
		);

		return array(
			'success'      => true,
			'community_id' => $community_id,
			'node_count'   => count( $nodes ),
			'nodes'        => $nodes,
		);
	}
}
