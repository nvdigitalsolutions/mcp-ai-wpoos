<?php
/**
 * Graphify Tool — Build Graph
 *
 * Triggers a full or incremental knowledge graph build pipeline.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_build_graph
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Build_Graph implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_build_graph';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Build Knowledge Graph', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Build or rebuild the WordPress knowledge graph. Detects published posts, taxonomy terms, users, and media; extracts structural links (internal links, taxonomies, authorship) and optionally AI-powered semantic entities and topics. Returns a summary of nodes and edges created. Use incremental=true to only process content changed since the last build.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'incremental'    => array(
					'type'        => 'boolean',
					'description' => __( 'Only process content modified since the last build. Faster for routine updates. Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
				'semantic'       => array(
					'type'        => 'boolean',
					'description' => __( 'Run AI-powered semantic entity and topic extraction. Requires an AI provider. Default: true.', 'nvoos-graphify' ),
					'default'     => true,
				),
				'async_semantic' => array(
					'type'        => 'boolean',
					'description' => __( 'Dispatch semantic extraction to WP Cron (non-blocking). Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
				'reset'          => array(
					'type'        => 'boolean',
					'description' => __( 'Truncate existing graph before building. Only applies when incremental=false. Default: false.', 'nvoos-graphify' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'async', 'long-running', 'performance-impact' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'graphify_forbidden', __( 'Building the knowledge graph requires administrator access.', 'nvoos-graphify' ) );
		}

		$result = NV_oOS_Graphify_Builder::build(
			array(
				'incremental'    => ! empty( $arguments['incremental'] ),
				'semantic'       => ! isset( $arguments['semantic'] ) || ! empty( $arguments['semantic'] ),
				'async_semantic' => ! empty( $arguments['async_semantic'] ),
				'reset'          => ! empty( $arguments['reset'] ),
			)
		);

		return $result;
	}
}
