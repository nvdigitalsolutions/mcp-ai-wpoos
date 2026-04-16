<?php
/**
 * Tool for finding the shortest path between two nodes in the knowledge graph.
 *
 * Uses the Graphify analyzer to perform a bounded BFS between a
 * source and target node label, returning the path with all
 * intermediate nodes and edges.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortest Path Tool.
 *
 * Finds the shortest content path between two topics or posts in
 * the knowledge graph, with configurable maximum hop count.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Shortest_Path implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_shortest_path';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Shortest Path', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Find the shortest content path between two topics or posts in the knowledge graph.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source'   => array(
					'type'        => 'string',
					'description' => __( 'Label of the starting node.', 'mcp-ai-wpoos' ),
				),
				'target'   => array(
					'type'        => 'string',
					'description' => __( 'Label of the destination node.', 'mcp-ai-wpoos' ),
				),
				'max_hops' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of hops to search. Defaults to 6, maximum 10.', 'mcp-ai-wpoos' ),
					'default'     => 6,
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'source', 'target' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'cacheable',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 0.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'developer', 'content_strategist', 'seo_specialist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to query the knowledge graph.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['source'] ) ) {
			return new WP_Error(
				'missing_source',
				__( 'A source node label is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $arguments['target'] ) ) {
			return new WP_Error(
				'missing_target',
				__( 'A target node label is required.', 'mcp-ai-wpoos' )
			);
		}

		$source   = sanitize_text_field( $arguments['source'] );
		$target   = sanitize_text_field( $arguments['target'] );
		$max_hops = isset( $arguments['max_hops'] ) ? absint( $arguments['max_hops'] ) : 6;
		$max_hops = max( 1, min( 10, $max_hops ) );

		$result = NV_oOS_Graphify_Analyzer::find_shortest_path( $source, $target, $max_hops );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$found = ! empty( $result['path'] );
		$nodes = isset( $result['path'] ) ? $result['path'] : array();
		$edges = isset( $result['edges'] ) ? $result['edges'] : array();
		$hops  = ! empty( $nodes ) ? count( $nodes ) - 1 : 0;

		if ( ! $found ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: source label, 2: target label, 3: max hops */
					__( 'No path found from "%1$s" to "%2$s" within %3$d hops.', 'mcp-ai-wpoos' ),
					$source,
					$target,
					$max_hops
				),
				'data'    => array(
					'path_found' => false,
					'source'     => $source,
					'target'     => $target,
					'max_hops'   => $max_hops,
					'hops'       => 0,
					'path'       => array(),
					'edges'      => array(),
				),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: source label, 2: target label, 3: number of hops */
				__( 'Path found from "%1$s" to "%2$s" in %3$d hops.', 'mcp-ai-wpoos' ),
				$source,
				$target,
				$hops
			),
			'data'    => array(
				'path_found' => true,
				'source'     => $source,
				'target'     => $target,
				'max_hops'   => $max_hops,
				'hops'       => $hops,
				'path'       => $nodes,
				'edges'      => $edges,
			),
		);
	}
}
