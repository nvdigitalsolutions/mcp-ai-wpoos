<?php
/**
 * Graphify Tool — God Nodes
 *
 * Returns the most-connected content pillars in the knowledge graph.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_god_nodes
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_God_Nodes implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_god_nodes';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Knowledge Graph God Nodes', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Return the most-connected nodes in the knowledge graph — the "god nodes" or content pillars that act as hubs connecting many other pieces of content. High-degree nodes are ideal candidates for pillar pages, link-building targets, or topic cluster anchors.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Number of top nodes to return (default: 10, max: 50).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'type'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by node type: post, page, term, topic, entity, user, media. Leave empty for all types.', 'nvoos-graphify' ),
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
		$limit = isset( $arguments['limit'] ) ? max( 1, min( 50, absint( $arguments['limit'] ) ) ) : 10;
		$type  = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';

		$nodes = NV_oOS_Graphify_Analyzer::get_god_nodes( $limit, $type );

		$summary = sprintf(
			/* translators: %d: node count */
			_n(
				'Found %d content pillar node in the knowledge graph.',
				'Found %d content pillar nodes in the knowledge graph.',
				count( $nodes ),
				'nvoos-graphify'
			),
			count( $nodes )
		);

		return array(
			'success'    => true,
			'god_nodes'  => $nodes,
			'node_count' => count( $nodes ),
			'summary'    => $summary,
		);
	}
}
