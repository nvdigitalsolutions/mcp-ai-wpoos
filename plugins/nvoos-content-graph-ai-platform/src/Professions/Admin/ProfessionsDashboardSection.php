<?php
/**
 * Professions Dashboard Section — powers the "Professions" tab.
 *
 * Lists profession templates (from the base plugin's `mcp_ai_profession` CPT),
 * shows category breakdown, and links to the Add Agent workflow.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Professions\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions\Admin;

use NvoosContentGraphAiPlatform\Admin\PlatformSection;

/**
 * Professions dashboard section.
 */
final class ProfessionsDashboardSection extends PlatformSection {

	public function get_id(): string {
		return 'platform_professions_dashboard';
	}

	public function get_title(): string {
		return __( 'Profession Templates', 'nvoos-content-graph-ai-platform' );
	}

	public function get_tab(): string {
		return 'professions';
	}

	public function get_priority(): int {
		return 1;
	}

	public function get_fields(): array {
		return array();
	}

	public function render_wrapper( string $page_slug = '' ): void {
		$professions      = $this->getRecentProfessions( 20 );
		$total_count      = $this->countProfessions();
		$categories       = $this->getCategoryCounts();
		$post_type_exists = post_type_exists( 'mcp_ai_profession' );

		?>
		<h2><?php esc_html_e( 'Profession Templates', 'nvoos-content-graph-ai-platform' ); ?></h2>

		<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $total_count ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Total Templates', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( count( $categories ) ); ?></div>
				<div style="color:#646970;"><?php esc_html_e( 'Categories', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem;min-width:140px;text-align:center;">
				<div style="font-size:2rem;font-weight:700;color:<?php echo $post_type_exists ? '#00a32a' : '#d63638'; ?>;">
					<?php echo $post_type_exists ? '✓' : '✗'; ?>
				</div>
				<div style="color:#646970;"><?php esc_html_e( 'CPT Registered', 'nvoos-content-graph-ai-platform' ); ?></div>
			</div>
		</div>

		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.5rem;">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Manage Professions', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ); ?>" class="button">
				<?php esc_html_e( 'Add Profession', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph-ai-platform-add-agent' ) ); ?>" class="button">
				<?php esc_html_e( 'Create Agent from Template', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
		</div>

		<h3><?php esc_html_e( 'What are Professions?', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="background:#fff;border:1px solid #c3c4c7;padding:1rem 1.5rem;margin-bottom:1.5rem;max-width:800px;">
			<p><?php esc_html_e( 'Professions are reusable agent templates. Each profession defines a role description, default AI provider/model, tool assignments, skill sets, and knowledge base. When you create a new agent, you can start from a profession template to jump-start configuration.', 'nvoos-content-graph-ai-platform' ); ?></p>
			<p><?php esc_html_e( 'Professions are stored as posts in the base plugin and are shared across agents. Edit a profession to update all agents created from it.', 'nvoos-content-graph-ai-platform' ); ?></p>
		</div>

		<?php if ( ! empty( $categories ) ) : ?>
			<h3><?php esc_html_e( 'Categories', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
				<?php foreach ( $categories as $slug => $count ) : ?>
					<span style="background:#f0f0f1;padding:0.25rem 0.75rem;border-radius:3px;font-size:0.9rem;">
						<?php echo esc_html( ucfirst( str_replace( '_', ' ', $slug ) ) ); ?>
						<strong>(<?php echo absint( $count ); ?>)</strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $professions ) ) : ?>
			<h3><?php esc_html_e( 'Recent Templates', 'nvoos-content-graph-ai-platform' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Profession', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Category', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Created', 'nvoos-content-graph-ai-platform' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $professions as $profession ) : ?>
						<?php $category = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_category', true ); ?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( get_edit_post_link( $profession->ID ) ); ?>">
										<?php echo esc_html( $profession->post_title ); ?>
									</a>
								</strong>
							</td>
							<td><?php echo esc_html( $category ? ucfirst( str_replace( '_', ' ', $category ) ) : '—' ); ?></td>
							<td><?php echo esc_html( get_the_date( '', $profession->ID ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $profession->ID ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'nvoos-content-graph-ai-platform' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php elseif ( $post_type_exists ) : ?>
			<div style="background:#fff;border:1px solid #c3c4c7;padding:2rem;text-align:center;max-width:800px;">
				<h3><?php esc_html_e( 'No profession templates yet', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p><?php esc_html_e( 'Create profession templates to jump-start agent creation with pre-configured roles, tools, and knowledge bases.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create First Profession', 'nvoos-content-graph-ai-platform' ); ?>
				</a>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Get recent profession posts.
	 */
	private function getRecentProfessions( int $limit = 20 ): array {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'publish',
			)
		);
	}

	/**
	 * Count all published professions.
	 */
	private function countProfessions(): int {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'mcp_ai_profession' );
		return is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
	}

	/**
	 * Get profession category counts.
	 */
	private function getCategoryCounts(): array {
		if ( ! post_type_exists( 'mcp_ai_profession' ) ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, COUNT(*) as cnt FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND post_id IN (
					SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
				)
				GROUP BY meta_value
				ORDER BY cnt DESC",
				'_wp_mcp_ai_profession_category',
				'mcp_ai_profession'
			)
		);

		$counts = array();
		foreach ( (array) $results as $row ) {
			if ( ! empty( $row->meta_value ) ) {
				$counts[ $row->meta_value ] = (int) $row->cnt;
			}
		}
		return $counts;
	}
}
