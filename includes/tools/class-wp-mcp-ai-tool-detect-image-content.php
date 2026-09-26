<?php
/**
 * Tool for classic (non-LLM) Google Cloud Vision content detection.
 *
 * Deterministic labels, OCR text, web entities, logos, landmarks, faces, and
 * safe-search signals — the cheap third rung of the non-LLM image
 * identification ladder. No vision language model is involved.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cloud-vision-client.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool that detects image content via the classic
 * Google Cloud Vision detection features.
 *
 * Requires a Google Cloud API key with the Cloud Vision API enabled. The
 * plugin reuses the configured Gemini API key, which can be overridden via
 * the `wp_mcp_ai_vision_api_key` filter. When no key is configured the tool
 * short-circuits before any HTTP request so image data never leaves the
 * server without credentials.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Detect_Image_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Map tool-facing feature names to Cloud Vision feature types.
	 *
	 * @var array
	 */
	const FEATURE_TYPES = array(
		'labels'       => 'LABEL_DETECTION',
		'text'         => 'TEXT_DETECTION',
		'web_entities' => 'WEB_DETECTION',
		'logos'        => 'LOGO_DETECTION',
		'landmarks'    => 'LANDMARK_DETECTION',
		'faces'        => 'FACE_DETECTION',
		'safe_search'  => 'SAFE_SEARCH_DETECTION',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'detect_image_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Detect Image Content', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects image content using classic (non-LLM) Google Cloud Vision features: labels, visible text, web entities, logos, landmarks, faces, and safe-search signals. Deterministic, cheap, and schema-stable — prefer this over analyze_image for identification questions. Requires a Google Cloud API key (reuses the Gemini key).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Identifying what an image shows (objects, text, logos, landmarks) or checking content safety, without spending vision-LLM tokens. The normalized output is deterministic and safe to compare across calls.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Open-ended visual questions or free-form image description; use analyze_image for that. Product matching against a catalog; use vision_product_search. Object bounding boxes with counts; use vision_object_localization.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'vision_object_localization', 'vision_product_search', 'analyze_image', 'identify_image' ),
			'notes'           => __( 'Requires a Google Cloud API key (reuses the Gemini key). Image bytes or URL are sent to Google Cloud Vision. Missing credentials short-circuit before any request.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image to analyze. Must be publicly reachable if a URL is used.', 'mcp-ai-wpoos' ),
				),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative to image_url.', 'mcp-ai-wpoos' ),
				),
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID containing the image. Alternative to image_url or image_content.', 'mcp-ai-wpoos' ),
				),
				'features'      => array(
					'type'        => 'array',
					'description' => __( 'Detection features to run. Defaults to all features.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'labels', 'text', 'web_entities', 'logos', 'landmarks', 'faces', 'safe_search' ),
					),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Maximum number of results per feature (1-100). Default 10.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_detect_image_content_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_forbidden',
				__( 'You do not have permission to use Detect Image Content.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$image_url     = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$image_content = isset( $arguments['image_content'] ) ? sanitize_text_field( $arguments['image_content'] ) : '';

		// Resolve an attachment to its local URL when provided.
		if ( empty( $image_url ) && empty( $image_content ) && ! empty( $arguments['attachment_id'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments, 'attachment_id' );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( is_int( $resolved ) && $resolved > 0 ) {
				$image_url = (string) wp_get_attachment_url( $resolved );
			} elseif ( is_array( $resolved ) && ! empty( $resolved['url'] ) ) {
				$image_url = esc_url_raw( $resolved['url'] );
			}
		}

		// Validate that at least one image input is present.
		if ( empty( $image_url ) && empty( $image_content ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_missing_image',
				__( 'One of image_url, image_content, or attachment_id must be provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize the requested features and map them to Cloud Vision types.
		$requested = isset( $arguments['features'] ) && is_array( $arguments['features'] ) ? $arguments['features'] : array_keys( self::FEATURE_TYPES );
		$requested = array_map( 'sanitize_key', $requested );
		$features  = array();

		foreach ( $requested as $feature_name ) {
			if ( isset( self::FEATURE_TYPES[ $feature_name ] ) ) {
				$features[] = array(
					'type'       => self::FEATURE_TYPES[ $feature_name ],
					'maxResults' => isset( $arguments['max_results'] ) ? min( 100, max( 1, absint( $arguments['max_results'] ) ) ) : 10,
				);
			}
		}

		if ( empty( $features ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_invalid_features',
				__( 'No valid detection features were requested.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$client  = new WP_MCP_AI_Cloud_Vision_Client();
		$image   = $client->build_image_source( $image_url, $image_content );
		$decoded = $client->annotate( $features, $image, $context, $arguments, $this );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$normalized = self::normalize_response( $decoded, $requested );

		return $this->format_success_response(
			__( 'Image content detected with classic Cloud Vision features.', 'mcp-ai-wpoos' ),
			$normalized
		);
	}

	/**
	 * Normalize the raw Cloud Vision response into a stable, serialisable shape.
	 *
	 * The envelope exposes one key per requested feature so downstream tooling
	 * (e.g. identify_image, describe_image_layout) can consume a deterministic
	 * contract instead of Google's raw JSON.
	 *
	 * @param array    $decoded   Decoded Cloud Vision response.
	 * @param string[] $requested Requested feature names.
	 * @return array Normalized feature data.
	 */
	public static function normalize_response( array $decoded, array $requested ) {
		$data = array();

		if ( empty( $decoded['responses'][0] ) || ! is_array( $decoded['responses'][0] ) ) {
			return $data;
		}

		$response = $decoded['responses'][0];

		if ( in_array( 'labels', $requested, true ) && ! empty( $response['labelAnnotations'] ) ) {
			$data['labels'] = array();
			foreach ( $response['labelAnnotations'] as $label ) {
				$data['labels'][] = array(
					'description' => sanitize_text_field( isset( $label['description'] ) ? $label['description'] : '' ),
					'score'       => isset( $label['score'] ) ? (float) $label['score'] : 0.0,
					'topicality'  => isset( $label['topicality'] ) ? (float) $label['topicality'] : 0.0,
				);
			}
		}

		if ( in_array( 'text', $requested, true ) && ! empty( $response['textAnnotations'] ) ) {
			$full_text    = isset( $response['textAnnotations'][0]['description'] ) ? sanitize_textarea_field( $response['textAnnotations'][0]['description'] ) : '';
			$data['text'] = array(
				'full_text' => $full_text,
				'length'    => strlen( $full_text ),
			);
		}

		if ( in_array( 'web_entities', $requested, true ) && ! empty( $response['webDetection']['webEntities'] ) ) {
			$data['web_entities'] = array();
			foreach ( $response['webDetection']['webEntities'] as $entity ) {
				$data['web_entities'][] = array(
					'description' => sanitize_text_field( isset( $entity['description'] ) ? $entity['description'] : '' ),
					'score'       => isset( $entity['score'] ) ? (float) $entity['score'] : 0.0,
				);
			}

			if ( ! empty( $response['webDetection']['bestGuessLabels'] ) ) {
				$data['best_guess_labels'] = array_map( 'sanitize_text_field', wp_list_pluck( $response['webDetection']['bestGuessLabels'], 'label' ) );
			}
		}

		if ( in_array( 'logos', $requested, true ) && ! empty( $response['logoAnnotations'] ) ) {
			$data['logos'] = array();
			foreach ( $response['logoAnnotations'] as $logo ) {
				$data['logos'][] = array(
					'description'   => sanitize_text_field( isset( $logo['description'] ) ? $logo['description'] : '' ),
					'score'         => isset( $logo['score'] ) ? (float) $logo['score'] : 0.0,
					'bounding_poly' => isset( $logo['boundingPoly'] ) ? $logo['boundingPoly'] : null,
				);
			}
		}

		if ( in_array( 'landmarks', $requested, true ) && ! empty( $response['landmarkAnnotations'] ) ) {
			$data['landmarks'] = array();
			foreach ( $response['landmarkAnnotations'] as $landmark ) {
				$data['landmarks'][] = array(
					'description' => sanitize_text_field( isset( $landmark['description'] ) ? $landmark['description'] : '' ),
					'score'       => isset( $landmark['score'] ) ? (float) $landmark['score'] : 0.0,
				);
			}
		}

		if ( in_array( 'faces', $requested, true ) && ! empty( $response['faceAnnotations'] ) ) {
			$data['faces'] = array();
			foreach ( $response['faceAnnotations'] as $face ) {
				$data['faces'][] = array(
					'joy'           => sanitize_text_field( isset( $face['joyLikelihood'] ) ? $face['joyLikelihood'] : '' ),
					'sorrow'        => sanitize_text_field( isset( $face['sorrowLikelihood'] ) ? $face['sorrowLikelihood'] : '' ),
					'anger'         => sanitize_text_field( isset( $face['angerLikelihood'] ) ? $face['angerLikelihood'] : '' ),
					'surprise'      => sanitize_text_field( isset( $face['surpriseLikelihood'] ) ? $face['surpriseLikelihood'] : '' ),
					'under_exposed' => sanitize_text_field( isset( $face['underExposedLikelihood'] ) ? $face['underExposedLikelihood'] : '' ),
					'blurred'       => sanitize_text_field( isset( $face['blurredLikelihood'] ) ? $face['blurredLikelihood'] : '' ),
					'headwear'      => sanitize_text_field( isset( $face['headwearLikelihood'] ) ? $face['headwearLikelihood'] : '' ),
					'bounding_poly' => isset( $face['boundingPoly'] ) ? $face['boundingPoly'] : null,
				);
			}
		}

		if ( in_array( 'safe_search', $requested, true ) && ! empty( $response['safeSearchAnnotation'] ) ) {
			$safe                = $response['safeSearchAnnotation'];
			$data['safe_search'] = array(
				'adult'    => sanitize_text_field( isset( $safe['adult'] ) ? $safe['adult'] : '' ),
				'spoof'    => sanitize_text_field( isset( $safe['spoof'] ) ? $safe['spoof'] : '' ),
				'medical'  => sanitize_text_field( isset( $safe['medical'] ) ? $safe['medical'] : '' ),
				'violence' => sanitize_text_field( isset( $safe['violence'] ) ? $safe['violence'] : '' ),
				'racy'     => sanitize_text_field( isset( $safe['racy'] ) ? $safe['racy'] : '' ),
			);
		}

		return $data;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'external-api',         // Calls Google Cloud Vision API.
			'cacheable',            // Deterministic for a given image; results can be cached.
		);
	}
}
