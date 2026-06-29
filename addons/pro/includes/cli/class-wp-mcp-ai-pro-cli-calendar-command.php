<?php
/**
 * WP-CLI commands for Calendar Booking.
 *
 * Provides bulk import commands for services in the
 * mcp_service custom post type.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CLI
 * @since     1.4.0
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

/**
 * Manages Calendar Booking records from the command line.
 *
 * ## EXAMPLES
 *
 *     # Import services from JSON file.
 *     wp mcp calendar import-services /path/to/services.json
 *
 *     # Import services from CSV with dry-run.
 *     wp mcp calendar import-services /path/to/services.csv --format=csv --dry-run
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Pro_CLI_Calendar_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Import services from a JSON or CSV file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the import file.
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--place-id=<id>]
	 * : Default place ID to link all imported services to.
	 *
	 * [--skip-existing]
	 * : Skip services that already exist.
	 *
	 * [--update-existing]
	 * : Update existing services with fresh data.
	 *
	 * [--dry-run]
	 * : Preview without creating.
	 *
	 * [--batch-size=<size>]
	 * : Items per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param array $args        Positional arguments.
	 * @param array $assoc_args  Associative arguments.
	 * @return void
	 */
	public function import_services( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_calendar_booking_toolkit', 'Calendar Booking' );

		$file = isset( $args[0] ) ? $args[0] : '';
		if ( empty( $file ) || ! file_exists( $file ) ) {
			WP_CLI::error( __( 'File not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$format = $this->get_format( $assoc_args, 'json' );
		if ( ! in_array( $format, array( 'json', 'csv' ), true ) ) {
			WP_CLI::error( __( 'Format must be json or csv.', 'mcp-ai-wpoos-pro' ) );
		}

		$is_dry_run = $this->is_dry_run( $assoc_args );
		if ( $is_dry_run ) {
			$this->dry_run_notice();
		}

		$tool_args = array(
			'skip_existing'   => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'update-existing', false ),
			'update_existing' => WP_CLI\Utils\get_flag_value( $assoc_args, 'update-existing', false ),
			'dedup_strategy'  => 'name',
			'dry_run'         => $is_dry_run,
			'batch_size'      => absint( WP_CLI\Utils\get_flag_value( $assoc_args, 'batch-size', 50 ) ),
		);

		$place_id = WP_CLI\Utils\get_flag_value( $assoc_args, 'place-id' );
		if ( $place_id ) {
			$tool_args['default_place_id'] = absint( $place_id );
		}

		$raw = file_get_contents( $file );
		if ( false === $raw ) {
			WP_CLI::error( __( 'Failed to read file.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'csv' === $format ) {
			$tool_args['source']     = 'csv';
			$tool_args['csv_string'] = $raw;
		} else {
			$tool_args['source']      = 'json';
			$tool_args['json_string'] = $raw;
		}

		$this->start_timer();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Services' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/calendar-booking/class-wp-mcp-ai-tool-import-services.php';
		}

		$tool   = new WP_MCP_AI_Tool_Import_Services();
		$result = $tool->execute( $tool_args, array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$this->display_summary(
			array(
				'total'         => $result['total'],
				'success_count' => $result['created'] + $result['updated'],
				'error_count'   => $result['failed'] + $result['skipped'],
				'errors'        => $result['errors'],
			)
		);
	}
}

// Register the command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp calendar', 'WP_MCP_AI_Pro_CLI_Calendar_Command' );
}
