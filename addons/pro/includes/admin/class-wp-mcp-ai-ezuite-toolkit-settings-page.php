<?php
/**
 * EZuite Toolkit Settings Page
 *
 * Admin settings page for the EZuite Inventory Sync Pro Toolkit.
 * Provides connection configuration, sync management, and CCT status.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cct-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-sync-engine.php';

/**
 * EZuite Toolkit Settings Page.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_EZuite_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->toolkit_slug     = 'ezuite';
		$this->toolkit_name     = __( 'EZuite Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_ezuite_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-ezuite-toolkit-settings';
		$this->parent_slug      = 'wp-mcp-ai-ezuite-toolkit';
		$this->has_remote_sites = false;
		$this->has_research     = false;
		$this->icon             = 'dashicons-database';

		add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 27 );

		// Handle Sync Now and Dry Run admin-post actions.
		add_action( 'admin_post_wp_mcp_ai_ezuite_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_wp_mcp_ai_ezuite_sync_dry_run', array( $this, 'handle_sync_dry_run' ) );

		parent::__construct();
	}

	/**
	 * Add top-level EZuite Toolkit admin menu.
	 */
	public function add_top_level_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_menu_page(
			__( 'EZuite Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'EZuite Toolkit', 'mcp-ai-wpoos-pro' ),
			'manage_woocommerce', // phpcs:ignore WordPress.WP.Capabilities.Unknown
			$this->parent_slug,
			array( $this, 'redirect_to_first_submenu' ),
			$this->icon,
			59
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
	 * @since 1.9.0
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

		// Sync connections (checkboxes from Remote Sites).
		$sanitized['sync_connections'] = array();
		if ( isset( $_POST['ezuite_sync_connections'] ) && is_array( $_POST['ezuite_sync_connections'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_connections'] = array_map( 'sanitize_key', wp_unslash( $_POST['ezuite_sync_connections'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		// Sync interval.
		if ( isset( $_POST['ezuite_sync_interval'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_interval'] = absint( $_POST['ezuite_sync_interval'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// WooCommerce sync toggle.
		$sanitized['enable_wc_sync'] = isset( $_POST['ezuite_enable_wc_sync'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Sync direction.
		if ( isset( $_POST['ezuite_sync_direction'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['sync_direction'] = sanitize_key( wp_unslash( $_POST['ezuite_sync_direction'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Low stock threshold.
		if ( isset( $_POST['ezuite_low_stock_threshold'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['low_stock_threshold'] = absint( $_POST['ezuite_low_stock_threshold'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// CCT slug.
		if ( isset( $_POST['ezuite_cct_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$sanitized['cct_slug'] = sanitize_key( wp_unslash( $_POST['ezuite_cct_slug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		// Field mapping (associative array of source → target).
		$sanitized['field_mapping'] = isset( $sanitized['field_mapping'] ) ? $sanitized['field_mapping'] : array();
		if ( isset( $_POST['ezuite_field_mapping_keys'] ) && is_array( $_POST['ezuite_field_mapping_keys'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& isset( $_POST['ezuite_field_mapping_values'] ) && is_array( $_POST['ezuite_field_mapping_values'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$keys    = array_map( 'sanitize_text_field', wp_unslash( $_POST['ezuite_field_mapping_keys'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$values  = array_map( 'sanitize_text_field', wp_unslash( $_POST['ezuite_field_mapping_values'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
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
		?>
		<div class="toolkit-overview">
			<?php
			// Sync / dry-run result notices (set via redirect from admin-post handlers).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['sync_ok'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sync completed successfully.', 'mcp-ai-wpoos-pro' ); ?></p></div>
				<?php
			elseif ( isset( $_GET['sync_error'] ) ) :
				$sync_msg = isset( $_GET['sync_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sync_msg'] ) ) : __( 'Unknown error.', 'mcp-ai-wpoos-pro' );
				?>
				<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Sync failed:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo esc_html( $sync_msg ); ?></p></div>
				<?php
			elseif ( isset( $_GET['dry_run_ok'] ) ) :
				$dr_items = isset( $_GET['dry_run_items'] ) ? absint( $_GET['dry_run_items'] ) : 0;
				$dr_errs  = isset( $_GET['dry_run_errs'] ) ? absint( $_GET['dry_run_errs'] ) : 0;
				$dr_dur   = isset( $_GET['dry_run_dur'] ) ? floatval( $_GET['dry_run_dur'] ) : 0;
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Dry Run Complete', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<p>
						<?php
						printf(
							/* translators: 1: item count, 2: error count, 3: duration in seconds */
							esc_html__( '%1$d items would be synced, %2$d errors (in %3$ss). No data was modified.', 'mcp-ai-wpoos-pro' ),
							absint( $dr_items ),
							absint( $dr_errs ),
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

			<h2><?php esc_html_e( 'EZuite Inventory Sync Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="toolkit-description">
				<p><?php esc_html_e( 'Synchronize EZuite ERP inventory data with WooCommerce using a CCT-based cache layer. AI assistants query locally cached data with zero EZuite API cost. Background sync via Action Scheduler keeps data fresh.', 'mcp-ai-wpoos-pro' ); ?></p>
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
						<th><?php esc_html_e( 'EZuite CCT Manager', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) : ?>
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
						$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager( $conn_id );
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
					<p><?php esc_html_e( 'No EZuite connections are configured for sync. Go to the Configuration tab to select which connections to synchronize.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'ezuite_inventory', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Search and filter inventory items from CCT cache (zero API cost)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'ezuite_sync', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Trigger sync and check status', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'ezuite_settings', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Manage toolkit configuration', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<p class="description"><?php esc_html_e( 'Note: These tools query the local CCT cache. Use the EZuite live-API tools for real-time operations and mutations.', 'mcp-ai-wpoos-pro' ); ?></p>
		</div>

		<script>
		( function() {
			var syncNowUrl = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_ezuite_sync_now' ) ); ?> + '&connection_id=';
			var dryRunUrl  = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=wp_mcp_ai_ezuite_sync_dry_run' ) ); ?> + '&connection_id=';

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

		// Gather all available EZuite connections from Remote Sites.
		$available_connections = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

			// Log diagnostic if connections exist but none match EZuite filter.
			$has_ezuite = false;
			$total      = is_array( $all_connections ) ? count( $all_connections ) : 0;

			foreach ( $all_connections as $conn_id => $conn ) {
				$conn_type = isset( $conn['connection_type'] ) ? sanitize_key( $conn['connection_type'] ) : '';
				if ( 'ezuite_erp' === $conn_type || 'ezuite' === $conn_type ) {
					$available_connections[ $conn_id ] = $conn;
					$has_ezuite                        = true;
				}
			}

			if ( $total > 0 && ! $has_ezuite && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[EZuite Toolkit] %d Remote Sites connection(s) exist but none matched ezuite_erp type. Connection types found: %s',
						$total,
						wp_json_encode(
							array_unique(
								array_map(
									function ( $c ) {
										return isset( $c['connection_type'] ) ? $c['connection_type'] : '(none)';
									},
									$all_connections
								)
							)
						)
					)
				);
			}
		}
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'EZuite Sync Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php if ( empty( $available_connections ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No EZuite ERP connections found. Please configure an EZuite connection in NV oOS → Remote Sites first.', 'mcp-ai-wpoos-pro' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-remote-sites' ) ); ?>" class="button"><?php esc_html_e( 'Go to Remote Sites', 'mcp-ai-wpoos-pro' ); ?></a></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Connections to Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select which EZuite connections to synchronize. Each connection will have its own CCT cache and sync schedule.', 'mcp-ai-wpoos-pro' ); ?></p>
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
								<input type="checkbox" name="ezuite_sync_connections[]"
									id="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>"
									value="<?php echo esc_attr( $conn['id'] ); ?>"
									<?php checked( in_array( $conn['id'], $sync_connections, true ) ); ?> />
								<label for="sync_conn_<?php echo esc_attr( $conn['id'] ); ?>">
									<?php esc_html_e( 'Enable sync for this connection', 'mcp-ai-wpoos-pro' ); ?>
								</label>
								<p class="description">
									<?php
									printf(
										/* translators: %s: connection ID */
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
					<p><?php esc_html_e( 'No EZuite connections are available for sync. Configure an EZuite ERP connection in NV oOS → Remote Sites, then return here to enable sync.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Sync Settings', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="ezuite_sync_interval"><?php esc_html_e( 'Sync Interval', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="ezuite_sync_interval" id="ezuite_sync_interval">
							<?php
							$current_interval = isset( $settings['sync_interval'] ) ? absint( $settings['sync_interval'] ) : 15;
							$intervals        = array(
								5    => __( 'Every 5 minutes', 'mcp-ai-wpoos-pro' ),
								15   => __( 'Every 15 minutes', 'mcp-ai-wpoos-pro' ),
								30   => __( 'Every 30 minutes', 'mcp-ai-wpoos-pro' ),
								60   => __( 'Every 1 hour', 'mcp-ai-wpoos-pro' ),
								120  => __( 'Every 2 hours', 'mcp-ai-wpoos-pro' ),
								360  => __( 'Every 6 hours', 'mcp-ai-wpoos-pro' ),
								720  => __( 'Every 12 hours', 'mcp-ai-wpoos-pro' ),
								1440 => __( 'Every 24 hours', 'mcp-ai-wpoos-pro' ),
							);
							foreach ( $intervals as $min => $label ) {
								printf(
									'<option value="%d" %s>%s</option>',
									esc_attr( (string) $min ),
									selected( $current_interval, $min, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'How often to run the full sync from EZuite ERP. Shorter intervals may increase API load.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WooCommerce Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ezuite_enable_wc_sync" value="yes"
								<?php checked( ! empty( $settings['enable_wc_sync'] ) ); ?> />
							<?php esc_html_e( 'Update WooCommerce stock quantities from EZuite', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ezuite_sync_direction"><?php esc_html_e( 'Sync Direction', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="ezuite_sync_direction" id="ezuite_sync_direction">
							<?php
							$current_direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'ezuite_to_woo';
							$directions        = array(
								'ezuite_to_woo' => __( 'EZuite → WooCommerce only', 'mcp-ai-wpoos-pro' ),
								'bidirectional' => __( 'Bidirectional', 'mcp-ai-wpoos-pro' ),
								'read_only'     => __( 'Read-Only (cache only, no WC writes)', 'mcp-ai-wpoos-pro' ),
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
						<label for="ezuite_low_stock_threshold"><?php esc_html_e( 'Low Stock Threshold', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="number" name="ezuite_low_stock_threshold" id="ezuite_low_stock_threshold"
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
						<label for="ezuite_cct_slug"><?php esc_html_e( 'CCT Slug', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="ezuite_cct_slug" id="ezuite_cct_slug"
							value="<?php echo esc_attr( isset( $settings['cct_slug'] ) ? $settings['cct_slug'] : WP_MCP_AI_EZuite_CCT_Manager::CCT_SLUG_DEFAULT ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'JetEngine CCT slug for inventory storage. Must be unique.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Field Mapping', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Map EZuite API field names to CCT column names. Use the EZuite field name as the key and the CCT column as the value.', 'mcp-ai-wpoos-pro' ); ?></p>
			<table class="form-table" id="ezuite-field-mapping-table">
				<?php
				$field_mapping = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();
				if ( empty( $field_mapping ) ) {
					$field_mapping = array( '' => '' );
				}
				foreach ( $field_mapping as $source => $target ) :
					?>
					<tr class="ezuite-field-mapping-row">
						<td>
							<input type="text" name="ezuite_field_mapping_keys[]"
								value="<?php echo esc_attr( $source ); ?>"
								placeholder="<?php esc_attr_e( 'EZuite field name', 'mcp-ai-wpoos-pro' ); ?>"
								class="regular-text" />
						</td>
						<td>
							<input type="text" name="ezuite_field_mapping_values[]"
								value="<?php echo esc_attr( $target ); ?>"
								placeholder="<?php esc_attr_e( 'CCT column name', 'mcp-ai-wpoos-pro' ); ?>"
								class="regular-text" />
						</td>
						<td>
							<button type="button" class="button ezuite-remove-mapping-row"><?php esc_html_e( 'Remove', 'mcp-ai-wpoos-pro' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p>
				<button type="button" class="button" id="ezuite-add-mapping-row"><?php esc_html_e( 'Add Field Mapping', 'mcp-ai-wpoos-pro' ); ?></button>
				<button type="button" class="button button-primary" id="ezuite-generate-default-mapping"><?php esc_html_e( 'Generate Default Mapping', 'mcp-ai-wpoos-pro' ); ?></button>
			</p>

			<script>
			( function() {
				var table = document.getElementById( 'ezuite-field-mapping-table' );

				function makeRow( key, value ) {
					var row = document.createElement( 'tr' );
					row.className = 'ezuite-field-mapping-row';
					row.innerHTML = '<td><input type="text" name="ezuite_field_mapping_keys[]" value="' + key + '" placeholder="<?php echo esc_js( __( 'EZuite field name', 'mcp-ai-wpoos-pro' ) ); ?>" class="regular-text" /></td>' +
						'<td><input type="text" name="ezuite_field_mapping_values[]" value="' + value + '" placeholder="<?php echo esc_js( __( 'CCT column name', 'mcp-ai-wpoos-pro' ) ); ?>" class="regular-text" /></td>' +
						'<td><button type="button" class="button ezuite-remove-mapping-row"><?php echo esc_js( __( 'Remove', 'mcp-ai-wpoos-pro' ) ); ?></button></td>';
					return row;
				}

				document.getElementById( 'ezuite-add-mapping-row' ).addEventListener( 'click', function() {
					table.appendChild( makeRow( '', '' ) );
				});

				// Generate Default Mapping button: pre-fills with inverse of the
				// CCT manager's get_default_field_mapping() (EZuite field → CCT column).
				document.getElementById( 'ezuite-generate-default-mapping' ).addEventListener( 'click', function() {
					var defaults = <?php echo wp_json_encode( WP_MCP_AI_EZuite_CCT_Manager::get_default_field_mapping_static() ); ?>;

					// Clear existing rows.
					table.innerHTML = '';

					// defaults is { cct_column: erp_field }, invert it.
					for ( var cctCol in defaults ) {
						if ( ! Object.prototype.hasOwnProperty.call( defaults, cctCol ) ) {
							continue;
						}
						var erpField = defaults[ cctCol ];
						if ( ! erpField ) {
							continue; // skip entries with empty EZuite field.
						}
						table.appendChild( makeRow( erpField, cctCol ) );
					}

					// Always leave one empty row for custom additions.
					table.appendChild( makeRow( '', '' ) );
				});

				table.addEventListener( 'click', function( e ) {
					if ( e.target && e.target.classList.contains( 'ezuite-remove-mapping-row' ) ) {
						var row = e.target.closest( 'tr' );
						if ( row && table.querySelectorAll( '.ezuite-field-mapping-row' ).length > 1 ) {
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
			'ezuite_inventory' => __( 'EZuite Inventory', 'mcp-ai-wpoos-pro' ),
			'ezuite_sync'      => __( 'EZuite Sync', 'mcp-ai-wpoos-pro' ),
			'ezuite_settings'  => __( 'EZuite Settings', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Render the Sync Log tab.
	 *
	 * Reads recent activity log entries filtered to 'ezuite' source
	 * from the wp_mcp_ai_log system.
	 *
	 * @since 1.9.0
	 */
	protected function render_logs_tab() {
		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$last_sync   = $cct_manager->get_last_sync_time();
		$row_count   = $cct_manager->get_row_count();
		$is_fresh    = $cct_manager->is_fresh();
		$last_error  = get_option( 'wp_mcp_ai_ezuite_last_sync_error', '' );

		// Low-stock counts.
		$low_count = absint( get_option( 'wp_mcp_ai_ezuite_low_stock_count', 0 ) );
		$out_count = absint( get_option( 'wp_mcp_ai_ezuite_out_of_stock_count', 0 ) );

		// Schema version.
		$schema_version = get_option( 'wp_mcp_ai_ezuite_sync_db_version', '1.9.0' );

		// Next scheduled sync.
		$next_sync = '';
		if ( function_exists( 'as_next_scheduled_action' ) && class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			$timestamp = as_next_scheduled_action(
				WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC,
				array(),
				WP_MCP_AI_EZuite_Sync_Engine::GROUP
			);
			$next_sync = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
		}

		// Recent activity log entries filtered to ezuite source.
		$recent_activity = array();
		if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'get_recent_activity_entries' ) ) {
			$recent_activity = WP_MCP_AI_Logger::get_recent_activity_entries( 50, array( 'ezuite' ) );
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
						<th><?php esc_html_e( 'Low Stock Items', 'mcp-ai-wpoos-pro' ); ?></th>
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

			<?php if ( ! empty( $recent_activity ) ) : ?>
				<h3><?php esc_html_e( 'Recent Activity', 'mcp-ai-wpoos-pro' ); ?></h3>
				<table class="widefat fixed striped" style="max-width: 800px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Message', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_activity as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $entry['timestamp'] ) ? $entry['timestamp'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['type'] ) ? $entry['type'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No recent activity log entries.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Sync Now" admin-post action.
	 *
	 * Runs a full EZuite sync for the given connection and
	 * redirects back to the overview tab with a result message.
	 *
	 * @since 1.9.0
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
	 * @since 1.9.0
	 */
	public function handle_sync_dry_run() {
		$this->handle_sync_action( true );
	}

	/**
	 * Common handler for sync and dry-run admin-post actions.
	 *
	 * @since 1.9.0
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
		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-sync-engine.php';
		}

		// Run the sync (or dry-run) for this connection.
		$result = WP_MCP_AI_EZuite_Sync_Engine::run_full_sync( $dry_run, $connection_id );

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
		} else {
			$redirect_url = add_query_arg(
				array( 'sync_ok' => 1 ),
				$redirect_url
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
