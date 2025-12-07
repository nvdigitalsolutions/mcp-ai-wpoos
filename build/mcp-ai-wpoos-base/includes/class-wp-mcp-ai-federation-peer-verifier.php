<?php
/**
 * Peer verification helper for federation directory.
 *
 * Handles verification of peer health, JWKS availability, and latency measurement.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles peer verification and health checks.
 */
class WP_MCP_AI_Federation_Peer_Verifier {

	/**
	 * Verify a peer by fetching its well-known document and checking JWKS.
	 *
	 * @param int    $peer_id      Peer post ID.
	 * @param string $wellknown_url Well-known URL to verify.
	 * @return array|WP_Error Verification result or error.
	 */
	public static function verify_peer( $peer_id, $wellknown_url ) {
		$start_time = microtime( true );

		// Fetch the well-known document.
		$response = wp_remote_get(
			$wellknown_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-MCP-AI-Federation/1.0',
			)
		);

		$latency = round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $response ) ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'down' );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR, $response->get_error_message() );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, current_time( 'mysql', true ) );

			return new WP_Error(
				'verification_failed',
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'down' );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR, "HTTP $status_code" );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, current_time( 'mysql', true ) );

			return new WP_Error(
				'verification_failed',
				sprintf( 'HTTP %d', $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'down' );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR, 'Invalid JSON response' );
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, current_time( 'mysql', true ) );

			return new WP_Error(
				'verification_failed',
				'Invalid JSON response'
			);
		}

		// Check JWKS availability.
		$jwks_uri       = isset( $data['jwks_uri'] ) ? $data['jwks_uri'] : '';
		$jwks_reachable = false;

		if ( $jwks_uri ) {
			$jwks_response = wp_remote_get(
				$jwks_uri,
				array(
					'timeout'    => 5,
					'user-agent' => 'WP-MCP-AI-Federation/1.0',
				)
			);

			$jwks_reachable = ! is_wp_error( $jwks_response ) && 200 === wp_remote_retrieve_response_code( $jwks_response );
		}

		// Determine health status.
		$health_status = 'healthy';
		if ( ! $jwks_reachable ) {
			$health_status = 'degraded';
		}

		// Update peer metadata.
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, $health_status );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, $latency );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_VERIFIED, current_time( 'mysql', true ) );

		if ( ! $jwks_reachable ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR, 'JWKS endpoint not reachable' );
		} else {
			delete_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LAST_ERROR );
		}

		// Update capabilities if changed.
		if ( isset( $data['capabilities'] ) ) {
			update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, wp_json_encode( $data['capabilities'] ) );
		}

		return array(
			'health_status'  => $health_status,
			'latency_ms'     => $latency,
			'jwks_reachable' => $jwks_reachable,
		);
	}

	/**
	 * Verify all peers (used by cron job).
	 */
	public static function verify_all_peers() {
		$query = new WP_Query(
			array(
				'post_type'              => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,  // Performance: Skip counting.
				'update_post_term_cache' => false, // Performance: Skip term cache.
			)
		);

		$verified_count = 0;
		$failed_count   = 0;

		foreach ( $query->posts as $peer_id ) {
			$wellknown_url = get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_WELLKNOWN_URL, true );

			if ( ! $wellknown_url ) {
				continue;
			}

			$result = self::verify_peer( $peer_id, $wellknown_url );

			if ( is_wp_error( $result ) ) {
				++$failed_count;
			} else {
				++$verified_count;
			}

			// Small delay to avoid overwhelming servers.
			/**
			 * Filter the delay between peer verification requests.
			 *
			 * @since 1.0.0
			 *
			 * @param int $delay_microseconds Delay in microseconds. Default 100000 (100ms).
			 */
			$delay_microseconds = apply_filters( 'wp_mcp_ai_federation_peer_verification_delay', 100000 );
			usleep( absint( $delay_microseconds ) );
		}

		WP_MCP_AI_Logger::log_event(
			'peers_verified',
			'Batch peer verification completed',
			array(
				'verified' => $verified_count,
				'failed'   => $failed_count,
			)
		);

		return array(
			'verified' => $verified_count,
			'failed'   => $failed_count,
		);
	}
}
