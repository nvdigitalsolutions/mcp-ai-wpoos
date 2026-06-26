<?php
/**
 * Pro tool: Dry-run a Pro Schedule.
 *
 * Simulates what a schedule would do without persisting any state, executing
 * any hook, calling any external service, or recording a run. Useful for
 * validating a schedule's configuration before activating it.
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
 * Read-only "what would this schedule do" inspector.
 */
class WP_MCP_AI_Pro_Tool_Dry_Run_Pro_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'dry_run_pro_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Dry-run Pro Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Simulates a Pro Schedule without executing it. Returns the next N upcoming runs and a summary of the action the schedule would perform (task hook, assistant message, broadcast channels, workflow steps).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id' => array(
					'type'        => 'string',
					'description' => __( 'Schedule identifier to dry-run.', 'mcp-ai-wpoos-pro' ),
				),
				'count'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 30,
					'default'     => 5,
					'description' => __( 'How many upcoming run timestamps to project.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'schedule_id' ),
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
				__( 'You do not have permission to inspect schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		$schedule_id = isset( $arguments['schedule_id'] ) ? sanitize_text_field( (string) $arguments['schedule_id'] ) : '';
		if ( '' === $schedule_id ) {
			return new WP_Error( 'missing_schedule_id', __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$count = isset( $arguments['count'] ) ? (int) $arguments['count'] : 5;
		$count = max( 1, min( 30, $count ) );

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error( 'schedule_not_found', __( 'Schedule not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$schedule_type = isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : 'task';
		$cadence       = isset( $schedule['schedule'] ) ? (string) $schedule['schedule'] : '';
		$enabled       = ! empty( $schedule['enabled'] );

		// Project upcoming runs using the helper added alongside the calendar card.
		$next_runs = array();
		if ( method_exists( 'WP_MCP_AI_Pro_Schedule_Manager', 'get_next_run_times' ) ) {
			$times = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_times( $schedule_id, $count );
			foreach ( $times as $when ) {
				$next_runs[] = array(
					'timestamp' => (int) $when,
					'iso8601'   => wp_date( DATE_ATOM, (int) $when ),
				);
			}
		}

		// Build the type-specific "what would happen" summary.
		$action_preview = $this->preview_action( $schedule_type, $schedule );

		// Surface configuration warnings without executing anything.
		$warnings = array();
		if ( ! $enabled ) {
			$warnings[] = __( 'Schedule is currently paused and would not dispatch.', 'mcp-ai-wpoos-pro' );
		}
		if ( '' === $cadence ) {
			$warnings[] = __( 'No cadence is configured; the schedule will not fire.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'single' !== $cadence ) {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $cadence ] ) ) {
				$warnings[] = sprintf(
					/* translators: %s: cadence slug */
					__( 'Cadence "%s" is not registered with WordPress; the schedule will not fire.', 'mcp-ai-wpoos-pro' ),
					$cadence
				);
			}
		}
		if ( empty( $next_runs ) ) {
			$warnings[] = __( 'No upcoming runs are projected. The schedule may be one-shot and already fired, or paused.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'schedule_id'    => $schedule_id,
			'name'           => isset( $schedule['name'] ) ? (string) $schedule['name'] : '',
			'schedule_type'  => $schedule_type,
			'cadence'        => $cadence,
			'enabled'        => $enabled,
			'timezone'       => wp_timezone_string(),
			'next_runs'      => $next_runs,
			'action'         => $action_preview,
			'warnings'       => $warnings,
			'would_dispatch' => $enabled && ! empty( $next_runs ),
		);
	}

	/**
	 * Build a type-specific summary of what the schedule would do, without
	 * touching the action, network, or database.
	 *
	 * @param string $schedule_type Schedule type constant value.
	 * @param array  $schedule      Full schedule record.
	 * @return array Preview payload.
	 */
	protected function preview_action( $schedule_type, array $schedule ) {
		switch ( $schedule_type ) {
			case 'assistant_run':
				$cfg          = isset( $schedule['assistant_config'] ) && is_array( $schedule['assistant_config'] ) ? $schedule['assistant_config'] : array();
				$assistant_id = isset( $cfg['assistant_id'] ) ? (int) $cfg['assistant_id'] : 0;
				$assistant    = $assistant_id ? get_post( $assistant_id ) : null;
				return array(
					'type'           => 'assistant_run',
					'assistant_id'   => $assistant_id,
					'assistant_name' => $assistant ? (string) $assistant->post_title : '',
					'message'        => isset( $cfg['message'] ) ? (string) $cfg['message'] : '',
				);

			case 'workflow':
				$steps         = isset( $schedule['workflow_steps'] ) && is_array( $schedule['workflow_steps'] ) ? $schedule['workflow_steps'] : array();
				$preview_steps = array();
				foreach ( $steps as $index => $step ) {
					$preview_steps[] = array(
						'index'     => (int) $index,
						'tool_slug' => isset( $step['tool_slug'] ) ? (string) $step['tool_slug'] : '',
						'label'     => isset( $step['label'] ) ? (string) $step['label'] : '',
					);
				}
				return array(
					'type'  => 'workflow',
					'steps' => $preview_steps,
				);

			case 'channel_broadcast':
				$cfg = isset( $schedule['broadcast_config'] ) && is_array( $schedule['broadcast_config'] ) ? $schedule['broadcast_config'] : array();
				return array(
					'type'     => 'channel_broadcast',
					'channels' => isset( $cfg['channels'] ) && is_array( $cfg['channels'] ) ? array_values( $cfg['channels'] ) : array(),
					'message'  => isset( $cfg['message'] ) ? (string) $cfg['message'] : '',
				);

			case 'workflow_builder':
				return array(
					'type'                => 'workflow_builder',
					'workflow_builder_id' => isset( $schedule['workflow_builder_id'] ) ? (string) $schedule['workflow_builder_id'] : '',
				);

			case 'task':
			default:
				return array(
					'type' => 'task',
					'hook' => isset( $schedule['hook'] ) ? (string) $schedule['hook'] : '',
					'args' => isset( $schedule['args'] ) && is_array( $schedule['args'] ) ? array_values( $schedule['args'] ) : array(),
				);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
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
			'risk_level'        => 'info',
		);
	}
}
