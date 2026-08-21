<?php
/**
 * Tool: route_knowledge_query — Classify a knowledge query and route it (Pro).
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
 * Hybrid Knowledge Router tool.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Tool_Route_Knowledge_Query implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'route_knowledge_query';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Knowledge — Route Query', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Classifies a knowledge query and produces an ordered routing plan across the OKF bundles (curated markdown), the vector store (semantic embeddings), and Paper Store (structured records). When OKF is the primary route, also performs the OKF lookup and returns the top matching concepts. Use this to decide which store to query first for a given question.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'  => array(
					'type'        => 'string',
					'description' => __( 'The knowledge query to classify.', 'mcp-ai-wpoos-pro' ),
				),
				'bundle' => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle to search when OKF is part of the plan (default "site-knowledge").', 'mcp-ai-wpoos-pro' ),
					'default'     => 'site-knowledge',
				),
				'top'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum OKF results (default 5, max 10).', 'mcp-ai-wpoos-pro' ),
					'default'     => 5,
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		if ( '' === $query ) {
			return new WP_Error( 'missing_params', __( 'A query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Hybrid_Knowledge_Router' ) ) {
			return new WP_Error(
				'wp_mcp_ai_router_missing',
				__( 'The hybrid knowledge router is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$bundle = isset( $arguments['bundle'] ) ? sanitize_text_field( $arguments['bundle'] ) : 'site-knowledge';
		$top    = isset( $arguments['top'] ) ? absint( $arguments['top'] ) : 5;

		$router = new WP_MCP_AI_Hybrid_Knowledge_Router();
		$plan   = $router->classify( $query );

		$results  = array();
		$okf_note = '';
		if ( WP_MCP_AI_Hybrid_Knowledge_Router::SOURCE_OKF === $plan['primary'] ) {
			$found = $router->search_okf( $bundle, $query, $top );
			if ( is_wp_error( $found ) ) {
				$okf_note = $found->get_error_message();
			} else {
				$results = $found;
			}
		}

		$escaped_sources = array();
		foreach ( $plan['sources'] as $source ) {
			$escaped_sources[] = array(
				'source' => esc_html( $source['source'] ),
				'reason' => esc_html( $source['reason'] ),
			);
		}

		$escaped_results = array();
		foreach ( $results as $result ) {
			$escaped_results[] = array(
				'concept_id'  => esc_html( $result['concept_id'] ),
				'title'       => esc_html( $result['title'] ),
				'description' => esc_html( $result['description'] ),
				'trust_tier'  => esc_html( $result['trust_tier'] ),
				'stale'       => (bool) $result['stale'],
				'score'       => (int) $result['score'],
			);
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: primary source, 2: result count */
				__( 'Routed to "%1$s" first (%2$d OKF matches).', 'mcp-ai-wpoos-pro' ),
				$plan['primary'],
				count( $escaped_results )
			),
			array(
				'query'   => esc_html( $query ),
				'plan'    => $escaped_sources,
				'primary' => esc_html( $plan['primary'] ),
				'signals' => array_map( 'esc_html', (array) $plan['signals'] ),
				'results' => $escaped_results,
				'note'    => esc_html( $okf_note ),
			)
		);
	}
}
