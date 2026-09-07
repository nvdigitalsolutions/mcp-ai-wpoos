<?php
/**
 * CRM Capture Interaction Tool (ecosystem port — Wave F2, CRM core +
 * email search batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-crm-capture-interaction.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the capture-tool-base require resolves from
 * `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/capture/'` (ported in the
 * same slice); the base-owned `WP_MCP_AI_Memory_Capture_Service` stays
 * served by the root classmap (`includes/services/`) — not copied (the
 * same ownership boundary as the D8 tool infra).
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Capture_Tool_Base' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php';
}

/**
 * MemPalace capture tool for CRM interactions.
 */
class WP_MCP_AI_Tool_CRM_Capture_Interaction extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * Determine whether CRM toolkit and capture base are available.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The CRM Capture Interaction tool requires the CRM Toolkit to be enabled in plugin settings.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'crm_capture_interaction';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'CRM — Capture Interaction', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a CRM interaction, objection, or next-action into the MemPalace account drawer. The record becomes part of "everything we remember about this account" and is automatically surfaced by hierarchical recall.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'account';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'account_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'CRM account / company identifier (CPT post ID, slug, or external CRM id). Forms the wing slug `account/{account_id}`.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'interactions', 'objections', 'next-actions' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'          => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
			'importance'    => 0.6,
			'sensitivity'   => 'pii',
			'consent_basis' => 'legitimate_interest',
			'verbatim'      => true,
			'ttl'           => 365 * DAY_IN_SECONDS,
			'source'        => 'crm_capture_interaction',
			'default_tags'  => array( 'crm', 'capture' ),
		);
	}
}
