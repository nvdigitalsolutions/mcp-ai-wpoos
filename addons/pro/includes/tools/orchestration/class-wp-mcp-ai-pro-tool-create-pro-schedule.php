<?php
/**
 * Pro tool: Create a Pro Schedule.
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
 * Provides an AI tool for creating pro scheduled tasks.
 */
class WP_MCP_AI_Pro_Tool_Create_Pro_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_pro_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Pro Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a named, managed scheduled task with retry logic, failure notifications, execution history, and enable/disable control.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$valid_schedules = array_keys( wp_get_schedules() );
		sort( $valid_schedules );
		$valid_schedules = array_merge( array( 'single' ), $valid_schedules );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_type'              => array(
					'type'        => 'string',
					'enum'        => array( 'task', 'workflow', 'assistant_run', 'channel_broadcast' ),
					'description' => __( 'Type: "task" (WP hook), "workflow" (tool chain), "assistant_run" (AI assistant), "channel_broadcast" (Slack/Telegram/Discord/etc.). Defaults to "task".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'task',
				),
				'hook'                       => array(
					'type'        => 'string',
					'description' => __( 'WordPress action hook to fire (required for "task" type).', 'mcp-ai-wpoos-pro' ),
				),
				'workflow_steps'             => array(
					'type'        => 'array',
					'description' => __( 'Ordered list of tool calls for "workflow" type. Each step: {tool_slug, arguments, label}.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'tool_slug' => array(
								'type'        => 'string',
								'description' => __( 'Registered tool slug to call at this step.', 'mcp-ai-wpoos-pro' ),
							),
							'arguments' => array(
								'type'        => 'object',
								'description' => __( 'Arguments to pass to the tool.', 'mcp-ai-wpoos-pro' ),
							),
							'label'     => array(
								'type'        => 'string',
								'description' => __( 'Optional human-readable label for this step.', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'assistant_config'           => array(
					'type'        => 'object',
					'description' => __( 'Configuration for "assistant_run" type.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'assistant_id' => array(
							'type'        => 'integer',
							'description' => __( 'Post ID of the NV oOS assistant to run.', 'mcp-ai-wpoos-pro' ),
						),
						'message'      => array(
							'type'        => 'string',
							'description' => __( 'Message to send to the assistant.', 'mcp-ai-wpoos-pro' ),
						),
						'context'      => array(
							'type'        => 'object',
							'description' => __( 'Optional extra context passed to the assistant run action.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'name'                       => array(
					'type'        => 'string',
					'description' => __( 'Human-readable label for this schedule.', 'mcp-ai-wpoos-pro' ),
				),
				'description'                => array(
					'type'        => 'string',
					'description' => __( 'Optional description of what this schedule does.', 'mcp-ai-wpoos-pro' ),
				),
				'schedule'                   => array(
					'type'        => 'string',
					'enum'        => $valid_schedules,
					'description' => __( 'Recurrence interval. Use "single" for a one-time run.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'single',
				),
				'timestamp'                  => array(
					'type'        => 'integer',
					'description' => __( 'Unix timestamp for the first (or only) execution. Defaults to 60 seconds from now.', 'mcp-ai-wpoos-pro' ),
				),
				'args'                       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional positional arguments passed to the action hook (task type only).', 'mcp-ai-wpoos-pro' ),
				),
				'enabled'                    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the schedule should be active immediately. Defaults to true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'priority'                   => array(
					'type'        => 'integer',
					'description' => __( 'Display priority (1 = highest, 10 = lowest). Defaults to 5.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
				),
				'tags'                       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional tags for categorising schedules.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_on_failure'          => array(
					'type'        => 'boolean',
					'description' => __( 'Send a failure notification (email and/or channel) when the schedule fails. Defaults to false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'notify_email'               => array(
					'type'        => 'string',
					'description' => __( 'Email address for failure notifications. Defaults to admin email.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_channels'            => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ),
					),
					'description' => __( 'Chat channel slugs to notify on failure (requires notify_channel_credentials).', 'mcp-ai-wpoos-pro' ),
				),
				'notify_channel_credentials' => array(
					'type'        => 'object',
					'description' => __( 'Per-channel credentials for failure channel notifications (same shape as unified_channel_broadcast credentials).', 'mcp-ai-wpoos-pro' ),
				),
				'broadcast_config'           => array(
					'type'        => 'object',
					'description' => __( 'Required for "channel_broadcast" type. Provide message, channels array, and credentials.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'message'     => array(
							'type'        => 'string',
							'description' => __( 'Message text to broadcast to the configured channels.', 'mcp-ai-wpoos-pro' ),
						),
						'channels'    => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ),
							),
							'description' => __( 'Channel slugs to broadcast to.', 'mcp-ai-wpoos-pro' ),
						),
						'credentials' => array(
							'type'        => 'object',
							'description' => __( 'Per-channel credentials object (see unified_channel_broadcast tool for shape).', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'max_retries'                => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of automatic retry attempts on failure (0-5). Defaults to 0.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 0,
				),
				'retry_delay'                => array(
					'type'        => 'integer',
					'description' => __( 'Seconds to wait before each retry attempt. Minimum 60. Defaults to 300.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => 300,
				),
				'timeout'                    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum execution time in seconds. Runs exceeding this are marked as failed. 0 = no limit. Defaults to 0.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'callback_url'               => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'External webhook URL that receives a POST with run results on completion or failure.', 'mcp-ai-wpoos-pro' ),
				),
				'callback_secret'            => array(
					'type'        => 'string',
					'description' => __( 'Optional HMAC-SHA256 signing secret. When set, every webhook POST includes an X-WP-MCP-AI-Signature header (sha256=<hex>) computed over "<timestamp>.<body>" so the receiver can verify authenticity. The X-WP-MCP-AI-Timestamp header contains the Unix timestamp used; receivers should reject requests where that timestamp is more than 300 seconds old to prevent replay attacks.', 'mcp-ai-wpoos-pro' ),
				),
				'display'                    => array(
					'type'        => 'object',
					'description' => __( 'Display / widget-binding settings consumed by the Scheduled Result block & Elementor widget.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'result_capture'   => array(
							'type' => 'string',
							'enum' => array( 'disabled', 'summary', 'full' ),
						),
						'public_render'    => array( 'type' => 'boolean' ),
						'public_fields'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'result_retention' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'widget_defaults'  => array(
							'type'       => 'object',
							'properties' => array(
								'render_mode'      => array(
									'type' => 'string',
									'enum' => array( 'summary-card', 'list', 'table', 'metric', 'timeline', 'raw' ),
								),
								'title'            => array( 'type' => 'string' ),
								'refresh_interval' => array(
									'type'    => 'integer',
									'minimum' => 0,
									'maximum' => 3600,
								),
							),
						),
					),
				),
			),
			'additionalProperties' => false,
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
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to create schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to create schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $arguments, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $result );
		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $result );

		return array(
			'schedule_id' => $result,
			'name'        => $schedule['name'],
			'hook'        => $schedule['hook'],
			'schedule'    => $schedule['schedule'],
			'enabled'     => $schedule['enabled'],
			'next_run'    => $next_run ? wp_date( DATE_ATOM, $next_run ) : null,
			'message'     => __( 'Pro schedule created successfully.', 'mcp-ai-wpoos-pro' ),
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
	 * @since 1.0.0
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
			'category'          => 'automation',
			'risk_level'        => 'standard',
		);
	}
}
