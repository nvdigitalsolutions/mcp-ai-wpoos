<?php
/**
 * Tool: okf_validate_bundle — Validate an OKF bundle for conformance.
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
 * OKF — Validate Bundle tool.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Tool_OKF_Validate_Bundle implements WP_MCP_AI_Tool_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'okf_validate_bundle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'OKF — Validate Bundle', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates an OKF bundle for v0.2 conformance: every concept must have parseable YAML frontmatter with a non-empty type field; reserved filenames (index.md, log.md) must follow conventions; status and stale_after values are checked. Also reports advisory broken cross-links and a trust-tier histogram. Returns a conformant flag plus concept, stale, deprecated, and broken-link counts and a list of advisory issues. Per the OKF spec, issues are reported but never block reading.', 'mcp-ai-wpoos' );
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
					'description' => __( 'OKF bundle name (e.g. "site-knowledge").', 'mcp-ai-wpoos' ),
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

		$writer = new WP_MCP_AI_OKF_Writer( $bundle_root );
		$result = $writer->validate_bundle();

		// Trust-tier histogram (OKF v0.2 §5.3) for the whole bundle.
		$reader      = new WP_MCP_AI_OKF_Reader( $bundle_root );
		$trust_tiers = array(
			'human-reviewed'    => 0,
			'machine-confirmed' => 0,
			'unverified'        => 0,
		);
		foreach ( $reader->search( array() ) as $concept ) {
			$tier = isset( $concept['trust_tier'] ) ? $concept['trust_tier'] : 'unverified';
			if ( isset( $trust_tiers[ $tier ] ) ) {
				++$trust_tiers[ $tier ];
			}
		}

		$broken_links = array();
		foreach ( $result['broken_links'] as $broken ) {
			$broken_links[] = array(
				'concept_id' => esc_html( $broken['concept_id'] ),
				'target'     => esc_html( $broken['target'] ),
				'resolved'   => esc_html( $broken['resolved'] ),
			);
		}

		return $this->format_success_response(
			$result['conformant']
				? sprintf(
					/* translators: %s: bundle name */
					__( 'Bundle "%s" is OKF v0.2-conformant.', 'mcp-ai-wpoos' ),
					$bundle
				)
				: sprintf(
					/* translators: 1: bundle name, 2: issue count */
					__( 'Bundle "%1$s" has %2$d advisory issue(s).', 'mcp-ai-wpoos' ),
					$bundle,
					count( $result['issues'] )
				),
			array(
				'bundle'            => esc_html( $bundle ),
				'conformant'        => (bool) $result['conformant'],
				'concept_count'     => (int) $result['concept_count'],
				'stale_count'       => (int) $result['stale_count'],
				'deprecated_count'  => (int) $result['deprecated_count'],
				'broken_link_count' => count( $broken_links ),
				'broken_links'      => $broken_links,
				'trust_tiers'       => array_map( 'absint', $trust_tiers ),
				'issues'            => array_map( 'esc_html', (array) $result['issues'] ),
			)
		);
	}
}
