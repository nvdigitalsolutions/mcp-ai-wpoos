<?php
/**
 * Tool: Content Gaps
 *
 * Identifies knowledge gaps and missing content opportunities from the graph.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyse the knowledge graph for content gaps and missing topics.
 *
 * Delegates to NV_oOS_Graphify_Analyzer::get_knowledge_gaps() and optionally
 * NV_oOS_Graphify_Analyzer::get_seo_insights() to identify topics that are
 * referenced in the graph but lack dedicated content.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Content_Gaps implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_content_gaps';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Content Gaps Analysis', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Identify knowledge gaps and missing content opportunities by analysing the site knowledge graph. Optionally include SEO insights for a combined content-strategy analysis.', 'nvoos-graphify' );
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
				'include_seo' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Whether to include SEO insights alongside the gap analysis.', 'nvoos-graphify' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Execute the content-gaps analysis.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Gap analysis on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		$include_seo = ! empty( $arguments['include_seo'] );

		$analyzer = new NV_oOS_Graphify_Analyzer();
		$gaps     = $analyzer->get_knowledge_gaps();

		if ( is_wp_error( $gaps ) ) {
			return $gaps;
		}

		$data = array(
			'knowledge_gaps' => $gaps,
		);

		if ( $include_seo ) {
			$seo_insights = $analyzer->get_seo_insights();

			if ( is_wp_error( $seo_insights ) ) {
				return $seo_insights;
			}

			$data['seo_insights'] = $seo_insights;
		}

		return array(
			'success' => true,
			'message' => $include_seo
				? __( 'Content gaps and SEO insights retrieved.', 'nvoos-graphify' )
				: __( 'Content gaps retrieved.', 'nvoos-graphify' ),
			'data'    => $data,
		);
	}
}
