<?php
/**
 * WP-CLI Commands for SLA management and monitoring.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Monitor and manage SLA-based job prioritization.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_CLI_SLA extends WP_MCP_AI_CLI_Base_Command {
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
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			$this->error( __( 'SLA Manager class not found.', 'mcp-ai-wpoos' ) );
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		// Check if enabled.
		$enabled = WP_MCP_AI_SLA_Manager::is_enabled();
		/* translators: %s: Enabled or Disabled */
		$this->info( sprintf( __( 'SLA Prioritization: %s', 'mcp-ai-wpoos' ), $enabled ? __( 'Enabled', 'mcp-ai-wpoos' ) : __( 'Disabled', 'mcp-ai-wpoos' ) ) );
		$this->info( '' );

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
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function tune( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			$this->error( __( 'SLA Manager class not found.', 'mcp-ai-wpoos' ) );
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

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

		$this->info( '' );
		if ( $critical_count > 0 ) {
			/* translators: %d: number of SLA tiers */
			$this->warning( sprintf( __( '%d tier(s) have critical issues.', 'mcp-ai-wpoos' ), $critical_count ) );
		} elseif ( $warning_count > 0 ) {
			/* translators: %d: number of SLA tiers */
			$this->warning( sprintf( __( '%d tier(s) have warnings.', 'mcp-ai-wpoos' ), $warning_count ) );
		} else {
			$this->success( __( 'All SLA tiers are healthy.', 'mcp-ai-wpoos' ) );
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
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function analyze( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			$this->error( __( 'SLA Manager class not found.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $args[0] ) ) {
			$this->error( __( 'Tier is required. Use: realtime, near_realtime, or batch', 'mcp-ai-wpoos' ) );
		}

		$tier   = sanitize_key( $args[0] );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$metrics = WP_MCP_AI_SLA_Manager::analyze_queue_metrics( $tier );

		if ( isset( $metrics['error'] ) ) {
			$this->error( $metrics['error'] );
		}

		// Display metrics as table.
		$display_items = array(
			array(
				'metric' => __( 'Tier', 'mcp-ai-wpoos' ),
				'value'  => $tier,
			),
			array(
				'metric' => __( 'SLA Target', 'mcp-ai-wpoos' ),
				'value'  => $metrics['sla_target'] . 's',
			),
			array(
				'metric' => __( 'Arrival Rate', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['arrival_rate'], 2 ) . ' jobs/sec',
			),
			array(
				'metric' => __( 'Service Time', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['service_time'], 2 ) . 's',
			),
			array(
				'metric' => __( 'Wait Time', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['wait_time'], 2 ) . 's',
			),
			array(
				'metric' => __( 'Queue Length', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['queue_length'], 2 ),
			),
			array(
				'metric' => __( 'System Capacity', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['system_capacity'], 2 ),
			),
			array(
				'metric' => __( 'Utilization', 'mcp-ai-wpoos' ),
				'value'  => number_format( $metrics['utilization'] * 100, 1 ) . '%',
			),
			array(
				'metric' => __( 'Required Workers', 'mcp-ai-wpoos' ),
				'value'  => $metrics['required_workers'],
			),
			array(
				'metric' => __( 'Recommended Workers', 'mcp-ai-wpoos' ),
				'value'  => $metrics['recommended_workers'],
			),
			array(
				'metric' => __( 'Max Concurrent', 'mcp-ai-wpoos' ),
				'value'  => $metrics['max_concurrent'],
			),
			array(
				'metric' => __( 'Over Capacity', 'mcp-ai-wpoos' ),
				'value'  => $metrics['over_capacity'] ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' ),
			),
			array(
				'metric' => __( 'Meets SLA', 'mcp-ai-wpoos' ),
				'value'  => $metrics['meets_sla'] ? __( 'Yes', 'mcp-ai-wpoos' ) : __( 'No', 'mcp-ai-wpoos' ),
			),
		);

		WP_CLI\Utils\format_items( $format, $display_items, array( 'metric', 'value' ) );

		// Show current queue stats.
		if ( isset( $metrics['current_stats'] ) ) {
			$this->info( '' );
			$this->info( __( 'Current Queue Stats:', 'mcp-ai-wpoos' ) );
			/* translators: %d: queue stat count */
				$this->info( sprintf( __( '  Total: %d', 'mcp-ai-wpoos' ), $metrics['current_stats']['total'] ) );
				/* translators: %d: queue stat count */
				$this->info( sprintf( __( '  Pending: %d', 'mcp-ai-wpoos' ), $metrics['current_stats']['pending'] ) );
				/* translators: %d: queue stat count */
				$this->info( sprintf( __( '  Active: %d', 'mcp-ai-wpoos' ), $metrics['current_stats']['active'] ) );
				/* translators: %d: queue stat count */
				$this->info( sprintf( __( '  Failed: %d', 'mcp-ai-wpoos' ), $metrics['current_stats']['failed'] ) );
		}

		// Show warnings.
		$this->info( '' );
		if ( ! $metrics['meets_sla'] ) {
			$this->warning( __( 'SLA target is at risk!', 'mcp-ai-wpoos' ) );
		}
		if ( $metrics['over_capacity'] ) {
			$this->warning( __( 'Queue is over capacity!', 'mcp-ai-wpoos' ) );
		}
		if ( $metrics['meets_sla'] && ! $metrics['over_capacity'] ) {
			$this->success( __( 'Tier is healthy.', 'mcp-ai-wpoos' ) );
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
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function enable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['sla_prioritization_enabled'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->success( __( 'SLA-based prioritization enabled.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Disable SLA-based prioritization.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable SLA prioritization
	 *     $ wp mcp-ai sla disable
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function disable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
		$settings['sla_prioritization_enabled'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->success( __( 'SLA-based prioritization disabled.', 'mcp-ai-wpoos' ) );
	}
}
