<?php
/**
 * Tool — Knowledge Graph Statistics
 *
 * Returns summary statistics about the site's knowledge graph including
 * node/edge counts, community count, type/confidence breakdowns,
 * and build status.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Graph Statistics tool implementation.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Graphify_Stats implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'graphify_graph_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Knowledge Graph Statistics', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns summary statistics about the site knowledge graph: total nodes, edges, communities, average degree, content type breakdown, relationship types, confidence levels, and last build timestamp. Use this to understand the site content structure at a glance.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Stats or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view graph statistics.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$graphify = WP_MCP_AI_Graphify::get_instance();
		$stats    = $graphify->get_stats();

		if ( isset( $stats['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_graphify_no_graph', $stats['error'] );
		}

		// Check if graph has been built.
		if ( 0 === $stats['node_count'] && 'idle' === $stats['build_status'] ) {
			return $this->format_chat_response(
				$stats,
				__( 'The knowledge graph has not been built yet. Use the graphify_build_graph tool to build it.', 'mcp-ai-wpoos' )
			);
		}

		$message = sprintf(
			/* translators: 1: node count, 2: edge count, 3: community count, 4: average degree */
			__( 'Knowledge graph: %1$d nodes, %2$d edges, %3$d communities, avg degree %4$s.', 'mcp-ai-wpoos' ),
			$stats['node_count'],
			$stats['edge_count'],
			$stats['community_count'],
			$stats['average_degree']
		);

		return $this->format_chat_response( $stats, $message );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.6.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'web_developer', 'content_strategist', 'seo_specialist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
			'cacheable',
		);
	}
}
