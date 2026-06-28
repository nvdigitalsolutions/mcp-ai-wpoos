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
	 * {@inheritdoc}
	 */
	protected function render_overview_tab() {
		$cct_manager   = new WP_MCP_AI_FlowHub_CCT_Manager();
		$last_sync     = $cct_manager->get_last_sync_time();
		$row_count     = $cct_manager->get_row_count();
		$is_fresh      = $cct_manager->is_fresh();
		$last_error    = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$settings      = get_option( $this->option_name, array() );
		$is_configured = ! empty( $settings['client_id'] ) && ! empty( $settings['api_key'] );
		$wc_active     = class_exists( 'WooCommerce' );
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'FlowHub Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="toolkit-description">
				<p><?php esc_html_e( 'Synchronize FlowHub dispensary inventory with WooCommerce. The toolkit maintains a local CCT cache so AI assistants can query inventory instantly without hitting the FlowHub API.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat fixed striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<th style="width: 200px;"><?php esc_html_e( 'Connection Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $is_configured ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Configured', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10008; <?php esc_html_e( 'Not Configured', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WooCommerce', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $wc_active ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: red;">&#10008; <?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Sync', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( ! empty( $last_sync ) ? $last_sync : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cached Items', 'mcp-ai-wpoos-pro' ); ?></th>
						<td><?php echo esc_html( $row_count ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cache Freshness', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<?php if ( $is_fresh ) : ?>
								<span style="color: green;">&#10004; <?php esc_html_e( 'Fresh', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color: orange;">&#9888; <?php esc_html_e( 'Stale', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $last_error ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last Error', 'mcp-ai-wpoos-pro' ); ?></th>
						<td style="color: red;"><?php echo esc_html( $last_error ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p>
				<button type="button" id="wp-mcp-ai-flowhub-sync-now" class="button button-primary">
					<?php esc_html_e( 'Sync Now', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<span id="wp-mcp-ai-flowhub-sync-status" style="margin-left: 10px; display: none;">
					<?php esc_html_e( 'Syncing...', 'mcp-ai-wpoos-pro' ); ?>
				</span>
			</p>

			<h3><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'flowhub_inventory', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Search and filter inventory items', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_products', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Browse product catalog and categories', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_locations', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'List dispensary locations with stock counts', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_sync', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Trigger sync and check status', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><strong><?php esc_html_e( 'flowhub_settings', 'mcp-ai-wpoos-pro' ); ?></strong> — <?php esc_html_e( 'Manage toolkit configuration', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>

		<script>
		( function() {
			var btn = document.getElementById( 'wp-mcp-ai-flowhub-sync-now' );
			var status = document.getElementById( 'wp-mcp-ai-flowhub-sync-status' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function() {
				btn.disabled = true;
				status.style.display = 'inline';
				location.href = '<?php echo esc_url( admin_url( 'admin-post.php?action=wp_mcp_ai_flowhub_sync_now' ) ); ?>';
			});
		} )();
		</script>
		<?php
	}

	/**
	 * {@inheritdoc}
	 */
	protected function render_configuration_tab() {
		$settings = get_option( $this->option_name, array() );
		?>
		<div class="toolkit-configuration">
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
							$current_direction = isset( $settings['sync_direction'] ) ? $settings['sync_direction'] : 'flowhub_to_woo';
							$directions        = array(
								'flowhub_to_woo' => __( 'FlowHub → WooCommerce only', 'mcp-ai-wpoos-pro' ),
								'bidirectional'  => __( 'Bidirectional', 'mcp-ai-wpoos-pro' ),
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
		</div>
		<?php
	}
}
