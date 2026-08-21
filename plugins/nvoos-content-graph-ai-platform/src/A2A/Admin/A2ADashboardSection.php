<?php
/**
 * A2A Dashboard Section — powers the "A2A" tab (Agent-to-Agent protocol).
 *
 * Displays A2A protocol status, registered agent cards, connection
 * health, and configuration status.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\A2A\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * A2A dashboard section.
 */
final class A2ADashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_a2a_dashboard';
	}

	public function get_title(): string {
		return __( 'Agent-to-Agent (A2A)', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'a2a';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array(
			'a2a_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable A2A Protocol', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Allow agents to discover and communicate with each other via the A2A protocol.', 'nvoos-content-graph-ai-platform' ),
			),
		);
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$settings       = $this->get_settings();
		$a2a_enabled    = ! empty( $settings['a2a_enabled'] );
		$base_available = class_exists( 'WP_MCP_AI_A2A_Agent_Card' );

		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $a2a_enabled ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $a2a_enabled ? 'ON' : 'OFF'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Protocol Status', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
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

		<h3><?php esc_html_e( 'About A2A', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'The Agent-to-Agent (A2A) protocol enables autonomous communication between AI agents. Each agent publishes an Agent Card describing its capabilities, tools, and endpoint. Other agents can discover, authenticate with, and delegate tasks to peers.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'A2A messages follow a standard format with roles, parts, context IDs, and task tracking. The protocol supports both synchronous and asynchronous task delegation.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}
}
