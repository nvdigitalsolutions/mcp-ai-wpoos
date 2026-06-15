<?php
/**
 * Pro tool: Schedule a Channel Broadcast.
 *
 * Convenience tool that wraps create_pro_schedule specifically for the
 * channel_broadcast type — sending a message to Telegram, Slack, Discord,
 * Teams, Messenger, or WhatsApp on a recurring or one-off schedule.
 *
 * Requires the Chat Channels Toolkit to be enabled (enable_chat_channels_toolkit).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Provides an AI tool for scheduling recurring or one-off channel broadcasts.
 */
class WP_MCP_AI_Pro_Tool_Schedule_Channel_Broadcast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'schedule_channel_broadcast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Schedule Channel Broadcast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules a message to be sent to one or more chat channels (Telegram, Slack, Discord, Teams, Messenger, WhatsApp) on a recurring or one-off basis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$valid_schedules = array_keys( wp_get_schedules() );
		sort( $valid_schedules );
		$valid_schedules = array_merge( array( 'single' ), $valid_schedules );

		return array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Human-readable label for this broadcast schedule.', 'mcp-ai-wpoos-pro' ),
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Message text to broadcast. Supports markdown on platforms that render it (Telegram, Slack, Discord).', 'mcp-ai-wpoos-pro' ),
				),
				'channels'    => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ),
					),
					'description' => __( 'Channel slugs to send the broadcast to.', 'mcp-ai-wpoos-pro' ),
				),
				'credentials' => array(
					'type'        => 'object',
					'description' => __( 'Credentials object keyed by channel slug (same shape as the unified_channel_broadcast tool).', 'mcp-ai-wpoos-pro' ),
				),
				'schedule'    => array(
					'type'        => 'string',
					'enum'        => $valid_schedules,
					'description' => __( 'Recurrence interval. Use "single" for a one-time broadcast.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'single',
				),
				'timestamp'   => array(
					'type'        => 'integer',
					'description' => __( 'Unix timestamp for first (or only) send. Defaults to 60 seconds from now.', 'mcp-ai-wpoos-pro' ),
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this broadcast is active. Defaults to true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'tags'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional tags for categorising this broadcast.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'message', 'channels', 'credentials' ),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}


	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to create scheduled broadcasts.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to create scheduled broadcasts.', 'mcp-ai-wpoos-pro' )
			);
		}

		$message     = isset( $arguments['message'] ) ? $arguments['message'] : '';
		$channels    = isset( $arguments['channels'] ) && is_array( $arguments['channels'] ) ? $arguments['channels'] : array();
		$credentials = isset( $arguments['credentials'] ) && is_array( $arguments['credentials'] ) ? $arguments['credentials'] : array();

		if ( '' === $message ) {
			return new WP_Error( 'missing_message', __( 'A message is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $channels ) ) {
			return new WP_Error( 'missing_channels', __( 'At least one channel must be specified.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $credentials ) ) {
			return new WP_Error( 'missing_credentials', __( 'Channel credentials are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$schedule_data = array(
			'schedule_type'    => WP_MCP_AI_Pro_Schedule_Manager::TYPE_CHANNEL_BROADCAST,
			'broadcast_config' => array(
				'message'     => $message,
				'channels'    => $channels,
				'credentials' => $credentials,
			),
			'name'             => isset( $arguments['name'] ) ? $arguments['name'] : sprintf(
				/* translators: %s: channel list */
				__( 'Channel Broadcast to %s', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $channels )
			),
			'schedule'         => isset( $arguments['schedule'] ) ? $arguments['schedule'] : 'single',
			'enabled'          => isset( $arguments['enabled'] ) ? (bool) $arguments['enabled'] : true,
			'tags'             => isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ? $arguments['tags'] : array(),
		);

		if ( isset( $arguments['timestamp'] ) ) {
			$schedule_data['timestamp'] = (int) $arguments['timestamp'];
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $schedule_data, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $result );
		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $result );

		return array(
			'schedule_id' => $result,
			'name'        => $schedule['name'],
			'channels'    => $channels,
			'schedule'    => $schedule['schedule'],
			'enabled'     => $schedule['enabled'],
			'next_run'    => $next_run ? wp_date( DATE_ATOM, $next_run ) : null,
			'message'     => __( 'Channel broadcast scheduled successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'requires-capability',
			'state-changing',
			'async',
			'deferred-result',
			'background-only',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Extended tool definition.
	 */
	public function get_extended_definition() {
		return array(
			'name'              => $this->get_name(),
			'slug'              => $this->get_slug(),
			'description'       => $this->get_description(),
			'parameters_schema' => $this->get_parameters_schema(),
			'capability_flags'  => $this->get_capability_flags(),
			'toolkit'           => 'schedule-manager',
			'category'          => 'messaging',
			'risk_level'        => 'standard',
		);
	}
}
