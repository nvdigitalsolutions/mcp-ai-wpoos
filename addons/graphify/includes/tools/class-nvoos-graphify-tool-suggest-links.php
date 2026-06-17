<?php
/**
 * Graphify Tool — Suggest Links
 *
 * Internal link suggestions based on knowledge graph communities.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_suggest_links
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Tool_Suggest_Links implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_suggest_links';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Suggest Internal Links', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Identify missing internal links between content pieces that are in the same knowledge community. Returns ranked suggestions of post pairs that share a topic cluster but are not yet directly linked — actionable SEO and UX improvements.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum link suggestions to return (default: 10, max: 50).', 'nvoos-graphify' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Focus suggestions on a specific post ID (optional).', 'nvoos-graphify' ),
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$limit   = isset( $arguments['limit'] ) ? max( 1, min( 50, absint( $arguments['limit'] ) ) ) : 10;
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( $post_id ) {
			$node = NV_oOS_Graphify_DB::get_node_by_post_id( $post_id );
			if ( ! $node || ! $node->community_id ) {
				return array(
					'success'          => true,
					'suggestions'      => array(),
					'suggestion_count' => 0,
					'message'          => __( 'No community data available for this post. Run graphify_build_graph first.', 'nvoos-graphify' ),
				);
			}
		}

		$suggestions = NV_oOS_Graphify_Analyzer::get_recommendations( $limit );

		// Filter to specific post if provided.
		if ( $post_id && $node ) {
			$suggestions = array_filter(
				$suggestions,
				function ( $s ) use ( $node ) {
					return isset( $s['source_node'] ) && (
						$s['source_node'] === $node->node_id || $s['target_node'] === $node->node_id
					);
				}
			);
			$suggestions = array_values( $suggestions );
		}

		return array(
			'success'          => true,
			'suggestions'      => $suggestions,
			'suggestion_count' => count( $suggestions ),
			'summary'          => sprintf(
				/* translators: %d: suggestion count */
				_n(
					'Found %d internal link opportunity.',
					'Found %d internal link opportunities.',
					count( $suggestions ),
					'nvoos-graphify'
				),
				count( $suggestions )
			),
		);
	}
}
