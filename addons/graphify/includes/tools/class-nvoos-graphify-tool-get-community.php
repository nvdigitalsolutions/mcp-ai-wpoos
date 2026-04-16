<?php
/**
 * Tool for retrieving all members of a knowledge graph community.
 *
 * Returns every node belonging to a given topic cluster (community)
 * with labels, types, and source references.
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 * @author  NV Digital Solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Community Tool.
 *
 * Retrieves all content in a topic cluster (community) from the
 * knowledge graph using the Graphify cluster analysis API.
 *
 * @since 0.2.0
 */
class NV_oOS_Graphify_Tool_Get_Community implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'graphify_get_community';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Get Community Members', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Get all content in a topic cluster (community) from the knowledge graph.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'community_id' => array(
					'type'        => 'integer',
					'description' => __( 'The numeric ID of the community to retrieve members for.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'community_id' ),
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
			'cacheable',
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
			'profession_tags'       => array( 'developer', 'content_strategist', 'seo_specialist' ),
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
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view graph communities.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! NV_oOS_Graphify::is_enabled() ) {
			return new WP_Error(
				'graphify_disabled',
				__( 'The Graphify addon is not enabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! isset( $arguments['community_id'] ) ) {
			return new WP_Error(
				'missing_community_id',
				__( 'A community_id is required.', 'mcp-ai-wpoos' )
			);
		}

		$community_id = absint( $arguments['community_id'] );

		$members = NV_oOS_Graphify_Cluster::get_community_members( $community_id );

		if ( is_wp_error( $members ) ) {
			return $members;
		}

		if ( empty( $members ) ) {
			return new WP_Error(
				'community_empty',
				sprintf(
					/* translators: %d: community ID */
					__( 'Community %d has no members or does not exist.', 'mcp-ai-wpoos' ),
					$community_id
				)
			);
		}

		$formatted = array();
		foreach ( $members as $member ) {
			$formatted[] = array(
				'node_id'    => isset( $member['node_id'] ) ? $member['node_id'] : '',
				'label'      => isset( $member['label'] ) ? $member['label'] : '',
				'type'       => isset( $member['node_type'] ) ? $member['node_type'] : '',
				'source_id'  => isset( $member['source_id'] ) ? $member['source_id'] : '',
				'source_url' => isset( $member['source_url'] ) ? $member['source_url'] : '',
				'degree'     => isset( $member['degree'] ) ? (int) $member['degree'] : 0,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: community ID, 2: member count */
				__( 'Community %1$d contains %2$d members.', 'mcp-ai-wpoos' ),
				$community_id,
				count( $formatted )
			),
			'data'    => array(
				'community_id' => $community_id,
				'member_count' => count( $formatted ),
				'members'      => $formatted,
			),
		);
	}
}
