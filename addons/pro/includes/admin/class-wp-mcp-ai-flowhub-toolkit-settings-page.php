<?php
/**
 * FlowHub Toolkit Settings Page
 *
 * Admin settings page for the FlowHub Inventory Sync Pro Toolkit.
 * Provides connection configuration, sync management, and CCT status.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';

/**
 * FlowHub Toolkit Settings Page.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_FlowHub_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->toolkit_slug     = 'flowhub';
		$this->toolkit_name     = __( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_flowhub_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-flowhub-toolkit-settings';
		$this->parent_slug      = 'wp-mcp-ai-flowhub-toolkit';
		$this->has_remote_sites = false;
		$this->has_research     = false;
		$this->icon             = 'dashicons-store';

		add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 25 );

		// Handle Sync Now and Dry Run admin-post actions.
		add_action( 'admin_post_wp_mcp_ai_flowhub_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_wp_mcp_ai_flowhub_sync_dry_run', array( $this, 'handle_sync_dry_run' ) );

		parent::__construct();
	}

	/**
	 * Add top-level FlowHub Toolkit admin menu.
	 */
	public function add_top_level_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_menu_page(
			__( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown
			$this->parent_slug,
			array( $this, 'redirect_to_first_submenu' ),
			$this->icon,
			57
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
	 * Sanitize all configuration fields on save.
	 *
	 * The base class sanitizer only handles a subset of fields. This override
	 * reads from the flat POST field names used in the configuration tab and
	 * merges them with previously-stored settings so that unchecked checkboxes
	 * and omitted fields are preserved.
	 *
	 * @since 1.4.0
	 *
	 * @param array $input Raw input (from Settings API; may be empty for flat-named fields).
	 * @return array Sanitized settings merged with existing.
	 */
	public function sanitize_settings( $input ) {
		// Load existing settings as the fallback for every key.
		$sanitized = get_option( $this->option_name, array() );

		// Only process when the form was actually submitted via POST.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['submit'] ) && ! isset( $_POST['action'] ) ) {
			return $sanitized;
		}

		// API credentials.
		if ( isset( $_POST['flowhub_client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['client_id'] = sanitize_text_field( wp_unslash( $_POST['flowhub_client_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}
		if ( isset( $_POST['flowhub_api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['api_key'] = wp_unslash( $_POST['flowhub_api_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key; stored as-is.
		}
		if ( isset( $_POST['flowhub_api_base_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['api_base_url'] = esc_url_raw( wp_unslash( $_POST['flowhub_api_base_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Location.
		if ( isset( $_POST['flowhub_location_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['location_id'] = sanitize_text_field( wp_unslash( $_POST['flowhub_location_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Sync connections (checkboxes from Remote Sites).
			$sanitized['sync_connections'] = array();
		if ( isset( $_POST['flowhub_sync_connections'] ) && is_array( $_POST['flowhub_sync_connections'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_connections'] = array_map( 'sanitize_key', wp_unslash( $_POST['flowhub_sync_connections'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

			// Sync settings.
		if ( isset( $_POST['flowhub_sync_interval'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_interval'] = absint( $_POST['flowhub_sync_interval'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$sanitized['enable_wc_sync'] = isset( $_POST['flowhub_enable_wc_sync'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['flowhub_sync_direction'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_direction'] = sanitize_key( wp_unslash( $_POST['flowhub_sync_direction'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}
		if ( isset( $_POST['flowhub_low_stock_threshold'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['low_stock_threshold'] = absint( $_POST['flowhub_low_stock_threshold'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// CCT.
		if ( isset( $_POST['flowhub_cct_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['cct_slug'] = sanitize_key( wp_unslash( $_POST['flowhub_cct_slug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Proxy.
		$sanitized['proxy_enabled'] = isset( $_POST['flowhub_proxy_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['flowhub_proxy_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['proxy_url'] = sanitize_text_field( wp_unslash( $_POST['flowhub_proxy_url'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}
		if ( isset( $_POST['flowhub_proxy_username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['proxy_username'] = sanitize_text_field( wp_unslash( $_POST['flowhub_proxy_username'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}
		if ( isset( $_POST['flowhub_proxy_password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['proxy_password'] = wp_unslash( $_POST['flowhub_proxy_password'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password; stored as-is.
		}

		// Field mapping (associative array of source → target).
		$sanitized['field_mapping'] = isset( $sanitized['field_mapping'] ) ? $sanitized['field_mapping'] : array();
		if ( isset( $_POST['flowhub_field_mapping_keys'] ) && is_array( $_POST['flowhub_field_mapping_keys'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& isset( $_POST['flowhub_field_mapping_values'] ) && is_array( $_POST['flowhub_field_mapping_values'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$keys    = array_map( 'sanitize_text_field', wp_unslash( $_POST['flowhub_field_mapping_keys'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$values  = array_map( 'sanitize_text_field', wp_unslash( $_POST['flowhub_field_mapping_values'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$mapping = array();
			foreach ( $keys as $i => $key ) {
				$key = trim( $key );
				if ( '' !== $key && isset( $values[ $i ] ) ) {
					$mapping[ $key ] = trim( $values[ $i ] );
				}
			}
			$sanitized['field_mapping'] = $mapping;
		}

		return $sanitized;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function render_overview_tab() {
		$settings         = get_option( $this->option_name, array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();
		$wc_active        = class_exists( 'WooCommerce' );
		$client_active    = class_exists( 'WP_MCP_AI_FlowHub_Client' );
		?>
		<div class="toolkit-overview">
			<?php
			// Sync / dry-run result notices (set via redirect from admin-post handlers).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['sync_ok'] ) ) :
				$sync_items = isset( $_GET['sync_items'] ) ? absint( $_GET['sync_items'] ) : null;
				$sync_errs  = isset( $_GET['sync_errs'] ) ? absint( $_GET['sync_errs'] ) : null;
				$sync_locs  = isset( $_GET['sync_locs'] ) ? absint( $_GET['sync_locs'] ) : null;
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Sync completed.', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<?php if ( null !== $sync_items ) : ?>
					<p>
						<?php
						/* translators: 1: synced count, 2: error count, 3: location count */
						echo esc_html(
							sprintf(
								__( '%1$d items synced across %3$d locations.', 'mcp-ai-wpoos-pro' ),
								$sync_items,
								$sync_errs,
								$sync_locs
							)
						);
						if ( $sync_errs > 0 ) {
							echo ' ' . esc_html(
								sprintf(
									/* translators: %d: error count */
									__( '%d items had errors (check logs for details).', 'mcp-ai-wpoos-pro' ),
									$sync_errs
								)
							);
						}
						if ( 0 === $sync_items && $sync_errs > 0 ) {
							echo '<br><strong>' . esc_html__( 'No items were stored in the CCT cache. Check the Sync Log tab for error details.', 'mcp-ai-wpoos-pro' ) . '</strong>';
						}
						?>
					</p>
					<?php endif; ?>
				</div>
				<?php
			elseif ( isset( $_GET['sync_error'] ) ) :
				$sync_msg = isset( $_GET['sync_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_msg'] ) ) : __( 'Unknown error.', 'mcp-ai-wpoos-pro' );
				?>
				<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Sync failed:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $sync_msg ); ?></p></div>
				<?php
			elseif ( isset( $_GET['dry_run_ok'] ) ) :
				$dr_items = isset( $_GET['dry_run_items'] ) ? absint( $_GET['dry_run_items'] ) : 0;
				$dr_locs  = isset( $_GET['dry_run_locs'] ) ? absint( $_GET['dry_run_locs'] ) : 0;
				$dr_errs  = isset( $_GET['dry_run_errs'] ) ? absint( $_GET['dry_run_errs'] ) : 0;
				$dr_dur   = isset( $_GET['dry_run_dur'] ) ? floatval( $_GET['dry_run_dur'] ) : 0;
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Dry Run Complete', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<p>
						<?php
						/* translators: 1: item count, 2: location count, 3: duration in seconds */
						printf(
							esc_html__( '%1$d items across %2$d locations (in %3$ss). No data was modified.', 'mcp-ai-wpoos-pro' ),
							absint( $dr_items ),
							absint( $dr_locs ),
							esc_html( (string) $dr_dur )
						);
						?>
					</p>
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

			<h2><?php esc_html_e( 'FlowHub Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="toolkit-description">
				<p><?php esc_html_e( 'Synchronize FlowHub dispensary inventory with WooCommerce using a CCT-based cache layer. AI assistants query locally cached data with zero FlowHub API cost. Background sync via Action Scheduler keeps data fresh.', 'mcp-ai-wpoos-pro' ); ?></p>
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
						<th><?php esc_html_e( 'FlowHub API Client', 'mcp-ai-wpoos-pro' ); ?></th>
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
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $sync_connections as $conn_id ) :
						$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager( $conn_id );
						$last_sync   = $cct_manager->get_last_sync_time();
						$row_count   = $cct_manager->get_row_count();
						$is_fresh    = $cct_manager->is_fresh();

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
					<p><?php esc_html_e( 'No FlowHub connections are configured for sync. Go to the Configuration tab to select which connections to synchronize.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'flowhub_inventory', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Search and filter inventory items from CCT cache (zero API cost)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_products', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse product catalog and categories', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_locations', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'List dispensary locations with stock counts', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_sync', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Trigger sync and check status', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_settings', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Manage toolkit configuration', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<p class="description"><?php esc_html_e( 'Note: These tools query the local CCT cache. Use the FlowHub live-API tools (flowhub_products_live, etc.) for real-time operations and mutations.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<script>
		( function() {
			var syncNowUrl = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_flowhub_sync_now' ) ); ?> + '&connection_id=';
			var dryRunUrl  = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_flowhub_sync_dry_run' ) ); ?> + '&connection_id=';

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

		// Gather all available FlowHub connections from Remote Sites.
		$available_connections = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $conn_id => $conn ) {
				$conn_type = isset( $conn['connection_type'] ) ? sanitize_key( $conn['connection_type'] ) : '';
				if ( 'flowhub' === $conn_type || 'flowhub_pos' === $conn_type ) {
					$available_connections[ $conn_id ] = $conn;
				}
			}
		}
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'FlowHub Sync Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php if ( empty( $available_connections ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No FlowHub remote connections found. Please configure a FlowHub connection in NV oOS → Remote Sites first, or use the direct API credentials below.', 'mcp-ai-wpoos-pro' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>" class="button"><?php esc_html_e( 'Go to Remote Sites', 'mcp-ai-wpoos-pro' ); ?></a></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Connections to Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select which FlowHub connections to synchronize. Each connection will have its own CCT cache and sync schedule.', 'mcp-ai-wpoos-pro' ); ?></p>
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
							<input type="checkbox" name="flowhub_sync_connections[]"
								id="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>"
								value="<?php echo esc_attr( $conn['id'] ); ?>"
								<?php checked( in_array( $conn['id'], $sync_connections, true ) ); ?> />
							<label for="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>">
								<?php esc_html_e( 'Enable sync for this connection', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: store/URL identifier */
									esc_html__( 'Connection ID: %s', 'mcp-ai-wpoos-pro' ),
									esc_html( $conn['id'] )
								);
								?>
							</p>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No FlowHub connections are available for sync. Configure a FlowHub connection in NV oOS → Remote Sites, then return here to enable sync.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'FlowHub API Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="flowhub_client_id"><?php esc_html_e( 'Client ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_client_id" id="flowhub_client_id"
							value="<?php echo esc_attr( isset( $settings['client_id'] ) ? $settings['client_id'] : '' ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'Your FlowHub API client ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="flowhub_api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="flowhub_api_key" id="flowhub_api_key"
							value="<?php echo esc_attr( isset( $settings['api_key'] ) ? $settings['api_key'] : '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Your FlowHub API key.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="flowhub_api_base_url"><?php esc_html_e( 'API Base URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="url" name="flowhub_api_base_url" id="flowhub_api_base_url"
							value="<?php echo esc_url( isset( $settings['api_base_url'] ) ? $settings['api_base_url'] : 'https://api.flowhub.co/v0/' ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'Override the FlowHub API base URL if needed.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Proxy Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'If FlowHub blocks requests from your server location, route API calls through a US-based proxy. Services like Webshare offer free/cheap HTTP proxies.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="flowhub_proxy_enabled"><?php esc_html_e( 'Enable Proxy', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="flowhub_proxy_enabled" id="flowhub_proxy_enabled" value="yes"
								<?php checked( ! empty( $settings['proxy_enabled'] ) ); ?> />
							<?php esc_html_e( 'Route FlowHub API requests through a proxy server', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="flowhub_proxy_url"><?php esc_html_e( 'Proxy URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_proxy_url" id="flowhub_proxy_url"
							value="<?php echo esc_attr( isset( $settings['proxy_url'] ) ? $settings['proxy_url'] : '' ); ?>"
							class="regular-text" placeholder="proxy.example.com:8080" />
						<p class="description"><?php esc_html_e( 'Proxy hostname and port (e.g., p.webshare.io:80). Supports HTTP proxies.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="flowhub_proxy_username"><?php esc_html_e( 'Proxy Username', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_proxy_username" id="flowhub_proxy_username"
							value="<?php echo esc_attr( isset( $settings['proxy_username'] ) ? $settings['proxy_username'] : '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Optional — only needed if your proxy requires authentication.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="flowhub_proxy_password"><?php esc_html_e( 'Proxy Password', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="flowhub_proxy_password" id="flowhub_proxy_password"
							value="<?php echo esc_attr( isset( $settings['proxy_password'] ) ? $settings['proxy_password'] : '' ); ?>"
							class="regular-text" autocomplete="off" />
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Sync Settings', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="flowhub_sync_interval"><?php esc_html_e( 'Sync Interval', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="flowhub_sync_interval" id="flowhub_sync_interval">
							<?php
							$current_interval = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 15;
							$intervals        = array( 1, 5, 15, 30, 60 );
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
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WooCommerce Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="flowhub_enable_wc_sync" value="yes"
								<?php checked( ! empty( $settings['enable_wc_sync'] ) ); ?> />
							<?php esc_html_e( 'Update WooCommerce stock quantities from FlowHub', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync Direction', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="flowhub_sync_direction" id="flowhub_sync_direction">
							<?php
							$current_direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'read_only';
							$directions        = array(
								'flowhub_to_woo' => __( 'FlowHub → WooCommerce only', 'mcp-ai-wpoos-pro' ),
								'bidirectional'  => __( 'Bidirectional', 'mcp-ai-wpoos-pro' ),
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
					<th scope="row">
						<label for="flowhub_low_stock_threshold"><?php esc_html_e( 'Low Stock Threshold', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="number" name="flowhub_low_stock_threshold" id="flowhub_low_stock_threshold"
							value="<?php echo esc_attr( isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5 ); ?>"
							min="0" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Quantity below which an item is considered "low stock".', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'CCT Configuration', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="flowhub_cct_slug"><?php esc_html_e( 'CCT Slug', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_cct_slug" id="flowhub_cct_slug"
							value="<?php echo esc_attr( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_FlowHub_CCT_Manager::CCT_SLUG_DEFAULT ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'JetEngine CCT slug for inventory storage.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Field Mapping', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Map FlowHub API field names to CCT column names. Use the FlowHub field name as the key and the CCT column as the value.', 'mcp-ai-wpoos-pro' ); ?></p>
			<table class="form-table" id="flowhub-field-mapping-table">
				<?php
				$field_mapping = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();
				if ( empty( $field_mapping ) ) {
					$field_mapping = array( '' => '' );
				}
				foreach ( $field_mapping as $source => $target ) :
					?>
					<tr class="flowhub-field-mapping-row">
						<td>
							<input type="text" name="flowhub_field_mapping_keys[]"
								value="<?php echo esc_attr( $source ); ?>"
								placeholder="<?php esc_attr_e( 'FlowHub field name', 'mcp-ai-wpoos-pro' ); ?>"
								class="regular-text" />
						</td>
						<td>
							<input type="text" name="flowhub_field_mapping_values[]"
								value="<?php echo esc_attr( $target ); ?>"
								placeholder="<?php esc_attr_e( 'CCT column name', 'mcp-ai-wpoos-pro' ); ?>"
								class="regular-text" />
						</td>
						<td>
							<button type="button" class="button flowhub-remove-mapping-row"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos-pro' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p>
				<button type="button" class="button" id="flowhub-add-mapping-row"><?php esc_html_e( 'Add Field Mapping', 'mcp-ai-wpoos-pro' ); ?></button>
				<button type="button" class="button button-primary" id="flowhub-generate-default-mapping"><?php esc_html_e( 'Generate Default Mapping', 'mcp-ai-wpoos-pro' ); ?></button>
			</p>

			<script>
			( function() {
				var table = document.getElementById( 'flowhub-field-mapping-table' );

				function makeRow( key, value ) {
					var row = document.createElement( 'tr' );
					row.className = 'flowhub-field-mapping-row';
					row.innerHTML = '<td><input type="text" name="flowhub_field_mapping_keys[]" value="' + key + '" placeholder="<?php echo esc_js( __( 'FlowHub field name', 'mcp-ai-wpoos-pro' ) ); ?>" class="regular-text" /></td>' +
						'<td><input type="text" name="flowhub_field_mapping_values[]" value="' + value + '" placeholder="<?php echo esc_js( __( 'CCT column name', 'mcp-ai-wpoos-pro' ) ); ?>" class="regular-text" /></td>' +
						'<td><button type="button" class="button flowhub-remove-mapping-row"><?php echo esc_js( __( 'Remove', 'mcp-ai-wpoos-pro' ) ); ?></button></td>';
					return row;
				}

				document.getElementById( 'flowhub-add-mapping-row' ).addEventListener( 'click', function() {
					table.appendChild( makeRow( '', '' ) );
				});

				// Generate Default Mapping button: pre-fills with inverse of the
				// CCT manager's get_default_field_mapping() (FlowHub field → CCT column).
				document.getElementById( 'flowhub-generate-default-mapping' ).addEventListener( 'click', function() {
					var defaults = <?php echo wp_json_encode( WP_MCP_AI_FlowHub_CCT_Manager::get_default_field_mapping_static() ); ?>;

					// Clear existing rows.
					table.innerHTML = '';

					// defaults is { cct_column: fh_field }, invert it.
					// Skip special extractor entries (prefixed with _extracted.)
					// since they aren't direct FlowHub API field names.
					for ( var cctCol in defaults ) {
						if ( ! Object.prototype.hasOwnProperty.call( defaults, cctCol ) ) {
							continue;
						}
						var fhField = defaults[ cctCol ];
						if ( ! fhField || fhField.indexOf( '_extracted.' ) === 0 ) {
							continue;
						}
						table.appendChild( makeRow( fhField, cctCol ) );
					}

					// Always leave one empty row for custom additions.
					table.appendChild( makeRow( '', '' ) );
				});

				table.addEventListener( 'click', function( e ) {
					if ( e.target && e.target.classList.contains( 'flowhub-remove-mapping-row' ) ) {
						var row = e.target.closest( 'tr' );
						if ( row && table.querySelectorAll( '.flowhub-field-mapping-row' ).length > 1 ) {
							row.remove();
						}
					}
				});
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_tools_list() {
		return array(
			'flowhub_inventory' => __( 'FlowHub Inventory', 'mcp-ai-wpoos-pro' ),
			'flowhub_products'  => __( 'FlowHub Products', 'mcp-ai-wpoos-pro' ),
			'flowhub_locations' => __( 'FlowHub Locations', 'mcp-ai-wpoos-pro' ),
			'flowhub_analytics' => __( 'FlowHub Analytics', 'mcp-ai-wpoos-pro' ),
			'flowhub_sync'      => __( 'FlowHub Sync', 'mcp-ai-wpoos-pro' ),
			'flowhub_settings'  => __( 'FlowHub Settings', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Handle the "Sync Now" admin-post action.
	 *
	 * @since 1.5.0
	 */
	public function handle_sync_now() {
		$this->handle_sync_action( false );
	}

	/**
	 * Handle the "Dry Run" admin-post action.
	 *
	 * @since 1.5.0
	 */
	public function handle_sync_dry_run() {
		$this->handle_sync_action( true );
	}

	/**
	 * Common handler for sync and dry-run admin-post actions.
	 *
	 * @since 1.5.0
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
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-sync-engine.php';
		}

		// Run the sync (or dry-run) for this connection.
		$result = WP_MCP_AI_FlowHub_Sync_Engine::run_full_sync( $dry_run, $connection_id );

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
				$redirect_url = add_query_arg(
					array(
						'dry_run_ok'    => 1,
						'dry_run_items' => isset( $result['data_summary']['items_would_sync'] ) ? absint( $result['data_summary']['items_would_sync'] ) : 0,
						'dry_run_locs'  => isset( $result['data_summary']['locations'] ) ? absint( $result['data_summary']['locations'] ) : 0,
						'dry_run_errs'  => isset( $result['data_summary']['errors'] ) ? absint( $result['data_summary']['errors'] ) : 0,
						'dry_run_dur'   => isset( $result['data_summary']['duration'] ) ? $result['data_summary']['duration'] : 0,
					),
					$redirect_url
				);
			}
		} elseif ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg(
					array(
						'sync_error' => 1,
						'sync_msg'   => rawurlencode( $result->get_error_message() ),
					),
					$redirect_url
				);
		} elseif ( is_array( $result ) ) {
			$items        = isset( $result['item_count'] ) ? absint( $result['item_count'] ) : 0;
			$errors       = isset( $result['error_count'] ) ? absint( $result['error_count'] ) : 0;
			$locs         = isset( $result['location_count'] ) ? absint( $result['location_count'] ) : 0;
			$redirect_url = add_query_arg(
				array(
					'sync_ok'    => 1,
					'sync_items' => $items,
					'sync_errs'  => $errors,
					'sync_locs'  => $locs,
				),
				$redirect_url
			);
		} else {
			$redirect_url = add_query_arg(
				array( 'sync_ok' => 1 ),
				$redirect_url
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

		/**
		 * Render the Sync Log tab.
		 *
		 * @since 1.4.0
		 */
	protected function render_sync_log_tab() {
		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$last_sync   = $cct_manager->get_last_sync_time();
		$row_count   = $cct_manager->get_row_count();
		$is_fresh    = $cct_manager->is_fresh();
		$last_error  = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );

		// API stats.
		$api_stats = array();
		if ( class_exists( 'WP_MCP_AI_FlowHub_Client' ) && method_exists( 'WP_MCP_AI_FlowHub_Client', 'get_api_stats' ) ) {
			$api_stats = WP_MCP_AI_FlowHub_Client::get_api_stats();
		}

		// Low-stock counts.
		$low_count = absint( get_option( 'wp_mcp_ai_flowhub_low_stock_count', 0 ) );
		$out_count = absint( get_option( 'wp_mcp_ai_flowhub_out_of_stock_count', 0 ) );

		// Schema version.
		$schema_version = get_option( 'wp_mcp_ai_flowhub_sync_db_version', '1.0.0' );

		// Next scheduled sync.
		$next_sync = '';
		if ( function_exists( 'as_next_scheduled_action' ) && class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
			$timestamp = as_next_scheduled_action(
				WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC,
				array(),
				WP_MCP_AI_FlowHub_Sync_Engine::GROUP
			);
			$next_sync = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
		}
		?>
		<div class="toolkit-sync-log">
			<h2><?php esc_html_e( 'Sync Log & Diagnostics', 'mcp-ai-wpoos-pro' ); ?></h2>

			<h3><?php esc_html_e( 'Current Status', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 800px;">
				<tbody>
					<tr>
						<th style="width: 250px;"><?php esc_html_e( 'Last Sync', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( ! empty( $last_sync ) ? $last_sync : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next Scheduled Sync', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( ! empty( $next_sync ) ? $next_sync : __( 'Not scheduled', 'mcp-ai-wpoos-pro' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CCT Rows', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( (string) $row_count ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cache Freshness', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo $is_fresh ? '<span style="color:green;">&#10004; ' . esc_html__( 'Fresh', 'mcp-ai-wpoos-pro' ) . '</span>' : '<span style="color:orange;">&#9888; ' . esc_html__( 'Stale', 'mcp-ai-wpoos-pro' ) . '</span>'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schema Version', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $schema_version ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Requests Today', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( isset( $api_stats['today'] ) ? (string) $api_stats['today'] : 'N/A' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Requests (Last Hour)', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( isset( $api_stats['last_hour'] ) ? (string) $api_stats['last_hour'] : 'N/A' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Low Stock Alerts', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( sprintf( '%d low, %d out of stock', $low_count, $out_count ) ); ?></td>
					</tr>
					<?php if ( ! empty( $last_error ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last Error', 'mcp-ai-wpoos-pro' ); ?></th>
						<td style="color:red;"><?php echo esc_html( $last_error ); ?></td>
					</tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php
				// Sync Run History from Sync Log Manager.
				$sync_runs = array();
				if ( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
					$sync_runs = WP_MCP_AI_Sync_Log_Manager::get_runs( 'flowhub', 10 );
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
}
