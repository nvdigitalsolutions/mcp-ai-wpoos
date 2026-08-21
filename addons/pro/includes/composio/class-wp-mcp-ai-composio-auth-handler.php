<?php
/**
 * Composio Connect — Connect Link authentication handler.
 *
 * Owns the hosted per-user authentication flow:
 *
 *  1. An admin (or the composio_create_connect_link tool) requests a link for
 *     a toolkit + application user. A single-use state token is minted and
 *     stored in a transient bound to the connection and WordPress user.
 *  2. The end user completes the hosted flow on Composio's Connect Link page.
 *  3. Composio redirects back to this site's callback URL. The handler
 *     validates the state token (CSRF defense), and — when the Composio
 *     project has callback identity verification enabled — redeems the
 *     single-use session_uri via POST /connected_accounts/complete_auth,
 *     which defeats OAuth session fixation.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio Connect Link authentication handler.
 */
class WP_MCP_AI_Composio_Auth_Handler {

	/**
	 * Transient key prefix for link state tokens.
	 */
	const STATE_PREFIX = 'wp_mcp_ai_composio_link_';

	/**
	 * State transient TTL in seconds (10 minutes).
	 */
	const STATE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Default user-id prefix for admin_shared mode connections.
	 */
	const SHARED_USER_PREFIX = 'nvoos-shared';

	/**
	 * Map a WordPress user ID to a stable Composio user_id string.
	 *
	 * @since 1.4.0
	 *
	 * @param int $wp_user_id WordPress user ID (0 = current user).
	 * @return string
	 */
	public static function wp_user_to_composio_user_id( $wp_user_id = 0 ) {
		$wp_user_id = absint( $wp_user_id );
		if ( 0 === $wp_user_id ) {
			$wp_user_id = get_current_user_id();
		}

		if ( 0 === $wp_user_id ) {
			return self::SHARED_USER_PREFIX;
		}

		return 'wp-' . $wp_user_id;
	}

	/**
	 * Resolve the Composio user_id for a connection and WordPress user.
	 *
	 * In admin_shared mode every account belongs to the connection's default
	 * user identity; in per_wp_user mode each WordPress user gets their own.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @param int   $wp_user_id WordPress user ID.
	 * @return string
	 */
	public static function resolve_user_id( array $connection, $wp_user_id = 0 ) {
		$mode = isset( $connection['default_user_mode'] ) ? sanitize_key( $connection['default_user_mode'] ) : 'admin_shared';

		if ( 'per_wp_user' === $mode ) {
			return self::wp_user_to_composio_user_id( $wp_user_id );
		}

		if ( ! empty( $connection['default_user_id'] ) ) {
			return sanitize_text_field( $connection['default_user_id'] );
		}

		return self::SHARED_USER_PREFIX;
	}

	/**
	 * Create a Connect Link for a toolkit and redirect the browser to it.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $toolkit       Toolkit slug (e.g. "gmail").
	 * @param int    $wp_user_id    Optional. WordPress user the link belongs to.
	 * @return array|WP_Error Array with url/state on success.
	 */
	public static function create_link( $connection_id, $toolkit, $wp_user_id = 0 ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$toolkit       = sanitize_key( (string) $toolkit );

		if ( '' === $connection_id || '' === $toolkit ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_link_params', __( 'A connection ID and toolkit are required to create a Connect Link.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_manager', __( 'The Remote Site Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection || 'composio' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_invalid_connection', __( 'Composio connection not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_disabled_connection', __( 'This Composio connection is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = self::resolve_user_id( $connection, $wp_user_id );

		// Mint the single-use state token before creating the link so a
		// callback can never be processed without a matching state.
		$state = wp_generate_password( 32, false, false );
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'connection_id' => $connection_id,
				'toolkit'       => $toolkit,
				'user_id'       => $user_id,
				'wp_user_id'    => absint( $wp_user_id ),
			),
			self::STATE_TTL
		);

		$client = WP_MCP_AI_Composio_Client::from_connection( $connection );

		$callback_url = admin_url(
			'admin.php?page=wp-mcp-ai-remote-sites&oauth_handler=composio_oauth_callback&connection_id=' . rawurlencode( $connection_id )
		);

		$result = $client->create_connect_link(
			$toolkit,
			$user_id,
			$callback_url,
			array(
				'callback_url' => $callback_url . '&state=' . rawurlencode( $state ),
			)
		);

		if ( is_wp_error( $result ) ) {
			delete_transient( self::STATE_PREFIX . $state );
			return $result;
		}

		$redirect_url = isset( $result['redirect_url'] ) ? esc_url_raw( $result['redirect_url'] ) : '';

		if ( '' === $redirect_url ) {
			delete_transient( self::STATE_PREFIX . $state );
			return new WP_Error( 'wp_mcp_ai_composio_link_failed', __( 'Composio did not return a Connect Link URL.', 'mcp-ai-wpoos-pro' ) );
		}

		// Attach the state to the outgoing link so it survives the hosted flow.
		$redirect_url = add_query_arg( 'state', rawurlencode( $state ), $redirect_url );

		return array(
			'url'   => $redirect_url,
			'state' => $state,
		);
	}

	/**
	 * Handle the return from a Composio Connect Link.
	 *
	 * Validates the state token, redeems the verifier session when present,
	 * flushes the accounts cache and redirects back to the connection editor.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) );
		}

		// OAuth callbacks cannot carry WordPress nonces — the single-use state
		// token validated below is the security control for this endpoint.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$session_uri   = isset( $_GET['session_uri'] ) ? esc_url_raw( wp_unslash( $_GET['session_uri'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect_base = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' );

		if ( '' === $connection_id ) {
			wp_safe_redirect( add_query_arg( 'composio_linked', '0', $redirect_base ) );
			exit;
		}

		$redirect = add_query_arg( array( 'edit' => $connection_id ), $redirect_base );

		// Validate + consume the state token exactly once.
		$stored = get_transient( self::STATE_PREFIX . $state );
		if ( false === $stored || ! is_array( $stored ) || ! hash_equals( (string) $stored['connection_id'], $connection_id ) ) {
			delete_transient( self::STATE_PREFIX . $state );
			wp_safe_redirect( add_query_arg( 'composio_linked', 'state', $redirect ) );
			exit;
		}
		delete_transient( self::STATE_PREFIX . $state );

		// Verifier mode: redeem the single-use session_uri and confirm the
		// signed-in user matches the account owner.
		if ( '' !== $session_uri ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				wp_safe_redirect( add_query_arg( 'composio_linked', '0', $redirect ) );
				exit;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null !== $connection ) {
				$client  = WP_MCP_AI_Composio_Client::from_connection( $connection );
				$user_id = isset( $stored['user_id'] ) ? $stored['user_id'] : self::resolve_user_id( $connection );

				$result = $client->complete_auth( $session_uri, $user_id );

				if ( is_wp_error( $result ) ) {
					wp_safe_redirect(
						add_query_arg(
							array(
								'composio_linked' => '0',
								'composio_error'  => rawurlencode( $result->get_error_message() ),
							),
							$redirect
						)
					);
					exit;
				}
			}
		}

		// Flush the cached connected-account listing for this connection.
		delete_transient( WP_MCP_AI_Composio_Client::CACHE_PREFIX . md5( $connection_id . '|' . WP_MCP_AI_Composio_Client::DEFAULT_BASE_URL . '/api/' . WP_MCP_AI_Composio_Client::API_VERSION . '/connected_accounts' ) );

		wp_safe_redirect( add_query_arg( 'composio_linked', '1', $redirect ) );
		exit;
	}

	/**
	 * Mark a connected account as expired (used by the webhook receiver).
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $account_id    Connected account nanoid.
	 * @return void
	 */
	public static function mark_account_expired( $connection_id, $account_id ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$account_id    = sanitize_text_field( (string) $account_id );

		if ( '' === $connection_id || '' === $account_id ) {
			return;
		}

		set_transient(
			'wp_mcp_ai_composio_expired_' . md5( $connection_id . '|' . $account_id ),
			time(),
			DAY_IN_SECONDS
		);

		// Flush the cached listing so the admin panel reflects the new state.
		delete_transient( WP_MCP_AI_Composio_Client::CACHE_PREFIX . md5( $connection_id . '|' . WP_MCP_AI_Composio_Client::DEFAULT_BASE_URL . '/api/' . WP_MCP_AI_Composio_Client::API_VERSION . '/connected_accounts' ) );
	}

	/**
	 * Check whether a connected account is marked expired.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Composio connection ID.
	 * @param string $account_id    Connected account nanoid.
	 * @return bool
	 */
	public static function is_account_expired( $connection_id, $account_id ) {
		$connection_id = sanitize_key( (string) $connection_id );
		$account_id    = sanitize_text_field( (string) $account_id );

		if ( '' === $connection_id || '' === $account_id ) {
			return false;
		}

		return false !== get_transient( 'wp_mcp_ai_composio_expired_' . md5( $connection_id . '|' . $account_id ) );
	}
}
