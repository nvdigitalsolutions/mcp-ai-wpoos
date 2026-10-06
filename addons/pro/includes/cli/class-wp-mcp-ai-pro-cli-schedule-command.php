<?php
/**
 * WP-CLI schedule management commands for NV oOS Pro.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Manage NV oOS Pro Schedules from the command line.
 *
 * Reads and executes schedules stored in the
 * {@see WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION} option through the
 * manager's public API. All scheduling/execution logic lives in the manager;
 * this command only formats arguments and output.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Schedule_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Load the schedule manager when it is not already available.
	 *
	 * @return void
	 */
	private function require_schedule_manager() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';
		}
	}

	/**
	 * List all Pro schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of columns to display.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all schedules.
	 *     $ wp mcp-ai schedule list
	 *
	 *     # Export schedule IDs.
	 *     $ wp mcp-ai schedule list --format=ids
	 *
	 *     # Show a subset of columns.
	 *     $ wp mcp-ai schedule list --fields=id,name,enabled,next_run
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$this->require_schedule_manager();

		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields(
			$assoc_args,
			array( 'id', 'name', 'type', 'cadence', 'enabled', 'priority', 'hook', 'next_run', 'last_run_status', 'run_count' )
		);

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();

		if ( empty( $schedules ) ) {
			WP_CLI::log( __( 'No schedules found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_keys( $schedules ) ) );
			return;
		}

		$items = array();
		foreach ( $schedules as $schedule ) {
			$schedule_id = isset( $schedule['id'] ) ? (string) $schedule['id'] : '';
			$next_run    = $schedule_id ? WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule_id ) : 0;
			$tags        = isset( $schedule['tags'] ) && is_array( $schedule['tags'] ) ? implode( ', ', $schedule['tags'] ) : '';

			$items[] = array(
				'id'              => $schedule_id,
				'name'            => isset( $schedule['name'] ) ? (string) $schedule['name'] : '',
				'type'            => isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : 'task',
				'cadence'         => isset( $schedule['schedule'] ) ? (string) $schedule['schedule'] : '',
				'enabled'         => ! empty( $schedule['enabled'] ) ? 'yes' : 'no',
				'priority'        => isset( $schedule['priority'] ) ? (int) $schedule['priority'] : 5,
				'hook'            => isset( $schedule['hook'] ) ? (string) $schedule['hook'] : '',
				'tags'            => $tags,
				'next_run'        => $next_run ? wp_date( DATE_ATOM, (int) $next_run ) : '',
				'last_run_status' => isset( $schedule['last_run_status'] ) ? (string) $schedule['last_run_status'] : '',
				'run_count'       => isset( $schedule['run_count'] ) ? (int) $schedule['run_count'] : 0,
				'created_at'      => isset( $schedule['created_at'] ) ? wp_date( DATE_ATOM, (int) $schedule['created_at'] ) : '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single schedule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The schedule ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get schedule details.
	 *     $ wp mcp-ai schedule get my-daily-report
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$this->require_schedule_manager();

		$schedule_id = isset( $args[0] ) ? sanitize_text_field( (string) $args[0] ) : '';
		$format      = $this->get_format( $assoc_args, 'table' );

		if ( '' === $schedule_id ) {
			WP_CLI::error( __( 'Please provide a schedule ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );

		if ( ! $schedule ) {
			/* translators: %s: schedule ID */
			WP_CLI::error( sprintf( __( 'Schedule "%s" not found.', 'mcp-ai-wpoos-pro' ), $schedule_id ) );
		}

		$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule_id );
		$history  = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, 10 );

		$data = array(
			'id'              => isset( $schedule['id'] ) ? (string) $schedule['id'] : '',
			'name'            => isset( $schedule['name'] ) ? (string) $schedule['name'] : '',
			'description'     => isset( $schedule['description'] ) ? (string) $schedule['description'] : '',
			'schedule_type'   => isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : 'task',
			'hook'            => isset( $schedule['hook'] ) ? (string) $schedule['hook'] : '',
			'cadence'         => isset( $schedule['schedule'] ) ? (string) $schedule['schedule'] : '',
			'enabled'         => ! empty( $schedule['enabled'] ) ? 'yes' : 'no',
			'priority'        => isset( $schedule['priority'] ) ? (int) $schedule['priority'] : 5,
			'tags'            => isset( $schedule['tags'] ) && is_array( $schedule['tags'] ) ? $schedule['tags'] : array(),
			'next_run'        => $next_run ? wp_date( DATE_ATOM, (int) $next_run ) : '',
			'last_run_status' => isset( $schedule['last_run_status'] ) ? (string) $schedule['last_run_status'] : '',
			'last_run_time'   => isset( $schedule['last_run_time'] ) && $schedule['last_run_time'] ? wp_date( DATE_ATOM, (int) $schedule['last_run_time'] ) : '',
			'run_count'       => isset( $schedule['run_count'] ) ? (int) $schedule['run_count'] : 0,
			'created_at'      => isset( $schedule['created_at'] ) ? wp_date( DATE_ATOM, (int) $schedule['created_at'] ) : '',
			'recent_history'  => $history,
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Run a schedule now, bypassing cron.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The schedule ID.
	 *
	 * [--dry-run]
	 * : Use the manager's preview run instead: the schedule executes
	 * synchronously but the run is recorded only in the result store, never in
	 * the history ring buffer.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Trigger a schedule immediately.
	 *     $ wp mcp-ai schedule run my-daily-report --yes
	 *
	 *     # Preview the run without touching history.
	 *     $ wp mcp-ai schedule run my-daily-report --dry-run
	 *
	 * @subcommand run
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$schedule_id = isset( $args[0] ) ? sanitize_text_field( (string) $args[0] ) : '';
		$dry_run     = $this->is_dry_run( $assoc_args );
		$yes         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( '' === $schedule_id ) {
			WP_CLI::error( __( 'Please provide a schedule ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$this->require_capability( 'manage_options' );

		$this->require_schedule_manager();

		if ( $dry_run ) {
			$envelope = WP_MCP_AI_Pro_Schedule_Manager::trigger_preview( $schedule_id );

			if ( is_wp_error( $envelope ) ) {
				WP_CLI::error( $envelope->get_error_message() );
			}

			/* translators: 1: schedule ID, 2: duration in seconds */
			WP_CLI::success(
				sprintf(
					__( 'Previewed schedule "%1$s" in %2$s seconds (history untouched).', 'mcp-ai-wpoos-pro' ),
					$schedule_id,
					isset( $envelope['duration'] ) ? (string) $envelope['duration'] : '0'
				)
			);
			return;
		}

		if ( ! $yes ) {
			/* translators: %s: schedule ID */
			WP_CLI::confirm( sprintf( __( 'Run schedule "%s" now?', 'mcp-ai-wpoos-pro' ), $schedule_id ) );
		}

		$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $schedule_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		/* translators: %s: schedule ID */
		WP_CLI::success( sprintf( __( 'Triggered schedule "%s".', 'mcp-ai-wpoos-pro' ), $schedule_id ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai schedule', 'WP_MCP_AI_Pro_CLI_Schedule_Command' );
}
