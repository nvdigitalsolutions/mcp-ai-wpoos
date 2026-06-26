<?php
/**
 * Tool for validating vehicle images before cleaning or repair estimates.
 *
 * Analyzes user-provided vehicle images to verify they meet the requirements
 * for AI-powered vehicle cleaning estimates or repair damage assessments.
 * Uses OpenAI Vision API for intelligent vehicle detection, view angle
 * classification, damage visibility, and image quality assessment.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Validates vehicle images for suitability in cleaning or repair estimate tools.
 *
 * Uses a two-pass approach per image:
 * 1. Technical validation - file format, dimensions, file size.
 * 2. AI vision analysis  - vehicle presence, view angle, lighting, sharpness,
 *    damage visibility (repair), soiling visibility (cleaning).
 *
 * Estimate type categories and their requirements:
 * - cleaning → at least 1 full vehicle exterior shot for size classification;
 *              optional interior / close-up soil / trunk shots.
 * - repair   → exterior coverage from 4 angles (front, rear, left, right);
 *              damage close-ups; optional VIN plate photo, corner views.
 *
 * Industry standards referenced:
 * - Google Vertex AI vehicle damage assessment guidelines
 * - Qapter / Solera AI collision repair image capture protocols
 * - Ravin AI 360° walkaround requirements
 * - CCC ONE / Mitchell estimating photo requirements
 * - Artura image capture guide for automotive
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Validate_Image_For_Vehicle implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Rating score thresholds for letter grades.
	 *
	 * Aligned with automotive industry AI image qualification tiers:
	 * - A (90-100): Excellent – images exceed all requirements.
	 * - B (75-89):  Good – meets requirements with minor improvements possible.
	 * - C (60-74):  Fair – usable but estimate accuracy may be reduced.
	 * - D (40-59):  Poor – significant issues, recommend additional photos.
	 * - F (0-39):   Fail – images cannot be used for reliable estimation.
	 *
	 * @var array<string, int>
	 */
	const RATING_THRESHOLDS = array(
		'A' => 90,
		'B' => 75,
		'C' => 60,
		'D' => 40,
		'F' => 0,
	);

	/**
	 * Rating labels for display.
	 *
	 * @var array<string, string>
	 */
	const RATING_LABELS = array(
		'A' => 'Excellent',
		'B' => 'Good',
		'C' => 'Fair',
		'D' => 'Poor',
		'F' => 'Fail',
	);

	/**
	 * Scoring weights for cleaning estimate images (must sum to 100).
	 *
	 * Based on car-wash / detailing industry requirements:
	 * - Vehicle presence and full-body visibility are critical for size classification.
	 * - Technical quality gates everything else.
	 * - Lighting and sharpness affect AI size classification accuracy.
	 * - Condition visibility helps the AI detect soil / add-on needs.
	 *
	 * @var array<string, int>
	 */
	const CLEANING_WEIGHTS = array(
		'vehicle_detected'     => 25,
		'full_vehicle_visible' => 20,
		'technical_format'     => 15,
		'image_dimensions'     => 8,
		'lighting'             => 12,
		'sharpness'            => 8,
		'condition_visibility' => 10,
		'single_vehicle'       => 2,
	);

	/**
	 * Scoring weights for repair estimate images (must sum to 100).
	 *
	 * Based on collision repair / insurance industry standards:
	 * - Coverage completeness (all four sides) is the most critical factor.
	 * - Vehicle detection is mandatory.
	 * - Damage visibility directly impacts estimate accuracy.
	 * - VIN visibility enables part lookup and pricing.
	 * - Technical quality and lighting affect damage classification.
	 *
	 * @var array<string, int>
	 */
	const REPAIR_WEIGHTS = array(
		'vehicle_detected'      => 15,
		'coverage_completeness' => 25,
		'damage_visibility'     => 20,
		'technical_format'      => 10,
		'image_dimensions'      => 5,
		'lighting'              => 10,
		'sharpness'             => 8,
		'vin_visibility'        => 5,
		'single_vehicle'        => 2,
	);

	/**
	 * Required exterior views for repair estimates (per industry standard).
	 *
	 * @var array<string, string>
	 */
	const REQUIRED_REPAIR_VIEWS = array(
		'front'      => 'Front view',
		'rear'       => 'Rear view',
		'left_side'  => 'Left (driver) side',
		'right_side' => 'Right (passenger) side',
	);

	/**
	 * Recommended additional views for repair estimates.
	 *
	 * @var array<string, string>
	 */
	const RECOMMENDED_REPAIR_VIEWS = array(
		'front_left_corner'  => 'Front-left corner (45°)',
		'front_right_corner' => 'Front-right corner (45°)',
		'rear_left_corner'   => 'Rear-left corner (45°)',
		'rear_right_corner'  => 'Rear-right corner (45°)',
		'damage_closeup'     => 'Close-up of damaged area',
		'vin_plate'          => 'VIN plate (windshield or door jamb)',
	);

	/**
	 * Allowed MIME types for input images.
	 *
	 * @var array<string>
	 */
	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
	);

	/**
	 * Minimum width/height in pixels.
	 *
	 * @var int
	 */
	const MIN_DIMENSION = 720;

	/**
	 * Recommended minimum width/height in pixels for best results.
	 *
	 * @var int
	 */
	const RECOMMENDED_DIMENSION = 1080;

	/**
	 * Maximum file size in bytes (20 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 20971520;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_image_for_vehicle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Image for Vehicle Estimate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates vehicle images to ensure they meet the requirements for AI-powered cleaning or repair estimates. For cleaning estimates, verifies a full vehicle shot is present for size classification. For repair estimates, checks multi-angle coverage (front, rear, left, right), damage visibility, and optional VIN plate photo. Uses OpenAI Vision to assess image quality, vehicle detection, view angles, and damage/condition visibility. Returns a weighted quality rating (0-100, A-F) with actionable feedback.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of WordPress attachment IDs for vehicle photos. Also accepts public image URLs (https://...) or file_id strings from chat uploads. For repair estimates, include front, rear, left side, right side, and damage close-ups.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
					'minItems'    => 1,
					'maxItems'    => 30,
				),
				'estimate_type'        => array(
					'type'        => 'string',
					'enum'        => array( 'cleaning', 'repair' ),
					'default'     => 'cleaning',
					'description' => __( 'The type of estimate these images will be used for. "cleaning" requires at least one full vehicle shot for size classification. "repair" requires multi-angle exterior coverage and damage close-ups.', 'mcp-ai-wpoos-pro' ),
				),
				'strict_mode'          => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, all recommended checks must pass for overall validation success. When false (default), only critical checks must pass.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'image_attachment_ids', 'estimate_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',
			'requires-vision-model',
			'read-only',
			'external-api',
			'network-dependent',
			'consumes-tokens',
			'non-deterministic',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array( 'vision' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the vehicle image validation tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Structured validation results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to validate vehicle images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to validate vehicle images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Validate estimate type.
		$estimate_type = isset( $arguments['estimate_type'] ) ? sanitize_key( $arguments['estimate_type'] ) : 'cleaning';
		if ( ! in_array( $estimate_type, array( 'cleaning', 'repair' ), true ) ) {
			$estimate_type = 'cleaning';
		}

		$strict_mode = ! empty( $arguments['strict_mode'] );

		// Resolve image inputs.
		$raw_ids = isset( $arguments['image_attachment_ids'] ) ? $arguments['image_attachment_ids'] : array();
		if ( empty( $raw_ids ) || ! is_array( $raw_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_images',
				__( 'At least one vehicle image is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Resolve each image to metadata.
		$images_data = array();
		foreach ( $raw_ids as $raw_id ) {
			$image_data = $this->resolve_single_image( $raw_id );
			if ( ! is_wp_error( $image_data ) ) {
				$images_data[] = $image_data;
			}
		}

		if ( empty( $images_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_valid_images',
				__( 'None of the provided image references could be resolved. Provide valid attachment IDs, file IDs, or URLs.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Technical validation of all images.
		$all_checks        = array();
		$critical_failures = array();

		foreach ( $images_data as $idx => $image_data ) {
			$tech_result = $this->validate_technical_requirements( $image_data, $idx );
			$all_checks  = array_merge( $all_checks, $tech_result['checks'] );
			if ( ! empty( $tech_result['critical_failures'] ) ) {
				$critical_failures = array_merge( $critical_failures, $tech_result['critical_failures'] );
			}
		}

		// AI vision analysis of the image set.
		$vision_result = $this->validate_with_vision_ai( $images_data, $estimate_type );
		if ( is_wp_error( $vision_result ) ) {
			return $vision_result;
		}
		$all_checks = array_merge( $all_checks, $vision_result['checks'] );

		// Determine overall pass/fail.
		$all_critical_failures = $critical_failures;
		$warnings              = array();

		foreach ( $all_checks as $check ) {
			if ( 'fail' === $check['status'] && ! empty( $check['critical'] ) ) {
				$all_critical_failures[] = $check['message'];
			} elseif ( 'fail' === $check['status'] || 'warning' === $check['status'] ) {
				$warnings[] = $check['message'];
			}
		}

		// Deduplicate.
		$all_critical_failures = array_values( array_unique( $all_critical_failures ) );
		$warnings              = array_values( array_unique( $warnings ) );

		$is_valid = empty( $all_critical_failures );
		if ( $strict_mode && ! empty( $warnings ) ) {
			$is_valid = false;
		}

		return $this->build_validation_response(
			$is_valid,
			$all_checks,
			$estimate_type,
			array_merge( $all_critical_failures, $strict_mode ? $warnings : array() ),
			$images_data,
			isset( $vision_result['suggestions'] ) ? $vision_result['suggestions'] : array()
		);
	}

	/**
	 * Resolve a single image input to metadata.
	 *
	 * @param mixed $raw_id Attachment ID, URL, or file_id.
	 * @return array|WP_Error Image data array or error.
	 */
	private function resolve_single_image( $raw_id ) {
		$image_url     = '';
		$attachment_id = 0;
		$file_path     = '';
		$mime_type     = '';
		$width         = 0;
		$height        = 0;
		$file_size     = 0;

		if ( is_numeric( $raw_id ) ) {
			$attachment_id = absint( $raw_id );
			$image_url     = wp_get_attachment_url( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			$mime_type     = get_post_mime_type( $attachment_id );

			if ( ! $image_url ) {
				return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'Invalid attachment ID.', 'mcp-ai-wpoos-pro' ) );
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( $metadata ) {
				$width  = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
				$height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
			}
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
			}
		} elseif ( is_string( $raw_id ) && preg_match( '#^https?://#i', $raw_id ) ) {
			$image_url     = esc_url_raw( $raw_id );
			$head_response = wp_remote_head(
				$image_url,
				array(
					'timeout' => 10,
					// sslverify intentionally not set — defaults to true so external
					// image hosts must present valid certificates. The global
					// http_request_args filter in WP_MCP_AI_HTTP_Helper relaxes this
					// only for loopback / private-network destinations when the
					// "Allow loopback SSL bypass" admin setting is enabled.
				)
			);
			if ( ! is_wp_error( $head_response ) ) {
				$content_type   = wp_remote_retrieve_header( $head_response, 'content-type' );
				$content_length = wp_remote_retrieve_header( $head_response, 'content-length' );
				if ( $content_type ) {
					$mime_type = sanitize_mime_type( $content_type );
				}
				if ( $content_length ) {
					$file_size = absint( $content_length );
				}
			}
		} elseif ( is_string( $raw_id ) && '' !== $raw_id ) {
			// Try resolving as file_id via the trait.
			$resolved = $this->resolve_attachment_id( array( 'file_id' => $raw_id ) );
			if ( ! is_wp_error( $resolved ) && $resolved > 0 ) {
				return $this->resolve_single_image( $resolved );
			}
			return new WP_Error( 'wp_mcp_ai_unresolvable', __( 'Could not resolve image reference.', 'mcp-ai-wpoos-pro' ) );
		} else {
			return new WP_Error( 'wp_mcp_ai_invalid_input', __( 'Invalid image input.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'attachment_id' => $attachment_id,
			'image_url'     => $image_url,
			'file_path'     => $file_path,
			'mime_type'     => $mime_type,
			'width'         => $width,
			'height'        => $height,
			'file_size'     => $file_size,
		);
	}

	/**
	 * Validate technical image requirements (format, size, dimensions).
	 *
	 * @param array $image_data Image data array.
	 * @param int   $index      Image index for labelling.
	 * @return array Array with 'checks' and 'critical_failures'.
	 */
	private function validate_technical_requirements( array $image_data, $index ) {
		$checks            = array();
		$critical_failures = array();
		$label             = sprintf(
			/* translators: %d: image number */
			__( 'Image #%d', 'mcp-ai-wpoos-pro' ),
			$index + 1
		);

		// Check 1: File format.
		if ( ! empty( $image_data['mime_type'] ) ) {
			$mime = strtolower( trim( explode( ';', $image_data['mime_type'] )[0] ) );
			if ( in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
				$checks[] = array(
					'name'     => 'file_format_' . $index,
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %1$s: image label, %2$s: MIME type */
						__( '%1$s: File format supported (%2$s).', 'mcp-ai-wpoos-pro' ),
						$label,
						$mime
					),
					'critical' => true,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$s: image label, %2$s: MIME type, %3$s: allowed types */
					__( '%1$s: Unsupported file format (%2$s). Allowed: %3$s.', 'mcp-ai-wpoos-pro' ),
					$label,
					$mime,
					implode( ', ', self::ALLOWED_MIME_TYPES )
				);
				$checks[]            = array(
					'name'     => 'file_format_' . $index,
					'status'   => 'fail',
					'message'  => $msg,
					'critical' => true,
				);
				$critical_failures[] = $msg;
			}
		}

		// Check 2: File size.
		if ( $image_data['file_size'] > 0 ) {
			if ( $image_data['file_size'] <= self::MAX_FILE_SIZE ) {
				$checks[] = array(
					'name'     => 'file_size_' . $index,
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %1$s: image label, %2$.1f: file size */
						__( '%1$s: File size acceptable (%2$.1f MB).', 'mcp-ai-wpoos-pro' ),
						$label,
						$image_data['file_size'] / 1048576
					),
					'critical' => true,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$s: image label, %2$.1f: file size, %3$.0f: max */
					__( '%1$s: File size (%2$.1f MB) exceeds the %3$.0f MB limit.', 'mcp-ai-wpoos-pro' ),
					$label,
					$image_data['file_size'] / 1048576,
					self::MAX_FILE_SIZE / 1048576
				);
				$checks[]            = array(
					'name'     => 'file_size_' . $index,
					'status'   => 'fail',
					'message'  => $msg,
					'critical' => true,
				);
				$critical_failures[] = $msg;
			}
		}

		// Check 3: Dimensions.
		if ( $image_data['width'] > 0 && $image_data['height'] > 0 ) {
			$shortest = min( $image_data['width'], $image_data['height'] );
			if ( $shortest >= self::RECOMMENDED_DIMENSION ) {
				$checks[] = array(
					'name'     => 'dimensions_' . $index,
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %1$s: label, %2$d: width, %3$d: height */
						__( '%1$s: Dimensions sufficient (%2$d × %3$d px).', 'mcp-ai-wpoos-pro' ),
						$label,
						$image_data['width'],
						$image_data['height']
					),
					'critical' => true,
				);
			} elseif ( $shortest >= self::MIN_DIMENSION ) {
				$checks[] = array(
					'name'     => 'dimensions_' . $index,
					'status'   => 'warning',
					'message'  => sprintf(
						/* translators: %1$s: label, %2$d: w, %3$d: h, %4$d: recommended */
						__( '%1$s: Dimensions (%2$d × %3$d px) below the recommended %4$d px. Results may be suboptimal.', 'mcp-ai-wpoos-pro' ),
						$label,
						$image_data['width'],
						$image_data['height'],
						self::RECOMMENDED_DIMENSION
					),
					'critical' => false,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$s: label, %2$d: w, %3$d: h, %4$d: min */
					__( '%1$s: Dimensions (%2$d × %3$d px) too small. Minimum required is %4$d px.', 'mcp-ai-wpoos-pro' ),
					$label,
					$image_data['width'],
					$image_data['height'],
					self::MIN_DIMENSION
				);
				$checks[]            = array(
					'name'     => 'dimensions_' . $index,
					'status'   => 'fail',
					'message'  => $msg,
					'critical' => true,
				);
				$critical_failures[] = $msg;
			}
		}

		return array(
			'checks'            => $checks,
			'critical_failures' => $critical_failures,
		);
	}

	/**
	 * Validate images using AI vision analysis.
	 *
	 * Sends all images to OpenAI Vision in a single request with an
	 * estimate-type-specific prompt.
	 *
	 * @param array  $images_data   Array of image data arrays.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @return array|WP_Error Array with 'checks' and 'suggestions', or WP_Error.
	 */
	private function validate_with_vision_ai( array $images_data, $estimate_type ) {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_class',
				__( 'OpenAI client class not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'OpenAI API key is not configured. Vehicle image validation requires OpenAI Vision.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$prompt = $this->build_vision_prompt( $estimate_type, count( $images_data ) );

		// Build message content with all images.
		$content   = array();
		$content[] = array(
			'type' => 'text',
			'text' => $prompt,
		);

		foreach ( $images_data as $image_data ) {
			if ( ! empty( $image_data['image_url'] ) ) {
				$content[] = array(
					'type'      => 'image_url',
					'image_url' => array(
						'url'    => $image_data['image_url'],
						'detail' => 'high',
					),
				);
			}
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->create_chat_completion(
			$messages,
			array(
				'model'                 => 'gpt-4o',
				'max_completion_tokens' => 1500,
				'response_format'       => array( 'type' => 'json_object' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from OpenAI Vision API.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$raw      = trim( $response['choices'][0]['message']['content'] );
		$analysis = json_decode( $raw, true );
		if ( ! is_array( $analysis ) ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				__( 'Could not parse Vision API response.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return $this->parse_vision_analysis( $analysis, $estimate_type );
	}

	/**
	 * Build the AI vision prompt for vehicle image validation.
	 *
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @param int    $image_count   Number of images.
	 * @return string The prompt text.
	 */
	private function build_vision_prompt( $estimate_type, $image_count ) {
		$prompt = 'You are a vehicle image validation assistant for an AI-powered ';

		if ( 'repair' === $estimate_type ) {
			$prompt .= 'vehicle repair estimate system. ';
			$prompt .= 'Analyze these ' . absint( $image_count ) . ' vehicle photo(s) to determine if they are suitable for generating an accurate repair estimate.';
			$prompt .= "\n\nRequired: Photos should cover front, rear, left side, and right side of the vehicle. Close-ups of damaged areas and a VIN plate photo are highly recommended.";
			$prompt .= "\n\nRespond with a JSON object:";
			$prompt .= "\n{";
			$prompt .= "\n  \"vehicle_detected\": boolean,";
			$prompt .= "\n  \"vehicle_count\": integer,";
			$prompt .= "\n  \"vehicle_description\": string,";
			$prompt .= "\n  \"views_identified\": {";
			$prompt .= "\n    \"front\": boolean,";
			$prompt .= "\n    \"rear\": boolean,";
			$prompt .= "\n    \"left_side\": boolean,";
			$prompt .= "\n    \"right_side\": boolean,";
			$prompt .= "\n    \"front_left_corner\": boolean,";
			$prompt .= "\n    \"front_right_corner\": boolean,";
			$prompt .= "\n    \"rear_left_corner\": boolean,";
			$prompt .= "\n    \"rear_right_corner\": boolean,";
			$prompt .= "\n    \"damage_closeup\": boolean,";
			$prompt .= "\n    \"vin_plate\": boolean,";
			$prompt .= "\n    \"interior\": boolean";
			$prompt .= "\n  },";
			$prompt .= "\n  \"damage_visible\": boolean,";
			$prompt .= "\n  \"damage_areas\": [string],";
			$prompt .= "\n  \"damage_severity_estimate\": \"minor\" | \"moderate\" | \"severe\" | \"unknown\",";
			$prompt .= "\n  \"vin_readable\": boolean,";
		} else {
			$prompt .= 'vehicle cleaning / detailing estimate system. ';
			$prompt .= 'Analyze these ' . absint( $image_count ) . ' vehicle photo(s) to determine if they are suitable for classifying vehicle size and generating a cleaning estimate.';
			$prompt .= "\n\nRequired: At least one photo showing the full vehicle exterior for size classification (Car, Small Truck/SUV, Oversize Truck/SUV).";
			$prompt .= "\n\nRespond with a JSON object:";
			$prompt .= "\n{";
			$prompt .= "\n  \"vehicle_detected\": boolean,";
			$prompt .= "\n  \"vehicle_count\": integer,";
			$prompt .= "\n  \"vehicle_description\": string,";
			$prompt .= "\n  \"full_vehicle_visible\": boolean,";
			$prompt .= "\n  \"estimated_size_tier\": \"car\" | \"small_truck_suv\" | \"oversize_truck_suv\" | \"unknown\",";
			$prompt .= "\n  \"size_confidence\": float,";
			$prompt .= "\n  \"condition_visible\": boolean,";
			$prompt .= "\n  \"visible_conditions\": [string],";
		}

		// Common fields.
		$prompt .= "\n  \"lighting_quality\": \"good\" | \"acceptable\" | \"poor\",";
		$prompt .= "\n  \"image_sharpness\": \"sharp\" | \"acceptable\" | \"blurry\",";
		$prompt .= "\n  \"obstructions\": [string],";
		$prompt .= "\n  \"overall_suitable\": boolean,";
		$prompt .= "\n  \"suggestions\": [string]";
		$prompt .= "\n}";

		$prompt .= "\n\nFor \"obstructions\", list anything blocking the view of the vehicle (e.g., \"person standing in front\", \"other car partially blocking\", \"tree branch obscuring panel\").";
		$prompt .= "\nFor \"suggestions\", provide actionable advice to improve image quality for the estimate.";
		$prompt .= "\nRespond ONLY with valid JSON. No additional text.";

		return $prompt;
	}

	/**
	 * Parse AI vision analysis into structured checks.
	 *
	 * @param array  $analysis      Parsed JSON from the Vision API.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @return array Array with 'checks' and 'suggestions'.
	 */
	private function parse_vision_analysis( array $analysis, $estimate_type ) {
		$checks = array();

		// Check: Vehicle detected.
		$vehicle_detected = ! empty( $analysis['vehicle_detected'] );
		$checks[]         = array(
			'name'     => 'vehicle_detected',
			'status'   => $vehicle_detected ? 'pass' : 'fail',
			'message'  => $vehicle_detected
				? __( 'A vehicle was detected in the images.', 'mcp-ai-wpoos-pro' )
				: __( 'No vehicle detected. Please provide photos of a vehicle.', 'mcp-ai-wpoos-pro' ),
			'critical' => true,
		);

		// Check: Single vehicle (multiple causes ambiguity).
		$vehicle_count = isset( $analysis['vehicle_count'] ) ? absint( $analysis['vehicle_count'] ) : 0;
		if ( $vehicle_count > 1 ) {
			$checks[] = array(
				'name'     => 'single_vehicle',
				'status'   => 'warning',
				'message'  => sprintf(
					/* translators: %d: vehicle count */
					__( 'Multiple vehicles detected (%d). Ensure photos focus on a single vehicle for accurate estimation.', 'mcp-ai-wpoos-pro' ),
					$vehicle_count
				),
				'critical' => false,
			);
		} elseif ( 1 === $vehicle_count ) {
			$checks[] = array(
				'name'     => 'single_vehicle',
				'status'   => 'pass',
				'message'  => __( 'Single vehicle detected (ideal for estimation).', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Estimate-type-specific checks.
		if ( 'repair' === $estimate_type ) {
			$checks = array_merge( $checks, $this->parse_repair_checks( $analysis ) );
		} else {
			$checks = array_merge( $checks, $this->parse_cleaning_checks( $analysis ) );
		}

		// Common checks: lighting.
		$lighting = isset( $analysis['lighting_quality'] ) ? sanitize_key( $analysis['lighting_quality'] ) : 'acceptable';
		if ( 'good' === $lighting ) {
			$checks[] = array(
				'name'     => 'lighting',
				'status'   => 'pass',
				'message'  => __( 'Lighting quality is good.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		} elseif ( 'acceptable' === $lighting ) {
			$checks[] = array(
				'name'     => 'lighting',
				'status'   => 'pass',
				'message'  => __( 'Lighting is acceptable. Better lighting would improve estimate accuracy.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'lighting',
				'status'   => 'warning',
				'message'  => __( 'Poor lighting detected. Dark, overexposed, or backlit images reduce AI accuracy. Retake in well-lit conditions.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Common checks: sharpness.
		$sharpness = isset( $analysis['image_sharpness'] ) ? sanitize_key( $analysis['image_sharpness'] ) : 'acceptable';
		if ( 'blurry' === $sharpness ) {
			$checks[] = array(
				'name'     => 'sharpness',
				'status'   => 'warning',
				'message'  => __( 'One or more images appear blurry. Sharper photos improve damage and size detection accuracy.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'sharpness',
				'status'   => 'pass',
				'message'  => 'sharp' === $sharpness
					? __( 'Images are sharp and clear.', 'mcp-ai-wpoos-pro' )
					: __( 'Image sharpness is acceptable.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Common checks: obstructions.
		$obstructions = isset( $analysis['obstructions'] ) && is_array( $analysis['obstructions'] ) ? $analysis['obstructions'] : array();
		if ( ! empty( $obstructions ) ) {
			$checks[] = array(
				'name'     => 'obstructions',
				'status'   => 'warning',
				'message'  => sprintf(
					/* translators: %s: obstruction list */
					__( 'Obstructions detected: %s. These may reduce estimate accuracy.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_map( 'sanitize_text_field', $obstructions ) )
				),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'obstructions',
				'status'   => 'pass',
				'message'  => __( 'No obstructions detected blocking the vehicle.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		$suggestions = isset( $analysis['suggestions'] ) && is_array( $analysis['suggestions'] ) ? $analysis['suggestions'] : array();

		return array(
			'checks'      => $checks,
			'suggestions' => array_map( 'sanitize_text_field', $suggestions ),
		);
	}

	/**
	 * Parse cleaning-specific checks from vision analysis.
	 *
	 * @param array $analysis Vision analysis data.
	 * @return array Cleaning-specific checks.
	 */
	private function parse_cleaning_checks( array $analysis ) {
		$checks = array();

		// Full vehicle visible (critical for size classification).
		$full_visible = ! empty( $analysis['full_vehicle_visible'] );
		$checks[]     = array(
			'name'     => 'full_vehicle_visible',
			'status'   => $full_visible ? 'pass' : 'fail',
			'message'  => $full_visible
				? __( 'Full vehicle is visible for size classification.', 'mcp-ai-wpoos-pro' )
				: __( 'Full vehicle is not visible. A photo showing the entire vehicle is required for accurate size classification and pricing.', 'mcp-ai-wpoos-pro' ),
			'critical' => true,
		);

		// Size tier detected.
		$size_tier  = isset( $analysis['estimated_size_tier'] ) ? sanitize_key( $analysis['estimated_size_tier'] ) : 'unknown';
		$confidence = isset( $analysis['size_confidence'] ) ? floatval( $analysis['size_confidence'] ) : 0.0;
		if ( 'unknown' !== $size_tier && $confidence > 0.5 ) {
			$checks[] = array(
				'name'     => 'size_classification',
				'status'   => $confidence >= 0.75 ? 'pass' : 'warning',
				'message'  => sprintf(
					/* translators: %1$s: size tier, %2$d: confidence pct */
					__( 'Vehicle classified as "%1$s" with %2$d%% confidence.', 'mcp-ai-wpoos-pro' ),
					$size_tier,
					round( $confidence * 100 )
				),
				'critical' => false,
			);
		} elseif ( $full_visible ) {
			$checks[] = array(
				'name'     => 'size_classification',
				'status'   => 'warning',
				'message'  => __( 'Vehicle size could not be confidently classified. A clearer full-body side photo would improve classification.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Condition visibility (helpful for add-on recommendations).
		$condition_visible  = ! empty( $analysis['condition_visible'] );
		$visible_conditions = isset( $analysis['visible_conditions'] ) && is_array( $analysis['visible_conditions'] )
			? $analysis['visible_conditions']
			: array();

		$checks[] = array(
			'name'     => 'condition_visibility',
			'status'   => $condition_visible ? 'pass' : 'info',
			'message'  => $condition_visible
				? sprintf(
					/* translators: %s: condition list */
					__( 'Vehicle conditions detected: %s. This helps recommend appropriate add-on services.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_map( 'sanitize_text_field', $visible_conditions ) )
				)
				: __( 'Vehicle condition not clearly visible. Close-up photos of soiled/damaged areas help recommend add-on services.', 'mcp-ai-wpoos-pro' ),
			'critical' => false,
		);

		return $checks;
	}

	/**
	 * Parse repair-specific checks from vision analysis.
	 *
	 * @param array $analysis Vision analysis data.
	 * @return array Repair-specific checks.
	 */
	private function parse_repair_checks( array $analysis ) {
		$checks = array();
		$views  = isset( $analysis['views_identified'] ) && is_array( $analysis['views_identified'] )
			? $analysis['views_identified']
			: array();

		// Required views coverage.
		$required_present = 0;
		$required_total   = count( self::REQUIRED_REPAIR_VIEWS );

		foreach ( self::REQUIRED_REPAIR_VIEWS as $view_key => $view_label ) {
			$present  = ! empty( $views[ $view_key ] );
			$checks[] = array(
				'name'     => 'view_' . $view_key,
				'status'   => $present ? 'pass' : 'fail',
				'message'  => $present
					? sprintf(
						/* translators: %s: view label */
						__( '%s view is present.', 'mcp-ai-wpoos-pro' ),
						$view_label
					)
					: sprintf(
						/* translators: %s: view label */
						__( '%s view is missing. This angle is required for a complete repair estimate.', 'mcp-ai-wpoos-pro' ),
						$view_label
					),
				'critical' => true,
			);
			if ( $present ) {
				++$required_present;
			}
		}

		// Coverage completeness score.
		$coverage_pct = $required_total > 0 ? round( ( $required_present / $required_total ) * 100 ) : 0;
		$checks[]     = array(
			'name'     => 'coverage_completeness',
			'status'   => $coverage_pct >= 100 ? 'pass' : ( $coverage_pct >= 50 ? 'warning' : 'fail' ),
			'message'  => sprintf(
				/* translators: %1$d: present, %2$d: total, %3$d: percentage */
				__( 'Exterior coverage: %1$d of %2$d required views present (%3$d%%).', 'mcp-ai-wpoos-pro' ),
				$required_present,
				$required_total,
				$coverage_pct
			),
			'critical' => $coverage_pct < 50,
		);

		// Recommended views (informational).
		foreach ( self::RECOMMENDED_REPAIR_VIEWS as $view_key => $view_label ) {
			$present  = ! empty( $views[ $view_key ] );
			$checks[] = array(
				'name'     => 'recommended_' . $view_key,
				'status'   => $present ? 'pass' : 'info',
				'message'  => $present
					? sprintf(
						/* translators: %s: view label */
						__( 'Recommended: %s is present.', 'mcp-ai-wpoos-pro' ),
						$view_label
					)
					: sprintf(
						/* translators: %s: view label */
						__( 'Recommended: %s is missing (optional but improves estimate accuracy).', 'mcp-ai-wpoos-pro' ),
						$view_label
					),
				'critical' => false,
			);
		}

		// Damage visibility.
		$damage_visible = ! empty( $analysis['damage_visible'] );
		$damage_areas   = isset( $analysis['damage_areas'] ) && is_array( $analysis['damage_areas'] )
			? $analysis['damage_areas']
			: array();

		$checks[] = array(
			'name'     => 'damage_visibility',
			'status'   => $damage_visible ? 'pass' : 'warning',
			'message'  => $damage_visible
				? sprintf(
					/* translators: %s: damage areas */
					__( 'Damage visible in: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_map( 'sanitize_text_field', $damage_areas ) )
				)
				: __( 'No clear damage visible in photos. Include close-up images of damaged areas for an accurate repair estimate.', 'mcp-ai-wpoos-pro' ),
			'critical' => false,
		);

		// Damage severity.
		$severity = isset( $analysis['damage_severity_estimate'] ) ? sanitize_key( $analysis['damage_severity_estimate'] ) : 'unknown';
		if ( 'unknown' !== $severity ) {
			$checks[] = array(
				'name'     => 'damage_severity',
				'status'   => 'info',
				'message'  => sprintf(
					/* translators: %s: severity level */
					__( 'Estimated damage severity: %s.', 'mcp-ai-wpoos-pro' ),
					ucfirst( $severity )
				),
				'critical' => false,
			);
		}

		// VIN visibility.
		$vin_readable = ! empty( $analysis['vin_readable'] );
		$checks[]     = array(
			'name'     => 'vin_visibility',
			'status'   => $vin_readable ? 'pass' : 'info',
			'message'  => $vin_readable
				? __( 'VIN plate is readable. This enables accurate part identification and pricing.', 'mcp-ai-wpoos-pro' )
				: __( 'VIN plate not detected. Including a VIN photo (windshield or door jamb) enables accurate part lookup and pricing.', 'mcp-ai-wpoos-pro' ),
			'critical' => false,
		);

		return $checks;
	}

	/**
	 * Build the final validation response with rating.
	 *
	 * @param bool   $is_valid      Whether the image set passed validation.
	 * @param array  $checks        All validation checks.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @param array  $failures      List of failure messages.
	 * @param array  $images_data   Array of image data arrays.
	 * @param array  $suggestions   AI suggestions.
	 * @return array Structured response.
	 */
	private function build_validation_response( $is_valid, array $checks, $estimate_type, array $failures, array $images_data, array $suggestions ) {
		$pass_count    = 0;
		$fail_count    = 0;
		$warning_count = 0;
		foreach ( $checks as $check ) {
			if ( 'pass' === $check['status'] ) {
				++$pass_count;
			} elseif ( 'fail' === $check['status'] ) {
				++$fail_count;
			} elseif ( 'warning' === $check['status'] ) {
				++$warning_count;
			}
		}
		$total_checks = count( $checks );

		$rating     = $this->calculate_rating( $checks, $estimate_type );
		$type_label = 'repair' === $estimate_type
			? __( 'Vehicle Repair Estimate', 'mcp-ai-wpoos-pro' )
			: __( 'Vehicle Cleaning Estimate', 'mcp-ai-wpoos-pro' );

		if ( $is_valid ) {
			$summary = sprintf(
				/* translators: %1$s: type, %2$d: pass, %3$d: total, %4$d: score, %5$s: grade */
				__( '✅ Images are suitable for %1$s. %2$d of %3$d checks passed. Rating: %4$d/100 (%5$s).', 'mcp-ai-wpoos-pro' ),
				$type_label,
				$pass_count,
				$total_checks,
				$rating['score'],
				$rating['grade'] . ' – ' . $rating['label']
			);
		} else {
			$summary = sprintf(
				/* translators: %1$s: type, %2$d: fails, %3$d: score, %4$s: grade */
				__( '❌ Images are not suitable for %1$s. %2$d issue(s) need resolution. Rating: %3$d/100 (%4$s).', 'mcp-ai-wpoos-pro' ),
				$type_label,
				$fail_count,
				$rating['score'],
				$rating['grade'] . ' – ' . $rating['label']
			);
		}

		if ( $warning_count > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: warning count */
				__( '%d warning(s) noted.', 'mcp-ai-wpoos-pro' ),
				$warning_count
			);
		}

		$image_urls = array();
		foreach ( $images_data as $img ) {
			if ( ! empty( $img['image_url'] ) ) {
				$image_urls[] = $img['image_url'];
			}
		}

		$response_data = array(
			'valid'         => $is_valid,
			'estimate_type' => $estimate_type,
			'type_label'    => $type_label,
			'summary'       => $summary,
			'rating'        => $rating,
			'checks'        => $checks,
			'statistics'    => array(
				'total'       => $total_checks,
				'passed'      => $pass_count,
				'failed'      => $fail_count,
				'warnings'    => $warning_count,
				'image_count' => count( $images_data ),
			),
			'image_urls'    => $image_urls,
		);

		if ( ! empty( $failures ) ) {
			$response_data['failures'] = $failures;
		}

		if ( ! empty( $suggestions ) ) {
			$response_data['suggestions'] = $suggestions;
		}

		return $this->format_chat_response( $response_data, $summary );
	}

	/**
	 * Calculate a weighted quality rating score (0-100) with letter grade.
	 *
	 * Weights differ by estimate type:
	 * - Cleaning: emphasis on full-vehicle visibility and size classification.
	 * - Repair:   emphasis on multi-angle coverage, damage visibility, and VIN.
	 *
	 * Based on industry standards from Qapter, Ravin AI, Google Vertex AI
	 * vehicle damage assessment, and CCC ONE estimating protocols.
	 *
	 * @param array  $checks        All validation checks.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @return array Rating with score, grade, label, and breakdown.
	 */
	private function calculate_rating( array $checks, $estimate_type ) {
		$weights          = 'repair' === $estimate_type ? self::REPAIR_WEIGHTS : self::CLEANING_WEIGHTS;
		$category_results = $this->categorize_checks( $checks, $estimate_type );
		$breakdown        = array();
		$total_score      = 0.0;

		foreach ( $weights as $category => $weight ) {
			$category_score  = isset( $category_results[ $category ] ) ? $category_results[ $category ] : 100.0;
			$weighted_points = ( $category_score / 100.0 ) * $weight;
			$total_score    += $weighted_points;

			$breakdown[ $category ] = array(
				'weight'         => $weight,
				'category_score' => round( $category_score, 1 ),
				'weighted_score' => round( $weighted_points, 1 ),
			);
		}

		$score = max( 0, min( 100, (int) round( $total_score ) ) );

		$grade = 'F';
		foreach ( self::RATING_THRESHOLDS as $letter => $threshold ) {
			if ( $score >= $threshold ) {
				$grade = $letter;
				break;
			}
		}

		return array(
			'score'     => $score,
			'grade'     => $grade,
			'label'     => isset( self::RATING_LABELS[ $grade ] ) ? self::RATING_LABELS[ $grade ] : 'Unknown',
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Categorize checks into scoring categories.
	 *
	 * @param array  $checks        All validation checks.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @return array<string, float> Category => average score (0-100).
	 */
	private function categorize_checks( array $checks, $estimate_type ) {
		$categories = array();

		foreach ( $checks as $check ) {
			$name     = isset( $check['name'] ) ? $check['name'] : '';
			$status   = isset( $check['status'] ) ? $check['status'] : 'info';
			$category = $this->check_name_to_category( $name, $estimate_type );

			if ( ! $category ) {
				continue;
			}

			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array( 'scores' => array() );
			}

			switch ( $status ) {
				case 'pass':
					$categories[ $category ]['scores'][] = 100;
					break;
				case 'warning':
					$categories[ $category ]['scores'][] = 50;
					break;
				case 'fail':
					$categories[ $category ]['scores'][] = 0;
					break;
				default:
					$categories[ $category ]['scores'][] = 100;
					break;
			}
		}

		$result = array();
		foreach ( $categories as $category => $data ) {
			$result[ $category ] = empty( $data['scores'] )
				? 100.0
				: array_sum( $data['scores'] ) / count( $data['scores'] );
		}

		return $result;
	}

	/**
	 * Map a check name to its scoring category.
	 *
	 * @param string $check_name    Check name.
	 * @param string $estimate_type 'cleaning' or 'repair'.
	 * @return string|null Category name or null.
	 */
	private function check_name_to_category( $check_name, $estimate_type ) {
		// Prefix-based mappings for per-image technical checks.
		$prefix_map = array(
			'file_format_' => 'technical_format',
			'file_size_'   => 'technical_format',
			'dimensions_'  => 'image_dimensions',
		);

		foreach ( $prefix_map as $prefix => $category ) {
			if ( 0 === strpos( $check_name, $prefix ) ) {
				return $category;
			}
		}

		// View coverage checks (repair).
		if ( 0 === strpos( $check_name, 'view_' ) || 'coverage_completeness' === $check_name ) {
			return 'coverage_completeness';
		}

		// Recommended views are informational, don't penalize score.
		if ( 0 === strpos( $check_name, 'recommended_' ) ) {
			return null;
		}

		// Exact-match mappings.
		$exact_map = array(
			'vehicle_detected'     => 'vehicle_detected',
			'single_vehicle'       => 'single_vehicle',
			'full_vehicle_visible' => 'full_vehicle_visible',
			'size_classification'  => 'full_vehicle_visible',
			'condition_visibility' => 'condition_visibility',
			'damage_visibility'    => 'damage_visibility',
			'damage_severity'      => 'damage_visibility',
			'vin_visibility'       => 'vin_visibility',
			'lighting'             => 'lighting',
			'sharpness'            => 'sharpness',
			'obstructions'         => 'lighting',
		);

		return isset( $exact_map[ $check_name ] ) ? $exact_map[ $check_name ] : null;
	}
}
