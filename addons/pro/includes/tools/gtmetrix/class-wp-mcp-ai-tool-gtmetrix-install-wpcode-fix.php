<?php
/**
 * GTmetrix tool: install WPCode fix (draft-only).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-wpcode-bridge.php';

/**
 * Install a report's matched fixes as an INACTIVE WPCode draft.
 *
 * Restricted from the chat client (code execution surface); requires the
 * wpcode_edit_snippets capability. Activation remains a human step.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Install_WPCode_Fix extends WP_MCP_AI_GTmetrix_Tool_Base {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_install_wpcode_fix';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Install WPCode Fix', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Install the fixes matched for a GTmetrix report as an INACTIVE WPCode draft (tagged gtmetrix-fix, never auto-activated). Activation is a manual step after staging verification.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating the WPCode draft for a reviewed report so a human can activate it after staging tests.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Activating snippets or installing untested risky blocks — that is a human decision.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_generate_wpcode_snippet', 'list_wpcode_snippets' ),
			'notes'           => __( 'Requires the WPCode plugin and the wpcode_edit_snippets capability. Drafts only — never activates.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'wpcode_edit_snippets';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'local-only', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID whose fixes to install.', 'mcp-ai-wpoos-pro' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'Snippet title (default: GTmetrix performance fixes).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'report_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard_key();
		if ( $error ) {
			return $error;
		}

		$report_id = $this->arg_report_id( $arguments );
		if ( '' === $report_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'A report ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$report = $this->client()->get_report( $report_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$flat = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $report );

		$lighthouse = $this->client()->get_resource( $report_id, 'lighthouse.json' );
		if ( is_wp_error( $lighthouse ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_no_lighthouse', __( 'This report has no Lighthouse JSON (metrics-only or legacy report).', 'mcp-ai-wpoos-pro' ) );
		}

		$audits_block = isset( $lighthouse['audits'] ) && is_array( $lighthouse['audits'] ) ? $lighthouse['audits'] : array();
		$audits       = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $audits_block );
		$matched      = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );

		if ( empty( $matched['fixes'] ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_no_fixes', __( 'No known fixes matched this report; nothing to install.', 'mcp-ai-wpoos-pro' ) );
		}

		$bridge = new WP_MCP_AI_GTmetrix_WPCode_Bridge();
		$title  = isset( $arguments['title'] ) && '' !== sanitize_text_field( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : __( 'GTmetrix performance fixes', 'mcp-ai-wpoos-pro' );

		$result = $bridge->install_draft(
			$matched['fixes'],
			array(
				'report_id' => $report_id,
				'url'       => isset( $flat['url'] ) ? $flat['url'] : '',
				'title'     => $title,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['success']   = true;
		$result['fix_count'] = count( $matched['fixes'] );
		$result['note']      = __( 'Draft created — review on staging and activate blocks one at a time.', 'mcp-ai-wpoos-pro' );

		return $result;
	}
}
