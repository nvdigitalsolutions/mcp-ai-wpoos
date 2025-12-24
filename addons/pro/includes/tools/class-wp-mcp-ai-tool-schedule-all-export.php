<?php
/**
 * Pro Tool: Schedule WP All Export.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Schedules a WP All Export to run at specified intervals.
 */
class WP_MCP_AI_Pro_Tool_Schedule_All_Export implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WP All Export is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return ( class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' ) ) && function_exists( 'wp_schedule_event' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Export Pro tool is disabled because WP All Export plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'schedule_all_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Schedule WP All Export', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules a WP All Export to run at specified intervals (Pro feature). Requires WP All Export plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'export_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the export template to schedule.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'interval'   => array(
					'type'        => 'string',
					'description' => __( 'Schedule interval: hourly, twicedaily, daily, or weekly.', 'wp-mcp-ai' ),
					'enum'        => array( 'hourly', 'twicedaily', 'daily', 'weekly' ),
					'default'     => 'daily',
				),
				'start_time' => array(
					'type'        => 'string',
					'description' => __( 'Optional start time in Y-m-d H:i:s format. Defaults to next interval.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'export_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_all_export_missing', __( 'WP All Export is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to schedule exports.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to schedule exports.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['export_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Export ID is required.', 'wp-mcp-ai' ) );
		}

		$export_id = absint( $arguments['export_id'] );

		// Verify export exists.
		$export = get_post( $export_id );
		if ( ! $export || 'pmxe_exports' !== $export->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_export', __( 'Invalid export ID.', 'wp-mcp-ai' ) );
		}

		$interval   = isset( $arguments['interval'] ) ? sanitize_key( $arguments['interval'] ) : 'daily';
		$start_time = isset( $arguments['start_time'] ) ? sanitize_text_field( $arguments['start_time'] ) : '';

		// Validate interval.
		$valid_intervals = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
		if ( ! in_array( $interval, $valid_intervals, true ) ) {
			$interval = 'daily';
		}

		// Add weekly schedule if it doesn't exist.
		if ( 'weekly' === $interval ) {
			add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		}

		// Calculate start timestamp.
		$timestamp = time();
		if ( $start_time ) {
			$parsed_time = strtotime( $start_time );
			if ( $parsed_time && $parsed_time > time() ) {
				$timestamp = $parsed_time;
			}
		}

		// Create unique hook name for this export.
		$hook = 'wp_mcp_ai_scheduled_export_' . $export_id;

		// Clear any existing schedules for this export.
		$scheduled = wp_next_scheduled( $hook, array( $export_id ) );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, $hook, array( $export_id ) );
		}

		// Schedule the export.
		$scheduled = wp_schedule_event( $timestamp, $interval, $hook, array( $export_id ) );

		if ( false === $scheduled ) {
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule export.', 'wp-mcp-ai' ) );
		}

		// Store schedule metadata.
		update_post_meta( $export_id, 'scheduled', 1 );
		update_post_meta( $export_id, 'schedule_interval', $interval );
		update_post_meta( $export_id, 'schedule_hook', $hook );

		// Register the action handler if not already registered.
		if ( ! has_action( $hook ) ) {
			add_action( $hook, array( $this, 'execute_scheduled_export' ) );
		}

		$next_run = wp_next_scheduled( $hook, array( $export_id ) );

		return array(
			'success'     => true,
			'message'     => __( 'Export scheduled successfully.', 'wp-mcp-ai' ),
			'export_id'   => $export_id,
			'export_name' => $export->post_title,
			'interval'    => $interval,
			'next_run'    => $next_run ? gmdate( DATE_W3C, $next_run ) : '',
		);
	}

	/**
	 * Add weekly schedule to WordPress cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 7 days in seconds.
				'display'  => __( 'Once Weekly', 'wp-mcp-ai' ),
			);
		}
		return $schedules;
	}

	/**
	 * Execute a scheduled export.
	 *
	 * @param int $export_id Export ID to execute.
	 */
	public function execute_scheduled_export( $export_id ) {
		if ( ! class_exists( 'PMXE_Export_Record' ) ) {
			return;
		}

		try {
			$export_record = new PMXE_Export_Record();
			$export_record->getById( $export_id );

			if ( $export_record->id ) {
				$export_record->process();
				$export_record->execute();
			}
		} catch ( Exception $e ) {
			error_log( 'WP MCP AI: Scheduled export failed - ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Export plugin.
			'state-changing',      // Modifies state by scheduling cron jobs.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
