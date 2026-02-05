<?php
/**
 * Tool for getting WebChat integration status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets WebChat integration status.
 */
class WP_MCP_AI_Tool_Get_WebChat_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_webchat_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WebChat Status', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets WebChat integration status, including enabled state, active rooms count, and total participants.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'required'             => array(),
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
			WP_MCP_AI_Logger::log_activity( 'Tool unavailable: get_webchat_status' );
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WebChat status.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get settings.
		$settings = get_option( 'wp_mcp_ai_webchat_settings', array() );
		$enabled  = ! empty( $settings['enable_webchat_integration'] );

		// Count rooms by status.
		$active_rooms_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_webchat_room',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_mcp_ai_webchat_status',
						'value' => 'active',
					),
				),
			)
		);

		$total_rooms_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_webchat_room',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Count total participants across all active rooms.
		global $wpdb;
		$total_participants = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CAST(meta_value AS UNSIGNED))
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				AND post_id IN (
					SELECT ID FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status = %s
				)",
				'_mcp_ai_webchat_active_participants',
				'mcp_ai_webchat_room',
				'publish'
			)
		);

		return array(
			'summary'            => __( 'WebChat integration status retrieved successfully.', 'mcp-ai-wpoos-pro' ),
			'enabled'            => $enabled,
			'total_rooms'        => $total_rooms_query->found_posts,
			'active_rooms'       => $active_rooms_query->found_posts,
			'total_participants' => absint( $total_participants ),
			'settings'           => array(
				'signaling_type'            => isset( $settings['enable_self_hosted_signaling'] ) && $settings['enable_self_hosted_signaling'] ? 'self-hosted' : 'external',
				'self_hosted_enabled'       => isset( $settings['enable_self_hosted_signaling'] ) ? (bool) $settings['enable_self_hosted_signaling'] : true,
				'self_hosted_endpoint'      => rest_url( 'mcp-ai/v1/webchat/' ),
				'external_signaling_server' => isset( $settings['default_signaling_server'] ) ? $settings['default_signaling_server'] : '',
				'default_max_participants'  => isset( $settings['default_max_participants'] ) ? absint( $settings['default_max_participants'] ) : 10,
				'enable_anonymous_chat'     => isset( $settings['enable_anonymous_chat'] ) ? (bool) $settings['enable_anonymous_chat'] : false,
			),
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
