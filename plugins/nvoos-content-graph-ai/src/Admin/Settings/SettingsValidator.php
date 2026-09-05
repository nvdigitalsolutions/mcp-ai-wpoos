<?php
/**
 * Settings validator for the Content Graph AI addon.
 *
 * Port of the base plugin's `WP_MCP_AI_Settings_Validator` (Wave D-UI-5):
 * same method surface, error codes, and messages, under the ecosystem
 * textdomain. Static utility consumed by the addon's settings sections.
 *
 * @package NvoosContentGraphAi\Admin\Settings
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates settings input.
 *
 * @since 1.1.0
 */
class SettingsValidator {

	/**
	 * Validate a URL.
	 *
	 * @param mixed $url URL to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_url( $url ) {
		if ( empty( $url ) ) {
			return true; // Empty is okay for optional fields.
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL format.', 'nvoos-content-graph-ai' ) );
		}

		return true;
	}

	/**
	 * Validate an email address.
	 *
	 * @param mixed $email Email to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_email( $email ) {
		if ( empty( $email ) ) {
			return true;
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid email address.', 'nvoos-content-graph-ai' ) );
		}

		return true;
	}

	/**
	 * Validate a required field.
	 *
	 * @param mixed  $value      Value to check.
	 * @param string $field_name Field name for the error message.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_required( $value, $field_name = '' ) {
		if ( empty( $value ) && '0' !== $value && 0 !== $value ) {
			$message = $field_name
				? sprintf(
					/* translators: %s: field name */
					__( '%s is required.', 'nvoos-content-graph-ai' ),
					$field_name
				)
				: __( 'This field is required.', 'nvoos-content-graph-ai' );

			return new \WP_Error( 'required_field', $message );
		}

		return true;
	}

	/**
	 * Validate a numeric value.
	 *
	 * @param mixed    $value Value to check.
	 * @param int|null $min   Minimum value (optional).
	 * @param int|null $max   Maximum value (optional).
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_number( $value, $min = null, $max = null ) {
		if ( empty( $value ) && '0' !== $value && 0 !== $value ) {
			return true;
		}

		if ( ! is_numeric( $value ) ) {
			return new \WP_Error( 'invalid_number', __( 'Value must be a number.', 'nvoos-content-graph-ai' ) );
		}

		if ( null !== $min && $value < $min ) {
			return new \WP_Error(
				'number_too_small',
				sprintf(
					/* translators: %d: minimum value */
					__( 'Value must be at least %d.', 'nvoos-content-graph-ai' ),
					$min
				)
			);
		}

		if ( null !== $max && $value > $max ) {
			return new \WP_Error(
				'number_too_large',
				sprintf(
					/* translators: %d: maximum value */
					__( 'Value must be no more than %d.', 'nvoos-content-graph-ai' ),
					$max
				)
			);
		}

		return true;
	}

	/**
	 * Validate that a value is one of the allowed options.
	 *
	 * @param mixed $value          Value to check.
	 * @param array $allowed_values Allowed values.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_enum( $value, $allowed_values ) {
		if ( empty( $value ) ) {
			return true;
		}

		if ( ! in_array( $value, $allowed_values, true ) ) {
			return new \WP_Error( 'invalid_option', __( 'Invalid option selected.', 'nvoos-content-graph-ai' ) );
		}

		return true;
	}

	/**
	 * Validate an API key format (alphanumeric with dashes/underscores).
	 *
	 * Note: many real provider keys (e.g. OpenAI `sk-…`) contain dots and
	 * are rejected by this conservative check — the AI sections therefore
	 * do not wire it onto live key fields (documented deviation).
	 *
	 * @param mixed $key API key to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_api_key( $key ) {
		if ( empty( $key ) ) {
			return true;
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $key ) ) {
			return new \WP_Error(
				'invalid_api_key',
				__( 'API key contains invalid characters.', 'nvoos-content-graph-ai' )
			);
		}

		return true;
	}

	/**
	 * Validate a JSON string.
	 *
	 * @param mixed $json JSON string to validate.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_json( $json ) {
		if ( empty( $json ) ) {
			return true;
		}

		json_decode( (string) $json );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'invalid_json',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Invalid JSON: %s', 'nvoos-content-graph-ai' ),
					json_last_error_msg()
				)
			);
		}

		return true;
	}
}
