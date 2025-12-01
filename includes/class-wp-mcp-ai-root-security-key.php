<?php
/**
 * Root Security Key Manager
 *
 * Provides optional security key verification for plugin initialization.
 * Can be enabled during emergency shutdown to require authentication for re-enabling.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Root_Security_Key' ) ) {
	/**
	 * Manages root security key verification for plugin initialization.
	 */
	class WP_MCP_AI_Root_Security_Key {
		/**
		 * Option key for storing security key requirement state.
		 */
		const OPTION_KEY_REQUIRED = 'wp_mcp_ai_root_key_required';

		/**
		 * Option key for storing failed verification attempts.
		 */
		const OPTION_KEY_FAILED_ATTEMPTS = 'wp_mcp_ai_root_key_failed_attempts';

		/**
		 * Transient key for rate limiting verification attempts.
		 */
		const TRANSIENT_RATE_LIMIT = 'wp_mcp_ai_root_key_rate_limit';

		/**
		 * Maximum failed attempts before temporary lockout.
		 */
		const MAX_FAILED_ATTEMPTS = 5;

		/**
		 * Lockout duration in seconds.
		 */
		const LOCKOUT_DURATION = 900; // 15 minutes.

		/**
		 * Time window for counting recent attempts (in seconds).
		 */
		const ATTEMPT_WINDOW = 300; // 5 minutes.

		/**
		 * Maximum number of failed attempts to store.
		 */
		const MAX_STORED_ATTEMPTS = 100;

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Root_Security_Key|null
		 */
		private static $instance = null;

		/**
		 * Get singleton instance.
		 *
		 * @return WP_MCP_AI_Root_Security_Key
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor.
		 */
		private function __construct() {
			// Constructor intentionally empty.
		}

		/**
		 * Check if root security key is configured.
		 *
		 * @return bool True if key is defined.
		 */
		public function is_key_configured() {
			return defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) && ! empty( WP_MCP_AI_ROOT_SECURITY_KEY );
		}

		/**
		 * Check if root security key is required for initialization.
		 *
		 * @return bool True if key verification is required.
		 */
		public function is_key_required() {
			// Key is only required if both:
			// 1. A key is configured via constant.
			// 2. The requirement has been enabled (e.g., during shutdown).
			if ( ! $this->is_key_configured() ) {
				return false;
			}

			$required = get_option( self::OPTION_KEY_REQUIRED, false );
			return ! empty( $required );
		}

		/**
		 * Enable root security key requirement.
		 *
		 * @param string $reason Reason for enabling key requirement.
		 * @return bool True on success, false on failure.
		 */
		public function enable_key_requirement( $reason = '' ) {
			if ( ! $this->is_key_configured() ) {
				return false;
			}

			$data = array(
				'enabled_at' => current_time( 'mysql', true ),
				'enabled_by' => get_current_user_id(),
				'reason'     => sanitize_text_field( $reason ),
			);

			update_option( self::OPTION_KEY_REQUIRED, $data, false );

			WP_MCP_AI_Logger::log_event(
				'root_key_enabled',
				'Root security key requirement enabled',
				array(
					'reason'  => $reason,
					'user_id' => get_current_user_id(),
				)
			);

			return true;
		}

		/**
		 * Disable root security key requirement.
		 *
		 * @param string $provided_key The security key to verify.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function disable_key_requirement( $provided_key ) {
			// Check if locked out due to too many failed attempts.
			if ( $this->is_locked_out() ) {
				return new WP_Error(
					'locked_out',
					__( 'Too many failed attempts. Please try again later.', 'wp-mcp-ai' )
				);
			}

			// Verify the provided key.
			$verification = $this->verify_key( $provided_key );
			if ( is_wp_error( $verification ) ) {
				return $verification;
			}

			// Clear the requirement.
			delete_option( self::OPTION_KEY_REQUIRED );
			delete_option( self::OPTION_KEY_FAILED_ATTEMPTS );

			WP_MCP_AI_Logger::log_event(
				'root_key_disabled',
				'Root security key requirement disabled',
				array( 'user_id' => get_current_user_id() )
			);

			return true;
		}

		/**
		 * Verify provided security key.
		 *
		 * @param string $provided_key The security key to verify.
		 * @return bool|WP_Error True if valid, WP_Error on failure.
		 */
		public function verify_key( $provided_key ) {
			if ( ! $this->is_key_configured() ) {
				return new WP_Error(
					'key_not_configured',
					__( 'Root security key is not configured.', 'wp-mcp-ai' )
				);
			}

			// Use hash_equals to prevent timing attacks.
			$is_valid = hash_equals( WP_MCP_AI_ROOT_SECURITY_KEY, $provided_key );

			if ( ! $is_valid ) {
				$this->record_failed_attempt();

				WP_MCP_AI_Logger::log_event(
					'root_key_verification_failed',
					'Root security key verification failed',
					array(
						'user_id' => get_current_user_id(),
						'ip'      => $this->get_client_ip(),
					)
				);

				return new WP_Error(
					'invalid_key',
					__( 'Invalid security key provided.', 'wp-mcp-ai' )
				);
			}

			// Reset failed attempts on successful verification.
			delete_option( self::OPTION_KEY_FAILED_ATTEMPTS );

			WP_MCP_AI_Logger::log_event(
				'root_key_verification_success',
				'Root security key verified successfully',
				array( 'user_id' => get_current_user_id() )
			);

			return true;
		}

		/**
		 * Check if plugin initialization should proceed.
		 *
		 * This is called during bootstrap to determine if initialization should be blocked.
		 *
		 * @return bool True if initialization should proceed, false if blocked.
		 */
		public function can_initialize() {
			// If key requirement is not enabled, always allow initialization.
			if ( ! $this->is_key_required() ) {
				return true;
			}

			// If in admin, allow showing the unlock interface.
			if ( is_admin() ) {
				return true;
			}

			// Block initialization for non-admin contexts when key is required.
			return false;
		}

		/**
		 * Record a failed verification attempt.
		 */
		private function record_failed_attempt() {
			$attempts = get_option( self::OPTION_KEY_FAILED_ATTEMPTS, array() );
			if ( ! is_array( $attempts ) ) {
				$attempts = array();
			}

			$attempts[] = array(
				'timestamp' => time(),
				'user_id'   => get_current_user_id(),
				'ip'        => $this->get_client_ip(),
			);

			// Keep only last MAX_STORED_ATTEMPTS attempts.
			if ( count( $attempts ) > self::MAX_STORED_ATTEMPTS ) {
				$attempts = array_slice( $attempts, -self::MAX_STORED_ATTEMPTS );
			}

			update_option( self::OPTION_KEY_FAILED_ATTEMPTS, $attempts, false );

			// Check if should trigger lockout.
			$recent_attempts = $this->count_recent_attempts( self::ATTEMPT_WINDOW );
			if ( $recent_attempts >= self::MAX_FAILED_ATTEMPTS ) {
				$this->trigger_lockout();
			}
		}

		/**
		 * Count recent failed attempts within time window.
		 *
		 * @param int $seconds Time window in seconds.
		 * @return int Number of recent attempts.
		 */
		private function count_recent_attempts( $seconds ) {
			$attempts = get_option( self::OPTION_KEY_FAILED_ATTEMPTS, array() );
			if ( ! is_array( $attempts ) ) {
				return 0;
			}

			$cutoff = time() - $seconds;
			$count  = 0;

			foreach ( $attempts as $attempt ) {
				if ( isset( $attempt['timestamp'] ) && $attempt['timestamp'] >= $cutoff ) {
					++$count;
				}
			}

			return $count;
		}

		/**
		 * Trigger temporary lockout.
		 */
		private function trigger_lockout() {
			set_transient( self::TRANSIENT_RATE_LIMIT, true, self::LOCKOUT_DURATION );

			WP_MCP_AI_Logger::log_event(
				'root_key_lockout',
				'Root security key verification locked out due to excessive failed attempts',
				array(
					'user_id'  => get_current_user_id(),
					'ip'       => $this->get_client_ip(),
					'duration' => self::LOCKOUT_DURATION,
				)
			);
		}

		/**
		 * Check if currently locked out.
		 *
		 * @return bool True if locked out.
		 */
		private function is_locked_out() {
			return (bool) get_transient( self::TRANSIENT_RATE_LIMIT );
		}

		/**
		 * Get client IP address.
		 *
		 * @return string
		 */
		private function get_client_ip() {
			$ip = '';

			if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			}

			return $ip;
		}

		/**
		 * Get status information.
		 *
		 * @return array Status information.
		 */
		public function get_status() {
			$status = array(
				'configured'      => $this->is_key_configured(),
				'required'        => $this->is_key_required(),
				'locked_out'      => $this->is_locked_out(),
				'failed_attempts' => $this->count_recent_attempts( self::ATTEMPT_WINDOW ),
			);

			if ( $this->is_key_required() ) {
				$requirement_data     = get_option( self::OPTION_KEY_REQUIRED, array() );
				$status['enabled_at'] = isset( $requirement_data['enabled_at'] ) ? $requirement_data['enabled_at'] : '';
				$status['reason']     = isset( $requirement_data['reason'] ) ? $requirement_data['reason'] : '';
			}

			return $status;
		}
	}
}
