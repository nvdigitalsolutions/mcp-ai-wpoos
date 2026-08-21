<?php
	/**
	 * Tool: okf_search — Search OKF concepts by type, tag, status, or trust tier.
	 *
	 * @package WP_MCP_AI
	 * @since   2.1.0
	 * @since   2.5.0 — Added OKF v0.2 filter criteria: status, trust_tier, include_stale.
	 * @author  NV Digital Solutions
	 * @copyright Copyright (c) 2026 NV Digital Solutions
	 * @license  GPL-3.0-or-later
	 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Search tool.
 */
class WP_MCP_AI_Tool_OKF_Search implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Search Concepts', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches an OKF bundle (v0.2) for concepts matching type, tag, lifecycle status, and/or trust tier. Returns concept summaries including OKF v0.2 trust signals: status, trust_tier, and staleness. Use this to filter for human-reviewed concepts only, or to surface deprecated/stale concepts that need attention.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'        => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name.', 'mcp-ai-wpoos' ),
				),
				'type'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by concept type (e.g. "Skill", "Policy", "Procedure").', 'mcp-ai-wpoos' ),
				),
				'tag'           => array(
					'type'        => 'string',
					'description' => __( 'Filter by tag (e.g. "security", "performance").', 'mcp-ai-wpoos' ),
				),
				'status'        => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'stable', 'deprecated' ),
					'description' => __( 'Filter by lifecycle status (OKF v0.2). Use "deprecated" to find retired concepts.', 'mcp-ai-wpoos' ),
				),
				'trust_tier'    => array(
					'type'        => 'string',
					'enum'        => array( 'unverified', 'machine-confirmed', 'human-reviewed' ),
					'description' => __( 'Filter by trust tier (OKF v0.2). Use "human-reviewed" for concepts signed off by a person.', 'mcp-ai-wpoos' ),
				),
				'include_stale' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include stale concepts (past their stale_after date). Default true.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'bundle' ),
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
		$bundle        = sanitize_text_field( $arguments['bundle'] );
		$type          = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		$tag           = isset( $arguments['tag'] ) ? sanitize_text_field( $arguments['tag'] ) : '';
		$status        = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$trust_tier    = isset( $arguments['trust_tier'] ) ? sanitize_text_field( $arguments['trust_tier'] ) : '';
		$include_stale = isset( $arguments['include_stale'] ) ? (bool) $arguments['include_stale'] : true;

		if ( empty( $bundle ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle name is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$criteria = array();
		if ( ! empty( $type ) ) {
			$criteria['type'] = $type;
		}
		if ( ! empty( $tag ) ) {
			$criteria['tag'] = $tag;
		}
		if ( ! empty( $status ) ) {
			$criteria['status'] = $status;
		}
		if ( ! empty( $trust_tier ) ) {
			$criteria['trust_tier'] = $trust_tier;
		}
		$criteria['include_stale'] = $include_stale;

		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$results = $reader->search( $criteria );

		$escaped = array();
		foreach ( $results as $result ) {
			$escaped[] = array(
				'concept_id'  => esc_html( $result['concept_id'] ),
				'type'        => esc_html( $result['type'] ),
				'title'       => esc_html( $result['title'] ),
				'description' => esc_html( $result['description'] ),
				'tags'        => array_map( 'esc_html', (array) $result['tags'] ),
				'status'      => esc_html( $result['status'] ),
				'trust_tier'  => esc_html( $result['trust_tier'] ),
				'stale'       => $result['stale'],
			);
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %d: result count */
				__( 'Found %d matching concepts.', 'mcp-ai-wpoos' ),
				count( $escaped )
			),
			array(
				'bundle'   => esc_html( $bundle ),
				'criteria' => array(
					'type'          => esc_html( $type ),
					'tag'           => esc_html( $tag ),
					'status'        => esc_html( $status ),
					'trust_tier'    => esc_html( $trust_tier ),
					'include_stale' => $include_stale,
				),
				'results'  => $escaped,
			)
		);
	}
}
