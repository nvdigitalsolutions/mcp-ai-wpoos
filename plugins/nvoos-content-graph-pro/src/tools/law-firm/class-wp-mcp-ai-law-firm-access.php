<?php
/**
 * includes/tools/law-firm/class-wp-mcp-ai-law-firm-access.php (ecosystem port — Wave F4, law-firm data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/class-wp-mcp-ai-law-firm-access.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path.
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

/**
 * Access control helpers for the Law Firm toolkit.
 */
class WP_MCP_AI_Law_Firm_Access {
	/**
	 * Check if a user can access a specific matter.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id   User ID.
	 * @param int $matter_id Matter post ID.
	 * @return bool
	 */
	public static function user_can_access_matter( $user_id, $matter_id ) {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return false;
		}
		// Check post author.
		if ( (int) $matter->post_author === (int) $user_id ) {
			return true;
		}
		// Check assigned attorney meta.
		$assigned = get_post_meta( $matter_id, '_lf_assigned_attorney', true );
		if ( $assigned && absint( $assigned ) === (int) $user_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if a user can access a specific client.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id   User ID.
	 * @param int $client_id Client post ID.
	 * @return bool
	 */
	public static function user_can_access_client( $user_id, $client_id ) {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$client = get_post( $client_id );
		if ( ! $client || 'mcp_ai_lf_client' !== $client->post_type ) {
			return false;
		}
		if ( (int) $client->post_author === (int) $user_id ) {
			return true;
		}
		return false;
	}
}
