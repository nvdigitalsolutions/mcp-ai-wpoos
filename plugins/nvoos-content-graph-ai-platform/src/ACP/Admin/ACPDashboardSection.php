<?php
/**
 * ACP Dashboard Section — powers the "ACP" tab (Agent Client Protocol).
 *
 * Displays ACP protocol status, IDE connection status, session
 * information, and configuration.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\ACP\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * ACP dashboard section.
 */
final class ACPDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_acp_dashboard';
	}

	public function get_title(): string {
		return __( 'Agent Client Protocol (ACP)', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'acp';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array(
			'acp_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable ACP Protocol', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Allow external IDEs (Zed, JetBrains, VS Code) to interact with agents via the Agent Client Protocol.', 'nvoos-content-graph-ai-platform' ),
			),
		);
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$settings       = $this->get_settings();
		$acp_enabled    = ! empty( $settings['acp_enabled'] );
		$base_available = class_exists( 'WP_MCP_AI_ACP_Server' );

		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $acp_enabled ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $acp_enabled ? 'ON' : 'OFF'; ?>
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

		<h3><?php esc_html_e( 'About ACP', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'The Agent Client Protocol (ACP) enables native integration between NV oOS assistants and external IDEs. Developers using Zed, JetBrains, or VS Code can interact with WordPress-hosted AI agents directly from their editor.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'ACP uses JSON-RPC over the WordPress REST API. The protocol handles session management, tool call translation, and permission requests — mapping IDE actions to internal agent operations.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}
}
