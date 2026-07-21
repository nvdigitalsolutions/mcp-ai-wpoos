<?php
/**
 * Shopify Sync Toolkit Settings Page
 *
 * Admin settings page for the Shopify Sync Pro Toolkit.
 * Provides connection configuration, sync management, CCT status,
 * webhook management, and GraphQL cost monitoring.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';

/**
 * Shopify Sync Toolkit Settings Page.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->toolkit_slug     = 'shopify-sync';
		$this->toolkit_name     = __( 'Shopify Sync', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_shopify_sync_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-shopify-sync-toolkit-settings';
		$this->parent_slug      = 'wp-mcp-ai-shopify-sync-toolkit';
		$this->has_remote_sites = false;
		$this->has_research     = false;
		$this->icon             = 'dashicons-update';

		add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 26 );

		// Handle Sync Now and Dry Run admin-post actions.
		add_action( 'admin_post_wp_mcp_ai_shopify_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_wp_mcp_ai_shopify_sync_dry_run', array( $this, 'handle_sync_dry_run' ) );

		parent::__construct();
	}

	/**
	 * Add top-level Shopify Sync admin menu.
	 */
	public function add_top_level_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			return;
		}

		add_menu_page(
			__( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
			__( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown
			$this->parent_slug,
			array( $this, 'redirect_to_first_submenu' ),
			$this->icon,
			58
		);
	}

	/**
	 * Redirect parent menu to first submenu page.
	 */
	public function redirect_to_first_submenu() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug ) );
		exit;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function render_overview_tab() {
		$settings         = get_option( $this->option_name, array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();
		$wc_active        = class_exists( 'WooCommerce' );
		$client_active    = class_exists( 'WP_MCP_AI_Shopify_Client' );
		?>
		<div class="toolkit-overview">
			<?php
			// Sync / dry-run result notices (set via redirect from admin-post handlers).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['sync_ok'] ) ) :
				$s_insert = isset( $_GET['sync_insert'] ) ? absint( $_GET['sync_insert'] ) : 0;
				$s_update = isset( $_GET['sync_update'] ) ? absint( $_GET['sync_update'] ) : 0;
				$s_skip   = isset( $_GET['sync_skip'] ) ? absint( $_GET['sync_skip'] ) : 0;
				$s_err    = isset( $_GET['sync_errors'] ) ? absint( $_GET['sync_errors'] ) : 0;
				$s_dur    = isset( $_GET['sync_dur'] ) ? floatval( $_GET['sync_dur'] ) : 0;
				$s_warn   = isset( $_GET['sync_warn'] ) ? sanitize_text_field( rawurldecode( sanitize_text_field( wp_unslash( $_GET['sync_warn'] ) ) ) ) : '';
				?>
				<div class="notice <?php echo '' !== $s_warn ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p><strong><?php esc_html_e( 'Sync Complete', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<?php
					/* translators: 1: inserted count, 2: updated count, 3: skipped count, 4: error count, 5: duration in seconds */
					printf(
						esc_html__( '%1$d items inserted, %2$d updated, %3$d skipped, %4$d errors (in %5$ss).', 'mcp-ai-wpoos-pro' ),
						absint( $s_insert ),
						absint( $s_update ),
						absint( $s_skip ),
						absint( $s_err ),
						esc_html( (string) $s_dur )
					);
					if ( '' !== $s_warn ) {
						echo '<p><strong>' . esc_html__( 'Warning:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $s_warn ) . '</p>';
					}
					?>
				</div>
				<?php
			elseif ( isset( $_GET['sync_error'] ) ) :
				$sync_msg = isset( $_GET['sync_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_msg'] ) ) : __( 'Unknown error.', 'mcp-ai-wpoos-pro' );
				?>
				<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Sync failed:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $sync_msg ); ?></p></div>
				<?php
			elseif ( isset( $_GET['dry_run_ok'] ) ) :
				$dr_insert = isset( $_GET['dry_run_insert'] ) ? absint( $_GET['dry_run_insert'] ) : 0;
				$dr_update = isset( $_GET['dry_run_update'] ) ? absint( $_GET['dry_run_update'] ) : 0;
				$dr_skip   = isset( $_GET['dry_run_skip'] ) ? absint( $_GET['dry_run_skip'] ) : 0;
				$dr_dur    = isset( $_GET['dry_run_dur'] ) ? floatval( $_GET['dry_run_dur'] ) : 0;
				$dr_warn   = isset( $_GET['dry_run_warn'] ) ? sanitize_text_field( rawurldecode( sanitize_text_field( wp_unslash( $_GET['dry_run_warn'] ) ) ) ) : '';
				?>
				<div class="notice <?php echo '' !== $dr_warn ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p><strong><?php esc_html_e( 'Dry Run Complete', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<?php
					/* translators: 1: would-insert count, 2: would-update count, 3: would-skip count, 4: duration in seconds */
					printf(
						esc_html__( '%1$d items would be inserted, %2$d updated, %3$d skipped (in %4$ss). No data was modified.', 'mcp-ai-wpoos-pro' ),
						absint( $dr_insert ),
						absint( $dr_update ),
						absint( $dr_skip ),
						esc_html( (string) $dr_dur )
					);
					if ( '' !== $dr_warn ) {
						echo '<p><strong>' . esc_html__( 'Warning:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $dr_warn ) . '</p>';
					}
					?>
				</div>
				<?php
			elseif ( isset( $_GET['dry_run_error'] ) ) :
				$dr_msg = isset( $_GET['dry_run_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['dry_run_msg'] ) ) : __( 'Unknown error.', 'mcp-ai-wpoos-pro' );
				?>
				<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Dry Run Failed:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $dr_msg ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>

			<h2><?php esc_html_e( 'Shopify Sync Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="toolkit-description">
				<p><?php esc_html_e( 'Synchronize Shopify inventory, products, and orders with WooCommerce using a CCT-based cache layer. AI assistants query locally cached data with zero GraphQL API cost. Background sync via Action Scheduler + Shopify webhooks keeps data fresh.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'System Status', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 700px;">
				<tbody>
					<tr>
						<th style="width: 250px;"><?php esc_html_e( 'WooCommerce', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $wc_active ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10008; <?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Shopify API Client', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $client_active ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10008; <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'JetEngine', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( function_exists( 'jet_engine' ) ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: orange;">&#9888; <?php esc_html_e( 'Not Installed — CCT cache requires JetEngine for storage.', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Action Scheduler', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( function_exists( 'as_has_scheduled_action' ) ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10008; <?php esc_html_e( 'Not Available', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $sync_connections ) ) : ?>
			<h3><?php esc_html_e( 'Sync Connections', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 900px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Connection', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Last Sync', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'CCT Rows', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Freshness', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Cost Today', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Webhooks', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $sync_connections as $conn_id ) :
						$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $conn_id );
						$engine      = new WP_MCP_AI_Shopify_Sync_Engine( $conn_id );
						$last_sync   = $cct_manager->get_last_sync_time();
						$row_count   = $cct_manager->get_row_count();
						$is_fresh    = $cct_manager->is_fresh();
						$cost_report = $engine->get_cost_report();
						$webhook_ok  = get_option( 'wp_mcp_ai_shopify_webhook_registered_' . $conn_id, false );

						// Get connection name from Remote Sites.
						$conn_name = $conn_id;
						if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
							$conn_data = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $conn_id );
							if ( $conn_data && ! empty( $conn_data['name'] ) ) {
								$conn_name = $conn_data['name'];
							}
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $conn_name ); ?></strong><br><small><?php echo esc_html( $conn_id ); ?></small></td>
							<td><?php echo esc_html( ! empty( $last_sync ) ? $last_sync : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?></td>
							<td><?php echo esc_html( $row_count ); ?></td>
							<td>
								<?php if ( $is_fresh ) : ?>
									<span style="color: green;">&#10004; <?php esc_html_e( 'Fresh', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span style="color: orange;">&#9888; <?php esc_html_e( 'Stale', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$pct   = $cost_report['pct_remaining'];
								$color = $pct < 20 ? 'red' : ( $pct < 50 ? 'orange' : 'green' );
								printf(
									'<span style="color: %1$s;">%2$d / %3$d pts (%4$s%%)</span>',
									esc_attr( $color ),
									esc_html( $cost_report['used'] ),
									esc_html( $cost_report['limit'] ),
									esc_html( (string) $pct )
								);
								?>
							</td>
							<td>
								<?php if ( $webhook_ok ) : ?>
									<span style="color: green;">&#10004; <?php esc_html_e( 'Registered', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php else : ?>
									<span style="color: #999;">&#8212; <?php esc_html_e( 'Not Registered', 'mcp-ai-wpoos-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="white-space: nowrap;">
								<button type="button" class="button button-small wp-mcp-ai-sync-now" data-connection="<?php echo esc_attr( $conn_id ); ?>">
									<?php esc_html_e( 'Sync Now', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<button type="button" class="button button-small wp-mcp-ai-sync-dry-run" data-connection="<?php echo esc_attr( $conn_id ); ?>" style="margin-left: 4px;">
									<?php esc_html_e( 'Dry Run', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No Shopify connections are configured for sync. Go to the Configuration tab to select which connections to synchronize.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'shopify_sync_inventory', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Search and filter inventory items from CCT cache (zero API cost)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'shopify_sync_products', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse product catalog by type, vendor, status', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'shopify_sync_orders', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'List recent orders and get order analytics', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'shopify_sync_settings', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Manage sync configuration and view cost reports', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'shopify_sync_analytics', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Inventory summaries, stock velocity, vendor breakdowns', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<p class="description"><?php esc_html_e( 'Note: These tools complement the existing Shopify live-API tools (shopify_products, shopify_inventory, etc.). Use sync tools for bulk queries and analytics at zero cost; use live-API tools for real-time operations and mutations.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<script>
		( function() {
			var syncNowUrl = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_shopify_sync_now&_wpnonce=' . wp_create_nonce( 'wp_mcp_ai_shopify_sync' ) ) ); ?> + '&connection_id=';
			var dryRunUrl  = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_shopify_sync_dry_run&_wpnonce=' . wp_create_nonce( 'wp_mcp_ai_shopify_sync' ) ) ); ?> + '&connection_id=';

			document.querySelectorAll( '.wp-mcp-ai-sync-now' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var connId = this.getAttribute( 'data-connection' );
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Syncing...', 'mcp-ai-wpoos-pro' ) ); ?>';
					location.href = syncNowUrl + encodeURIComponent( connId );
				});
			});

			document.querySelectorAll( '.wp-mcp-ai-sync-dry-run' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var connId = this.getAttribute( 'data-connection' );
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Running...', 'mcp-ai-wpoos-pro' ) ); ?>';
					location.href = dryRunUrl + encodeURIComponent( connId );
				});
			});
		} )();
		</script>
		<?php
	}

	/**
	 * {@inheritdoc}
	 */
	protected function render_configuration_tab() {
		$settings         = get_option( $this->option_name, array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		// Gather all available Shopify connections from Remote Sites.
		$available_connections = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $conn_id => $conn ) {
				$conn_type = isset( $conn['connection_type'] ) ? sanitize_key( $conn['connection_type'] ) : '';
				if ( 'shopify' === $conn_type || 'shopify_catalog' === $conn_type ) {
					$available_connections[ $conn_id ] = $conn;
				}
			}
		}
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Shopify Sync Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php if ( empty( $available_connections ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No Shopify connections found. Please configure a Shopify connection in NV oOS → Remote Sites first.', 'mcp-ai-wpoos-pro' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>" class="button"><?php esc_html_e( 'Go to Remote Sites', 'mcp-ai-wpoos-pro' ); ?></a></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Connections to Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select which Shopify connections to synchronize. Each connection will have its own CCT cache and sync schedule.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php if ( ! empty( $available_connections ) ) : ?>
			<table class="form-table">
				<?php foreach ( $available_connections as $conn ) : ?>
				<tr>
					<th scope="row">
						<label for="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>">
							<?php echo esc_html( isset( $conn['name'] ) ? $conn['name'] : $conn['id'] ); ?>
							<?php if ( empty( $conn['enabled'] ) ) : ?>
							<span style="color: #999;">— <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</label>
					</th>
					<td>
						<input type="checkbox" name="shopify_sync_connections[]"
							id="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>"
							value="<?php echo esc_attr( $conn['id'] ); ?>"
							<?php checked( in_array( $conn['id'], $sync_connections, true ) ); ?> />
						<label for="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>">
							<?php esc_html_e( 'Enable sync for this connection', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: store URL */
								esc_html__( 'Store: %s', 'mcp-ai-wpoos-pro' ),
								esc_html( isset( $conn['url'] ) ? $conn['url'] : '' )
							);
							?>
						</p>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No Shopify connections are available for sync. Configure a Shopify connection in NV oOS → Remote Sites, then return here to enable sync.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Sync Settings', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="shopify_sync_interval"><?php esc_html_e( 'Sync Interval', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="shopify_sync_interval" id="shopify_sync_interval">
							<?php
							$current_interval = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 15;
							$intervals        = array( 5, 15, 30, 60 );
							foreach ( $intervals as $min ) {
								printf(
									'<option value="%d" %s>%s</option>',
									esc_attr( (string) $min ),
									selected( $current_interval, $min, false ),
									esc_html(
										sprintf(
											/* translators: %d: minutes */
											__( 'Every %d minute(s)', 'mcp-ai-wpoos-pro' ),
											$min
										)
									)
								);
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'How often to run the full sync from Shopify (uses Bulk Operations at 10 GraphQL pts each). Webhooks provide real-time updates between syncs at zero cost.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="shopify_sync_mode"><?php esc_html_e( 'Sync Mode', 'mcp-ai-wpoos-pro' ); ?></label>
							</th>
							<td>
								<select name="shopify_sync_mode" id="shopify_sync_mode">
									<?php
									$current_mode = isset( $settings['sync_mode'] ) ? $settings['sync_mode'] : 'full';
									$modes        = array(
										'full'    => __( 'Full — all product data (images, prices, tags, vendor, all inventory levels)', 'mcp-ai-wpoos-pro' ),
										'minimal' => __( 'Minimal — title, SKU, and stock levels only (faster sync, lower cost)', 'mcp-ai-wpoos-pro' ),
									);
									foreach ( $modes as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( $current_mode, $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Full sync pulls all product fields and variant data. Minimal sync only pulls title, SKU, and available stock quantities — ideal for inventory-only use cases with lower GraphQL cost and faster sync times.', 'mcp-ai-wpoos-pro' ); ?></p>
							</td>
						</tr>
				<tr>
					<th scope="row">
						<label for="shopify_sync_direction"><?php esc_html_e( 'Sync Direction', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="shopify_sync_direction" id="shopify_sync_direction">
							<?php
							$current_direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'shopify_to_woo';
							$directions        = array(
								'shopify_to_woo' => __( 'Shopify → WooCommerce only', 'mcp-ai-wpoos-pro' ),
								'bidirectional'  => __( 'Bidirectional (Phase 2)', 'mcp-ai-wpoos-pro' ),
								'read_only'      => __( 'Read-Only (cache only, no WC writes)', 'mcp-ai-wpoos-pro' ),
							);
							foreach ( $directions as $value => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $value ),
									selected( $current_direction, $value, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WooCommerce Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="shopify_enable_wc_sync" value="yes"
								<?php checked( ! empty( $settings['enable_wc_sync'] ) ); ?> />
							<?php esc_html_e( 'Update WooCommerce stock quantities from Shopify', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="shopify_low_stock_threshold"><?php esc_html_e( 'Low Stock Threshold', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="number" name="shopify_low_stock_threshold" id="shopify_low_stock_threshold"
							value="<?php echo esc_attr( isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5 ); ?>"
							min="0" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Quantity below which an item is considered "low stock".', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhooks', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="shopify_enable_webhooks" value="yes"
								<?php checked( ! isset( $settings['enable_webhooks'] ) || ! empty( $settings['enable_webhooks'] ) ); ?> />
							<?php esc_html_e( 'Enable Shopify webhooks for real-time inventory updates (zero API cost)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<?php if ( class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: webhook URL */
									esc_html__( 'Webhook endpoint: %s', 'mcp-ai-wpoos-pro' ),
									'<code>' . esc_url( rest_url( 'mcp-ai/v1/shopify/webhook' ) ) . '</code>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'CCT Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="shopify_cct_slug"><?php esc_html_e( 'CCT Slug', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="shopify_cct_slug" id="shopify_cct_slug"
							value="<?php echo esc_attr( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_Shopify_Sync_CCT_Manager::CCT_SLUG_DEFAULT ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'JetEngine CCT slug for inventory storage. Must be unique.', 'mcp-ai-wpoos-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="shopify_catalog_search_terms"><?php esc_html_e( 'Catalog API Search Terms', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php $terms = isset( $settings['catalog_search_terms'] ) ? $settings['catalog_search_terms'] : array(); ?>
							<textarea name="shopify_catalog_search_terms" id="shopify_catalog_search_terms"
								rows="4" cols="50" class="large-text code"
								placeholder="<?php esc_attr_e( 'One search term per line', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( is_array( $terms ) ? implode( "\n", $terms ) : $terms ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'For Catalog API connections only. Enter search terms (one per line) to query the Shopify Catalog. Each term is searched separately (max 50 results per term). Leave blank for a broad default search. Results are cached in the CCT for zero-cost AI tool access.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		<?php
	}

	/**
	 * Sanitize and save the settings form submission.
	 *
	 * Overrides the base class to handle Shopify-specific fields
	 * that are rendered directly in render_configuration_tab()
	 * instead of through the WordPress Settings API field registration.
	 *
	 * @param array $input Raw input from the Settings API (unused; fields
	 *                     are read directly from $_POST).
	 * @return array Sanitized settings array.
	 */
	public function sanitize_settings( $input ) {
		// Load existing settings as the fallback for every key.
		$sanitized = get_option( $this->option_name, array() );

		// Only process when the form was actually submitted via POST.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['submit'] ) && ! isset( $_POST['action'] ) ) {
			return $sanitized;
		}

		// Sync connections (checkboxes).
		$sanitized['sync_connections'] = array();
		if ( isset( $_POST['shopify_sync_connections'] ) && is_array( $_POST['shopify_sync_connections'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_connections'] = array_map( 'sanitize_key', wp_unslash( $_POST['shopify_sync_connections'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		// Sync interval.
		if ( isset( $_POST['shopify_sync_interval'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_interval'] = absint( $_POST['shopify_sync_interval'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Sync direction.
		if ( isset( $_POST['shopify_sync_direction'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_direction'] = sanitize_key( wp_unslash( $_POST['shopify_sync_direction'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Sync mode (full or minimal).
		if ( isset( $_POST['shopify_sync_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode                   = sanitize_key( wp_unslash( $_POST['shopify_sync_mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_mode'] = in_array( $mode, array( 'full', 'minimal' ), true ) ? $mode : 'full';
		}

		// WooCommerce sync toggle.
		$sanitized['enable_wc_sync'] = isset( $_POST['shopify_enable_wc_sync'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Low stock threshold.
		if ( isset( $_POST['shopify_low_stock_threshold'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['low_stock_threshold'] = absint( $_POST['shopify_low_stock_threshold'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Webhooks toggle.
		$sanitized['enable_webhooks'] = isset( $_POST['shopify_enable_webhooks'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// CCT slug.
		if ( isset( $_POST['shopify_cct_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['cct_slug'] = sanitize_key( wp_unslash( $_POST['shopify_cct_slug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Catalog API search terms.
		if ( isset( $_POST['shopify_catalog_search_terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_terms                         = sanitize_textarea_field( wp_unslash( $_POST['shopify_catalog_search_terms'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$terms                             = array_filter( array_map( 'trim', explode( "\n", $raw_terms ) ) );
			$sanitized['catalog_search_terms'] = array_slice( $terms, 0, 20 ); // Max 20 search terms.
		}

		return $sanitized;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_tools_list() {
		return array(
			'shopify_sync_inventory' => __( 'Shopify Sync Inventory', 'mcp-ai-wpoos-pro' ),
			'shopify_sync_products'  => __( 'Shopify Sync Products', 'mcp-ai-wpoos-pro' ),
			'shopify_sync_orders'    => __( 'Shopify Sync Orders', 'mcp-ai-wpoos-pro' ),
			'shopify_sync_settings'  => __( 'Shopify Sync Settings', 'mcp-ai-wpoos-pro' ),
			'shopify_sync_analytics' => __( 'Shopify Sync Analytics', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Render the Sync Log tab.
	 *
	 * @since 1.9.1
	 */
	protected function render_sync_log_tab() {
		$settings         = get_option( $this->option_name, array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		?>
		<div class="toolkit-sync-log">
			<h2><?php esc_html_e( 'Sync Log & Diagnostics', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php if ( ! empty( $sync_connections ) ) : ?>
				<h3><?php esc_html_e( 'Connection Status', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat fixed striped" style="max-width: 900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Connection', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Sync', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'CCT Rows', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Fresh', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Last Error', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $sync_connections as $conn_id ) {
							if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
								require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
							}
							$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $conn_id );
							$last_sync   = $cct_manager->get_last_sync_time();
							$row_count   = $cct_manager->get_row_count();
							$is_fresh    = $cct_manager->is_fresh();
							$last_error  = get_option( 'wp_mcp_ai_shopify_last_sync_error_' . $conn_id, '' );

							$conn_name = $conn_id;
							if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
								$conn_data = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $conn_id );
								if ( $conn_data && ! empty( $conn_data['name'] ) ) {
									$conn_name = $conn_data['name'];
								}
							}
							?>
							<tr>
								<td><?php echo esc_html( $conn_name ); ?></td>
								<td><?php echo esc_html( ! empty( $last_sync ) ? $last_sync : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?></td>
								<td><?php echo esc_html( (string) $row_count ); ?></td>
								<td><?php echo $is_fresh ? '<span style="color:green;">&#10004; ' . esc_html__( 'Fresh', 'mcp-ai-wpoos-pro' ) . '</span>' : '<span style="color:orange;">&#9888; ' . esc_html__( 'Stale', 'mcp-ai-wpoos-pro' ) . '</span>'; ?></td>
								<td><?php echo esc_html( ! empty( $last_error ) ? $last_error : __( 'None', 'mcp-ai-wpoos-pro' ) ); ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No connections configured for sync.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>

			<?php
			// Sync Run History from Sync Log Manager — merge shopify_sync and shopify_wc runs.
			$sync_runs = array();
			if ( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
				$sync_runs = WP_MCP_AI_Sync_Log_Manager::get_runs( 'shopify_sync', 10 );
				$wc_runs   = WP_MCP_AI_Sync_Log_Manager::get_runs( 'shopify_wc', 10 );
				$sync_runs = array_merge( $sync_runs, $wc_runs );

				// Sort by started_at descending.
				usort(
					$sync_runs,
					function ( $a, $b ) {
						return strcmp(
							isset( $b['started_at'] ) ? $b['started_at'] : '',
							isset( $a['started_at'] ) ? $a['started_at'] : ''
						);
					}
				);
				$sync_runs = array_slice( $sync_runs, 0, 10 );
			}
			?>

			<?php if ( ! empty( $sync_runs ) ) : ?>
				<h3><?php esc_html_e( 'Recent Sync Runs', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat fixed striped" style="max-width: 900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Started', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Items', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Dry Run', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sync_runs as $run ) : ?>
							<?php
							$status_class = 'completed' === $run['status'] ? 'color:green;' : ( 'failed' === $run['status'] ? 'color:red;' : '' );
							?>
							<tr>
								<td><?php echo esc_html( isset( $run['started_at'] ) ? $run['started_at'] : '' ); ?></td>
								<td style="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $run['status'] ) ); ?></td>
								<td><?php echo esc_html( isset( $run['duration_secs'] ) ? $run['duration_secs'] . 's' : '' ); ?></td>
								<td><?php echo esc_html( isset( $run['items_total'] ) ? $run['items_total'] : 0 ); ?></td>
								<td><?php echo esc_html( isset( $run['items_errored'] ) ? $run['items_errored'] : 0 ); ?></td>
								<td><?php echo ! empty( $run['dry_run'] ) ? '&#10004;' : '&#10008;'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No sync runs recorded yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Sync Now" admin-post action.
	 *
	 * Runs a full Shopify sync for the given connection and
	 * redirects back to the overview tab with a result message.
	 *
	 * @since 1.3.0
	 */
	public function handle_sync_now() {
		$this->handle_sync_action( false );
	}

	/**
	 * Handle the "Dry Run" admin-post action.
	 *
	 * Validates the entire sync pipeline without writing data.
	 * Redirects back to the overview tab with a detailed
	 * dry-run report.
	 *
	 * @since 1.3.0
	 */
	public function handle_sync_dry_run() {
		$this->handle_sync_action( true );
	}

	/**
	 * Common handler for sync and dry-run admin-post actions.
	 *
	 * @since 1.3.0
	 *
	 * @param bool $dry_run Whether this is a dry-run.
	 */
	private function handle_sync_action( $dry_run ) {
			// Capability check.
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
					wp_die(
						esc_html__( 'You do not have sufficient permissions to perform this action.', 'mcp-ai-wpoos-pro' ),
						403
					);
		}

			// Nonce verification to prevent CSRF attacks.
			check_admin_referer( 'wp_mcp_ai_shopify_sync' );

				// Validate connection ID.
		$connection_id = isset( $_GET['connection_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			: '';

		if ( empty( $connection_id ) ) {
			wp_die(
				esc_html__( 'Missing connection ID.', 'mcp-ai-wpoos-pro' ),
				400
			);
		}

		// Verify the connection is in the configured sync list.
		$settings         = get_option( $this->option_name, array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		if ( ! in_array( $connection_id, $sync_connections, true ) ) {
			wp_die(
				esc_html__( 'This connection is not configured for sync.', 'mcp-ai-wpoos-pro' ),
				400
			);
		}

		// Load sync engine if needed.
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		}

		// Run the sync (or dry-run).
		$result = WP_MCP_AI_Shopify_Sync_Engine::run_full_sync( $connection_id, $dry_run );

		// Build redirect URL with result query args.
		$redirect_url = add_query_arg(
			array(
				'page' => $this->page_slug,
				'tab'  => 'overview',
			),
			admin_url( 'admin.php' )
		);

		if ( $dry_run ) {
			if ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg(
					array(
						'dry_run_error' => 1,
						'dry_run_msg'   => rawurlencode( $result->get_error_message() ),
					),
					$redirect_url
				);
			} else {
				$dry_run_args = array(
					'dry_run_ok'     => 1,
					'dry_run_insert' => isset( $result['data_summary']['items_would_insert'] ) ? absint( $result['data_summary']['items_would_insert'] ) : 0,
					'dry_run_update' => isset( $result['data_summary']['items_would_update'] ) ? absint( $result['data_summary']['items_would_update'] ) : 0,
					'dry_run_skip'   => isset( $result['data_summary']['items_would_skip'] ) ? absint( $result['data_summary']['items_would_skip'] ) : 0,
					'dry_run_dur'    => isset( $result['data_summary']['duration'] ) ? $result['data_summary']['duration'] : 0,
				);
				if ( ! empty( $result['validation']['warnings'] ) && is_array( $result['validation']['warnings'] ) ) {
					$dry_run_args['dry_run_warn'] = rawurlencode( implode( ' ', array_map( 'sanitize_text_field', $result['validation']['warnings'] ) ) );
				}
				$redirect_url = add_query_arg( $dry_run_args, $redirect_url );
			}
		} elseif ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg(
					array(
						'sync_error' => 1,
						'sync_msg'   => rawurlencode( $result->get_error_message() ),
					),
					$redirect_url
				);
		} else {
			$sync_args = array(
				'sync_ok'     => 1,
				'sync_insert' => isset( $result['inserted'] ) ? absint( $result['inserted'] ) : 0,
				'sync_update' => isset( $result['updated'] ) ? absint( $result['updated'] ) : 0,
				'sync_skip'   => isset( $result['skipped'] ) ? absint( $result['skipped'] ) : 0,
				'sync_errors' => isset( $result['errors'] ) ? absint( $result['errors'] ) : 0,
				'sync_dur'    => isset( $result['duration'] ) ? $result['duration'] : 0,
			);
			if ( ! empty( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
				$sync_args['sync_warn'] = rawurlencode( implode( ' ', array_map( 'sanitize_text_field', $result['warnings'] ) ) );
			}
			$redirect_url = add_query_arg( $sync_args, $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
