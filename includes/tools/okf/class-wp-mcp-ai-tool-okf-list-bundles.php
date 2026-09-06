<?php
/**
 * Tool: okf_list_bundles — List available OKF knowledge bundles.
 *
 * @package WP_MCP_AI
 * @since   1.1.62
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF — List Bundles tool.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Tool_OKF_List_Bundles implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_list_bundles';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — List Bundles', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the OKF knowledge bundles available on this site with health statistics — concept count, stale/deprecated counts, conformance, issue count, concept types, and trust-tier histogram. Use this to discover which bundles exist before browsing or searching them. Bundle filesystem paths are not exposed.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			// Empty stdClass encodes as `{}`; an empty PHP array would encode
			// as `[]`, which strict providers (DeepSeek) reject.
			'properties' => new stdClass(),
			'required'   => array(),
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
	 * @param array $arguments Tool arguments (none).
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$escaped = array();
		foreach ( $bundles as $bundle ) {
			$escaped[] = array(
				'name'             => esc_html( $bundle['name'] ),
				'protected'        => (bool) $bundle['protected'],
				'concept_count'    => (int) $bundle['concept_count'],
				'stale_count'      => (int) $bundle['stale_count'],
				'deprecated_count' => (int) $bundle['deprecated_count'],
				'conformant'       => (bool) $bundle['conformant'],
				'issue_count'      => (int) $bundle['issue_count'],
				'types'            => array_map( 'esc_html', (array) $bundle['types'] ),
				'trust_tiers'      => array_map( 'absint', (array) $bundle['trust_tiers'] ),
				'modified'         => (int) $bundle['modified'],
			);
			// Note: the 'path' key is deliberately not exposed — it is a
			// server filesystem detail the assistant does not need.
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %d: bundle count */
				__( 'Listed %d OKF knowledge bundles.', 'mcp-ai-wpoos' ),
				count( $escaped )
			),
			array(
				'bundles' => $escaped,
			)
		);
	}
}
