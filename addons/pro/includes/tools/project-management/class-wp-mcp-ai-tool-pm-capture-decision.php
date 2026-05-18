<?php
/**
 * Project Management capture tool — `pm_capture_decision`.
 *
 * Drops a decision, status update, or ADR into the MemPalace
 * `project/{project_id}` wing. Decision-grade content is born `tier=core`
 * with importance 0.85 (per the plan) so it is always returned by
 * hierarchical recall and surfaced by `wake_up_context`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Project_Management_Toolkit
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
 * MemPalace capture tool for project management decisions, status, ADRs.
 */
class WP_MCP_AI_Tool_PM_Capture_Decision extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pm_capture_decision';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Project Management — Capture Decision', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a project decision, status update, or Architecture Decision Record (ADR) into the MemPalace project drawer. Decision-grade records are born tier=core so they are always part of "what this project knows".', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'project';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'project_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'Project identifier (Project CPT post ID, slug, or external system id). Forms the wing slug `project/{project_id}`.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'decisions', 'status', 'adr' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'          => WP_MCP_AI_Memory_Capture_Service::TIER_CORE,
			'importance'    => 0.85,
			'sensitivity'   => 'internal',
			'consent_basis' => 'legitimate_interest',
			'verbatim'      => true,
			'ttl'           => 5 * 365 * DAY_IN_SECONDS,
			'source'        => 'pm_capture_decision',
			'default_tags'  => array( 'pm', 'decision' ),
		);
	}
}
