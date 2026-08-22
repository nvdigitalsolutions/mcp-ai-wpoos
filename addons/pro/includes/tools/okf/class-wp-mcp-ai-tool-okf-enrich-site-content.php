<?php
/**
 * Tool: okf_enrich_site_content — Generate OKF concepts from site content (Pro).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Enrich Site Content tool.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Tool_OKF_Enrich_Site_Content implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_enrich_site_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Enrich from Site Content', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Crawls published WordPress content (posts, pages, other public post types, and optionally taxonomy terms) and auto-generates OKF concepts with cross-links into a bundle (default "site-content"). Deterministic and idempotent — re-running refreshes the same concepts. Descriptions can be upgraded to AI summaries via the wp_mcp_ai_okf_enrichment_description filter. Requires administrator capability because it writes many files.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'          => array(
					'type'        => 'string',
					'description' => __( 'Target bundle name (created on first run; default "site-content").', 'mcp-ai-wpoos-pro' ),
					'default'     => 'site-content',
				),
				'post_types'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Post types to crawl (default post, page).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum content items (default 50; hard cap 200).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
				),
				'include_terms'   => array(
					'type'        => 'boolean',
					'description' => __( 'Also generate concepts for public taxonomy terms (default false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'include_content' => array(
					'type'        => 'boolean',
					'description' => __( 'Include a trimmed copy of the post content in the concept body (default true).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Enrichment_Agent' ) ) {
			return new WP_Error( 'wp_mcp_ai_okf_engine_missing', __( 'The OKF enrichment agent is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$args = array(
			'bundle'          => isset( $arguments['bundle'] ) ? sanitize_text_field( $arguments['bundle'] ) : 'site-content',
			'limit'           => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50,
			'include_terms'   => ! empty( $arguments['include_terms'] ),
			'include_content' => ! isset( $arguments['include_content'] ) || ! empty( $arguments['include_content'] ),
		);

		if ( isset( $arguments['post_types'] ) && is_array( $arguments['post_types'] ) ) {
			$args['post_types'] = array_map( 'sanitize_key', $arguments['post_types'] );
		}

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: bundle name, 2: concept count */
				__( 'Enriched bundle "%1$s" with %2$d concepts.', 'mcp-ai-wpoos-pro' ),
				$result['bundle'],
				$result['concepts']
			),
			array(
				'bundle'   => esc_html( $result['bundle'] ),
				'created'  => (int) $result['created'],
				'skipped'  => (int) $result['skipped'],
				'concepts' => (int) $result['concepts'],
				'errors'   => array_map( 'esc_html', (array) $result['errors'] ),
			)
		);
	}
}
