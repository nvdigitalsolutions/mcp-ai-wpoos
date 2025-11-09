<?php
/**
 * PII Detection and Redaction for WP oOS.
 *
 * Automatically detects and redacts Personally Identifiable Information (PII)
 * from logs, responses, and data storage to ensure privacy compliance.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PII detection and redaction service.
 */
class WP_MCP_AI_PII_Detector {

	/**
	 * PII patterns for detection.
	 *
	 * @var array
	 */
	protected static $patterns = array();

	/**
	 * Initialize PII patterns.
	 */
	protected static function init_patterns() {
		if ( ! empty( self::$patterns ) ) {
			return;
		}

		self::$patterns = array(
			'email' => array(
				'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
				'replacement' => '[EMAIL_REDACTED]',
			),
			'phone_us' => array(
				'pattern' => '/\b(?:\+?1[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})\b/',
				'replacement' => '[PHONE_REDACTED]',
			),
			'ssn' => array(
				'pattern' => '/\b(?!000|666|9\d{2})\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/',
				'replacement' => '[SSN_REDACTED]',
			),
			'credit_card' => array(
				'pattern' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|3(?:0[0-5]|[68][0-9])[0-9]{11}|6(?:011|5[0-9]{2})[0-9]{12})\b/',
				'replacement' => '[CARD_REDACTED]',
			),
			'ip_address' => array(
				'pattern' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
				'replacement' => '[IP_REDACTED]',
			),
			'ipv6_address' => array(
				'pattern' => '/\b(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}\b/',
				'replacement' => '[IPV6_REDACTED]',
			),
			'api_key' => array(
				'pattern' => '/\b(?:sk-[a-zA-Z0-9]{48}|pk-[a-zA-Z0-9]{48})\b/',
				'replacement' => '[API_KEY_REDACTED]',
			),
			'bearer_token' => array(
				'pattern' => '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
				'replacement' => 'Bearer [TOKEN_REDACTED]',
			),
			'password' => array(
				'pattern' => '/(?:password|passwd|pwd)[\s:=]+["\']?([^\s"\'<>]+)["\']?/i',
				'replacement' => 'password=[PASSWORD_REDACTED]',
			),
		);

		/**
		 * Filter PII detection patterns.
		 *
		 * @since 1.0.0
		 *
		 * @param array $patterns PII patterns array.
		 */
		self::$patterns = apply_filters( 'wp_mcp_ai_pii_patterns', self::$patterns );
	}

	/**
	 * Detect PII in text.
	 *
	 * @param string $text Text to scan.
	 * @return array Detected PII types.
	 */
	public static function detect( $text ) {
		self::init_patterns();

		$detected = array();

		foreach ( self::$patterns as $type => $config ) {
			if ( preg_match( $config['pattern'], $text ) ) {
				$detected[] = $type;
			}
		}

		return $detected;
	}

	/**
	 * Redact PII from text.
	 *
	 * @param string $text Text to redact.
	 * @param array  $types Optional specific types to redact. Empty = all types.
	 * @return string Redacted text.
	 */
	public static function redact( $text, $types = array() ) {
		self::init_patterns();

		$redacted = $text;

		foreach ( self::$patterns as $type => $config ) {
			// Skip if specific types requested and this isn't one of them.
			if ( ! empty( $types ) && ! in_array( $type, $types, true ) ) {
				continue;
			}

			$redacted = preg_replace( $config['pattern'], $config['replacement'], $redacted );
		}

		/**
		 * Filter redacted text.
		 *
		 * @since 1.0.0
		 *
		 * @param string $redacted Redacted text.
		 * @param string $original Original text.
		 * @param array  $types    Types that were redacted.
		 */
		return apply_filters( 'wp_mcp_ai_pii_redacted_text', $redacted, $text, $types );
	}

	/**
	 * Redact PII from array recursively.
	 *
	 * @param array $data Data array to redact.
	 * @param array $types Optional specific types to redact.
	 * @return array Redacted data.
	 */
	public static function redact_array( $data, $types = array() ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_array( $value, $types );
			} elseif ( is_string( $value ) ) {
				$redacted[ $key ] = self::redact( $value, $types );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Check if text contains PII.
	 *
	 * @param string $text Text to check.
	 * @return bool True if PII detected, false otherwise.
	 */
	public static function contains_pii( $text ) {
		$detected = self::detect( $text );
		return ! empty( $detected );
	}

	/**
	 * Redact sensitive field names from arrays.
	 *
	 * @param array $data Data array.
	 * @return array Redacted data.
	 */
	public static function redact_sensitive_keys( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive_keys = array(
			'password',
			'passwd',
			'pwd',
			'secret',
			'api_key',
			'apikey',
			'token',
			'access_token',
			'refresh_token',
			'bearer',
			'authorization',
			'auth',
			'ssn',
			'social_security',
			'credit_card',
			'card_number',
			'cvv',
			'pin',
			'private_key',
		);

		/**
		 * Filter sensitive key names.
		 *
		 * @since 1.0.0
		 *
		 * @param array $keys Sensitive key names.
		 */
		$sensitive_keys = apply_filters( 'wp_mcp_ai_sensitive_keys', $sensitive_keys );

		$redacted = array();

		foreach ( $data as $key => $value ) {
			$key_lower = strtolower( $key );

			// Check if key is sensitive.
			$is_sensitive = false;
			foreach ( $sensitive_keys as $sensitive ) {
				if ( strpos( $key_lower, $sensitive ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			if ( $is_sensitive ) {
				$redacted[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_sensitive_keys( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Sanitize text for safe logging (redact PII and sensitive data).
	 *
	 * @param string $text Text to sanitize.
	 * @return string Sanitized text.
	 */
	public static function sanitize_for_logging( $text ) {
		// First redact PII.
		$sanitized = self::redact( $text );

		// Then apply additional sanitization.
		$sanitized = wp_kses( $sanitized, array() ); // Strip all HTML.
		$sanitized = sanitize_text_field( $sanitized );

		return $sanitized;
	}

	/**
	 * Sanitize array for safe logging.
	 *
	 * @param array $data Data to sanitize.
	 * @return array Sanitized data.
	 */
	public static function sanitize_array_for_logging( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// First redact sensitive keys.
		$sanitized = self::redact_sensitive_keys( $data );

		// Then redact PII from values.
		$sanitized = self::redact_array( $sanitized );

		return $sanitized;
	}

	/**
	 * Get PII detection report for text.
	 *
	 * @param string $text Text to analyze.
	 * @return array Report with detected types and counts.
	 */
	public static function get_detection_report( $text ) {
		self::init_patterns();

		$report = array(
			'has_pii' => false,
			'types'   => array(),
			'count'   => 0,
		);

		foreach ( self::$patterns as $type => $config ) {
			$matches = array();
			preg_match_all( $config['pattern'], $text, $matches );

			if ( ! empty( $matches[0] ) ) {
				$report['has_pii'] = true;
				$report['types'][ $type ] = count( $matches[0] );
				$report['count'] += count( $matches[0] );
			}
		}

		return $report;
	}

	/**
	 * Partially redact email (show first 2 chars and domain).
	 *
	 * @param string $email Email address.
	 * @return string Partially redacted email.
	 */
	public static function partial_redact_email( $email ) {
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return '[INVALID_EMAIL]';
		}

		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '[INVALID_EMAIL]';
		}

		$local = $parts[0];
		$domain = $parts[1];

		// Show first 2 characters of local part.
		$visible = substr( $local, 0, 2 );
		$hidden = str_repeat( '*', max( 0, strlen( $local ) - 2 ) );

		return $visible . $hidden . '@' . $domain;
	}

	/**
	 * Partially redact phone number (show last 4 digits).
	 *
	 * @param string $phone Phone number.
	 * @return string Partially redacted phone.
	 */
	public static function partial_redact_phone( $phone ) {
		// Remove non-digits.
		$digits = preg_replace( '/\D/', '', $phone );

		if ( strlen( $digits ) < 4 ) {
			return '[INVALID_PHONE]';
		}

		// Show last 4 digits.
		$visible = substr( $digits, -4 );
		$hidden = str_repeat( '*', strlen( $digits ) - 4 );

		return $hidden . $visible;
	}

	/**
	 * Check if PII redaction is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filter to enable/disable PII redaction.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether PII redaction is enabled. Default true.
		 */
		return apply_filters( 'wp_mcp_ai_pii_redaction_enabled', true );
	}

	/**
	 * Apply PII redaction to log entry.
	 *
	 * @param array $entry Log entry.
	 * @return array Redacted log entry.
	 */
	public static function redact_log_entry( $entry ) {
		if ( ! self::is_enabled() ) {
			return $entry;
		}

		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		// Redact message.
		if ( isset( $entry['message'] ) && is_string( $entry['message'] ) ) {
			$entry['message'] = self::redact( $entry['message'] );
		}

		// Redact context.
		if ( isset( $entry['context'] ) && is_array( $entry['context'] ) ) {
			$entry['context'] = self::sanitize_array_for_logging( $entry['context'] );
		}

		return $entry;
	}
}
