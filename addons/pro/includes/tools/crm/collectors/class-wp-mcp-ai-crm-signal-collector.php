<?php
/**
 * CRM Signal Collector
 *
 * Thin collector facade. Own-site collection lives in the Signals engine;
 * this class gives integrations a stable entry point and a filter seam for
 * additional collectors (provider SERP rank, maps, reviews).
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 *
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collector facade.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Signal_Collector {

	/**
	 * Collect signals for one lead.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $args    { url, force }.
	 * @return array Collected signal keys.
	 */
	public static function collect( $lead_id, array $args = array() ) {
		$lead_id = absint( $lead_id );
		if ( ! $lead_id || ! class_exists( 'WP_MCP_AI_CRM_Signals' ) ) {
			return array();
		}

		$collected = WP_MCP_AI_CRM_Signals::collect_own_site( $lead_id, $args );

		/**
		 * Filter the collected signal keys.
		 *
		 * @param array $collected Collected keys.
		 * @param int   $lead_id   Lead post ID.
		 * @param array $args      Collector args.
		 */
		return (array) apply_filters( 'wp_mcp_ai_crm_collected_signals', $collected, $lead_id, $args );
	}
}
