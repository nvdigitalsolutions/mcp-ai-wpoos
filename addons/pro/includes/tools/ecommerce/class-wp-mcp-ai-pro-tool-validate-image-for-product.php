<?php
/**
 * Tool for validating user images before product actualization.
 *
 * Analyzes user-provided images (profile pictures or full body shots) to verify
 * they meet the requirements for virtual product placement (accessories such as
 * watches, jewelry, glasses, etc.) using AI vision analysis.
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
 * Validates user images for suitability in product actualization / virtual try-on.
 *
 * Uses a two-pass approach:
 * 1. Technical validation - file format, dimensions, file size.
 * 2. AI vision analysis  - body part visibility, lighting, obstructions, pose.
 *
 * Product type categories and their required body parts:
 * - watch / bracelet  → wrist + hand/forearm visible
 * - ring              → hand + fingers visible
 * - earring           → ear(s) + jawline visible
 * - necklace / chain  → neck + upper chest visible
 * - glasses / eyewear → face, both eyes visible
 * - hat / headwear    → head + face visible
 * - bag / purse       → upper body (shoulder/arm) visible
 * - general           → any clear person image
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Validate_Image_For_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Rating score thresholds for letter grades.
	 *
	 * Derived from industry-standard virtual try-on quality benchmarks:
	 * - A (90–100): Excellent – image exceeds all requirements, ideal for product placement.
	 * - B (75–89):  Good – meets requirements, minor improvements possible.
	 * - C (60–74):  Fair – usable but product placement quality may be reduced.
	 * - D (40–59):  Poor – significant issues, recommend re-upload.
	 * - F (0–39):   Fail – image cannot be used for product placement.
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
	 * Scoring weights for each check category (must sum to 100).
	 *
	 * Based on industry-standard virtual try-on quality frameworks:
	 * - Body part visibility is the most critical factor (cannot place product without it).
	 * - Technical quality (format, size, resolution) gates everything else.
	 * - Lighting, sharpness, pose, and obstructions affect placement realism.
	 * - Single person and no conflicting accessories are nice-to-haves.
	 *
	 * @var array<string, int>
	 */
	const SCORING_WEIGHTS = array(
		'required_body_parts'  => 35,
		'technical_format'     => 15,
		'image_dimensions'     => 10,
		'lighting'             => 10,
		'sharpness'            => 8,
		'pose'                 => 10,
		'obstructions'         => 5,
		'single_person'        => 3,
		'existing_accessories' => 2,
		'optional_body_parts'  => 2,
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
	const MIN_DIMENSION = 512;

	/**
	 * Recommended minimum width/height in pixels for best results.
	 *
	 * @var int
	 */
	const RECOMMENDED_DIMENSION = 1024;

	/**
	 * Maximum file size in bytes (20 MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 20971520;

	/**
	 * Product types mapped to their required body-part groups.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const PRODUCT_TYPE_REQUIREMENTS = array(
		'watch'    => array(
			'label'          => 'Watch',
			'required_parts' => array( 'wrist', 'hand' ),
			'optional_parts' => array( 'forearm' ),
			'preferred_pose' => 'Wrist facing camera, hand relaxed or slightly raised.',
			'min_dimension'  => 1024,
		),
		'bracelet' => array(
			'label'          => 'Bracelet',
			'required_parts' => array( 'wrist', 'hand' ),
			'optional_parts' => array( 'forearm' ),
			'preferred_pose' => 'Wrist facing camera with clear view of wrist area.',
			'min_dimension'  => 1024,
		),
		'ring'     => array(
			'label'          => 'Ring',
			'required_parts' => array( 'hand', 'fingers' ),
			'optional_parts' => array( 'wrist' ),
			'preferred_pose' => 'Hand open or naturally relaxed, fingers clearly visible.',
			'min_dimension'  => 1024,
		),
		'earring'  => array(
			'label'          => 'Earring',
			'required_parts' => array( 'ear', 'face' ),
			'optional_parts' => array( 'jawline', 'neck' ),
			'preferred_pose' => 'Ear fully exposed, hair tucked behind ear. Side or three-quarter profile.',
			'min_dimension'  => 512,
		),
		'necklace' => array(
			'label'          => 'Necklace / Chain',
			'required_parts' => array( 'neck', 'upper_chest' ),
			'optional_parts' => array( 'face', 'shoulders' ),
			'preferred_pose' => 'Front-facing with neck and upper chest clearly visible. No high-collar clothing.',
			'min_dimension'  => 1024,
		),
		'glasses'  => array(
			'label'          => 'Glasses / Eyewear',
			'required_parts' => array( 'face', 'eyes', 'ears' ),
			'optional_parts' => array( 'forehead' ),
			'preferred_pose' => 'Front-facing with both eyes and ears visible. No existing glasses or sunglasses.',
			'min_dimension'  => 512,
		),
		'hat'      => array(
			'label'          => 'Hat / Headwear',
			'required_parts' => array( 'head', 'face' ),
			'optional_parts' => array( 'forehead', 'hair' ),
			'preferred_pose' => 'Front-facing or slight angle, head and face visible. No existing hat or headwear.',
			'min_dimension'  => 512,
		),
		'bag'      => array(
			'label'          => 'Bag / Purse',
			'required_parts' => array( 'shoulder', 'arm' ),
			'optional_parts' => array( 'upper_body', 'hand' ),
			'preferred_pose' => 'Upper body visible with at least one arm and shoulder clear. Half or full body shot.',
			'min_dimension'  => 1024,
		),
		'general'  => array(
			'label'          => 'General Accessory',
			'required_parts' => array( 'person' ),
			'optional_parts' => array(),
			'preferred_pose' => 'Clear image of a person with the relevant body area visible.',
			'min_dimension'  => 512,
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_image_for_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Image for Product Placement', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates a user-provided image (profile picture or full body shot) to ensure it meets the requirements for virtual product placement / try-on. Checks image quality, dimensions, lighting, and verifies that the necessary body parts are visible for the selected product type (watch, ring, earring, necklace, glasses, hat, bag, bracelet, or general accessory). Uses OpenAI Vision to perform intelligent analysis and returns actionable feedback.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the user image. Also accepts a public image URL (https://...) or a file_id string from chat file uploads (e.g., "file-abc123").', 'mcp-ai-wpoos-pro' ),
				),
				'file_id'       => array(
					'type'        => 'string',
					'description' => __( 'OpenAI file ID from a chat upload. Alternative to attachment_id.', 'mcp-ai-wpoos-pro' ),
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'Direct URL to the user image. Alternative to attachment_id or file_id.', 'mcp-ai-wpoos-pro' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'Direct URL to the user image. Alternative to attachment_id or file_id.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'  => array(
					'type'        => 'string',
					'enum'        => array( 'watch', 'bracelet', 'ring', 'earring', 'necklace', 'glasses', 'hat', 'bag', 'general' ),
					'default'     => 'general',
					'description' => __( 'The type of accessory product to be placed on the user image. Determines which body parts must be visible. Options: watch, bracelet, ring, earring, necklace, glasses, hat, bag, general.', 'mcp-ai-wpoos-pro' ),
				),
				'strict_mode'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, all recommended checks must pass for overall validation success. When false (default), only critical checks must pass.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'product_type' ),
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
	 * Execute the image validation tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Structured validation results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to validate images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to validate images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Validate product type.
		$product_type = isset( $arguments['product_type'] ) ? sanitize_key( $arguments['product_type'] ) : 'general';
		if ( ! isset( self::PRODUCT_TYPE_REQUIREMENTS[ $product_type ] ) ) {
			$product_type = 'general';
		}

		$strict_mode = ! empty( $arguments['strict_mode'] );

		// Resolve the image input.
		$image_data = $this->resolve_image_input( $arguments );
		if ( is_wp_error( $image_data ) ) {
			return $image_data;
		}

		$checks = array();

		// Pass 1: Technical validation.
		$technical_result = $this->validate_technical_requirements( $image_data, $product_type );
		$checks           = array_merge( $checks, $technical_result['checks'] );

		// If technical validation has critical failures, return early.
		if ( ! empty( $technical_result['critical_failures'] ) ) {
			return $this->build_validation_response(
				false,
				$checks,
				$product_type,
				$technical_result['critical_failures'],
				$image_data
			);
		}

		// Pass 2: AI vision analysis.
		$vision_result = $this->validate_with_vision_ai( $image_data, $product_type );
		if ( is_wp_error( $vision_result ) ) {
			return $vision_result;
		}
		$checks = array_merge( $checks, $vision_result['checks'] );

		// Determine overall pass/fail.
		$critical_failures = array();
		$warnings          = array();
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] && ! empty( $check['critical'] ) ) {
				$critical_failures[] = $check['message'];
			} elseif ( 'fail' === $check['status'] || 'warning' === $check['status'] ) {
				$warnings[] = $check['message'];
			}
		}

		$is_valid = empty( $critical_failures );

		// In strict mode, warnings also cause failure.
		if ( $strict_mode && ! empty( $warnings ) ) {
			$is_valid = false;
		}

		return $this->build_validation_response(
			$is_valid,
			$checks,
			$product_type,
			array_merge( $critical_failures, $strict_mode ? $warnings : array() ),
			$image_data
		);
	}

	/**
	 * Resolve image input from various argument formats.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Image data array or error.
	 */
	private function resolve_image_input( array $arguments ) {
		$image_url     = '';
		$attachment_id = 0;
		$file_path     = '';
		$mime_type     = '';
		$width         = 0;
		$height        = 0;
		$file_size     = 0;

		// Try the trait-based resolver first.
		$resolved = $this->resolve_attachment_id( $arguments );

		if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
			// Remote URL case.
			$image_url = esc_url_raw( $resolved['url'] );
		} elseif ( is_wp_error( $resolved ) ) {
			// Trait couldn't resolve - try direct image_url / url parameters.
			$direct_url = '';
			if ( ! empty( $arguments['image_url'] ) ) {
				$direct_url = esc_url_raw( $arguments['image_url'] );
			} elseif ( ! empty( $arguments['url'] ) ) {
				$direct_url = esc_url_raw( $arguments['url'] );
			}

			if ( ! empty( $direct_url ) ) {
				$image_url = $direct_url;
			} else {
				return new WP_Error(
					'wp_mcp_ai_missing_image',
					__( 'You must provide an image via attachment_id, file_id, url, or image_url.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( $resolved > 0 ) {
			$attachment_id = absint( $resolved );
			$image_url     = wp_get_attachment_url( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			$mime_type     = get_post_mime_type( $attachment_id );

			if ( ! $image_url ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					__( 'Could not get URL for attachment.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			// Get dimensions from attachment metadata.
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( $metadata ) {
				$width  = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
				$height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
			}

			// Get file size.
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size = filesize( $file_path );
			}
		}

		// If we only have a URL, try to fetch headers for metadata.
		if ( ! empty( $image_url ) && 0 === $attachment_id ) {
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
	 * @param array  $image_data   Image data array.
	 * @param string $product_type Product type slug.
	 * @return array Array with 'checks' and 'critical_failures'.
	 */
	private function validate_technical_requirements( array $image_data, $product_type ) {
		$checks            = array();
		$critical_failures = array();
		$type_config       = self::PRODUCT_TYPE_REQUIREMENTS[ $product_type ];

		// Check 1: File format.
		if ( ! empty( $image_data['mime_type'] ) ) {
			// Normalize MIME type (strip parameters like charset).
			$mime = strtolower( trim( explode( ';', $image_data['mime_type'] )[0] ) );
			if ( in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
				$checks[] = array(
					'name'     => 'file_format',
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %s: MIME type */
						__( 'File format is supported (%s).', 'mcp-ai-wpoos-pro' ),
						$mime
					),
					'critical' => true,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$s: MIME type, %2$s: list of allowed types */
					__( 'Unsupported file format (%1$s). Allowed formats: %2$s.', 'mcp-ai-wpoos-pro' ),
					$mime,
					implode( ', ', self::ALLOWED_MIME_TYPES )
				);
				$checks[]            = array(
					'name'     => 'file_format',
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
					'name'     => 'file_size',
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %s: file size in MB */
						__( 'File size is acceptable (%.1f MB).', 'mcp-ai-wpoos-pro' ),
						$image_data['file_size'] / 1048576
					),
					'critical' => true,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$s: file size, %2$s: max size */
					__( 'File size (%1$.1f MB) exceeds the maximum allowed (%2$.0f MB). Please compress or resize the image.', 'mcp-ai-wpoos-pro' ),
					$image_data['file_size'] / 1048576,
					self::MAX_FILE_SIZE / 1048576
				);
				$checks[]            = array(
					'name'     => 'file_size',
					'status'   => 'fail',
					'message'  => $msg,
					'critical' => true,
				);
				$critical_failures[] = $msg;
			}
		}

		// Check 3: Image dimensions.
		$min_required = isset( $type_config['min_dimension'] ) ? $type_config['min_dimension'] : self::MIN_DIMENSION;
		if ( $image_data['width'] > 0 && $image_data['height'] > 0 ) {
			$shortest_side = min( $image_data['width'], $image_data['height'] );

			if ( $shortest_side >= $min_required ) {
				$checks[] = array(
					'name'     => 'dimensions',
					'status'   => 'pass',
					'message'  => sprintf(
						/* translators: %1$d: width, %2$d: height */
						__( 'Image dimensions are sufficient (%1$d × %2$d px).', 'mcp-ai-wpoos-pro' ),
						$image_data['width'],
						$image_data['height']
					),
					'critical' => true,
				);
			} elseif ( $shortest_side >= self::MIN_DIMENSION ) {
				$checks[] = array(
					'name'     => 'dimensions',
					'status'   => 'warning',
					'message'  => sprintf(
						/* translators: %1$d: width, %2$d: height, %3$d: recommended min */
						__( 'Image dimensions (%1$d × %2$d px) are below the recommended minimum of %3$d px for %4$s placement. Results may be suboptimal.', 'mcp-ai-wpoos-pro' ),
						$image_data['width'],
						$image_data['height'],
						$min_required,
						$type_config['label']
					),
					'critical' => false,
				);
			} else {
				$msg = sprintf(
					/* translators: %1$d: width, %2$d: height, %3$d: min dimension */
					__( 'Image dimensions (%1$d × %2$d px) are too small. Minimum required is %3$d px on the shortest side.', 'mcp-ai-wpoos-pro' ),
					$image_data['width'],
					$image_data['height'],
					self::MIN_DIMENSION
				);
				$checks[]            = array(
					'name'     => 'dimensions',
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
	 * Validate image using AI vision analysis.
	 *
	 * Uses OpenAI Vision API to analyze the image for body part visibility,
	 * lighting quality, obstructions, and overall suitability.
	 *
	 * @param array  $image_data   Image data array.
	 * @param string $product_type Product type slug.
	 * @return array|WP_Error Array with 'checks' key, or WP_Error.
	 */
	private function validate_with_vision_ai( array $image_data, $product_type ) {
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
				__( 'OpenAI API key is not configured. Image validation requires OpenAI Vision capabilities.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$type_config = self::PRODUCT_TYPE_REQUIREMENTS[ $product_type ];
		$prompt      = $this->build_vision_prompt( $product_type, $type_config );

		// Build the message content.
		$content = array(
			array(
				'type' => 'text',
				'text' => $prompt,
			),
		);

		if ( ! empty( $image_data['image_url'] ) ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url'    => $image_data['image_url'],
					'detail' => 'high',
				),
			);
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
				'model'                 => 'gpt-4.1',
				'max_completion_tokens' => 1024,
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

		$raw_content     = trim( $response['choices'][0]['message']['content'] );
		$vision_analysis = json_decode( $raw_content, true );

		if ( ! is_array( $vision_analysis ) ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				__( 'Could not parse Vision API response.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'      => 500,
					'raw_content' => $raw_content,
				)
			);
		}

		return $this->parse_vision_analysis( $vision_analysis, $product_type, $type_config );
	}

	/**
	 * Build the AI vision analysis prompt for the given product type.
	 *
	 * @param string $product_type Product type slug.
	 * @param array  $type_config  Product type configuration.
	 * @return string The prompt text.
	 */
	private function build_vision_prompt( $product_type, array $type_config ) {
		$required_parts = implode( ', ', $type_config['required_parts'] );
		$optional_parts = ! empty( $type_config['optional_parts'] ) ? implode( ', ', $type_config['optional_parts'] ) : 'none';

		$prompt  = 'You are an image validation assistant for a virtual product try-on system. ';
		$prompt .= 'Analyze this user photo to determine if it is suitable for placing a ' . esc_html( $type_config['label'] ) . ' product on the person. ';
		$prompt .= "\n\nRequired visible body parts: " . esc_html( $required_parts );
		$prompt .= "\nOptional (helpful) body parts: " . esc_html( $optional_parts );
		$prompt .= "\nPreferred pose: " . esc_html( $type_config['preferred_pose'] );
		$prompt .= "\n\nAnalyze the image and respond with a JSON object containing these fields:";
		$prompt .= "\n\n{";
		$prompt .= "\n  \"person_detected\": boolean,";
		$prompt .= "\n  \"person_count\": integer,";
		$prompt .= "\n  \"required_parts_visible\": {";

		foreach ( $type_config['required_parts'] as $part ) {
			$prompt .= "\n    \"" . esc_html( $part ) . '": boolean,';
		}

		$prompt  = rtrim( $prompt, ',' );
		$prompt .= "\n  },";
		$prompt .= "\n  \"optional_parts_visible\": {";

		if ( ! empty( $type_config['optional_parts'] ) ) {
			foreach ( $type_config['optional_parts'] as $part ) {
				$prompt .= "\n    \"" . esc_html( $part ) . '": boolean,';
			}
			$prompt = rtrim( $prompt, ',' );
		}

		$prompt .= "\n  },";
		$prompt .= "\n  \"obstructions\": [string],";
		$prompt .= "\n  \"lighting_quality\": \"good\" | \"acceptable\" | \"poor\",";
		$prompt .= "\n  \"image_sharpness\": \"sharp\" | \"acceptable\" | \"blurry\",";
		$prompt .= "\n  \"background_complexity\": \"simple\" | \"moderate\" | \"complex\",";
		$prompt .= "\n  \"existing_accessories\": [string],";
		$prompt .= "\n  \"pose_suitable\": boolean,";
		$prompt .= "\n  \"overall_suitable\": boolean,";
		$prompt .= "\n  \"suggestions\": [string]";
		$prompt .= "\n}";
		$prompt .= "\n\nFor \"obstructions\", list anything blocking the required body parts (e.g., \"hair covering ear\", \"sleeve covering wrist\", \"hat blocking head\").";
		$prompt .= "\nFor \"existing_accessories\", list any accessories already worn on the target body parts that might conflict (e.g., \"watch on left wrist\", \"earrings\", \"glasses\").";
		$prompt .= "\nFor \"suggestions\", provide actionable advice to improve the image for product placement if needed.";
		$prompt .= "\nRespond ONLY with valid JSON. No additional text.";

		return $prompt;
	}

	/**
	 * Parse the AI vision analysis response into structured checks.
	 *
	 * @param array  $analysis     Parsed JSON from the Vision API.
	 * @param string $product_type Product type slug.
	 * @param array  $type_config  Product type configuration.
	 * @return array Array with 'checks' key.
	 */
	private function parse_vision_analysis( array $analysis, $product_type, array $type_config ) {
		$checks = array();

		// Check: Person detected.
		$person_detected = ! empty( $analysis['person_detected'] );
		$checks[]        = array(
			'name'     => 'person_detected',
			'status'   => $person_detected ? 'pass' : 'fail',
			'message'  => $person_detected
				? __( 'A person was detected in the image.', 'mcp-ai-wpoos-pro' )
				: __( 'No person was detected in the image. Please provide a photo that includes a person.', 'mcp-ai-wpoos-pro' ),
			'critical' => true,
		);

		// Check: Single person (multiple people cause placement confusion).
		$person_count = isset( $analysis['person_count'] ) ? absint( $analysis['person_count'] ) : 0;
		if ( $person_count > 1 ) {
			$checks[] = array(
				'name'     => 'single_person',
				'status'   => 'warning',
				'message'  => sprintf(
					/* translators: %d: number of people */
					__( 'Multiple people detected (%d). For best results, provide an image with a single person to avoid placement ambiguity.', 'mcp-ai-wpoos-pro' ),
					$person_count
				),
				'critical' => false,
			);
		} elseif ( 1 === $person_count ) {
			$checks[] = array(
				'name'     => 'single_person',
				'status'   => 'pass',
				'message'  => __( 'Single person detected (ideal for product placement).', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Check: Required body parts.
		$required_parts_data = isset( $analysis['required_parts_visible'] ) ? $analysis['required_parts_visible'] : array();
		$missing_parts       = array();

		foreach ( $type_config['required_parts'] as $part ) {
			$visible  = ! empty( $required_parts_data[ $part ] );
			$checks[] = array(
				'name'     => 'body_part_' . $part,
				'status'   => $visible ? 'pass' : 'fail',
				'message'  => $visible
					? sprintf(
						/* translators: %s: body part name */
						__( 'Required body part "%s" is visible.', 'mcp-ai-wpoos-pro' ),
						$part
					)
					: sprintf(
						/* translators: %s: body part name */
						__( 'Required body part "%s" is not visible or not clearly identifiable.', 'mcp-ai-wpoos-pro' ),
						$part
					),
				'critical' => true,
			);

			if ( ! $visible ) {
				$missing_parts[] = $part;
			}
		}

		// Check: Optional body parts.
		$optional_parts_data = isset( $analysis['optional_parts_visible'] ) ? $analysis['optional_parts_visible'] : array();
		foreach ( $type_config['optional_parts'] as $part ) {
			$visible  = ! empty( $optional_parts_data[ $part ] );
			$checks[] = array(
				'name'     => 'optional_part_' . $part,
				'status'   => $visible ? 'pass' : 'info',
				'message'  => $visible
					? sprintf(
						/* translators: %s: body part name */
						__( 'Optional body part "%s" is visible (improves placement quality).', 'mcp-ai-wpoos-pro' ),
						$part
					)
					: sprintf(
						/* translators: %s: body part name */
						__( 'Optional body part "%s" is not visible (product placement can still proceed).', 'mcp-ai-wpoos-pro' ),
						$part
					),
				'critical' => false,
			);
		}

		// Check: Obstructions.
		$obstructions = isset( $analysis['obstructions'] ) && is_array( $analysis['obstructions'] ) ? $analysis['obstructions'] : array();
		if ( ! empty( $obstructions ) ) {
			$checks[] = array(
				'name'     => 'obstructions',
				'status'   => 'warning',
				'message'  => sprintf(
					/* translators: %s: list of obstructions */
					__( 'Obstructions detected: %s. These may affect product placement accuracy.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_map( 'sanitize_text_field', $obstructions ) )
				),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'obstructions',
				'status'   => 'pass',
				'message'  => __( 'No obstructions detected on the target body area.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Check: Lighting quality.
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
				'message'  => __( 'Lighting quality is acceptable. Better lighting would improve results.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'lighting',
				'status'   => 'warning',
				'message'  => __( 'Poor lighting detected. The image is too dark, overexposed, or has strong backlighting. Product placement may not look natural.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Check: Image sharpness.
		$sharpness = isset( $analysis['image_sharpness'] ) ? sanitize_key( $analysis['image_sharpness'] ) : 'acceptable';
		if ( 'blurry' === $sharpness ) {
			$checks[] = array(
				'name'     => 'sharpness',
				'status'   => 'warning',
				'message'  => __( 'Image appears blurry. A sharper image will produce better product placement results.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'sharpness',
				'status'   => 'pass',
				'message'  => 'sharp' === $sharpness
					? __( 'Image is sharp and clear.', 'mcp-ai-wpoos-pro' )
					: __( 'Image sharpness is acceptable.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Check: Existing accessories on target area.
		$existing = isset( $analysis['existing_accessories'] ) && is_array( $analysis['existing_accessories'] ) ? $analysis['existing_accessories'] : array();
		if ( ! empty( $existing ) ) {
			$checks[] = array(
				'name'     => 'existing_accessories',
				'status'   => 'warning',
				'message'  => sprintf(
					/* translators: %s: list of existing accessories */
					__( 'Existing accessories detected on the target area: %s. These may conflict with product placement. Consider removing them or using a different photo.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', array_map( 'sanitize_text_field', $existing ) )
				),
				'critical' => false,
			);
		} else {
			$checks[] = array(
				'name'     => 'existing_accessories',
				'status'   => 'pass',
				'message'  => __( 'No conflicting accessories detected on the target body area.', 'mcp-ai-wpoos-pro' ),
				'critical' => false,
			);
		}

		// Check: Pose suitability.
		$pose_suitable = ! empty( $analysis['pose_suitable'] );
		$checks[]      = array(
			'name'     => 'pose',
			'status'   => $pose_suitable ? 'pass' : 'warning',
			'message'  => $pose_suitable
				? __( 'Pose is suitable for product placement.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: %s: preferred pose description */
					__( 'Pose could be improved. Preferred: %s', 'mcp-ai-wpoos-pro' ),
					$type_config['preferred_pose']
				),
			'critical' => false,
		);

		// Include raw suggestions from AI.
		$suggestions = isset( $analysis['suggestions'] ) && is_array( $analysis['suggestions'] ) ? $analysis['suggestions'] : array();

		return array(
			'checks'       => $checks,
			'suggestions'  => array_map( 'sanitize_text_field', $suggestions ),
			'raw_analysis' => $analysis,
		);
	}

	/**
	 * Build the final validation response.
	 *
	 * @param bool   $is_valid    Whether the image passed validation.
	 * @param array  $checks      All validation checks.
	 * @param string $product_type Product type slug.
	 * @param array  $failures    List of failure messages.
	 * @param array  $image_data  Image data array.
	 * @return array Structured response.
	 */
	private function build_validation_response( $is_valid, array $checks, $product_type, array $failures, array $image_data ) {
		$type_config = self::PRODUCT_TYPE_REQUIREMENTS[ $product_type ];

		// Count by status.
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

		// Calculate the industry-standard rating.
		$rating = $this->calculate_rating( $checks, $product_type );

		// Build summary message.
		if ( $is_valid ) {
			$summary = sprintf(
				/* translators: %1$s: product type label, %2$d: pass count, %3$d: total checks, %4$d: score, %5$s: grade */
				__( '✅ Image is suitable for %1$s placement. %2$d of %3$d checks passed. Rating: %4$d/100 (%5$s).', 'mcp-ai-wpoos-pro' ),
				$type_config['label'],
				$pass_count,
				$total_checks,
				$rating['score'],
				$rating['grade'] . ' – ' . $rating['label']
			);
		} else {
			$summary = sprintf(
				/* translators: %1$s: product type label, %2$d: fail count, %3$d: score, %4$s: grade */
				__( '❌ Image is not suitable for %1$s placement. %2$d issue(s) need to be resolved. Rating: %3$d/100 (%4$s).', 'mcp-ai-wpoos-pro' ),
				$type_config['label'],
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

		$response_data = array(
			'valid'          => $is_valid,
			'product_type'   => $product_type,
			'product_label'  => $type_config['label'],
			'summary'        => $summary,
			'rating'         => $rating,
			'checks'         => $checks,
			'statistics'     => array(
				'total'    => $total_checks,
				'passed'   => $pass_count,
				'failed'   => $fail_count,
				'warnings' => $warning_count,
			),
			'preferred_pose' => $type_config['preferred_pose'],
			'image_url'      => $image_data['image_url'],
		);

		if ( ! empty( $failures ) ) {
			$response_data['failures'] = $failures;
		}

		return $this->format_chat_response( $response_data, $summary );
	}

	/**
	 * Calculate a weighted quality rating score (0–100) with letter grade.
	 *
	 * Scoring is based on industry-standard virtual try-on quality benchmarks
	 * from providers like Google Merchant Center, Camweara, Tangiblee, and
	 * research papers (GlamTry: Advancing Virtual Try-On for High-End Accessories).
	 *
	 * Weight distribution (must total 100):
	 * - Required body parts  (35) – Cannot place product without visibility.
	 * - Technical format      (15) – File format and file size compliance.
	 * - Image dimensions      (10) – Resolution affects detail quality.
	 * - Lighting quality      (10) – Affects realism of product integration.
	 * - Image sharpness        (8) – Blurry images degrade output quality.
	 * - Pose suitability      (10) – Correct pose enables accurate placement.
	 * - Obstructions           (5) – Blocking items reduce placement accuracy.
	 * - Single person          (3) – Multiple people cause placement ambiguity.
	 * - Existing accessories   (2) – Conflicts with product overlap.
	 * - Optional body parts    (2) – Bonus for additional context.
	 *
	 * Each category scores 0–100% of its weight:
	 * - pass    = 100% of weight
	 * - warning = 50% of weight
	 * - fail    = 0% of weight
	 * - info    = 100% of weight (informational only)
	 *
	 * @param array  $checks      All validation checks.
	 * @param string $product_type Product type slug.
	 * @return array Rating data with score, grade, label, and breakdown.
	 */
	private function calculate_rating( array $checks, $product_type ) {
		$type_config = self::PRODUCT_TYPE_REQUIREMENTS[ $product_type ];
		$breakdown   = array();
		$total_score = 0.0;

		// Map each check to its scoring category and compute category scores.
		$category_results = $this->categorize_checks( $checks, $type_config );

		foreach ( self::SCORING_WEIGHTS as $category => $weight ) {
			if ( ! isset( $category_results[ $category ] ) ) {
				// Category had no applicable checks – award full points (not penalized).
				$category_score = 100.0;
			} else {
				$category_score = $category_results[ $category ];
			}

			$weighted_points = ( $category_score / 100.0 ) * $weight;
			$total_score    += $weighted_points;

			$breakdown[ $category ] = array(
				'weight'         => $weight,
				'category_score' => round( $category_score, 1 ),
				'weighted_score' => round( $weighted_points, 1 ),
			);
		}

		$score = (int) round( $total_score );
		$score = max( 0, min( 100, $score ) );

		// Determine letter grade.
		$grade = 'F';
		foreach ( self::RATING_THRESHOLDS as $letter => $threshold ) {
			if ( $score >= $threshold ) {
				$grade = $letter;
				break;
			}
		}

		$label = isset( self::RATING_LABELS[ $grade ] ) ? self::RATING_LABELS[ $grade ] : 'Unknown';

		return array(
			'score'     => $score,
			'grade'     => $grade,
			'label'     => $label,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Categorize checks into scoring categories and compute per-category scores.
	 *
	 * @param array $checks     All validation checks.
	 * @param array $type_config Product type configuration.
	 * @return array<string, float> Category => score (0–100).
	 */
	private function categorize_checks( array $checks, array $type_config ) {
		$categories = array();

		foreach ( $checks as $check ) {
			$name     = isset( $check['name'] ) ? $check['name'] : '';
			$status   = isset( $check['status'] ) ? $check['status'] : 'info';
			$category = $this->check_name_to_category( $name, $type_config );

			if ( ! $category ) {
				continue;
			}

			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array(
					'scores' => array(),
				);
			}

			// Convert status to a 0–100 score.
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
				case 'info':
				default:
					$categories[ $category ]['scores'][] = 100;
					break;
			}
		}

		// Average scores per category.
		$result = array();
		foreach ( $categories as $category => $data ) {
			if ( empty( $data['scores'] ) ) {
				$result[ $category ] = 100.0;
			} else {
				$result[ $category ] = array_sum( $data['scores'] ) / count( $data['scores'] );
			}
		}

		return $result;
	}

	/**
	 * Map a check name to its scoring category.
	 *
	 * @param string $check_name Check name.
	 * @param array  $type_config Product type configuration.
	 * @return string|null Category name or null if unmapped.
	 */
	private function check_name_to_category( $check_name, array $type_config ) {
		// Prefix-based mappings for dynamic check names (body parts).
		$prefix_map = array(
			'body_part_'     => 'required_body_parts',
			'optional_part_' => 'optional_body_parts',
		);

		foreach ( $prefix_map as $prefix => $category ) {
			if ( 0 === strpos( $check_name, $prefix ) ) {
				return $category;
			}
		}

		// Exact-match mappings for static check names.
		$exact_map = array(
			'person_detected'      => 'required_body_parts',
			'file_format'          => 'technical_format',
			'file_size'            => 'technical_format',
			'dimensions'           => 'image_dimensions',
			'lighting'             => 'lighting',
			'sharpness'            => 'sharpness',
			'pose'                 => 'pose',
			'obstructions'         => 'obstructions',
			'single_person'        => 'single_person',
			'existing_accessories' => 'existing_accessories',
		);

		return isset( $exact_map[ $check_name ] ) ? $exact_map[ $check_name ] : null;
	}
}
