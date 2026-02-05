<?php
/**
 * Tool for creating WebChat rooms.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new WebChat room.
 */
class WP_MCP_AI_Tool_Create_WebChat_Room implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_webchat_room';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create WebChat Room', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new WebChat room for real-time video/audio communication. Returns room ID and URL for participants to join.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Title of the WebChat room.', 'mcp-ai-wpoos-pro' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Optional description for the room.', 'mcp-ai-wpoos-pro' ),
				),
				'max_participants' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of participants allowed. Must be between 2 and 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 2,
					'maximum'     => 100,
				),
				'allow_anonymous'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to allow anonymous participants without authentication.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool Whether the tool is available.
	 */
	public static function is_available() {
		// WebChat is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_webchat_integration'] );
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return __( 'WebChat integration is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'WebChat integration is not enabled in settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check availability.
		if ( ! self::is_available() ) {
			WP_MCP_AI_Logger::log_activity( 'Tool unavailable: create_webchat_room' );
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create WebChat rooms.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize inputs.
		$title            = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description      = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$max_participants = isset( $arguments['max_participants'] ) ? absint( $arguments['max_participants'] ) : 10;
		$allow_anonymous  = isset( $arguments['allow_anonymous'] ) ? (bool) $arguments['allow_anonymous'] : false;

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Room title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate max_participants range.
		$max_participants = max( 2, min( 100, $max_participants ) );

		// Create WebChat room post.
		$room_data = array(
			'post_type'    => 'mcp_ai_webchat_room',
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$room_id = wp_insert_post( $room_data, true );

		if ( is_wp_error( $room_id ) ) {
			WP_MCP_AI_Logger::log_error( 'Failed to create WebChat room: ' . $room_id->get_error_message() );
			return $room_id;
		}

		// Store room metadata.
		update_post_meta( $room_id, '_mcp_ai_webchat_max_participants', $max_participants );
		update_post_meta( $room_id, '_mcp_ai_webchat_allow_anonymous', $allow_anonymous );
		update_post_meta( $room_id, '_mcp_ai_webchat_active_participants', 0 );
		update_post_meta( $room_id, '_mcp_ai_webchat_status', 'active' );

		// Get signaling configuration.
		$settings         = get_option( 'wp_mcp_ai_webchat_settings', array() );
		$use_self_hosted  = isset( $settings['enable_self_hosted_signaling'] ) ? (bool) $settings['enable_self_hosted_signaling'] : true;
		$signaling_server = '';

		if ( $use_self_hosted ) {
			// Use self-hosted REST API endpoint.
			$signaling_server = rest_url( 'mcp-ai/v1/webchat/' );
		} elseif ( isset( $settings['default_signaling_server'] ) && $settings['default_signaling_server'] ) {
			// Use external WebSocket server.
			$signaling_server = $settings['default_signaling_server'];
		}

		if ( $signaling_server ) {
			update_post_meta( $room_id, '_mcp_ai_webchat_signaling_server', $signaling_server );
		}

		// Generate room URL.
		$room_url = get_permalink( $room_id );

		WP_MCP_AI_Logger::log_activity( sprintf( 'WebChat room created: %s (ID: %d)', $title, $room_id ) );

		return array(
			'summary'          => sprintf(
				/* translators: 1: room title, 2: room ID */
				__( 'WebChat room created: %1$s (ID: %2$d)', 'mcp-ai-wpoos-pro' ),
				$title,
				$room_id
			),
			'room_id'          => $room_id,
			'room_url'         => $room_url,
			'title'            => $title,
			'description'      => $description,
			'max_participants' => $max_participants,
			'allow_anonymous'  => $allow_anonymous,
			'status'           => 'active',
			'signaling_type'   => $use_self_hosted ? 'self-hosted' : 'external',
			'signaling_server' => $signaling_server,
			'author_id'        => $current_user_id,
			'created_at'       => get_the_date( 'c', $room_id ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'local-only',
			'requires-capability',
			'state-changing',
			'reversible',
		);
	}
}
