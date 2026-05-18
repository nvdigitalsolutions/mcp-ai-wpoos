<?php
/**
 * CRM capture tool — `crm_capture_interaction`.
 *
 * Drops an interaction, objection, or next-action into the MemPalace
 * `account/{account_id}` wing so subsequent assistant turns about that
 * account automatically wake up the relevant drawer via `wake_up_context`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Capture_Tool_Base' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php';
}

/**
 * MemPalace capture tool for CRM interactions.
 */
class WP_MCP_AI_Tool_CRM_Capture_Interaction extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'crm_capture_interaction';
	}

	/**
	 * {\@inheritdoc}
	 *
	 * @return string WordPress capability string.
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'CRM — Capture Interaction', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a CRM interaction, objection, or next-action into the MemPalace account drawer. The record becomes part of "everything we remember about this account" and is automatically surfaced by hierarchical recall.', 'mcp-ai-wpoos-pro' );
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
		return __( 'CRM account / company identifier (CPT post ID, slug, or external CRM id). Forms the wing slug `account/{account_id}`.', 'mcp-ai-wpoos-pro' );
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
