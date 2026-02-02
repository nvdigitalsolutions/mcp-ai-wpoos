<?php
/**
 * Toolkit Enhancement Admin Dashboard Widget
 *
 * Displays toolkit enhancement statistics and quick links in the WordPress admin dashboard.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Toolkit Enhancement Dashboard Widget class
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Toolkit_Enhancement_Dashboard_Widget {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
	}

	/**
	 * Add dashboard widget
	 */
	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'wp_mcp_ai_toolkit_enhancement',
			__( 'AI Toolkit Enhancement', 'mcp-ai-wpoos' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content
	 */
	public function render_dashboard_widget() {
		// Get statistics.
		$stats = wp_mcp_ai_get_enhancement_stats();

		?>
		<div class="wp-mcp-ai-toolkit-dashboard">
			<style>
				.wp-mcp-ai-toolkit-dashboard { font-size: 13px; }
				.wp-mcp-ai-toolkit-stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 15px 0; }
				.wp-mcp-ai-toolkit-stat-box { background: #f0f0f1; padding: 12px; border-radius: 4px; text-align: center; }
				.wp-mcp-ai-toolkit-stat-box .stat-value { font-size: 28px; font-weight: 600; color: #2271b1; line-height: 1; }
				.wp-mcp-ai-toolkit-stat-box .stat-label { font-size: 12px; color: #646970; margin-top: 5px; }
				.wp-mcp-ai-toolkit-list { list-style: none; padding: 0; margin: 15px 0; }
				.wp-mcp-ai-toolkit-list li { padding: 8px 0; border-bottom: 1px solid #f0f0f1; display: flex; justify-content: space-between; align-items: center; }
				.wp-mcp-ai-toolkit-list li:last-child { border-bottom: none; }
				.wp-mcp-ai-toolkit-badge { background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
				.wp-mcp-ai-toolkit-actions { margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f1; }
				.wp-mcp-ai-toolkit-actions a { margin-right: 10px; }
			</style>

			<h3 style="margin-top: 0;"><?php esc_html_e( 'System Overview', 'mcp-ai-wpoos' ); ?></h3>

			<div class="wp-mcp-ai-toolkit-stat-grid">
				<div class="wp-mcp-ai-toolkit-stat-box">
					<div class="stat-value"><?php echo absint( $stats['toolkits']['total'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Toolkits', 'mcp-ai-wpoos' ); ?></div>
				</div>
				<div class="wp-mcp-ai-toolkit-stat-box">
					<div class="stat-value"><?php echo absint( $stats['patterns']['total'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Patterns', 'mcp-ai-wpoos' ); ?></div>
				</div>
				<div class="wp-mcp-ai-toolkit-stat-box">
					<div class="stat-value"><?php echo absint( $stats['toolkits']['tools_mapped'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Tools Mapped', 'mcp-ai-wpoos' ); ?></div>
				</div>
				<div class="wp-mcp-ai-toolkit-stat-box">
					<div class="stat-value"><?php echo absint( $stats['toolkits']['coverage_percent'] ); ?>%</div>
					<div class="stat-label"><?php esc_html_e( 'Coverage', 'mcp-ai-wpoos' ); ?></div>
				</div>
			</div>

			<h4><?php esc_html_e( 'Top Toolkits', 'mcp-ai-wpoos' ); ?></h4>
			<ul class="wp-mcp-ai-toolkit-list">
				<?php
				$toolkit_registry = wp_mcp_ai_get_toolkit_registry();
				$toolkits         = $toolkit_registry->get_all_toolkits();
				$toolkit_counts   = array();

				// Get tool counts for each toolkit.
				foreach ( $toolkits as $slug => $toolkit ) {
					$tools                   = $toolkit_registry->get_toolkit_tools( $slug );
					$toolkit_counts[ $slug ] = array(
						'name'  => $toolkit['name'],
						'count' => count( $tools ),
						'icon'  => isset( $toolkit['icon'] ) ? $toolkit['icon'] : '🔧',
					);
				}

				// Sort by count descending.
				uasort(
					$toolkit_counts,
					function ( $a, $b ) {
						return $b['count'] - $a['count'];
					}
				);

				// Show top 5.
				$top_toolkits = array_slice( $toolkit_counts, 0, 5, true );

				foreach ( $top_toolkits as $slug => $data ) {
					?>
					<li>
						<span>
							<span style="margin-right: 5px;"><?php echo esc_html( $data['icon'] ); ?></span>
							<?php echo esc_html( $data['name'] ); ?>
						</span>
						<span class="wp-mcp-ai-toolkit-badge"><?php echo absint( $data['count'] ); ?> <?php esc_html_e( 'tools', 'mcp-ai-wpoos' ); ?></span>
					</li>
					<?php
				}
				?>
			</ul>

			<div class="wp-mcp-ai-toolkit-actions">
				<?php
				// Check if we have an assistants page.
				$assistants_url = admin_url( 'edit.php?post_type=mcp_ai_assistant' );
				?>
				<a href="<?php echo esc_url( $assistants_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'View Assistants', 'mcp-ai-wpoos' ); ?>
				</a>
				<?php
				// Check if we have settings page.
				$settings_url = admin_url( 'admin.php?page=wp-mcp-ai-settings' );
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Settings', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<p style="margin: 15px 0 0; font-size: 11px; color: #646970;">
				<?php
				printf(
					/* translators: %s: toolkit count */
					esc_html__( 'Toolkit Enhancement System v1.2.0 • %s toolkits active', 'mcp-ai-wpoos' ),
					absint( $stats['toolkits']['total'] )
				);
				?>
			</p>
		</div>
		<?php
	}
}
