<?php
/**
 * Admin page for Asset Inventory management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset Inventory Admin Page class.
 *
 * Provides admin UI for viewing and managing ISO 27001 asset inventory.
 */
class WP_MCP_AI_Asset_Inventory_Admin {
	/**
	 * Initialize admin page.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Asset Inventory', 'mcp-ai-wpoos' ),
			__( 'Asset Inventory', 'mcp-ai-wpoos' ),
			'manage_options',
			'nvoos-asset-inventory',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'nvoos-pro-dashboard_page_nvoos-asset-inventory' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-asset-inventory',
			WP_MCP_AI_URL . 'assets/css/asset-inventory.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-asset-inventory',
			WP_MCP_AI_URL . 'assets/js/asset-inventory.js',
			array( 'jquery', 'wp-api' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-asset-inventory',
			'wpMcpAiAssetInventory',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'apiUrl'  => rest_url( 'mcp-ai/v1/assets' ),
				'strings' => array(
					'discovering'      => __( 'Discovering assets...', 'mcp-ai-wpoos' ),
					'discoverySuccess' => __( 'Asset discovery completed successfully!', 'mcp-ai-wpoos' ),
					'discoveryError'   => __( 'Asset discovery failed. Please try again.', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page() {
		$inventory = WP_MCP_AI_Asset_Inventory::get_instance()->get_asset_inventory();
		$stats     = WP_MCP_AI_Asset_Inventory::get_instance()->get_asset_statistics();

		?>
		<div class="wrap wp-mcp-ai-asset-inventory">
			<h1>
				<?php echo esc_html__( 'Asset Inventory', 'mcp-ai-wpoos' ); ?>
				<button type="button" class="button button-primary" id="wp-mcp-ai-discover-assets">
					<?php echo esc_html__( 'Discover Assets', 'mcp-ai-wpoos' ); ?>
				</button>
			</h1>

			<div class="wp-mcp-ai-inventory-notice" style="display: none;"></div>

			<?php if ( $inventory ) : ?>
				<div class="wp-mcp-ai-inventory-stats">
					<h2><?php echo esc_html__( 'Asset Statistics', 'mcp-ai-wpoos' ); ?></h2>
					<div class="wp-mcp-ai-stats-grid">
						<div class="wp-mcp-ai-stat-card">
							<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $stats['total'] ); ?></div>
							<div class="wp-mcp-ai-stat-label"><?php echo esc_html__( 'Total Assets', 'mcp-ai-wpoos' ); ?></div>
						</div>

						<?php foreach ( $stats['by_classification'] as $level => $count ) : ?>
							<div class="wp-mcp-ai-stat-card wp-mcp-ai-classification-<?php echo esc_attr( $level ); ?>">
								<div class="wp-mcp-ai-stat-value"><?php echo esc_html( $count ); ?></div>
								<div class="wp-mcp-ai-stat-label"><?php echo esc_html( ucfirst( $level ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>

					<p class="wp-mcp-ai-last-updated">
						<?php
						/* translators: %s: date and time */
						printf(
							esc_html__( 'Last updated: %s', 'mcp-ai-wpoos' ),
							esc_html( $stats['generated_at'] )
						);
						?>
					</p>
				</div>

				<div class="wp-mcp-ai-inventory-filters">
					<h2><?php echo esc_html__( 'Filter Assets', 'mcp-ai-wpoos' ); ?></h2>
					<label>
						<?php echo esc_html__( 'Classification:', 'mcp-ai-wpoos' ); ?>
						<select id="wp-mcp-ai-filter-classification">
							<option value=""><?php echo esc_html__( 'All', 'mcp-ai-wpoos' ); ?></option>
							<option value="public"><?php echo esc_html__( 'Public', 'mcp-ai-wpoos' ); ?></option>
							<option value="internal"><?php echo esc_html__( 'Internal', 'mcp-ai-wpoos' ); ?></option>
							<option value="confidential"><?php echo esc_html__( 'Confidential', 'mcp-ai-wpoos' ); ?></option>
							<option value="restricted"><?php echo esc_html__( 'Restricted', 'mcp-ai-wpoos' ); ?></option>
						</select>
					</label>

					<label>
						<?php echo esc_html__( 'Type:', 'mcp-ai-wpoos' ); ?>
						<select id="wp-mcp-ai-filter-type">
							<option value=""><?php echo esc_html__( 'All', 'mcp-ai-wpoos' ); ?></option>
							<option value="api_key"><?php echo esc_html__( 'API Key/Credential', 'mcp-ai-wpoos' ); ?></option>
							<option value="user_data"><?php echo esc_html__( 'User Data', 'mcp-ai-wpoos' ); ?></option>
							<option value="chat_transcript"><?php echo esc_html__( 'Chat Transcript', 'mcp-ai-wpoos' ); ?></option>
							<option value="code"><?php echo esc_html__( 'Source Code', 'mcp-ai-wpoos' ); ?></option>
							<option value="configuration"><?php echo esc_html__( 'Configuration', 'mcp-ai-wpoos' ); ?></option>
							<option value="database"><?php echo esc_html__( 'Database', 'mcp-ai-wpoos' ); ?></option>
							<option value="third_party"><?php echo esc_html__( 'Third-Party Integration', 'mcp-ai-wpoos' ); ?></option>
							<option value="documentation"><?php echo esc_html__( 'Documentation', 'mcp-ai-wpoos' ); ?></option>
						</select>
					</label>
				</div>

				<div class="wp-mcp-ai-inventory-table">
					<h2><?php echo esc_html__( 'Asset List', 'mcp-ai-wpoos' ); ?></h2>
					<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-assets-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Asset Name', 'mcp-ai-wpoos' ); ?></th>
								<th><?php echo esc_html__( 'Type', 'mcp-ai-wpoos' ); ?></th>
								<th><?php echo esc_html__( 'Classification', 'mcp-ai-wpoos' ); ?></th>
								<th><?php echo esc_html__( 'Owner', 'mcp-ai-wpoos' ); ?></th>
								<th><?php echo esc_html__( 'Location', 'mcp-ai-wpoos' ); ?></th>
								<th><?php echo esc_html__( 'Last Modified', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $inventory['assets'] as $asset ) : ?>
								<tr data-classification="<?php echo esc_attr( $asset['classification'] ); ?>" data-type="<?php echo esc_attr( $asset['type'] ); ?>">
									<td>
										<strong><?php echo esc_html( $asset['name'] ); ?></strong>
										<div class="row-actions">
											<span><?php echo esc_html( $asset['description'] ); ?></span>
										</div>
									</td>
									<td><?php echo esc_html( WP_MCP_AI_Asset_Inventory::ASSET_TYPES[ $asset['type'] ] ?? $asset['type'] ); ?></td>
									<td>
										<span class="wp-mcp-ai-badge wp-mcp-ai-badge-<?php echo esc_attr( $asset['classification'] ); ?>">
											<?php echo esc_html( ucfirst( $asset['classification'] ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $asset['owner'] ); ?></td>
									<td><code><?php echo esc_html( $asset['location'] ); ?></code></td>
									<td><?php echo esc_html( $asset['last_modified'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p><?php echo esc_html__( 'No asset inventory found. Click "Discover Assets" to generate the inventory.', 'mcp-ai-wpoos' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wp-mcp-ai-inventory-info">
				<h2><?php echo esc_html__( 'About Asset Inventory', 'mcp-ai-wpoos' ); ?></h2>
				<p><?php echo esc_html__( 'This asset inventory system implements ISO 27001:2022 Control A.5.9 - Inventory of Information and Other Associated Assets.', 'mcp-ai-wpoos' ); ?></p>
				<p><?php echo esc_html__( 'It automatically discovers and classifies all information assets within the plugin, including:', 'mcp-ai-wpoos' ); ?></p>
				<ul>
					<li><?php echo esc_html__( 'Source code and documentation', 'mcp-ai-wpoos' ); ?></li>
					<li><?php echo esc_html__( 'Configuration and API credentials', 'mcp-ai-wpoos' ); ?></li>
					<li><?php echo esc_html__( 'User data and chat transcripts', 'mcp-ai-wpoos' ); ?></li>
					<li><?php echo esc_html__( 'Third-party integrations and dependencies', 'mcp-ai-wpoos' ); ?></li>
				</ul>
				<p><?php echo esc_html__( 'Assets are automatically classified according to their sensitivity level (Public, Internal, Confidential, Restricted) to ensure appropriate protection measures are applied.', 'mcp-ai-wpoos' ); ?></p>
			</div>
		</div>
		<?php
	}
}

// Initialize admin page.
// NOTE: This is now handled by WP_MCP_AI_Pro_Dashboard to ensure
// proper coordination of ISO 27001 admin pages.
// if ( is_admin() ) {
// new WP_MCP_AI_Asset_Inventory_Admin();
// }.
