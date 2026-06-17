<?php
/**
 * Graphify Tool — Shortest Path
 *
 * Find the shortest content path between two topics/posts using BFS.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_shortest_path
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Shortest_Path implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_shortest_path';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Shortest Path Between Topics', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Find the shortest content path between two topics or posts in the knowledge graph using BFS. Returns the sequence of nodes connecting them, which reveals surprising semantic bridges. Provide start and end as labels, node_ids, or post IDs.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'start'     => array(
					'type'        => 'string',
					'description' => __( 'Label or node_id of the starting node.', 'nvoos-graphify' ),
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'end'       => array(
					'type'        => 'string',
					'description' => __( 'Label or node_id of the destination node.', 'nvoos-graphify' ),
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'max_depth' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum path length to search (default: 6).', 'nvoos-graphify' ),
					'minimum'     => 2,
					'maximum'     => 10,
					'default'     => 6,
				),
			),
			'required'             => array( 'start', 'end' ),
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
		$start_label = sanitize_text_field( $arguments['start'] ?? '' );
		$end_label   = sanitize_text_field( $arguments['end'] ?? '' );
		$max_depth   = isset( $arguments['max_depth'] ) ? max( 2, min( 10, absint( $arguments['max_depth'] ) ) ) : 6;

		if ( ! $start_label || ! $end_label ) {
			return array(
				'success' => false,
				'error'   => __( 'Both start and end are required.', 'nvoos-graphify' ),
			);
		}

		// Resolve start node.
		$start_node = NV_oOS_Graphify_DB::get_node( $start_label );
		if ( ! $start_node ) {
			$results    = NV_oOS_Graphify_DB::search_nodes( $start_label, '', 1 );
			$start_node = ! empty( $results ) ? $results[0] : null;
		}

		// Resolve end node.
		$end_node = NV_oOS_Graphify_DB::get_node( $end_label );
		if ( ! $end_node ) {
			$results  = NV_oOS_Graphify_DB::search_nodes( $end_label, '', 1 );
			$end_node = ! empty( $results ) ? $results[0] : null;
		}

		if ( ! $start_node ) {
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Start node "%s" not found.', 'nvoos-graphify' ), $start_label ),
			);
		}
		if ( ! $end_node ) {
			return array(
				'success' => false,
				'error'   => sprintf( __( 'End node "%s" not found.', 'nvoos-graphify' ), $end_label ),
			);
		}

		$path_ids = NV_oOS_Graphify_Analyzer::shortest_path( $start_node->node_id, $end_node->node_id, $max_depth );

		if ( null === $path_ids ) {
			return array(
				'success' => false,
				'start'   => $start_node->label,
				'end'     => $end_node->label,
				'error'   => sprintf(
					__( 'No path found between "%1$s" and "%2$s" within depth %3$d.', 'nvoos-graphify' ),
					$start_node->label,
					$end_node->label,
					$max_depth
				),
			);
		}

		// Expand path IDs to full node details.
		$path_nodes = array();
		foreach ( $path_ids as $nid ) {
			$n            = NV_oOS_Graphify_DB::get_node( $nid );
			$path_nodes[] = $n ? array(
				'node_id' => $n->node_id,
				'label'   => $n->label,
				'type'    => $n->type,
				'url'     => $n->url,
			) : array( 'node_id' => $nid );
		}

		return array(
			'success'     => true,
			'start'       => $start_node->label,
			'end'         => $end_node->label,
			'path_length' => count( $path_ids ) - 1,
			'path'        => $path_nodes,
			'summary'     => sprintf(
				/* translators: 1: start label, 2: end label, 3: path length */
				__( 'Shortest path from "%1$s" to "%2$s" is %3$d hop(s).', 'nvoos-graphify' ),
				$start_node->label,
				$end_node->label,
				count( $path_ids ) - 1
			),
		);
	}
}
