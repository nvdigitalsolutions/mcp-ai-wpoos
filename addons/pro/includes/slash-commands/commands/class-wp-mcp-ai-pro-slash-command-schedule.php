<?php
/**
 * Schedule Pro Slash Command
 *
 * Provides schedule management actions: list, show, create, pause, resume, delete, run, history.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule Command Class
 *
 * Sub-actions (via $args[0] or --action=<val>):
 *   list    — list schedules for current user (--all lists all, requires manage_options)
 *   show    — show schedule details + last run history
 *   create  — create a new schedule
 *   pause   — toggle_schedule false
 *   resume  — toggle_schedule true
 *   delete  — delete schedule (requires manage_options)
 *   run     — trigger_now
 *   history — show run history
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Schedule {

	/**
	 * Execute schedule command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );
		$limit   = isset( $flags['limit'] ) ? absint( $flags['limit'] ) : 20;

		// Determine sub-action.
		$action = 'list';
		if ( ! empty( $args[0] ) ) {
			$action = sanitize_key( $args[0] );
		} elseif ( ! empty( $flags['action'] ) ) {
			$action = sanitize_key( $flags['action'] );
		}

		// Validate base capability.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure Schedule Manager is available.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Schedule Manager service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		switch ( $action ) {
			case 'list':
				return $this->action_list( $user_id, $flags, $as_json, $limit );

			case 'show':
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule show <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_show( $schedule_id, $as_json );

			case 'create':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'forbidden', __( 'Creating schedules requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_create( $user_id, $flags, $as_json );

			case 'pause':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'forbidden', __( 'Pausing schedules requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
				}
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule pause <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_toggle( $schedule_id, false, $user_id, $as_json );

			case 'resume':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'forbidden', __( 'Resuming schedules requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
				}
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule resume <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_toggle( $schedule_id, true, $user_id, $as_json );

			case 'delete':
				if ( ! user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'forbidden', __( 'Deleting schedules requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
				}
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule delete <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_delete( $schedule_id, $as_json );

			case 'run':
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule run <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_run( $schedule_id, $user_id, $as_json );

			case 'history':
				$schedule_id = isset( $args[1] ) ? absint( $args[1] ) : 0;
				if ( ! $schedule_id ) {
					return new WP_Error( 'missing_id', __( 'Schedule ID required. Usage: /schedule history <id>', 'mcp-ai-wpoos-pro' ) );
				}
				return $this->action_history( $schedule_id, $limit, $as_json );

			default:
				return new WP_Error(
					'unknown_action',
					sprintf(
						/* translators: %s: the unknown action string */
						__( 'Unknown action "%s". Valid actions: list, show, create, pause, resume, delete, run, history.', 'mcp-ai-wpoos-pro' ),
						esc_html( $action )
					)
				);
		}
	}

	/**
	 * List schedules.
	 *
	 * @param int   $user_id User ID.
	 * @param array $flags   Command flags.
	 * @param bool  $as_json Output as JSON.
	 * @param int   $limit   Max results.
	 * @return string|array
	 */
	private function action_list( $user_id, $flags, $as_json, $limit ) {
		$list_all = isset( $flags['all'] ) && user_can( $user_id, 'manage_options' );
		$filters  = $list_all ? array() : array( 'user_id' => $user_id );

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules( $filters );

		if ( is_wp_error( $schedules ) ) {
			return $schedules;
		}

		$schedules = is_array( $schedules ) ? array_slice( $schedules, 0, $limit ) : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Schedules retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $schedules,
			);
		}

		if ( empty( $schedules ) ) {
			return __( 'No schedules found.', 'mcp-ai-wpoos-pro' );
		}

		$output  = '## ' . __( 'Schedules', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$output .= "| ID | Name | Type | Cron | Enabled |\n";
		$output .= "|----|------|------|------|---------|\n";
		foreach ( $schedules as $s ) {
			$id      = isset( $s['id'] ) ? absint( $s['id'] ) : '–';
			$name    = isset( $s['name'] ) ? esc_html( $s['name'] ) : '–';
			$type    = isset( $s['schedule_type'] ) ? esc_html( $s['schedule_type'] ) : '–';
			$cron    = isset( $s['schedule'] ) ? esc_html( $s['schedule'] ) : '–';
			$enabled = ! empty( $s['enabled'] ) ? '✅' : '❌';
			$output .= "| {$id} | {$name} | {$type} | {$cron} | {$enabled} |\n";
		}

		return $output;
	}

	/**
	 * Show schedule details and last run history.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param bool $as_json     Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_show( $schedule_id, $as_json ) {
		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules( array( 'id' => $schedule_id ) );

		if ( is_wp_error( $schedules ) ) {
			return $schedules;
		}

		$schedule = is_array( $schedules ) && ! empty( $schedules ) ? reset( $schedules ) : null;

		if ( ! $schedule ) {
			return new WP_Error( 'not_found', __( 'Schedule not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, 5 );
		$history = is_array( $history ) ? $history : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Schedule details retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => array(
					'schedule' => $schedule,
					'history'  => $history,
				),
			);
		}

		$name    = isset( $schedule['name'] ) ? esc_html( $schedule['name'] ) : '–';
		$type    = isset( $schedule['schedule_type'] ) ? esc_html( $schedule['schedule_type'] ) : '–';
		$cron    = isset( $schedule['schedule'] ) ? esc_html( $schedule['schedule'] ) : '–';
		$enabled = ! empty( $schedule['enabled'] ) ? '✅ Enabled' : '❌ Disabled';

		$output  = "## Schedule: {$name}\n\n";
		$output .= "- **ID:** {$schedule_id}\n";
		$output .= "- **Type:** {$type}\n";
		$output .= "- **Cron:** {$cron}\n";
		$output .= "- **Status:** {$enabled}\n\n";

		if ( ! empty( $history ) ) {
			$output .= "### Recent Run History\n\n";
			$output .= "| Ran At | Status | Duration |\n";
			$output .= "|--------|--------|----------|\n";
			foreach ( $history as $run ) {
				$ran_at   = isset( $run['ran_at'] ) ? esc_html( $run['ran_at'] ) : '–';
				$status   = isset( $run['status'] ) ? esc_html( $run['status'] ) : '–';
				$duration = isset( $run['duration'] ) ? esc_html( $run['duration'] ) . 's' : '–';
				$output  .= "| {$ran_at} | {$status} | {$duration} |\n";
			}
		} else {
			$output .= "_No run history available._\n";
		}

		return $output;
	}

	/**
	 * Create a new schedule.
	 *
	 * @param int   $user_id User ID.
	 * @param array $flags   Command flags.
	 * @param bool  $as_json Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_create( $user_id, $flags, $as_json ) {
		$name   = isset( $flags['name'] ) ? sanitize_text_field( $flags['name'] ) : '';
		$type   = isset( $flags['type'] ) ? sanitize_key( $flags['type'] ) : '';
		$cron   = isset( $flags['cron'] ) ? sanitize_text_field( $flags['cron'] ) : '';
		$notify = isset( $flags['notify'] );

		$allowed_types = array( 'task', 'workflow', 'assistant_run', 'channel_broadcast', 'workflow_builder' );

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Schedule name required. Use --name=<name>', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $type ) || ! in_array( $type, $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_type',
				sprintf(
					/* translators: %s: allowed types list */
					__( 'Invalid schedule type. Allowed: %s', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $allowed_types )
				)
			);
		}

		if ( empty( $cron ) ) {
			return new WP_Error( 'missing_cron', __( 'Cron interval required. Use --cron=<interval>', 'mcp-ai-wpoos-pro' ) );
		}

		$data = array(
			'name'              => $name,
			'schedule_type'     => $type,
			'schedule'          => $cron,
			'notify_on_failure' => $notify,
		);

		$result = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $data, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Schedule created.', 'mcp-ai-wpoos-pro' ),
				'data'    => $result,
			);
		}

		return sprintf(
			/* translators: %s: schedule name */
			__( '✅ Schedule "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
			esc_html( $name )
		);
	}

	/**
	 * Toggle schedule enabled/disabled.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param bool $enabled     Whether to enable.
	 * @param int  $user_id     User ID.
	 * @param bool $as_json     Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_toggle( $schedule_id, $enabled, $user_id, $as_json ) {
		$result = WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule( $schedule_id, $enabled, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$state_label = $enabled
			? __( 'resumed', 'mcp-ai-wpoos-pro' )
			: __( 'paused', 'mcp-ai-wpoos-pro' );

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %1$d: schedule ID, %2$s: state label */
					__( 'Schedule %1$d %2$s.', 'mcp-ai-wpoos-pro' ),
					$schedule_id,
					$state_label
				),
				'data'    => array(
					'schedule_id' => $schedule_id,
					'enabled'     => $enabled,
				),
			);
		}

		return sprintf(
			/* translators: %1$d: schedule ID, %2$s: state */
			__( '✅ Schedule %1$d %2$s.', 'mcp-ai-wpoos-pro' ),
			$schedule_id,
			$state_label
		);
	}

	/**
	 * Delete a schedule.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param bool $as_json     Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_delete( $schedule_id, $as_json ) {
		$result = WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $schedule_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: schedule ID */
					__( 'Schedule %d deleted.', 'mcp-ai-wpoos-pro' ),
					$schedule_id
				),
				'data'    => array( 'schedule_id' => $schedule_id ),
			);
		}

		return sprintf(
			/* translators: %d: schedule ID */
			__( '✅ Schedule %d deleted.', 'mcp-ai-wpoos-pro' ),
			$schedule_id
		);
	}

	/**
	 * Run a schedule immediately.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param int  $user_id     User ID.
	 * @param bool $as_json     Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_run( $schedule_id, $user_id, $as_json ) {
		$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: schedule ID */
					__( 'Schedule %d triggered.', 'mcp-ai-wpoos-pro' ),
					$schedule_id
				),
				'data'    => $result,
			);
		}

		return sprintf(
			/* translators: %d: schedule ID */
			__( '✅ Schedule %d triggered for immediate execution.', 'mcp-ai-wpoos-pro' ),
			$schedule_id
		);
	}

	/**
	 * Show run history for a schedule.
	 *
	 * @param int  $schedule_id Schedule ID.
	 * @param int  $limit       Max history entries.
	 * @param bool $as_json     Output as JSON.
	 * @return string|array|WP_Error
	 */
	private function action_history( $schedule_id, $limit, $as_json ) {
		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, $limit );

		if ( is_wp_error( $history ) ) {
			return $history;
		}

		$history = is_array( $history ) ? $history : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Run history retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $history,
			);
		}

		if ( empty( $history ) ) {
			return sprintf(
				/* translators: %d: schedule ID */
				__( 'No run history found for schedule %d.', 'mcp-ai-wpoos-pro' ),
				$schedule_id
			);
		}

		$output = sprintf(
			/* translators: %d: schedule ID */
			'## ' . __( 'Run History for Schedule %d', 'mcp-ai-wpoos-pro' ) . "\n\n",
			$schedule_id
		);
		$output .= "| # | Ran At | Status | Duration |\n";
		$output .= "|---|--------|--------|----------|\n";

		$i = 1;
		foreach ( $history as $run ) {
			$ran_at   = isset( $run['ran_at'] ) ? esc_html( $run['ran_at'] ) : '–';
			$status   = isset( $run['status'] ) ? esc_html( $run['status'] ) : '–';
			$duration = isset( $run['duration'] ) ? esc_html( $run['duration'] ) . 's' : '–';
			$output  .= "| {$i} | {$ran_at} | {$status} | {$duration} |\n";
			++$i;
		}

		return $output;
	}
}
