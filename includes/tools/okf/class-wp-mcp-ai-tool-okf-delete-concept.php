<?php
/**
 * Tool: okf_delete_concept — Delete (archive) an OKF concept.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   1.1.62 — Bundle path resolution via WP_MCP_AI_OKF_Bundle_Manager;
 *                auto-generated bundles are protected from direct deletion.
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Delete Concept tool.
 */
class WP_MCP_AI_Tool_OKF_Delete_Concept implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_delete_concept';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Delete Concept', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Archives an OKF concept (v0.2) by renaming it with a .deleted extension (recoverable). Prefer setting status: deprecated over deletion when the concept should remain discoverable for historical reference. Use deletion only for genuinely erroneous content.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Concept ID to delete.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'bundle', 'concept_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'delete_posts';
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

		if ( empty( $bundle ) || empty( $concept_id ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle and concept_id are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		// Auto-generated bundles (skill-knowledge) are rebuilt by the plugin
		// on upgrades; refuse direct deletions.
		$writable = $manager->assert_bundle_writable( $bundle );
		if ( is_wp_error( $writable ) ) {
			return $writable;
		}

		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );
		$result = $writer->delete_concept( $concept_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Maintain the bundle's log.md (OKF v0.2 §9). Best-effort: a log
		// failure must not fail the deletion itself.
		$log_dir = dirname( $concept_id );
		if ( '.' === $log_dir ) {
			$log_dir = '';
		}
		$manager->append_log(
			$bundle,
			$log_dir,
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" archived.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			'Deletion'
		);

		return $this->format_success_response(
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" archived successfully.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			array(
				'bundle'     => esc_html( $bundle ),
				'concept_id' => esc_html( $concept_id ),
			)
		);
	}
}
