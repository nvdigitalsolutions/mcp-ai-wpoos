<?php
/**
 * Extended Cognition Tool — Get Motion Context
 *
 * Reads device orientation and acceleration data (vestibular / proprioceptive input).
 * The browser reports DeviceOrientationEvent (alpha/beta/gamma) and
 * DeviceMotionEvent (acceleration, rotation rate) to help the AI understand
 * the user's physical context — standing, lying down, walking, device tilt, etc.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-ext-cog-tool.php';
require_once __DIR__ . '/trait-wp-mcp-ai-ext-cog-sensor-access.php';
/**
 * Motion context tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Get_Motion_Context implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_get_motion_context';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Motion Context (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Read device orientation and motion state for understanding the user\'s physical context.', 'mcp-ai-wpoos' );
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
			'name'                => 'ext_cog_get_motion_context',
			'description'         => 'Read the device orientation and motion state (proprioceptive / vestibular input). Returns gyroscope orientation angles (alpha/beta/gamma), linear acceleration, rotation rate, and a device-class inference (mobile/tablet/desktop). Use this to understand the user\'s physical context: are they standing, sitting, walking, or holding their phone at an angle? Useful for adaptive UX, accessibility, and embodied interaction design.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'   => array(
						'type'        => 'string',
						'description' => 'Active chat session ID for routing to the correct browser tab.',
					),
					'sample_count' => array(
						'type'        => 'integer',
						'description' => 'Number of motion samples to average before returning (1–20). Higher values give smoother readings. Default: 5.',
						'minimum'     => 1,
						'maximum'     => 20,
						'default'     => 5,
					),
					'timeout_ms'   => array(
						'type'        => 'integer',
						'description' => 'Max milliseconds to wait for browser motion data. Default: 5000.',
						'minimum'     => 1000,
						'maximum'     => 15000,
						'default'     => 5000,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'extended-cognition', 'sensors', 'motion' ),
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
		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_motion'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The motion sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos' ) );
		}

		$session_id   = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$sample_count = isset( $arguments['sample_count'] ) ? max( 1, min( 20, absint( $arguments['sample_count'] ) ) ) : 5;
		$timeout_ms   = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 5000;

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required to route sensor requests to the browser.', 'mcp-ai-wpoos' ) );
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'motion', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Motion sensor rate limit exceeded. Please wait before requesting another reading.', 'mcp-ai-wpoos' ) );
		}

		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'         => 'get_motion_context',
				'request_id'   => $request_id,
				'sample_count' => $sample_count,
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
			usleep( 250000 ); // 250ms.
		}

		if ( null === $captured ) {
			return new WP_Error(
				'capture_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Motion context request timed out after %d seconds. Device motion events may not be available (desktop browsers or permission not granted).', 'mcp-ai-wpoos' ),
					$timeout_s
				)
			);
		}

		return array(
			'success'            => true,
			'sensor'             => 'motion',
			'captured_at'        => $captured['captured_at'],
			'is_mobile'          => isset( $captured['is_mobile'] ) ? (bool) $captured['is_mobile'] : false,
			'device_class'       => isset( $captured['device_class'] ) ? sanitize_text_field( $captured['device_class'] ) : 'unknown',
			'orientation'        => array(
				'alpha'    => isset( $captured['alpha'] ) ? floatval( $captured['alpha'] ) : null,
				'beta'     => isset( $captured['beta'] ) ? floatval( $captured['beta'] ) : null,
				'gamma'    => isset( $captured['gamma'] ) ? floatval( $captured['gamma'] ) : null,
				'absolute' => isset( $captured['absolute'] ) ? (bool) $captured['absolute'] : false,
			),
			'acceleration'       => array(
				'x' => isset( $captured['accel_x'] ) ? floatval( $captured['accel_x'] ) : null,
				'y' => isset( $captured['accel_y'] ) ? floatval( $captured['accel_y'] ) : null,
				'z' => isset( $captured['accel_z'] ) ? floatval( $captured['accel_z'] ) : null,
			),
			'rotation_rate'      => array(
				'alpha' => isset( $captured['rot_alpha'] ) ? floatval( $captured['rot_alpha'] ) : null,
				'beta'  => isset( $captured['rot_beta'] ) ? floatval( $captured['rot_beta'] ) : null,
				'gamma' => isset( $captured['rot_gamma'] ) ? floatval( $captured['rot_gamma'] ) : null,
			),
			'activity_inference' => isset( $captured['activity_inference'] ) ? sanitize_text_field( $captured['activity_inference'] ) : '',
			'message'            => __( 'Device motion context captured. alpha/beta/gamma are orientation angles in degrees. acceleration is in m/s².', 'mcp-ai-wpoos' ),
		);
	}
}
