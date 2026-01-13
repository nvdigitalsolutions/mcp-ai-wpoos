<?php
/**
 * Admin UI for managing remote WordPress/WooCommerce site connections.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Admin interface for remote site connections.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Remote_Sites_Admin {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Add admin menu page under main NV oOS menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Remote Site Connections', 'wp-mcp-ai-pro' ),
			__( 'Remote Sites', 'wp-mcp-ai-pro' ),
			'manage_options',
			'wp-mcp-ai-remote-sites',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'nv-oos_page_wp-mcp-ai-remote-sites' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-pro-remote-sites',
			WP_MCP_AI_PRO_URL . 'assets/css/remote-sites-admin.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}

	/**
	 * Handle admin actions.
	 *
	 * @since 1.0.0
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'wp-mcp-ai-remote-sites' !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		// Handle delete action.
		if ( 'delete' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'delete_connection_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			$deleted       = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );

			if ( $deleted ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&deleted=1' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found or could not be deleted.', 'wp-mcp-ai-pro' ) ) ) );
			}
			exit;
		}

		// Handle test action.
		if ( 'test' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'test_connection_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			$result       = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&connection_id=' . $connection_id );

			if ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg( 'test_error', rawurlencode( $result->get_error_message() ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( 'test_success', '1', $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle save action.
		if ( isset( $_POST['wp_mcp_ai_pro_save_connection'] ) && isset( $_POST['_wpnonce'] ) ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! wp_verify_nonce( $nonce, 'save_remote_connection' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			// Get connection type first to determine which fields to use.
			$connection_type = isset( $_POST['connection_type'] ) ? sanitize_key( wp_unslash( $_POST['connection_type'] ) ) : 'wordpress';

			// Map connection-type-specific fields to generic field names.
			$api_key         = '';
			$api_secret      = '';
			$client_id       = '';
			$client_secret   = '';

			switch ( $connection_type ) {
				case 'isams':
					$api_key     = isset( $_POST['isams_api_key'] ) ? wp_unslash( $_POST['isams_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret  = isset( $_POST['isams_api_secret'] ) ? wp_unslash( $_POST['isams_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'flowhub':
					$api_key     = isset( $_POST['flowhub_api_key'] ) ? wp_unslash( $_POST['flowhub_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$client_id   = isset( $_POST['flowhub_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_client_id'] ) ) : '';
					break;
				case 'quickbooks':
					$client_id     = isset( $_POST['quickbooks_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['quickbooks_client_id'] ) ) : '';
					$client_secret = isset( $_POST['quickbooks_client_secret'] ) ? wp_unslash( $_POST['quickbooks_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'ezuite_erp':
					$api_key     = isset( $_POST['ezuite_erp_api_key'] ) ? wp_unslash( $_POST['ezuite_erp_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret  = isset( $_POST['ezuite_erp_api_secret'] ) ? wp_unslash( $_POST['ezuite_erp_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
			}

			$connection_data = array(
				'id'              => isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '',
				'name'            => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'url'             => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				'connection_type' => $connection_type,
				'auth_type'       => isset( $_POST['auth_type'] ) ? sanitize_key( wp_unslash( $_POST['auth_type'] ) ) : 'none',
				'username'        => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
				'password'        => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'token'           => isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
				'consumer_secret' => isset( $_POST['consumer_secret'] ) ? wp_unslash( $_POST['consumer_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'api_key'         => $api_key,
				'api_secret'      => $api_secret,
				'client_id'       => $client_id,
				'client_secret'   => $client_secret,
				'app_id'          => isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '',
				'app_secret'      => isset( $_POST['app_secret'] ) ? wp_unslash( $_POST['app_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'location_id'     => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
				'company_id'      => isset( $_POST['company_id'] ) ? sanitize_text_field( wp_unslash( $_POST['company_id'] ) ) : '',
				'sandbox_mode'    => ! empty( $_POST['sandbox_mode'] ),
				'has_woocommerce' => ! empty( $_POST['has_woocommerce'] ),
				'enabled'         => ! empty( $_POST['enabled'] ),
				'cache_ttl'       => isset( $_POST['cache_ttl'] ) ? max( 0, min( 3600, absint( $_POST['cache_ttl'] ) ) ) : 300,
				'test_endpoint'   => isset( $_POST['test_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['test_endpoint'] ) ) : '',
			);

			$result          = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&saved=1' ) );
			}
			exit;
		}
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_admin_page() {
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$editing = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$connection_to_edit = null;

		if ( $editing ) {
			$connection_to_edit = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $editing );

			// If editing but connection not found, show error and list instead.
			if ( null === $connection_to_edit ) {
				$editing = '';
				$_GET['error'] = __( 'Connection not found.', 'wp-mcp-ai-pro' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Remote WordPress/WooCommerce Site Connections', 'wp-mcp-ai-pro' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection saved successfully.', 'wp-mcp-ai-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection deleted successfully.', 'wp-mcp-ai-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $error_message = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_success'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection test successful!', 'wp-mcp-ai-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $test_error = isset( $_GET['test_error'] ) ? sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) : ''; ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( urldecode( $test_error ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $editing || isset( $_GET['add'] ) ) : ?>
				<?php $this->render_edit_form( $connection_to_edit ); ?>
			<?php else : ?>
				<?php $this->render_connections_list( $connections ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render connections list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connections Connections array.
	 */
	protected function render_connections_list( $connections ) {
		?>
		<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&add=1' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Add New Connection', 'wp-mcp-ai-pro' ); ?>
			</a>
			<div>
				<span class="dashicons dashicons-info-outline" style="color: #2271b1; vertical-align: middle;"></span>
				<em style="color: #646970;">
					<?php
					printf(
						/* translators: %s: cache duration */
						esc_html__( 'Caching enabled: %s TTL', 'wp-mcp-ai-pro' ),
						'<strong>5 minutes</strong>'
					);
					?>
				</em>
			</div>
		</div>

		<?php if ( empty( $connections ) ) : ?>
			<p><?php esc_html_e( 'No remote site connections configured yet.', 'wp-mcp-ai-pro' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'URL', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Auth Type', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Health', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-mcp-ai-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $connection_key => $connection ) : ?>
						<?php
						// Use the array key as the connection ID (most reliable).
						// Fall back to $connection['id'] if key is numeric (shouldn't happen, but defensive).
						$connection_id = is_string( $connection_key ) ? $connection_key : ( isset( $connection['id'] ) ? $connection['id'] : '' );
						$health_metrics = WP_MCP_AI_Pro_Remote_Site_Manager::get_health_metrics( $connection_id );
						?>
						<tr>
							<td><strong><?php echo esc_html( $connection['name'] ); ?></strong></td>
							<td>
								<?php
								$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'wordpress';
								
								// Define labels and colors for each connection type
								$type_labels = array(
									'wordpress'   => __( 'WordPress', 'wp-mcp-ai-pro' ),
									'generic'     => __( 'Generic REST API', 'wp-mcp-ai-pro' ),
									'isams'       => __( 'iSAMS', 'wp-mcp-ai-pro' ),
									'flowhub'     => __( 'Flowhub', 'wp-mcp-ai-pro' ),
									'payhere'     => __( 'PayHere', 'wp-mcp-ai-pro' ),
									'quickbooks'  => __( 'QuickBooks', 'wp-mcp-ai-pro' ),
									'ezuite_erp'  => __( 'EZuite ERP', 'wp-mcp-ai-pro' ),
								);
								
								$type_colors = array(
									'wordpress'   => '#2271b1',
									'generic'     => '#50575e',
									'isams'       => '#d63638',
									'flowhub'     => '#00a32a',
									'payhere'     => '#f0b849',
									'quickbooks'  => '#2c9f47',
									'ezuite_erp'  => '#8c50a7',
								);
								
								$type_label = isset( $type_labels[ $connection_type ] ) ? $type_labels[ $connection_type ] : $connection_type;
								$type_badge_color = isset( $type_colors[ $connection_type ] ) ? $type_colors[ $connection_type ] : '#50575e';
								?>
								<span style="display: inline-block; padding: 2px 8px; background: <?php echo esc_attr( $type_badge_color ); ?>; color: white; border-radius: 3px; font-size: 11px;">
									<?php echo esc_html( $type_label ); ?>
								</span>
								<?php if ( 'wordpress' === $connection_type && ! empty( $connection['has_woocommerce'] ) ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #96588a; color: white; border-radius: 3px; font-size: 11px; margin-left: 4px;">WC</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $connection['url'] ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $connection['auth_type'] ) ) ); ?></td>
							<td>
								<?php
								$status_colors = array(
									'healthy' => '#46b450',
									'degraded' => '#ffb900',
									'unhealthy' => '#dc3232',
									'unknown' => '#8c8f94',
								);
								$status_color = isset( $status_colors[ $health_metrics['status'] ] ) ? $status_colors[ $health_metrics['status'] ] : $status_colors['unknown'];
								?>
								<span style="color: <?php echo esc_attr( $status_color ); ?>;">●</span>
								<?php
								if ( $health_metrics['request_count'] > 0 ) {
									printf(
										/* translators: 1: success rate, 2: request count */
										esc_html__( '%1$s%% (%2$d reqs)', 'wp-mcp-ai-pro' ),
										esc_html( $health_metrics['success_rate'] ),
										absint( $health_metrics['request_count'] )
									);
								} else {
									esc_html_e( 'No data', 'wp-mcp-ai-pro' );
								}
								?>
							</td>
							<td>
								<?php if ( ! empty( $connection['enabled'] ) ) : ?>
									<span style="color: green;">●</span> <?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?>
								<?php else : ?>
									<span style="color: red;">●</span> <?php esc_html_e( 'Disabled', 'wp-mcp-ai-pro' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id ) ); ?>">
									<?php esc_html_e( 'Edit', 'wp-mcp-ai-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=test&connection_id=' . $connection_id ), 'test_connection_' . $connection_id ) ); ?>">
									<?php esc_html_e( 'Test', 'wp-mcp-ai-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=delete&connection_id=' . $connection_id ), 'delete_connection_' . $connection_id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this connection?', 'wp-mcp-ai-pro' ); ?>');" style="color: #b32d2e;">
									<?php esc_html_e( 'Delete', 'wp-mcp-ai-pro' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'Performance & Reliability Features', 'wp-mcp-ai-pro' ); ?></h3>
				<p style="margin-bottom: 15px;">
					<?php esc_html_e( 'Remote site requests include advanced features for performance, reliability, and monitoring.', 'wp-mcp-ai-pro' ); ?>
				</p>
				<table class="form-table" style="background: white; border: 1px solid #ddd;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Caching', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'GET requests are cached to reduce redundant API calls. Default: 5 minutes. Configure per-connection in connection settings above.', 'wp-mcp-ai-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate Limiting', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: rate limit, 2: filter name */
									esc_html__( 'Limited to %1$s per user to prevent abuse. Customize via %2$s filter.', 'wp-mcp-ai-pro' ),
									'<code>30 requests/minute</code>',
									'<code>wp_mcp_ai_pro_remote_wp_rate_limit</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retry Logic', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Automatic retry with exponential backoff (3 attempts) for transient errors. Improves reliability.', 'wp-mcp-ai-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Deduplication', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Prevents duplicate simultaneous requests to the same endpoint. Reduces load on remote servers.', 'wp-mcp-ai-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Compression Support', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Requests accept gzip/deflate compression to reduce bandwidth and improve speed for large responses.', 'wp-mcp-ai-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Health Monitoring', 'wp-mcp-ai-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Tracks success rates, response times, and connection health. View health status in the "Health" column above.', 'wp-mcp-ai-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p style="margin-top: 15px;">
					<strong><?php esc_html_e( 'Developer Note:', 'wp-mcp-ai-pro' ); ?></strong>
					<?php
					printf(
						/* translators: %s: documentation link */
						esc_html__( 'Use filters to customize caching, rate limits, and retry behavior. See %s for details.', 'wp-mcp-ai-pro' ),
						'<a href="' . esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/tools/remote-wp-connection.md' ) . '" target="_blank">' . esc_html__( 'documentation', 'wp-mcp-ai-pro' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render edit/add form.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $connection Connection data or null for new connection.
	 */
	protected function render_edit_form( $connection ) {
		$is_edit = ! empty( $connection );
		?>
		<h2><?php echo $is_edit ? esc_html__( 'Edit Connection', 'wp-mcp-ai-pro' ) : esc_html__( 'Add New Connection', 'wp-mcp-ai-pro' ); ?></h2>

		<form method="post" action="">
			<?php wp_nonce_field( 'save_remote_connection', '_wpnonce' ); ?>

			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $connection['id'] ); ?>">
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="name"><?php esc_html_e( 'Connection Name', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="name" id="name" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['name'] ) : ''; ?>" required>
						<p class="description"><?php esc_html_e( 'A friendly name to identify this connection.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="url"><?php esc_html_e( 'Site URL', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="url" id="url" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['url'] ) : ''; ?>" placeholder="https://example.com" required>
						<p class="description"><?php esc_html_e( 'The full URL of the remote site (including https://).', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="connection_type"><?php esc_html_e( 'Connection Type', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php
						$connection_type = $is_edit && isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'wordpress';
						?>
						<select name="connection_type" id="connection_type" class="regular-text" required>
							<option value="wordpress" <?php selected( $connection_type, 'wordpress' ); ?>>
								<?php esc_html_e( 'WordPress / WooCommerce', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="generic" <?php selected( $connection_type, 'generic' ); ?>>
								<?php esc_html_e( 'Generic REST API', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="isams" <?php selected( $connection_type, 'isams' ); ?>>
								<?php esc_html_e( 'iSAMS (School Management)', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="flowhub" <?php selected( $connection_type, 'flowhub' ); ?>>
								<?php esc_html_e( 'Flowhub (POS/Retail)', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="payhere" <?php selected( $connection_type, 'payhere' ); ?>>
								<?php esc_html_e( 'PayHere (Payment Gateway)', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="quickbooks" <?php selected( $connection_type, 'quickbooks' ); ?>>
								<?php esc_html_e( 'QuickBooks (Accounting)', 'wp-mcp-ai-pro' ); ?>
							</option>
							<option value="ezuite_erp" <?php selected( $connection_type, 'ezuite_erp' ); ?>>
								<?php esc_html_e( 'EZuite ERP (Inventory)', 'wp-mcp-ai-pro' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select the type of connection. Each type has specific authentication requirements and field configurations.', 'wp-mcp-ai-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="auth_type"><?php esc_html_e( 'Authentication Type', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<select name="auth_type" id="auth_type" onchange="toggleAuthFields(this.value)">
							<option value="none" <?php selected( $is_edit ? $connection['auth_type'] : 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wp-mcp-ai-pro' ); ?></option>
							<option value="custom_header" <?php selected( $is_edit ? $connection['auth_type'] : '', 'custom_header' ); ?>><?php esc_html_e( 'Custom Header', 'wp-mcp-ai-pro' ); ?></option>
							<option value="application_password" <?php selected( $is_edit ? $connection['auth_type'] : '', 'application_password' ); ?>><?php esc_html_e( 'Application Password', 'wp-mcp-ai-pro' ); ?></option>
							<option value="basic_auth" <?php selected( $is_edit ? $connection['auth_type'] : '', 'basic_auth' ); ?>><?php esc_html_e( 'Basic Auth', 'wp-mcp-ai-pro' ); ?></option>
							<option value="jwt" <?php selected( $is_edit ? $connection['auth_type'] : '', 'jwt' ); ?>><?php esc_html_e( 'JWT Token', 'wp-mcp-ai-pro' ); ?></option>
							<option value="woocommerce" <?php selected( $is_edit ? $connection['auth_type'] : '', 'woocommerce' ); ?>><?php esc_html_e( 'WooCommerce API Keys (ck_/cs_)', 'wp-mcp-ai-pro' ); ?></option>
						</select>
					</td>
				</tr>

				<tr id="username_field" style="display: none;">
					<th scope="row">
						<label for="username"><?php esc_html_e( 'Username', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="username" id="username" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['username'] ) : ''; ?>" autocomplete="off">
					</td>
				</tr>

				<tr id="password_field" style="display: none;">
					<th scope="row">
						<label for="password"><?php esc_html_e( 'Password / Application Password', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="password" id="password" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing password.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="token_field" style="display: none;">
					<th scope="row">
						<label for="token"><?php esc_html_e( 'JWT Token', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<textarea name="token" id="token" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing token.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="consumer_key_field" style="display: none;">
					<th scope="row">
						<label for="consumer_key"><?php esc_html_e( 'Consumer Key', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="consumer_key" id="consumer_key" class="regular-text" value="" autocomplete="off" placeholder="ck_...">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing consumer key.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="consumer_secret_field" style="display: none;">
					<th scope="row">
						<label for="consumer_secret"><?php esc_html_e( 'Consumer Secret', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="consumer_secret" id="consumer_secret" class="regular-text" value="" autocomplete="new-password" placeholder="cs_...">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing consumer secret.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Type-specific fields for iSAMS -->
				<tr class="isams-only-field" style="display: none;">
					<th scope="row">
						<label for="isams_api_key"><?php esc_html_e( 'API Key', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="isams_api_key" id="isams_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="isams-only-field" style="display: none;">
					<th scope="row">
						<label for="isams_api_secret"><?php esc_html_e( 'API Secret', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="isams_api_secret" id="isams_api_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API secret.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Type-specific fields for Flowhub -->
				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_api_key"><?php esc_html_e( 'API Key (key header)', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="flowhub_api_key" id="flowhub_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'wp-mcp-ai-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Flowhub API key. Sent as "key" header in requests.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_client_id"><?php esc_html_e( 'Client ID (clientId header)', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="flowhub_client_id" id="flowhub_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Flowhub client identifier. Sent as "clientId" header in requests.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="location_id"><?php esc_html_e( 'Location ID (Optional)', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="location_id" id="location_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['location_id'] ) ? esc_attr( $connection['location_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: The Flowhub location/dispensary ID for filtering requests.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for PayHere -->
				<tr class="payhere-only-field" style="display: none;">
					<th scope="row">
						<label for="app_id"><?php esc_html_e( 'App ID', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="app_id" id="app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing App ID.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="payhere-only-field" style="display: none;">
					<th scope="row">
						<label for="app_secret"><?php esc_html_e( 'App Secret', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="app_secret" id="app_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing App Secret.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="payhere-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox Mode', 'wp-mcp-ai-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'Enable sandbox/test mode', 'wp-mcp-ai-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use PayHere sandbox environment for testing.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for QuickBooks -->
				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="quickbooks_client_id"><?php esc_html_e( 'Client ID', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="quickbooks_client_id" id="quickbooks_client_id" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing client ID.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="quickbooks_client_secret"><?php esc_html_e( 'Client Secret / OAuth Token', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<textarea name="quickbooks_client_secret" id="quickbooks_client_secret" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing client secret/token. For OAuth, paste the complete token here.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="company_id"><?php esc_html_e( 'Company ID (Realm ID)', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="company_id" id="company_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['company_id'] ) ? esc_attr( $connection['company_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'The QuickBooks company/realm ID.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for EZuite ERP -->
				<tr class="ezuite_erp-only-field" style="display: none;">
					<th scope="row">
						<label for="ezuite_erp_api_key"><?php esc_html_e( 'API Key', 'wp-mcp-ai-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="ezuite_erp_api_key" id="ezuite_erp_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'wp-mcp-ai-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your EZuite API key provided by EZuite.', 'wp-mcp-ai-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="wordpress-only-field">
					<th scope="row"><?php esc_html_e( 'WooCommerce', 'wp-mcp-ai-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="has_woocommerce" value="1" <?php checked( $is_edit && ! empty( $connection['has_woocommerce'] ) ); ?>>
							<?php esc_html_e( 'This site has WooCommerce installed', 'wp-mcp-ai-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enable to access WooCommerce products, orders, and other data.', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<tr class="generic-only-field" style="display:none;">
					<th scope="row">
						<label for="test_endpoint"><?php esc_html_e( 'Test Endpoint', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="test_endpoint" id="test_endpoint" class="regular-text" value="<?php echo $is_edit && isset( $connection['test_endpoint'] ) ? esc_attr( $connection['test_endpoint'] ) : '/'; ?>" placeholder="/">
						<p class="description">
							<?php esc_html_e( 'API endpoint to use for connection testing (e.g., /api/health or /). Default: /', 'wp-mcp-ai-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wp-mcp-ai-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! $is_edit || ! empty( $connection['enabled'] ) ); ?>>
							<?php esc_html_e( 'Connection enabled', 'wp-mcp-ai-pro' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<input type="number" name="cache_ttl" id="cache_ttl" class="small-text" value="<?php echo $is_edit && isset( $connection['cache_ttl'] ) ? esc_attr( $connection['cache_ttl'] ) : '300'; ?>" min="0" max="3600">
						<p class="description">
							<?php esc_html_e( 'How long to cache GET requests (in seconds). Default: 300 (5 minutes). Set to 0 to disable caching for this connection.', 'wp-mcp-ai-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="wp_mcp_ai_pro_save_connection" class="button button-primary" value="<?php echo $is_edit ? esc_attr__( 'Update Connection', 'wp-mcp-ai-pro' ) : esc_attr__( 'Add Connection', 'wp-mcp-ai-pro' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'wp-mcp-ai-pro' ); ?>
				</a>
			</p>
		</form>

		<script type="text/javascript">
		function toggleAuthFields(authType) {
			var usernameField = document.getElementById('username_field');
			var passwordField = document.getElementById('password_field');
			var tokenField = document.getElementById('token_field');
			var consumerKeyField = document.getElementById('consumer_key_field');
			var consumerSecretField = document.getElementById('consumer_secret_field');

			usernameField.style.display = 'none';
			passwordField.style.display = 'none';
			tokenField.style.display = 'none';
			consumerKeyField.style.display = 'none';
			consumerSecretField.style.display = 'none';

			if (authType === 'application_password' || authType === 'basic_auth') {
				usernameField.style.display = 'table-row';
				passwordField.style.display = 'table-row';
			} else if (authType === 'jwt') {
				tokenField.style.display = 'table-row';
			} else if (authType === 'woocommerce') {
				consumerKeyField.style.display = 'table-row';
				consumerSecretField.style.display = 'table-row';
			}
		}

		function toggleConnectionTypeFields(connectionType) {
			var wordpressFields = document.querySelectorAll('.wordpress-only-field');
			var genericFields = document.querySelectorAll('.generic-only-field');
			var isamsFields = document.querySelectorAll('.isams-only-field');
			var flowhubFields = document.querySelectorAll('.flowhub-only-field');
			var payhereFields = document.querySelectorAll('.payhere-only-field');
			var quickbooksFields = document.querySelectorAll('.quickbooks-only-field');
			var ezuiteFields = document.querySelectorAll('.ezuite_erp-only-field');
			var authTypeSelect = document.getElementById('auth_type');

			// Hide all type-specific fields first
			wordpressFields.forEach(function(field) {
				field.style.display = 'none';
			});
			genericFields.forEach(function(field) {
				field.style.display = 'none';
			});
			isamsFields.forEach(function(field) {
				field.style.display = 'none';
			});
			flowhubFields.forEach(function(field) {
				field.style.display = 'none';
			});
			payhereFields.forEach(function(field) {
				field.style.display = 'none';
			});
			quickbooksFields.forEach(function(field) {
				field.style.display = 'none';
			});
			ezuiteFields.forEach(function(field) {
				field.style.display = 'none';
			});

			// Show fields for selected connection type
			if (connectionType === 'wordpress') {
				wordpressFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
			} else if (connectionType === 'generic') {
				genericFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
			} else if (connectionType === 'isams') {
				isamsFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
			} else if (connectionType === 'flowhub') {
				flowhubFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Flowhub uses custom header authentication
				authTypeSelect.value = 'custom_header';
			} else if (connectionType === 'payhere') {
				payhereFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
			} else if (connectionType === 'quickbooks') {
				quickbooksFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
			} else if (connectionType === 'ezuite_erp') {
				ezuiteFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// EZuite ERP uses custom header authentication
				authTypeSelect.value = 'custom_header';
			}
		}

		// Initialize on page load
		document.addEventListener('DOMContentLoaded', function() {
			var authType = document.getElementById('auth_type').value;
			var connectionType = document.getElementById('connection_type').value;

			toggleAuthFields(authType);
			toggleConnectionTypeFields(connectionType);

			// Add event listener for connection type changes
			document.getElementById('connection_type').addEventListener('change', function() {
				toggleConnectionTypeFields(this.value);
			});
		});
		</script>
		<?php
	}
}

// Initialize the admin interface.
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Remote_Sites_Admin();
}
