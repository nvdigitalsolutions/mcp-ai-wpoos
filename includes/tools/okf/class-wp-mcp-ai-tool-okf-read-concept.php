<?php
	/**
	 * Tool: okf_read_concept — Read a single OKF concept.
	 *
	 * @package WP_MCP_AI
	 * @since   2.1.0
	 * @since   2.5.0 — Surfaces OKF v0.2 trust-signal fields (status, trust_tier,
	 *                stale, stale_after, generated, verified, sources).
	 * @since   1.1.62 — Bundle path resolution via WP_MCP_AI_OKF_Bundle_Manager.
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
		return __( 'Reads a single OKF concept by its concept ID (e.g. "policies/patient-admission"). Returns the frontmatter metadata including OKF v0.2 trust signals (status, trust tier, staleness, provenance) and the markdown body. Use this to retrieve curated, authoritative knowledge from the Open Knowledge Format bundle. Check the trust_tier to assess reliability before acting on the content.', 'mcp-ai-wpoos' );
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

		$manager     = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundle_root = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $bundle_root ) ) {
			return $bundle_root;
		}

		$reader  = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$concept = $reader->get_concept( $concept_id );

		if ( is_wp_error( $concept ) ) {
			return $concept;
		}

		$fm = $concept['frontmatter'];

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
				'type'        => isset( $fm['type'] ) ? esc_html( $fm['type'] ) : '',
				'title'       => isset( $fm['title'] ) ? esc_html( $fm['title'] ) : '',
				'description' => isset( $fm['description'] ) ? esc_html( $fm['description'] ) : '',
				// OKF v0.2 trust-signal fields.
				'status'      => isset( $fm['status'] ) ? esc_html( $fm['status'] ) : 'stable',
				'trust_tier'  => esc_html( $reader->get_trust_tier( $fm ) ),
				'stale'       => $reader->is_stale( $fm ),
				'stale_after' => isset( $fm['stale_after'] ) ? esc_html( $fm['stale_after'] ) : '',
				'frontmatter' => $this->escape_frontmatter( $fm ),
				'body'        => wp_kses_post( $concept['body'] ),
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
