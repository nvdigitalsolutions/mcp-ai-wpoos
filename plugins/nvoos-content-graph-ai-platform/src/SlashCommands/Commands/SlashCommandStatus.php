<?php
/**
 * /status slash command.
 *
 * Aggregates async health, job counts, and tool-registry status into a
 * single Markdown report with ✅ / ⚠️ / ❌ indicators.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandStatus
 *
 * @since 2.1.0
 */
class SlashCommandStatus {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute the /status command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context.
	 * @return array|WP_Error Command response or error.
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new \WP_Error( 'guest_forbidden', __( 'This command requires authentication.', 'nvoos-content-graph-ai-platform' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /status.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json = isset( $flags['json'] );

		$data = array();

		// 1. Async health.
		$data['async_health'] = $this->get_async_health();

		// 2. Cron status counts.
		$data['job_counts'] = $this->get_job_counts( $user_id );

		// 3. Tool registry status.
		$data['tool_registry'] = $this->get_tool_registry_status();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $data,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_report( $data ),
			'data'    => $data,
		);
	}

	/**
	 * Get async health data.
	 *
	 * @return array
	 */
	private function get_async_health() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Health_Monitor' ) ) {
			return array(
				'available' => false,
				'status'    => 'unknown',
				'message'   => __( 'Async health monitor not available.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$health = \WP_MCP_AI_Async_Health_Monitor::check_async_health();

		if ( ! is_array( $health ) ) {
			return array(
				'available' => true,
				'status'    => 'unknown',
				'raw'       => $health,
			);
		}

		return array_merge( array( 'available' => true ), $health );
	}

	/**
	 * Get job counts from cron status service.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private function get_job_counts( $user_id ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Cron_Status_Service' ) ) {
			return array(
				'available' => false,
				'message'   => __( 'Cron status service not available.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$service = new \WP_MCP_AI_Cron_Status_Service();
		$counts  = $service->get_status_counts( $user_id );

		if ( ! is_array( $counts ) ) {
			return array(
				'available' => true,
				'counts'    => array(),
			);
		}

		return array(
			'available' => true,
			'counts'    => $counts,
		);
	}

	/**
	 * Get tool registry status.
	 *
	 * @return array
	 */
	private function get_tool_registry_status() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array(
				'available'  => false,
				'tool_count' => 0,
				'message'    => __( 'Tool registry not available.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		$registry   = \WP_MCP_AI_Tool_Registry::get_instance();
		$tools      = $registry->get_tools();
		$tool_count = is_array( $tools ) ? count( $tools ) : 0;

		return array(
			'available'  => true,
			'tool_count' => $tool_count,
		);
	}

	/**
	 * Render Markdown status report.
	 *
	 * @param array $data Aggregated data.
	 * @return string
	 */
	private function render_report( array $data ) {
		$out  = "## System Status\n\n";
		$out .= "| Component | Status |\n";
		$out .= "|-----------|--------|\n";

		// Async health section.
		$health        = isset( $data['async_health'] ) ? $data['async_health'] : array();
		$health_ok     = isset( $health['available'] ) && $health['available'];
		$health_status = isset( $health['status'] ) ? (string) $health['status'] : 'unknown';
		$health_icon   = $this->get_icon( $health_ok, $health_status );
		$out          .= sprintf( "| Async Health | %s %s |\n", $health_icon, esc_html( $health_status ) );

		// Job counts section.
		$job_data  = isset( $data['job_counts'] ) ? $data['job_counts'] : array();
		$jobs_ok   = isset( $job_data['available'] ) && $job_data['available'];
		$counts    = isset( $job_data['counts'] ) && is_array( $job_data['counts'] ) ? $job_data['counts'] : array();
		$failed    = isset( $counts['failed'] ) ? (int) $counts['failed'] : 0;
		$pending   = isset( $counts['pending'] ) ? (int) $counts['pending'] : 0;
		$active    = isset( $counts['active'] ) ? (int) $counts['active'] : 0;
		$jobs_icon = ! $jobs_ok ? '⚠️' : ( $failed > 0 ? '❌' : '✅' );
		$out      .= sprintf( "| Job Queue | %s pending: %d, active: %d, failed: %d |\n", $jobs_icon, $pending, $active, $failed );

		// Tool registry section.
		$tool_data  = isset( $data['tool_registry'] ) ? $data['tool_registry'] : array();
		$tools_ok   = isset( $tool_data['available'] ) && $tool_data['available'];
		$tool_count = isset( $tool_data['tool_count'] ) ? (int) $tool_data['tool_count'] : 0;
		$tools_icon = $tools_ok ? '✅' : '❌';
		$out       .= sprintf(
			"| Tool Registry | %s %s |\n",
			$tools_icon,
			$tools_ok
				? sprintf(
					/* translators: %d: number of registered tools */
					__( '%d tools registered', 'nvoos-content-graph-ai-platform' ),
					$tool_count
				)
				: __( 'unavailable', 'nvoos-content-graph-ai-platform' )
		);

		// Detailed counts if available.
		if ( $jobs_ok && ! empty( $counts ) ) {
			$out .= "\n### Job Counts\n\n";
			foreach ( $counts as $count_status => $count_val ) {
				$out .= sprintf( "- **%s:** %d\n", esc_html( (string) $count_status ), (int) $count_val );
			}
		}

		// Extra health detail if available.
		if ( $health_ok && ! empty( $health ) ) {
			$out .= "\n### Health Details\n\n";
			foreach ( $health as $key => $val ) {
				if ( 'available' === $key || 'status' === $key ) {
					continue;
				}
				if ( is_scalar( $val ) ) {
					$out .= sprintf( "- **%s:** %s\n", esc_html( (string) $key ), esc_html( (string) $val ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Choose an indicator icon based on availability and status string.
	 *
	 * @param bool   $available Service is available.
	 * @param string $status    Raw status string.
	 * @return string Emoji icon.
	 */
	private function get_icon( $available, $status ) {
		if ( ! $available ) {
			return '⚠️';
		}
		if ( strpos( $status, 'error' ) !== false || strpos( $status, 'fail' ) !== false ) {
			return '❌';
		}
		if ( strpos( $status, 'warn' ) !== false || strpos( $status, 'unknown' ) !== false ) {
			return '⚠️';
		}
		return '✅';
	}
}
