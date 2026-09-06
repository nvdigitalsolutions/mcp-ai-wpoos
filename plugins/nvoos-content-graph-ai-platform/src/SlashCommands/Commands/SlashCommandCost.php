<?php
/**
 * /cost slash command.
 *
 * Displays token usage and cost summaries from the cost-tracking service
 * with optional per-user breakdown.
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
 * Class SlashCommandCost
 *
 * @since 2.1.0
 */
class SlashCommandCost {
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
	 * Execute the /cost command.
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

		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability gate.
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'You do not have permission to use /cost.', 'nvoos-content-graph-ai-platform' ) );
		}

		$as_json    = isset( $flags['json'] );
		$days       = isset( $flags['days'] ) ? absint( $flags['days'] ) : 7;
		$target_uid = isset( $flags['user-id'] ) ? absint( $flags['user-id'] ) : $current_user_id;

		// Limit look-back to 365 days.
		if ( $days < 1 ) {
			$days = 7;
		} elseif ( $days > 365 ) {
			$days = 365;
		}

		// --user-id requires manage_options.
		if ( $target_uid !== $current_user_id && ! user_can( $current_user_id, 'manage_options' ) ) {
			return new \WP_Error( 'insufficient_capability', __( 'manage_options capability is required to view another user\'s costs.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Cost tracking service is not available.', 'nvoos-content-graph-ai-platform' ) );
		}

		// Calculate date range.
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

		// Fetch site totals.
		$dashboard = \WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( $days );

		// Fetch per-user breakdown.
		$user_breakdown = \WP_MCP_AI_Cost_Tracking_Service::get_user_cost_breakdown( $target_uid, $start_date, $end_date );

		$data = array(
			'days'           => $days,
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'dashboard'      => is_array( $dashboard ) ? $dashboard : array(),
			'user_breakdown' => is_array( $user_breakdown ) ? $user_breakdown : array(),
		);

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
	 * Render a Markdown cost report.
	 *
	 * @param array $data Aggregated cost data.
	 * @return string
	 */
	private function render_report( array $data ) {
		$days      = isset( $data['days'] ) ? (int) $data['days'] : 7;
		$start     = isset( $data['start_date'] ) ? esc_html( $data['start_date'] ) : '—';
		$end       = isset( $data['end_date'] ) ? esc_html( $data['end_date'] ) : '—';
		$dashboard = isset( $data['dashboard'] ) && is_array( $data['dashboard'] ) ? $data['dashboard'] : array();
		$breakdown = isset( $data['user_breakdown'] ) && is_array( $data['user_breakdown'] ) ? $data['user_breakdown'] : array();

		$out = sprintf(
			"## Cost Summary — Last %d Days (%s → %s)\n\n",
			$days,
			$start,
			$end
		);

		// Site-level totals.
		if ( ! empty( $dashboard ) ) {
			$total_cost   = isset( $dashboard['total_cost'] ) ? number_format( (float) $dashboard['total_cost'], 4 ) : '—';
			$total_tokens = isset( $dashboard['total_tokens'] ) ? number_format_i18n( (int) $dashboard['total_tokens'] ) : '—';

			$out .= "### Site Totals\n\n";
			$out .= "| Metric | Value |\n";
			$out .= "|--------|------:|\n";
			$out .= sprintf( "| Total cost | $%s |\n", $total_cost );
			$out .= sprintf( "| Total tokens | %s |\n", $total_tokens );

			// Provider / model breakdown.
			if ( isset( $dashboard['by_provider'] ) && is_array( $dashboard['by_provider'] ) ) {
				$out .= "\n### By Provider / Model\n\n";
				$out .= "| Provider | Model | Tokens | Cost |\n";
				$out .= "|----------|-------|-------:|-----:|\n";
				foreach ( $dashboard['by_provider'] as $provider_slug => $provider_data ) {
					$provider_name = esc_html( (string) $provider_slug );
					if ( isset( $provider_data['models'] ) && is_array( $provider_data['models'] ) ) {
						foreach ( $provider_data['models'] as $model_slug => $model_data ) {
							$m_tokens = isset( $model_data['tokens'] ) ? number_format_i18n( (int) $model_data['tokens'] ) : '—';
							$m_cost   = isset( $model_data['cost'] ) ? '$' . number_format( (float) $model_data['cost'], 4 ) : '—';
							$out     .= sprintf(
								"| %s | %s | %s | %s |\n",
								$provider_name,
								esc_html( (string) $model_slug ),
								$m_tokens,
								$m_cost
							);
						}
					} else {
						$p_tokens = isset( $provider_data['tokens'] ) ? number_format_i18n( (int) $provider_data['tokens'] ) : '—';
						$p_cost   = isset( $provider_data['cost'] ) ? '$' . number_format( (float) $provider_data['cost'], 4 ) : '—';
						$out     .= sprintf( "| %s | — | %s | %s |\n", $provider_name, $p_tokens, $p_cost );
					}
				}
			}
		} else {
			$out .= "_No cost data available for this period._\n";
		}

		// Per-user breakdown.
		if ( ! empty( $breakdown ) ) {
			$out .= "\n### Your Breakdown\n\n";
			$out .= "| Date | Provider | Model | Tokens | Cost |\n";
			$out .= "|------|----------|-------|-------:|-----:|\n";
			foreach ( $breakdown as $row ) {
				$row_date     = isset( $row['date'] ) ? esc_html( (string) $row['date'] ) : '—';
				$row_provider = isset( $row['provider'] ) ? esc_html( (string) $row['provider'] ) : '—';
				$row_model    = isset( $row['model'] ) ? esc_html( (string) $row['model'] ) : '—';
				$row_tokens   = isset( $row['tokens'] ) ? number_format_i18n( (int) $row['tokens'] ) : '—';
				$row_cost     = isset( $row['cost'] ) ? '$' . number_format( (float) $row['cost'], 4 ) : '—';
				$out         .= sprintf( "| %s | %s | %s | %s | %s |\n", $row_date, $row_provider, $row_model, $row_tokens, $row_cost );
			}
		}

		return $out;
	}
}
