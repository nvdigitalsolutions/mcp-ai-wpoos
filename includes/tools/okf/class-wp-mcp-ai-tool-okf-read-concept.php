<?php
/**
 * Tool: okf_read_concept — Read a single OKF concept.
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
 * OKF — Read Concept tool.
 */
class WP_MCP_AI_Tool_OKF_Read_Concept implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_read_concept';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Read Concept', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads a single OKF concept by its concept ID (e.g. "policies/patient-admission"). Returns the frontmatter metadata and markdown body. Use this to retrieve curated, authoritative knowledge from the Open Knowledge Format bundle.', 'mcp-ai-wpoos' );
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
					'description' => __( 'OKF bundle name or path (e.g. "skill-knowledge", "site-knowledge").', 'mcp-ai-wpoos' ),
				),
				'concept_id' => array(
					'type'        => 'string',
					'description' => __( 'Concept ID — the file path without .md suffix (e.g. "skills/wp-security-audit").', 'mcp-ai-wpoos' ),
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
		// Gate 1 — Sanitize at entry.
		$bundle     = sanitize_text_field( $arguments['bundle'] );
		$concept_id = sanitize_text_field( $arguments['concept_id'] );

		if ( empty( $bundle ) || empty( $concept_id ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle and concept_id are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$bundle_root = $this->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$concept = $reader->get_concept( $concept_id );

		if ( is_wp_error( $concept ) ) {
			return $concept;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" retrieved.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			array(
				'bundle'      => esc_html( $bundle ),
				'concept_id'  => esc_html( $concept['concept_id'] ),
				'type'        => isset( $concept['frontmatter']['type'] ) ? esc_html( $concept['frontmatter']['type'] ) : '',
				'title'       => isset( $concept['frontmatter']['title'] ) ? esc_html( $concept['frontmatter']['title'] ) : '',
				'description' => isset( $concept['frontmatter']['description'] ) ? esc_html( $concept['frontmatter']['description'] ) : '',
				'frontmatter' => $this->escape_frontmatter( $concept['frontmatter'] ),
				'body'        => wp_kses_post( $concept['body'] ),
			)
		);
	}

	/**
	 * Resolve a bundle name to an absolute directory path.
	 *
	 * @since 2.1.0
	 *
	 * @param string $bundle Bundle name or relative path.
	 * @return string|WP_Error
	 */
	private function resolve_bundle_root( $bundle ) {
		// Prevent directory traversal.
		if ( false !== strpos( $bundle, '..' ) ) {
			return new WP_Error( 'okf_invalid_bundle', __( 'Invalid bundle name.', 'mcp-ai-wpoos' ) );
		}

		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'] . '/mcp-ai-wpoos/knowledge';

		// Check the uploads-based bundle first.
		$path = wp_normalize_path( $base . '/' . $bundle );
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

	/**
	 * Escape frontmatter values for safe output.
	 *
	 * @since 2.1.0
	 *
	 * @param array $frontmatter Raw frontmatter.
	 * @return array
	 */
	private function escape_frontmatter( $frontmatter ) {
		$escaped = array();
		foreach ( $frontmatter as $key => $value ) {
			if ( is_array( $value ) ) {
				$escaped[ esc_html( $key ) ] = array_map( 'esc_html', $value );
			} else {
				$escaped[ esc_html( $key ) ] = esc_html( (string) $value );
			}
		}
		return $escaped;
	}
}
