<?php
/**
 * Extended Cognition Tool — Capture Visual
 *
 * Implements the active sensing loop for the camera sensor.
 * The tool pushes a capture request to the browser via the sensor queue
 * (SSE-based), then polls for the returned frame. The captured frame is
 * either returned as base64 JPEG for immediate AI vision analysis or
 * stored as a WordPress media attachment.
 *
 * This mirrors Clark & Chalmers' "Otto's notebook" model: the AI agent
 * actively requests perceptual access rather than passively receiving data.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Camera capture tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Capture_Visual implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_capture_visual';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Capture Visual (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Capture a still frame from the user\'s camera for AI vision analysis.', 'mcp-ai-wpoos' );
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
			'name'                => 'ext_cog_capture_visual',
			'description'         => 'Capture a still frame from the user\'s camera (visual cortex input). The AI agent actively requests a camera snapshot, which the browser captures and returns as a base64 JPEG image. Use this to see what is in front of the user\'s device — objects, text, environments, body language, or any visual context relevant to the current conversation. Requires camera permission and a secure connection.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'    => array(
						'type'        => 'string',
						'description' => 'Active chat session ID used to route the capture request to the correct browser tab.',
					),
					'resolution'    => array(
						'type'        => 'string',
						'enum'        => array( 'low', 'medium', 'high' ),
						'description' => 'Capture resolution. low=320x240, medium=640x480, high=1280x720. Default: medium.',
						'default'     => 'medium',
					),
					'store'         => array(
						'type'        => 'boolean',
						'description' => 'If true, save the captured frame as a WordPress media attachment. Default: false.',
						'default'     => false,
					),
					'analysis_hint' => array(
						'type'        => 'string',
						'description' => 'Optional hint describing what to look for in the captured image (e.g. "identify objects on the desk", "read any visible text").',
						'maxLength'   => 500,
					),
					'timeout_ms'    => array(
						'type'        => 'integer',
						'description' => 'Maximum milliseconds to wait for the browser to return the captured frame. Default: 10000.',
						'minimum'     => 3000,
						'maximum'     => 30000,
						'default'     => 10000,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'sensors', 'vision' ),
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
			return new WP_Error( 'https_required', __( 'Camera capture requires a secure (HTTPS) connection.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_camera'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The camera sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos' ) );
		}

		$session_id    = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$resolution    = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : 'medium';
		$store         = ! empty( $arguments['store'] );
		$analysis_hint = isset( $arguments['analysis_hint'] ) ? sanitize_text_field( $arguments['analysis_hint'] ) : '';
		$timeout_ms    = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 10000;

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required to route sensor requests to the browser.', 'mcp-ai-wpoos' ) );
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Rate limit check.
		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'camera', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Camera capture rate limit exceeded. Please wait before requesting another capture.', 'mcp-ai-wpoos' ) );
		}

		$resolution_map = array(
			'low'    => array(
				'width'  => 320,
				'height' => 240,
			),
			'medium' => array(
				'width'  => 640,
				'height' => 480,
			),
			'high'   => array(
				'width'  => 1280,
				'height' => 720,
			),
		);
		$res            = isset( $resolution_map[ $resolution ] ) ? $resolution_map[ $resolution ] : $resolution_map['medium'];

		// Push capture request to browser queue.
		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'       => 'capture_visual',
				'request_id' => $request_id,
				'resolution' => $res,
				'store'      => $store,
			)
		);

		// Poll for browser response (the browser posts back via REST).
		$timeout_s  = ceil( $timeout_ms / 1000 );
		$poll_start = time();
		$captured   = null;

		while ( ( time() - $poll_start ) < $timeout_s ) {
			$data = WP_MCP_AI_Ext_Cog_Sensor_Session::consume_data( $post_id, $request_id );
			if ( null !== $data ) {
				$captured = $data;
				break;
			}
			// Short sleep to avoid hammering the DB.
			usleep( 300000 ); // 300ms.
		}

		if ( null === $captured ) {
			return new WP_Error(
				'capture_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Camera capture timed out after %d seconds. Ensure the browser tab is open and camera permission is granted.', 'mcp-ai-wpoos' ),
					$timeout_s
				)
			);
		}

		$result = array(
			'success'       => true,
			'sensor'        => 'camera',
			'captured_at'   => $captured['captured_at'],
			'resolution'    => $res,
			'image_base64'  => isset( $captured['image_base64'] ) ? $captured['image_base64'] : null,
			'image_mime'    => 'image/jpeg',
			'attachment_id' => isset( $captured['attachment_id'] ) ? absint( $captured['attachment_id'] ) : null,
		);

		if ( $analysis_hint ) {
			$result['analysis_hint'] = $analysis_hint;
			$result['message']       = sprintf(
				/* translators: %s: analysis hint */
				__( 'Camera frame captured. Analysis hint: %s', 'mcp-ai-wpoos' ),
				$analysis_hint
			);
		} else {
			$result['message'] = __( 'Camera frame captured successfully. Analyze the image_base64 data to interpret what the user sees.', 'mcp-ai-wpoos' );
		}

		return $result;
	}
}
