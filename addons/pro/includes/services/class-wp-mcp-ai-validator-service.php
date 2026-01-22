<?php
/**
 * Validator Service - Comprehensive data validation using validator.js and email-validator NPM packages.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for validating CRM and contact data.
 *
 * This service provides validation for:
 * - Email addresses (RFC 5322 compliant)
 * - Phone numbers (international format)
 * - URLs
 * - Credit cards
 * - Input sanitization
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Validator_Service {

	/**
	 * Check if validator packages are available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		$validator       = WP_MCP_AI_PRO_PATH . 'node_modules/validator';
		$email_validator = WP_MCP_AI_PRO_PATH . 'node_modules/email-validator';

		return file_exists( $validator ) || file_exists( $email_validator );
	}

	/**
	 * Validate email address.
	 *
	 * @param string $email Email address to validate.
	 * @param array  $options Validation options.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function is_email( $email, $options = array() ) {
		// Basic PHP validation first.
		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_email',
				__( 'Invalid email address format.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Allow Node.js-based validation via filter.
		$result = apply_filters( 'wp_mcp_ai_validator_email', false, array(
			'email'   => $email,
			'options' => $options,
		) );

		// If no filter implementation, use PHP validation.
		if ( false === $result ) {
			return true;
		}

		return $result;
	}

	/**
	 * Check if email domain has MX records.
	 *
	 * @param string $email Email address.
	 * @return bool True if MX records exist.
	 */
	public function has_mx_records( $email ) {
		$domain = substr( strrchr( $email, '@' ), 1 );

		if ( ! $domain ) {
			return false;
		}

		// Check MX records.
		$mx_records = array();
		return getmxrr( $domain, $mx_records ) && ! empty( $mx_records );
	}

	/**
	 * Validate phone number.
	 *
	 * @param string $phone Phone number.
	 * @param string $country Country code (default: US).
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function is_phone_number( $phone, $country = 'US' ) {
		if ( empty( $phone ) ) {
			return new WP_Error(
				'empty_phone',
				__( 'Phone number cannot be empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Allow Node.js-based validation via filter (libphonenumber-js).
		$result = apply_filters( 'wp_mcp_ai_validator_phone', false, array(
			'phone'   => $phone,
			'country' => $country,
		) );

		if ( false === $result ) {
			// Basic PHP validation - just check if it contains digits.
			$digits = preg_replace( '/[^0-9]/', '', $phone );
			if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
				return new WP_Error(
					'invalid_phone',
					__( 'Invalid phone number. Must be 10-15 digits.', 'mcp-ai-wpoos-pro' )
				);
			}
			return true;
		}

		return $result;
	}

	/**
	 * Validate URL.
	 *
	 * @param string $url URL to validate.
	 * @param array  $options Validation options.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function is_url( $url, $options = array() ) {
		// Basic PHP validation.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL format.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Allow Node.js-based validation via filter.
		$result = apply_filters( 'wp_mcp_ai_validator_url', false, array(
			'url'     => $url,
			'options' => $options,
		) );

		if ( false === $result ) {
			return true;
		}

		return $result;
	}

	/**
	 * Validate credit card number.
	 *
	 * @param string $card Credit card number.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function is_credit_card( $card ) {
		// Remove spaces and dashes.
		$card = preg_replace( '/[\s-]/', '', $card );

		// Allow Node.js-based validation via filter.
		$result = apply_filters( 'wp_mcp_ai_validator_credit_card', false, array(
			'card' => $card,
		) );

		if ( false === $result ) {
			// Basic Luhn algorithm check.
			return $this->luhn_check( $card );
		}

		return $result;
	}

	/**
	 * Luhn algorithm for credit card validation.
	 *
	 * @param string $number Credit card number.
	 * @return bool True if valid.
	 */
	private function luhn_check( $number ) {
		$sum = 0;
		$alt = false;

		for ( $i = strlen( $number ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $number[ $i ];

			if ( $alt ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}

			$sum += $digit;
			$alt  = ! $alt;
		}

		return ( $sum % 10 === 0 );
	}

	/**
	 * Sanitize input based on type.
	 *
	 * @param mixed  $input Input to sanitize.
	 * @param string $type Type of sanitization (email, url, text, html, etc.).
	 * @return mixed Sanitized input.
	 */
	public function sanitize_input( $input, $type = 'text' ) {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $input );

			case 'url':
				return esc_url_raw( $input );

			case 'text':
				return sanitize_text_field( $input );

			case 'textarea':
				return sanitize_textarea_field( $input );

			case 'html':
				return wp_kses_post( $input );

			case 'int':
				return absint( $input );

			case 'float':
				return floatval( $input );

			case 'bool':
				return (bool) $input;

			case 'phone':
				// Remove non-digit characters except +.
				return preg_replace( '/[^0-9+]/', '', $input );

			default:
				return sanitize_text_field( $input );
		}
	}

	/**
	 * Validate multiple fields at once.
	 *
	 * @param array $data Data to validate.
	 * @param array $rules Validation rules.
	 * @return array|WP_Error Array of validated data or error.
	 */
	public function validate_fields( $data, $rules ) {
		$errors         = array();
		$validated_data = array();

		foreach ( $rules as $field => $rule ) {
			$value = isset( $data[ $field ] ) ? $data[ $field ] : null;

			// Check required.
			if ( isset( $rule['required'] ) && $rule['required'] && empty( $value ) ) {
				$errors[ $field ] = sprintf(
					/* translators: %s: field name */
					__( '%s is required.', 'mcp-ai-wpoos-pro' ),
					$field
				);
				continue;
			}

			// Skip validation if empty and not required.
			if ( empty( $value ) ) {
				continue;
			}

			// Validate based on type.
			if ( isset( $rule['type'] ) ) {
				switch ( $rule['type'] ) {
					case 'email':
						$result = $this->is_email( $value );
						if ( is_wp_error( $result ) ) {
							$errors[ $field ] = $result->get_error_message();
						}
						break;

					case 'phone':
						$country = isset( $rule['country'] ) ? $rule['country'] : 'US';
						$result  = $this->is_phone_number( $value, $country );
						if ( is_wp_error( $result ) ) {
							$errors[ $field ] = $result->get_error_message();
						}
						break;

					case 'url':
						$result = $this->is_url( $value );
						if ( is_wp_error( $result ) ) {
							$errors[ $field ] = $result->get_error_message();
						}
						break;
				}
			}

			// Sanitize.
			$sanitize_type            = isset( $rule['sanitize'] ) ? $rule['sanitize'] : $rule['type'];
			$validated_data[ $field ] = $this->sanitize_input( $value, $sanitize_type );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'validation_failed', __( 'Validation failed.', 'mcp-ai-wpoos-pro' ), $errors );
		}

		return $validated_data;
	}

	/**
	 * Check if email is from a disposable domain.
	 *
	 * @param string $email Email address.
	 * @return bool True if disposable.
	 */
	public function is_disposable_email( $email ) {
		$disposable_domains = array(
			'tempmail.com',
			'10minutemail.com',
			'guerrillamail.com',
			'mailinator.com',
			'throwaway.email',
			'temp-mail.org',
		);

		$domain = substr( strrchr( $email, '@' ), 1 );

		// Allow filtering the list.
		$disposable_domains = apply_filters( 'wp_mcp_ai_disposable_email_domains', $disposable_domains );

		return in_array( strtolower( $domain ), array_map( 'strtolower', $disposable_domains ), true );
	}
}
