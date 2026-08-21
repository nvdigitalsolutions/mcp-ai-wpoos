<?php
/**
 * Measurement Dashboard Section — powers the "Measurement" tab.
 *
 * Displays telemetry overview, retention policy, data collection
 * status, and metric summary from the base plugin's measurement
 * subsystem.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Measurement\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * Measurement dashboard section.
 */
final class MeasurementDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_measurement_dashboard';
	}

	public function get_title(): string {
		return __( 'Measurement & Telemetry', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'measurement';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array(
			'measurement_retention_days' => array(
				'type'        => 'number',
				'label'       => __( 'Data Retention (days)', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'How long telemetry data is kept before automatic cleanup. Set to 0 to keep indefinitely.', 'nvoos-content-graph-ai-platform' ),
				'min'         => 0,
				'max'         => 365,
				'default'     => 90,
			),
		);
	}

	/**
	 * Render both form-table fields and telemetry status cards.
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		$settings       = $this->get_settings();
		$retention_days = (int) ( $settings['measurement_retention_days'] ?? 90 );
		$base_available = function_exists( 'wp_mcp_ai_get_telemetry_stats' );
		$stats          = $base_available ? $this->getTelemetryStats() : array();

		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $stats['total_events'] ?? 0 ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Total Events', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $retention_days ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Retention Days', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $base_available ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $base_available ? '✓' : '✗'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Base Plugin Bridge', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<h3><?php esc_html_e( 'Settings', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<table class="form-table">
			<tbody>
				<?php $this->render(); ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'About Telemetry', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'The measurement subsystem collects telemetry data from agent runs, tool executions, and chat interactions. This data powers dashboards, cost analysis, and performance monitoring. No personally identifiable information is collected.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'Markup telemetry measures the structural distance between agent responses and canonical examples, providing a quantitative signal for response quality. Run Timeline visualises each agent execution as a span tree.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Get telemetry stats from the base plugin.
	 */
	private function getTelemetryStats(): array {
		if ( ! function_exists( 'wp_mcp_ai_get_telemetry_stats' ) ) {
			return array();
		}
		$stats = wp_mcp_ai_get_telemetry_stats();
		return is_array( $stats ) ? $stats : array();
	}
}
