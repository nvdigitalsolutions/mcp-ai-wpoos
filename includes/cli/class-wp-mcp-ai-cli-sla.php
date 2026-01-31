<?php
/**
 * WP-CLI Commands for SLA management and monitoring.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitor and manage SLA-based job prioritization.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_CLI_SLA {
	/**
	 * Show SLA tier configuration and current status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
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
	 *     # Show SLA status
	 *     $ wp mcp-ai sla status
	 *
	 *     # Export as JSON
	 *     $ wp mcp-ai sla status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			WP_CLI::error( 'SLA Manager class not found.' );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		// Check if enabled.
		$enabled = WP_MCP_AI_SLA_Manager::is_enabled();
		WP_CLI::log( sprintf( 'SLA Prioritization: %s', $enabled ? 'Enabled' : 'Disabled' ) );
		WP_CLI::log( '' );

		// Get tier information.
		$tiers_info = WP_MCP_AI_SLA_Manager::get_all_tiers_info();

		$display_items = array();
		foreach ( $tiers_info as $tier => $info ) {
			$display_items[] = array(
				'tier'        => ucfirst( str_replace( '_', ' ', $tier ) ),
				'priority'    => $info['priority'],
				'sla_target'  => $info['sla_target'] . 's',
				'concurrent'  => $info['concurrent'],
				'description' => $info['description'],
			);
		}

		WP_CLI\Utils\format_items( $format, $display_items, array( 'tier', 'priority', 'sla_target', 'concurrent', 'description' ) );
	}

	/**
	 * Get tuning recommendations based on current queue metrics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
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
	 *     # Show tuning recommendations
	 *     $ wp mcp-ai sla tune
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function tune( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			WP_CLI::error( 'SLA Manager class not found.' );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$recommendations = WP_MCP_AI_SLA_Manager::get_tuning_recommendations();

		$display_items = array();
		foreach ( $recommendations as $rec ) {
			$display_items[] = array(
				'tier'        => ucfirst( str_replace( '_', ' ', $rec['tier'] ) ),
				'current'     => $rec['current'],
				'recommended' => $rec['recommended'],
				'status'      => $rec['status'],
				'message'     => $rec['message'],
			);
		}

		WP_CLI\Utils\format_items( $format, $display_items, array( 'tier', 'current', 'recommended', 'status', 'message' ) );

		// Show summary.
		$critical_count = 0;
		$warning_count  = 0;
		$ok_count       = 0;

		foreach ( $recommendations as $rec ) {
			if ( 'critical' === $rec['status'] ) {
				++$critical_count;
			} elseif ( 'warning' === $rec['status'] ) {
				++$warning_count;
			} else {
				++$ok_count;
			}
		}

		WP_CLI::log( '' );
		if ( $critical_count > 0 ) {
			WP_CLI::warning( sprintf( '%d tier(s) have critical issues.', $critical_count ) );
		} elseif ( $warning_count > 0 ) {
			WP_CLI::warning( sprintf( '%d tier(s) have warnings.', $warning_count ) );
		} else {
			WP_CLI::success( 'All SLA tiers are healthy.' );
		}
	}

	/**
	 * Analyze queue metrics for a specific tier.
	 *
	 * ## OPTIONS
	 *
	 * <tier>
	 * : Tier to analyze (realtime, near_realtime, batch).
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
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
	 *     # Analyze realtime tier
	 *     $ wp mcp-ai sla analyze realtime
	 *
	 *     # Analyze batch tier as JSON
	 *     $ wp mcp-ai sla analyze batch --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function analyze( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			WP_CLI::error( 'SLA Manager class not found.' );
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Tier is required. Use: realtime, near_realtime, or batch' );
		}

		$tier   = $args[0];
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$metrics = WP_MCP_AI_SLA_Manager::analyze_queue_metrics( $tier );

		if ( isset( $metrics['error'] ) ) {
			WP_CLI::error( $metrics['error'] );
		}

		// Display metrics as table.
		$display_items = array(
			array(
				'metric' => 'Tier',
				'value'  => $tier,
			),
			array(
				'metric' => 'SLA Target',
				'value'  => $metrics['sla_target'] . 's',
			),
			array(
				'metric' => 'Arrival Rate',
				'value'  => number_format( $metrics['arrival_rate'], 2 ) . ' jobs/sec',
			),
			array(
				'metric' => 'Service Time',
				'value'  => number_format( $metrics['service_time'], 2 ) . 's',
			),
			array(
				'metric' => 'Wait Time',
				'value'  => number_format( $metrics['wait_time'], 2 ) . 's',
			),
			array(
				'metric' => 'Queue Length',
				'value'  => number_format( $metrics['queue_length'], 2 ),
			),
			array(
				'metric' => 'System Capacity',
				'value'  => number_format( $metrics['system_capacity'], 2 ),
			),
			array(
				'metric' => 'Utilization',
				'value'  => number_format( $metrics['utilization'] * 100, 1 ) . '%',
			),
			array(
				'metric' => 'Required Workers',
				'value'  => $metrics['required_workers'],
			),
			array(
				'metric' => 'Recommended Workers',
				'value'  => $metrics['recommended_workers'],
			),
			array(
				'metric' => 'Max Concurrent',
				'value'  => $metrics['max_concurrent'],
			),
			array(
				'metric' => 'Over Capacity',
				'value'  => $metrics['over_capacity'] ? 'Yes' : 'No',
			),
			array(
				'metric' => 'Meets SLA',
				'value'  => $metrics['meets_sla'] ? 'Yes' : 'No',
			),
		);

		WP_CLI\Utils\format_items( $format, $display_items, array( 'metric', 'value' ) );

		// Show current queue stats.
		if ( isset( $metrics['current_stats'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Current Queue Stats:' );
			WP_CLI::log( sprintf( '  Total: %d', $metrics['current_stats']['total'] ) );
			WP_CLI::log( sprintf( '  Pending: %d', $metrics['current_stats']['pending'] ) );
			WP_CLI::log( sprintf( '  Active: %d', $metrics['current_stats']['active'] ) );
			WP_CLI::log( sprintf( '  Failed: %d', $metrics['current_stats']['failed'] ) );
		}

		// Show warnings.
		WP_CLI::log( '' );
		if ( ! $metrics['meets_sla'] ) {
			WP_CLI::warning( 'SLA target is at risk!' );
		}
		if ( $metrics['over_capacity'] ) {
			WP_CLI::warning( 'Queue is over capacity!' );
		}
		if ( $metrics['meets_sla'] && ! $metrics['over_capacity'] ) {
			WP_CLI::success( 'Tier is healthy.' );
		}
	}

	/**
	 * Enable SLA-based prioritization.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable SLA prioritization
	 *     $ wp mcp-ai sla enable
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function enable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['sla_prioritization_enabled'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		WP_CLI::success( 'SLA-based prioritization enabled.' );
	}

	/**
	 * Disable SLA-based prioritization.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable SLA prioritization
	 *     $ wp mcp-ai sla disable
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function disable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['sla_prioritization_enabled'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		WP_CLI::success( 'SLA-based prioritization disabled.' );
	}
}
