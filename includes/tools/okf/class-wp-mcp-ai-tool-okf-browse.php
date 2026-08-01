<?php
	/**
	 * Tool: okf_browse — Browse an OKF bundle directory.
	 *
	 * @package WP_MCP_AI
	 * @since   2.1.0
	 * @since   2.5.0 — Surfaces OKF v0.2 trust-signal fields for each concept.
	 * @author  NV Digital Solutions
	 * @copyright Copyright (c) 2026 NV Digital Solutions
	 * @license  GPL-3.0-or-later
	 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Browse tool.
 */
class WP_MCP_AI_Tool_OKF_Browse implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_browse';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Browse Bundle', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Browses an OKF bundle (v0.2) directory via its index.md, listing available concepts and subdirectories with trust-signal summaries (type, status, trust tier). Use this to discover what knowledge is available before reading specific concepts.', 'mcp-ai-wpoos' );
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
					'description' => __( 'OKF bundle name (e.g. "skill-knowledge", "site-knowledge").', 'mcp-ai-wpoos' ),
				),
				'path'   => array(
					'type'        => 'string',
					'description' => __( 'Directory path within the bundle (empty for root).', 'mcp-ai-wpoos' ),
					'default'     => '',
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
		$bundle = sanitize_text_field( $arguments['bundle'] );
		$path   = isset( $arguments['path'] ) ? sanitize_text_field( $arguments['path'] ) : '';

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

		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$entries = $reader->browse( $path );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$escaped_entries = array();
		foreach ( $entries as $entry ) {
			$item = array(
				'title'       => esc_html( $entry['title'] ),
				'path'        => esc_html( $entry['path'] ),
				'description' => esc_html( $entry['description'] ),
			);

			// Enrich with OKF v0.2 trust signals when available.
			if ( ! empty( $entry['path'] ) && '/' !== substr( $entry['path'], -1 ) ) {
				$concept_id = preg_replace( '/\.md$/', '', $entry['path'] );
				$concept    = $reader->get_concept( $concept_id );
				if ( ! is_wp_error( $concept ) ) {
					$fm                 = $concept['frontmatter'];
					$item['type']       = isset( $fm['type'] ) ? esc_html( $fm['type'] ) : '';
					$item['status']     = isset( $fm['status'] ) ? esc_html( $fm['status'] ) : 'stable';
					$item['trust_tier'] = esc_html( $reader->get_trust_tier( $fm ) );
					$item['stale']      = $reader->is_stale( $fm );
				}
			}

			$escaped_entries[] = $item;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: bundle name, 2: entry count */
				__( 'Browsed bundle "%1$s" — %2$d entries found.', 'mcp-ai-wpoos' ),
				$bundle,
				count( $escaped_entries )
			),
			array(
				'bundle'  => esc_html( $bundle ),
				'path'    => esc_html( $path ),
				'entries' => $escaped_entries,
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
