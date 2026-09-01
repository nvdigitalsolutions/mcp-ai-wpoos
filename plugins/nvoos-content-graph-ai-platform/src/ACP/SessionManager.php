<?php
/**
 * ACP Session Manager.
 *
 * Handles ACP session lifecycle operations:
 * - session/new
 * - session/load
 * - session/list
 * - session/cancel
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/acp/class-wp-mcp-ai-acp-session-manager.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager for ACP sessions.
 */
class SessionManager {

	/**
	 * Prefix for transient keys.
	 */
	const TRANSIENT_PREFIX = 'acp_sess_';

	/**
	 * Session lifetime in seconds (e.g., 24 hours).
	 */
	const SESSION_LIFETIME = 86400;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Initialization.
	}

	/**
	 * Create a new session.
	 *
	 * @param array $params Request parameters.
	 * @return array|\WP_Error Session details or error.
	 */
	public function create_session( $params ) {
		$session_id = 'sess_' . wp_generate_password( 16, false );
		$user_id    = get_current_user_id();

		// Default session structure.
		$session_data = array(
			'id'         => $session_id,
			'user_id'    => $user_id,
			'created_at' => time(),
			'updated_at' => time(),
			'messages'   => array(),
			'config'     => isset( $params['config'] ) ? $params['config'] : array(),
		);

		set_transient( self::TRANSIENT_PREFIX . $session_id, $session_data, self::SESSION_LIFETIME );

		// Keep track of user's sessions for session/list.
		$this->add_to_user_sessions( $user_id, $session_id );

		return array(
			'sessionId' => $session_id,
		);
	}

	/**
	 * Load an existing session.
	 *
	 * @param string $session_id Session ID.
	 * @return array|\WP_Error Session details or error.
	 */
	public function load_session( $session_id ) {
		$session = get_transient( self::TRANSIENT_PREFIX . $session_id );

		if ( false === $session ) {
			return new \WP_Error( -32001, 'Session not found or expired' );
		}

		// Ensure the user owns this session or has permissions.
		if ( get_current_user_id() !== $session['user_id'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( -32002, 'Unauthorized access to session' );
		}

		return array(
			'sessionId' => $session['id'],
		);
	}

	/**
	 * List existing sessions for the current user.
	 *
	 * @return array List of sessions.
	 */
	public function list_sessions() {
		$user_id  = get_current_user_id();
		$sessions = get_user_meta( $user_id, '_acp_sessions', true );

		if ( empty( $sessions ) || ! is_array( $sessions ) ) {
			return array( 'sessions' => array() );
		}

		$active_sessions = array();

		foreach ( $sessions as $session_id ) {
			$session = get_transient( self::TRANSIENT_PREFIX . $session_id );
			if ( false !== $session ) {
				$active_sessions[] = array(
					'id' => $session_id,
				);
			}
		}

		// Cleanup expired sessions from user meta.
		if ( count( $active_sessions ) !== count( $sessions ) ) {
			update_user_meta( $user_id, '_acp_sessions', wp_list_pluck( $active_sessions, 'id' ) );
		}

		return array(
			'sessions' => $active_sessions,
		);
	}

	/**
	 * Cancel a session operation.
	 *
	 * @param string $session_id Session ID.
	 * @return bool True if cancelled.
	 */
	public function cancel_session( $session_id ) {
		// Store a cancellation flag for this session to interrupt ongoing prompt turns.
		set_transient( self::TRANSIENT_PREFIX . $session_id . '_cancel', true, 60 );
		return true;
	}

	/**
	 * Check if a session has been cancelled.
	 *
	 * @param string $session_id Session ID.
	 * @return bool True if cancelled.
	 */
	public function is_cancelled( $session_id ) {
		return (bool) get_transient( self::TRANSIENT_PREFIX . $session_id . '_cancel' );
	}

	/**
	 * Clear the cancellation flag.
	 *
	 * @param string $session_id Session ID.
	 */
	public function clear_cancellation( $session_id ) {
		delete_transient( self::TRANSIENT_PREFIX . $session_id . '_cancel' );
	}

	/**
	 * Retrieve full session data.
	 *
	 * @param string $session_id Session ID.
	 * @return array|false Session data array or false.
	 */
	public function get_session_data( $session_id ) {
		return get_transient( self::TRANSIENT_PREFIX . $session_id );
	}

	/**
	 * Update session data.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $data       New session data.
	 * @return bool True on success.
	 */
	public function update_session_data( $session_id, $data ) {
		$data['updated_at'] = time();
		return set_transient( self::TRANSIENT_PREFIX . $session_id, $data, self::SESSION_LIFETIME );
	}

	/**
	 * Append a session ID to the user's session list.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Session ID.
	 */
	protected function add_to_user_sessions( $user_id, $session_id ) {
		if ( ! $user_id ) {
			return;
		}

		$sessions = get_user_meta( $user_id, '_acp_sessions', true );
		if ( empty( $sessions ) || ! is_array( $sessions ) ) {
			$sessions = array();
		}

		if ( ! in_array( $session_id, $sessions, true ) ) {
			$sessions[] = $session_id;
			update_user_meta( $user_id, '_acp_sessions', $sessions );
		}
	}
}
