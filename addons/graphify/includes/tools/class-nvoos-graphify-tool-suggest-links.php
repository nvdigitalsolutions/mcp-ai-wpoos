<?php
/**
 * Tool: Suggest Links
 *
 * Recommends internal links between posts based on knowledge-graph analysis.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggest internal-link opportunities between WordPress posts.
 *
 * Delegates to NV_oOS_Graphify_Analyzer::get_content_recommendations() which
 * identifies strongly related but unlinked content based on shared graph
 * neighborhoods, community co-membership, and edge confidence.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Suggest_Links implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_suggest_links';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Suggest Internal Links', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Recommend internal-link opportunities between posts based on knowledge-graph analysis. Returns pairs of posts that are topically related but not yet linked, with confidence scores and reasoning.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only' );
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
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Maximum number of link suggestions to return.', 'nvoos-graphify' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Execute the link-suggestion analysis.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Link suggestions on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = max( 1, min( 50, $limit ) );

		$analyzer        = new NV_oOS_Graphify_Analyzer();
		$recommendations = $analyzer->get_content_recommendations();

		// Trim to requested limit since the analyzer returns all recommendations.
		if ( is_array( $recommendations ) ) {
			$recommendations = array_slice( $recommendations, 0, $limit );
		}

		if ( is_wp_error( $recommendations ) ) {
			return $recommendations;
		}

		$suggestions = array();
		if ( is_array( $recommendations ) ) {
			foreach ( $recommendations as $rec ) {
				$suggestions[] = array(
					'source_post' => isset( $rec['source_post'] ) ? absint( $rec['source_post'] ) : 0,
					'target_post' => isset( $rec['target_post'] ) ? absint( $rec['target_post'] ) : 0,
					'reason'      => isset( $rec['reason'] ) ? sanitize_text_field( $rec['reason'] ) : '',
					'confidence'  => isset( $rec['confidence'] ) ? (float) $rec['confidence'] : 0.0,
				);
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of suggestions */
				__( 'Found %d link suggestion(s).', 'nvoos-graphify' ),
				count( $suggestions )
			),
			'data'    => $suggestions,
		);
	}
}
