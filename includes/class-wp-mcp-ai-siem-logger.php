<?php
/**
 * SIEM Logger for WP oOS
 *
 * Exports security events to SIEM systems in multiple formats.
 * Supports Syslog, JSON, Common Event Format (CEF), and custom formats.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
	/**
	 * SIEM Logger class for security event export.
	 */
	class WP_MCP_AI_SIEM_Logger {

		/**
		 * SIEM export formats.
		 */
		const FORMAT_SYSLOG = 'syslog';
		const FORMAT_JSON   = 'json';
		const FORMAT_CEF    = 'cef';
		const FORMAT_CUSTOM = 'custom';

		/**
		 * Security event types.
		 */
		const EVENT_AUTH_SUCCESS      = 'auth_success';
		const EVENT_AUTH_FAILURE      = 'auth_failure';
		const EVENT_AUTH_LOGOUT       = 'auth_logout';
		const EVENT_ACCESS_DENIED     = 'access_denied';
		const EVENT_PRIVILEGE_CHANGE  = 'privilege_change';
		const EVENT_API_KEY_CREATED   = 'api_key_created';
		const EVENT_API_KEY_ROTATED   = 'api_key_rotated';
		const EVENT_API_KEY_REVOKED   = 'api_key_revoked';
		const EVENT_DATA_ACCESS       = 'data_access';
		const EVENT_DATA_MODIFIED     = 'data_modified';
		const EVENT_DATA_DELETED      = 'data_deleted';
		const EVENT_CONFIG_CHANGE     = 'config_change';
		const EVENT_RATE_LIMIT        = 'rate_limit';
		const EVENT_SUSPICIOUS        = 'suspicious';
		const EVENT_FILE_SCAN         = 'file_scan';

		/**
		 * Severity levels (RFC 5424).
		 */
		const SEVERITY_EMERGENCY = 0; // System is unusable.
		const SEVERITY_ALERT     = 1; // Action must be taken immediately.
		const SEVERITY_CRITICAL  = 2; // Critical conditions.
		const SEVERITY_ERROR     = 3; // Error conditions.
		const SEVERITY_WARNING   = 4; // Warning conditions.
		const SEVERITY_NOTICE    = 5; // Normal but significant.
		const SEVERITY_INFO      = 6; // Informational messages.
		const SEVERITY_DEBUG     = 7; // Debug-level messages.

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_SIEM_Logger|null
		 */
		private static $instance = null;

		/**
		 * SIEM configuration.
		 *
		 * @var array
		 */
		private $config = array();

		/**
		 * Get singleton instance.
		 *
		 * @return WP_MCP_AI_SIEM_Logger
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
		private function __construct() {
			$this->load_config();
		}

		/**
		 * Load SIEM configuration from settings.
		 */
		private function load_config() {
			$settings = get_option( 'wp_mcp_ai_siem_settings', array() );

			$defaults = array(
				'enabled'         => false,
				'format'          => self::FORMAT_JSON,
				'endpoint'        => '',
				'endpoint_type'   => 'http',
				'endpoint_url'    => '',
				'facility'        => LOG_USER,
				'severity_map'    => $this->get_default_severity_map(),
				'batch_size'      => 100,
				'batch_interval'  => 60, // seconds.
				'include_context' => true,
				'redact_pii'      => true,
			);

			$this->config = wp_parse_args( $settings, $defaults );
		}

		/**
		 * Get default severity mapping.
		 *
		 * @return array
		 */
		private function get_default_severity_map() {
			return array(
				'emergency' => LOG_EMERG,
				'alert'     => LOG_ALERT,
				'critical'  => LOG_CRIT,
				'error'     => LOG_ERR,
				'warning'   => LOG_WARNING,
				'notice'    => LOG_NOTICE,
				'info'      => LOG_INFO,
				'debug'     => LOG_DEBUG,
			);
		}

		/**
		 * Check if SIEM export is enabled.
		 *
		 * @return bool
		 */
		public function is_enabled() {
			return ! empty( $this->config['enabled'] );
		}

		/**
		 * Check if SIEM export is enabled (static wrapper).
		 *
		 * @return bool
		 */
		public static function is_enabled_static() {
			/**
			 * Filter to enable/disable SIEM logging.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether SIEM logging is enabled. Default false.
			 */
			return apply_filters( 'wp_mcp_ai_siem_enabled', false );
		}

		/**
		 * Log a security event (static wrapper for external callers).
		 *
		 * This is the public API that other classes should use.
		 *
		 * @param string $event_type Event type (use EVENT_* constants).
		 * @param string $message    Event message.
		 * @param array  $context    Event context data.
		 * @param int    $severity   Event severity (use SEVERITY_* constants).
		 * @return bool True on success, false on failure.
		 */
		public static function log_security_event( $event_type, $message, $context = array(), $severity = self::SEVERITY_INFO ) {
			if ( ! self::is_enabled_static() ) {
				return false;
			}

			$instance = self::get_instance();
			
			// Convert severity int to string for internal API.
			$severity_string = self::severity_to_string( $severity );
			
			return $instance->export_event( $event_type, $message, $context, $severity_string );
		}

		/**
		 * Convert severity integer to string.
		 *
		 * @param int $severity Severity level constant.
		 * @return string Severity string.
		 */
		private static function severity_to_string( $severity ) {
			$map = array(
				self::SEVERITY_EMERGENCY => 'emergency',
				self::SEVERITY_ALERT     => 'alert',
				self::SEVERITY_CRITICAL  => 'critical',
				self::SEVERITY_ERROR     => 'error',
				self::SEVERITY_WARNING   => 'warning',
				self::SEVERITY_NOTICE    => 'notice',
				self::SEVERITY_INFO      => 'info',
				self::SEVERITY_DEBUG     => 'debug',
			);

			return isset( $map[ $severity ] ) ? $map[ $severity ] : 'info';
		}

		/**
		 * Get severity label for a severity level (for tests).
		 *
		 * @param int $severity Severity level constant.
		 * @return string Severity label.
		 */
		private static function get_severity_label( $severity ) {
			$labels = array(
				self::SEVERITY_EMERGENCY => 'EMERGENCY',
				self::SEVERITY_ALERT     => 'ALERT',
				self::SEVERITY_CRITICAL  => 'CRITICAL',
				self::SEVERITY_ERROR     => 'ERROR',
				self::SEVERITY_WARNING   => 'WARNING',
				self::SEVERITY_NOTICE    => 'NOTICE',
				self::SEVERITY_INFO      => 'INFO',
				self::SEVERITY_DEBUG     => 'DEBUG',
			);

			return isset( $labels[ $severity ] ) ? $labels[ $severity ] : 'INFO';
		}

		/**
		 * Generate a correlation ID (static wrapper for tests).
		 *
		 * @return string Correlation ID.
		 */
		public static function generate_correlation_id() {
			return sprintf(
				'wpmcp-%s-%s',
				time(),
				wp_generate_password( 12, false, false )
			);
		}

		/**
		 * Get SIEM configuration (static wrapper for tests).
		 *
		 * @return array Configuration array.
		 */
		public static function get_config() {
			$instance = self::get_instance();
			return $instance->config;
		}

		/**
		 * Anonymize an IP address (static wrapper for tests).
		 *
		 * @param string $ip IP address to anonymize.
		 * @return string Anonymized IP address.
		 */
		private static function anonymize_ip( $ip ) {
			// IPv4.
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$parts = explode( '.', $ip );
				$parts[3] = '0';
				return implode( '.', $parts );
			}

			// IPv6.
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				$parts = explode( ':', $ip );
				// Zero out the last 4 groups.
				for ( $i = count( $parts ) - 4; $i < count( $parts ); $i++ ) {
					if ( isset( $parts[ $i ] ) ) {
						$parts[ $i ] = '0';
					}
				}
				return implode( ':', $parts );
			}

			return $ip;
		}

		/**
		 * Export security event to SIEM.
		 *
		 * @param string $event_type Event type identifier.
		 * @param string $message    Event message.
		 * @param array  $context    Event context data.
		 * @param string $severity   Event severity level.
		 * @return bool True on success, false on failure.
		 */
		public function export_event( $event_type, $message, $context = array(), $severity = 'info' ) {
			if ( ! $this->is_enabled() ) {
				return false;
			}

			// Redact PII if enabled.
			if ( $this->config['redact_pii'] ) {
				$context = $this->redact_pii( $context );
				$message = $this->redact_pii_string( $message );
			}

			// Add correlation ID if available.
			if ( ! isset( $context['correlation_id'] ) ) {
				$context['correlation_id'] = $this->get_correlation_id();
			}

			// Build event data.
			$event_data = array(
				'timestamp'      => current_time( 'mysql' ),
				'timestamp_unix' => time(),
				'event_type'     => sanitize_key( $event_type ),
				'message'        => sanitize_text_field( $message ),
				'severity'       => $this->normalize_severity( $severity ),
				'context'        => $context,
				'source'         => array(
					'plugin'  => 'wp-mcp-ai',
					'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
					'site'    => get_site_url(),
				),
			);

			// Format and export based on configured format.
			switch ( $this->config['format'] ) {
				case self::FORMAT_SYSLOG:
					return $this->export_to_syslog( $event_data );

				case self::FORMAT_JSON:
					return $this->export_to_json( $event_data );

				case self::FORMAT_CEF:
					return $this->export_to_cef( $event_data );

				case self::FORMAT_CUSTOM:
					return $this->export_custom( $event_data );

				default:
					return false;
			}
		}

		/**
		 * Export event to syslog.
		 *
		 * @param array $event_data Event data.
		 * @return bool
		 */
		private function export_to_syslog( $event_data ) {
			$severity = isset( $this->config['severity_map'][ $event_data['severity'] ] )
				? $this->config['severity_map'][ $event_data['severity'] ]
				: LOG_INFO;

			$message = sprintf(
				'[WP-MCP-AI] %s: %s | Context: %s',
				strtoupper( $event_data['event_type'] ),
				$event_data['message'],
				wp_json_encode( $event_data['context'] )
			);

			openlog( 'wp-mcp-ai', LOG_PID | LOG_PERROR, $this->config['facility'] );
			$result = syslog( $severity, $message );
			closelog();

			return $result;
		}

		/**
		 * Export event to JSON endpoint.
		 *
		 * @param array $event_data Event data.
		 * @return bool
		 */
		private function export_to_json( $event_data ) {
			if ( empty( $this->config['endpoint'] ) ) {
				return false;
			}

			$response = wp_remote_post(
				$this->config['endpoint'],
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $event_data ),
					'timeout' => 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'WP-MCP-AI SIEM Export Error: ' . $response->get_error_message() );
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			return $status_code >= 200 && $status_code < 300;
		}

		/**
		 * Export event to Common Event Format (CEF).
		 *
		 * @param array $event_data Event data.
		 * @return bool
		 */
		private function export_to_cef( $event_data ) {
			// CEF Format: CEF:Version|Device Vendor|Device Product|Device Version|Signature ID|Name|Severity|Extension
			$cef_message = sprintf(
				'CEF:0|WordPress|WP-MCP-AI|%s|%s|%s|%d|%s',
				$event_data['source']['version'],
				$event_data['event_type'],
				$event_data['message'],
				$this->severity_to_cef_level( $event_data['severity'] ),
				$this->build_cef_extension( $event_data['context'] )
			);

			// Export via syslog or endpoint.
			if ( ! empty( $this->config['endpoint'] ) ) {
				return $this->export_cef_to_endpoint( $cef_message );
			}

			openlog( 'wp-mcp-ai', LOG_PID | LOG_PERROR, $this->config['facility'] );
			$result = syslog( LOG_INFO, $cef_message );
			closelog();

			return $result;
		}

		/**
		 * Export CEF message to endpoint.
		 *
		 * @param string $cef_message CEF formatted message.
		 * @return bool
		 */
		private function export_cef_to_endpoint( $cef_message ) {
			$response = wp_remote_post(
				$this->config['endpoint'],
				array(
					'headers' => array(
						'Content-Type' => 'text/plain',
					),
					'body'    => $cef_message,
					'timeout' => 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			return $status_code >= 200 && $status_code < 300;
		}

		/**
		 * Build CEF extension field.
		 *
		 * @param array $context Event context.
		 * @return string
		 */
		private function build_cef_extension( $context ) {
			$extensions = array();

			foreach ( $context as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$cef_key              = str_replace( '_', '', ucwords( $key, '_' ) );
					$extensions[ $cef_key ] = $this->escape_cef_value( $value );
				}
			}

			$parts = array();
			foreach ( $extensions as $key => $value ) {
				$parts[] = "{$key}={$value}";
			}

			return implode( ' ', $parts );
		}

		/**
		 * Escape CEF value.
		 *
		 * @param mixed $value Value to escape.
		 * @return string
		 */
		private function escape_cef_value( $value ) {
			$value = (string) $value;
			$value = str_replace( '\\', '\\\\', $value );
			$value = str_replace( '=', '\\=', $value );
			$value = str_replace( "\n", '\\n', $value );
			$value = str_replace( "\r", '\\r', $value );
			return $value;
		}

		/**
		 * Convert severity to CEF level (0-10).
		 *
		 * @param string $severity Severity level.
		 * @return int
		 */
		private function severity_to_cef_level( $severity ) {
			$map = array(
				'emergency' => 10,
				'alert'     => 9,
				'critical'  => 8,
				'error'     => 7,
				'warning'   => 6,
				'notice'    => 5,
				'info'      => 4,
				'debug'     => 2,
			);

			return isset( $map[ $severity ] ) ? $map[ $severity ] : 4;
		}

		/**
		 * Export event with custom format.
		 *
		 * @param array $event_data Event data.
		 * @return bool
		 */
		private function export_custom( $event_data ) {
			/**
			 * Allows custom SIEM export implementation.
			 *
			 * @param bool  $exported   Whether event was exported.
			 * @param array $event_data Event data.
			 */
			return apply_filters( 'wp_mcp_ai_siem_export_custom', false, $event_data );
		}

		/**
		 * Normalize severity level.
		 *
		 * @param string $severity Input severity.
		 * @return string Normalized severity.
		 */
		private function normalize_severity( $severity ) {
			$valid = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

			$severity = strtolower( sanitize_key( $severity ) );

			return in_array( $severity, $valid, true ) ? $severity : 'info';
		}

		/**
		 * Redact PII from context array.
		 *
		 * @param array $context Event context.
		 * @return array Redacted context.
		 */
		private function redact_pii( $context ) {
			$pii_fields = array( 'password', 'token', 'secret', 'api_key', 'bearer', 'authorization' );

			foreach ( $context as $key => $value ) {
				$key_lower = strtolower( $key );

				// Redact known PII fields.
				foreach ( $pii_fields as $pii_field ) {
					if ( strpos( $key_lower, $pii_field ) !== false ) {
						$context[ $key ] = '[REDACTED]';
						continue 2;
					}
				}

				// Redact email addresses.
				if ( is_string( $value ) && is_email( $value ) ) {
					$context[ $key ] = $this->redact_email( $value );
				}

				// Recursively redact arrays.
				if ( is_array( $value ) ) {
					$context[ $key ] = $this->redact_pii( $value );
				}
			}

			return $context;
		}

		/**
		 * Redact PII from string.
		 *
		 * @param string $string Input string.
		 * @return string Redacted string.
		 */
		private function redact_pii_string( $string ) {
			// Redact email addresses.
			$string = preg_replace( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $string );

			// Redact IPv4 addresses.
			$string = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP]', $string );

			// Redact tokens (common patterns).
			$string = preg_replace( '/\b[A-Za-z0-9_-]{32,}\b/', '[TOKEN]', $string );

			return $string;
		}

		/**
		 * Redact email address partially.
		 *
		 * @param string $email Email address.
		 * @return string Redacted email.
		 */
		private function redact_email( $email ) {
			$parts = explode( '@', $email );
			if ( count( $parts ) !== 2 ) {
				return '[EMAIL]';
			}

			$local  = $parts[0];
			$domain = $parts[1];

			// Keep first 2 chars of local part.
			$redacted_local = substr( $local, 0, 2 ) . '***';

			return "{$redacted_local}@{$domain}";
		}

		/**
		 * Get or generate correlation ID for current request.
		 *
		 * @return string
		 */
		private function get_correlation_id() {
			static $correlation_id = null;

			if ( null === $correlation_id ) {
				$correlation_id = WP_MCP_AI_Correlation_ID::get_current_id();
			}

			return $correlation_id;
		}

		/**
		 * Get SIEM export statistics.
		 *
		 * @return array
		 */
		public function get_stats() {
			return get_option(
				'wp_mcp_ai_siem_stats',
				array(
					'total_exported'  => 0,
					'export_failures' => 0,
					'last_export'     => null,
				)
			);
		}

		/**
		 * Update SIEM export statistics.
		 *
		 * @param bool $success Whether export succeeded.
		 */
		private function update_stats( $success ) {
			$stats = $this->get_stats();

			if ( $success ) {
				$stats['total_exported']++;
				$stats['last_export'] = current_time( 'mysql' );
			} else {
				$stats['export_failures']++;
			}

			update_option( 'wp_mcp_ai_siem_stats', $stats, false );
		}
	}
}
