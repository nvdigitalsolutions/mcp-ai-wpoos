<?php
/**
 * VIN Decode Tool
 *
 * Validates and decodes Vehicle Identification Numbers (VINs) using the
 * NHTSA vPIC (Vehicle Product Information Catalog) API. Supports VIN
 * validation via ISO 3779 check-digit, and decoding to retrieve
 * year, make, model, trim, body style, engine, and other vehicle details.
 *
 * @package    WP_MCP_AI
 * @subpackage Pro\Tools
 * @since      2.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VIN Decode Tool class.
 *
 * Provides VIN validation and NHTSA vPIC API decoding for vehicle
 * identification within the NV oOS tool framework.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_VIN_Decode implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * NHTSA vPIC API base URL.
	 *
	 * @var string
	 */
	const VPIC_API_URL = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/';

	/**
	 * Transliteration map for VIN check-digit validation per ISO 3779.
	 *
	 * @var array<string, int>
	 */
	const VIN_TRANSLITERATION = array(
		'A' => 1,
		'B' => 2,
		'C' => 3,
		'D' => 4,
		'E' => 5,
		'F' => 6,
		'G' => 7,
		'H' => 8,
		'J' => 1,
		'K' => 2,
		'L' => 3,
		'M' => 4,
		'N' => 5,
		'P' => 7,
		'R' => 9,
		'S' => 2,
		'T' => 3,
		'U' => 4,
		'V' => 5,
		'W' => 6,
		'X' => 7,
		'Y' => 8,
		'Z' => 9,
	);

	/**
	 * Positional weight factors for VIN check-digit calculation.
	 *
	 * @var int[]
	 */
	const VIN_WEIGHTS = array( 8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2 );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vin_decode';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'VIN Decode', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates and decodes a Vehicle Identification Number (VIN) using the NHTSA vPIC API. Returns year, make, model, trim, body style, engine, and other vehicle details.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'vin'        => array(
					'type'        => 'string',
					'description' => __( 'The 17-character Vehicle Identification Number to decode.', 'mcp-ai-wpoos' ),
					'minLength'   => 17,
					'maxLength'   => 17,
				),
				'model_year' => array(
					'type'        => 'integer',
					'description' => __( 'Optional model year to improve decode accuracy (some VINs are ambiguous without it).', 'mcp-ai-wpoos' ),
					'minimum'     => 1981,
					'maximum'     => 2100,
				),
			),
			'required'             => array( 'vin' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'external-api',
			'network-dependent',
			'rate-limited',
			'cacheable',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition.
	 *
	 * @since 2.2.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'vehicle_estimation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'automotive_mechanic', 'insurance_adjuster', 'body_shop_estimator' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Check whether the tool is available in the current environment.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * Indicate that this tool requires the Pro add-on.
	 *
	 * @since 2.2.0
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Execute the VIN decode tool.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = $context['user_id'] ?? get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use VIN decode.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$vin = isset( $arguments['vin'] ) ? strtoupper( trim( $arguments['vin'] ) ) : '';
		if ( 17 !== strlen( $vin ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_vin',
				__( 'VIN must be exactly 17 characters.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Reject VINs containing I, O, Q (not used in VIN standard).
		if ( preg_match( '/[IOQ]/', $vin ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_vin',
				__( 'VIN contains invalid characters (I, O, or Q are not permitted).', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $vin ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_vin',
				__( 'VIN contains invalid characters. Only alphanumeric characters (excluding I, O, Q) are allowed.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Validate check digit (position 9).
		$check_result = $this->validate_check_digit( $vin );

		// Check transient cache first.
		$cache_key = 'wp_mcp_ai_vin_' . md5( $vin );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$cached['source']      = 'cache';
			$cached['check_digit'] = $check_result;
			return $this->format_success_response(
				/* translators: %s: VIN string */
				sprintf( __( 'Vehicle identified (cached): %s', 'mcp-ai-wpoos' ), $this->format_vehicle_summary( $cached ) ),
				$cached
			);
		}

		// Build NHTSA vPIC API request.
		$api_url = self::VPIC_API_URL . rawurlencode( $vin ) . '?format=json';
		if ( ! empty( $arguments['model_year'] ) ) {
			$api_url .= '&modelyear=' . absint( $arguments['model_year'] );
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_vin_decode_timeout', 15 );

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_vin_api_error',
				/* translators: %s: error message */
				sprintf( __( 'NHTSA vPIC API request failed: %s', 'mcp-ai-wpoos' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_vin_api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'NHTSA vPIC API returned HTTP %d.', 'mcp-ai-wpoos' ), $status_code ),
				array( 'status' => $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['Results'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vin_decode_failed',
				__( 'Failed to decode VIN: unexpected API response format.', 'mcp-ai-wpoos' ),
				array( 'status' => 502 )
			);
		}

		$result = $body['Results'][0];

		// Check for decode errors from NHTSA.
		$error_code = isset( $result['ErrorCode'] ) ? trim( $result['ErrorCode'] ) : '0';
		$error_text = isset( $result['ErrorText'] ) ? trim( $result['ErrorText'] ) : '';

		$vehicle = $this->normalize_vpic_result( $result, $vin, $check_result );

		// Cache successful decodes for 24 hours.
		if ( ! empty( $vehicle['year'] ) && ! empty( $vehicle['make'] ) ) {
			set_transient( $cache_key, $vehicle, DAY_IN_SECONDS );
		}

		// Include NHTSA error information if present.
		if ( '0' !== $error_code ) {
			$vehicle['nhtsa_error_code'] = $error_code;
			$vehicle['nhtsa_error_text'] = $error_text;
		}

		return $this->format_success_response(
			/* translators: %s: vehicle description summary */
			sprintf( __( 'Vehicle identified: %s', 'mcp-ai-wpoos' ), $this->format_vehicle_summary( $vehicle ) ),
			$vehicle
		);
	}

	/**
	 * Validate VIN check digit at position 9 per ISO 3779.
	 *
	 * @since 2.2.0
	 *
	 * @param string $vin 17-character VIN.
	 * @return array Check-digit validation result with 'valid' boolean and 'details' string.
	 */
	public function validate_check_digit( $vin ) {
		$chars = str_split( strtoupper( $vin ) );
		$sum   = 0;

		for ( $i = 0; $i < 17; $i++ ) {
			$char = $chars[ $i ];
			if ( ctype_digit( $char ) ) {
				$value = (int) $char;
			} elseif ( isset( self::VIN_TRANSLITERATION[ $char ] ) ) {
				$value = self::VIN_TRANSLITERATION[ $char ];
			} else {
				return array(
					'valid'   => false,
					'details' => sprintf(
						/* translators: 1: character, 2: position */
						__( 'Invalid character "%1$s" at position %2$d.', 'mcp-ai-wpoos' ),
						$char,
						$i + 1
					),
				);
			}
			$sum += $value * self::VIN_WEIGHTS[ $i ];
		}

		$remainder = $sum % 11;
		$expected  = ( 10 === $remainder ) ? 'X' : (string) $remainder;
		$actual    = $chars[8];
		$is_valid  = ( $expected === $actual );

		return array(
			'valid'   => $is_valid,
			'details' => $is_valid
				? __( 'Check digit is valid.', 'mcp-ai-wpoos' )
				/* translators: 1: expected check digit, 2: actual check digit */
				: sprintf( __( 'Check digit mismatch: expected %1$s, got %2$s.', 'mcp-ai-wpoos' ), $expected, $actual ),
		);
	}

	/**
	 * Normalize NHTSA vPIC response into a clean vehicle descriptor.
	 *
	 * @since 2.2.0
	 *
	 * @param array  $result      Raw vPIC result row.
	 * @param string $vin         The VIN that was decoded.
	 * @param array  $check_digit Check-digit validation result.
	 * @return array Normalized vehicle descriptor.
	 */
	protected function normalize_vpic_result( $result, $vin, $check_digit ) {
		$clean = function ( $key ) use ( $result ) {
			$val = isset( $result[ $key ] ) ? trim( $result[ $key ] ) : '';
			return ( '' !== $val && 'Not Applicable' !== $val ) ? $val : '';
		};

		return array(
			'vin'                 => $vin,
			'year'                => $clean( 'ModelYear' ),
			'make'                => $clean( 'Make' ),
			'model'               => $clean( 'Model' ),
			'trim'                => $clean( 'Trim' ),
			'body_class'          => $clean( 'BodyClass' ),
			'vehicle_type'        => $clean( 'VehicleType' ),
			'drive_type'          => $clean( 'DriveType' ),
			'fuel_type'           => $clean( 'FuelTypePrimary' ),
			'engine_displacement' => $clean( 'DisplacementL' ),
			'engine_cylinders'    => $clean( 'EngineCylinders' ),
			'engine_model'        => $clean( 'EngineModel' ),
			'transmission'        => $clean( 'TransmissionStyle' ),
			'plant_country'       => $clean( 'PlantCountry' ),
			'plant_city'          => $clean( 'PlantCity' ),
			'manufacturer'        => $clean( 'Manufacturer' ),
			'doors'               => $clean( 'Doors' ),
			'gvwr'                => $clean( 'GVWR' ),
			'abs'                 => $clean( 'ABS' ),
			'esc'                 => $clean( 'ESC' ),
			'forward_collision'   => $clean( 'ForwardCollisionWarning' ),
			'lane_departure'      => $clean( 'LaneDepartureWarning' ),
			'adaptive_cruise'     => $clean( 'AdaptiveCruiseControl' ),
			'blind_spot'          => $clean( 'BlindSpotMon' ),
			'parking_assist'      => $clean( 'ParkAssist' ),
			'backup_camera'       => $clean( 'RearVisibilitySystem' ),
			'airbag_locations'    => $clean( 'AirBagLocFront' ),
			'check_digit'         => $check_digit,
			'source'              => 'nhtsa_vpic',
			'decoded_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Format a one-line vehicle summary from vehicle descriptor.
	 *
	 * @since 2.2.0
	 *
	 * @param array $vehicle Vehicle descriptor array.
	 * @return string Human-readable summary.
	 */
	protected function format_vehicle_summary( $vehicle ) {
		$parts = array_filter(
			array(
				$vehicle['year'] ?? '',
				$vehicle['make'] ?? '',
				$vehicle['model'] ?? '',
				$vehicle['trim'] ?? '',
			)
		);
		return implode( ' ', $parts ) ? implode( ' ', $parts ) : __( 'Unknown Vehicle', 'mcp-ai-wpoos' );
	}
}
