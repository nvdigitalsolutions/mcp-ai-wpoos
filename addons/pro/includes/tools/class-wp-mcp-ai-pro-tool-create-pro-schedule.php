<?php
/**
 * Pro tool: Create a Pro Schedule.
 *
 * @package WP_MCP_AI_Pro
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
				'schedule_type'     => array(
					'type'        => 'string',
					'enum'        => array( 'task', 'workflow', 'assistant_run' ),
					'description' => __( 'Type of schedule: "task" (WP action hook), "workflow" (tool chain), or "assistant_run" (AI assistant). Defaults to "task".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'task',
				),
				'hook'              => array(
					'type'        => 'string',
					'description' => __( 'WordPress action hook to fire (required for "task" type).', 'mcp-ai-wpoos-pro' ),
				),
				'workflow_steps'    => array(
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
				'assistant_config'  => array(
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
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'Human-readable label for this schedule.', 'mcp-ai-wpoos-pro' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Optional description of what this schedule does.', 'mcp-ai-wpoos-pro' ),
				),
				'schedule'          => array(
					'type'        => 'string',
					'enum'        => $valid_schedules,
					'description' => __( 'Recurrence interval. Use "single" for a one-time run.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'single',
				),
				'timestamp'         => array(
					'type'        => 'integer',
					'description' => __( 'Unix timestamp for the first (or only) execution. Defaults to 60 seconds from now.', 'mcp-ai-wpoos-pro' ),
				),
				'args'              => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional positional arguments passed to the action hook (task type only).', 'mcp-ai-wpoos-pro' ),
				),
				'enabled'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the schedule should be active immediately. Defaults to true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'priority'          => array(
					'type'        => 'integer',
					'description' => __( 'Display priority (1 = highest, 10 = lowest). Defaults to 5.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
				),
				'tags'              => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional tags for categorising schedules.', 'mcp-ai-wpoos-pro' ),
				),
				'notify_on_failure' => array(
					'type'        => 'boolean',
					'description' => __( 'Send an admin email when the schedule fails. Defaults to false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'notify_email'      => array(
					'type'        => 'string',
					'description' => __( 'Email address for failure notifications. Defaults to admin email.', 'mcp-ai-wpoos-pro' ),
				),
				'max_retries'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of automatic retry attempts on failure (0-5). Defaults to 0.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 0,
				),
				'retry_delay'       => array(
					'type'        => 'integer',
					'description' => __( 'Seconds to wait before each retry attempt. Minimum 60. Defaults to 300.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => 300,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

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
