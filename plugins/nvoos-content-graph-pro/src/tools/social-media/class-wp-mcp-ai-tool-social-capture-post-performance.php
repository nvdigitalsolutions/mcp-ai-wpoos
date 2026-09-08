<?php
/**
 * Social media tool (ecosystem port — Wave F2, social tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/social-media/class-wp-mcp-ai-tool-social-capture-post-performance.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps where the
 * base references path constants (capture base require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
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
		return __( 'Social Media — Capture Post Performance', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture brand voice notes, post-performance observations, or audience reactions into the MemPalace brand drawer. Records are born tier=recall; the tier manager promotes high-importance items to core based on access frequency.', 'nvoos-content-graph-pro' );
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
		return __( 'Brand identifier (brand CPT post ID, slug, or external profile id). Forms the wing slug `brand/{brand_id}`.', 'nvoos-content-graph-pro' );
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
