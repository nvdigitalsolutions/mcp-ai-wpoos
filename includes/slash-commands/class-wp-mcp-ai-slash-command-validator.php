<?php
/**
 * Slash Command Validator
 *
 * Provides advanced validation utilities following industry best practices.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command Validator Class
 *
 * Implements schema validation, format checking, and data normalization
 * following 2024 industry standards.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Slash_Command_Validator {

	/**
	 * Validation error codes.
	 *
	 * @var array
	 */
	const ERROR_CODES = array(
		'missing_required'  => 'E001',
		'invalid_format'    => 'E002',
		'invalid_type'      => 'E003',
		'out_of_range'      => 'E004',
		'duplicate_entry'   => 'E005',
		'failed_constraint' => 'E006',
	);

	/**
	 * Validate email format.
	 *
	 * @since 1.3.0
	 *
	 * @param string $email Email address to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_email( $email ) {
		$email = sanitize_email( $email );
		
		if ( empty( $email ) ) {
			return new WP_Error(
				self::ERROR_CODES['missing_required'],
				__( 'Email address is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_format'],
				__( 'Invalid email format.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Validate phone number format.
	 *
	 * Supports international formats.
	 *
	 * @since 1.3.0
	 *
	 * @param string $phone Phone number to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_phone( $phone ) {
		$phone = sanitize_text_field( $phone );
		
		if ( empty( $phone ) ) {
			return new WP_Error(
				self::ERROR_CODES['missing_required'],
				__( 'Phone number is required.', 'mcp-ai-wpoos' )
			);
		}

		// Remove common formatting characters.
		$cleaned = preg_replace( '/[\s\(\)\-\.]/', '', $phone );
		
		// Check for valid international phone number pattern.
		// Supports formats like: +1234567890, 1234567890, (123) 456-7890.
		if ( ! preg_match( '/^\+?[0-9]{7,15}$/', $cleaned ) ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_format'],
				__( 'Invalid phone number format.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Validate URL format.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url URL to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_url( $url ) {
		$url = esc_url_raw( $url );
		
		if ( empty( $url ) ) {
			return new WP_Error(
				self::ERROR_CODES['missing_required'],
				__( 'URL is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_format'],
				__( 'Invalid URL format.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Validate numeric range.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value Value to validate.
	 * @param int   $min Minimum value.
	 * @param int   $max Maximum value.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_range( $value, $min, $max ) {
		if ( ! is_numeric( $value ) ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_type'],
				__( 'Value must be numeric.', 'mcp-ai-wpoos' )
			);
		}

		$value = floatval( $value );

		if ( $value < $min || $value > $max ) {
			return new WP_Error(
				self::ERROR_CODES['out_of_range'],
				sprintf(
					/* translators: 1: minimum value, 2: maximum value */
					__( 'Value must be between %1$d and %2$d.', 'mcp-ai-wpoos' ),
					$min,
					$max
				)
			);
		}

		return true;
	}

	/**
	 * Validate against schema definition.
	 *
	 * @since 1.3.0
	 *
	 * @param array $data Data to validate.
	 * @param array $schema Schema definition.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_schema( $data, $schema ) {
		$errors = array();

		foreach ( $schema as $field => $rules ) {
			$value = isset( $data[ $field ] ) ? $data[ $field ] : null;

			// Check required fields.
			if ( ! empty( $rules['required'] ) && empty( $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: field name */
					__( 'Field "%s" is required.', 'mcp-ai-wpoos' ),
					$field
				);
				continue;
			}

			// Skip validation if field is not required and empty.
			if ( empty( $value ) ) {
				continue;
			}

			// Type validation.
			if ( ! empty( $rules['type'] ) ) {
				$type_check = self::validate_type( $value, $rules['type'] );
				if ( is_wp_error( $type_check ) ) {
					$errors[] = sprintf(
						/* translators: 1: field name, 2: error message */
						__( 'Field "%1$s": %2$s', 'mcp-ai-wpoos' ),
						$field,
						$type_check->get_error_message()
					);
					continue;
				}
			}

			// Format validation.
			if ( ! empty( $rules['format'] ) ) {
				$format_check = self::validate_format( $value, $rules['format'] );
				if ( is_wp_error( $format_check ) ) {
					$errors[] = sprintf(
						/* translators: 1: field name, 2: error message */
						__( 'Field "%1$s": %2$s', 'mcp-ai-wpoos' ),
						$field,
						$format_check->get_error_message()
					);
					continue;
				}
			}

			// Range validation.
			if ( isset( $rules['min'] ) || isset( $rules['max'] ) ) {
				$min = isset( $rules['min'] ) ? $rules['min'] : PHP_INT_MIN;
				$max = isset( $rules['max'] ) ? $rules['max'] : PHP_INT_MAX;
				
				$range_check = self::validate_range( $value, $min, $max );
				if ( is_wp_error( $range_check ) ) {
					$errors[] = sprintf(
						/* translators: 1: field name, 2: error message */
						__( 'Field "%1$s": %2$s', 'mcp-ai-wpoos' ),
						$field,
						$range_check->get_error_message()
					);
				}
			}

			// Enum validation.
			if ( ! empty( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) {
				$errors[] = sprintf(
					/* translators: 1: field name, 2: allowed values */
					__( 'Field "%1$s" must be one of: %2$s', 'mcp-ai-wpoos' ),
					$field,
					implode( ', ', $rules['enum'] )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				self::ERROR_CODES['failed_constraint'],
				implode( ' ', $errors )
			);
		}

		return true;
	}

	/**
	 * Validate data type.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $type Expected type (string, integer, boolean, array, etc.).
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	protected static function validate_type( $value, $type ) {
		$valid = false;

		switch ( $type ) {
			case 'string':
				$valid = is_string( $value );
				break;
			case 'integer':
			case 'int':
				$valid = is_int( $value ) || ctype_digit( $value );
				break;
			case 'number':
			case 'float':
				$valid = is_numeric( $value );
				break;
			case 'boolean':
			case 'bool':
				$valid = is_bool( $value ) || in_array( $value, array( '0', '1', 'true', 'false' ), true );
				break;
			case 'array':
				$valid = is_array( $value );
				break;
			case 'object':
				$valid = is_object( $value ) || is_array( $value );
				break;
			default:
				$valid = true; // Unknown type, skip validation.
		}

		if ( ! $valid ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_type'],
				sprintf(
					/* translators: %s: expected type */
					__( 'Expected type "%s".', 'mcp-ai-wpoos' ),
					$type
				)
			);
		}

		return true;
	}

	/**
	 * Validate format.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $value Value to validate.
	 * @param string $format Format name (email, url, phone, date, etc.).
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	protected static function validate_format( $value, $format ) {
		switch ( $format ) {
			case 'email':
				return self::validate_email( $value );
			case 'url':
				return self::validate_url( $value );
			case 'phone':
				return self::validate_phone( $value );
			case 'date':
				return self::validate_date( $value );
			case 'datetime':
				return self::validate_datetime( $value );
			default:
				return true; // Unknown format, skip validation.
		}
	}

	/**
	 * Validate date format.
	 *
	 * @since 1.3.0
	 *
	 * @param string $date Date string to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	protected static function validate_date( $date ) {
		$date = sanitize_text_field( $date );
		
		if ( empty( $date ) ) {
			return new WP_Error(
				self::ERROR_CODES['missing_required'],
				__( 'Date is required.', 'mcp-ai-wpoos' )
			);
		}

		// Try to parse the date.
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_format'],
				__( 'Invalid date format. Use YYYY-MM-DD or similar.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Validate datetime format.
	 *
	 * @since 1.3.0
	 *
	 * @param string $datetime Datetime string to validate.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	protected static function validate_datetime( $datetime ) {
		$datetime = sanitize_text_field( $datetime );
		
		if ( empty( $datetime ) ) {
			return new WP_Error(
				self::ERROR_CODES['missing_required'],
				__( 'Datetime is required.', 'mcp-ai-wpoos' )
			);
		}

		// Try to parse the datetime.
		$timestamp = strtotime( $datetime );
		if ( false === $timestamp ) {
			return new WP_Error(
				self::ERROR_CODES['invalid_format'],
				__( 'Invalid datetime format. Use YYYY-MM-DD HH:MM:SS or similar.', 'mcp-ai-wpoos' )
			);
		}

		return true;
	}

	/**
	 * Normalize email address.
	 *
	 * @since 1.3.0
	 *
	 * @param string $email Email to normalize.
	 * @return string Normalized email.
	 */
	public static function normalize_email( $email ) {
		return strtolower( trim( sanitize_email( $email ) ) );
	}

	/**
	 * Normalize phone number.
	 *
	 * @since 1.3.0
	 *
	 * @param string $phone Phone to normalize.
	 * @return string Normalized phone.
	 */
	public static function normalize_phone( $phone ) {
		// Remove all non-digit characters except leading +.
		$phone = trim( $phone );
		if ( 0 === strpos( $phone, '+' ) ) {
			return '+' . preg_replace( '/[^0-9]/', '', substr( $phone, 1 ) );
		}
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Normalize name (capitalize first letter of each word).
	 *
	 * @since 1.3.0
	 *
	 * @param string $name Name to normalize.
	 * @return string Normalized name.
	 */
	public static function normalize_name( $name ) {
		return ucwords( strtolower( trim( sanitize_text_field( $name ) ) ) );
	}

	/**
	 * Check for duplicate lead by email.
	 *
	 * @since 1.3.0
	 *
	 * @param string $email Email to check.
	 * @return bool|WP_Error True if unique, WP_Error if duplicate found.
	 */
	public static function check_duplicate_lead( $email ) {
		$email = self::normalize_email( $email );
		
		// Check in stored leads option.
		$leads = get_option( 'wp_mcp_ai_crm_leads', array() );
		
		foreach ( $leads as $lead ) {
			if ( isset( $lead['email'] ) && self::normalize_email( $lead['email'] ) === $email ) {
				return new WP_Error(
					self::ERROR_CODES['duplicate_entry'],
					sprintf(
						/* translators: %s: email address */
						__( 'A lead with email "%s" already exists.', 'mcp-ai-wpoos' ),
						$email
					)
				);
			}
		}

		return true;
	}
}
