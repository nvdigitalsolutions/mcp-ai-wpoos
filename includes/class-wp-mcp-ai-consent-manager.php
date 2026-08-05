<?php
/**
 * AI Consent Manager
 *
 * Collects, records, and verifies user consent before AI interaction,
 * as required by EU AI Act Article 50(1) and India IT Rules 2026 (SGI).
 *
 * Stores consent state:
 * - Server-side: user meta for logged-in users, security audit log events
 * - Client-side: localStorage for guest users (managed by chat.js)
 *
 * @package WP_MCP_AI
 * @since   1.1.45
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Consent Manager class.
 *
 * Singleton that manages AI interaction consent lifecycle:
 * recording, verification, and revocation.
 */
class WP_MCP_AI_Consent_Manager {

	/**
	 * User meta key for consent state.
	 *
	 * @var string
	 */
	const CONSENT_META_KEY = '_wp_mcp_ai_consent';

	/**
	 * User meta key for consent timestamp.
	 *
	 * @var string
	 */
	const CONSENT_TIMESTAMP_META_KEY = '_wp_mcp_ai_consent_at';

	/**
	 * Event type for consent granted in the security audit log.
	 *
	 * @var string
	 */
	const EVENT_CONSENT_GRANTED = 'ai_consent_granted';

	/**
	 * Event type for consent revoked in the security audit log.
	 *
	 * @var string
	 */
	const EVENT_CONSENT_REVOKED = 'ai_consent_revoked';

	/**
	 * REST namespace for consent endpoints.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Consent_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Consent_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register the consent REST endpoint.
	 *
	 * Hooks into rest_api_init. Called from Transparency Service boot().
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function register_consent_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/transparency/consent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::get_instance(), 'handle_consent_request' ),
				'permission_callback' => array( self::get_instance(), 'consent_permission_check' ),
				'args'                => array(
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'grant', 'revoke' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission check for consent endpoint.
	 *
	 * Allows any authenticated user or guest to record consent.
	 * Guest consent is tracked client-side; server-side recording
	 * is for audit purposes only.
	 *
	 * @since 1.1.45
	 *
	 * @return true|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function consent_permission_check() {
		// Rate-limit consent recording to prevent abuse.
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' !== $ip ) {
			$transient_key = 'wp_mcp_ai_consent_rate_' . md5( $ip );
			if ( get_transient( $transient_key ) ) {
				return new WP_Error(
					'consent_rate_limited',
					__( 'Too many consent requests. Please wait before trying again.', 'mcp-ai-wpoos' ),
					array( 'status' => 429 )
				);
			}
			set_transient( $transient_key, true, 60 );
		}

		return true;
	}

	/**
	 * Handle consent grant/revoke requests.
	 *
	 * @since 1.1.45
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_consent_request( $request ) {
		$action  = $request->get_param( 'action' );
		$user_id = get_current_user_id();

		if ( 'grant' === $action ) {
			$result = $this->record_consent( $user_id );
		} else {
			$result = $this->revoke_consent( $user_id );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'action'       => $action,
				'consented_at' => 'grant' === $action ? gmdate( 'c' ) : null,
			),
			200
		);
	}

	/**
	 * Record user consent for AI interaction.
	 *
	 * Stores consent state in user meta (logged-in users) and logs
	 * the event in the security audit log.
	 *
	 * @since 1.1.45
	 *
	 * @param int $user_id WordPress user ID (0 for guests).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function record_consent( $user_id ) {
		$user_id = absint( $user_id );

		$context = array(
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '',
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
			'timestamp'  => current_time( 'mysql' ),
		);

		// Store in user meta for logged-in users.
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::CONSENT_META_KEY, 'granted' );
			update_user_meta( $user_id, self::CONSENT_TIMESTAMP_META_KEY, $context['timestamp'] );
		}

		// Log to security audit log.
		if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_MCP_AI_Security_Audit_Logger::log_event(
				self::EVENT_CONSENT_GRANTED,
				$user_id,
				$context
			);
		}

		/**
		 * Fires after AI consent is granted.
		 *
		 * @since 1.1.45
		 *
		 * @param int   $user_id WordPress user ID (0 for guests).
		 * @param array $context Consent context (user_agent, ip, timestamp).
		 */
		do_action( 'wp_mcp_ai_consent_granted', $user_id, $context );

		return true;
	}

	/**
	 * Revoke user consent for AI interaction.
	 *
	 * @since 1.1.45
	 *
	 * @param int $user_id WordPress user ID (0 for guests).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function revoke_consent( $user_id ) {
		$user_id = absint( $user_id );

		$context = array(
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '',
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
			'timestamp'  => current_time( 'mysql' ),
		);

		// Remove from user meta.
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::CONSENT_META_KEY );
			delete_user_meta( $user_id, self::CONSENT_TIMESTAMP_META_KEY );
		}

		// Log to security audit log.
		if ( class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
			WP_MCP_AI_Security_Audit_Logger::log_event(
				self::EVENT_CONSENT_REVOKED,
				$user_id,
				$context
			);
		}

		/**
		 * Fires after AI consent is revoked.
		 *
		 * @since 1.1.45
		 *
		 * @param int   $user_id WordPress user ID (0 for guests).
		 * @param array $context Revocation context.
		 */
		do_action( 'wp_mcp_ai_consent_revoked', $user_id, $context );

		return true;
	}

	/**
	 * Check if a user has consented to AI interaction.
	 *
	 * For logged-in users, checks the user meta flag.
	 * For guests, always returns false (client-side localStorage is authoritative).
	 *
	 * @since 1.1.45
	 *
	 * @param int $user_id WordPress user ID (0 for guests).
	 * @return bool True if consent has been granted.
	 */
	public function has_user_consented( $user_id ) {
		$user_id = absint( $user_id );

		// Guests rely on client-side localStorage; server can't track their consent state.
		if ( 0 === $user_id ) {
			return false;
		}

		$consent = get_user_meta( $user_id, self::CONSENT_META_KEY, true );

		return 'granted' === $consent;
	}

	/**
	 * Get the consent timestamp for a user.
	 *
	 * @since 1.1.45
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|null ISO 8601 timestamp or null if no consent recorded.
	 */
	public function get_consent_timestamp( $user_id ) {
		$user_id = absint( $user_id );

		if ( 0 === $user_id ) {
			return null;
		}

		$timestamp = get_user_meta( $user_id, self::CONSENT_TIMESTAMP_META_KEY, true );

		return ! empty( $timestamp ) ? $timestamp : null;
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 *
	 * @since 1.1.45
	 *
	 * @return void
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
