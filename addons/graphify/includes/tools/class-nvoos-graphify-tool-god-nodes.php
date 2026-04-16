<?php
/**
 * Tool: God Nodes
 *
 * Returns the most highly connected nodes in the knowledge graph.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identify the "god nodes" — the most connected hubs in the graph.
 *
 * Delegates to NV_oOS_Graphify_Analyzer::get_god_nodes() which ranks nodes
 * by degree centrality and returns the top N results.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_God_Nodes implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_god_nodes';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'God Nodes (Top Hubs)', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Return the most highly connected hub nodes in the knowledge graph ranked by degree centrality. Useful for identifying key topics and content pillars.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'top_n' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Number of top hub nodes to return.', 'nvoos-graphify' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Execute the god-nodes analysis.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error List of top hub nodes on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$top_n = isset( $arguments['top_n'] ) ? absint( $arguments['top_n'] ) : 10;
		$top_n = max( 1, min( 50, $top_n ) );

		$analyzer  = new NV_oOS_Graphify_Analyzer();
		$god_nodes = $analyzer->get_god_nodes( $top_n );

		if ( is_wp_error( $god_nodes ) ) {
			return $god_nodes;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of god nodes returned */
				__( 'Top %d hub node(s) retrieved.', 'nvoos-graphify' ),
				count( $god_nodes )
			),
			'data'    => $god_nodes,
		);
	}
}
