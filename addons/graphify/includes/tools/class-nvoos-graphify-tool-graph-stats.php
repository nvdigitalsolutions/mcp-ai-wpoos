<?php
/**
 * Graphify Tool — Graph Stats
 *
 * Returns aggregate statistics about the knowledge graph.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_graph_stats
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Graph_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_graph_stats';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Knowledge Graph Statistics', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Return aggregate statistics about the site knowledge graph: total node and edge counts, breakdown by type, edge confidence distribution, number of communities, and last build timestamp. Use this to understand how well the knowledge graph has been built before running deeper analysis tools.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
		$stats        = NV_oOS_Graphify_DB::get_stats();
		$last_build   = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', 'never' );
		$build_status = NV_oOS_Graphify_DB::get_meta( 'build_status', 'idle' );

		return array(
			'success'      => true,
			'stats'        => $stats,
			'last_build'   => $last_build,
			'build_status' => $build_status,
		);
	}
}
