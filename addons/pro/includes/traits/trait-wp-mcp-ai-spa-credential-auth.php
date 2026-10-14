<?php
/**
 * Trait WP_MCP_AI_SPA_Credential_Auth
 *
 * Shared permission logic for Pro SPA REST surfaces that must work for the
 * headless NV oOS Dictation desktop app. The dictate app embeds the hosted
 * Pro SPA in a sandboxed <iframe> and authenticates with an assistant
 * credential (or a guest token) — it does NOT carry the site's WordPress
 * login cookie, so a bare `is_user_logged_in()` check 401s there.
 *
 * @package NV_oOS_Pro
 * @subpackage Traits
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides `permission_check_spa_drawer()` — the multi-mode permission gate
 * for SPA drawer endpoints (Tool Shortcuts, Slash Commands, OKF Skills).
 *
 * Mirrors {@see WP_MCP_AI_Pro_SPA_Surfaces_Controller::check_permission()},
 * which already satisfies the same headless client against
 * `/wp-json/mcp-ai-pro/v1/spa-surfaces`.
 *
 * @since 2.2.0
 */
trait WP_MCP_AI_SPA_Credential_Auth {

	/**
	 * Permission gate accepting an authenticated user, a guest token, or a
	 * valid assistant credential (Bearer `cred_xxx.SECRET` or the filtered raw
	 * header form).
	 *
	 * Guest tokens grant read-only access, matching the base REST layer's
	 * `X-WP-MCP-AI-Guest` / `guest_token` convention already accepted by the
	 * chat, memory and SPA-surfaces surfaces — and exercised by the hosted Pro
	 * SPA's embedded (iframe) chat surface.
	 *
	 * @since 2.2.0
	 *
	 * @param \WP_REST_Request $request     REST request.
	 * @param string           $capability  Minimum capability for a logged-in
	 *                                      user (default: `read`).
	 * @param bool             $allow_guest Whether a guest token satisfies the
	 *                                      gate (default: true — these drawers
	 *                                      are read-only).
	 * @return true|\WP_Error
	 */
	protected static function permission_check_spa_drawer( \WP_REST_Request $request, $capability = 'read', $allow_guest = true ) {
		// Authenticated WordPress users with sufficient capability are first-class.
		if ( is_user_logged_in() ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have sufficient permissions.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Guest tokens (read-only) — mirror the base authenticator's convention.
		if ( $allow_guest ) {
			$guest_token = $request->get_header( 'X-WP-MCP-AI-Guest' );
			if ( ! $guest_token ) {
				$guest_token = $request->get_param( 'guest_token' );
			}
			if ( is_string( $guest_token ) && '' !== trim( $guest_token ) ) {
				return true;
			}
		}

		// Otherwise require a valid assistant credential, the same contract the
		// dictate app's bearer auth already satisfies.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$header = $request->get_header( 'Authorization' );
			$token  = '';
			if ( ! empty( $header ) && preg_match( '/^Bearer\s+(.*)$/i', $header, $matches ) ) {
				$token = trim( $matches[1] );
			} elseif ( ! empty( $header ) && apply_filters( 'wp_mcp_ai_accept_raw_credential_header', true ) ) {
				$token = trim( $header );
			}

			if ( '' !== $token && \WP_MCP_AI_Credentials::is_token_format( $token ) ) {
				$validated = \WP_MCP_AI_Credentials::validate_token( $token );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				return true;
			}
		}

		return new \WP_Error(
			'rest_not_logged_in',
			__( 'You must be logged in.', 'mcp-ai-wpoos' ),
			array( 'status' => 401 )
		);
	}
}
