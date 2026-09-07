<?php
/**
 * Validator Service (ecosystem port — Wave F2, Pro services slice).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/services/class-wp-mcp-ai-validator-service.php` for
 * the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Validator Service - Comprehensive data validation using validator.js and
 * email-validator NPM packages.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the node_modules availability probe resolves
 * from `NVOOS_CONTENT_GRAPH_PRO_PATH` (standalone the packages don't ship,
 * so `is_available()` reports false and the PHP-native paths serve);
 * strict-types coercion guards in `has_mx_records()` and
 * `is_disposable_email()` (the byte-identical base code relies on PHP
 * coercing `strrchr()`'s false return to an empty string).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
		$validator       = NVOOS_CONTENT_GRAPH_PRO_PATH . 'node_modules/validator';
		$email_validator = NVOOS_CONTENT_GRAPH_PRO_PATH . 'node_modules/email-validator';

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
				__( 'Invalid email address format.', 'nvoos-content-graph-pro' )
			);
		}

		// Allow Node.js-based validation via filter.
		$result = apply_filters(
			'wp_mcp_ai_validator_email',
			false,
			array(
				'email'   => $email,
				'options' => $options,
			)
		);

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
		// Deviation (documented): strict-types coercion guard — the
		// byte-identical base code relies on PHP coercing strrchr()'s
		// false return (no '@' in the input) to an empty string.
		$at     = strrchr( (string) $email, '@' );
		$domain = ( false === $at ) ? '' : substr( $at, 1 );

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
				__( 'Phone number cannot be empty.', 'nvoos-content-graph-pro' )
			);
		}

		// Allow Node.js-based validation via filter (libphonenumber-js).
		$result = apply_filters(
			'wp_mcp_ai_validator_phone',
			false,
			array(
				'phone'   => $phone,
				'country' => $country,
			)
		);

		if ( false === $result ) {
			// Basic PHP validation - just check if it contains digits.
			$digits = preg_replace( '/[^0-9]/', '', $phone );
			if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
				return new WP_Error(
					'invalid_phone',
					__( 'Invalid phone number. Must be 10-15 digits.', 'nvoos-content-graph-pro' )
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
				__( 'Invalid URL format.', 'nvoos-content-graph-pro' )
			);
		}

		// Allow Node.js-based validation via filter.
		$result = apply_filters(
			'wp_mcp_ai_validator_url',
			false,
			array(
				'url'     => $url,
				'options' => $options,
			)
		);

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
		$result = apply_filters(
			'wp_mcp_ai_validator_credit_card',
			false,
			array(
				'card' => $card,
			)
		);

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

		return ( 0 === $sum % 10 );
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
					__( '%s is required.', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'validation_failed', __( 'Validation failed.', 'nvoos-content-graph-pro' ), $errors );
		}

		return $validated_data;
	}

	/**
	 * Check if email is from a disposable domain.
	 *
	 * Uses a basic list of common disposable email domains.
	 * For production use, consider integrating with a disposable email API
	 * or maintaining a more comprehensive list.
	 *
	 * @param string $email Email address.
	 * @return bool True if disposable.
	 */
	public function is_disposable_email( $email ) {
		// Basic list of common disposable domains.
		// This should be expanded or integrated with a service like
		// mailcheck.ai, kickbox.io, or emaillistverify.com for production.
		$disposable_domains = array(
			'tempmail.com',
			'10minutemail.com',
			'guerrillamail.com',
			'mailinator.com',
			'throwaway.email',
			'temp-mail.org',
			'yopmail.com',
			'maildrop.cc',
			'sharklasers.com',
			'grr.la',
		);

		// Deviation (documented): strict-types coercion guard — see
		// has_mx_records().
		$at     = strrchr( (string) $email, '@' );
		$domain = ( false === $at ) ? '' : substr( $at, 1 );

		// Allow filtering the list - recommended to hook into this for comprehensive coverage.
		$disposable_domains = apply_filters( 'wp_mcp_ai_disposable_email_domains', $disposable_domains );

		return in_array( strtolower( $domain ), array_map( 'strtolower', $disposable_domains ), true );
	}
}
