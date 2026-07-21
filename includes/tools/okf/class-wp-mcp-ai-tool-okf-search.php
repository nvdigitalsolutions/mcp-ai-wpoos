<?php
/**
 * Tool: okf_search — Search OKF concepts by type or tag.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
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
		return __( 'Searches an OKF bundle for concepts matching a given type and/or tag. Returns a list of matching concept summaries with their concept IDs, titles, and descriptions.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle' => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name.', 'mcp-ai-wpoos' ),
				),
				'type'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by concept type (e.g. "Skill", "Policy", "Procedure").', 'mcp-ai-wpoos' ),
				),
				'tag'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by tag (e.g. "security", "performance").', 'mcp-ai-wpoos' ),
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
		$bundle    = sanitize_text_field( $arguments['bundle'] );
		$type      = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		$tag       = isset( $arguments['tag'] ) ? sanitize_text_field( $arguments['tag'] ) : '';

		if ( empty( $bundle ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle name is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$bundle_root = $this->resolve_bundle_root( $bundle );
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
					'type' => esc_html( $type ),
					'tag'  => esc_html( $tag ),
				),
				'results'  => $escaped,
			)
		);
	}

	/**
	 * Resolve a bundle name to an absolute directory path.
	 *
	 * @param string $bundle Bundle name.
	 * @return string|WP_Error
	 */
	private function resolve_bundle_root( $bundle ) {
		if ( false !== strpos( $bundle, '..' ) ) {
			return new WP_Error( 'okf_invalid_bundle', __( 'Invalid bundle name.', 'mcp-ai-wpoos' ) );
		}

		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'] . '/mcp-ai-wpoos/knowledge';
		$path       = wp_normalize_path( $base . '/' . $bundle );

		if ( is_dir( $path ) ) {
			return $path;
		}

		return new WP_Error(
			'okf_bundle_not_found',
			sprintf(
				/* translators: %s: bundle name */
				__( 'OKF bundle not found: %s', 'mcp-ai-wpoos' ),
				$bundle
			)
		);
	}
}
