<?php
/**
 * SIEM Integration Logger for WP oOS.
 *
 * Provides enterprise-grade SIEM (Security Information and Event Management) integration
 * with support for syslog, remote endpoints, and structured security event logging.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SIEM Logger for security events and audit trails.
 */
class WP_MCP_AI_SIEM_Logger {

	/**
	 * Event severity levels (RFC 5424 compliant).
	 */
	const SEVERITY_EMERGENCY = 0; // System is unusable.
	const SEVERITY_ALERT     = 1; // Action must be taken immediately.
	const SEVERITY_CRITICAL  = 2; // Critical conditions.
	const SEVERITY_ERROR     = 3; // Error conditions.
	const SEVERITY_WARNING   = 4; // Warning conditions.
	const SEVERITY_NOTICE    = 5; // Normal but significant condition.
	const SEVERITY_INFO      = 6; // Informational messages.
	const SEVERITY_DEBUG     = 7; // Debug-level messages.

	/**
	 * Security event types.
	 */
	const EVENT_AUTH_SUCCESS     = 'auth.success';
	const EVENT_AUTH_FAILURE     = 'auth.failure';
	const EVENT_AUTH_LOGOUT      = 'auth.logout';
	const EVENT_ACCESS_DENIED    = 'access.denied';
	const EVENT_PRIVILEGE_CHANGE = 'privilege.change';
	const EVENT_API_KEY_CREATED  = 'api_key.created';
	const EVENT_API_KEY_ROTATED  = 'api_key.rotated';
	const EVENT_API_KEY_REVOKED  = 'api_key.revoked';
	const EVENT_DATA_ACCESS      = 'data.access';
	const EVENT_DATA_MODIFIED    = 'data.modified';
	const EVENT_DATA_DELETED     = 'data.deleted';
	const EVENT_CONFIG_CHANGE    = 'config.change';
	const EVENT_RATE_LIMIT       = 'rate_limit.exceeded';
	const EVENT_SUSPICIOUS       = 'security.suspicious';
	const EVENT_FILE_UPLOAD      = 'file.upload';
	const EVENT_FILE_SCAN        = 'file.scan';

	/**
	 * Check if SIEM logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filter to enable/disable SIEM logging.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether SIEM logging is enabled. Default false.
		 */
		return apply_filters( 'wp_mcp_ai_siem_enabled', false );
	}

	/**
	 * Get SIEM endpoint configuration.
	 *
	 * @return array SIEM endpoint configuration.
	 */
	public static function get_config() {
		$config = array(
			'enabled'        => self::is_enabled(),
			'endpoint_type'  => get_option( 'wp_mcp_ai_siem_endpoint_type', 'syslog' ),
			'endpoint_url'   => get_option( 'wp_mcp_ai_siem_endpoint_url', '' ),
			'endpoint_token' => get_option( 'wp_mcp_ai_siem_endpoint_token', '' ),
			'facility'       => get_option( 'wp_mcp_ai_siem_facility', 'local0' ),
			'include_pii'    => get_option( 'wp_mcp_ai_siem_include_pii', false ),
		);

		/**
		 * Filter SIEM configuration.
		 *
		 * @since 1.0.0
		 *
		 * @param array $config SIEM configuration array.
		 */
		return apply_filters( 'wp_mcp_ai_siem_config', $config );
	}

	/**
	 * Log a security event to SIEM.
	 *
	 * @param string $event_type Event type constant.
	 * @param string $message    Human-readable message.
	 * @param array  $context    Additional context data.
	 * @param int    $severity   Event severity level.
	 * @return bool True if logged successfully, false otherwise.
	 */
	public static function log_security_event( $event_type, $message, $context = array(), $severity = self::SEVERITY_INFO ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$config = self::get_config();

		// Generate correlation ID if not provided.
		if ( ! isset( $context['correlation_id'] ) ) {
			$context['correlation_id'] = self::generate_correlation_id();
		}

		// Build structured event.
		$event = self::build_event( $event_type, $message, $context, $severity );

		// Redact PII if configured.
		if ( ! $config['include_pii'] ) {
			$event = self::redact_pii( $event );
		}

		// Send to configured endpoint.
		switch ( $config['endpoint_type'] ) {
			case 'syslog':
				return self::send_to_syslog( $event, $severity, $config['facility'] );

			case 'http':
				return self::send_to_http_endpoint( $event, $config );

			case 'webhook':
				return self::send_to_webhook( $event, $config );

			default:
				return false;
		}
	}

	/**
	 * Build a structured security event.
	 *
	 * @param string $event_type Event type.
	 * @param string $message    Message.
	 * @param array  $context    Context.
	 * @param int    $severity   Severity level.
	 * @return array Structured event.
	 */
	protected static function build_event( $event_type, $message, $context, $severity ) {
		global $wpdb;

		$event = array(
			'timestamp'      => current_time( 'c', true ), // ISO 8601.
			'event_type'     => sanitize_key( $event_type ),
			'severity'       => absint( $severity ),
			'severity_label' => self::get_severity_label( $severity ),
			'message'        => sanitize_text_field( $message ),
			'application'    => 'wp-mcp-ai',
			'version'        => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'user_id'        => get_current_user_id(),
			'user_ip'        => self::get_client_ip(),
			'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'request_uri'    => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( $_SERVER['REQUEST_METHOD'] ) : '',
			'context'        => $context,
		);

		/**
		 * Filter the structured security event before sending to SIEM.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $event      Structured event data.
		 * @param string $event_type Event type.
		 * @param string $message    Event message.
		 * @param array  $context    Event context.
		 */
		return apply_filters( 'wp_mcp_ai_siem_event', $event, $event_type, $message, $context );
	}

	/**
	 * Send event to syslog.
	 *
	 * @param array  $event    Event data.
	 * @param int    $severity Severity level.
	 * @param string $facility Syslog facility.
	 * @return bool
	 */
	protected static function send_to_syslog( $event, $severity, $facility ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_openlog, WordPress.PHP.DevelopmentFunctions.error_log_syslog, WordPress.PHP.DevelopmentFunctions.error_log_closelog
		if ( ! function_exists( 'openlog' ) || ! function_exists( 'syslog' ) || ! function_exists( 'closelog' ) ) {
			return false;
		}

		$facility_const = self::get_syslog_facility( $facility );
		if ( false === $facility_const ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_openlog
		openlog( 'wp-mcp-ai', LOG_PID | LOG_ODELAY, $facility_const );

		$message = wp_json_encode( $event );
		if ( false === $message ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_closelog
			closelog();
			return false;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_syslog
		$result = syslog( $severity, $message );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_closelog
		closelog();

		return $result;
	}

	/**
	 * Send event to HTTP endpoint.
	 *
	 * @param array $event  Event data.
	 * @param array $config SIEM configuration.
	 * @return bool
	 */
	protected static function send_to_http_endpoint( $event, $config ) {
		if ( empty( $config['endpoint_url'] ) ) {
			return false;
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $config['endpoint_token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $config['endpoint_token'];
		}

		$response = wp_remote_post(
			$config['endpoint_url'],
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $event ),
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_event( 'siem_error', 'Failed to send event to SIEM endpoint', array( 'error' => $response->get_error_message() ) );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code >= 200 && $status_code < 300;
	}

	/**
	 * Send event to webhook.
	 *
	 * @param array $event  Event data.
	 * @param array $config SIEM configuration.
	 * @return bool
	 */
	protected static function send_to_webhook( $event, $config ) {
		// Webhooks use same mechanism as HTTP endpoints.
		return self::send_to_http_endpoint( $event, $config );
	}

	/**
	 * Redact PII from event data.
	 *
	 * @param array $event Event data.
	 * @return array Event data with PII redacted.
	 */
	protected static function redact_pii( $event ) {
		// Redact IP address.
		if ( isset( $event['user_ip'] ) ) {
			$event['user_ip'] = self::anonymize_ip( $event['user_ip'] );
		}

		// Redact user agent.
		if ( isset( $event['user_agent'] ) ) {
			$event['user_agent'] = '[REDACTED]';
		}

		// Redact sensitive context fields.
		if ( isset( $event['context'] ) && is_array( $event['context'] ) ) {
			$sensitive_keys = array( 'email', 'password', 'token', 'api_key', 'secret', 'ssn', 'phone', 'address' );
			foreach ( $sensitive_keys as $key ) {
				if ( isset( $event['context'][ $key ] ) ) {
					$event['context'][ $key ] = '[REDACTED]';
				}
			}
		}

		/**
		 * Filter event data after PII redaction.
		 *
		 * @since 1.0.0
		 *
		 * @param array $event Event data with PII redacted.
		 */
		return apply_filters( 'wp_mcp_ai_siem_redacted_event', $event );
	}

	/**
	 * Anonymize IP address.
	 *
	 * @param string $ip IP address.
	 * @return string Anonymized IP address.
	 */
	protected static function anonymize_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// IPv4: mask last octet.
			$parts = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// IPv6: mask last 80 bits.
			$parts = explode( ':', $ip );
			for ( $i = 3; $i < count( $parts ); $i++ ) {
				$parts[ $i ] = '0';
			}
			return implode( ':', $parts );
		}
		return '[INVALID]';
	}

	/**
	 * Generate a correlation ID for distributed tracing.
	 *
	 * @return string Correlation ID.
	 */
	public static function generate_correlation_id() {
		return sprintf(
			'%s-%s-%s',
			uniqid( 'wpmcp', true ),
			time(),
			wp_generate_password( 8, false )
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	protected static function get_client_ip() {
		$ip = '';

		// Check for proxied requests.
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// Handle comma-separated IPs in X-Forwarded-For.
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip = trim( $ips[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					break;
				}
			}
		}

		return $ip;
	}

	/**
	 * Get severity label from severity level.
	 *
	 * @param int $severity Severity level.
	 * @return string Severity label.
	 */
	protected static function get_severity_label( $severity ) {
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
		return isset( $labels[ $severity ] ) ? $labels[ $severity ] : 'UNKNOWN';
	}

	/**
	 * Get syslog facility constant.
	 *
	 * @param string $facility Facility name.
	 * @return int|false Facility constant or false if not found.
	 */
	protected static function get_syslog_facility( $facility ) {
		$facilities = array(
			'auth'     => defined( 'LOG_AUTH' ) ? LOG_AUTH : false,
			'authpriv' => defined( 'LOG_AUTHPRIV' ) ? LOG_AUTHPRIV : false,
			'cron'     => defined( 'LOG_CRON' ) ? LOG_CRON : false,
			'daemon'   => defined( 'LOG_DAEMON' ) ? LOG_DAEMON : false,
			'kern'     => defined( 'LOG_KERN' ) ? LOG_KERN : false,
			'local0'   => defined( 'LOG_LOCAL0' ) ? LOG_LOCAL0 : false,
			'local1'   => defined( 'LOG_LOCAL1' ) ? LOG_LOCAL1 : false,
			'local2'   => defined( 'LOG_LOCAL2' ) ? LOG_LOCAL2 : false,
			'local3'   => defined( 'LOG_LOCAL3' ) ? LOG_LOCAL3 : false,
			'local4'   => defined( 'LOG_LOCAL4' ) ? LOG_LOCAL4 : false,
			'local5'   => defined( 'LOG_LOCAL5' ) ? LOG_LOCAL5 : false,
			'local6'   => defined( 'LOG_LOCAL6' ) ? LOG_LOCAL6 : false,
			'local7'   => defined( 'LOG_LOCAL7' ) ? LOG_LOCAL7 : false,
			'lpr'      => defined( 'LOG_LPR' ) ? LOG_LPR : false,
			'mail'     => defined( 'LOG_MAIL' ) ? LOG_MAIL : false,
			'news'     => defined( 'LOG_NEWS' ) ? LOG_NEWS : false,
			'syslog'   => defined( 'LOG_SYSLOG' ) ? LOG_SYSLOG : false,
			'user'     => defined( 'LOG_USER' ) ? LOG_USER : false,
			'uucp'     => defined( 'LOG_UUCP' ) ? LOG_UUCP : false,
		);

		return isset( $facilities[ $facility ] ) ? $facilities[ $facility ] : false;
	}
}
