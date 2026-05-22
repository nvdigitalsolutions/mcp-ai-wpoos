<?php
/**
 * Vehicle Repair Estimate Tool
 *
 * Orchestrates a multi-step pipeline to produce a structured vehicle
 * repair estimate from photos:
 *  1. Image intake and coverage validation
 *  2. VIN capture (OCR) and decode
 *  3. AI-powered damage and part detection via vision models
 *  4. Price-sheet line-item mapping
 *  5. Estimate generation with confidence scoring
 *
 * Leverages existing tools: extract_image_text (OCR), analyze_image (vision),
 * vision_object_localization (Google Cloud Vision), and the new vin_decode tool.
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
 * Vehicle Repair Estimate Tool class.
 *
 * Produces a structured repair estimate from vehicle damage photos,
 * an optional VIN, and a price-sheet attachment.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_Vehicle_Repair_Estimate implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Required exterior views for a complete estimate.
	 *
	 * @var string[]
	 */
	const REQUIRED_VIEWS = array(
		'front',
		'rear',
		'left_side',
		'right_side',
	);

	/**
	 * Recommended additional views for a thorough estimate.
	 *
	 * @var string[]
	 */
	const RECOMMENDED_VIEWS = array(
		'front_left_corner',
		'front_right_corner',
		'rear_left_corner',
		'rear_right_corner',
	);

	/**
	 * Supported damage types for classification.
	 *
	 * @var string[]
	 */
	const DAMAGE_TYPES = array(
		'scratch',
		'dent',
		'crack',
		'broken',
		'deformation',
		'misalignment',
		'missing',
		'paint_damage',
		'corrosion',
	);

	/**
	 * Supported repair operations.
	 *
	 * @var string[]
	 */
	const REPAIR_OPERATIONS = array(
		'replace',
		'repair',
		'refinish',
		'blend',
		'remove_and_install',
		'remove_and_replace',
		'calibration',
		'alignment',
	);

	/**
	 * Confidence thresholds.
	 *
	 * @var float[]
	 */
	const CONFIDENCE_THRESHOLDS = array(
		'vehicle_id'  => 0.8,
		'part_detect' => 0.7,
		'damage_type' => 0.7,
		'coverage'    => 0.9,
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vehicle_repair_estimate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vehicle Repair Estimate', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a structured vehicle repair estimate from damage photos. Identifies the vehicle via VIN or visual recognition, detects damaged parts, classifies damage types and severity, and maps findings to price-sheet line items. Returns a detailed estimate with confidence scores and assumptions.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_attachment_ids'      => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress attachment IDs for vehicle damage photos. Include front, rear, left, right, and close-up images.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
					'maxItems'    => 30,
				),
				'price_sheet_attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID for the price sheet (CSV, XLSX, or JSON). Columns: line_item_code, description, vehicle_applicability, operation, unit_cost, labor_hours, labor_rate_group, notes.', 'mcp-ai-wpoos' ),
				),
				'vin'                       => array(
					'type'        => 'string',
					'description' => __( 'Vehicle Identification Number (17 characters). If not provided, the tool will attempt VIN OCR from uploaded photos.', 'mcp-ai-wpoos' ),
					'minLength'   => 17,
					'maxLength'   => 17,
				),
				'vin_image_attachment_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID for a VIN photo (windshield or door jamb label). Prioritized for VIN OCR.', 'mcp-ai-wpoos' ),
				),
				'vehicle_overrides'         => array(
					'type'        => 'object',
					'description' => __( 'Manual vehicle identification when VIN is unavailable.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'year'  => array(
							'type'        => 'integer',
							'description' => __( 'Model year.', 'mcp-ai-wpoos' ),
						),
						'make'  => array(
							'type'        => 'string',
							'description' => __( 'Vehicle make (e.g., Toyota, Ford).', 'mcp-ai-wpoos' ),
						),
						'model' => array(
							'type'        => 'string',
							'description' => __( 'Vehicle model (e.g., Camry, F-150).', 'mcp-ai-wpoos' ),
						),
						'trim'  => array(
							'type'        => 'string',
							'description' => __( 'Vehicle trim level.', 'mcp-ai-wpoos' ),
						),
					),
				),
				'labor_rate_profile'        => array(
					'type'        => 'object',
					'description' => __( 'Shop-specific labor rate overrides.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'body_rate'       => array(
							'type'        => 'number',
							'description' => __( 'Body labor rate per hour in dollars.', 'mcp-ai-wpoos' ),
						),
						'paint_rate'      => array(
							'type'        => 'number',
							'description' => __( 'Paint labor rate per hour in dollars.', 'mcp-ai-wpoos' ),
						),
						'mechanical_rate' => array(
							'type'        => 'number',
							'description' => __( 'Mechanical labor rate per hour in dollars.', 'mcp-ai-wpoos' ),
						),
						'frame_rate'      => array(
							'type'        => 'number',
							'description' => __( 'Frame/structural labor rate per hour in dollars.', 'mcp-ai-wpoos' ),
						),
					),
				),
				'output_detail_level'       => array(
					'type'        => 'string',
					'description' => __( 'Level of detail in the output.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'summary', 'full' ),
					'default'     => 'full',
				),
			),
			'required'             => array( 'image_attachment_ids' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'requires-vision-model',
			'external-api',
			'network-dependent',
			'long-running',
			'may-timeout',
			'rate-limited',
			'consumes-tokens',
			'non-deterministic',
			'large-response',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'rate_limits'  => array(
				'requests_per_minute' => 5,
				'requests_per_hour'   => 30,
				'max_concurrent'      => 2,
			),
			'timeout'      => array(
				'single_image'  => 30,
				'full_estimate' => 300,
			),
			'dependencies' => array(
				'required' => array( 'extract_image_text', 'analyze_image' ),
				'optional' => array( 'vin_decode', 'vision_object_localization' ),
			),
			'cache'        => array(
				'ttl'    => 1800,
				'key_by' => array( 'image_attachment_ids', 'vin' ),
			),
			'retry'        => array(
				'strategy'    => 'exponential_backoff',
				'max_retries' => 2,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition (toolkit metadata).
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
			'risk_level'            => 'medium',
		);
	}

	/**
	 * Whether this tool is available.
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
	 * Execute the vehicle repair estimate pipeline.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Structured estimate or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// --- Permission check ---
		$user_id = $context['user_id'] ?? get_current_user_id();

		/**
		 * Filter the required capability for the vehicle repair estimate tool.
		 *
		 * @since 2.2.0
		 *
		 * @param string $capability WordPress capability required.
		 */
		$required_cap = apply_filters( 'wp_mcp_ai_vehicle_estimate_required_capability', 'upload_files' );

		if ( ! $user_id || ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate vehicle repair estimates.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// --- Input validation ---
		$image_ids = $arguments['image_attachment_ids'] ?? array();
		if ( empty( $image_ids ) || ! is_array( $image_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'At least one vehicle image attachment ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize attachment IDs.
		$image_ids = array_map( 'absint', $image_ids );
		$image_ids = array_filter( $image_ids );
		if ( empty( $image_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_input',
				__( 'No valid image attachment IDs provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Validate attachments exist and are images.
		$valid_images   = array();
		$invalid_images = array();
		foreach ( $image_ids as $att_id ) {
			if ( ! wp_attachment_is_image( $att_id ) ) {
				$invalid_images[] = $att_id;
				continue;
			}
			$url = wp_get_attachment_url( $att_id );
			if ( ! $url ) {
				$invalid_images[] = $att_id;
				continue;
			}
			$valid_images[] = array(
				'attachment_id' => $att_id,
				'url'           => $url,
				'filename'      => basename( get_attached_file( $att_id ) ),
			);
		}

		if ( empty( $valid_images ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_input',
				__( 'No valid image attachments found. Please upload vehicle photos.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$estimate_id = wp_generate_uuid4();
		$warnings    = array();

		if ( ! empty( $invalid_images ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated list of invalid attachment IDs */
				__( 'Skipped invalid/non-image attachments: %s', 'mcp-ai-wpoos' ),
				implode( ', ', $invalid_images )
			);
		}

		// --- Step 1: Vehicle identification ---
		$vehicle = $this->identify_vehicle( $arguments, $valid_images, $context );
		if ( is_wp_error( $vehicle ) ) {
			// Vehicle ID failed but we can still proceed with limited info.
			$vehicle    = $this->build_unknown_vehicle_descriptor( $arguments );
			$warnings[] = $vehicle['warning'] ?? __( 'Could not identify vehicle. Estimate may be less accurate.', 'mcp-ai-wpoos' );
		}

		// --- Step 2: Damage analysis ---
		$findings = $this->analyze_damage( $valid_images, $vehicle, $context );
		if ( is_wp_error( $findings ) ) {
			return $findings;
		}

		// --- Step 3: Coverage assessment ---
		$coverage = $this->assess_coverage( $valid_images, $findings );

		// --- Step 4: Price-sheet mapping ---
		$price_sheet_id = isset( $arguments['price_sheet_attachment_id'] ) ? absint( $arguments['price_sheet_attachment_id'] ) : 0;
		$labor_rates    = $arguments['labor_rate_profile'] ?? $this->get_default_labor_rates();
		$line_items     = $this->map_to_line_items( $findings, $vehicle, $price_sheet_id, $labor_rates );

		// --- Step 5: Calculate totals ---
		$totals = $this->calculate_totals( $line_items, $labor_rates );

		// --- Step 6: Build estimate ---
		$detail_level = $arguments['output_detail_level'] ?? 'full';

		$estimate = array(
			'estimate_id'        => $estimate_id,
			'vehicle_descriptor' => $this->sanitize_vehicle_for_output( $vehicle ),
			'findings'           => $findings,
			'line_items'         => $line_items,
			'totals'             => $totals,
			'coverage'           => $coverage,
			'uncertainty'        => $this->calculate_uncertainty( $vehicle, $findings, $coverage ),
			'assumptions'        => $this->get_estimate_assumptions( $vehicle, $findings, $price_sheet_id ),
			'metadata'           => array(
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'image_count'  => count( $valid_images ),
				'detail_level' => $detail_level,
				'tool_version' => '2.2.0',
			),
		);

		if ( ! empty( $warnings ) ) {
			$estimate['warnings'] = $warnings;
		}

		// Summary mode strips detailed findings.
		if ( 'summary' === $detail_level ) {
			unset( $estimate['findings'] );
			$estimate['line_items'] = array_map(
				function ( $item ) {
					unset( $item['evidence'] );
					return $item;
				},
				$estimate['line_items']
			);
		}

		// Build user-facing message.
		$message = $this->build_estimate_message( $estimate );

		return $this->format_success_response( $message, $estimate );
	}

	/**
	 * Identify the vehicle through VIN decode, OCR, or manual overrides.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments   Tool arguments.
	 * @param array $images      Validated image data.
	 * @param array $context     Execution context.
	 * @return array|WP_Error    Vehicle descriptor or error.
	 */
	protected function identify_vehicle( $arguments, $images, $context ) {
		// Path 1: Direct VIN provided.
		if ( ! empty( $arguments['vin'] ) ) {
			$vin_result = $this->decode_vin( $arguments['vin'], $context );
			if ( ! is_wp_error( $vin_result ) ) {
				return $vin_result;
			}
		}

		// Path 2: VIN image provided — run OCR.
		if ( ! empty( $arguments['vin_image_attachment_id'] ) ) {
			$vin_from_image = $this->extract_vin_from_image( absint( $arguments['vin_image_attachment_id'] ), $context );
			if ( ! is_wp_error( $vin_from_image ) && ! empty( $vin_from_image ) ) {
				$vin_result = $this->decode_vin( $vin_from_image, $context );
				if ( ! is_wp_error( $vin_result ) ) {
					return $vin_result;
				}
			}
		}

		// Path 3: Manual vehicle overrides.
		if ( ! empty( $arguments['vehicle_overrides'] ) ) {
			$overrides = $arguments['vehicle_overrides'];
			if ( ! empty( $overrides['make'] ) && ! empty( $overrides['model'] ) ) {
				return array(
					'vin'        => '',
					'year'       => isset( $overrides['year'] ) ? absint( $overrides['year'] ) : '',
					'make'       => sanitize_text_field( $overrides['make'] ),
					'model'      => sanitize_text_field( $overrides['model'] ),
					'trim'       => isset( $overrides['trim'] ) ? sanitize_text_field( $overrides['trim'] ) : '',
					'body_class' => '',
					'source'     => 'manual_override',
					'confidence' => 0.95,
				);
			}
		}

		// Path 4: Attempt visual recognition via LLM vision.
		$visual_id = $this->visual_vehicle_recognition( $images, $context );
		if ( ! is_wp_error( $visual_id ) ) {
			return $visual_id;
		}

		return new WP_Error(
			'wp_mcp_ai_vehicle_id_failed',
			__( 'Could not identify vehicle. Please provide a VIN, a VIN photo, or manual vehicle details (year/make/model).', 'mcp-ai-wpoos' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Decode a VIN string using the vin_decode tool.
	 *
	 * @since 2.2.0
	 *
	 * @param string $vin     17-character VIN.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Vehicle descriptor or error.
	 */
	protected function decode_vin( $vin, $context ) {
		$vin = strtoupper( trim( $vin ) );
		if ( 17 !== strlen( $vin ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_vin', __( 'Invalid VIN length.', 'mcp-ai-wpoos' ) );
		}

		// Try using the registered vin_decode tool via the registry.
		if ( function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			$registry = wp_mcp_ai_get_tool_registry();
			if ( $registry && $registry->is_tool_registered( 'vin_decode' ) ) {
				$result = $registry->execute_tool(
					'vin_decode',
					array( 'vin' => $vin ),
					$context
				);
				if ( ! is_wp_error( $result ) && ! empty( $result['data'] ) ) {
					$data               = $result['data'];
					$data['confidence'] = 0.98;
					return $data;
				} elseif ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['make'] ) ) {
					$result['confidence'] = 0.98;
					return $result;
				}
			}
		}

		// Fallback: direct NHTSA call.
		$api_url  = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/' . rawurlencode( $vin ) . '?format=json';
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'wp_mcp_ai_vin_api_error', __( 'VIN decode API request failed.', 'mcp-ai-wpoos' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['Results'][0] ) ) {
			return new WP_Error( 'wp_mcp_ai_vin_decode_failed', __( 'VIN decode returned no results.', 'mcp-ai-wpoos' ) );
		}

		$r     = $body['Results'][0];
		$clean = function ( $key ) use ( $r ) {
			$val = isset( $r[ $key ] ) ? trim( $r[ $key ] ) : '';
			return ( '' !== $val && 'Not Applicable' !== $val ) ? $val : '';
		};

		return array(
			'vin'        => $vin,
			'year'       => $clean( 'ModelYear' ),
			'make'       => $clean( 'Make' ),
			'model'      => $clean( 'Model' ),
			'trim'       => $clean( 'Trim' ),
			'body_class' => $clean( 'BodyClass' ),
			'source'     => 'nhtsa_vpic',
			'confidence' => 0.98,
		);
	}

	/**
	 * Extract a VIN string from an image using OCR.
	 *
	 * @since 2.2.0
	 *
	 * @param int   $attachment_id Image attachment ID.
	 * @param array $context       Execution context.
	 * @return string|WP_Error     Extracted VIN or error.
	 */
	protected function extract_vin_from_image( $attachment_id, $context ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry || ! $registry->is_tool_registered( 'extract_image_text' ) ) {
			return new WP_Error( 'wp_mcp_ai_ocr_unavailable', __( 'OCR tool not available.', 'mcp-ai-wpoos' ) );
		}

		$ocr_result = $registry->execute_tool(
			'extract_image_text',
			array(
				'attachment_id'   => $attachment_id,
				'preserve_layout' => true,
			),
			$context
		);

		if ( is_wp_error( $ocr_result ) ) {
			return $ocr_result;
		}

		$text = '';
		if ( is_array( $ocr_result ) && isset( $ocr_result['text'] ) ) {
			$text = $ocr_result['text'];
		} elseif ( is_array( $ocr_result ) && isset( $ocr_result['data']['text'] ) ) {
			$text = $ocr_result['data']['text'];
		}

		// Extract 17-character VIN pattern from OCR text.
		if ( preg_match( '/\b([A-HJ-NPR-Z0-9]{17})\b/', strtoupper( $text ), $matches ) ) {
			return $matches[1];
		}

		return new WP_Error(
			'wp_mcp_ai_vin_not_found',
			__( 'Could not extract a valid VIN from the image. Please provide a clearer VIN photo or enter the VIN manually.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Attempt visual vehicle recognition using an LLM vision model.
	 *
	 * @since 2.2.0
	 *
	 * @param array $images  Validated image data.
	 * @param array $context Execution context.
	 * @return array|WP_Error Vehicle descriptor or error.
	 */
	protected function visual_vehicle_recognition( $images, $context ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry || ! $registry->is_tool_registered( 'analyze_image' ) ) {
			return new WP_Error( 'wp_mcp_ai_vision_unavailable', __( 'Image analysis tool not available.', 'mcp-ai-wpoos' ) );
		}

		// Use first full-vehicle image for identification.
		$image = $images[0];

		$result = $registry->execute_tool(
			'analyze_image',
			array(
				'attachment_id' => $image['attachment_id'],
				'prompt'        => 'Identify this vehicle. Return ONLY a JSON object with keys: year (integer or null), make (string), model (string), body_style (string: sedan/suv/pickup/coupe/van/hatchback/wagon/convertible), confidence (float 0-1). Do not include any other text.',
				'max_tokens'    => 200,
			),
			$context
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = '';
		if ( is_array( $result ) && isset( $result['text'] ) ) {
			$text = $result['text'];
		} elseif ( is_array( $result ) && isset( $result['data']['text'] ) ) {
			$text = $result['data']['text'];
		} elseif ( is_string( $result ) ) {
			$text = $result;
		}

		$parsed = $this->parse_json_from_text( $text );
		if ( empty( $parsed ) || empty( $parsed['make'] ) ) {
			return new WP_Error( 'wp_mcp_ai_visual_id_failed', __( 'Visual vehicle recognition did not return usable results.', 'mcp-ai-wpoos' ) );
		}

		$confidence = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.5;

		return array(
			'vin'        => '',
			'year'       => isset( $parsed['year'] ) ? absint( $parsed['year'] ) : '',
			'make'       => sanitize_text_field( $parsed['make'] ),
			'model'      => sanitize_text_field( $parsed['model'] ?? '' ),
			'trim'       => '',
			'body_class' => sanitize_text_field( $parsed['body_style'] ?? '' ),
			'source'     => 'visual_recognition',
			'confidence' => $confidence,
		);
	}

	/**
	 * Analyze damage in vehicle images using LLM vision.
	 *
	 * @since 2.2.0
	 *
	 * @param array $images  Validated image data.
	 * @param array $vehicle Vehicle descriptor.
	 * @param array $context Execution context.
	 * @return array|WP_Error Array of findings or error.
	 */
	protected function analyze_damage( $images, $vehicle, $context ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_registry', __( 'Tool registry not available.', 'mcp-ai-wpoos' ) );
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry || ! $registry->is_tool_registered( 'analyze_image' ) ) {
			return new WP_Error( 'wp_mcp_ai_vision_unavailable', __( 'Image analysis tool not available for damage detection.', 'mcp-ai-wpoos' ) );
		}

		$vehicle_desc = trim(
			sprintf(
				'%s %s %s %s',
				$vehicle['year'] ?? '',
				$vehicle['make'] ?? '',
				$vehicle['model'] ?? '',
				$vehicle['trim'] ?? ''
			)
		);

		$prompt = sprintf(
			/* translators: %s: vehicle description */
			'Analyze this vehicle image for body damage. Vehicle: %s. '
			. 'Return ONLY a JSON object with these keys: '
			. '"view" (string: front/rear/left_side/right_side/front_left_corner/front_right_corner/rear_left_corner/rear_right_corner/closeup/vin/other), '
			. '"damages" (array of objects, each with: '
			. '"part" (string: the damaged vehicle part name like bumper_cover_front, headlamp_right, door_front_left, fender_front_right, hood, windshield, mirror_left, quarter_panel_rear_right, tail_lamp_left, trunk_lid, roof, grille, etc), '
			. '"damage_type" (string: scratch/dent/crack/broken/deformation/misalignment/missing/paint_damage/corrosion), '
			. '"severity" (string: light/moderate/heavy), '
			. '"location" (string: left_front/left_rear/right_front/right_rear/center/top/bottom), '
			. '"confidence" (float 0-1), '
			. '"description" (string: brief description of the specific damage)), '
			. '"has_damage" (boolean). Do not include any other text.',
			$vehicle_desc ?: 'unknown'
		);

		$findings = array();

		foreach ( $images as $image ) {
			$result = $registry->execute_tool(
				'analyze_image',
				array(
					'attachment_id' => $image['attachment_id'],
					'prompt'        => $prompt,
					'max_tokens'    => 1000,
				),
				$context
			);

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$text = '';
			if ( is_array( $result ) && isset( $result['text'] ) ) {
				$text = $result['text'];
			} elseif ( is_array( $result ) && isset( $result['data']['text'] ) ) {
				$text = $result['data']['text'];
			} elseif ( is_string( $result ) ) {
				$text = $result;
			}

			$parsed = $this->parse_json_from_text( $text );
			if ( ! is_array( $parsed ) ) {
				continue;
			}

			$view    = sanitize_text_field( $parsed['view'] ?? 'other' );
			$damages = $parsed['damages'] ?? array();

			if ( ! empty( $parsed['has_damage'] ) && is_array( $damages ) ) {
				foreach ( $damages as $damage ) {
					$findings[] = array(
						'image_attachment_id' => $image['attachment_id'],
						'view'                => $view,
						'part'                => sanitize_text_field( $damage['part'] ?? 'unknown' ),
						'damage_type'         => sanitize_text_field( $damage['damage_type'] ?? 'unknown' ),
						'severity'            => sanitize_text_field( $damage['severity'] ?? 'moderate' ),
						'location'            => sanitize_text_field( $damage['location'] ?? '' ),
						'confidence'          => isset( $damage['confidence'] ) ? (float) $damage['confidence'] : 0.5,
						'description'         => sanitize_text_field( $damage['description'] ?? '' ),
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * Assess photo coverage against required views.
	 *
	 * @since 2.2.0
	 *
	 * @param array $images   Validated image data.
	 * @param array $findings Damage findings.
	 * @return array Coverage assessment with score and missing views.
	 */
	protected function assess_coverage( $images, $findings ) {
		$views_present = array();
		foreach ( $findings as $f ) {
			$views_present[ $f['view'] ] = true;
		}

		$required_missing    = array();
		$recommended_missing = array();

		foreach ( self::REQUIRED_VIEWS as $view ) {
			if ( ! isset( $views_present[ $view ] ) ) {
				$required_missing[] = $view;
			}
		}

		foreach ( self::RECOMMENDED_VIEWS as $view ) {
			if ( ! isset( $views_present[ $view ] ) ) {
				$recommended_missing[] = $view;
			}
		}

		$total_expected = count( self::REQUIRED_VIEWS ) + count( self::RECOMMENDED_VIEWS );
		$total_present  = $total_expected - count( $required_missing ) - count( $recommended_missing );
		$score          = $total_expected > 0 ? round( $total_present / $total_expected, 2 ) : 0;

		$messages = array();
		if ( ! empty( $required_missing ) ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated list of missing views */
				__( 'Missing required views: %s. Please add these photos for a more reliable estimate.', 'mcp-ai-wpoos' ),
				implode(
					', ',
					array_map(
						function ( $v ) {
							return str_replace( '_', ' ', $v ); },
						$required_missing
					)
				)
			);
		}
		if ( ! empty( $recommended_missing ) ) {
			$messages[] = sprintf(
				/* translators: %s: comma-separated list of missing views */
				__( 'Missing recommended corner views: %s.', 'mcp-ai-wpoos' ),
				implode(
					', ',
					array_map(
						function ( $v ) {
							return str_replace( '_', ' ', $v ); },
						$recommended_missing
					)
				)
			);
		}

		return array(
			'score'               => $score,
			'views_present'       => array_keys( $views_present ),
			'required_missing'    => $required_missing,
			'recommended_missing' => $recommended_missing,
			'image_count'         => count( $images ),
			'messages'            => $messages,
		);
	}

	/**
	 * Map damage findings to price-sheet line items.
	 *
	 * @since 2.2.0
	 *
	 * @param array $findings      Damage findings.
	 * @param array $vehicle       Vehicle descriptor.
	 * @param int   $price_sheet_id Price-sheet attachment ID (0 if none).
	 * @param array $labor_rates   Labor rate profile.
	 * @return array Array of estimate line items.
	 */
	protected function map_to_line_items( $findings, $vehicle, $price_sheet_id, $labor_rates ) {
		$price_sheet = array();
		if ( $price_sheet_id > 0 ) {
			$price_sheet = $this->parse_price_sheet( $price_sheet_id );
		}

		$line_items = array();
		$seen_parts = array();

		foreach ( $findings as $finding ) {
			$part        = $finding['part'];
			$damage_type = $finding['damage_type'];
			$severity    = $finding['severity'];

			// Deduplicate: same part + same damage type only counted once.
			$dedup_key = $part . '|' . $damage_type;
			if ( isset( $seen_parts[ $dedup_key ] ) ) {
				// Keep higher confidence.
				$existing_idx = $seen_parts[ $dedup_key ];
				if ( $finding['confidence'] > ( $line_items[ $existing_idx ]['confidence'] ?? 0 ) ) {
					$line_items[ $existing_idx ]['evidence'][] = array(
						'image_attachment_id' => $finding['image_attachment_id'],
						'view'                => $finding['view'],
					);
				}
				continue;
			}

			// Determine operation based on severity and damage type.
			$operations = $this->determine_operations( $part, $damage_type, $severity, $vehicle );

			foreach ( $operations as $operation ) {
				$line_item = $this->build_line_item(
					$part,
					$operation,
					$damage_type,
					$severity,
					$finding,
					$vehicle,
					$price_sheet,
					$labor_rates
				);

				$idx                      = count( $line_items );
				$line_items[]             = $line_item;
				$seen_parts[ $dedup_key ] = $idx;
			}
		}

		return $line_items;
	}

	/**
	 * Determine which repair operations are needed for a damage finding.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part        Part identifier.
	 * @param string $damage_type Damage type.
	 * @param string $severity    Severity level.
	 * @param array  $vehicle     Vehicle descriptor.
	 * @return string[] Array of operation identifiers.
	 */
	protected function determine_operations( $part, $damage_type, $severity, $vehicle ) {
		$operations = array();

		// Broken/missing items always need replacement.
		if ( in_array( $damage_type, array( 'broken', 'missing' ), true ) ) {
			$operations[] = 'replace';
		} elseif ( 'heavy' === $severity || 'deformation' === $damage_type ) {
			$operations[] = 'replace';
		} elseif ( in_array( $damage_type, array( 'dent', 'scratch', 'paint_damage' ), true ) ) {
			if ( 'light' === $severity ) {
				$operations[] = 'repair';
			} else {
				$operations[] = 'repair';
			}
		} elseif ( 'crack' === $damage_type ) {
			// Cracks in glass or structural parts generally mean replacement.
			if ( $this->is_glass_part( $part ) || $this->is_structural_part( $part ) ) {
				$operations[] = 'replace';
			} else {
				$operations[] = 'repair';
			}
		} else {
			$operations[] = 'repair';
		}

		// Add refinish for exterior panel operations.
		if ( $this->is_exterior_panel( $part ) && 'replace' === ( $operations[0] ?? '' ) ) {
			$operations[] = 'refinish';
		} elseif ( $this->is_exterior_panel( $part ) && in_array( $damage_type, array( 'scratch', 'paint_damage', 'dent' ), true ) ) {
			$operations[] = 'refinish';
		}

		// Windshield replacement may need ADAS calibration.
		if ( 'windshield' === $part && in_array( 'replace', $operations, true ) ) {
			if ( $this->vehicle_has_adas( $vehicle ) ) {
				$operations[] = 'calibration';
			}
		}

		// Headlamp/tail lamp replacement may need aiming.
		if ( $this->is_lamp_part( $part ) && in_array( 'replace', $operations, true ) ) {
			$operations[] = 'alignment';
		}

		return array_unique( $operations );
	}

	/**
	 * Build a single estimate line item.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part        Part identifier.
	 * @param string $operation   Operation type.
	 * @param string $damage_type Damage type.
	 * @param string $severity    Severity level.
	 * @param array  $finding     Original finding data.
	 * @param array  $vehicle     Vehicle descriptor.
	 * @param array  $price_sheet Parsed price-sheet rows.
	 * @param array  $labor_rates Labor rate profile.
	 * @return array Line item data.
	 */
	protected function build_line_item( $part, $operation, $damage_type, $severity, $finding, $vehicle, $price_sheet, $labor_rates ) {
		$matched_row = $this->match_price_sheet_row( $part, $operation, $vehicle, $price_sheet );

		// Use price-sheet values if matched, otherwise use heuristic estimates.
		if ( ! empty( $matched_row ) ) {
			$unit_cost   = (float) ( $matched_row['unit_cost'] ?? 0 );
			$labor_hours = (float) ( $matched_row['labor_hours'] ?? 0 );
			$rate_group  = $matched_row['labor_rate_group'] ?? 'body';
			$code        = $matched_row['line_item_code'] ?? '';
			$description = $matched_row['description'] ?? '';
			$from_sheet  = true;
		} else {
			$estimates   = $this->heuristic_cost_estimate( $part, $operation, $severity );
			$unit_cost   = $estimates['unit_cost'];
			$labor_hours = $estimates['labor_hours'];
			$rate_group  = $estimates['rate_group'];
			$code        = strtoupper( str_replace( ' ', '_', $part ) ) . '_' . strtoupper( $operation );
			$description = sprintf(
				/* translators: 1: operation type, 2: part name */
				__( '%1$s %2$s', 'mcp-ai-wpoos' ),
				ucfirst( str_replace( '_', ' ', $operation ) ),
				str_replace( '_', ' ', $part )
			);
			$from_sheet = false;
		}

		$labor_rate    = $this->get_rate_for_group( $rate_group, $labor_rates );
		$labor_cost    = round( $labor_hours * $labor_rate, 2 );
		$extended_cost = round( $unit_cost + $labor_cost, 2 );

		return array(
			'line_item_code'   => $code,
			'description'      => $description,
			'part'             => $part,
			'operation'        => $operation,
			'damage_type'      => $damage_type,
			'severity'         => $severity,
			'unit_cost'        => $unit_cost,
			'labor_hours'      => $labor_hours,
			'labor_rate_group' => $rate_group,
			'labor_rate'       => $labor_rate,
			'labor_cost'       => $labor_cost,
			'extended_cost'    => $extended_cost,
			'from_price_sheet' => $from_sheet,
			'confidence'       => $finding['confidence'],
			'evidence'         => array(
				array(
					'image_attachment_id' => $finding['image_attachment_id'],
					'view'                => $finding['view'],
				),
			),
		);
	}

	/**
	 * Match a price-sheet row by part and operation.
	 *
	 * Uses normalized token matching for fuzzy lookups.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part        Part identifier.
	 * @param string $operation   Operation type.
	 * @param array  $vehicle     Vehicle descriptor.
	 * @param array  $price_sheet Parsed price-sheet rows.
	 * @return array|null Matched row or null.
	 */
	protected function match_price_sheet_row( $part, $operation, $vehicle, $price_sheet ) {
		if ( empty( $price_sheet ) ) {
			return null;
		}

		$part_tokens = $this->tokenize( $part );
		$best_match  = null;
		$best_score  = 0;
		$threshold   = 0.4;

		foreach ( $price_sheet as $row ) {
			// Filter by operation if specified.
			$row_op = strtolower( trim( $row['operation'] ?? '' ) );
			if ( '' !== $row_op && false === strpos( $row_op, str_replace( '_', ' ', $operation ) ) ) {
				continue;
			}

			// Filter by vehicle applicability if specified.
			$applicability = strtolower( trim( $row['vehicle_applicability'] ?? '' ) );
			if ( '' !== $applicability ) {
				$year = strtolower( $vehicle['year'] ?? '' );
				$make = strtolower( $vehicle['make'] ?? '' );
				if ( '' !== $year && false === strpos( $applicability, $year ) ) {
					continue;
				}
				if ( '' !== $make && false === strpos( $applicability, $make ) ) {
					continue;
				}
			}

			// Token similarity on description.
			$desc_tokens = $this->tokenize( $row['description'] ?? '' );
			$score       = $this->jaccard_similarity( $part_tokens, $desc_tokens );
			if ( $score > $best_score && $score >= $threshold ) {
				$best_score = $score;
				$best_match = $row;
			}
		}

		return $best_match;
	}

	/**
	 * Parse a price-sheet attachment (CSV) into an array of rows.
	 *
	 * @since 2.2.0
	 *
	 * @param int $attachment_id Price-sheet attachment ID.
	 * @return array Parsed rows with standardized keys.
	 */
	protected function parse_price_sheet( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array();
		}

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( 'json' === $extension ) {
			return $this->parse_json_price_sheet( $file_path );
		}

		// Default to CSV parsing.
		return $this->parse_csv_price_sheet( $file_path );
	}

	/**
	 * Parse a CSV price sheet.
	 *
	 * @since 2.2.0
	 *
	 * @param string $file_path Absolute path to CSV file.
	 * @return array Parsed rows.
	 */
	protected function parse_csv_price_sheet( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		$header = fgetcsv( $handle );
		if ( ! $header ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			return array();
		}

		// Normalize header keys.
		$header = array_map(
			function ( $h ) {
				return strtolower( trim( str_replace( array( ' ', '-' ), '_', $h ) ) );
			},
			$header
		);

		$rows = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}
			$rows[] = array_combine( $header, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		return $rows;
	}

	/**
	 * Parse a JSON price sheet.
	 *
	 * @since 2.2.0
	 *
	 * @param string $file_path Absolute path to JSON file.
	 * @return array Parsed rows.
	 */
	protected function parse_json_price_sheet( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return array();
		}
		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		// If it's a flat array of objects, return as-is.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			return $data;
		}
		// If it's wrapped (e.g., { "items": [...] }), try common keys.
		foreach ( array( 'items', 'rows', 'data', 'line_items' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}
		return array();
	}

	/**
	 * Calculate totals from line items.
	 *
	 * @since 2.2.0
	 *
	 * @param array $line_items Estimate line items.
	 * @param array $labor_rates Labor rate profile.
	 * @return array Totals breakdown.
	 */
	protected function calculate_totals( $line_items, $labor_rates ) {
		$parts_total = 0;
		$labor_total = 0;
		$total_hours = 0;

		foreach ( $line_items as $item ) {
			$parts_total += $item['unit_cost'];
			$labor_total += $item['labor_cost'];
			$total_hours += $item['labor_hours'];
		}

		$subtotal = round( $parts_total + $labor_total, 2 );

		/**
		 * Filter the tax rate for vehicle repair estimates.
		 *
		 * @since 2.2.0
		 *
		 * @param float $tax_rate Tax rate as a decimal (e.g., 0.08 for 8%).
		 */
		$tax_rate = (float) apply_filters( 'wp_mcp_ai_vehicle_estimate_tax_rate', 0.0 );
		$tax      = round( $subtotal * $tax_rate, 2 );
		$total    = round( $subtotal + $tax, 2 );

		return array(
			'parts_total'     => round( $parts_total, 2 ),
			'labor_total'     => round( $labor_total, 2 ),
			'total_hours'     => round( $total_hours, 1 ),
			'subtotal'        => $subtotal,
			'tax_rate'        => $tax_rate,
			'tax'             => $tax,
			'total'           => $total,
			'line_item_count' => count( $line_items ),
			'currency'        => 'USD',
		);
	}

	/**
	 * Calculate overall estimate uncertainty.
	 *
	 * @since 2.2.0
	 *
	 * @param array $vehicle  Vehicle descriptor.
	 * @param array $findings Damage findings.
	 * @param array $coverage Coverage assessment.
	 * @return array Uncertainty assessment.
	 */
	protected function calculate_uncertainty( $vehicle, $findings, $coverage ) {
		$factors = array();

		// Vehicle identification confidence.
		$vehicle_conf = (float) ( $vehicle['confidence'] ?? 0 );
		if ( $vehicle_conf < self::CONFIDENCE_THRESHOLDS['vehicle_id'] ) {
			$factors[] = __( 'Vehicle identification confidence is low.', 'mcp-ai-wpoos' );
		}

		// Coverage score.
		$coverage_score = (float) ( $coverage['score'] ?? 0 );
		if ( $coverage_score < self::CONFIDENCE_THRESHOLDS['coverage'] ) {
			$factors[] = __( 'Photo coverage is incomplete.', 'mcp-ai-wpoos' );
		}

		// Low-confidence findings.
		$low_conf_count = 0;
		foreach ( $findings as $f ) {
			if ( ( $f['confidence'] ?? 0 ) < self::CONFIDENCE_THRESHOLDS['damage_type'] ) {
				++$low_conf_count;
			}
		}
		if ( $low_conf_count > 0 ) {
			$factors[] = sprintf(
				/* translators: %d: number of low confidence findings */
				__( '%d damage finding(s) have low confidence.', 'mcp-ai-wpoos' ),
				$low_conf_count
			);
		}

		// No VIN.
		if ( empty( $vehicle['vin'] ) ) {
			$factors[] = __( 'No VIN available — trim-specific pricing may be inaccurate.', 'mcp-ai-wpoos' );
		}

		// Compute overall confidence level.
		$num_factors = count( $factors );
		if ( 0 === $num_factors ) {
			$level = 'high';
		} elseif ( $num_factors <= 2 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'confidence_level'     => $level,
			'factors'              => $factors,
			'vehicle_confidence'   => $vehicle_conf,
			'coverage_score'       => $coverage_score,
			'low_confidence_items' => $low_conf_count,
		);
	}

	/**
	 * Get standard assumptions included with every estimate.
	 *
	 * @since 2.2.0
	 *
	 * @param array $vehicle        Vehicle descriptor.
	 * @param array $findings       Damage findings.
	 * @param int   $price_sheet_id Price-sheet attachment ID.
	 * @return string[] List of assumption statements.
	 */
	protected function get_estimate_assumptions( $vehicle, $findings, $price_sheet_id ) {
		$assumptions = array(
			__( 'This estimate is based on visible damage in uploaded photos only. Hidden damage behind panels may increase the final cost.', 'mcp-ai-wpoos' ),
			__( 'Parts prices are estimates and may vary by supplier, availability, and region.', 'mcp-ai-wpoos' ),
			__( 'Labor times are estimated. Actual times may vary based on vehicle condition and shop equipment.', 'mcp-ai-wpoos' ),
		);

		if ( 0 === $price_sheet_id ) {
			$assumptions[] = __( 'No price sheet was provided. Costs are based on industry-average heuristics and should be verified against actual supplier quotes.', 'mcp-ai-wpoos' );
		}

		if ( empty( $vehicle['trim'] ) ) {
			$assumptions[] = __( 'Vehicle trim is unknown. Base-trim pricing was used; actual costs may differ for higher trims with additional features.', 'mcp-ai-wpoos' );
		}

		if ( empty( $vehicle['vin'] ) ) {
			$assumptions[] = __( 'Vehicle was identified visually (no VIN). Year, make, and model may not be exact.', 'mcp-ai-wpoos' );
		}

		return $assumptions;
	}

	/**
	 * Heuristic cost estimates when no price sheet is available.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part      Part identifier.
	 * @param string $operation Operation type.
	 * @param string $severity  Severity level.
	 * @return array Estimated unit_cost, labor_hours, and rate_group.
	 */
	protected function heuristic_cost_estimate( $part, $operation, $severity ) {
		// Base part-cost estimates (USD) for common operations.
		$part_costs = array(
			'bumper_cover_front'       => array(
				'replace'  => 350,
				'repair'   => 0,
				'refinish' => 0,
			),
			'bumper_cover_rear'        => array(
				'replace'  => 350,
				'repair'   => 0,
				'refinish' => 0,
			),
			'headlamp_left'            => array(
				'replace' => 250,
				'repair'  => 0,
			),
			'headlamp_right'           => array(
				'replace' => 250,
				'repair'  => 0,
			),
			'tail_lamp_left'           => array(
				'replace' => 150,
				'repair'  => 0,
			),
			'tail_lamp_right'          => array(
				'replace' => 150,
				'repair'  => 0,
			),
			'fender_front_left'        => array(
				'replace'  => 250,
				'repair'   => 0,
				'refinish' => 0,
			),
			'fender_front_right'       => array(
				'replace'  => 250,
				'repair'   => 0,
				'refinish' => 0,
			),
			'door_front_left'          => array(
				'replace'  => 600,
				'repair'   => 0,
				'refinish' => 0,
			),
			'door_front_right'         => array(
				'replace'  => 600,
				'repair'   => 0,
				'refinish' => 0,
			),
			'door_rear_left'           => array(
				'replace'  => 550,
				'repair'   => 0,
				'refinish' => 0,
			),
			'door_rear_right'          => array(
				'replace'  => 550,
				'repair'   => 0,
				'refinish' => 0,
			),
			'hood'                     => array(
				'replace'  => 450,
				'repair'   => 0,
				'refinish' => 0,
			),
			'trunk_lid'                => array(
				'replace'  => 400,
				'repair'   => 0,
				'refinish' => 0,
			),
			'windshield'               => array(
				'replace' => 350,
				'repair'  => 100,
			),
			'quarter_panel_rear_left'  => array(
				'replace'  => 800,
				'repair'   => 0,
				'refinish' => 0,
			),
			'quarter_panel_rear_right' => array(
				'replace'  => 800,
				'repair'   => 0,
				'refinish' => 0,
			),
			'mirror_left'              => array(
				'replace' => 200,
				'repair'  => 0,
			),
			'mirror_right'             => array(
				'replace' => 200,
				'repair'  => 0,
			),
			'grille'                   => array(
				'replace' => 150,
				'repair'  => 0,
			),
			'roof'                     => array(
				'replace'  => 1200,
				'repair'   => 0,
				'refinish' => 0,
			),
		);

		// Labor hour estimates by operation.
		$labor_hours_map = array(
			'replace'            => 3.0,
			'repair'             => 2.0,
			'refinish'           => 2.5,
			'blend'              => 1.5,
			'remove_and_install' => 1.0,
			'remove_and_replace' => 1.5,
			'calibration'        => 1.5,
			'alignment'          => 0.5,
		);

		// Severity multipliers for labor.
		$severity_multipliers = array(
			'light'    => 0.7,
			'moderate' => 1.0,
			'heavy'    => 1.4,
		);

		$unit_cost   = 0;
		$labor_hours = $labor_hours_map[ $operation ] ?? 2.0;
		$rate_group  = 'body';

		// Look up part cost.
		if ( isset( $part_costs[ $part ][ $operation ] ) ) {
			$unit_cost = $part_costs[ $part ][ $operation ];
		} else {
			// Generic fallback.
			if ( 'replace' === $operation ) {
				$unit_cost = 300;
			} elseif ( 'refinish' === $operation ) {
				$unit_cost  = 0; // Paint materials are included in labor typically.
				$rate_group = 'paint';
			}
		}

		// Apply severity multiplier to labor.
		$multiplier  = $severity_multipliers[ $severity ] ?? 1.0;
		$labor_hours = round( $labor_hours * $multiplier, 1 );

		// Assign rate group.
		if ( 'refinish' === $operation || 'blend' === $operation ) {
			$rate_group = 'paint';
		} elseif ( 'calibration' === $operation ) {
			$rate_group = 'mechanical';
		} elseif ( 'alignment' === $operation ) {
			$rate_group = 'mechanical';
		}

		return array(
			'unit_cost'   => (float) $unit_cost,
			'labor_hours' => $labor_hours,
			'rate_group'  => $rate_group,
		);
	}

	/**
	 * Get the labor rate for a specific rate group.
	 *
	 * @since 2.2.0
	 *
	 * @param string $rate_group Rate group identifier.
	 * @param array  $labor_rates Labor rate profile.
	 * @return float Labor rate per hour.
	 */
	protected function get_rate_for_group( $rate_group, $labor_rates ) {
		$key = $rate_group . '_rate';
		if ( isset( $labor_rates[ $key ] ) ) {
			return (float) $labor_rates[ $key ];
		}

		$defaults = $this->get_default_labor_rates();
		return (float) ( $defaults[ $key ] ?? 75.0 );
	}

	/**
	 * Get default labor rates.
	 *
	 * @since 2.2.0
	 * @return array Default labor rates.
	 */
	protected function get_default_labor_rates() {
		/**
		 * Filter the default labor rates for vehicle repair estimates.
		 *
		 * @since 2.2.0
		 *
		 * @param array $rates Default labor rates per hour in USD.
		 */
		return apply_filters(
			'wp_mcp_ai_vehicle_estimate_default_labor_rates',
			array(
				'body_rate'       => 75.0,
				'paint_rate'      => 75.0,
				'mechanical_rate' => 95.0,
				'frame_rate'      => 100.0,
			)
		);
	}

	/**
	 * Build the user-facing estimate summary message.
	 *
	 * @since 2.2.0
	 *
	 * @param array $estimate Full estimate data.
	 * @return string Formatted message.
	 */
	protected function build_estimate_message( $estimate ) {
		$vehicle = $estimate['vehicle_descriptor'] ?? array();
		$totals  = $estimate['totals'] ?? array();
		$unc     = $estimate['uncertainty'] ?? array();

		$vehicle_name = trim(
			sprintf(
				'%s %s %s',
				$vehicle['year'] ?? '',
				$vehicle['make'] ?? '',
				$vehicle['model'] ?? ''
			)
		) ?: __( 'Unknown Vehicle', 'mcp-ai-wpoos' );

		$parts   = array();
		$parts[] = sprintf(
			/* translators: %s: vehicle description */
			__( 'Vehicle Repair Estimate for %s', 'mcp-ai-wpoos' ),
			$vehicle_name
		);

		if ( ! empty( $totals['total'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: formatted total amount */
				__( 'Estimated Total: $%s', 'mcp-ai-wpoos' ),
				number_format( $totals['total'], 2 )
			);
		}

		if ( ! empty( $totals['line_item_count'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: number of line items, 2: total labor hours */
				__( '%1$d line item(s), %2$s labor hours', 'mcp-ai-wpoos' ),
				$totals['line_item_count'],
				number_format( $totals['total_hours'] ?? 0, 1 )
			);
		}

		$parts[] = sprintf(
			/* translators: %s: confidence level */
			__( 'Confidence: %s', 'mcp-ai-wpoos' ),
			ucfirst( $unc['confidence_level'] ?? 'unknown' )
		);

		// Add coverage warnings.
		$coverage = $estimate['coverage'] ?? array();
		if ( ! empty( $coverage['messages'] ) ) {
			foreach ( $coverage['messages'] as $msg ) {
				$parts[] = $msg;
			}
		}

		$parts[] = __( 'Note: This is an AI-generated estimate based on visible damage in your photos. Hidden damage may affect final costs.', 'mcp-ai-wpoos' );

		return implode( "\n", $parts );
	}

	/**
	 * Build an unknown vehicle descriptor from manual overrides.
	 *
	 * @since 2.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Vehicle descriptor with warning.
	 */
	protected function build_unknown_vehicle_descriptor( $arguments ) {
		$overrides = $arguments['vehicle_overrides'] ?? array();
		return array(
			'vin'        => '',
			'year'       => isset( $overrides['year'] ) ? absint( $overrides['year'] ) : '',
			'make'       => sanitize_text_field( $overrides['make'] ?? '' ),
			'model'      => sanitize_text_field( $overrides['model'] ?? '' ),
			'trim'       => sanitize_text_field( $overrides['trim'] ?? '' ),
			'body_class' => '',
			'source'     => 'unknown',
			'confidence' => 0.0,
			'warning'    => __( 'Could not identify vehicle. Estimate may be less accurate.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Sanitize vehicle descriptor for output.
	 *
	 * @since 2.2.0
	 *
	 * @param array $vehicle Vehicle descriptor.
	 * @return array Sanitized vehicle data.
	 */
	protected function sanitize_vehicle_for_output( $vehicle ) {
		$safe         = array();
		$allowed_keys = array(
			'vin',
			'year',
			'make',
			'model',
			'trim',
			'body_class',
			'vehicle_type',
			'drive_type',
			'fuel_type',
			'engine_displacement',
			'engine_cylinders',
			'transmission',
			'manufacturer',
			'doors',
			'source',
			'confidence',
		);
		foreach ( $allowed_keys as $key ) {
			if ( isset( $vehicle[ $key ] ) ) {
				$safe[ $key ] = $vehicle[ $key ];
			}
		}
		return $safe;
	}

	/**
	 * Check whether a part is a glass component.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part Part identifier.
	 * @return bool
	 */
	protected function is_glass_part( $part ) {
		return in_array(
			$part,
			array( 'windshield', 'rear_window', 'side_window_left', 'side_window_right', 'quarter_glass_left', 'quarter_glass_right' ),
			true
		);
	}

	/**
	 * Check whether a part is structural.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part Part identifier.
	 * @return bool
	 */
	protected function is_structural_part( $part ) {
		return in_array(
			$part,
			array( 'frame_rail', 'unibody', 'rocker_panel', 'a_pillar', 'b_pillar', 'c_pillar', 'floor_pan', 'apron', 'radiator_support' ),
			true
		);
	}

	/**
	 * Check whether a part is an exterior panel.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part Part identifier.
	 * @return bool
	 */
	protected function is_exterior_panel( $part ) {
		$panels = array(
			'bumper_cover_front',
			'bumper_cover_rear',
			'fender_front_left',
			'fender_front_right',
			'door_front_left',
			'door_front_right',
			'door_rear_left',
			'door_rear_right',
			'hood',
			'trunk_lid',
			'roof',
			'quarter_panel_rear_left',
			'quarter_panel_rear_right',
		);
		return in_array( $part, $panels, true );
	}

	/**
	 * Check whether a part is a lamp/light.
	 *
	 * @since 2.2.0
	 *
	 * @param string $part Part identifier.
	 * @return bool
	 */
	protected function is_lamp_part( $part ) {
		return in_array(
			$part,
			array( 'headlamp_left', 'headlamp_right', 'fog_lamp_left', 'fog_lamp_right' ),
			true
		);
	}

	/**
	 * Check whether the vehicle has ADAS features.
	 *
	 * @since 2.2.0
	 *
	 * @param array $vehicle Vehicle descriptor.
	 * @return bool
	 */
	protected function vehicle_has_adas( $vehicle ) {
		$adas_fields = array(
			'forward_collision',
			'lane_departure',
			'adaptive_cruise',
			'blind_spot',
			'backup_camera',
		);
		foreach ( $adas_fields as $field ) {
			if ( ! empty( $vehicle[ $field ] ) ) {
				return true;
			}
		}
		// Heuristic: vehicles 2018+ are likely to have at least a backup camera.
		$year = (int) ( $vehicle['year'] ?? 0 );
		return $year >= 2018;
	}

	/**
	 * Tokenize a string for fuzzy matching.
	 *
	 * @since 2.2.0
	 *
	 * @param string $text Input text.
	 * @return string[] Lowercase tokens.
	 */
	protected function tokenize( $text ) {
		$text   = strtolower( str_replace( array( '_', '-', '/', '&' ), ' ', $text ) );
		$tokens = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_unique( $tokens );
	}

	/**
	 * Compute Jaccard similarity between two token sets.
	 *
	 * @since 2.2.0
	 *
	 * @param string[] $a First token set.
	 * @param string[] $b Second token set.
	 * @return float Similarity score between 0 and 1.
	 */
	protected function jaccard_similarity( $a, $b ) {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$intersection = count( array_intersect( $a, $b ) );
		$union        = count( array_unique( array_merge( $a, $b ) ) );
		return $union > 0 ? $intersection / $union : 0.0;
	}

	/**
	 * Safely parse JSON from text that may contain surrounding prose.
	 *
	 * @since 2.2.0
	 *
	 * @param string $text Raw text that may contain JSON.
	 * @return array|null Parsed data or null.
	 */
	protected function parse_json_from_text( $text ) {
		if ( empty( $text ) ) {
			return null;
		}

		// Try direct decode first.
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Try extracting JSON from markdown code blocks.
		if ( preg_match( '/```(?:json)?\s*\n?([\s\S]*?)\n?```/', $text, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Try extracting JSON object from text.
		if ( preg_match( '/\{[\s\S]*\}/', $text, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}
