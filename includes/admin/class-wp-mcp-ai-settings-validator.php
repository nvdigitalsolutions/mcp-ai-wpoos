<?php
/**
 * Settings Validator for WP oOS
 *
 * Provides validation utilities for settings fields.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Validator' ) ) {
	/**
	 * Validates settings input.
	 */
	class WP_MCP_AI_Settings_Validator {
		/**
		 * Validate a URL.
		 *
		 * @param string $url URL to validate.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_url( $url ) {
			if ( empty( $url ) ) {
				return true; // Empty is okay for optional fields.
			}

			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'invalid_url', __( 'Invalid URL format.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate an email address.
		 *
		 * @param string $email Email to validate.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_email( $email ) {
			if ( empty( $email ) ) {
				return true;
			}

			if ( ! is_email( $email ) ) {
				return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate a required field.
		 *
		 * @param mixed  $value Value to check.
		 * @param string $field_name Field name for error message.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_required( $value, $field_name = '' ) {
			if ( empty( $value ) && '0' !== $value && 0 !== $value ) {
				$message = $field_name
					? sprintf(
						/* translators: %s: field name */
						__( '%s is required.', 'wp-mcp-ai' ),
						$field_name
					)
					: __( 'This field is required.', 'wp-mcp-ai' );

				return new WP_Error( 'required_field', $message );
			}

			return true;
		}

		/**
		 * Validate a numeric value.
		 *
		 * @param mixed $value Value to check.
		 * @param int   $min Minimum value (optional).
		 * @param int   $max Maximum value (optional).
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_number( $value, $min = null, $max = null ) {
			if ( empty( $value ) && '0' !== $value && 0 !== $value ) {
				return true;
			}

			if ( ! is_numeric( $value ) ) {
				return new WP_Error( 'invalid_number', __( 'Value must be a number.', 'wp-mcp-ai' ) );
			}

			if ( null !== $min && $value < $min ) {
				return new WP_Error(
					'number_too_small',
					sprintf(
						/* translators: %d: minimum value */
						__( 'Value must be at least %d.', 'wp-mcp-ai' ),
						$min
					)
				);
			}

			if ( null !== $max && $value > $max ) {
				return new WP_Error(
					'number_too_large',
					sprintf(
						/* translators: %d: maximum value */
						__( 'Value must be no more than %d.', 'wp-mcp-ai' ),
						$max
					)
				);
			}

			return true;
		}

		/**
		 * Validate that a value is one of allowed options.
		 *
		 * @param mixed $value Value to check.
		 * @param array $allowed_values Allowed values.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_enum( $value, $allowed_values ) {
			if ( empty( $value ) ) {
				return true;
			}

			if ( ! in_array( $value, $allowed_values, true ) ) {
				return new WP_Error( 'invalid_option', __( 'Invalid option selected.', 'wp-mcp-ai' ) );
			}

			return true;
		}

		/**
		 * Validate an API key format (alphanumeric with dashes/underscores).
		 *
		 * @param string $key API key to validate.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_api_key( $key ) {
			if ( empty( $key ) ) {
				return true;
			}

			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
				return new WP_Error(
					'invalid_api_key',
					__( 'API key contains invalid characters.', 'wp-mcp-ai' )
				);
			}

			return true;
		}

		/**
		 * Validate JSON string.
		 *
		 * @param string $json JSON string to validate.
		 * @return bool|WP_Error True if valid, WP_Error otherwise.
		 */
		public static function validate_json( $json ) {
			if ( empty( $json ) ) {
				return true;
			}

			json_decode( $json );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'invalid_json',
					sprintf(
						/* translators: %s: JSON error message */
						__( 'Invalid JSON: %s', 'wp-mcp-ai' ),
						json_last_error_msg()
					)
				);
			}

			return true;
		}
	}
}
