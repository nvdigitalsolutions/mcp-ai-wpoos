<?php
	/**
	 * Tool: okf_write_concept — Create or update an OKF concept.
	 *
	 * @package WP_MCP_AI
	 * @since   2.1.0
	 * @since   2.5.0 — Emits OKF v0.2 `generated` provenance field instead of v0.1 `timestamp`.
	 * @author  NV Digital Solutions
	 * @copyright Copyright (c) 2026 NV Digital Solutions
	 * @license  GPL-3.0-or-later
	 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — Write Concept tool.
 */
class WP_MCP_AI_Tool_OKF_Write_Concept implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_write_concept';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Write Concept', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates or updates an OKF concept document in a bundle (OKF v0.2). Requires at minimum a type field in the frontmatter. Supports optional v0.2 trust-signal fields: status (draft/stable/deprecated), stale_after (ISO 8601 date), and verification metadata. Use this to curate and maintain the OKF knowledge base programmatically.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bundle'      => array(
					'type'        => 'string',
					'description' => __( 'OKF bundle name.', 'mcp-ai-wpoos' ),
				),
				'concept_id'  => array(
					'type'        => 'string',
					'description' => __( 'Concept ID — the file path without .md suffix (e.g. "policies/privacy-policy").', 'mcp-ai-wpoos' ),
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Concept type (required by OKF v0.2, e.g. "Policy", "Procedure", "Skill").', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Human-readable title.', 'mcp-ai-wpoos' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'One-line summary of the concept.', 'mcp-ai-wpoos' ),
				),
				'body'        => array(
					'type'        => 'string',
					'description' => __( 'Markdown body content.', 'mcp-ai-wpoos' ),
				),
				'tags'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'List of tags for categorization.', 'mcp-ai-wpoos' ),
				),
				'status'      => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'stable', 'deprecated' ),
					'description' => __( 'Lifecycle status (OKF v0.2). Omit for stable, set to draft for work-in-progress, deprecated for retired concepts.', 'mcp-ai-wpoos' ),
				),
				'stale_after' => array(
					'type'        => 'string',
					'description' => __( 'ISO 8601 date after which the concept is considered stale (OKF v0.2, e.g. "2027-06-30").', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'bundle', 'concept_id', 'type', 'body' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$bundle      = sanitize_text_field( $arguments['bundle'] );
		$concept_id  = sanitize_text_field( $arguments['concept_id'] );
		$type        = sanitize_text_field( $arguments['type'] );
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '';
		$body        = wp_kses_post( $arguments['body'] );
		$tags        = isset( $arguments['tags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['tags'] ) : array();
		$status      = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$stale_after = isset( $arguments['stale_after'] ) ? sanitize_text_field( $arguments['stale_after'] ) : '';

		if ( empty( $bundle ) || empty( $concept_id ) || empty( $type ) ) {
			return new WP_Error( 'missing_params', __( 'Bundle, concept_id, and type are required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$bundle_root = $this->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );

		// Ensure the bundle directory exists.
		$ensured = $writer->ensure_bundle_root();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		// Build frontmatter.
		$frontmatter = array(
			'type' => $type,
		);
		if ( ! empty( $title ) ) {
			$frontmatter['title'] = $title;
		}
		if ( ! empty( $description ) ) {
			$frontmatter['description'] = $description;
		}
		if ( ! empty( $tags ) ) {
			$frontmatter['tags'] = $tags;
		}
		if ( ! empty( $status ) ) {
			$frontmatter['status'] = $status;
		}
		if ( ! empty( $stale_after ) ) {
			$frontmatter['stale_after'] = $stale_after;
		}

		// OKF v0.2 provenance: generated replaces v0.1 timestamp.
		$frontmatter['generated'] = array(
			'by' => 'okf_write_concept tool',
			'at' => gmdate( 'c' ),
		);

		$result = $writer->write_concept( $concept_id, $frontmatter, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %s: concept ID */
				__( 'Concept "%s" saved successfully.', 'mcp-ai-wpoos' ),
				$concept_id
			),
			array(
				'bundle'     => esc_html( $bundle ),
				'concept_id' => esc_html( $concept_id ),
				'type'       => esc_html( $type ),
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
