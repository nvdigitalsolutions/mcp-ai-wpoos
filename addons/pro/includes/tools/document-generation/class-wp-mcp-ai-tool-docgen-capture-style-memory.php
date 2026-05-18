<?php
/**
 * Document Generation capture tool — `docgen_capture_style_memory`.
 *
 * Captures a user's writing-style preference into the MemPalace
 * `user/{user_id}` wing. Document Generation is one of two toolkits
 * (Multilingual being the other) explicitly allowed to set
 * `verbatim=false`. Even there, the original is kept verbatim at
 * `tier=archival` while the summary becomes the `tier=recall` representative
 * — the mem0 / MemPalace verbatim discipline.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
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
 * MemPalace capture tool for Document Generation style memory & drafts.
 */
class WP_MCP_AI_Tool_DocGen_Capture_Style_Memory extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docgen_capture_style_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Document Generation — Capture Style Memory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a user writing-style preference or draft into the MemPalace user drawer. This is one of only two toolkits allowed to provide a summary alongside the verbatim source — the original is kept at tier=archival, the summary becomes the tier=recall representative.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'user';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'user_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'WordPress user ID (or external user identifier). Forms the wing slug `user/{user_id}`.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'style', 'drafts' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'                => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
			'importance'          => 0.55,
			'sensitivity'         => 'pii',
			'consent_basis'       => 'consent',
			'verbatim'            => true,
			'allow_summarisation' => true,
			'ttl'                 => 2 * 365 * DAY_IN_SECONDS,
			'source'              => 'docgen_capture_style_memory',
			'default_tags'        => array( 'docgen', 'style' ),
		);
	}
}
