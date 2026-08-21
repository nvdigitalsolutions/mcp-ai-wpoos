<?php
/**
 * Tool: okf_traverse — Traverse OKF concept links.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   1.1.62 — Bundle path resolution via WP_MCP_AI_OKF_Bundle_Manager.
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Traverse tool.
 */
class WP_MCP_AI_Tool_OKF_Traverse implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_traverse';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Traverse Links', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Follows cross-links from an OKF concept (v0.2) up to a specified depth, returning the subgraph of connected concepts with trust-signal summaries. Use this to explore related knowledge without guessing which concepts are relevant.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'     => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name.', 'mcp-ai-wpoos' ),
				),
				'concept_id' => array(
					'type'        => 'string',
					'description' => __( 'Starting concept ID.', 'mcp-ai-wpoos' ),
				),
				'depth'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum link-following depth (1-5, default 2).', 'mcp-ai-wpoos' ),
					'default'     => 2,
				),
			),
			'required'   => array( 'bundle', 'concept_id' ),
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
		$bundle     = sanitize_text_field( $arguments['bundle'] );
		$concept_id = sanitize_text_field( $arguments['concept_id'] );
		$depth      = isset( $arguments['depth'] ) ? absint( $arguments['depth'] ) : 2;

		if ( empty( $bundle ) || empty( $concept_id ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle and concept_id are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$reader = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$result = $reader->traverse( $concept_id, $depth );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: concept ID, 2: depth */
				__( 'Traversed concept "%1$s" at depth %2$d.', 'mcp-ai-wpoos' ),
				$concept_id,
				$depth
			),
			array(
				'bundle'   => esc_html( $bundle ),
				'depth'    => $depth,
				'subgraph' => $result,
			)
		);
	}
}
