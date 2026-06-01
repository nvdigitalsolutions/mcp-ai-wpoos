<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) { return false; }
		// Check post author
		if ( (int) $matter->post_author === (int) $user_id ) { return true; }
		// Check assigned attorney meta
		$assigned = get_post_meta( $matter_id, '_lf_assigned_attorney', true );
		if ( $assigned && absint( $assigned ) === (int) $user_id ) { return true; }
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
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		$client = get_post( $client_id );
		if ( ! $client || 'mcp_ai_lf_client' !== $client->post_type ) { return false; }
		if ( (int) $client->post_author === (int) $user_id ) { return true; }
		return false;
	}
}
