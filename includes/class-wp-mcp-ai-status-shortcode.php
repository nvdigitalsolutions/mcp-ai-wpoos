<?php
/**
 * Status Page Shortcode
 *
 * Renders a public-facing status page via the [nvoos_status] shortcode.
 * Displays service component health badges, overall status, and optionally
 * uptime history.
 *
 * Usage:
 *   [nvoos_status]
 *   [nvoos_status show_history="true" compact="true"]
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status page shortcode class.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Status_Shortcode {

	/**
	 * Render the [nvoos_status] shortcode.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Enclosed content (unused).
	 * @return string Rendered HTML.
	 */
	public static function render( $atts, $content = '' ) {
		unset( $content ); // Not used.

		$atts = shortcode_atts(
			array(
				'show_history' => 'false',
				'compact'      => 'false',
			),
			$atts,
			'nvoos_status'
		);

		$show_history = 'true' === $atts['show_history'];
		$is_compact   = 'true' === $atts['compact'];

		$registry   = WP_MCP_AI_Service_Status_Registry::get_instance();
		$components = $registry->get_public_status();
		$overall    = $registry->compute_overall_status( $components );

		ob_start();

		$container_class = 'nvoos-status-page';
		if ( $is_compact ) {
			$container_class .= ' nvoos-status-compact';
		}

		echo '<div class="' . esc_attr( $container_class ) . '">';

		// Overall status banner.
		self::render_overall_banner( $overall );

		// Component grid.
		if ( empty( $components ) ) {
			echo '<p class="nvoos-status-empty">';
			esc_html_e( 'No service components are currently being monitored.', 'mcp-ai-wpoos' );
			echo '</p>';
		} else {
			self::render_component_grid( $components, $is_compact );
		}

		// Uptime history (optional).
		if ( $show_history ) {
			self::render_uptime_history( $registry->get_uptime_history( 30 ) );
		}

		// Last checked timestamp.
		$last_check = (int) get_option( WP_MCP_AI_Service_Status_Registry::LAST_CHECK_KEY, 0 );
		if ( $last_check > 0 ) {
			echo '<p class="nvoos-status-last-checked">';
			printf(
				/* translators: %s: human-readable time difference */
				esc_html__( 'Last checked: %s ago', 'mcp-ai-wpoos' ),
				esc_html( human_time_diff( $last_check ) )
			);
			echo '</p>';
		}

		echo '</div>';

		// Inline styles (minimal, overridable by theme).
		self::render_inline_styles();

		return ob_get_clean();
	}

	/**
	 * Render the overall status banner.
	 *
	 * @since 1.2.0
	 *
	 * @param string $overall Overall status.
	 * @return void
	 */
	private static function render_overall_banner( $overall ) {
		$status_labels = array(
			'operational'          => __( 'All Systems Operational', 'mcp-ai-wpoos' ),
			'under_maintenance'    => __( 'Under Maintenance', 'mcp-ai-wpoos' ),
			'degraded_performance' => __( 'Degraded Performance', 'mcp-ai-wpoos' ),
			'partial_outage'       => __( 'Partial Outage', 'mcp-ai-wpoos' ),
			'major_outage'         => __( 'Major Outage', 'mcp-ai-wpoos' ),
		);

		$label = isset( $status_labels[ $overall ] ) ? $status_labels[ $overall ] : $overall;

		$banner_class = 'nvoos-status-overall nvoos-status-overall--' . esc_attr( $overall );

		echo '<div class="' . esc_attr( $banner_class ) . '">';
		echo '<span class="nvoos-status-indicator nvoos-status-indicator--' . esc_attr( $overall ) . '"></span>';
		echo '<span class="nvoos-status-overall-text">' . esc_html( $label ) . '</span>';
		echo '</div>';
	}

	/**
	 * Render the service component grid.
	 *
	 * @since 1.2.0
	 *
	 * @param array $components Map of slug => component data.
	 * @param bool  $compact    Whether to use compact layout.
	 * @return void
	 */
	private static function render_component_grid( $components, $compact ) {
		$grid_class = $compact ? 'nvoos-status-grid nvoos-status-grid--compact' : 'nvoos-status-grid';

		echo '<div class="' . esc_attr( $grid_class ) . '">';

		foreach ( $components as $slug => $component ) {
			$name       = isset( $component['name'] ) ? $component['name'] : $slug;
			$status     = isset( $component['status'] ) ? $component['status'] : 'unknown';
			$message    = isset( $component['message'] ) ? $component['message'] : '';
			$checked_at = isset( $component['checked_at'] ) ? (int) $component['checked_at'] : 0;

			$status_labels = array(
				'operational'          => __( 'Operational', 'mcp-ai-wpoos' ),
				'under_maintenance'    => __( 'Maintenance', 'mcp-ai-wpoos' ),
				'degraded_performance' => __( 'Degraded', 'mcp-ai-wpoos' ),
				'partial_outage'       => __( 'Partial Outage', 'mcp-ai-wpoos' ),
				'major_outage'         => __( 'Major Outage', 'mcp-ai-wpoos' ),
			);
			$status_label  = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

			echo '<div class="nvoos-status-component nvoos-status-component--' . esc_attr( $status ) . '">';
			echo '<div class="nvoos-status-component-header">';
			echo '<span class="nvoos-status-indicator nvoos-status-indicator--' . esc_attr( $status ) . '"></span>';
			echo '<strong class="nvoos-status-component-name">' . esc_html( $name ) . '</strong>';
			echo '</div>';

			echo '<span class="nvoos-status-badge nvoos-status-badge--' . esc_attr( $status ) . '">';
			echo esc_html( $status_label );
			echo '</span>';

			if ( ! $compact && '' !== $message ) {
				echo '<p class="nvoos-status-component-message">' . esc_html( $message ) . '</p>';
			}

			if ( ! $compact && $checked_at > 0 ) {
				echo '<span class="nvoos-status-component-time">';
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Checked %s ago', 'mcp-ai-wpoos' ),
					esc_html( human_time_diff( $checked_at ) )
				);
				echo '</span>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render uptime history section.
	 *
	 * @since 1.2.0
	 *
	 * @param array $history Map of date => uptime percentage.
	 * @return void
	 */
	private static function render_uptime_history( $history ) {
		if ( empty( $history ) ) {
			return;
		}

		$overall = round( array_sum( $history ) / count( $history ), 2 );

		echo '<div class="nvoos-status-history">';
		echo '<h3>' . esc_html__( 'Uptime History', 'mcp-ai-wpoos' ) . '</h3>';

		echo '<div class="nvoos-status-history-overall">';
		printf(
			/* translators: 1: uptime percentage, 2: number of days */
			esc_html__( '%1$.2f%% uptime over the last %2$d days', 'mcp-ai-wpoos' ),
			(float) $overall,
			(int) count( $history )
		);
		echo '</div>';

		// Simple bar chart using CSS.
		echo '<div class="nvoos-status-history-bars">';
		foreach ( $history as $date => $pct ) {
			$bar_class = 'nvoos-status-bar--ok';
			if ( $pct < 99.0 ) {
				$bar_class = 'nvoos-status-bar--degraded';
			}
			if ( $pct < 95.0 ) {
				$bar_class = 'nvoos-status-bar--outage';
			}

			echo '<div class="nvoos-status-bar-wrapper" title="' . esc_attr( $date . ': ' . $pct . '%' ) . '">';
			echo '<div class="nvoos-status-bar ' . esc_attr( $bar_class ) . '" style="height:' . esc_attr( (string) $pct ) . '%"></div>';
			echo '<span class="nvoos-status-bar-label">' . esc_html( gmdate( 'M j', strtotime( $date ) ) ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render minimal inline styles for the status page.
	 *
	 * These styles are intentionally minimal so themes can override them.
	 * The container class is .nvoos-status-page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function render_inline_styles() {
		?>
		<style>
		.nvoos-status-page {
			max-width: 800px;
			margin: 0 auto;
			padding: 20px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}
		.nvoos-status-overall {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 16px 20px;
			border-radius: 8px;
			margin-bottom: 24px;
			font-size: 16px;
			font-weight: 600;
		}
		.nvoos-status-overall--operational {
			background: #d4edda;
			color: #155724;
			border: 1px solid #c3e6cb;
		}
		.nvoos-status-overall--under_maintenance {
			background: #cce5ff;
			color: #004085;
			border: 1px solid #b8daff;
		}
		.nvoos-status-overall--degraded_performance {
			background: #fff3cd;
			color: #856404;
			border: 1px solid #ffeaa7;
		}
		.nvoos-status-overall--partial_outage {
			background: #ffeaa7;
			color: #b45309;
			border: 1px solid #ffd666;
		}
		.nvoos-status-overall--major_outage {
			background: #f8d7da;
			color: #721c24;
			border: 1px solid #f5c6cb;
		}
		.nvoos-status-overall-text {
			flex: 1;
		}
		.nvoos-status-indicator {
			display: inline-block;
			width: 12px;
			height: 12px;
			border-radius: 50%;
			flex-shrink: 0;
		}
		.nvoos-status-indicator--operational { background: #28a745; }
		.nvoos-status-indicator--under_maintenance { background: #007bff; }
		.nvoos-status-indicator--degraded_performance { background: #ffc107; }
		.nvoos-status-indicator--partial_outage { background: #fd7e14; }
		.nvoos-status-indicator--major_outage { background: #dc3545; }

		.nvoos-status-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
			gap: 16px;
			margin-bottom: 24px;
		}
		.nvoos-status-grid--compact {
			grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
			gap: 10px;
		}
		.nvoos-status-component {
			background: #fff;
			border: 1px solid #dee2e6;
			border-radius: 8px;
			padding: 16px;
		}
		.nvoos-status-component--major_outage {
			border-color: #dc3545;
			background: #fff5f5;
		}
		.nvoos-status-component--partial_outage {
			border-color: #fd7e14;
			background: #fff8f0;
		}
		.nvoos-status-component-header {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 8px;
		}
		.nvoos-status-component-name {
			font-size: 14px;
		}
		.nvoos-status-badge {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
		}
		.nvoos-status-badge--operational { background: #d4edda; color: #155724; }
		.nvoos-status-badge--under_maintenance { background: #cce5ff; color: #004085; }
		.nvoos-status-badge--degraded_performance { background: #fff3cd; color: #856404; }
		.nvoos-status-badge--partial_outage { background: #ffeaa7; color: #b45309; }
		.nvoos-status-badge--major_outage { background: #f8d7da; color: #721c24; }

		.nvoos-status-component-message {
			margin: 8px 0 4px;
			font-size: 13px;
			color: #6c757d;
		}
		.nvoos-status-component-time {
			font-size: 12px;
			color: #adb5bd;
		}

		.nvoos-status-history {
			margin-bottom: 24px;
		}
		.nvoos-status-history h3 {
			margin: 0 0 8px;
			font-size: 16px;
		}
		.nvoos-status-history-overall {
			margin-bottom: 12px;
			font-size: 14px;
			color: #6c757d;
		}
		.nvoos-status-history-bars {
			display: flex;
			align-items: flex-end;
			gap: 4px;
			height: 80px;
		}
		.nvoos-status-bar-wrapper {
			flex: 1;
			display: flex;
			flex-direction: column;
			align-items: center;
			height: 100%;
		}
		.nvoos-status-bar {
			width: 100%;
			max-width: 24px;
			border-radius: 2px 2px 0 0;
			margin-top: auto;
			min-height: 2px;
		}
		.nvoos-status-bar--ok { background: #28a745; }
		.nvoos-status-bar--degraded { background: #ffc107; }
		.nvoos-status-bar--outage { background: #dc3545; }
		.nvoos-status-bar-label {
			font-size: 10px;
			color: #adb5bd;
			margin-top: 4px;
		}

		.nvoos-status-last-checked {
			font-size: 12px;
			color: #adb5bd;
			text-align: center;
		}
		.nvoos-status-empty {
			text-align: center;
			padding: 40px;
			color: #6c757d;
			font-style: italic;
		}
		</style>
		<?php
	}
}
