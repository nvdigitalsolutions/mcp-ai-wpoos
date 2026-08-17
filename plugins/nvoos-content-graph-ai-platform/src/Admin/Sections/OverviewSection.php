<?php
/**
 * Overview section — landing tab for the NV Platform dashboard.
 *
 * Displays system status cards: Content Graph version, AI addon status,
 * agent/project/resource counts, dependency health, and quick links.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Sections
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Sections;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;
use NvoosContentGraphAiPlatform\Admin\PlatformSection;
use NvoosContentGraphAiPlatform\Agents\Agents;

/**
 * Overview dashboard section.
 */
final class OverviewSection extends PlatformSection {

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id(): string {
		return 'platform_overview';
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Platform Overview', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * @since 1.0.0
	 * @return string
	 */
	public function get_tab(): string {
		return 'overview';
	}

	/**
	 * Overview has no standard fields — it renders status cards.
	 *
	 * @since 1.0.0
	 * @return array<string, array<string,mixed>>
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * @since 1.0.0
	 * @return int
	 */
	public function get_priority(): int {
		return 1; // First in the overview tab.
	}

	/**
	 * Render custom status-card UI instead of form-table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_slug The settings page slug.
	 * @return void
	 */
	public function render_wrapper( string $page_slug = '' ): void {
		$agent_count    = $this->countPosts( Agents::POST_TYPE );
		$project_count  = $this->countPosts( 'ai_platform_project' );
		$resource_count = $this->countPosts( 'ai_platform_resource' );
		$template_count = $this->countPosts( 'ai_platform_template' );

		$content_graph_version = defined( 'NVOOS_CONTENT_GRAPH_VERSION' ) ? NVOOS_CONTENT_GRAPH_VERSION : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
		$ai_version       = defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
		$platform_version = NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;

		$graph_enabled = function_exists( 'nvoos_content_graph_is_enabled' ) && nvoos_content_graph_is_enabled();
		$ai_active     = class_exists( 'NvoosContentGraphAi\\Plugin' );

		?>
		<h2><?php esc_html_e( 'Platform Overview', 'nvoos-content-graph-ai-platform' ); ?></h2>

		<div class="ai-platform-status-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem;">

			<?php
			$this->renderStatusCard(
				__( 'Knowledge Graph', 'nvoos-content-graph-ai-platform' ),
				$graph_enabled ? __( 'Active', 'nvoos-content-graph-ai-platform' ) : __( 'Inactive', 'nvoos-content-graph-ai-platform' ),
				$graph_enabled ? 'green' : 'red',
				'dashicons-networking',
				sprintf(
					/* translators: %s: version number */
					__( 'Version %s', 'nvoos-content-graph-ai-platform' ),
					$content_graph_version
				)
			);
			?>

			<?php
			$this->renderStatusCard(
				__( 'AI Addon', 'nvoos-content-graph-ai-platform' ),
				$ai_active ? __( 'Active', 'nvoos-content-graph-ai-platform' ) : __( 'Missing', 'nvoos-content-graph-ai-platform' ),
				$ai_active ? 'green' : 'red',
				'dashicons-superhero',
				sprintf(
					/* translators: %s: version number */
					__( 'Version %s', 'nvoos-content-graph-ai-platform' ),
					$ai_version
				)
			);
			?>

			<?php
			$this->renderStatusCard(
				__( 'Platform Version', 'nvoos-content-graph-ai-platform' ),
				$platform_version,
				'blue',
				'dashicons-layout',
				__( 'NV Platform addon', 'nvoos-content-graph-ai-platform' )
			);
			?>
		</div>

		<h3><?php esc_html_e( 'Inventory', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div class="ai-platform-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
			<?php
			$this->renderStatCard(
				$agent_count,
				__( 'Agents', 'nvoos-content-graph-ai-platform' ),
				admin_url( 'edit.php?post_type=' . Agents::POST_TYPE )
			);
			$this->renderStatCard(
				$project_count,
				__( 'Projects', 'nvoos-content-graph-ai-platform' ),
				admin_url( 'edit.php?post_type=ai_platform_project' )
			);
			$this->renderStatCard(
				$resource_count,
				__( 'Resources', 'nvoos-content-graph-ai-platform' ),
				admin_url( 'edit.php?post_type=ai_platform_resource' )
			);
			$this->renderStatCard(
				$template_count,
				__( 'Templates', 'nvoos-content-graph-ai-platform' ),
				admin_url( 'edit.php?post_type=ai_platform_template' )
			);
			?>
		</div>

		<h3><?php esc_html_e( 'Quick Links', 'nvoos-content-graph-ai-platform' ); ?></h3>
		<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PlatformDashboard::PAGE_SLUG . '&tab=agents' ) ); ?>" class="button">
				<?php esc_html_e( 'Manage Agents', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button">
				<?php esc_html_e( 'New Agent', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-content-graph' ) ); ?>" class="button">
				<?php esc_html_e( 'Knowledge Graph', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ai_platform_project' ) ); ?>" class="button">
				<?php esc_html_e( 'New Project', 'nvoos-content-graph-ai-platform' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render a single status indicator card.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label       Card title.
	 * @param string $status      Status text.
	 * @param string $color       green | red | blue.
	 * @param string $dashicon    Dashicon class slug.
	 * @param string $description Subtitle or detail text.
	 * @return void
	 */
	private function renderStatusCard( string $label, string $status, string $color, string $dashicon, string $description ): void {
		$color_map = array(
			'green' => '#00a32a',
			'red'   => '#d63638',
			'blue'  => '#2271b1',
		);

		$hex = $color_map[ $color ] ?? '#2271b1';
		?>
		<div class="ai-platform-status-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr( $hex ); ?>;padding:1rem;">
			<div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
				<span class="dashicons <?php echo esc_attr( $dashicon ); ?>" style="color:<?php echo esc_attr( $hex ); ?>;"></span>
				<strong><?php echo esc_html( $label ); ?></strong>
			</div>
			<div style="font-size:1.2rem;font-weight:600;color:<?php echo esc_attr( $hex ); ?>;margin-bottom:0.25rem;">
				<?php echo esc_html( $status ); ?>
			</div>
			<div style="color:#646970;font-size:0.9rem;">
				<?php echo esc_html( $description ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single stat counter card.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $count  The count value.
	 * @param string $label  Singular label.
	 * @param string $url    Optional admin URL for the "View all" link.
	 * @return void
	 */
	private function renderStatCard( int $count, string $label, string $url ): void {
		?>
		<div class="ai-platform-stat-card" style="background:#fff;border:1px solid #c3c4c7;padding:1rem;text-align:center;">
			<div style="font-size:2rem;font-weight:700;color:#2271b1;"><?php echo absint( $count ); ?></div>
			<div style="color:#646970;margin-bottom:0.5rem;"><?php echo esc_html( $label ); ?></div>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-small"><?php esc_html_e( 'View all', 'nvoos-content-graph-ai-platform' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Safely count published posts of a given type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	private function countPosts( string $post_type ): int {
		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}

		$counts = wp_count_posts( $post_type );
		if ( ! is_object( $counts ) ) {
			return 0;
		}

		return (int) ( $counts->publish ?? 0 );
	}
}
