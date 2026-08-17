<?php
/**
 * Agents Dashboard Section — powers the "Agents" tab on the NV Platform dashboard.
 *
 * Displays agent inventory, recent agents, quick-create actions, and
 * links to the full agent management pages (Add, Build, Test).
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;
use NvoosContentGraphAiPlatform\Admin\PlatformSection;
use NvoosContentGraphAiPlatform\Agents\Agents;

/**
 * Agents dashboard section — overview of all agents with quick actions.
 */
final class AgentsDashboardSection extends PlatformSection {

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id(): string {
		return 'platform_agents_dashboard';
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Agents', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_tab(): string {
		return 'agents';
	}

	/**
	 * @since 1.0.0
	 * @return int
	 */
	public function get_priority(): int {
		return 1;
	}

	/**
	 * No form-table fields — renders a custom agent management panel.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string,mixed>>
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Render the agents overview panel.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_slug The settings page slug.
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		$agents = $this->getRecentAgents( 10 );

		$total_count = $this->countAgents();
		$settings    = $this->get_settings();
		$max_agents  = (int) ( $settings['max_agents'] ?? 50 );

		?>
		<h2><?php esc_html_e( 'Agent Management', 'nvoos-content-graph-ai-platform' ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $total_count ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Total Agents', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $max_agents > 0 ? '#2271b1' : '#646970'; ?>;">
					<?php echo $max_agents > 0 ? absint( $max_agents ) : '&infin;'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'Max Allowed', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button button-primary">
				<span class="dashicons dashicons-plus-alt" style="vertical-align:middle;"></span>
				<?php esc_html_e( 'Create Agent', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button">
				<?php esc_html_e( 'View All Agents', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph-ai-platform-add-agent' ) ); ?>" class="button">
				<?php esc_html_e( 'Add from Template', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph-ai-platform-build-agent' ) ); ?>" class="button">
				<?php esc_html_e( 'Build Agent', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
		</div>

		<?php if ( ! empty( $agents ) ) : ?>
			<h3><?php esc_html_e( 'Recent Agents', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="max-width:100%;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Agent', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Model', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Created', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agents as $agent ) : ?>
						<?php
						$provider = get_post_meta( $agent->ID, '_wp_mcp_ai_provider', true );
						$model    = get_post_meta( $agent->ID, '_wp_mcp_ai_model', true );
						?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( get_edit_post_link( $agent->ID ) ); ?>">
										<?php echo esc_html( $agent->post_title ); ?>
									</a>
								</strong>
							</td>
							<td><?php echo esc_html( $provider ?: '—' ); ?></td>
							<td><?php echo esc_html( $model ?: '—' ); ?></td>
							<td><?php echo esc_html( get_the_date( '', $agent->ID ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $agent->ID ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'nvoos-content-graph-ai-platform' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:2rem;text-align:center;">
				<h3><?php esc_html_e( 'No agents yet', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php esc_html_e( 'Create your first AI agent to get started. Agents are powered by the AI addon and can be configured with tools, skills, and memory.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Your First Agent', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Get the most recently created agents.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum number to return.
	 * @return \WP_Post[]
	 */
	private function getRecentAgents( int $limit = 10 ): array {
		if ( ! post_type_exists( Agents::POST_TYPE ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => Agents::POST_TYPE,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'publish',
			)
		);
	}

	/**
	 * Count all published agents.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function countAgents(): int {
		if ( ! post_type_exists( Agents::POST_TYPE ) ) {
			return 0;
		}

		$counts = wp_count_posts( Agents::POST_TYPE );
		if ( ! is_object( $counts ) ) {
			return 0;
		}

		return (int) ( $counts->publish ?? 0 );
	}
}
