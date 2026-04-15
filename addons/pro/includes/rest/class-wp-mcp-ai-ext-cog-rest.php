<?php
/**
 * NV oOS Extended Cognition — REST API Controller
 *
 * Provides three REST endpoints that implement the active sensing loop:
 *
 * 1. GET  /mcp-ai/v1/ext-cog/sensor-queue/{session_id}
 *    SSE endpoint the browser JS polls to receive pending sensor requests.
 *
 * 2. POST /mcp-ai/v1/ext-cog/sensor-data
 *    Receives captured sensor data sent back from the browser.
 *
 * 3. GET  /mcp-ai/v1/ext-cog/sensor-permissions
 *    Returns current per-sensor enabled state (informational).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the Extended Cognition Toolkit.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ext_Cog_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Maximum base64 payload size (bytes) for sensor data uploads.
	 * Default 3MB — slightly above the 2MB setting default to allow encoding overhead.
	 *
	 * @var int
	 */
	const MAX_PAYLOAD_BYTES = 3145728;

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		// SSE queue endpoint — browser polls this to receive sensor requests from AI.
		register_rest_route(
			self::NAMESPACE,
			'/ext-cog/sensor-queue/(?P<session_id>[a-zA-Z0-9_\-]{1,64})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sensor_queue' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Sensor data upload — browser posts captured data back.
		register_rest_route(
			self::NAMESPACE,
			'/ext-cog/sensor-data',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive_sensor_data' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'session_id'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'request_id'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sensor_type' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'camera', 'screen', 'audio', 'motion', 'permission' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'data'        => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		// Permissions info endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/ext-cog/sensor-permissions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_sensor_permissions' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission callback: logged-in user or valid nonce.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public static function check_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required WordPress REST API permission callback signature.
		// Nonce-verified requests from the same origin.
		if ( is_user_logged_in() ) {
			return true;
		}

		// Guest access: check if enabled in settings.
		$settings = wp_mcp_ai_ext_cog_get_settings();
		if ( ! empty( $settings['guest_access'] ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'mcp-ai-wpoos' ), array( 'status' => 401 ) );
	}

	/**
	 * GET /ext-cog/sensor-queue/{session_id}
	 *
	 * Returns pending sensor requests for the given session.
	 * The browser JS polls this endpoint (long-poll or short-poll) and
	 * executes any returned sensor capture requests.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_sensor_queue( $request ) {
		if ( ! wp_mcp_ai_ext_cog_is_enabled() ) {
			return new WP_Error( 'addon_disabled', __( 'Extended Cognition Toolkit is disabled.', 'mcp-ai-wpoos' ), array( 'status' => 503 ) );
		}

		$session_id = $request->get_param( 'session_id' );
		$user_id    = get_current_user_id();
		$post_id    = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$requests = WP_MCP_AI_Ext_Cog_Sensor_Session::pop_requests( $post_id );

		return rest_ensure_response(
			array(
				'success'    => true,
				'session_id' => $session_id,
				'requests'   => $requests,
				'count'      => count( $requests ),
			)
		);
	}

	/**
	 * POST /ext-cog/sensor-data
	 *
	 * Receives captured sensor data from the browser and stores it
	 * so the polling PHP tool can consume it.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive_sensor_data( $request ) {
		if ( ! wp_mcp_ai_ext_cog_is_enabled() ) {
			return new WP_Error( 'addon_disabled', __( 'Extended Cognition Toolkit is disabled.', 'mcp-ai-wpoos' ), array( 'status' => 503 ) );
		}

		$session_id  = $request->get_param( 'session_id' );
		$request_id  = $request->get_param( 'request_id' );
		$sensor_type = $request->get_param( 'sensor_type' );
		$data        = $request->get_param( 'data' );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', __( 'Data must be an object.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Enforce payload size limit.
		$settings       = wp_mcp_ai_ext_cog_get_settings();
		$max_size_bytes = absint( $settings['max_capture_size_kb'] ) * 1024;
		$body           = $request->get_body();
		if ( strlen( $body ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'payload_too_large',
				__( 'Sensor data payload exceeds the maximum allowed size.', 'mcp-ai-wpoos' ),
				array( 'status' => 413 )
			);
		}

		// Validate base64 image data if present.
		if ( isset( $data['image_base64'] ) ) {
			if ( ! self::validate_base64_image( $data['image_base64'], $max_size_bytes ) ) {
				return new WP_Error( 'invalid_image', __( 'Invalid or oversized image data.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
			}
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Sanitize incoming data fields.
		$clean_data                = self::sanitize_sensor_data( $data, $sensor_type );
		$clean_data['sensor_type'] = $sensor_type;

		WP_MCP_AI_Ext_Cog_Sensor_Session::store_data( $post_id, $request_id, $clean_data );

		// Optionally save to media library if store=true was requested.
		$attachment_id = null;
		if ( ! empty( $data['store'] ) && ! empty( $data['image_base64'] ) && is_user_logged_in() ) {
			$attachment_id = self::save_image_to_media( $data['image_base64'], $sensor_type, $session_id );
			if ( ! is_wp_error( $attachment_id ) ) {
				// Update stored record with attachment ID.
				$clean_data['attachment_id'] = $attachment_id;
				WP_MCP_AI_Ext_Cog_Sensor_Session::store_data( $post_id, $request_id, $clean_data );
			}
		}

		return rest_ensure_response(
			array(
				'success'       => true,
				'session_id'    => $session_id,
				'request_id'    => $request_id,
				'sensor_type'   => $sensor_type,
				'attachment_id' => $attachment_id && ! is_wp_error( $attachment_id ) ? absint( $attachment_id ) : null,
			)
		);
	}

	/**
	 * GET /ext-cog/sensor-permissions
	 *
	 * Returns the current enabled state of each sensor type from settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function get_sensor_permissions( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required WordPress REST API callback signature.
		$settings = wp_mcp_ai_ext_cog_get_settings();

		return rest_ensure_response(
			array(
				'success'      => true,
				'sensors'      => array(
					'camera'     => ! empty( $settings['sensor_camera'] ),
					'microphone' => ! empty( $settings['sensor_microphone'] ),
					'screen'     => ! empty( $settings['sensor_screen'] ),
					'motion'     => ! empty( $settings['sensor_motion'] ),
				),
				'guest_access' => ! empty( $settings['guest_access'] ),
				'gdpr_consent' => ! empty( $settings['gdpr_consent'] ),
			)
		);
	}

	/**
	 * Validate a base64-encoded image string.
	 *
	 * Checks MIME type is an image and byte size is within limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base64   Base64 image data (may include data URI prefix).
	 * @param int    $max_bytes Maximum allowed decoded byte size.
	 * @return bool
	 */
	private static function validate_base64_image( $base64, $max_bytes ) {
		if ( ! is_string( $base64 ) || empty( $base64 ) ) {
			return false;
		}

		// Strip data URI prefix if present.
		$raw = $base64;
		if ( strpos( $raw, ',' ) !== false ) {
			$parts = explode( ',', $raw, 2 );
			$raw   = isset( $parts[1] ) ? $parts[1] : $raw;

			// Validate MIME from data URI header.
			$header = $parts[0];
			if ( strpos( $header, 'data:image/' ) !== 0 ) {
				return false;
			}
		}

		// Check decoded byte size.
		$decoded_size = strlen( $raw ) * 3 / 4;
		if ( $decoded_size > $max_bytes ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize incoming sensor data based on sensor type.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data        Raw sensor data from browser.
	 * @param string $sensor_type Sensor type slug.
	 * @return array Sanitized data.
	 */
	private static function sanitize_sensor_data( array $data, $sensor_type ) {
		$clean = array();

		switch ( $sensor_type ) {
			case 'camera':
			case 'screen':
				if ( isset( $data['image_base64'] ) ) {
					$clean['image_base64'] = $data['image_base64']; // Validated separately.
				}
				if ( isset( $data['dimensions'] ) && is_array( $data['dimensions'] ) ) {
					$clean['dimensions'] = array(
						'width'  => absint( $data['dimensions']['width'] ?? 0 ),
						'height' => absint( $data['dimensions']['height'] ?? 0 ),
					);
				}
				break;

			case 'audio':
				if ( isset( $data['transcript'] ) ) {
					$clean['transcript'] = sanitize_text_field( $data['transcript'] );
				}
				if ( isset( $data['ambient_label'] ) ) {
					$clean['ambient_label'] = sanitize_text_field( $data['ambient_label'] );
				}
				if ( isset( $data['language_detected'] ) ) {
					$clean['language_detected'] = sanitize_text_field( $data['language_detected'] );
				}
				if ( isset( $data['transcription_confidence'] ) ) {
					$clean['transcription_confidence'] = floatval( $data['transcription_confidence'] );
				}
				break;

			case 'motion':
				foreach ( array( 'alpha', 'beta', 'gamma', 'accel_x', 'accel_y', 'accel_z', 'rot_alpha', 'rot_beta', 'rot_gamma' ) as $key ) {
					if ( isset( $data[ $key ] ) ) {
						$clean[ $key ] = floatval( $data[ $key ] );
					}
				}
				if ( isset( $data['is_mobile'] ) ) {
					$clean['is_mobile'] = (bool) $data['is_mobile'];
				}
				if ( isset( $data['device_class'] ) ) {
					$clean['device_class'] = sanitize_text_field( $data['device_class'] );
				}
				if ( isset( $data['activity_inference'] ) ) {
					$clean['activity_inference'] = sanitize_text_field( $data['activity_inference'] );
				}
				if ( isset( $data['absolute'] ) ) {
					$clean['absolute'] = (bool) $data['absolute'];
				}
				break;

			case 'permission':
				if ( isset( $data['permissions'] ) && is_array( $data['permissions'] ) ) {
					$allowed = array( 'granted', 'denied', 'prompt', 'not-supported' );
					foreach ( $data['permissions'] as $sensor => $state ) {
						$clean_sensor                          = sanitize_key( $sensor );
						$clean['permissions'][ $clean_sensor ] = in_array( $state, $allowed, true ) ? $state : 'unknown';
					}
				}
				break;
		}

		if ( isset( $data['store'] ) ) {
			$clean['store'] = (bool) $data['store'];
		}

		return $clean;
	}

	/**
	 * Save a base64-encoded image to the WordPress media library.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base64      Base64 image data.
	 * @param string $sensor_type Sensor type for filename.
	 * @param string $session_id  Session ID for filename.
	 * @return int|WP_Error Attachment ID on success.
	 */
	private static function save_image_to_media( $base64, $sensor_type, $session_id ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'upload_unavailable', __( 'Media upload not available.', 'mcp-ai-wpoos' ) );
		}

		// Strip data URI prefix.
		$raw  = $base64;
		$mime = 'image/jpeg';
		$ext  = 'jpg';

		if ( strpos( $raw, 'data:image/' ) === 0 ) {
			$parts = explode( ',', $raw, 2 );
			$raw   = isset( $parts[1] ) ? $parts[1] : $raw;
			if ( strpos( $parts[0], 'image/png' ) !== false ) {
				$mime = 'image/png';
				$ext  = 'png';
			}
		}

		$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return new WP_Error( 'decode_failed', __( 'Failed to decode image data.', 'mcp-ai-wpoos' ) );
		}

		$upload_dir = wp_upload_dir();
		$filename   = sanitize_file_name(
			'ext-cog-' . $sensor_type . '-' . substr( $session_id, 0, 8 ) . '-' . time() . '.' . $ext
		);
		$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $filepath, $decoded ) ) {
			return new WP_Error( 'write_failed', __( 'Failed to write image file.', 'mcp-ai-wpoos' ) );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $filepath );

		if ( ! is_wp_error( $attach_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		return $attach_id;
	}
}
