<?php
/**
 * Blueprints Dashboard Section — powers the "Blueprints" tab.
 *
 * Displays blueprint library status, template counts, apply history,
 * and configuration for auto-apply behaviour.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Blueprints\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Blueprints\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * Blueprints dashboard section.
 */
final class BlueprintsDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_blueprints_dashboard';
	}

	public function get_title(): string {
		return __( 'Blueprints', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'blueprints';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array(
			'blueprint_auto_apply' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Auto-apply blueprints on activation', 'nvoos-content-graph-ai-platform' ),
				'description' => __( 'When enabled, blueprints are automatically applied when their parent plugin or addon is activated.', 'nvoos-content-graph-ai-platform' ),
			),
		);
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$settings       = $this->get_settings();
		$auto_apply     = ! empty( $settings['blueprint_auto_apply'] );
		$base_available = class_exists( 'WP_MCP_AI_Blueprint_Registry' );
		$template_count = $this->countTemplates();

		?>
		<h2><?php echo esc_html( $this->get_title() ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $template_count ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Blueprint Templates', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $auto_apply ? '#00a32a' : '#646970'; ?>;">
					<?php echo $auto_apply ? 'ON' : 'OFF'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Auto-Apply', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:160px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $base_available ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $base_available ? '✓' : '✗'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Base Plugin Bridge', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ai_platform_template' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Manage Templates', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ai_platform_template' ) ); ?>" class="button">
				<?php esc_html_e( 'New Template', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
		</div>

		<h3><?php esc_html_e( 'Settings', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<table class="form-table">
			<tbody>
				<?php $this->render(); ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'About Blueprints', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'Blueprints are pre-built configuration packages that set up agents, tools, skills, workflows, and graph schemas in a single operation. Think of them as "starter kits" for specific use cases — e-commerce, content marketing, customer support, etc.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'The blueprint library grows as addons register their blueprints. Auto-apply mode automatically deploys a blueprint when its parent addon is activated, ensuring new features are ready immediately.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Count blueprint templates from the Platform's own CPT.
	 */
	private function countTemplates(): int {
		if ( ! post_type_exists( 'ai_platform_template' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'ai_platform_template' );
		return is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
	}
}
