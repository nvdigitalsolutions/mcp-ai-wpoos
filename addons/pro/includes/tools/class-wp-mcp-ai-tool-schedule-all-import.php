<?php
/**
 * Pro Tool: Schedule WP All Import.
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
 * Schedules a WP All Import to run at specified intervals.
 */
class WP_MCP_AI_Pro_Tool_Schedule_All_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WP All Import is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return ( class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' ) ) && function_exists( 'wp_schedule_event' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Import Pro tool is disabled because WP All Import plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'schedule_all_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Schedule WP All Import', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules a WP All Import to run at specified intervals (Pro feature). Requires WP All Import plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'import_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the import template to schedule.', 'wp-mcp-ai' ),
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
			'required'             => array( 'import_id' ),
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
			return new WP_Error( 'wp_mcp_ai_all_import_missing', __( 'WP All Import is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to schedule imports.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to schedule imports.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['import_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Import ID is required.', 'wp-mcp-ai' ) );
		}

		$import_id = absint( $arguments['import_id'] );

		// Verify import exists.
		$import = get_post( $import_id );
		if ( ! $import || 'import' !== $import->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_import', __( 'Invalid import ID.', 'wp-mcp-ai' ) );
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

		// Create unique hook name for this import.
		$hook = 'wp_mcp_ai_scheduled_import_' . $import_id;

		// Clear any existing schedules for this import.
		$scheduled = wp_next_scheduled( $hook, array( $import_id ) );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, $hook, array( $import_id ) );
		}

		// Schedule the import.
		$scheduled = wp_schedule_event( $timestamp, $interval, $hook, array( $import_id ) );

		if ( false === $scheduled ) {
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule import.', 'wp-mcp-ai' ) );
		}

		// Store schedule metadata.
		update_post_meta( $import_id, 'is_scheduled', 1 );
		update_post_meta( $import_id, 'schedule_interval', $interval );
		update_post_meta( $import_id, 'schedule_hook', $hook );

		// Register the action handler if not already registered.
		if ( ! has_action( $hook ) ) {
			add_action( $hook, array( $this, 'execute_scheduled_import' ) );
		}

		$next_run = wp_next_scheduled( $hook, array( $import_id ) );

		return array(
			'success'     => true,
			'message'     => __( 'Import scheduled successfully.', 'wp-mcp-ai' ),
			'import_id'   => $import_id,
			'import_name' => $import->post_title,
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
	 * Execute a scheduled import.
	 *
	 * @param int $import_id Import ID to execute.
	 */
	public function execute_scheduled_import( $import_id ) {
		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			return;
		}

		try {
			$import_record = new PMXI_Import_Record();
			$import_record->getById( $import_id );

			if ( ! $import_record->id ) {
				return;
			}

			// Get import key for cron URL.
			$import_key = $import_record->import_key ?? '';
			
			if ( $import_key ) {
				$trigger_url = add_query_arg(
					array(
						'import_key' => $import_key,
						'import_id'  => $import_id,
						'action'     => 'trigger',
					),
					home_url()
				);

				// Trigger via HTTP request.
				wp_remote_get(
					$trigger_url,
					array(
						'timeout'   => 1,
						'blocking'  => false,
						'sslverify' => false,
					)
				);
			}
		} catch ( Exception $e ) {
			error_log( 'WP MCP AI: Scheduled import failed - ' . $e->getMessage() );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Import plugin.
			'state-changing',      // Modifies state by scheduling cron jobs.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
