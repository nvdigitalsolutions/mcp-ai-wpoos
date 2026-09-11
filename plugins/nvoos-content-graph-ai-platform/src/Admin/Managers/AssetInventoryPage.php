<?php
/**
 * Asset inventory admin page (Wave E-UI-2, sub-cluster 7 — final).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Asset_Inventory_Admin`
 * (`includes/admin/class-wp-mcp-ai-asset-inventory-admin.php`):
 * byte-identical page surface — the `nvoos-asset-inventory` page
 * slug (admin_menu priority 99, fleet capability), the
 * `wpMcpAiAssetInventory` localized envelope (wp_rest nonce,
 * `mcp-ai/v1/assets` REST apiUrl, four-string i18n block), the
 * Discover Assets action, the stats grid (total + per-classification
 * cards, last-updated line), the classification/type filter selects,
 * the six-column assets table (badge classifications, per-row
 * data-classification/data-type attributes, row-action
 * descriptions), the empty state, and the ISO 27001 A.5.9 about
 * section. The discover action is REST-driven (no admin-post/AJAX
 * handlers).
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Managers\`. The
 *    class is named `AssetInventoryPage` to disambiguate from the
 *    future ported engine class.
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerManagers()`;
 *    the base admin owns the same page under the base Pro dashboard
 *    menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`); the
 *    byte-identical slug is kept.
 *  - Collaborators resolve per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the asset
 *    inventory engine via the base `WP_MCP_AI_Asset_Inventory`
 *    monolith / null standalone (the engine is not yet ported — the
 *    render degrades to the byte-identical empty state, documented
 *    forward-reference); the fleet capability via the base
 *    `wp_mcp_ai_fleet_capability()` helper monolith / an inline
 *    replication of the same default + `wp_mcp_ai_fleet_capability`
 *    filter standalone.
 *  - The enqueue gate keeps the base's three allowed hook suffixes
 *    and adds the platform menu's hook suffix (additive, documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The page's own assets (asset-inventory.css/js) are copied
 *    byte-identically into the platform asset tree; versioning
 *    resolves through the platform's per-file asset seam.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * Asset Inventory admin page controller.
 *
 * @since 2.0.0
 */
class AssetInventoryPage {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-asset-inventory';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Asset inventory engine class name (per-mode seam).
	 *
	 * Base-owned and not yet ported — standalone hides the inventory
	 * data behind the empty state (documented).
	 *
	 * @return string|null
	 */
	protected static function asset_inventory_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Asset_Inventory' ) ) {
			return 'WP_MCP_AI_Asset_Inventory';
		}

		return null;
	}

	/**
	 * Fleet capability slug (per-mode seam).
	 *
	 * @return string
	 */
	protected static function required_capability() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \function_exists( 'wp_mcp_ai_fleet_capability' ) ) {
			return \wp_mcp_ai_fleet_capability();
		}

		// Byte-identical replication of the base helper's default +
		// filter for standalone installs.
		$default_cap = \is_multisite() ? 'manage_network_options' : 'manage_options';

		/** This filter is documented in includes/bootstrap/helpers.php */
		$cap = \apply_filters( 'wp_mcp_ai_fleet_capability', $default_cap );

		return ( \is_string( $cap ) && '' !== $cap ) ? \sanitize_key( $cap ) : $default_cap;
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-file mtime).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_version( $relative_path ) {
		$absolute_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/' . \ltrim( $relative_path, '/' );

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Add admin menu item.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Asset Inventory', 'nvoos-content-graph-ai-platform' ),
			__( 'Asset Inventory', 'nvoos-content-graph-ai-platform' ),
			self::required_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		$allowed_hooks = array(
			'nvoos-pro-dashboard_page_nvoos-asset-inventory',
			'nvoos-pro_page_nvoos-asset-inventory',
			'nv-oos-pro_page_nvoos-asset-inventory',
			// The platform's "NV Platform" top-level menu — title-derived
			// (mirrors the base's title-derived variants).
			'nv-platform_page_nvoos-asset-inventory',
		);

		if ( ! \in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		\wp_enqueue_style(
			'wp-mcp-ai-asset-inventory',
			self::asset_url( 'css/asset-inventory.css' ),
			array(),
			self::asset_version( 'css/asset-inventory.css' )
		);

		\wp_enqueue_script(
			'wp-mcp-ai-asset-inventory',
			self::asset_url( 'js/asset-inventory.js' ),
			array( 'jquery', 'wp-api' ),
			self::asset_version( 'js/asset-inventory.js' ),
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-asset-inventory',
			'wpMcpAiAssetInventory',
			array(
				'nonce'   => \wp_create_nonce( 'wp_rest' ),
				'apiUrl'  => \rest_url( 'mcp-ai/v1/assets' ),
				'strings' => array(
					'discovering'      => __( 'Discovering assets...', 'nvoos-content-graph-ai-platform' ),
					'discoverButton'   => __( 'Discover Assets', 'nvoos-content-graph-ai-platform' ),
					'discoverySuccess' => __( 'Asset discovery completed successfully!', 'nvoos-content-graph-ai-platform' ),
					'discoveryError'   => __( 'Asset discovery failed. Please try again.', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);
	}

	/**
	 * Resolve the inventory payload + statistics per install mode.
	 *
	 * @return array{inventory: array|null, stats: array}
	 */
	protected function resolve_inventory(): array {
		$inventory_class = self::asset_inventory_class();

		if ( null === $inventory_class ) {
			return array(
				'inventory' => null,
				'stats'     => array(
					'total'             => 0,
					'by_classification' => array(),
					'generated_at'      => '',
				),
			);
		}

		return array(
			'inventory' => $inventory_class::get_instance()->get_asset_inventory(),
			'stats'     => $inventory_class::get_instance()->get_asset_statistics(),
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$resolved  = $this->resolve_inventory();
		$inventory = $resolved['inventory'];
		$stats     = $resolved['stats'];

		$inventory_class = self::asset_inventory_class();
		?>
		<div class="wrap wp-mcp-ai-asset-inventory">
			<h1>
				<?php echo \esc_html__( 'Asset Inventory', 'nvoos-content-graph-ai-platform' ); ?>
				<button type="button" class="button button-primary" id="wp-mcp-ai-discover-assets">
					<?php echo \esc_html__( 'Discover Assets', 'nvoos-content-graph-ai-platform' ); ?>
				</button>
			</h1>

			<div class="wp-mcp-ai-inventory-notice" style="display: none;"></div>

			<?php if ( $inventory ) : ?>
				<div class="wp-mcp-ai-inventory-stats">
					<h2><?php echo \esc_html__( 'Asset Statistics', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<div class="wp-mcp-ai-stats-grid">
						<div class="wp-mcp-ai-stat-card">
							<div class="wp-mcp-ai-stat-value"><?php echo \esc_html( $stats['total'] ); ?></div>
							<div class="wp-mcp-ai-stat-label"><?php echo \esc_html__( 'Total Assets', 'nvoos-content-graph-ai-platform' ); ?></div>
						</div>

						<?php foreach ( $stats['by_classification'] as $level => $count ) : ?>
							<div class="wp-mcp-ai-stat-card wp-mcp-ai-classification-<?php echo \esc_attr( $level ); ?>">
								<div class="wp-mcp-ai-stat-value"><?php echo \esc_html( $count ); ?></div>
								<div class="wp-mcp-ai-stat-label"><?php echo \esc_html( \ucfirst( $level ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>

					<p class="wp-mcp-ai-last-updated">
						<?php
						\printf(
							/* translators: %s: date and time */
							\esc_html__( 'Last updated: %s', 'nvoos-content-graph-ai-platform' ),
							\esc_html( $stats['generated_at'] )
						);
						?>
					</p>
				</div>

				<div class="wp-mcp-ai-inventory-filters">
					<h2><?php echo \esc_html__( 'Filter Assets', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<label>
						<?php echo \esc_html__( 'Classification:', 'nvoos-content-graph-ai-platform' ); ?>
						<select id="wp-mcp-ai-filter-classification">
							<option value=""><?php echo \esc_html_x( 'All', 'filter dropdown', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="public"><?php echo \esc_html__( 'Public', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="internal"><?php echo \esc_html__( 'Internal', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="confidential"><?php echo \esc_html__( 'Confidential', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="restricted"><?php echo \esc_html__( 'Restricted', 'nvoos-content-graph-ai-platform' ); ?></option>
						</select>
					</label>

					<label>
						<?php echo \esc_html__( 'Type:', 'nvoos-content-graph-ai-platform' ); ?>
						<select id="wp-mcp-ai-filter-type">
							<option value=""><?php echo \esc_html_x( 'All', 'filter dropdown', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="api_key"><?php echo \esc_html__( 'API Key/Credential', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="user_data"><?php echo \esc_html__( 'User Data', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="chat_transcript"><?php echo \esc_html__( 'Chat Transcript', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="code"><?php echo \esc_html__( 'Source Code', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="configuration"><?php echo \esc_html__( 'Configuration', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="database"><?php echo \esc_html__( 'Database', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="third_party"><?php echo \esc_html__( 'Third-Party Integration', 'nvoos-content-graph-ai-platform' ); ?></option>
							<option value="documentation"><?php echo \esc_html__( 'Documentation', 'nvoos-content-graph-ai-platform' ); ?></option>
						</select>
					</label>
				</div>

				<div class="wp-mcp-ai-inventory-table">
					<h2><?php echo \esc_html__( 'Asset List', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-assets-table">
						<thead>
							<tr>
								<th><?php echo \esc_html__( 'Asset Name', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php echo \esc_html__( 'Type', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php echo \esc_html__( 'Classification', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php echo \esc_html__( 'Owner', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php echo \esc_html__( 'Location', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php echo \esc_html__( 'Last Modified', 'nvoos-content-graph-ai-platform' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$asset_types = ( null !== $inventory_class && \defined( $inventory_class . '::ASSET_TYPES' ) ) ? $inventory_class::ASSET_TYPES : array();
							foreach ( $inventory['assets'] as $asset ) :
								?>
								<tr data-classification="<?php echo \esc_attr( $asset['classification'] ); ?>" data-type="<?php echo \esc_attr( $asset['type'] ); ?>">
									<td>
										<strong><?php echo \esc_html( $asset['name'] ); ?></strong>
										<div class="row-actions">
											<span><?php echo \esc_html( $asset['description'] ); ?></span>
										</div>
									</td>
									<td><?php echo \esc_html( $asset_types[ $asset['type'] ] ?? $asset['type'] ); ?></td>
									<td>
										<span class="wp-mcp-ai-badge wp-mcp-ai-badge-<?php echo \esc_attr( $asset['classification'] ); ?>">
											<?php echo \esc_html( \ucfirst( $asset['classification'] ) ); ?>
										</span>
									</td>
									<td><?php echo \esc_html( $asset['owner'] ); ?></td>
									<td><code><?php echo \esc_html( $asset['location'] ); ?></code></td>
									<td><?php echo \esc_html( $asset['last_modified'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p><?php echo \esc_html__( 'No asset inventory found. Click "Discover Assets" to generate the inventory.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wp-mcp-ai-inventory-info">
				<h2><?php echo \esc_html__( 'About Asset Inventory', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<p><?php echo \esc_html__( 'This asset inventory system implements ISO 27001:2022 Control A.5.9 - Inventory of Information and Other Associated Assets.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<p><?php echo \esc_html__( 'It automatically discovers and classifies all information assets within the plugin, including:', 'nvoos-content-graph-ai-platform' ); ?></p>
				<ul>
					<li><?php echo \esc_html__( 'Source code and documentation', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php echo \esc_html__( 'Configuration and API credentials', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php echo \esc_html__( 'User data and chat transcripts', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php echo \esc_html__( 'Third-party integrations and dependencies', 'nvoos-content-graph-ai-platform' ); ?></li>
				</ul>
				<p><?php echo \esc_html__( 'Assets are automatically classified according to their sensitivity level (Public, Internal, Confidential, Restricted) to ensure appropriate protection measures are applied.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>
		</div>
		<?php
	}
}
