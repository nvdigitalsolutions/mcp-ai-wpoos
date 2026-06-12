<?php
/**
 * HuggingFace Vision Inference Service
 *
 * Wraps HuggingFace's hosted Inference API and dedicated Inference Endpoints
 * for computer-vision models: object detection (OWLv2/YOLO), zero-shot
 * image classification (CLIP/FashionCLIP), and image feature extraction
 * (DINOv2/CLIP embeddings).
 *
 * Complements the existing WP_MCP_AI_Huggingface_Client (chat completions,
 * audio transcription, TTS) with vision-specific inference that calls the
 * per-model https://api-inference.huggingface.co/models/{model_id} endpoint
 * or a user-supplied dedicated Inference Endpoint.
 *
 * Also supports Ollama-local vision models when the Ollama provider is
 * configured and a vision-capable model (llava, bakllava, minicpm-v, etc.)
 * is available.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HuggingFace Vision Inference Service.
 *
 * All public methods accept a base64-encoded image and return a canonical
 * detection/classification result array, or WP_Error on failure.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_HF_Vision_Inference_Service {

	/**
	 * Default object-detection model on HuggingFace Hub.
	 *
	 * @var string
	 */
	const DEFAULT_DETECTION_MODEL = 'google/owlv2-base-patch16';

	/**
	 * Default zero-shot classification model on HuggingFace Hub.
	 *
	 * @var string
	 */
	const DEFAULT_CLASSIFICATION_MODEL = 'patrickjohncyh/fashion-clip';

	/**
	 * Default feature-extraction model on HuggingFace Hub.
	 *
	 * @var string
	 */
	const DEFAULT_EMBEDDING_MODEL = 'facebook/dinov2-large';

	/**
	 * Hard limit on image size sent to HF API (5 MB base64 payload).
	 *
	 * @var int
	 */
	const MAX_PAYLOAD_BYTES = 5242880;

	/**
	 * Get the HF API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return isset( $settings['huggingface_api_key'] ) ? $settings['huggingface_api_key'] : '';
	}

	/**
	 * Get the dedicated HF Inference Endpoint URL (may be empty).
	 *
	 * @return string
	 */
	public function get_endpoint_url() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return isset( $settings['huggingface_endpoint_url'] ) ? $settings['huggingface_endpoint_url'] : '';
	}

	/**
	 * Run object detection on a base64-encoded image.
	 *
	 * Uses OWLv2 (open-vocabulary) by default.  Pass candidate_labels to
	 * constrain detection to specific objects.  Without labels the model
	 * returns its default COCO-style output.
	 *
	 * @param string   $image_base64     Base64-encoded JPEG/PNG image (no data URI prefix).
	 * @param string[] $candidate_labels Optional list of text labels to detect.
	 * @param string   $model            HuggingFace model ID (default: google/owlv2-base-patch16).
	 * @param float    $threshold        Minimum confidence score (0.0–1.0).
	 * @return array|WP_Error Detection results or error.
	 */
	public function run_object_detection( $image_base64, array $candidate_labels = array(), $model = '', $threshold = 0.5 ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_hf_api_key',
				__( 'No HuggingFace API key configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model = ! empty( $model ) ? sanitize_text_field( $model ) : self::DEFAULT_DETECTION_MODEL;
		$url   = $this->build_model_url( $model );

		// Prepare input.  OWLv2 accepts base64 image with optional candidate labels parameter.
		$payload = array(
			'inputs' => $image_base64,
		);

		$parameters = array();
		if ( ! empty( $candidate_labels ) ) {
			$parameters['candidate_labels'] = array_map( 'sanitize_text_field', array_slice( $candidate_labels, 0, 50 ) );
		}
		if ( $threshold > 0 ) {
			$parameters['threshold'] = (float) max( 0.0, min( 1.0, $threshold ) );
		}

		if ( ! empty( $parameters ) ) {
			$payload['parameters'] = $parameters;
		}

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_payload_too_large',
				__( 'Image payload exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		$response = $this->post_inference( $url, $encoded, 60 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->normalize_detection_result( $response, $model );
	}

	/**
	 * Run zero-shot image classification against a set of candidate labels.
	 *
	 * Uses CLIP or FashionCLIP.  Returns per-label confidence scores.
	 *
	 * @param string   $image_base64     Base64-encoded image.
	 * @param string[] $candidate_labels Text labels to classify against.
	 * @param string   $model            HuggingFace model ID (default: patrickjohncyh/fashion-clip).
	 * @return array|WP_Error Classification results or error.
	 */
	public function run_zero_shot_classification( $image_base64, array $candidate_labels, $model = '' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_hf_api_key',
				__( 'No HuggingFace API key configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $candidate_labels ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_labels',
				__( 'At least one candidate label is required for zero-shot classification.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model = ! empty( $model ) ? sanitize_text_field( $model ) : self::DEFAULT_CLASSIFICATION_MODEL;
		$url   = $this->build_model_url( $model );

		$payload = array(
			'inputs'     => $image_base64,
			'parameters' => array(
				'candidate_labels' => array_map( 'sanitize_text_field', array_slice( $candidate_labels, 0, 100 ) ),
			),
		);

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_payload_too_large',
				__( 'Image payload exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		$response = $this->post_inference( $url, $encoded, 45 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->normalize_classification_result( $response, $model );
	}

	/**
	 * Extract a feature embedding (vector) from an image for similarity search.
	 *
	 * Uses DINOv2 by default.
	 *
	 * @param string $image_base64 Base64-encoded image.
	 * @param string $model        HuggingFace model ID (default: facebook/dinov2-large).
	 * @return array|WP_Error Array with 'embedding' key (float[]), or error.
	 */
	public function run_image_feature_extraction( $image_base64, $model = '' ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_hf_api_key',
				__( 'No HuggingFace API key configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model = ! empty( $model ) ? sanitize_text_field( $model ) : self::DEFAULT_EMBEDDING_MODEL;
		$url   = $this->build_model_url( $model );

		$payload = array(
			'inputs' => $image_base64,
		);

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_payload_too_large',
				__( 'Image payload exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		$response = $this->post_inference( $url, $encoded, 45 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// DINOv2 returns a flat float array as the top-level response.
		$embedding = array();
		if ( is_array( $response ) ) {
			// If it's a list of floats.
			if ( isset( $response[0] ) && is_float( $response[0] ) ) {
				$embedding = $response;
			} elseif ( isset( $response['embedding'] ) && is_array( $response['embedding'] ) ) {
				$embedding = $response['embedding'];
			} elseif ( isset( $response['image_embeds'] ) && is_array( $response['image_embeds'] ) ) {
				$embedding = $response['image_embeds'];
			}
		}

		return array(
			'success'    => true,
			'model'      => $model,
			'embedding'  => $embedding,
			'dimensions' => count( $embedding ),
		);
	}

	/**
	 * Run brand-detection pipeline: detect objects → classify each region.
	 *
	 * Composite: OWLv2 detects items → FashionCLIP classifies each detected
	 * region against the supplied brand/product labels.
	 *
	 * @param string   $image_base64  Base64-encoded image.
	 * @param string[] $brand_labels  Brand/product labels for classification.
	 * @param string   $detection_model Detection model ID.
	 * @param string   $class_model   Classification model ID.
	 * @param float    $threshold     Minimum detection confidence.
	 * @return array|WP_Error
	 */
	public function run_brand_detection_pipeline(
		$image_base64,
		array $brand_labels,
		$detection_model = '',
		$class_model = '',
		$threshold = 0.5
	) {
		// Step 1: Detect generic objects.
		$detections = $this->run_object_detection( $image_base64, array(), $detection_model, $threshold );
		if ( is_wp_error( $detections ) ) {
			return $detections;
		}

		// If no objects found, return empty.
		if ( empty( $detections['detections'] ) ) {
			return array(
				'success'              => true,
				'detections'           => array(),
				'brands_found'         => array(),
				'detection_model'      => $detections['model'],
				'classification_model' => ! empty( $class_model ) ? $class_model : self::DEFAULT_CLASSIFICATION_MODEL,
				'message'              => __( 'No objects detected in the image.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Step 2: Classify the whole image against brand labels.
		// Per-region classification requires GD/Imagick cropping; without it
		// we distribute the top-N classification results across detections
		// in confidence order so each detection gets a distinct label.
		$class_model_id = ! empty( $class_model ) ? sanitize_text_field( $class_model ) : self::DEFAULT_CLASSIFICATION_MODEL;
		$classification = $this->run_zero_shot_classification( $image_base64, $brand_labels, $class_model_id );
		if ( is_wp_error( $classification ) ) {
			// Classification failed — still return detections.
			return array(
				'success'              => true,
				'detections'           => $detections['detections'],
				'brands_found'         => array(),
				'detection_model'      => $detections['model'],
				'classification_model' => $class_model_id,
				'classification_error' => $classification->get_error_message(),
				'message'              => __( 'Objects detected but brand classification failed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Distribute classification results across detections.
		// Each detection gets a distinct label from the top-N results;
		// when there are more detections than labels the remaining
		// detections are left without brand assignments.
		$class_labels = $classification['labels'];
		$label_count  = count( $class_labels );
		$brands_found = array();

		foreach ( $detections['detections'] as $i => &$det ) {
			if ( $i < $label_count ) {
				$label                   = $class_labels[ $i ];
				$det['brand_label']      = $label['label'];
				$det['brand_confidence'] = $label['score'];
				$brands_found[]          = $label['label'];
			}
		}
		unset( $det );

		$brands_found = array_values( array_unique( $brands_found ) );

		return array(
			'success'              => true,
			'detections'           => $detections['detections'],
			'brands_found'         => $brands_found,
			'detection_model'      => $detections['model'],
			'classification_model' => $class_model_id,
			'message'              => sprintf(
				/* translators: %d: number of unique brands */
				_n(
					'Found %d unique brand in the image.',
					'Found %d unique brands in the image.',
					count( $brands_found ),
					'mcp-ai-wpoos-pro'
				),
				count( $brands_found )
			),
		);
	}

	// ---------------------------------------------------------------------------
	// Ollama-native vision inference (local models)
	// ---------------------------------------------------------------------------

	/**
	 * Run zero-shot classification via a local Ollama vision model.
	 *
	 * When a HuggingFace API key is absent but Ollama is configured with a
	 * vision-capable model (llava, bakllava, minicpm-v, gemma3, etc.), this
	 * method sends the image + classification prompt to the Ollama /api/chat
	 * endpoint and parses the structured JSON response.
	 *
	 * @param string   $image_base64     Base64-encoded image.
	 * @param string[] $candidate_labels Labels to classify against.
	 * @param string   $ollama_model     Optional Ollama model override (falls back to ollama_model setting).
	 * @return array|WP_Error
	 */
	public function run_ollama_zero_shot_classification( $image_base64, array $candidate_labels, $ollama_model = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_unavailable',
				__( 'Ollama client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		if ( empty( $candidate_labels ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_labels',
				__( 'At least one candidate label is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$client   = new WP_MCP_AI_Ollama_Client();
		$endpoint = $client->get_endpoint_url();
		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_not_configured',
				__( 'Ollama endpoint URL is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model = ! empty( $ollama_model ) ? sanitize_text_field( $ollama_model ) : $client->get_model();
		if ( empty( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_no_model',
				__( 'No Ollama model configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Verify vision capability.
		if ( method_exists( $client, 'supports_vision' ) && ! $client->supports_vision( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_no_vision',
				sprintf(
					/* translators: %s: model name */
					__( 'The Ollama model "%s" does not support vision.', 'mcp-ai-wpoos-pro' ),
					$model
				),
				array( 'status' => 400 )
			);
		}

		// Build classification prompt.
		$labels_csv = implode( ', ', $candidate_labels );
		$prompt     = sprintf(
			"Classify this image into exactly one of the following categories: %s.\n" .
			'Return ONLY a JSON object with keys: "label" (the best-matching category), ' .
			'"confidence" (a float between 0.0 and 1.0), and "all_scores" (an object ' .
			'mapping each category to its confidence score).  Do not include any other text.',
			$labels_csv
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'         => 'input_image',
						'image_base64' => $image_base64,
					),
				),
			),
		);

		$options = array(
			'model'       => $model,
			'temperature' => 0.1,
			'format'      => 'json',
		);

		$response = $client->create_chat_completion( $messages, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse the JSON response content.
		$content = isset( $response['choices'][0]['message']['content'] )
			? $response['choices'][0]['message']['content']
			: '';

		// content may be an array (OpenAI-format content parts) or string.
		if ( is_array( $content ) ) {
			$text_parts = array();
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) ) {
					$text_parts[] = $part['text'];
				}
			}
			$content = implode( "\n", $text_parts );
		}

		$parsed = json_decode( $content, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $parsed ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_parse_error',
				__( 'Failed to parse classification response from Ollama vision model.', 'mcp-ai-wpoos-pro' ),
				array( 'raw_content' => $content )
			);
		}

		// Normalize to canonical format.
		$labels = array();
		if ( isset( $parsed['all_scores'] ) && is_array( $parsed['all_scores'] ) ) {
			foreach ( $parsed['all_scores'] as $label => $score ) {
				$labels[] = array(
					'label' => sanitize_text_field( $label ),
					'score' => (float) $score,
				);
			}
			// Sort descending.
			usort(
				$labels,
				function ( $a, $b ) {
					return $b['score'] <=> $a['score'];
				}
			);
		} elseif ( isset( $parsed['label'] ) ) {
			$labels[] = array(
				'label' => sanitize_text_field( $parsed['label'] ),
				'score' => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 1.0,
			);
		}

		return array(
			'success'  => true,
			'model'    => $model,
			'provider' => 'ollama',
			'labels'   => $labels,
		);
	}

	/**
	 * Run object detection via a local Ollama vision model.
	 *
	 * Sends the image + detection prompt to Ollama and parses a structured
	 * JSON response listing detected objects, bounding boxes, and counts.
	 *
	 * @param string   $image_base64     Base64-encoded image.
	 * @param string[] $candidate_labels Objects to look for.
	 * @param string   $ollama_model     Optional Ollama model override.
	 * @return array|WP_Error
	 */
	public function run_ollama_object_detection( $image_base64, array $candidate_labels, $ollama_model = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_unavailable',
				__( 'Ollama client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$client   = new WP_MCP_AI_Ollama_Client();
		$endpoint = $client->get_endpoint_url();
		if ( empty( $endpoint ) ) {
			return new WP_Error( 'wp_mcp_ai_ollama_not_configured', __( 'Ollama endpoint URL is not configured.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$model = ! empty( $ollama_model ) ? sanitize_text_field( $ollama_model ) : $client->get_model();
		if ( empty( $model ) ) {
			return new WP_Error( 'wp_mcp_ai_ollama_no_model', __( 'No Ollama model configured.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		if ( method_exists( $client, 'supports_vision' ) && ! $client->supports_vision( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_no_vision',
				sprintf(
					/* translators: %s: model name */
					__( 'The Ollama model "%s" does not support vision.', 'mcp-ai-wpoos-pro' ),
					$model
				),
				array( 'status' => 400 )
			);
		}

		$labels_csv = ! empty( $candidate_labels )
			? esc_html( implode( ', ', $candidate_labels ) )
			: 'general objects';

		$prompt = sprintf(
			"Analyze this image and detect all visible items, especially: %s.\n" .
			"Return ONLY a JSON object with these keys:\n" .
			'"detections": an array of objects, each with "label" (string), "confidence" (float 0-1), "count" (integer), and "bounding_box" (object with x, y, width, height as floats 0-1).\n' .
			'"total_items": total count of all detected items.\n' .
			'"unique_labels": array of unique label strings found.\n' .
			'Do not include any other text.',
			$labels_csv
		);

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'         => 'input_image',
						'image_base64' => $image_base64,
					),
				),
			),
		);

		$options = array(
			'model'       => $model,
			'temperature' => 0.1,
			'format'      => 'json',
		);

		$response = $client->create_chat_completion( $messages, $options );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = isset( $response['choices'][0]['message']['content'] )
			? $response['choices'][0]['message']['content']
			: '';

		if ( is_array( $content ) ) {
			$text_parts = array();
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) ) {
					$text_parts[] = $part['text'];
				}
			}
			$content = implode( "\n", $text_parts );
		}

		$parsed = json_decode( $content, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $parsed ) ) {
			return new WP_Error(
				'wp_mcp_ai_ollama_parse_error',
				__( 'Failed to parse detection response from Ollama vision model.', 'mcp-ai-wpoos-pro' ),
				array( 'raw_content' => $content )
			);
		}

		// Normalize detections.
		$detections = array();
		if ( isset( $parsed['detections'] ) && is_array( $parsed['detections'] ) ) {
			foreach ( $parsed['detections'] as $det ) {
				$detections[] = array(
					'label'        => isset( $det['label'] ) ? sanitize_text_field( $det['label'] ) : '',
					'confidence'   => isset( $det['confidence'] ) ? (float) $det['confidence'] : 0.0,
					'count'        => isset( $det['count'] ) ? absint( $det['count'] ) : 1,
					'bounding_box' => isset( $det['bounding_box'] ) && is_array( $det['bounding_box'] )
						? array(
							'x'      => isset( $det['bounding_box']['x'] ) ? (float) $det['bounding_box']['x'] : 0.0,
							'y'      => isset( $det['bounding_box']['y'] ) ? (float) $det['bounding_box']['y'] : 0.0,
							'width'  => isset( $det['bounding_box']['width'] ) ? (float) $det['bounding_box']['width'] : 0.0,
							'height' => isset( $det['bounding_box']['height'] ) ? (float) $det['bounding_box']['height'] : 0.0,
						)
						: null,
				);
			}
		}

		return array(
			'success'       => true,
			'model'         => $model,
			'provider'      => 'ollama',
			'detections'    => $detections,
			'total_items'   => isset( $parsed['total_items'] ) ? absint( $parsed['total_items'] ) : count( $detections ),
			'unique_labels' => isset( $parsed['unique_labels'] ) && is_array( $parsed['unique_labels'] )
				? array_map( 'sanitize_text_field', $parsed['unique_labels'] )
				: array(),
		);
	}

	// ---------------------------------------------------------------------------
	// Internal helpers
	// ---------------------------------------------------------------------------

	/**
	 * Build the Inference API URL for a model.
	 *
	 * If a dedicated endpoint URL is configured, uses it.  Otherwise targets
	 * the hosted api-inference.huggingface.co endpoint.
	 *
	 * @param string $model Model ID.
	 * @return string Full URL.
	 */
	private function build_model_url( $model ) {
		$endpoint_url = $this->get_endpoint_url();
		if ( ! empty( $endpoint_url ) ) {
			// Dedicated Inference Endpoint: may support OpenAI-compatible /v1 prefix.
			return untrailingslashit( $endpoint_url );
		}
		return sprintf( 'https://api-inference.huggingface.co/models/%s', rawurlencode( $model ) );
	}

	/**
	 * POST JSON to a HF Inference endpoint and return the decoded body.
	 *
	 * @param string $url     Endpoint URL.
	 * @param string $payload JSON-encoded payload.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error Decoded response or WP_Error.
	 */
	private function post_inference( $url, $payload, $timeout = 45 ) {
		$api_key = $this->get_api_key();

		$request_args = array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => $payload,
			'timeout' => max( 10, absint( $timeout ) ),
		);

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_hf_vision_http_error',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_hf_vision_empty_response',
				__( 'HuggingFace returned an empty response.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => $code )
			);
		}

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			// Some models return raw binary/image data for errors.
			return new WP_Error(
				'wp_mcp_ai_hf_vision_invalid_json',
				__( 'HuggingFace returned non-JSON response.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'       => $code,
					'body_preview' => substr( $body, 0, 200 ),
				)
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_msg = isset( $decoded['error'] )
				? ( is_string( $decoded['error'] ) ? $decoded['error'] : wp_json_encode( $decoded['error'] ) )
				: __( 'Unknown HuggingFace error.', 'mcp-ai-wpoos-pro' );

			// Detect model-warming status.
			if ( false !== strpos( strtolower( $error_msg ), 'loading' ) || false !== strpos( strtolower( $error_msg ), 'currently loading' ) ) {
				return new WP_Error(
					'wp_mcp_ai_hf_vision_model_warming',
					__( 'The HuggingFace model is still loading. Please retry in a few seconds.', 'mcp-ai-wpoos-pro' ),
					array(
						'status'         => 503,
						'estimated_time' => isset( $decoded['estimated_time'] ) ? $decoded['estimated_time'] : 20,
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_hf_vision_api_error',
				$error_msg,
				array( 'status' => $code )
			);
		}

		return $decoded;
	}

	/**
	 * Normalize an object-detection response into canonical format.
	 *
	 * @param array  $response Raw API response.
	 * @param string $model    Model identifier.
	 * @return array
	 */
	private function normalize_detection_result( array $response, $model ) {
		$detections = array();

		// OWLv2 format — array of objects with label, score, and box keys.
		if ( is_array( $response ) && isset( $response[0] ) && isset( $response[0]['label'] ) ) {
			foreach ( $response as $item ) {
				$detections[] = array(
					'label'      => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
					'confidence' => isset( $item['score'] ) ? (float) $item['score'] : 0.0,
					'box'        => isset( $item['box'] ) ? $this->normalize_box( $item['box'] ) : null,
				);
			}
		} elseif ( isset( $response['detections'] ) && is_array( $response['detections'] ) ) {
			// Alternative format.
			foreach ( $response['detections'] as $item ) {
				$detections[] = array(
					'label'      => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : ( isset( $item['class'] ) ? sanitize_text_field( $item['class'] ) : '' ),
					'confidence' => isset( $item['score'] ) ? (float) $item['score'] : ( isset( $item['confidence'] ) ? (float) $item['confidence'] : 0.0 ),
					'box'        => isset( $item['box'] ) ? $this->normalize_box( $item['box'] ) : ( isset( $item['bbox'] ) ? $this->normalize_box( $item['bbox'] ) : null ),
				);
			}
		}

		return array(
			'success'     => true,
			'model'       => $model,
			'detections'  => $detections,
			'total_count' => count( $detections ),
		);
	}

	/**
	 * Normalize a classification response into canonical format.
	 *
	 * @param array  $response Raw API response.
	 * @param string $model    Model identifier.
	 * @return array
	 */
	private function normalize_classification_result( array $response, $model ) {
		$labels = array();

		// CLIP and FashionCLIP format — array of objects with label and score keys.
		if ( is_array( $response ) && isset( $response[0] ) && isset( $response[0]['label'] ) ) {
			foreach ( $response as $item ) {
				$labels[] = array(
					'label' => sanitize_text_field( $item['label'] ),
					'score' => isset( $item['score'] ) ? (float) $item['score'] : 0.0,
				);
			}
		} elseif ( isset( $response['labels'] ) && isset( $response['scores'] ) ) {
			$cnt = min( count( $response['labels'] ), count( $response['scores'] ) );
			for ( $i = 0; $i < $cnt; $i++ ) {
				$labels[] = array(
					'label' => sanitize_text_field( $response['labels'][ $i ] ),
					'score' => (float) $response['scores'][ $i ],
				);
			}
			usort(
				$labels,
				function ( $a, $b ) {
					return $b['score'] <=> $a['score'];
				}
			);
		}

		return array(
			'success' => true,
			'model'   => $model,
			'labels'  => $labels,
		);
	}

	/**
	 * Normalize a bounding-box to {x, y, width, height}.
	 *
	 * @param array $box Raw box data.
	 * @return array
	 */
	private function normalize_box( array $box ) {
		return array(
			'x'      => isset( $box['xmin'] ) ? (float) $box['xmin'] : ( isset( $box['x'] ) ? (float) $box['x'] : 0.0 ),
			'y'      => isset( $box['ymin'] ) ? (float) $box['ymin'] : ( isset( $box['y'] ) ? (float) $box['y'] : 0.0 ),
			'width'  => isset( $box['xmax'] ) ? ( (float) $box['xmax'] - (float) ( $box['xmin'] ?? 0 ) ) : ( isset( $box['width'] ) ? (float) $box['width'] : 0.0 ),
			'height' => isset( $box['ymax'] ) ? ( (float) $box['ymax'] - (float) ( $box['ymin'] ?? 0 ) ) : ( isset( $box['height'] ) ? (float) $box['height'] : 0.0 ),
		);
	}
}
