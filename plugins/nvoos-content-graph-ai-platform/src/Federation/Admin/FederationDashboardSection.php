<?php
/**
 * Federation Dashboard Section — powers the "Federation" tab.
 *
 * Displays federation configuration, connected node status,
 * sync frequency settings, and network overview.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Federation\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * Federation dashboard section.
 */
final class FederationDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_federation_dashboard';
	}

	public function get_title(): string {
		return __( 'Federation', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'federation';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array(
			'federation_enabled'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Federation', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'Allow this platform instance to join a federated network of NV oOS nodes.', 'nvoos-content-graph-ai-platform' ),
			),
			'federation_sync_freq' => array(
				'type'        => 'select',
				'label'       => __( 'Sync Frequency', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'How often federated nodes synchronise knowledge graph data.', 'nvoos-content-graph-ai-platform' ),
				'options'     => array(
					'hourly'     => __( 'Hourly', 'nvoos-content-graph-ai-platform' ),
					'twicedaily' => __( 'Twice Daily', 'nvoos-content-graph-ai-platform' ),
					'daily'      => __( 'Daily', 'nvoos-content-graph-ai-platform' ),
					'weekly'     => __( 'Weekly', 'nvoos-content-graph-ai-platform' ),
				),
				'default'     => 'daily',
			),
		);
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$settings           = $this->get_settings();
		$federation_enabled = ! empty( $settings['federation_enabled'] );
		$sync_freq          = $settings['federation_sync_freq'] ?? 'daily';
		$base_available     = class_exists( 'WP_MCP_AI_Federation_Manager' );

		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $federation_enabled ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $federation_enabled ? 'ON' : 'OFF'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Federation Status', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo esc_html( ucfirst( $sync_freq ) ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Sync Frequency', 'nvoos-content-graph-ai-platform' ); ?></div>
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

		<h3><?php esc_html_e( 'About Federation', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'Federation allows multiple NV oOS instances to share knowledge graph data, agent configurations, and tool registries across a network. Each node maintains its own graph while selectively synchronising with peers.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'Federated nodes can be configured to push updates, pull from peers, or operate in a mesh topology. Sync frequency controls how often graph diffs are exchanged between nodes.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}
}
