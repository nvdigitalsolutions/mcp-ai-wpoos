<?php
/**
 * Extended Cognition Tool — Detect Objects
 *
 * Real-time object detection from the user's camera feed via HuggingFace's
 * OWLv2 model (or a local Ollama vision model).  The tool pushes a capture
 * request through the existing SSE sensor bridge, sends the returned frame
 * to the vision inference service, and returns structured detection results
 * with optional brand classification via FashionCLIP.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-ext-cog-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-ext-cog-sensor-access.php';
/**
 * Object detection tool for the Extended Cognition toolkit.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Detect_Objects implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_detect_objects';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Detect Objects (Extended Cognition)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Detect and count objects visible in the user\'s camera feed using HuggingFace OWLv2 or a local Ollama vision model. Supports generic object detection (COCO classes), open-vocabulary detection with custom labels, and brand/product classification via FashionCLIP. Returns bounding boxes, confidence scores, and per-object brand assignments.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get tool definition (input schema for the AI assistant).
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_detect_objects',
			'description'         => $this->get_description(),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'     => array(
						'type'        => 'string',
						'description' => 'Active chat session ID used to route the capture request to the correct browser tab.',
					),
					'labels'         => array(
						'type'        => 'array',
						'items'       => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'description' => 'Candidate object or brand labels to detect (e.g. ["Paco Rabanne bottle", "Givenchy perfume"]). If omitted, uses the site\'s Product Brand taxonomy catalogue.',
						'maxItems'    => 100,
					),
					'detection_mode' => array(
						'type'        => 'string',
						'enum'        => array( 'objects', 'brands', 'full' ),
						'description' => 'Detection mode. "objects" returns generic COCO-class detections. "brands" runs FashionCLIP zero-shot classification against the supplied labels. "full" runs both OWLv2 detection and FashionCLIP brand classification. Default: full.',
						'default'     => 'full',
					),
					'min_confidence' => array(
						'type'        => 'number',
						'description' => 'Minimum confidence threshold (0.0–1.0). Detections below this are filtered out. Default: 0.5.',
						'minimum'     => 0.0,
						'maximum'     => 1.0,
						'default'     => 0.5,
					),
					'include_bbox'   => array(
						'type'        => 'boolean',
						'description' => 'Whether to include bounding box coordinates for each detection. Default: true.',
						'default'     => true,
					),
					'provider'       => array(
						'type'        => 'string',
						'enum'        => array( 'auto', 'huggingface', 'ollama' ),
						'description' => 'Vision provider. "auto" prefers HuggingFace when an API key is set, falling back to Ollama if a vision model is available. Default: auto.',
						'default'     => 'auto',
					),
					'model'          => array(
						'type'        => 'string',
						'description' => 'Explicit model override (e.g. "google/owlv2-base-patch16" for HF, "llava:13b" for Ollama). When omitted the configured defaults are used.',
						'maxLength'   => 200,
					),
					'timeout_ms'     => array(
						'type'        => 'integer',
						'description' => 'Maximum milliseconds to wait for camera capture + inference. Default: 30000.',
						'minimum'     => 5000,
						'maximum'     => 90000,
						'default'     => 30000,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => $this->get_required_capability(),
			'category'            => array( 'extended-cognition', 'vision', 'object-detection' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Security gate 1: HTTPS.
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'https_required', __( 'Object detection requires a secure (HTTPS) connection.', 'mcp-ai-wpoos-pro' ) );
		}

		// Security gate 2: permissions.
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_camera'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The camera sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Sanitize arguments (two-gate rule: sanitize at entry) ---
		$session_id     = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$detection_mode = isset( $arguments['detection_mode'] ) ? sanitize_text_field( $arguments['detection_mode'] ) : 'full';
		$min_confidence = isset( $arguments['min_confidence'] ) ? (float) max( 0.0, min( 1.0, $arguments['min_confidence'] ) ) : 0.5;
		$include_bbox   = ! isset( $arguments['include_bbox'] ) || ! empty( $arguments['include_bbox'] );
		$provider       = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$model_override = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';
		$timeout_ms     = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 30000;

		// Sanitize labels array.
		$labels = array();
		if ( isset( $arguments['labels'] ) && is_array( $arguments['labels'] ) ) {
			$labels = array_map( 'sanitize_text_field', array_slice( $arguments['labels'], 0, 100 ) );
		}

		// Fall back to taxonomy catalogue if no labels provided.
		if ( empty( $labels ) && class_exists( 'WP_MCP_AI_Product_Brand_Taxonomy' ) ) {
			$labels = WP_MCP_AI_Product_Brand_Taxonomy::get_brand_labels( 50 );
		}

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required to route sensor requests to the browser.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Step 1: Capture frame via sensor bridge ---
		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Rate limit.
		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'camera', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Camera capture rate limit exceeded. Please wait before requesting another capture.', 'mcp-ai-wpoos-pro' ) );
		}

		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'       => 'capture_visual',
				'request_id' => $request_id,
				'resolution' => array(
					'width'  => 640,
					'height' => 480,
				),
				'store'      => false,
			)
		);

		// Poll for browser response.
		$timeout_s  = ceil( $timeout_ms / 1000 );
		$poll_start = time();
		$captured   = null;

		while ( ( time() - $poll_start ) < $timeout_s ) {
			$data = WP_MCP_AI_Ext_Cog_Sensor_Session::consume_data( $post_id, $request_id );
			if ( null !== $data ) {
				$captured = $data;
				break;
			}
			usleep( 300000 );
		}

		if ( null === $captured || empty( $captured['image_base64'] ) ) {
			return new WP_Error(
				'capture_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Camera capture timed out after %d seconds.', 'mcp-ai-wpoos-pro' ),
					$timeout_s
				)
			);
		}

		$image_base64 = $captured['image_base64'];

		// --- Step 2: Resolve provider ---
		$service    = new WP_MCP_AI_HF_Vision_Inference_Service();
		$use_ollama = false;

		if ( 'ollama' === $provider ) {
			$use_ollama = true;
		} elseif ( 'auto' === $provider ) {
			$hf_key = $service->get_api_key();
			if ( empty( $hf_key ) ) {
				$use_ollama = true;
			}
		}

		// --- Step 3: Run detection ---
		if ( $use_ollama ) {
			// Ollama path: single vision-model call.
			if ( 'brands' === $detection_mode || 'full' === $detection_mode ) {
				// Classify against brands.
				$result = $service->run_ollama_zero_shot_classification(
					$image_base64,
					$labels,
					$model_override
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return array(
					'success'     => true,
					'mode'        => $detection_mode,
					'provider'    => 'ollama',
					'model'       => $result['model'],
					'labels'      => $result['labels'],
					'captured_at' => $captured['captured_at'],
					'message'     => $this->build_message( $result['labels'], $detection_mode ),
				);
			}

			// Objects mode: detection via Ollama.
			$result = $service->run_ollama_object_detection(
				$image_base64,
				! empty( $labels ) ? $labels : array(),
				$model_override
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'     => true,
				'mode'        => $detection_mode,
				'provider'    => 'ollama',
				'model'       => $result['model'],
				'detections'  => $include_bbox ? $result['detections'] : $this->strip_bbox( $result['detections'] ),
				'total_items' => $result['total_items'],
				'captured_at' => $captured['captured_at'],
				'message'     => sprintf(
					/* translators: %d: number of items detected */
					_n( 'Detected %d item.', 'Detected %d items.', $result['total_items'], 'mcp-ai-wpoos-pro' ),
					$result['total_items']
				),
			);
		}

		// --- HuggingFace path ---
		$det_model = ! empty( $model_override ) ? $model_override : $settings['hf_detection_model'];
		$cls_model = ! empty( $model_override ) ? $model_override : $settings['hf_classification_model'];

		if ( 'objects' === $detection_mode ) {
			$result = $service->run_object_detection( $image_base64, $labels, $det_model, $min_confidence );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'     => true,
				'mode'        => 'objects',
				'provider'    => 'huggingface',
				'model'       => $result['model'],
				'detections'  => $include_bbox ? $result['detections'] : $this->strip_bbox( $result['detections'] ),
				'total_count' => $result['total_count'],
				'captured_at' => $captured['captured_at'],
				'message'     => sprintf(
					/* translators: %d: number of objects detected */
					_n( 'Detected %d object.', 'Detected %d objects.', $result['total_count'], 'mcp-ai-wpoos-pro' ),
					$result['total_count']
				),
			);
		}

		if ( 'brands' === $detection_mode ) {
			$result = $service->run_zero_shot_classification( $image_base64, $labels, $cls_model );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'     => true,
				'mode'        => 'brands',
				'provider'    => 'huggingface',
				'model'       => $result['model'],
				'labels'      => $result['labels'],
				'captured_at' => $captured['captured_at'],
				'message'     => $this->build_classification_message( $result['labels'] ),
			);
		}

		// Full mode: detect + classify.
		$result = $service->run_brand_detection_pipeline(
			$image_base64,
			$labels,
			$det_model,
			$cls_model,
			$min_confidence
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'      => true,
			'mode'         => 'full',
			'provider'     => 'huggingface',
			'detections'   => $include_bbox ? $result['detections'] : $this->strip_bbox( $result['detections'] ),
			'brands_found' => $result['brands_found'],
			'captured_at'  => $captured['captured_at'],
			'message'      => esc_html( $result['message'] ),
		);
	}

	/**
	 * Strip bounding box data from detections when include_bbox is false.
	 *
	 * @param array $detections Detection array.
	 * @return array
	 */
	private function strip_bbox( array $detections ) {
		foreach ( $detections as &$det ) {
			unset( $det['bounding_box'] );
			unset( $det['box'] );
		}
		unset( $det );
		return $detections;
	}

	/**
	 * Build a human-readable message for label-only results.
	 *
	 * @param array  $labels Classification result labels.
	 * @param string $mode   Detection mode.
	 * @return string
	 */
	private function build_message( array $labels, $mode ) {
		if ( empty( $labels ) ) {
			return __( 'No items matched the requested labels.', 'mcp-ai-wpoos-pro' );
		}

		$top = $labels[0];
		return sprintf(
			/* translators: %1$s: top label, %2$d: confidence percentage */
			__( 'Top match: %1$s (%2$d%% confidence).', 'mcp-ai-wpoos-pro' ),
			esc_html( $top['label'] ),
			absint( $top['score'] * 100 )
		);
	}

	/**
	 * Build a human-readable message from classification results.
	 *
	 * @param array $labels Classification labels.
	 * @return string
	 */
	private function build_classification_message( array $labels ) {
		if ( empty( $labels ) ) {
			return __( 'No classifications matched.', 'mcp-ai-wpoos-pro' );
		}

		$parts = array();
		foreach ( array_slice( $labels, 0, 5 ) as $item ) {
			$parts[] = sprintf(
				'%s (%d%%)',
				esc_html( $item['label'] ),
				absint( $item['score'] * 100 )
			);
		}

		return sprintf(
			/* translators: %s: comma-separated classification results */
			__( 'Classification results: %s', 'mcp-ai-wpoos-pro' ),
			implode( ', ', $parts )
		);
	}
}
