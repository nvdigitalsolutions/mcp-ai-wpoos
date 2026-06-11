<?php
/**
 * Extended Cognition Tool — Recognize Products
 *
 * Fine-grained product identification from a single camera snapshot.
 * Designed for luxury/fashion/retail use cases: "How many Paco Rabanne
 * items are on this shelf, and which are Givenchy?"
 *
 * Supports three search modes:
 *  - zero_shot: FashionCLIP directly on the whole image
 *  - detect_then_classify: OWLv2 detection → FashionCLIP per detection
 *  - similarity: DINOv2 embedding extraction for future vector search
 *
 * Falls back to Ollama local vision models when no HuggingFace API key
 * is configured.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product recognition tool for the Extended Cognition toolkit.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Recognize_Products {

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_recognize_products';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Recognize Products (Extended Cognition)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Identify fashion, luxury, and retail products visible in the user\'s camera feed using FashionCLIP zero-shot classification, OWLv2 object detection, or DINOv2 feature extraction. Designed for shelf-stocking scenarios: count items per brand, identify specific products, and find similar-looking items. Supports HuggingFace Inference API and local Ollama vision models.', 'mcp-ai-wpoos-pro' );
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
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'ext_cog_recognize_products',
			'description'         => $this->get_description(),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'         => array(
						'type'        => 'string',
						'description' => 'Active chat session ID.',
					),
					'product_categories' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string', 'maxLength' => 50 ),
						'description' => 'Filter to specific product categories (e.g. ["fragrances", "handbags", "shoes", "watches"]). Helps the model narrow its search.',
						'maxItems'    => 20,
					),
					'brand_catalog'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string', 'maxLength' => 100 ),
						'description' => 'Curated list of brands to look for (e.g. ["Paco Rabanne", "Givenchy", "Dior"]). If omitted, falls back to the site\'s Product Brand taxonomy.',
						'maxItems'    => 100,
					),
					'search_mode'        => array(
						'type'        => 'string',
						'enum'        => array( 'zero_shot', 'detect_then_classify', 'similarity' ),
						'description' => 'Search strategy. "zero_shot" runs FashionCLIP on the whole image. "detect_then_classify" runs OWLv2 first, then classifies each detection. "similarity" extracts a DINOv2 embedding for future vector comparison. Default: detect_then_classify.',
						'default'     => 'detect_then_classify',
					),
					'max_results'        => array(
						'type'        => 'integer',
						'description' => 'Maximum number of unique products to return. Default: 20.',
						'minimum'     => 1,
						'maximum'     => 50,
						'default'     => 20,
					),
					'min_confidence'     => array(
						'type'        => 'number',
						'description' => 'Minimum confidence threshold (0.0–1.0). Default: 0.5.',
						'minimum'     => 0.0,
						'maximum'     => 1.0,
						'default'     => 0.5,
					),
					'provider'           => array(
						'type'        => 'string',
						'enum'        => array( 'auto', 'huggingface', 'ollama' ),
						'description' => 'Vision provider. Default: auto.',
						'default'     => 'auto',
					),
					'model'              => array(
						'type'        => 'string',
						'description' => 'Explicit model override.',
						'maxLength'   => 200,
					),
					'timeout_ms'         => array(
						'type'        => 'integer',
						'description' => 'Max ms for capture + inference. Default: 30000.',
						'minimum'     => 5000,
						'maximum'     => 90000,
						'default'     => 30000,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => $this->get_required_capability(),
			'category'            => array( 'extended-cognition', 'vision', 'product-recognition' ),
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
		if ( ! is_ssl() && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return new WP_Error( 'https_required', __( 'Product recognition requires a secure (HTTPS) connection.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();
		if ( empty( $settings['sensor_camera'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The camera sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Sanitize ---
		$session_id    = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$search_mode   = isset( $arguments['search_mode'] ) ? sanitize_text_field( $arguments['search_mode'] ) : 'detect_then_classify';
		$max_results   = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 20;
		$min_confidence = isset( $arguments['min_confidence'] ) ? (float) max( 0.0, min( 1.0, $arguments['min_confidence'] ) ) : 0.5;
		$provider      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$model_override = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';
		$timeout_ms    = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 30000;

		// Sanitize categories.
		$categories = array();
		if ( isset( $arguments['product_categories'] ) && is_array( $arguments['product_categories'] ) ) {
			$categories = array_map( 'sanitize_text_field', array_slice( $arguments['product_categories'], 0, 20 ) );
		}

		// Resolve brand catalogue.
		$brand_catalog = array();
		if ( isset( $arguments['brand_catalog'] ) && is_array( $arguments['brand_catalog'] ) ) {
			$brand_catalog = array_map( 'sanitize_text_field', array_slice( $arguments['brand_catalog'], 0, 100 ) );
		} elseif ( class_exists( 'WP_MCP_AI_Product_Brand_Taxonomy' ) ) {
			$brand_catalog = WP_MCP_AI_Product_Brand_Taxonomy::get_brand_labels( 100 );
		}

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// --- Capture ---
		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'camera', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Rate limit exceeded.', 'mcp-ai-wpoos-pro' ) );
		}

		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'       => 'capture_visual',
				'request_id' => $request_id,
				'resolution' => array( 'width' => 640, 'height' => 480 ),
				'store'      => false,
			)
		);

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
			return new WP_Error( 'capture_timeout', __( 'Camera capture timed out.', 'mcp-ai-wpoos-pro' ) );
		}

		$image_base64 = $captured['image_base64'];

		// --- Resolve provider ---
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

		// Build augmented labels: combine categories + brands for better accuracy.
		$labels = $brand_catalog;
		if ( ! empty( $categories ) ) {
			foreach ( $categories as $cat ) {
				foreach ( $brand_catalog as $brand ) {
					$labels[] = $brand . ' ' . $cat;
				}
			}
		}
		$labels = array_values( array_unique( $labels ) );

		// --- Execute ---
		if ( $use_ollama ) {
			$result = $service->run_ollama_zero_shot_classification( $image_base64, $labels, $model_override );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->build_product_result( $result['labels'], $max_results, $min_confidence, 'ollama', $result['model'], $captured['captured_at'], $categories );
		}

		if ( 'similarity' === $search_mode ) {
			// Extract embedding for future vector search.
			$emb_model = ! empty( $model_override ) ? $model_override : ( isset( $settings['hf_embedding_model'] ) ? $settings['hf_embedding_model'] : '' );
			$result    = $service->run_image_feature_extraction( $image_base64, $emb_model );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'     => true,
				'search_mode' => 'similarity',
				'provider'    => 'huggingface',
				'model'       => $result['model'],
				'embedding'   => $result['embedding'],
				'dimensions'  => $result['dimensions'],
				'captured_at' => $captured['captured_at'],
				'message'     => __( 'Image embedding extracted successfully. Use this vector for similarity search against a product catalogue.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( 'zero_shot' === $search_mode ) {
			$cls_model = ! empty( $model_override ) ? $model_override : ( isset( $settings['hf_classification_model'] ) ? $settings['hf_classification_model'] : '' );
			$result    = $service->run_zero_shot_classification( $image_base64, $labels, $cls_model );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->build_product_result( $result['labels'], $max_results, $min_confidence, 'huggingface', $result['model'], $captured['captured_at'], $categories );
		}

		// detect_then_classify (default).
		$det_model = ! empty( $model_override ) ? $model_override : ( isset( $settings['hf_detection_model'] ) ? $settings['hf_detection_model'] : '' );
		$cls_model = ! empty( $model_override ) ? $model_override : ( isset( $settings['hf_classification_model'] ) ? $settings['hf_classification_model'] : '' );

		$result = $service->run_brand_detection_pipeline( $image_base64, $labels, $det_model, $cls_model, $min_confidence );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Build product list from detections + brands.
		$products = $this->build_detected_products( $result, $categories );
		$products = array_slice( $products, 0, $max_results );

		// Compute brand summary.
		$brand_counts = array();
		foreach ( $result['detections'] as $det ) {
			$brand = isset( $det['brand_label'] ) ? $det['brand_label'] : ( isset( $det['label'] ) ? $det['label'] : '' );
			if ( ! empty( $brand ) ) {
				$brand_counts[ $brand ] = isset( $brand_counts[ $brand ] ) ? $brand_counts[ $brand ] + 1 : 1;
			}
		}

		arsort( $brand_counts );

		$unique_brands = array_keys( $brand_counts );

		return array(
			'success'        => true,
			'search_mode'    => 'detect_then_classify',
			'provider'       => 'huggingface',
			'products'       => $products,
			'total_detected' => count( $result['detections'] ),
			'unique_brands'  => $unique_brands,
			'brand_counts'   => $brand_counts,
			'captured_at'    => $captured['captured_at'],
			'message'        => sprintf(
				/* translators: 1: total detected, 2: unique brands */
				__( 'Found %1$d items across %2$d unique brands.', 'mcp-ai-wpoos-pro' ),
				count( $result['detections'] ),
				count( $unique_brands )
			),
		);
	}

	/**
	 * Build canonical product result array from classification output.
	 *
	 * @param array  $labels         Classified labels.
	 * @param int    $max            Maximum results.
	 * @param float  $min_confidence Minimum confidence.
	 * @param string $provider       Provider name.
	 * @param string $model          Model name.
	 * @param int    $captured_at    Timestamp.
	 * @param array  $categories     Product categories filter.
	 * @return array
	 */
	private function build_product_result( array $labels, $max, $min_confidence, $provider, $model, $captured_at, array $categories ) {
		$products    = array();
		$brands      = array();
		$category_hint = ! empty( $categories ) ? $categories[0] : '';

		foreach ( $labels as $item ) {
			if ( $item['score'] < $min_confidence ) {
				continue;
			}

			$products[] = array(
				'name'       => esc_html( $item['label'] ),
				'confidence' => $item['score'],
				'category'   => $category_hint,
			);

			$brands[] = $item['label'];

			if ( count( $products ) >= $max ) {
				break;
			}
		}

		$unique_brands = array_values( array_unique( $brands ) );

		return array(
			'success'        => true,
			'search_mode'    => 'zero_shot',
			'provider'       => $provider,
			'model'          => $model,
			'products'       => $products,
			'unique_brands'  => $unique_brands,
			'captured_at'    => $captured_at,
			'message'        => sprintf(
				/* translators: %d: number of unique brands */
				_n( 'Identified %d unique product/brand.', 'Identified %d unique products/brands.', count( $unique_brands ), 'mcp-ai-wpoos-pro' ),
				count( $unique_brands )
			),
		);
	}

	/**
	 * Build product list from detection pipeline output.
	 *
	 * @param array $pipeline_result Result from run_brand_detection_pipeline().
	 * @param array $categories      Product categories filter.
	 * @return array
	 */
	private function build_detected_products( array $pipeline_result, array $categories ) {
		$products    = array();
		$seen        = array();
		$category_hint = ! empty( $categories ) ? $categories[0] : '';

		foreach ( $pipeline_result['detections'] as $det ) {
			$label = isset( $det['brand_label'] ) ? $det['brand_label'] : ( isset( $det['label'] ) ? $det['label'] : '' );

			if ( empty( $label ) || isset( $seen[ $label ] ) ) {
				continue;
			}

			$seen[ $label ] = true;

			$products[] = array(
				'name'              => esc_html( $label ),
				'detection_confidence' => isset( $det['confidence'] ) ? (float) $det['confidence'] : 0.0,
				'brand_confidence'  => isset( $det['brand_confidence'] ) ? (float) $det['brand_confidence'] : 0.0,
				'category'          => $category_hint,
				'bounding_box'      => isset( $det['box'] ) ? $det['box'] : null,
			);
		}

		return $products;
	}

	/**
	 * Check if the current user (or guest) is allowed to use sensors.
	 *
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function current_user_can_use_sensors( array $context ) {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();
		if ( ! empty( $settings['guest_access'] ) && ! empty( $context['guest_request'] ) ) {
			return true;
		}

		return false;
	}
}
