<?php
/**
 * Tool for identifying content gaps in the knowledge graph.
 *
 * Analyzes the graph for orphan nodes, thin communities, and
 * missing content topics, combining knowledge gap analysis with
 * SEO insights to produce actionable recommendations.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Gaps Tool.
 *
 * Identifies topics with thin communities or isolated nodes and
 * suggests new content to create, combining knowledge gap data
 * with SEO insight analysis.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Content_Gaps implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_content_gaps';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Content Gap Analysis', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Identify topics with thin communities or isolated nodes and suggest new content to create.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 0.2.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'content_strategist', 'seo_specialist', 'editor' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view content gap analysis.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		$knowledge_gaps = NV_oOS_Graphify_Analyzer::get_knowledge_gaps();
		$seo_insights   = NV_oOS_Graphify_Analyzer::get_seo_insights();

		if ( is_wp_error( $knowledge_gaps ) ) {
			return $knowledge_gaps;
		}

		if ( is_wp_error( $seo_insights ) ) {
			return $seo_insights;
		}

		// Extract orphan nodes (nodes with zero or very low degree).
		$orphan_nodes = array();
		if ( isset( $knowledge_gaps['orphan_nodes'] ) && is_array( $knowledge_gaps['orphan_nodes'] ) ) {
			foreach ( $knowledge_gaps['orphan_nodes'] as $orphan ) {
				$orphan_nodes[] = array(
					'node_id'    => isset( $orphan['node_id'] ) ? $orphan['node_id'] : '',
					'label'      => isset( $orphan['label'] ) ? $orphan['label'] : '',
					'type'       => isset( $orphan['node_type'] ) ? $orphan['node_type'] : '',
					'source_url' => isset( $orphan['source_url'] ) ? $orphan['source_url'] : '',
				);
			}
		}

		// Extract thin communities (communities with very few members).
		$thin_communities = array();
		if ( isset( $knowledge_gaps['thin_communities'] ) && is_array( $knowledge_gaps['thin_communities'] ) ) {
			foreach ( $knowledge_gaps['thin_communities'] as $community ) {
				$thin_communities[] = array(
					'community_id' => isset( $community['community_id'] ) ? $community['community_id'] : '',
					'member_count' => isset( $community['member_count'] ) ? (int) $community['member_count'] : 0,
					'top_label'    => isset( $community['top_label'] ) ? $community['top_label'] : '',
				);
			}
		}

		// Extract content suggestions.
		$suggestions = array();
		if ( isset( $knowledge_gaps['suggestions'] ) && is_array( $knowledge_gaps['suggestions'] ) ) {
			foreach ( $knowledge_gaps['suggestions'] as $suggestion ) {
				$suggestions[] = array(
					'topic'      => isset( $suggestion['topic'] ) ? $suggestion['topic'] : '',
					'reason'     => isset( $suggestion['reason'] ) ? $suggestion['reason'] : '',
					'priority'   => isset( $suggestion['priority'] ) ? $suggestion['priority'] : 'medium',
					'related_to' => isset( $suggestion['related_to'] ) ? $suggestion['related_to'] : array(),
				);
			}
		}

		// Format SEO insights.
		$seo_data = array();
		if ( is_array( $seo_insights ) ) {
			foreach ( $seo_insights as $insight ) {
				$seo_data[] = array(
					'type'        => isset( $insight['type'] ) ? $insight['type'] : '',
					'description' => isset( $insight['description'] ) ? $insight['description'] : '',
					'impact'      => isset( $insight['impact'] ) ? $insight['impact'] : '',
					'nodes'       => isset( $insight['nodes'] ) ? $insight['nodes'] : array(),
				);
			}
		}

		$total_issues = count( $orphan_nodes ) + count( $thin_communities ) + count( $suggestions );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: orphan count, 2: thin community count, 3: suggestion count */
				__( 'Found %1$d orphan nodes, %2$d thin communities, and %3$d content suggestions.', 'mcp-ai-wpoos' ),
				count( $orphan_nodes ),
				count( $thin_communities ),
				count( $suggestions )
			),
			'data'    => array(
				'total_issues'     => $total_issues,
				'orphan_nodes'     => $orphan_nodes,
				'thin_communities' => $thin_communities,
				'suggestions'      => $suggestions,
				'seo_insights'     => $seo_data,
			),
		);
	}
}
