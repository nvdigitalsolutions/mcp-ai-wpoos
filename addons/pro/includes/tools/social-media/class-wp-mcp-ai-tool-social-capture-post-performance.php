<?php
/**
 * Social Media capture tool — `social_capture_post_performance`.
 *
 * Captures voice / brand-tone notes or post-performance observations into the
 * MemPalace `brand/{brand_id}` wing. Performance/observation content is born
 * `tier=recall` (per the plan) — the tier manager promotes the most-accessed
 * records to `core` over time.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Social_Media_Toolkit
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
 * MemPalace capture tool for social-media voice + post performance.
 */
class WP_MCP_AI_Tool_Social_Capture_Post_Performance extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'social_capture_post_performance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Social Media — Capture Post Performance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture brand voice notes, post-performance observations, or audience reactions into the MemPalace brand drawer. Records are born tier=recall; the tier manager promotes high-importance items to core based on access frequency.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'brand';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'brand_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'Brand identifier (brand CPT post ID, slug, or external profile id). Forms the wing slug `brand/{brand_id}`.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'voice', 'performance' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'          => WP_MCP_AI_Memory_Capture_Service::TIER_RECALL,
			'importance'    => 0.45,
			'sensitivity'   => 'public',
			'consent_basis' => 'legitimate_interest',
			'verbatim'      => true,
			'ttl'           => 365 * DAY_IN_SECONDS,
			'source'        => 'social_capture_post_performance',
			'default_tags'  => array( 'social', 'performance' ),
		);
	}
}
