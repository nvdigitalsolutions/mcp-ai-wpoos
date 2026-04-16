<?php
/**
 * Tool for retrieving the most-connected nodes in the knowledge graph.
 *
 * Returns "god nodes" — the content pillars with the highest degree
 * centrality — using the Graphify analyzer API.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * God Nodes Tool.
 *
 * Identifies and returns the most-connected content nodes in the
 * knowledge graph, representing the core pillars of the site.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_God_Nodes implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_god_nodes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Knowledge Graph God Nodes', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( "Return the most-connected content nodes — the core pillars of the site's knowledge graph.", 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'top_n' => array(
					'type'        => 'integer',
					'description' => __( 'Number of top nodes to return. Defaults to 10, maximum 50.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
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
			'profession_tags'       => array( 'content_strategist', 'seo_specialist', 'editor' ),
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
				__( 'You do not have permission to view graph analytics.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		$top_n = isset( $arguments['top_n'] ) ? absint( $arguments['top_n'] ) : 10;
		$top_n = max( 1, min( 50, $top_n ) );

		$god_nodes = NV_oOS_Graphify_Analyzer::get_god_nodes( $top_n );

		if ( is_wp_error( $god_nodes ) ) {
			return $god_nodes;
		}

		if ( empty( $god_nodes ) ) {
			return array(
				'success' => true,
				'message' => __( 'No god nodes found. The knowledge graph may be empty.', 'mcp-ai-wpoos' ),
				'data'    => array(
					'top_n'     => $top_n,
					'god_nodes' => array(),
				),
			);
		}

		$formatted = array();
		foreach ( $god_nodes as $node ) {
			$formatted[] = array(
				'node_id'   => isset( $node['node_id'] ) ? $node['node_id'] : '',
				'label'     => isset( $node['label'] ) ? $node['label'] : '',
				'type'      => isset( $node['node_type'] ) ? $node['node_type'] : '',
				'degree'    => isset( $node['degree'] ) ? (int) $node['degree'] : 0,
				'community' => isset( $node['community'] ) ? $node['community'] : null,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of god nodes returned */
				__( 'Returning the top %d most-connected nodes.', 'mcp-ai-wpoos' ),
				count( $formatted )
			),
			'data'    => array(
				'top_n'     => $top_n,
				'god_nodes' => $formatted,
			),
		);
	}
}
