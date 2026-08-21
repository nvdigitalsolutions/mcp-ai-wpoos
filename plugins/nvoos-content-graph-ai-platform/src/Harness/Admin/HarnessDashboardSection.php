<?php
/**
 * Harness Dashboard Section — powers the "Harness" tab on the NV Platform dashboard.
 *
 * Displays test harness status, active profiles, recent test results,
 * and profile management links.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Harness\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * Harness dashboard section.
 */
final class HarnessDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_harness_dashboard';
	}

	public function get_title(): string {
		return __( 'Test Harness', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'harness';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array();
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$settings       = $this->get_settings();
		$timeout        = (int) ( $settings['harness_default_timeout'] ?? 30 );
		$base_available = function_exists( 'wp_mcp_ai_run_harness_test' );

		?>
		<h2><?php esc_html_e( 'Agent Test Harness', 'nvoos-content-graph-ai-platform' ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $base_available ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $base_available ? '✓' : '✗'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Base Plugin Bridge', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $timeout ); ?>s</div>
				<div style="color:#646970;"><?php esc_html_e( 'Default Timeout', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph-ai-platform-test-agent' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Test an Agent', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
		</div>

		<h3><?php esc_html_e( 'What is the Test Harness?', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'The test harness evaluates AI agents against predefined scenarios — measuring response quality, tool usage accuracy, hallucination rates, and latency. Profiles define which metrics to collect and what thresholds constitute a passing result.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'Test results are stored as harness profiles linked to agents, enabling regression testing when agent configurations change. Use the Test Agent page to run individual evaluations.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}
}
