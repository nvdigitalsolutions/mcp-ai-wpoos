<?php
/**
 * Vision Analysis Tool — RF-DETR Detect
 *
 * Exposes the full RF-DETR surface through the Roboflow Inference service:
 * closed-vocabulary object detection (COCO or fine-tuned classes), instance
 * segmentation (mask polygons), and person keypoint detection. One tool,
 * three tasks — all three hit the same /infer/{model_id} endpoint family.
 *
 * Complements `analyze_image_objects` (which owns per-category counting):
 * this tool returns raw ranked detections with boxes, mask polygons, and
 * keypoints instead of a count breakdown.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.90
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

if ( ! class_exists( 'WP_MCP_AI_Roboflow_Inference_Service' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-roboflow-inference-service.php';
}

// Toolkit settings accessor — normally loaded by the Pro module registry via
// the toolkit init.php; load it explicitly so the tool works standalone.
if ( ! function_exists( 'wp_mcp_ai_vision_analysis_get_settings' ) ) {
	require_once __DIR__ . '/init.php';
}

/**
 * RF-DETR detection / segmentation / keypoint tool.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Tool_Rfdetr_Detect extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Default required capability (filterable).
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'edit_posts';

	/**
	 * Default segmentation checkpoint when no model override is supplied.
	 *
	 * @var string
	 */
	const DEFAULT_SEGMENTATION_MODEL = 'rfdetr-seg-small';

	/**
	 * Default keypoint checkpoint (Apache 2.0 preview).
	 *
	 * @var string
	 */
	const DEFAULT_KEYPOINT_MODEL = 'rfdetr-keypoint-preview';

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
		return 'rfdetr_detect';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'RF-DETR Detect', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects objects, instance-segmentation masks, or person keypoints in an image using RF-DETR (Roboflow). Task "detect" returns ranked detections with bounding boxes; "segment" adds mask polygons; "keypoints" returns the 17 COCO person keypoints per instance. Runs against a self-hosted Roboflow Inference server (bytes stay on your network), the Serverless Cloud API, or a fine-tuned model.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Precise, deterministic detection with bounding boxes; object cut-out masks; or person pose keypoints — including on a fine-tuned catalog model.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Per-category counting summaries — use analyze_image_objects with provider roboflow. Open-vocabulary "find anything" prompts — use analyze_image_objects with OWLv2.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'analyze_image_objects', 'vision_object_localization', 'detect_image_content', 'describe_image_layout' ),
			'notes'           => __( 'Requires the Roboflow settings under Pro → Vision Analysis. Self-hosted endpoints need no API key; hosted tiers send image bytes to the configured endpoint.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$source_schema = $this->get_source_parameters_schema();

		$analysis_schema = array(
			'task'        => array(
				'type'        => 'string',
				'enum'        => array( 'detect', 'segment', 'keypoints' ),
				'description' => __( 'Detection task. "detect" returns ranked detections with boxes; "segment" adds mask polygons per instance; "keypoints" returns person keypoints per instance. Default: detect.', 'mcp-ai-wpoos-pro' ),
				'default'     => 'detect',
			),
			'model_id'    => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'RF-DETR model alias (rfdetr-nano|small|medium|large, rfdetr-seg-*, rfdetr-keypoint-preview) or a fine-tuned workspace/project/version path. Defaults: the configured detection model for detect, rfdetr-seg-small for segment, rfdetr-keypoint-preview for keypoints.', 'mcp-ai-wpoos-pro' ),
			),
			'confidence'  => array(
				'type'        => 'number',
				'minimum'     => 0.0,
				'maximum'     => 1.0,
				'description' => __( 'Minimum confidence threshold (0.0–1.0). Default: 0.5.', 'mcp-ai-wpoos-pro' ),
				'default'     => 0.5,
			),
			'iou'         => array(
				'type'        => 'number',
				'minimum'     => 0.0,
				'maximum'     => 1.0,
				'description' => __( 'IoU threshold for non-maximum suppression overlap. Default: 0.5.', 'mcp-ai-wpoos-pro' ),
				'default'     => 0.5,
			),
			'max_results' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Maximum detections to return. Default: 25.', 'mcp-ai-wpoos-pro' ),
				'default'     => 25,
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
			'category'            => array( 'vision', 'object-detection', 'segmentation', 'keypoints', 'image-analysis' ),
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
			'external-api',
			'network-dependent',
			'rate-limited',
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_forbidden',
				__( 'You must be logged in to use RF-DETR detection.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$required_capability = apply_filters(
			'wp_mcp_ai_rfdetr_detect_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $user_id && $required_capability && ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_forbidden',
				__( 'You do not have permission to use RF-DETR detection.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// --- SSRF guard for remote image URLs ---
		foreach ( array( 'url', 'image_url' ) as $url_key ) {
			if ( ! empty( $arguments[ $url_key ] ) ) {
				$raw_url = esc_url_raw( $arguments[ $url_key ], array( 'http', 'https' ) );
				if ( ! $raw_url ) {
					return new WP_Error(
						'wp_mcp_ai_rfdetr_invalid_url',
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
		$task        = isset( $arguments['task'] ) ? sanitize_text_field( $arguments['task'] ) : 'detect';
		$task        = in_array( $task, array( 'detect', 'segment', 'keypoints' ), true ) ? $task : 'detect';
		$model_id    = isset( $arguments['model_id'] ) ? sanitize_text_field( $arguments['model_id'] ) : '';
		$confidence  = isset( $arguments['confidence'] ) ? (float) max( 0.0, min( 1.0, $arguments['confidence'] ) ) : 0.5;
		$iou         = isset( $arguments['iou'] ) ? (float) max( 0.0, min( 1.0, $arguments['iou'] ) ) : 0.5;
		$max_results = isset( $arguments['max_results'] ) ? min( 100, max( 1, absint( $arguments['max_results'] ) ) ) : 25;

		// --- Resolve the source image ---
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		$source_path = ! empty( $source_image->source_file_path ) ? $source_image->source_file_path : '';
		if ( '' === $source_path || ! file_exists( $source_path ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_rfdetr_source_unreadable',
				__( 'The source image could not be read.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$settings = wp_mcp_ai_vision_analysis_get_settings();

		$image_base64 = $this->base64_for_inference( $source_image, $source_path, absint( $settings['max_image_bytes'] ) );
		if ( is_wp_error( $image_base64 ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $image_base64;
		}

		// --- Resolve the model per task ---
		$service = new WP_MCP_AI_Roboflow_Inference_Service();

		if ( '' === $model_id ) {
			if ( 'keypoints' === $task ) {
				$model_id = self::DEFAULT_KEYPOINT_MODEL;
			} elseif ( 'segment' === $task ) {
				$model_id = self::DEFAULT_SEGMENTATION_MODEL;
			} else {
				$model_id = $service->get_default_model();
			}
		}

		// --- Inference ---
		$result = $service->infer( $image_base64, $model_id, $confidence, $iou );
		$this->cleanup_source_image( $source_image, $arguments );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$detections = array_slice( $result['detections'], 0, $max_results );

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

		$envelope = array(
			'success'     => true,
			'task'        => $task,
			'provider'    => 'roboflow',
			'model'       => $result['model'],
			'detections'  => $detections,
			'total_count' => count( $detections ),
		);

		if ( null !== $result['inference_ms'] ) {
			$envelope['inference_ms'] = $result['inference_ms'];
		}

		if ( '' !== $image_url ) {
			$envelope['image_url'] = $image_url;
		}

		$envelope['message'] = sprintf(
			/* translators: 1: number of detections, 2: task name, 3: model identifier */
			__( 'Found %1$d detection(s) (%2$s) with %3$s.', 'mcp-ai-wpoos-pro' ),
			count( $detections ),
			$task,
			$result['model']
		);

		return $envelope;
	}

	/**
	 * Base64-encode the source image for inference, downscaling when the
	 * payload would exceed the configured limit.
	 *
	 * @param WP_Image_Editor $source_image    Loaded source editor.
	 * @param string          $source_path     On-disk source path.
	 * @param int             $max_image_bytes Maximum payload bytes.
	 * @return string|WP_Error
	 */
	private function base64_for_inference( $source_image, $source_path, $max_image_bytes ) {
		$max_image_bytes = $max_image_bytes > 0 ? $max_image_bytes : WP_MCP_AI_Roboflow_Inference_Service::MAX_PAYLOAD_BYTES;

		$image_data = file_get_contents( $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( false === $image_data ) {
			return new WP_Error( 'wp_mcp_ai_rfdetr_source_read_error', __( 'Failed to read the source image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
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
					'wp_mcp_ai_rfdetr_image_too_large',
					__( 'The image exceeds the maximum upload size and could not be downscaled.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 413 )
				);
			}
		}

		$temp_path = wp_tempnam( 'rfdetr-resized-' );
		if ( ! $temp_path ) {
			return new WP_Error( 'wp_mcp_ai_rfdetr_temp_error', __( 'Failed to create a temporary file.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$saved = $source_image->save( $temp_path );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $temp_path );
			return new WP_Error( 'wp_mcp_ai_rfdetr_resize_save_error', __( 'Failed to save the downscaled image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$saved_path   = isset( $saved['path'] ) ? $saved['path'] : $temp_path;
		$resized_data = file_get_contents( $saved_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		wp_delete_file( $saved_path );

		if ( false === $resized_data ) {
			return new WP_Error( 'wp_mcp_ai_rfdetr_source_read_error', __( 'Failed to read the downscaled image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$encoded = base64_encode( $resized_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to encode binary image data for API transmission, not for obfuscation.

		if ( strlen( $encoded ) > $max_image_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_rfdetr_image_too_large',
				__( 'The image is still too large after downscaling.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		return $encoded;
	}
}
