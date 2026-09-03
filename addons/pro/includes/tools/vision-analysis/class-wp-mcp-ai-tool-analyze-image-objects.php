<?php
/**
 * Vision Analysis Tool — Analyze Image Objects
 *
 * Detects the objects in an image and returns a per-category count breakdown
 * with optional bounding boxes. Detection runs through HuggingFace OWLv2 or
 * a local Ollama vision model; a VLM pass (OpenAI / Anthropic / Gemini) can
 * provide open-world counting or label normalization. Phase 2 adds GD-based
 * bounding-box annotation that returns an annotated copy as an attachment.
 *
 * Design invariant: in hybrid mode the detector owns the counts — the VLM
 * renames labels but never recounts.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-vision-count-normalizer.php';
require_once __DIR__ . '/class-wp-mcp-ai-vision-vlm-client.php';
require_once __DIR__ . '/class-wp-mcp-ai-vision-annotator.php';

// The HF vision service is loaded by the Pro module registry when the
// Extended Cognition toolkit is enabled; load it explicitly so the Vision
// Analysis toolkit works standalone.
if ( ! class_exists( 'WP_MCP_AI_HF_Vision_Inference_Service' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-hf-vision-inference-service.php';
}

// Toolkit settings accessor — normally loaded by the Pro module registry via
// the toolkit init.php; load it explicitly so the tool works standalone.
if ( ! function_exists( 'wp_mcp_ai_vision_analysis_get_settings' ) ) {
	require_once __DIR__ . '/init.php';
}

/**
 * Object counting and breakdown tool for the Vision Analysis toolkit.
 *
 * @since 1.1.68
 */
class WP_MCP_AI_Tool_Analyze_Image_Objects extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * Maximum downscale dimension applied to oversized images before upload.
	 *
	 * @var int
	 */
	const MAX_DOWNSCALE_DIMENSION = 1600;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_image_objects';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Image Objects', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detect and count the objects in an image, returning a per-category count breakdown with confidence scores and optional bounding boxes. Uses dedicated detectors (HuggingFace OWLv2, local Ollama vision) with an optional VLM pass (OpenAI, Anthropic, Gemini) for open-world counting and label normalization. Can return an annotated copy of the image with boxes drawn on it.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$source_schema = $this->get_source_parameters_schema();

		$analysis_schema = array(
			'mode'           => array(
				'type'        => 'string',
				'enum'        => array( 'hybrid', 'detection', 'vlm' ),
				'description' => __( 'Analysis mode. "detection" uses a dedicated detector (HuggingFace OWLv2 or Ollama vision) and counts boxes — most accurate. "vlm" asks a chat vision model to count. "hybrid" runs detection first and optionally uses a VLM to normalize labels. Default: hybrid.', 'mcp-ai-wpoos-pro' ),
				'default'     => 'hybrid',
			),
			'provider'       => array(
				'type'        => 'string',
				'enum'        => array( 'auto', 'huggingface', 'ollama', 'openai', 'anthropic', 'gemini' ),
				'description' => __( 'Vision provider. Detection modes use "huggingface" (OWLv2) or "ollama"; VLM modes use "openai", "anthropic", or "gemini". "auto" picks the best configured provider. Default: auto.', 'mcp-ai-wpoos-pro' ),
				'default'     => 'auto',
			),
			'model'          => array(
				'type'        => 'string',
				'description' => __( 'Explicit model override (e.g. "google/owlv2-base-patch16" for HuggingFace, "llava:13b" for Ollama, "gpt-4o-mini" for OpenAI). When omitted, configured defaults are used.', 'mcp-ai-wpoos-pro' ),
				'maxLength'   => 200,
			),
			'categories'     => array(
				'type'        => 'array',
				'items'       => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'description' => __( 'Candidate object categories to look for (open-vocabulary, e.g. ["person", "car", "cup"]). Omit to detect general objects.', 'mcp-ai-wpoos-pro' ),
				'maxItems'    => 100,
			),
			'min_confidence' => array(
				'type'        => 'number',
				'description' => __( 'Minimum confidence threshold (0.0–1.0). Detections below this are filtered out. Default: 0.5.', 'mcp-ai-wpoos-pro' ),
				'minimum'     => 0.0,
				'maximum'     => 1.0,
				'default'     => 0.5,
			),
			'include_boxes'  => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to include bounding box coordinates for each detection. Default: true.', 'mcp-ai-wpoos-pro' ),
				'default'     => true,
			),
			'annotate'       => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to return an annotated copy of the image with labeled bounding boxes drawn on it. Requires detection boxes and the PHP GD extension. Default: false.', 'mcp-ai-wpoos-pro' ),
				'default'     => false,
			),
			'max_tokens'     => array(
				'type'        => 'integer',
				'description' => __( 'Maximum response tokens for VLM modes. Default: 1024.', 'mcp-ai-wpoos-pro' ),
				'minimum'     => 128,
				'maximum'     => 8192,
				'default'     => 1024,
			),
		);

		return array(
			'type'                 => 'object',
			'properties'           => array_merge( $source_schema, $analysis_schema ),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'input_schema'        => $this->get_parameters_schema(),
			'required_capability' => $this->get_required_capability(),
			'category'            => array( 'vision', 'object-detection', 'counting', 'image-analysis' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'read-only',
			'requires-credentials',
			'requires-vision-model',
			'external-api',
			'network-dependent',
			'consumes-tokens',
			'model-dependent',
			'async',
			'rate-limited',
			'performance-impact',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = wp_mcp_ai_vision_analysis_get_settings();

		// --- Gate 1: authentication / capabilities ---
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_va_forbidden',
				__( 'You must be logged in to use object analysis.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_forbidden',
				__( 'You do not have permission to analyze images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// --- Gate 2: SSRF guard for remote image URLs ---
		foreach ( array( 'url', 'image_url' ) as $url_key ) {
			if ( ! empty( $arguments[ $url_key ] ) ) {
				$raw_url = esc_url_raw( $arguments[ $url_key ], array( 'http', 'https' ) );
				if ( ! $raw_url ) {
					return new WP_Error(
						'wp_mcp_ai_va_invalid_url',
						__( 'The provided image URL is not valid.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}

				if ( ! $this->is_local_wordpress_url( $raw_url ) && class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
					$guard_check = WP_MCP_AI_Url_Guard::validate( $raw_url );
					if ( is_wp_error( $guard_check ) ) {
						return $guard_check;
					}
				}
			}
		}

		// --- Sanitize arguments (two-gate rule: sanitize at entry) ---
		$mode           = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'hybrid';
		$provider_arg   = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$model_override = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';
		$min_confidence = isset( $arguments['min_confidence'] ) ? (float) max( 0.0, min( 1.0, $arguments['min_confidence'] ) ) : $settings['min_confidence'];
		$include_boxes  = ! isset( $arguments['include_boxes'] ) || ! empty( $arguments['include_boxes'] );
		$annotate       = isset( $arguments['annotate'] ) ? ! empty( $arguments['annotate'] ) : $settings['annotate_default'];
		$max_tokens     = isset( $arguments['max_tokens'] ) ? absint( $arguments['max_tokens'] ) : 1024;
		$max_tokens     = max( 128, min( 8192, $max_tokens ) );

		if ( ! in_array( $mode, array( 'hybrid', 'detection', 'vlm' ), true ) ) {
			$mode = 'hybrid';
		}

		$categories = array();
		if ( isset( $arguments['categories'] ) && is_array( $arguments['categories'] ) ) {
			$categories = array_filter( array_map( 'sanitize_text_field', array_slice( $arguments['categories'], 0, 100 ) ) );
		}

		// --- Resolve the source image ---
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		$source_path = ! empty( $source_image->source_file_path ) ? $source_image->source_file_path : '';
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_va_source_unreadable',
				__( 'The source image could not be read.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$mime_type = $this->resolve_source_mime_type( $arguments );
		if ( '' === $mime_type ) {
			$checked   = wp_check_filetype( $source_path );
			$mime_type = isset( $checked['type'] ) ? $checked['type'] : '';
		}

		// Echo the public image URL so the orchestrator can re-inject the
		// image into follow-up vision turns.
		$image_url = '';
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$image_url = wp_get_attachment_url( absint( $arguments['attachment_id'] ) );
		} elseif ( ! empty( $arguments['url'] ) ) {
			$image_url = esc_url_raw( $arguments['url'] );
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
		}

		// --- Prepare base64 payload (with size management) ---
		$image_base64 = $this->base64_for_inference( $source_image, $source_path, absint( $settings['max_image_bytes'] ) );
		if ( is_wp_error( $image_base64 ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $image_base64;
		}

		// --- Dispatch ---
		$service = new WP_MCP_AI_HF_Vision_Inference_Service();

		if ( 'vlm' === $mode ) {
			$result = $this->run_vlm_mode( $image_base64, $mime_type, $image_url, $categories, $provider_arg, $model_override, $max_tokens );
		} else {
			$result = $this->run_detection_mode(
				$service,
				$image_base64,
				$categories,
				$provider_arg,
				$model_override,
				$min_confidence,
				$include_boxes
			);

			// Hybrid: detector failed → fall back to VLM counting when configured.
			if ( is_wp_error( $result ) && 'hybrid' === $mode ) {
				$vlm_fallback = $this->run_vlm_mode( $image_base64, $mime_type, $image_url, $categories, $provider_arg, $model_override, $max_tokens );
				if ( is_wp_error( $vlm_fallback ) ) {
					$this->cleanup_source_image( $source_image, $arguments );
					return $result;
				}
				$result = $vlm_fallback;
			} elseif ( ! is_wp_error( $result ) && 'hybrid' === $mode ) {
				// Hybrid: detector succeeded → VLM label-normalization pass.
				// The VLM renames mislabeled categories; counts and boxes
				// remain the detector's.
				$normalized = $this->run_label_normalization(
					$result['counts'],
					$image_base64,
					$mime_type,
					$image_url,
					$provider_arg,
					$model_override
				);

				if ( is_array( $normalized ) ) {
					$result['counts']            = $normalized['counts'];
					$result['vlm_normalization'] = array(
						'applied'  => $normalized['applied'],
						'provider' => $normalized['provider'],
						'model'    => $normalized['model'],
					);
				}
			}
		}

		$this->cleanup_source_image( $source_image, $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// --- Phase 2: annotation ---
		$envelope = $result;
		if ( $annotate ) {
			$annotation = $this->annotate_breakdown( $source_path, $envelope['counts'], $arguments, $user_id );
			if ( is_wp_error( $annotation ) ) {
				$envelope['annotation_error'] = $annotation->get_error_message();
			} else {
				$envelope['annotated_image'] = $annotation;
			}
		}

		$envelope['success']     = true;
		$envelope['total_items'] = WP_MCP_AI_Vision_Count_Normalizer::total_from_breakdown( $envelope['counts'] );
		$envelope['message']     = WP_MCP_AI_Vision_Count_Normalizer::build_message( $envelope['counts'], $envelope['total_items'] );

		if ( '' !== $image_url ) {
			$envelope['image_url'] = esc_url_raw( $image_url );
		}

		return $envelope;
	}

	/**
	 * Run the dedicated-detector path (HuggingFace OWLv2 or Ollama vision).
	 *
	 * @param WP_MCP_AI_HF_Vision_Inference_Service $service        Vision service.
	 * @param string                                $image_base64   Base64 image bytes.
	 * @param array                                 $categories     Candidate labels.
	 * @param string                                $provider_arg   Provider argument.
	 * @param string                                $model_override Model override.
	 * @param float                                 $min_confidence Confidence threshold.
	 * @param bool                                  $include_boxes  Retain boxes.
	 * @return array|WP_Error
	 */
	private function run_detection_mode( $service, $image_base64, array $categories, $provider_arg, $model_override, $min_confidence, $include_boxes ) {
		$settings   = wp_mcp_ai_vision_analysis_get_settings();
		$use_ollama = 'ollama' === $provider_arg;

		if ( 'auto' === $provider_arg ) {
			$hf_key = $service->get_api_key();
			if ( empty( $hf_key ) ) {
				$use_ollama = true;
			}
		}

		if ( $use_ollama ) {
			$result = $service->run_ollama_object_detection( $image_base64, $categories, $model_override );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $result['detections'], $include_boxes );

			return array(
				'mode'     => 'detection',
				'provider' => 'ollama',
				'model'    => isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : '',
				'counts'   => $breakdown,
			);
		}

		$model  = '' !== $model_override ? $model_override : $settings['detection_model'];
		$result = $service->run_object_detection( $image_base64, $categories, $model, $min_confidence );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $result['detections'], $include_boxes );

		return array(
			'mode'     => 'detection',
			'provider' => 'huggingface',
			'model'    => isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : $model,
			'counts'   => $breakdown,
		);
	}

	/**
	 * Run the chat-VLM counting path.
	 *
	 * @param string $image_base64   Base64 image bytes.
	 * @param string $mime_type      Image MIME type.
	 * @param string $image_url      Public image URL (may be empty).
	 * @param array  $categories     Candidate labels.
	 * @param string $provider_arg   Provider argument.
	 * @param string $model_override Model override.
	 * @param int    $max_tokens     Maximum response tokens.
	 * @return array|WP_Error
	 */
	private function run_vlm_mode( $image_base64, $mime_type, $image_url, array $categories, $provider_arg, $model_override, $max_tokens ) {
		$settings = wp_mcp_ai_vision_analysis_get_settings();
		$client   = new WP_MCP_AI_Vision_VLM_Client();

		$provider = $client->resolve_provider( $provider_arg );
		if ( '' === $provider ) {
			return new WP_Error(
				'wp_mcp_ai_va_no_vlm_provider',
				__( 'No chat vision provider is configured. Add an OpenAI, Anthropic, or Gemini API key, or use mode "detection" with HuggingFace/Ollama.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model    = '' !== $model_override ? $model_override : $settings['vlm_model'];
		$prompt   = $client->build_counting_prompt( $categories );
		$timeout  = 90;
		$response = $client->request( $provider, $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = WP_MCP_AI_Vision_Count_Normalizer::extract_json( $response['content'] );
		if ( null === $parsed ) {
			return new WP_Error(
				'wp_mcp_ai_va_vlm_parse_error',
				__( 'The vision model did not return a parseable JSON breakdown.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'      => 502,
					'raw_preview' => substr( $response['content'], 0, 400 ),
				)
			);
		}

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::normalize_vlm_counts( $parsed );

		return array(
			'mode'     => 'vlm',
			'provider' => $provider,
			'model'    => isset( $response['model'] ) ? sanitize_text_field( $response['model'] ) : $model,
			'counts'   => $breakdown,
		);
	}

	/**
	 * Run the hybrid label-normalization pass over a detector breakdown.
	 *
	 * The VLM is asked to rename mislabeled categories only; counts and boxes
	 * always come from the detector.
	 *
	 * @param array  $breakdown      Detector breakdown.
	 * @param string $image_base64   Base64 image bytes.
	 * @param string $mime_type      Image MIME type.
	 * @param string $image_url      Public image URL (may be empty).
	 * @param string $provider_arg   Provider argument.
	 * @param string $model_override Model override.
	 * @return array{counts: array, applied: bool, provider: string, model: string}|WP_Error
	 */
	private function run_label_normalization( array $breakdown, $image_base64, $mime_type, $image_url, $provider_arg, $model_override ) {
		$settings = wp_mcp_ai_vision_analysis_get_settings();
		$client   = new WP_MCP_AI_Vision_VLM_Client();

		$provider = $client->resolve_provider( $provider_arg );
		if ( '' === $provider ) {
			return array(
				'counts'   => $breakdown,
				'applied'  => false,
				'provider' => '',
				'model'    => '',
			);
		}

		$labels   = wp_list_pluck( $breakdown, 'label' );
		$prompt   = $client->build_normalization_prompt( $labels );
		$model    = '' !== $model_override ? $model_override : $settings['vlm_model'];
		$response = $client->request( $provider, $prompt, $image_url, $image_base64, $mime_type, $model, 512, 60 );

		if ( is_wp_error( $response ) ) {
			return array(
				'counts'   => $breakdown,
				'applied'  => false,
				'provider' => $provider,
				'model'    => $model,
			);
		}

		$aliases = WP_MCP_AI_Vision_Count_Normalizer::extract_json( $response['content'] );

		// The normalization prompt asks for a flat label → label map. A
		// JSON object decodes to a PHP associative array; a JSON list (or
		// parse failure) is not usable as an alias map.
		if ( is_array( $aliases ) && array_values( $aliases ) !== $aliases ) {
			$merged = WP_MCP_AI_Vision_Count_Normalizer::merge_label_aliases( $breakdown, $aliases );

			return array(
				'counts'   => $merged,
				'applied'  => true,
				'provider' => $provider,
				'model'    => isset( $response['model'] ) ? sanitize_text_field( $response['model'] ) : $model,
			);
		}

		return array(
			'counts'   => $breakdown,
			'applied'  => false,
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Produce the annotated copy of the image for the breakdown.
	 *
	 * @param string $source_path Source image path.
	 * @param array  $breakdown   Canonical breakdown (entries carry boxes).
	 * @param array  $arguments   Tool arguments (used for naming).
	 * @param int    $user_id     Current user ID.
	 * @return array|WP_Error Attachment data or error.
	 */
	private function annotate_breakdown( $source_path, array $breakdown, array $arguments, $user_id ) {
		$has_boxes = false;
		foreach ( $breakdown as $entry ) {
			if ( ! empty( $entry['boxes'] ) ) {
				$has_boxes = true;
				break;
			}
		}

		if ( ! $has_boxes ) {
			return new WP_Error(
				'wp_mcp_ai_va_no_boxes',
				__( 'Annotation requires detector bounding boxes. Run with mode "detection" or "hybrid" and include_boxes=true.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$annotated = WP_MCP_AI_Vision_Annotator::annotate( $source_path, $breakdown );
		if ( is_wp_error( $annotated ) ) {
			return $annotated;
		}

		$image_data = file_get_contents( $annotated['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( false === $image_data ) {
			wp_delete_file( $annotated['path'] );
			return new WP_Error( 'wp_mcp_ai_va_annotate_read_error', __( 'Failed to read the annotated image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$file_name = isset( $arguments['file_name'] ) && '' !== $arguments['file_name']
			? sanitize_file_name( $arguments['file_name'] )
			: 'image';
		$extension = 'image/png' === $annotated['mime_type'] ? 'png' : 'jpg';
		$file_name = sprintf( '%s-object-breakdown-%s.%s', sanitize_title( $file_name ), gmdate( 'Ymd-His' ), $extension );

		$upload = wp_upload_bits( $file_name, null, $image_data );
		wp_delete_file( $annotated['path'] );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_va_annotate_upload_error', $upload['error'] );
		}

		$final_path = isset( $upload['file'] ) ? $upload['file'] : '';
		if ( '' === $final_path || ! file_exists( $final_path ) ) {
			return new WP_Error( 'wp_mcp_ai_va_annotate_upload_error', __( 'Failed to upload the annotated image.', 'mcp-ai-wpoos-pro' ) );
		}

		$attachment = array(
			'post_mime_type' => $annotated['mime_type'],
			'post_title'     => __( 'Object Count Breakdown (annotated)', 'mcp-ai-wpoos-pro' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $final_path );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $final_path );
			return new WP_Error( 'wp_mcp_ai_va_annotate_attachment_error', __( 'Failed to create the annotated attachment.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $final_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
			'mime_type'     => $annotated['mime_type'],
			'title'         => __( 'Object Count Breakdown (annotated)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Base64-encode the source image for inference, downscaling when the
	 * payload would exceed the configured limit.
	 *
	 * @param WP_Image_Editor $source_image   Loaded source editor.
	 * @param string          $source_path    On-disk source path.
	 * @param int             $max_image_bytes Maximum payload bytes.
	 * @return string|WP_Error
	 */
	private function base64_for_inference( $source_image, $source_path, $max_image_bytes ) {
		$max_image_bytes = $max_image_bytes > 0 ? $max_image_bytes : WP_MCP_AI_HF_Vision_Inference_Service::MAX_PAYLOAD_BYTES;

		$image_data = file_get_contents( $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( false === $image_data ) {
			return new WP_Error( 'wp_mcp_ai_va_source_read_error', __( 'Failed to read the source image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$encoded = base64_encode( $image_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to encode binary image data for API transmission, not for obfuscation.

		if ( strlen( $encoded ) <= $max_image_bytes ) {
			return $encoded;
		}

		// Oversized: downscale to at most 1600px on the longest edge and re-encode.
		$size  = $source_image->get_size();
		$width = isset( $size['width'] ) ? absint( $size['width'] ) : 0;
		if ( $width > self::MAX_DOWNSCALE_DIMENSION ) {
			$height = isset( $size['height'] ) ? absint( $size['height'] ) : 0;
			if ( $height < 1 ) {
				$height = $width;
			}
			$new_height = max( 1, (int) round( $height * ( self::MAX_DOWNSCALE_DIMENSION / $width ) ) );

			$resized = $source_image->resize( self::MAX_DOWNSCALE_DIMENSION, $new_height );
			if ( is_wp_error( $resized ) ) {
				return new WP_Error(
					'wp_mcp_ai_va_image_too_large',
					__( 'The image exceeds the maximum upload size and could not be downscaled.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 413 )
				);
			}
		}

		$temp_path = wp_tempnam( 'va-resized-' );
		if ( ! $temp_path ) {
			return new WP_Error( 'wp_mcp_ai_va_temp_error', __( 'Failed to create a temporary file.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$saved = $source_image->save( $temp_path );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $temp_path );
			return new WP_Error( 'wp_mcp_ai_va_resize_save_error', __( 'Failed to save the downscaled image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$saved_path   = isset( $saved['path'] ) ? $saved['path'] : $temp_path;
		$resized_data = file_get_contents( $saved_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		wp_delete_file( $saved_path );

		if ( false === $resized_data ) {
			return new WP_Error( 'wp_mcp_ai_va_source_read_error', __( 'Failed to read the downscaled image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$encoded = base64_encode( $resized_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to encode binary image data for API transmission, not for obfuscation.

		if ( strlen( $encoded ) > $max_image_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_va_image_too_large',
				__( 'The image is still too large after downscaling.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		return $encoded;
	}
}
