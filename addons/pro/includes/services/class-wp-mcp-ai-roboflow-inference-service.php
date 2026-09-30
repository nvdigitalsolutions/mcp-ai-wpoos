<?php
/**
 * Roboflow Inference Service
 *
 * Wraps the Roboflow Inference HTTP API (self-hosted Docker server, the
 * Serverless Cloud API, or a dedicated deployment) for the RF-DETR family of
 * real-time detection transformers: object detection, instance segmentation,
 * and keypoint detection (preview).
 *
 * RF-DETR (roboflow/rf-detr, ICLR 2026, arXiv:2511.09554) is a SOTA
 * real-time transformer on a DINOv2 backbone. Nano/Large detection aliases,
 * every segmentation alias, and the keypoint preview are Apache 2.0; the
 * XL/2XL detection aliases are PML 1.0 and are gated behind an explicit
 * admin consent toggle (va_roboflow_allow_pml).
 *
 * One client covers all three trust tiers:
 *   - self-hosted  http(s)://<host>:9001  (no API key required locally)
 *   - dedicated    customer deployment URL
 *   - serverless   https://serverless.roboflow.com (API key required)
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.1.90
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roboflow Inference Service.
 *
 * All public methods accept a base64-encoded image and return a canonical
 * detection result array, or WP_Error on failure.
 *
 * @since 1.1.90
 */
class WP_MCP_AI_Roboflow_Inference_Service {

	/**
	 * Default deployment target: the Roboflow Serverless Cloud API.
	 *
	 * @var string
	 */
	const DEFAULT_API_URL = 'https://serverless.roboflow.com';

	/**
	 * Default RF-DETR detection alias.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'rfdetr-small';

	/**
	 * Hard limit on the JSON payload size sent to the inference endpoint.
	 *
	 * Mirrors WP_MCP_AI_HF_Vision_Inference_Service::MAX_PAYLOAD_BYTES.
	 *
	 * @var int
	 */
	const MAX_PAYLOAD_BYTES = 5242880;

	/**
	 * Apache-2.0 model aliases. These are exposed by default.
	 *
	 * @var string[]
	 */
	const APACHE_MODEL_ALIASES = array(
		'rfdetr-nano',
		'rfdetr-small',
		'rfdetr-medium',
		'rfdetr-large',
		'rfdetr-seg-nano',
		'rfdetr-seg-small',
		'rfdetr-seg-medium',
		'rfdetr-seg-large',
		'rfdetr-seg-xlarge',
		'rfdetr-seg-2xlarge',
		'rfdetr-keypoint-preview',
	);

	/**
	 * PML-1.0 model aliases (rfdetr_plus extension). Gated behind consent.
	 *
	 * @var string[]
	 */
	const PML_MODEL_ALIASES = array(
		'rfdetr-xlarge',
		'rfdetr-2xlarge',
	);

	/**
	 * COCO 80-class label map used when the server omits the class name.
	 *
	 * @var string[]
	 */
	const COCO_CLASSES = array(
		'person',
		'bicycle',
		'car',
		'motorcycle',
		'airplane',
		'bus',
		'train',
		'truck',
		'boat',
		'traffic light',
		'fire hydrant',
		'stop sign',
		'parking meter',
		'bench',
		'bird',
		'cat',
		'dog',
		'horse',
		'sheep',
		'cow',
		'elephant',
		'bear',
		'zebra',
		'giraffe',
		'backpack',
		'umbrella',
		'handbag',
		'tie',
		'suitcase',
		'frisbee',
		'skis',
		'snowboard',
		'sports ball',
		'kite',
		'baseball bat',
		'baseball glove',
		'skateboard',
		'surfboard',
		'tennis racket',
		'bottle',
		'wine glass',
		'cup',
		'fork',
		'knife',
		'spoon',
		'bowl',
		'banana',
		'apple',
		'sandwich',
		'orange',
		'broccoli',
		'carrot',
		'hot dog',
		'pizza',
		'donut',
		'cake',
		'chair',
		'couch',
		'potted plant',
		'bed',
		'dining table',
		'toilet',
		'tv',
		'laptop',
		'mouse',
		'remote',
		'keyboard',
		'cell phone',
		'microwave',
		'oven',
		'toaster',
		'sink',
		'refrigerator',
		'book',
		'clock',
		'vase',
		'scissors',
		'teddy bear',
		'hair drier',
		'toothbrush',
	);

	/**
	 * Get the Roboflow API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		$all = get_option( 'wp_mcp_ai_settings', array() );
		$key = isset( $all['va_roboflow_api_key'] ) ? $all['va_roboflow_api_key'] : '';

		if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			$key = WP_MCP_AI_Credential_Resolver::get_api_key( 'roboflow' ) ?? '';
		}

		return sanitize_text_field( $key );
	}

	/**
	 * Get the configured deployment URL (may fall back to the serverless default).
	 *
	 * @return string
	 */
	public function get_api_url() {
		$all = get_option( 'wp_mcp_ai_settings', array() );
		$url = isset( $all['va_roboflow_api_url'] ) ? $all['va_roboflow_api_url'] : '';

		if ( '' === $url ) {
			$url = self::DEFAULT_API_URL;
		}

		return untrailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) );
	}

	/**
	 * Whether the Roboflow provider is usable end-to-end.
	 *
	 * A configured API key satisfies every tier; a self-host endpoint (loopback
	 * or private-network host) is usable without a key because the local
	 * Inference server does not require authentication.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( '' !== $this->get_api_key() ) {
			return true;
		}

		return $this->is_self_host_endpoint( $this->get_api_url() );
	}

	/**
	 * Get the default detection model alias from settings.
	 *
	 * @return string
	 */
	public function get_default_model() {
		$all   = get_option( 'wp_mcp_ai_settings', array() );
		$model = isset( $all['va_roboflow_model'] ) ? sanitize_text_field( $all['va_roboflow_model'] ) : '';

		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Get the configured catalog (fine-tuned) model reference, if any.
	 *
	 * Accepts either an RF-DETR-style alias or a workspace/project/version path.
	 *
	 * @return string
	 */
	public function get_catalog_model() {
		$all   = get_option( 'wp_mcp_ai_settings', array() );
		$model = isset( $all['va_roboflow_catalog_model'] ) ? sanitize_text_field( $all['va_roboflow_catalog_model'] ) : '';

		return $model;
	}

	/**
	 * Validate a model reference against the alias allowlists.
	 *
	 * Workspace/project/version paths (two or more path segments) are accepted
	 * verbatim for fine-tuned models. Bare aliases must be Apache-2.0, or
	 * PML-1.0 with the admin consent toggle enabled.
	 *
	 * @param string $model_id Model alias or workspace/project/version path.
	 * @return string|WP_Error Sanitized model reference or error.
	 */
	public function validate_model_alias( $model_id ) {
		$model_id = trim( sanitize_text_field( $model_id ) );

		if ( '' === $model_id ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_no_model',
				__( 'No RF-DETR model configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Workspace/project/version references contain at least one slash.
		if ( false !== strpos( $model_id, '/' ) ) {
			return $model_id;
		}

		if ( in_array( $model_id, self::APACHE_MODEL_ALIASES, true ) ) {
			return $model_id;
		}

		if ( in_array( $model_id, self::PML_MODEL_ALIASES, true ) ) {
			$all       = get_option( 'wp_mcp_ai_settings', array() );
			$allow_pml = ! empty( $all['va_roboflow_allow_pml'] );

			if ( ! $allow_pml ) {
				return new WP_Error(
					'wp_mcp_ai_roboflow_pml_not_enabled',
					__( 'This RF-DETR model is licensed under PML 1.0. Enable "Allow PML-licensed models" in the Vision Analysis settings to use it.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			return $model_id;
		}

		return new WP_Error(
			'wp_mcp_ai_roboflow_unknown_model',
			sprintf(
				/* translators: %s: model identifier */
				__( 'Unknown RF-DETR model alias "%s".', 'mcp-ai-wpoos-pro' ),
				$model_id
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Run inference against an RF-DETR (or fine-tuned) model.
	 *
	 * @param string $image_base64 Base64-encoded JPEG/PNG image (no data URI prefix).
	 * @param string $model_id     Model alias or workspace/project/version path.
	 * @param float  $confidence   Minimum confidence score (0.0–1.0).
	 * @param float  $iou          IoU threshold for NMS overlap (0.0–1.0).
	 * @param array  $extra        Extra inference parameters (reserved).
	 * @return array|WP_Error Canonical result or error.
	 */
	public function infer( $image_base64, $model_id = '', $confidence = 0.5, $iou = 0.5, array $extra = array() ) {
		$api_url = $this->get_api_url();

		$endpoint_check = $this->validate_endpoint( $api_url );
		if ( is_wp_error( $endpoint_check ) ) {
			return $endpoint_check;
		}

		$model_id = '' !== $model_id ? $model_id : $this->get_default_model();
		$model_id = $this->validate_model_alias( $model_id );
		if ( is_wp_error( $model_id ) ) {
			return $model_id;
		}

		$api_key = $this->get_api_key();
		if ( '' === $api_key && ! $this->is_self_host_endpoint( $api_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_missing_api_key',
				__( 'No Roboflow API key configured. Add one under Vision Analysis settings, or point the API URL at a self-hosted Roboflow Inference server.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$confidence = (float) max( 0.0, min( 1.0, $confidence ) );
		$iou        = (float) max( 0.0, min( 1.0, $iou ) );

		$payload = array(
			'image'      => array(
				'type'  => 'base64',
				'value' => $image_base64,
			),
			'confidence' => $confidence,
		);

		if ( $iou > 0 ) {
			$payload['iou_threshold'] = $iou;
		}

		if ( ! empty( $extra ) ) {
			$payload = array_merge( $payload, $extra );
		}

		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_payload_too_large',
				__( 'Image payload exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 413 )
			);
		}

		$url = $this->build_infer_url( $api_url, $model_id );

		$headers = array(
			'Content-Type' => 'application/json',
		);

		// The Roboflow API key travels as the raw value of the Authorization
		// header (api_key_transport="header" in the inference SDK). Self-hosted
		// deployments typically need no auth header at all.
		if ( '' !== $api_key ) {
			$headers['Authorization'] = $api_key;
		}

		$request_args = array(
			'headers' => $headers,
			'body'    => $encoded,
			'timeout' => 60,
		);

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_http_error',
				$response->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_empty_response',
				__( 'Roboflow returned an empty response.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => $code )
			);
		}

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err || ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_invalid_json',
				__( 'Roboflow returned a non-JSON response.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'       => $code,
					'body_preview' => substr( $body, 0, 200 ),
				)
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_msg = isset( $decoded['message'] )
				? sanitize_text_field( $decoded['message'] )
				: __( 'Unknown Roboflow error.', 'mcp-ai-wpoos-pro' );

			return new WP_Error(
				'wp_mcp_ai_roboflow_api_error',
				$error_msg,
				array( 'status' => $code )
			);
		}

		return $this->normalize_result( $decoded, $model_id );
	}

	/**
	 * Run inference and group the result into a count breakdown.
	 *
	 * Thin wrapper over infer() for the Vision Analysis toolkit; the grouping
	 * math lives in WP_MCP_AI_Vision_Count_Normalizer so counts never drift.
	 *
	 * @since 1.1.90
	 *
	 * @param string $image_base64  Base64-encoded image.
	 * @param string $model_id      Model alias or workspace/project/version path.
	 * @param float  $confidence    Minimum confidence score (0.0–1.0).
	 * @param bool   $include_boxes Whether to retain per-entry bounding boxes.
	 * @return array|WP_Error Count breakdown or error.
	 */
	public function count_objects( $image_base64, $model_id = '', $confidence = 0.5, $include_boxes = true ) {
		$result = $this->infer( $image_base64, $model_id, $confidence );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! class_exists( 'WP_MCP_AI_Vision_Count_Normalizer' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-vision-count-normalizer.php';
		}

		$counts = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $result['detections'], $include_boxes );

		return array(
			'success'     => true,
			'model'       => $result['model'],
			'counts'      => $counts,
			'total_items' => WP_MCP_AI_Vision_Count_Normalizer::total_from_breakdown( $counts ),
			'detections'  => $result['detections'],
		);
	}

	/**
	 * Build the /infer/{model_id} URL for a deployment target.
	 *
	 * @param string $api_url  Deployment base URL (untrailing-slashed).
	 * @param string $model_id Model alias or workspace/project/version path.
	 * @return string Full endpoint URL.
	 */
	private function build_infer_url( $api_url, $model_id ) {
		return sprintf( '%s/infer/%s', $api_url, implode( '/', array_map( 'rawurlencode', explode( '/', $model_id ) ) ) );
	}

	/**
	 * Validate the configured endpoint URL (SSRF + HTTPS discipline).
	 *
	 * Non-loopback hosts must be HTTPS. Private/loopback hosts (the standard
	 * self-hosted Inference server placement) may use HTTP. Any URL the plugin
	 * will POST to passes the shared SSRF URL guard first.
	 *
	 * @param string $url Endpoint URL.
	 * @return true|WP_Error
	 */
	private function validate_endpoint( $url ) {
		if ( '' === $url ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_no_endpoint',
				__( 'No Roboflow Inference endpoint configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_invalid_endpoint',
				__( 'The Roboflow endpoint must use http or https.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( 'http' === $scheme && ! $this->is_self_host_endpoint( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_roboflow_insecure_endpoint',
				__( 'Non-local Roboflow endpoints must use HTTPS.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
			$guard_check = WP_MCP_AI_Url_Guard::validate( $url );

			if ( is_wp_error( $guard_check ) && $this->is_self_host_endpoint( $url ) ) {
				// The admin explicitly configured this self-hosted Inference
				// server — allow its host through the SSRF guard for this
				// validation only (the guard's operator-allowlist seam).
				$configured_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$allow_host      = function ( $hosts, $host ) use ( $configured_host ) {
					if ( strtolower( (string) $host ) === $configured_host ) {
						$hosts[] = $configured_host;
					}
					return $hosts;
				};

				add_filter( WP_MCP_AI_Url_Guard::FILTER_ALLOWED_HOSTS, $allow_host, 10, 2 );
				$guard_check = WP_MCP_AI_Url_Guard::validate( $url );
				remove_filter( WP_MCP_AI_Url_Guard::FILTER_ALLOWED_HOSTS, $allow_host, 10 );
			}

			if ( is_wp_error( $guard_check ) ) {
				return $guard_check;
			}
		}

		return true;
	}

	/**
	 * Whether the endpoint host is local/private (no API key required).
	 *
	 * Self-hosted Roboflow Inference servers live at loopback addresses or on
	 * the private network; those deployments do not require an API key and
	 * are the only tier allowed to serve plain HTTP.
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	private function is_self_host_endpoint( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}

		// RFC-1918 + link-local ranges: the standard placement for a
		// self-hosted Inference container on a private network.
		$private_patterns = array(
			'/^10\.\d+\.\d+\.\d+$/',
			'/^192\.168\.\d+\.\d+$/',
			'/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/',
			'/^169\.254\.\d+\.\d+$/',
		);

		foreach ( $private_patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a raw Inference response into the canonical result shape.
	 *
	 * The canonical detection entry mirrors the HF vision service:
	 * `{label, confidence, box:{x,y,width,height}}` in normalized (0–1)
	 * coordinates, extended with optional `mask_points` (segmentation polygon,
	 * normalized) and `keypoints` (per-instance `{class,x,y,confidence}`,
	 * normalized). Provider-only fields never leak into tool output.
	 *
	 * @param array  $response Raw decoded response.
	 * @param string $model_id Model reference used for the request.
	 * @return array
	 */
	private function normalize_result( array $response, $model_id ) {
		$image_width  = isset( $response['image']['width'] ) ? max( 1, absint( $response['image']['width'] ) ) : 1;
		$image_height = isset( $response['image']['height'] ) ? max( 1, absint( $response['image']['height'] ) ) : 1;

		$predictions = isset( $response['predictions'] ) && is_array( $response['predictions'] )
			? $response['predictions']
			: array();

		$detections = array();

		foreach ( $predictions as $pred ) {
			if ( ! is_array( $pred ) ) {
				continue;
			}

			$confidence = isset( $pred['confidence'] ) ? (float) $pred['confidence'] : 0.0;
			$class_id   = isset( $pred['class_id'] ) ? absint( $pred['class_id'] ) : -1;

			$label = isset( $pred['class'] ) && '' !== (string) $pred['class']
				? sanitize_text_field( $pred['class'] )
				: ( isset( self::COCO_CLASSES[ $class_id ] ) ? self::COCO_CLASSES[ $class_id ] : '' );

			if ( '' === $label ) {
				continue;
			}

			$detection = array(
				'label'      => $label,
				'confidence' => round( min( 1.0, max( 0.0, $confidence ) ), 4 ),
			);

			// Roboflow reports x/y as the box center in pixels.
			if ( isset( $pred['x'], $pred['y'], $pred['width'], $pred['height'] ) ) {
				$x      = (float) $pred['x'];
				$y      = (float) $pred['y'];
				$width  = (float) $pred['width'];
				$height = (float) $pred['height'];

				$detection['box'] = array(
					'x'      => round( ( $x - ( $width / 2 ) ) / $image_width, 4 ),
					'y'      => round( ( $y - ( $height / 2 ) ) / $image_height, 4 ),
					'width'  => round( $width / $image_width, 4 ),
					'height' => round( $height / $image_height, 4 ),
				);
			}

			// Instance segmentation polygon (already normalized 0–1 upstream).
			if ( isset( $pred['points'] ) && is_array( $pred['points'] ) ) {
				$points = array();
				foreach ( $pred['points'] as $point ) {
					if ( ! is_array( $point ) || ! isset( $point['x'], $point['y'] ) ) {
						continue;
					}
					$points[] = array(
						'x' => round( (float) $point['x'], 4 ),
						'y' => round( (float) $point['y'], 4 ),
					);
				}
				if ( ! empty( $points ) ) {
					$detection['mask_points'] = $points;
				}
			}

			// Keypoints: pixel-space upstream, normalized here for consistency.
			if ( isset( $pred['keypoints'] ) && is_array( $pred['keypoints'] ) ) {
				$keypoints = array();
				foreach ( $pred['keypoints'] as $kp ) {
					if ( ! is_array( $kp ) || ! isset( $kp['x'], $kp['y'] ) ) {
						continue;
					}
					$keypoints[] = array(
						'class'      => isset( $kp['class'] ) ? sanitize_text_field( $kp['class'] ) : '',
						'x'          => round( (float) $kp['x'] / $image_width, 4 ),
						'y'          => round( (float) $kp['y'] / $image_height, 4 ),
						'confidence' => isset( $kp['confidence'] ) ? round( (float) $kp['confidence'], 4 ) : 0.0,
					);
				}
				if ( ! empty( $keypoints ) ) {
					$detection['keypoints'] = $keypoints;
				}
			}

			$detections[] = $detection;
		}

		return array(
			'success'      => true,
			'model'        => $model_id,
			'provider'     => 'roboflow',
			'detections'   => $detections,
			'total_count'  => count( $detections ),
			'inference_ms' => isset( $response['time'] ) ? round( (float) $response['time'] * 1000, 2 ) : null,
		);
	}
}
