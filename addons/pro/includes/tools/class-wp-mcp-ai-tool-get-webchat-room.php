<?php
/**
 * Tool for retrieving WebChat room details.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves WebChat room details.
 */
class WP_MCP_AI_Tool_Get_WebChat_Room implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_webchat_room';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WebChat Room', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves details of a specific WebChat room, including participant count and settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'room_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the WebChat room to retrieve.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'room_id' ),
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
		$settings = get_option( 'wp_mcp_ai_webchat_settings', array() );
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
			WP_MCP_AI_Logger::log_activity( 'Tool unavailable: get_webchat_room' );
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WebChat rooms.', 'mcp-ai-wpoos-pro' ) );
		}

		$room_id = isset( $arguments['room_id'] ) ? absint( $arguments['room_id'] ) : 0;

		if ( ! $room_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_room_id', __( 'Room ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$room = get_post( $room_id );

		if ( ! $room || 'mcp_ai_webchat_room' !== $room->post_type ) {
			return new WP_Error( 'wp_mcp_ai_room_not_found', __( 'WebChat room not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get room metadata.
		$max_participants    = get_post_meta( $room_id, '_mcp_ai_webchat_max_participants', true );
		$active_participants = get_post_meta( $room_id, '_mcp_ai_webchat_active_participants', true );
		$allow_anonymous     = get_post_meta( $room_id, '_mcp_ai_webchat_allow_anonymous', true );
		$status              = get_post_meta( $room_id, '_mcp_ai_webchat_status', true );
		$signaling_server    = get_post_meta( $room_id, '_mcp_ai_webchat_signaling_server', true );

		// Calculate availability.
		$is_full       = absint( $active_participants ) >= absint( $max_participants );
		$is_available  = 'active' === $status && ! $is_full;

		return array(
			'summary'             => sprintf(
				/* translators: %s: room title */
				__( 'WebChat Room: %s', 'mcp-ai-wpoos-pro' ),
				get_the_title( $room )
			),
			'room_id'             => $room_id,
			'title'               => get_the_title( $room ),
			'description'         => $room->post_content,
			'max_participants'    => absint( $max_participants ),
			'active_participants' => absint( $active_participants ),
			'allow_anonymous'     => (bool) $allow_anonymous,
			'status'              => $status,
			'is_available'        => $is_available,
			'is_full'             => $is_full,
			'signaling_server'    => $signaling_server,
			'room_url'            => get_permalink( $room_id ),
			'author_id'           => absint( $room->post_author ),
			'created_at'          => $room->post_date,
			'updated_at'          => $room->post_modified,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
		);
	}
}
