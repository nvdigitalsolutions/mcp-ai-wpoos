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
			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_connection_' . $_GET['connection_id'] ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			$connection_id = sanitize_key( $_GET['connection_id'] );
			$deleted = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );

			if ( $deleted ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&deleted=1' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . urlencode( __( 'Connection not found or could not be deleted.', 'wp-mcp-ai-pro' ) ) ) );
			}
			exit;
		}

		// Handle test action.
		if ( 'test' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'test_connection_' . $_GET['connection_id'] ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			$connection_id = sanitize_key( $_GET['connection_id'] );
			$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&connection_id=' . $connection_id );

			if ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg( 'test_error', urlencode( $result->get_error_message() ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( 'test_success', '1', $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle save action.
		if ( isset( $_POST['wp_mcp_ai_pro_save_connection'] ) && isset( $_POST['_wpnonce'] ) ) {
			if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'save_remote_connection' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'wp-mcp-ai-pro' ) );
			}

			$connection_data = array(
				'id'              => isset( $_POST['connection_id'] ) ? sanitize_key( $_POST['connection_id'] ) : '',
				'name'            => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',
				'url'             => isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '',
				'auth_type'       => isset( $_POST['auth_type'] ) ? sanitize_key( $_POST['auth_type'] ) : 'none',
				'username'        => isset( $_POST['username'] ) ? sanitize_text_field( $_POST['username'] ) : '',
				'password'        => isset( $_POST['password'] ) ? $_POST['password'] : '',
				'token'           => isset( $_POST['token'] ) ? $_POST['token'] : '',
				'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( $_POST['consumer_key'] ) : '',
				'consumer_secret' => isset( $_POST['consumer_secret'] ) ? $_POST['consumer_secret'] : '',
				'has_woocommerce' => ! empty( $_POST['has_woocommerce'] ),
				'enabled'         => ! empty( $_POST['enabled'] ),
			);

			$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . urlencode( $result->get_error_message() ) ) );
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

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection deleted successfully.', 'wp-mcp-ai-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $_GET['error'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_success'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection test successful!', 'wp-mcp-ai-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( urldecode( $_GET['test_error'] ) ); ?></p>
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
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&add=1' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Add New Connection', 'wp-mcp-ai-pro' ); ?>
			</a>
		</p>

		<?php if ( empty( $connections ) ) : ?>
			<p><?php esc_html_e( 'No remote site connections configured yet.', 'wp-mcp-ai-pro' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'URL', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Auth Type', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'WooCommerce', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-mcp-ai-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-mcp-ai-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $connection ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $connection['name'] ); ?></strong></td>
							<td><?php echo esc_html( $connection['url'] ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $connection['auth_type'] ) ) ); ?></td>
							<td><?php echo ! empty( $connection['has_woocommerce'] ) ? '✓' : '—'; ?></td>
							<td>
								<?php if ( ! empty( $connection['enabled'] ) ) : ?>
									<span style="color: green;">●</span> <?php esc_html_e( 'Enabled', 'wp-mcp-ai-pro' ); ?>
								<?php else : ?>
									<span style="color: red;">●</span> <?php esc_html_e( 'Disabled', 'wp-mcp-ai-pro' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Edit', 'wp-mcp-ai-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=test&connection_id=' . $connection['id'] ), 'test_connection_' . $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Test', 'wp-mcp-ai-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=delete&connection_id=' . $connection['id'] ), 'delete_connection_' . $connection['id'] ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this connection?', 'wp-mcp-ai-pro' ); ?>');" style="color: #b32d2e;">
									<?php esc_html_e( 'Delete', 'wp-mcp-ai-pro' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
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
						<p class="description"><?php esc_html_e( 'The full URL of the remote WordPress site (including https://).', 'wp-mcp-ai-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="auth_type"><?php esc_html_e( 'Authentication Type', 'wp-mcp-ai-pro' ); ?></label>
					</th>
					<td>
						<select name="auth_type" id="auth_type" onchange="toggleAuthFields(this.value)">
							<option value="none" <?php selected( $is_edit ? $connection['auth_type'] : 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wp-mcp-ai-pro' ); ?></option>
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

				<tr>
					<th scope="row"><?php esc_html_e( 'WooCommerce', 'wp-mcp-ai-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="has_woocommerce" value="1" <?php checked( $is_edit && ! empty( $connection['has_woocommerce'] ) ); ?>>
							<?php esc_html_e( 'This site has WooCommerce installed', 'wp-mcp-ai-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enable to access WooCommerce products, orders, and other data.', 'wp-mcp-ai-pro' ); ?></p>
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

		// Initialize on page load
		document.addEventListener('DOMContentLoaded', function() {
			var authType = document.getElementById('auth_type').value;
			toggleAuthFields(authType);
		});
		</script>
		<?php
	}
}

// Initialize the admin interface.
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Remote_Sites_Admin();
}
