<?php
/**
 * Extended Cognition Tool — Capture Screen
 *
 * Implements the active sensing loop for screen capture (metacognitive mirror).
 * Triggers getDisplayMedia() in the browser to capture the full screen,
 * a window, a tab, or a specific DOM element. Returns a base64 PNG
 * for AI vision analysis.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen capture tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Ext_Cog_Capture_Screen implements WP_MCP_AI_Ext_Cog_Tool_Interface {

	use WP_MCP_AI_Ext_Cog_Sensor_Access;

	/**
	 * Get tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ext_cog_capture_screen';
	}

	/**
	 * Get tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Capture Screen (Extended Cognition)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Capture a screenshot of the user\'s screen, browser window, specific tab, or a CSS-selected DOM element (metacognitive mirror).', 'mcp-ai-wpoos' );
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
			'name'                => 'ext_cog_capture_screen',
			'description'         => 'Capture a screenshot of the user\'s screen, browser window, specific tab, or a CSS-selected DOM element (metacognitive mirror). The AI agent actively requests a visual snapshot of what the user is looking at. Use this to understand UI state, read on-screen content, analyze layouts, debug visual issues, or share context about the user\'s current view. Screen capture requires an explicit user permission prompt (getDisplayMedia).',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id' => array(
						'type'        => 'string',
						'description' => 'Active chat session ID for routing to the correct browser tab.',
					),
					'mode'       => array(
						'type'        => 'string',
						'enum'        => array( 'fullscreen', 'window', 'tab', 'element' ),
						'description' => 'Capture mode. fullscreen/window/tab use getDisplayMedia (requires user to choose). element captures a specific DOM element without a permission prompt. Default: tab.',
						'default'     => 'tab',
					),
					'selector'   => array(
						'type'        => 'string',
						'description' => 'CSS selector for element capture mode (e.g. "#main-content", ".product-image"). Only used when mode=element.',
						'maxLength'   => 200,
					),
					'annotate'   => array(
						'type'        => 'boolean',
						'description' => 'Overlay a timestamp on the captured screenshot. Default: false.',
						'default'     => false,
					),
					'store'      => array(
						'type'        => 'boolean',
						'description' => 'Save the screenshot as a WordPress media attachment. Default: false.',
						'default'     => false,
					),
					'timeout_ms' => array(
						'type'        => 'integer',
						'description' => 'Max milliseconds to wait for browser to return the screenshot. Default: 15000.',
						'minimum'     => 3000,
						'maximum'     => 30000,
						'default'     => 15000,
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
			return new WP_Error( 'https_required', __( 'Screen capture requires a secure (HTTPS) connection.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->current_user_can_use_sensors( $context ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to use sensory tools.', 'mcp-ai-wpoos' ) );
		}

		$settings = wp_mcp_ai_ext_cog_get_settings();

		if ( empty( $settings['sensor_screen'] ) ) {
			return new WP_Error( 'sensor_disabled', __( 'The screen sensor is disabled in Extended Cognition settings.', 'mcp-ai-wpoos' ) );
		}

		$session_id = isset( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : '';
		$mode       = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'tab';
		$selector   = isset( $arguments['selector'] ) ? sanitize_text_field( $arguments['selector'] ) : '';
		$annotate   = ! empty( $arguments['annotate'] );
		$store      = ! empty( $arguments['store'] );
		$timeout_ms = isset( $arguments['timeout_ms'] ) ? absint( $arguments['timeout_ms'] ) : 15000;

		if ( empty( $session_id ) ) {
			return new WP_Error( 'missing_session', __( 'A session_id is required to route sensor requests to the browser.', 'mcp-ai-wpoos' ) );
		}

		// Validate mode.
		$valid_modes = array( 'fullscreen', 'window', 'tab', 'element' );
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			$mode = 'tab';
		}

		// Require selector for element mode.
		if ( 'element' === $mode && empty( $selector ) ) {
			return new WP_Error( 'missing_selector', __( 'A CSS selector is required when mode=element.', 'mcp-ai-wpoos' ) );
		}

		$user_id = get_current_user_id();
		$post_id = WP_MCP_AI_Ext_Cog_Sensor_Session::get_or_create( $session_id, $user_id );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$rate_limit = absint( $settings['rate_limit'] );
		if ( ! WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit( $post_id, 'screen', $rate_limit ) ) {
			return new WP_Error( 'rate_limited', __( 'Screen capture rate limit exceeded. Please wait before requesting another capture.', 'mcp-ai-wpoos' ) );
		}

		$request_id = wp_generate_uuid4();
		WP_MCP_AI_Ext_Cog_Sensor_Session::push_request(
			$post_id,
			array(
				'type'       => 'capture_screen',
				'request_id' => $request_id,
				'mode'       => $mode,
				'selector'   => $selector,
				'annotate'   => $annotate,
				'store'      => $store,
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
			usleep( 300000 ); // 300ms.
		}

		if ( null === $captured ) {
			return new WP_Error(
				'capture_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Screen capture timed out after %d seconds. Ensure the browser tab is open and screen permission is granted.', 'mcp-ai-wpoos' ),
					$timeout_s
				)
			);
		}

		return array(
			'success'       => true,
			'sensor'        => 'screen',
			'captured_at'   => $captured['captured_at'],
			'mode'          => $mode,
			'image_base64'  => isset( $captured['image_base64'] ) ? $captured['image_base64'] : null,
			'image_mime'    => 'image/png',
			'dimensions'    => isset( $captured['dimensions'] ) ? $captured['dimensions'] : null,
			'attachment_id' => isset( $captured['attachment_id'] ) ? absint( $captured['attachment_id'] ) : null,
			'message'       => __( 'Screenshot captured. Analyze image_base64 to interpret the current screen state.', 'mcp-ai-wpoos' ),
		);
	}
}
