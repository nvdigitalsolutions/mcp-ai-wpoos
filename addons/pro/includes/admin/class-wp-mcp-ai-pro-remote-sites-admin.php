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
		// Priority 30 ensures this runs after Pro Dashboard menu registration (priority 25).
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_google_oauth_host' ) );
	}

	/**
	 * Allow Google OAuth host for redirects.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public function allow_google_oauth_host( $hosts ) {
		$hosts[] = 'accounts.google.com';
		return $hosts;
	}

	/**
	 * Add admin menu page under NV oOS Pro menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Remote Site Connections', 'mcp-ai-wpoos-pro' ),
			__( 'Remote Sites', 'mcp-ai-wpoos-pro' ),
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
		if ( 'nvoos-pro-dashboard_page_wp-mcp-ai-remote-sites' !== $hook ) {
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
		// Check for OAuth handler parameter (used instead of 'action' to avoid Google OAuth restrictions).
		$oauth_handler = isset( $_GET['oauth_handler'] ) ? sanitize_key( $_GET['oauth_handler'] ) : '';

		// Handle delete action.
		if ( 'delete' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'delete_connection_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			$deleted = WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $connection_id );

			if ( $deleted ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&deleted=1' ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found or could not be deleted.', 'mcp-ai-wpoos-pro' ) ) ) );
			}
			exit;
		}

		// Handle test action.
		if ( 'test' === $action && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'test_connection_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&connection_id=' . $connection_id );

			if ( is_wp_error( $result ) ) {
				$redirect_url = add_query_arg( 'test_error', rawurlencode( $result->get_error_message() ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( 'test_success', '1', $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Gmail OAuth connect handler removed - button now links directly to Google.
		// OAuth state and connection ID are stored in transient when button is rendered.

		// Handle Gmail OAuth callback action.
		if ( 'gmail_oauth_callback' === $oauth_handler ) {
			$this->handle_gmail_oauth_callback();
		}

		// Handle Google Drive OAuth connect action.
		if ( 'google_drive_oauth_connect' === $oauth_handler && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'google_drive_oauth_connect_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			$this->handle_google_drive_oauth_start( $connection_id );
		}

		// Handle Google Drive OAuth callback action.
		if ( 'google_drive_oauth_callback' === $oauth_handler ) {
			$this->handle_google_drive_oauth_callback();
		}

		// Handle save action.
		if ( isset( $_POST['wp_mcp_ai_pro_save_connection'] ) && isset( $_POST['_wpnonce'] ) ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! wp_verify_nonce( $nonce, 'save_remote_connection' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			// Get connection type first to determine which fields to use.
			$connection_type = isset( $_POST['connection_type'] ) ? sanitize_key( wp_unslash( $_POST['connection_type'] ) ) : 'WordPress';

			// Map connection-type-specific fields to generic field names.
			$api_key       = '';
			$api_secret    = '';
			$client_id     = '';
			$client_secret = '';
			$refresh_token = '';
			$user_email    = '';

			switch ( $connection_type ) {
				case 'mesh_peer':
					$api_key = isset( $_POST['mesh_inbound_api_key'] ) ? wp_unslash( $_POST['mesh_inbound_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'isams':
					$api_key    = isset( $_POST['isams_api_key'] ) ? wp_unslash( $_POST['isams_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['isams_api_secret'] ) ? wp_unslash( $_POST['isams_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'flowhub':
					$api_key   = isset( $_POST['flowhub_api_key'] ) ? wp_unslash( $_POST['flowhub_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$client_id = isset( $_POST['flowhub_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_client_id'] ) ) : '';
					break;
				case 'quickbooks':
					$client_id     = isset( $_POST['quickbooks_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['quickbooks_client_id'] ) ) : '';
					$client_secret = isset( $_POST['quickbooks_client_secret'] ) ? wp_unslash( $_POST['quickbooks_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'ezuite_erp':
					$api_key    = isset( $_POST['ezuite_erp_api_key'] ) ? wp_unslash( $_POST['ezuite_erp_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['ezuite_erp_api_secret'] ) ? wp_unslash( $_POST['ezuite_erp_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'gmail':
					$client_id     = isset( $_POST['gmail_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gmail_client_id'] ) ) : '';
					$client_secret = isset( $_POST['gmail_client_secret'] ) ? wp_unslash( $_POST['gmail_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$refresh_token = isset( $_POST['gmail_refresh_token'] ) ? wp_unslash( $_POST['gmail_refresh_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$user_email    = isset( $_POST['gmail_user_email'] ) ? sanitize_email( wp_unslash( $_POST['gmail_user_email'] ) ) : '';
					break;
				case 'google_drive':
					$client_id     = isset( $_POST['google_drive_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_drive_client_id'] ) ) : '';
					$client_secret = isset( $_POST['google_drive_client_secret'] ) ? wp_unslash( $_POST['google_drive_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$refresh_token = isset( $_POST['google_drive_refresh_token'] ) ? wp_unslash( $_POST['google_drive_refresh_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$user_email    = isset( $_POST['google_drive_user_email'] ) ? sanitize_email( wp_unslash( $_POST['google_drive_user_email'] ) ) : '';
					break;
				case 'telegram':
					$api_key   = isset( $_POST['telegram_bot_token'] ) ? wp_unslash( $_POST['telegram_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'whatsapp':
					$api_key   = isset( $_POST['whatsapp_access_token'] ) ? wp_unslash( $_POST['whatsapp_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'slack':
					$api_key    = isset( $_POST['slack_bot_token'] ) ? wp_unslash( $_POST['slack_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['slack_signing_secret'] ) ? wp_unslash( $_POST['slack_signing_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'discord':
					$api_key = isset( $_POST['discord_bot_token'] ) ? wp_unslash( $_POST['discord_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'microsoft_teams':
					// Teams uses app_id and app_secret (handled separately below)
					$api_secret = isset( $_POST['teams_app_password'] ) ? wp_unslash( $_POST['teams_app_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'facebook_messenger':
					$api_key    = isset( $_POST['messenger_page_access_token'] ) ? wp_unslash( $_POST['messenger_page_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['messenger_app_secret'] ) ? wp_unslash( $_POST['messenger_app_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'webchat':
					// WebChat uses connection_id (handled separately as p2p_connection_id below)
					$api_secret = isset( $_POST['webchat_encryption_key'] ) ? wp_unslash( $_POST['webchat_encryption_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
			}

			// For FlowHub connections, always use the fixed API URL and custom_header auth
			$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
			$auth_type = isset( $_POST['auth_type'] ) ? sanitize_key( wp_unslash( $_POST['auth_type'] ) ) : 'none';

			if ( 'flowhub' === $connection_type ) {
				$url       = 'https://api.flowhub.co';
				$auth_type = 'custom_header';
			}

			// For EZuite ERP connections, always use custom_header auth
			if ( 'ezuite_erp' === $connection_type ) {
				$auth_type = 'custom_header';
			}

			// For Gmail connections, always use the Gmail API URL
			if ( 'gmail' === $connection_type ) {
				$url       = 'https://gmail.googleapis.com';
				$auth_type = 'none'; // Gmail uses OAuth, not standard auth types
			}

			// For Google Drive connections, always use the Google Drive API URL
			if ( 'google_drive' === $connection_type ) {
				$url       = 'https://www.googleapis.com/drive/v3';
				$auth_type = 'none'; // Google Drive uses OAuth, not standard auth types
			}

			// For chat channel connections, set appropriate API URLs
			if ( 'telegram' === $connection_type ) {
				$url       = 'https://api.telegram.org';
				$auth_type = 'none';
			}

			if ( 'whatsapp' === $connection_type ) {
				$url       = 'https://graph.facebook.com/v18.0';
				$auth_type = 'none';
			}

			if ( 'slack' === $connection_type ) {
				$url       = 'https://slack.com/api';
				$auth_type = 'none';
			}

			if ( 'discord' === $connection_type ) {
				$url       = 'https://discord.com/api/v10';
				$auth_type = 'none';
			}

			if ( 'microsoft_teams' === $connection_type ) {
				$url       = 'https://smba.trafficmanager.net/apis';
				$auth_type = 'none';
			}

			if ( 'facebook_messenger' === $connection_type ) {
				$url       = 'https://graph.facebook.com/v18.0';
				$auth_type = 'none';
			}

			if ( 'webchat' === $connection_type ) {
				$url       = home_url( '/wp-json/mcp-ai/v1/webhooks/webchat' );
				$auth_type = 'none';
			}

			// For mesh peer connections, use custom_header auth with mesh API key
			if ( 'mesh_peer' === $connection_type ) {
				$auth_type = 'custom_header';
			}

			$connection_data = array(
				'id'              => isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '',
				'name'            => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'url'             => $url,
				'connection_type' => $connection_type,
				'auth_type'       => $auth_type,
				'username'        => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
				'password'        => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'token'           => isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'consumer_key'    => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
				'consumer_secret' => isset( $_POST['consumer_secret'] ) ? wp_unslash( $_POST['consumer_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'api_key'         => $api_key,
				'api_secret'      => $api_secret,
				'client_id'       => $client_id,
				'client_secret'   => $client_secret,
				'app_id'          => isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : ( isset( $_POST['teams_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_app_id'] ) ) : '' ),
				'app_secret'      => isset( $_POST['app_secret'] ) ? wp_unslash( $_POST['app_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'location_id'     => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
				'company_id'      => isset( $_POST['company_id'] ) ? sanitize_text_field( wp_unslash( $_POST['company_id'] ) ) : '',
				'sandbox_mode'    => ! empty( $_POST['sandbox_mode'] ),
				'has_woocommerce' => ! empty( $_POST['has_woocommerce'] ),
				'enabled'         => ! empty( $_POST['enabled'] ),
				'cache_ttl'       => isset( $_POST['cache_ttl'] ) ? max( 0, min( 3600, absint( $_POST['cache_ttl'] ) ) ) : 300,
				'test_endpoint'   => isset( $_POST['test_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['test_endpoint'] ) ) : '',
				// Gmail-specific fields.
				'refresh_token'   => $refresh_token,
				'user_email'      => $user_email,
				// Google Drive-specific fields.
				'folder_id'       => isset( $_POST['google_drive_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_drive_folder_id'] ) ) : '',
				// Telegram-specific fields.
				'bot_username'    => isset( $_POST['telegram_bot_username'] ) ? sanitize_text_field( wp_unslash( $_POST['telegram_bot_username'] ) ) : '',
				// WhatsApp-specific fields.
				'phone_number_id' => isset( $_POST['whatsapp_phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_phone_number_id'] ) ) : '',
				'business_account_id' => isset( $_POST['whatsapp_business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_business_account_id'] ) ) : '',
				'verify_token'    => isset( $_POST['whatsapp_verify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_verify_token'] ) ) : ( isset( $_POST['messenger_verify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_verify_token'] ) ) : '' ),
				// Slack-specific fields.
				'workspace_id'    => isset( $_POST['slack_workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slack_workspace_id'] ) ) : '',
				// Discord-specific fields.
				'application_id'  => isset( $_POST['discord_application_id'] ) ? sanitize_text_field( wp_unslash( $_POST['discord_application_id'] ) ) : '',
				'guild_id'        => isset( $_POST['discord_guild_id'] ) ? sanitize_text_field( wp_unslash( $_POST['discord_guild_id'] ) ) : '',
				// Microsoft Teams-specific fields.
				'tenant_id'       => isset( $_POST['teams_tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_tenant_id'] ) ) : '',
				// Facebook Messenger-specific fields.
				'page_id'         => isset( $_POST['messenger_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_page_id'] ) ) : '',
				// WebChat-specific fields.
				'p2p_connection_id' => isset( $_POST['webchat_connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webchat_connection_id'] ) ) : '',
			);

			$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

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
		$connections        = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$editing            = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
		$connection_to_edit = null;

		if ( $editing ) {
			$connection_to_edit = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $editing );

			// If editing but connection not found, show error and list instead.
			if ( null === $connection_to_edit ) {
				$editing       = '';
				$_GET['error'] = __( 'Connection not found.', 'mcp-ai-wpoos-pro' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Remote Site Connections', 'mcp-ai-wpoos-pro' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection saved successfully.', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection deleted successfully.', 'mcp-ai-wpoos-pro' ); ?></p>
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
					<p><?php esc_html_e( 'Connection test successful!', 'mcp-ai-wpoos-pro' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $test_error = isset( $_GET['test_error'] ) ? sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) : ''; ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( urldecode( $test_error ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['oauth_success'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $oauth_success = isset( $_GET['oauth_success'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_success'] ) ) : ''; ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( urldecode( $oauth_success ) ); ?></p>
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
				<?php esc_html_e( 'Add New Connection', 'mcp-ai-wpoos-pro' ); ?>
			</a>
			<div>
				<span class="dashicons dashicons-info-outline" style="color: #2271b1; vertical-align: middle;"></span>
				<em style="color: #646970;">
					<?php
					printf(
						/* translators: %s: cache duration */
						esc_html__( 'Caching enabled: %s TTL', 'mcp-ai-wpoos-pro' ),
						'<strong>5 minutes</strong>'
					);
					?>
				</em>
			</div>
		</div>

		<?php if ( empty( $connections ) ) : ?>
			<p><?php esc_html_e( 'No remote site connections configured yet.', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'URL', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Auth Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $connection_key => $connection ) : ?>
						<?php
						// Use the array key as the connection ID (most reliable).
						// Fall back to $connection['id'] if key is numeric (shouldn't happen, but defensive).
						$connection_id  = is_string( $connection_key ) ? $connection_key : ( isset( $connection['id'] ) ? $connection['id'] : '' );
						$health_metrics = WP_MCP_AI_Pro_Remote_Site_Manager::get_health_metrics( $connection_id );
						?>
						<tr>
							<td><strong><?php echo esc_html( $connection['name'] ); ?></strong></td>
							<td>
								<?php
								$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';

								// Define labels and colors for each connection type
								$type_labels = array(
									'wordpress'          => __( 'WordPress', 'mcp-ai-wpoos-pro' ),
									'mesh_peer'          => __( 'Mesh Peer', 'mcp-ai-wpoos-pro' ),
									'generic'            => __( 'Generic REST API', 'mcp-ai-wpoos-pro' ),
									'isams'              => __( 'iSAMS', 'mcp-ai-wpoos-pro' ),
									'flowhub'            => __( 'Flowhub', 'mcp-ai-wpoos-pro' ),
									'payhere'            => __( 'PayHere', 'mcp-ai-wpoos-pro' ),
									'quickbooks'         => __( 'QuickBooks', 'mcp-ai-wpoos-pro' ),
									'ezuite_erp'         => __( 'EZuite ERP', 'mcp-ai-wpoos-pro' ),
									'gmail'              => __( 'Gmail', 'mcp-ai-wpoos-pro' ),
									'google_drive'       => __( 'Google Drive', 'mcp-ai-wpoos-pro' ),
									'telegram'           => __( 'Telegram', 'mcp-ai-wpoos-pro' ),
									'whatsapp'           => __( 'WhatsApp', 'mcp-ai-wpoos-pro' ),
									'slack'              => __( 'Slack', 'mcp-ai-wpoos-pro' ),
									'discord'            => __( 'Discord', 'mcp-ai-wpoos-pro' ),
									'microsoft_teams'    => __( 'MS Teams', 'mcp-ai-wpoos-pro' ),
									'facebook_messenger' => __( 'Messenger', 'mcp-ai-wpoos-pro' ),
									'webchat'            => __( 'WebChat', 'mcp-ai-wpoos-pro' ),
								);

								$type_colors = array(
									'wordpress'          => '#2271b1',
									'mesh_peer'          => '#7e57c2', // Purple - same as MESH badge in ai_peer
									'generic'            => '#50575e',
									'isams'              => '#d63638',
									'flowhub'            => '#00a32a',
									'payhere'            => '#f0b849',
									'quickbooks'         => '#2c9f47',
									'ezuite_erp'         => '#8c50a7',
									'gmail'              => '#ea4335', // Google red color
									'google_drive'       => '#4285f4', // Google blue color
									'telegram'           => '#0088cc', // Telegram blue
									'whatsapp'           => '#25d366', // WhatsApp green
									'slack'              => '#4a154b', // Slack purple
									'discord'            => '#5865f2', // Discord blurple
									'microsoft_teams'    => '#6264a7', // Teams purple
									'facebook_messenger' => '#0084ff', // Messenger blue
									'webchat'            => '#ff6b6b', // WebChat coral red
								);

								$type_label       = isset( $type_labels[ $connection_type ] ) ? $type_labels[ $connection_type ] : $connection_type;
								$type_badge_color = isset( $type_colors[ $connection_type ] ) ? $type_colors[ $connection_type ] : '#50575e';
								?>
								<span style="display: inline-block; padding: 2px 8px; background: <?php echo esc_attr( $type_badge_color ); ?>; color: white; border-radius: 3px; font-size: 11px;">
									<?php echo esc_html( $type_label ); ?>
								</span>
								<?php if ( 'WordPress' === $connection_type && ! empty( $connection['has_woocommerce'] ) ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #96588a; color: white; border-radius: 3px; font-size: 11px; margin-left: 4px;">WC</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $connection['url'] ); ?></td>
							<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $connection['auth_type'] ) ) ); ?></td>
							<td>
								<?php
								$status_colors = array(
									'healthy'   => '#46b450',
									'degraded'  => '#ffb900',
									'unhealthy' => '#dc3232',
									'unknown'   => '#8c8f94',
								);
								$status_color  = isset( $status_colors[ $health_metrics['status'] ] ) ? $status_colors[ $health_metrics['status'] ] : $status_colors['unknown'];
								?>
								<span style="color: <?php echo esc_attr( $status_color ); ?>;">●</span>
								<?php
								if ( $health_metrics['request_count'] > 0 ) {
									printf(
										/* translators: 1: success rate, 2: request count */
										esc_html__( '%1$s%% (%2$d reqs)', 'mcp-ai-wpoos-pro' ),
										esc_html( $health_metrics['success_rate'] ),
										absint( $health_metrics['request_count'] )
									);
								} else {
									esc_html_e( 'No data', 'mcp-ai-wpoos-pro' );
								}
								?>
							</td>
							<td>
								<?php if ( ! empty( $connection['enabled'] ) ) : ?>
									<span style="color: green;">●</span> <?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?>
								<?php else : ?>
									<span style="color: red;">●</span> <?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id ) ); ?>">
									<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=test&connection_id=' . $connection_id ), 'test_connection_' . $connection_id ) ); ?>">
									<?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&action=delete&connection_id=' . $connection_id ), 'delete_connection_' . $connection_id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this connection?', 'mcp-ai-wpoos-pro' ); ?>');" style="color: #b32d2e;">
									<?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
				<h3 style="margin-top: 0;"><?php esc_html_e( 'Performance & Reliability Features', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p style="margin-bottom: 15px;">
					<?php esc_html_e( 'Remote site requests include advanced features for performance, reliability, and monitoring.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<table class="form-table" style="background: white; border: 1px solid #ddd;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Caching', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'GET requests are cached to reduce redundant API calls. Default: 5 minutes. Configure per-connection in connection settings above.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate Limiting', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: rate limit, 2: filter name */
									esc_html__( 'Limited to %1$s per user to prevent abuse. Customize via %2$s filter.', 'mcp-ai-wpoos-pro' ),
									'<code>30 requests/minute</code>',
									'<code>wp_mcp_ai_pro_remote_wp_rate_limit</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retry Logic', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Automatic retry with exponential backoff (3 attempts) for transient errors. Improves reliability.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Request Deduplication', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Prevents duplicate simultaneous requests to the same endpoint. Reduces load on remote servers.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Compression Support', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Requests accept gzip/deflate compression to reduce bandwidth and improve speed for large responses.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Health Monitoring', 'mcp-ai-wpoos-pro' ); ?></th>
						<td>
							<p>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></strong>
							</p>
							<p class="description">
								<?php esc_html_e( 'Tracks success rates, response times, and connection health. View health status in the "Health" column above.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p style="margin-top: 15px;">
					<strong><?php esc_html_e( 'Developer Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php
					printf(
						/* translators: %s: documentation link */
						esc_html__( 'Use filters to customize caching, rate limits, and retry behavior. See %s for details.', 'mcp-ai-wpoos-pro' ),
						'<a href="' . esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/features/tools/remote-wp-connection.md' ) . '" target="_blank">' . esc_html__( 'documentation', 'mcp-ai-wpoos-pro' ) . '</a>'
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
		<h2><?php echo $is_edit ? esc_html__( 'Edit Connection', 'mcp-ai-wpoos-pro' ) : esc_html__( 'Add New Connection', 'mcp-ai-wpoos-pro' ); ?></h2>

		<form method="post" action="">
			<?php wp_nonce_field( 'save_remote_connection', '_wpnonce' ); ?>

			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $connection['id'] ); ?>">
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="name"><?php esc_html_e( 'Connection Name', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="name" id="name" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['name'] ) : ''; ?>" required>
						<p class="description"><?php esc_html_e( 'A friendly name to identify this connection.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="url"><?php esc_html_e( 'Site URL', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="url" id="url" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['url'] ) : ''; ?>" placeholder="https://example.com" required>
						<p class="description" id="url-description"><?php esc_html_e( 'The full URL of the remote site (including https://).', 'mcp-ai-wpoos-pro' ); ?></p>
						<p class="description" id="url-description-flowhub" style="display: none;">
							<?php esc_html_e( 'FlowHub uses a fixed API URL. This field is automatically set to https://api.flowhub.co', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="connection_type"><?php esc_html_e( 'Connection Type', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php
						$connection_type = $is_edit && isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';
						?>
						<select name="connection_type" id="connection_type" class="regular-text" required>
							<option value="wordpress" <?php selected( $connection_type, 'WordPress' ); ?>>
								<?php esc_html_e( 'WordPress / WooCommerce', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="mesh_peer" <?php selected( $connection_type, 'mesh_peer' ); ?>>
								<?php esc_html_e( 'Mesh Peer (Distributed AI)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="generic" <?php selected( $connection_type, 'generic' ); ?>>
								<?php esc_html_e( 'Generic REST API', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="isams" <?php selected( $connection_type, 'isams' ); ?>>
								<?php esc_html_e( 'iSAMS (School Management)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="flowhub" <?php selected( $connection_type, 'flowhub' ); ?>>
								<?php esc_html_e( 'Flowhub (POS/Retail)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="payhere" <?php selected( $connection_type, 'payhere' ); ?>>
								<?php esc_html_e( 'PayHere (Payment Gateway)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="quickbooks" <?php selected( $connection_type, 'quickbooks' ); ?>>
								<?php esc_html_e( 'QuickBooks (Accounting)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="ezuite_erp" <?php selected( $connection_type, 'ezuite_erp' ); ?>>
								<?php esc_html_e( 'EZuite ERP (Inventory)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="gmail" <?php selected( $connection_type, 'gmail' ); ?>>
								<?php esc_html_e( 'Gmail (Email Service)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="google_drive" <?php selected( $connection_type, 'google_drive' ); ?>>
								<?php esc_html_e( 'Google Drive (Cloud Storage)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="telegram" <?php selected( $connection_type, 'telegram' ); ?>>
								<?php esc_html_e( 'Telegram (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="whatsapp" <?php selected( $connection_type, 'whatsapp' ); ?>>
								<?php esc_html_e( 'WhatsApp Business (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="slack" <?php selected( $connection_type, 'slack' ); ?>>
								<?php esc_html_e( 'Slack (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="discord" <?php selected( $connection_type, 'discord' ); ?>>
								<?php esc_html_e( 'Discord (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="microsoft_teams" <?php selected( $connection_type, 'microsoft_teams' ); ?>>
								<?php esc_html_e( 'Microsoft Teams (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="facebook_messenger" <?php selected( $connection_type, 'facebook_messenger' ); ?>>
								<?php esc_html_e( 'Facebook Messenger (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="webchat" <?php selected( $connection_type, 'webchat' ); ?>>
								<?php esc_html_e( 'WebChat P2P (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select the type of connection. Each type has specific authentication requirements and field configurations.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr id="auth_type_row">
					<th scope="row">
						<label for="auth_type"><?php esc_html_e( 'Authentication Type', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="auth_type" id="auth_type" onchange="toggleAuthFields(this.value)">
							<option value="none" <?php selected( $is_edit ? $connection['auth_type'] : 'none', 'none' ); ?>><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="custom_header" <?php selected( $is_edit ? $connection['auth_type'] : '', 'custom_header' ); ?>><?php esc_html_e( 'Custom Header', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="application_password" <?php selected( $is_edit ? $connection['auth_type'] : '', 'application_password' ); ?>><?php esc_html_e( 'Application Password', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="basic_auth" <?php selected( $is_edit ? $connection['auth_type'] : '', 'basic_auth' ); ?>><?php esc_html_e( 'Basic Auth', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="jwt" <?php selected( $is_edit ? $connection['auth_type'] : '', 'jwt' ); ?>><?php esc_html_e( 'JWT Token', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="woocommerce" <?php selected( $is_edit ? $connection['auth_type'] : '', 'woocommerce' ); ?>><?php esc_html_e( 'WooCommerce API Keys (ck_/cs_)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Authentication method for WordPress and Generic API connections.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr id="username_field" style="display: none;">
					<th scope="row">
						<label for="username"><?php esc_html_e( 'Username', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="username" id="username" class="regular-text" value="<?php echo $is_edit ? esc_attr( $connection['username'] ) : ''; ?>" autocomplete="off">
					</td>
				</tr>

				<tr id="password_field" style="display: none;">
					<th scope="row">
						<label for="password"><?php esc_html_e( 'Password / Application Password', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="password" id="password" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing password.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="token_field" style="display: none;">
					<th scope="row">
						<label for="token"><?php esc_html_e( 'JWT Token', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="token" id="token" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="consumer_key_field" style="display: none;">
					<th scope="row">
						<label for="consumer_key"><?php esc_html_e( 'Consumer Key', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="consumer_key" id="consumer_key" class="regular-text" value="" autocomplete="off" placeholder="ck_...">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing consumer key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr id="consumer_secret_field" style="display: none;">
					<th scope="row">
						<label for="consumer_secret"><?php esc_html_e( 'Consumer Secret', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="consumer_secret" id="consumer_secret" class="regular-text" value="" autocomplete="new-password" placeholder="cs_...">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing consumer secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Type-specific fields for iSAMS -->
				<tr class="isams-only-field" style="display: none;">
					<th scope="row">
						<label for="isams_api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="isams_api_key" id="isams_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="isams-only-field" style="display: none;">
					<th scope="row">
						<label for="isams_api_secret"><?php esc_html_e( 'API Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="isams_api_secret" id="isams_api_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Type-specific fields for Flowhub -->
				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_api_key"><?php esc_html_e( 'API Key (key header)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="flowhub_api_key" id="flowhub_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Flowhub API key. Sent as "key" header in requests.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_client_id"><?php esc_html_e( 'Client ID (clientId header)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="flowhub_client_id" id="flowhub_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Flowhub client identifier. Sent as "clientId" header in requests.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="location_id"><?php esc_html_e( 'Location ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="location_id" id="location_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['location_id'] ) ? esc_attr( $connection['location_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: The Flowhub location/dispensary ID for filtering requests.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for PayHere -->
				<tr class="payhere-only-field" style="display: none;">
					<th scope="row">
						<label for="app_id"><?php esc_html_e( 'App ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="app_id" id="app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing App ID.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="payhere-only-field" style="display: none;">
					<th scope="row">
						<label for="app_secret"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="app_secret" id="app_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing App Secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="payhere-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'Enable sandbox/test mode', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use PayHere sandbox environment for testing.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Mesh Peer -->
				<tr class="mesh_peer-only-field" style="display: none;">
					<th scope="row">
						<label for="mesh_inbound_api_key"><?php esc_html_e( 'Mesh Inbound API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="mesh_inbound_api_key" id="mesh_inbound_api_key" class="regular-text" value="" autocomplete="off" placeholder="mesh_...">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description">
								<?php 
								echo wp_kses_post( 
									sprintf(
										/* translators: %s: link to remote site settings */
										__( 'The mesh inbound API key from the remote site. Find this at Settings → Advanced → Federation & Mesh on the remote site.', 'mcp-ai-wpoos-pro' )
									)
								); 
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="mesh_peer-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #7e57c2; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'About Mesh Peer Connections', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 8px 0; font-size: 13px;">
								<?php esc_html_e( 'Mesh peers enable distributed AI workload processing across multiple WordPress sites. This connection type is specifically designed for:', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ul style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Querying remote site assistants and data', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Distributed processing of AI tasks', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Federation discovery via .well-known/ai-peer', 'mcp-ai-wpoos-pro' ); ?></li>
							</ul>
							<p style="margin: 0; font-size: 13px;">
								<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'The remote site must have this plugin installed with Federation & Mesh enabled.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</div>
					</td>
				</tr>

				<!-- Type-specific fields for QuickBooks -->
				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="quickbooks_client_id"><?php esc_html_e( 'Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="quickbooks_client_id" id="quickbooks_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing client ID.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="quickbooks_client_secret"><?php esc_html_e( 'Client Secret / OAuth Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<textarea name="quickbooks_client_secret" id="quickbooks_client_secret" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing client secret/token. For OAuth, paste the complete token here.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="quickbooks-only-field" style="display: none;">
					<th scope="row">
						<label for="company_id"><?php esc_html_e( 'Company ID (Realm ID)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="company_id" id="company_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['company_id'] ) ? esc_attr( $connection['company_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'The QuickBooks company/realm ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for EZuite ERP -->
				<tr class="ezuite_erp-only-field" style="display: none;">
					<th scope="row">
						<label for="ezuite_erp_api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="ezuite_erp_api_key" id="ezuite_erp_api_key" class="regular-text" value="" autocomplete="off">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your EZuite API key provided by EZuite.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="ezuite_erp-only-field" style="display: none;">
					<th scope="row">
						<label for="ezuite_erp_api_secret"><?php esc_html_e( 'API Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="ezuite_erp_api_secret" id="ezuite_erp_api_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your EZuite API secret provided by EZuite.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Type-specific fields for Gmail -->
				<tr class="gmail-only-field" style="display: none;">
					<th scope="row">
						<label for="gmail_client_id"><?php esc_html_e( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="gmail_client_id" id="gmail_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="gmail-only-field" style="display: none;">
					<th scope="row">
						<label for="gmail_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="gmail_client_secret" id="gmail_client_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<?php if ( ! empty( $connection['client_secret'] ) ) : ?>
								<p class="description">
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
									<?php esc_html_e( 'Client secret is set. Leave blank to keep existing secret, or enter a new one to replace it.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Client secret is not set. Enter OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="gmail-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Authorized Redirect URI', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$gmail_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'gmail_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<input type="text" readonly="readonly" value="<?php echo esc_url( $gmail_redirect_uri ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description">
							<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php esc_html_e( 'Copy this exact URL and add it to the "Authorized redirect URIs" in your Google Cloud Console OAuth 2.0 credentials. The URL must match exactly (including https://).', 'mcp-ai-wpoos-pro' ); ?>
							<br>
							<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open Google Cloud Console', 'mcp-ai-wpoos-pro' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span>
							</a>
						</p>
					</td>
				</tr>

				<tr class="gmail-only-field" style="display: none;">
					<th scope="row">
						<label for="gmail_refresh_token"><?php esc_html_e( 'Refresh Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="gmail_refresh_token" id="gmail_refresh_token" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
								<p class="description">
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
									<?php esc_html_e( 'Refresh token is set. Leave blank to keep existing token, or paste a new token to replace it.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Optional: OAuth refresh token. Leave blank if not obtained yet through OAuth flow.', 'mcp-ai-wpoos-pro' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Optional: Pre-existing OAuth refresh token. If not provided, tools will need to initiate OAuth flow.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="gmail-only-field" style="display: none;">
					<th scope="row">
						<label for="gmail_user_email"><?php esc_html_e( 'Gmail User Email (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="email" name="gmail_user_email" id="gmail_user_email" class="regular-text" value="<?php echo $is_edit && isset( $connection['user_email'] ) ? esc_attr( $connection['user_email'] ) : ''; ?>" autocomplete="off" placeholder="user@gmail.com">
						<p class="description"><?php esc_html_e( 'The Gmail address associated with this connection for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Google Drive -->
				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label for="google_drive_client_id"><?php esc_html_e( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="google_drive_client_id" id="google_drive_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label for="google_drive_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="google_drive_client_secret" id="google_drive_client_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<?php if ( ! empty( $connection['client_secret'] ) ) : ?>
								<p class="description">
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
									<?php esc_html_e( 'Client secret is set. Leave blank to keep existing secret, or enter a new one to replace it.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Client secret is not set. Enter OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Authorized Redirect URI', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$google_drive_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'google_drive_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<input type="text" readonly="readonly" value="<?php echo esc_url( $google_drive_redirect_uri ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description">
							<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php esc_html_e( 'Copy this exact URL and add it to the "Authorized redirect URIs" in your Google Cloud Console OAuth 2.0 credentials. The URL must match exactly (including https://).', 'mcp-ai-wpoos-pro' ); ?>
							<br>
							<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open Google Cloud Console', 'mcp-ai-wpoos-pro' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span>
							</a>
						</p>
					</td>
				</tr>

				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label for="google_drive_refresh_token"><?php esc_html_e( 'Refresh Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="google_drive_refresh_token" id="google_drive_refresh_token" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
								<p class="description">
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
									<?php esc_html_e( 'Refresh token is set. Leave blank to keep existing token, or paste a new token to replace it.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Optional: OAuth refresh token. Leave blank if not obtained yet through OAuth flow.', 'mcp-ai-wpoos-pro' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Optional: Pre-existing OAuth refresh token. If not provided, tools will need to initiate OAuth flow.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label for="google_drive_folder_id"><?php esc_html_e( 'Folder ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="google_drive_folder_id" id="google_drive_folder_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['folder_id'] ) ? esc_attr( $connection['folder_id'] ) : ''; ?>" autocomplete="off" placeholder="1a2b3c4d5e6f7g8h9i0j">
						<p class="description"><?php esc_html_e( 'Optional: Limit access to a specific folder by ID. Leave blank for full drive access (within granted scopes).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="google_drive-only-field" style="display: none;">
					<th scope="row">
						<label for="google_drive_user_email"><?php esc_html_e( 'Google User Email (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="email" name="google_drive_user_email" id="google_drive_user_email" class="regular-text" value="<?php echo $is_edit && isset( $connection['user_email'] ) ? esc_attr( $connection['user_email'] ) : ''; ?>" autocomplete="off" placeholder="user@example.com">
						<p class="description"><?php esc_html_e( 'The Google account email associated with this connection for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<?php if ( $is_edit && 'gmail' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
					<tr class="gmail-only-field" style="display: none;">
						<th scope="row">
							<label><?php esc_html_e( 'OAuth Connection', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php
							// Generate Google OAuth URL directly without intermediate handler.
							$has_required_credentials = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] );

							if ( $has_required_credentials ) {
								// Generate OAuth state and store connection ID.
								$state         = wp_generate_uuid4();
								$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );

								set_transient(
									$transient_key,
									array(
										'user_id'       => get_current_user_id(),
										'connection_id' => $connection['id'],
										'time'          => time(),
									),
									10 * MINUTE_IN_SECONDS
								);

								// Build redirect URI (where Google will send user after authorization).
								$redirect_uri = add_query_arg(
									array(
										'page'          => 'wp-mcp-ai-remote-sites',
										'oauth_handler' => 'gmail_oauth_callback',
									),
									admin_url( 'admin.php' )
								);

								// Build Google OAuth authorization URL.
								$oauth_params = array(
									'client_id'     => $connection['client_id'],
									'redirect_uri'  => $redirect_uri,
									'response_type' => 'code',
									'scope'         => 'https://www.googleapis.com/auth/gmail.readonly',
									'access_type'   => 'offline',
									'include_granted_scopes' => 'true',
									'prompt'        => 'consent',
									'state'         => $state,
								);

								if ( ! empty( $connection['user_email'] ) && 'me' !== strtolower( $connection['user_email'] ) ) {
									$oauth_params['login_hint'] = $connection['user_email'];
								}

								$oauth_url = add_query_arg( $oauth_params, 'https://accounts.google.com/o/oauth2/v2/auth' );
							} else {
								// If credentials not set, link to edit page with error.
								$oauth_url = add_query_arg(
									array(
										'page'  => 'wp-mcp-ai-remote-sites',
										'edit'  => $connection['id'],
										'error' => rawurlencode( __( 'Please save the Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ),
									),
									admin_url( 'admin.php' )
								);
							}
							?>
							<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-secondary" <?php echo $has_required_credentials ? '' : 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"'; ?>>
								<span class="dashicons dashicons-google" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Connect to Gmail', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<p class="description">
								<?php esc_html_e( 'Click to authorize this connection with your Google account and obtain a refresh token. Make sure to save the Client ID and Client Secret first.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
								<p class="description" style="color: #46b450;">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'This connection is already authorized. Click the button above to re-authorize if needed.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>

				<!-- Google Drive OAuth Connection Button -->
				<?php if ( $is_edit && 'google_drive' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
					<tr class="google_drive-only-field" style="display: none;">
						<th scope="row">
							<label><?php esc_html_e( 'OAuth Connection', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php
							$oauth_url = wp_nonce_url(
								add_query_arg(
									array(
										'page'          => 'wp-mcp-ai-remote-sites',
										'oauth_handler' => 'google_drive_oauth_connect',
										'connection_id' => $connection['id'],
									),
									admin_url( 'admin.php' )
								),
								'google_drive_oauth_connect_' . $connection['id']
							);
							?>
							<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-secondary">
								<span class="dashicons dashicons-google" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Connect to Google Drive', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<p class="description">
								<?php esc_html_e( 'Click to authorize this connection with your Google account and obtain a refresh token. Make sure to save the Client ID and Client Secret first.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
								<p class="description" style="color: #46b450;">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'This connection is already authorized. Click the button above to re-authorize if needed.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>

				<!-- Type-specific fields for Telegram -->
				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_bot_token"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="telegram_bot_token" id="telegram_bot_token" class="regular-text" value="" autocomplete="new-password" placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing bot token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Telegram bot token from @BotFather.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_bot_username"><?php esc_html_e( 'Bot Username (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="telegram_bot_username" id="telegram_bot_username" class="regular-text" value="<?php echo $is_edit && isset( $connection['bot_username'] ) ? esc_attr( $connection['bot_username'] ) : ''; ?>" autocomplete="off" placeholder="@mybot">
						<p class="description"><?php esc_html_e( 'Optional: Your bot username for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure this URL in your Telegram bot settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for WhatsApp -->
				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_access_token"><?php esc_html_e( 'Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="whatsapp_access_token" id="whatsapp_access_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your WhatsApp Business API access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_phone_number_id"><?php esc_html_e( 'Phone Number ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="whatsapp_phone_number_id" id="whatsapp_phone_number_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['phone_number_id'] ) ? esc_attr( $connection['phone_number_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business phone number ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_business_account_id"><?php esc_html_e( 'Business Account ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_business_account_id" id="whatsapp_business_account_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['business_account_id'] ) ? esc_attr( $connection['business_account_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Your WhatsApp Business Account ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_verify_token"><?php esc_html_e( 'Verify Token', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_verify_token" id="whatsapp_verify_token" class="regular-text" value="<?php echo $is_edit && isset( $connection['verify_token'] ) ? esc_attr( $connection['verify_token'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Use this token when setting up webhooks in WhatsApp Business settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure this in your WhatsApp Business settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Slack -->
				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label for="slack_bot_token"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="slack_bot_token" id="slack_bot_token" class="regular-text" value="" autocomplete="new-password" placeholder="xoxb-your-bot-token">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing bot token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Slack Bot User OAuth Token (starts with xoxb-).', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label for="slack_signing_secret"><?php esc_html_e( 'Signing Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="slack_signing_secret" id="slack_signing_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing signing secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Used to verify requests from Slack.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label for="slack_workspace_id"><?php esc_html_e( 'Workspace ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="slack_workspace_id" id="slack_workspace_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['workspace_id'] ) ? esc_attr( $connection['workspace_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Slack workspace ID for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/slack' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure as Request URL in Slack app Event Subscriptions.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Discord -->
				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label for="discord_bot_token"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="discord_bot_token" id="discord_bot_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing bot token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Discord bot token from the Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label for="discord_application_id"><?php esc_html_e( 'Application ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="discord_application_id" id="discord_application_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['application_id'] ) ? esc_attr( $connection['application_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Discord application ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label for="discord_guild_id"><?php esc_html_e( 'Guild/Server ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="discord_guild_id" id="discord_guild_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['guild_id'] ) ? esc_attr( $connection['guild_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Default Discord server/guild ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/discord' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure as Interactions Endpoint URL in Discord Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Microsoft Teams -->
				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_app_id"><?php esc_html_e( 'App ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="teams_app_id" id="teams_app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Microsoft Teams application ID (from Azure AD).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_app_password"><?php esc_html_e( 'App Password', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="teams_app_password" id="teams_app_password" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing app password.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Microsoft Teams app password/secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_tenant_id"><?php esc_html_e( 'Tenant ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="teams_tenant_id" id="teams_tenant_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['tenant_id'] ) ? esc_attr( $connection['tenant_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Azure AD tenant ID for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Messaging Endpoint', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/teams' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure as Messaging Endpoint in Azure Bot Channels Registration.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Facebook Messenger -->
				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_page_access_token"><?php esc_html_e( 'Page Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="messenger_page_access_token" id="messenger_page_access_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing page access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Facebook Page access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_app_secret"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="messenger_app_secret" id="messenger_app_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing app secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Facebook app secret for signature verification.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_page_id"><?php esc_html_e( 'Page ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="messenger_page_id" id="messenger_page_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['page_id'] ) ? esc_attr( $connection['page_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Facebook page ID for reference.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_verify_token"><?php esc_html_e( 'Verify Token', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="messenger_verify_token" id="messenger_verify_token" class="regular-text" value="<?php echo $is_edit && isset( $connection['verify_token'] ) ? esc_attr( $connection['verify_token'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Use this when setting up webhook subscription in Messenger settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/messenger' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Configure as Callback URL in Messenger settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for WebChat -->
				<tr class="webchat-only-field" style="display: none;">
					<th scope="row">
						<label for="webchat_connection_id"><?php esc_html_e( 'P2P Connection ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="webchat_connection_id" id="webchat_connection_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['p2p_connection_id'] ) ? esc_attr( $connection['p2p_connection_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Unique identifier for this P2P WebChat connection.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="webchat-only-field" style="display: none;">
					<th scope="row">
						<label for="webchat_encryption_key"><?php esc_html_e( 'Encryption Key (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="webchat_encryption_key" id="webchat_encryption_key" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing encryption key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Optional: Encryption key for secure P2P communication.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="webchat-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'WebSocket Endpoint', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/webchat' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'WebSocket/HTTP endpoint for P2P WebChat connections.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="wordpress-only-field">
					<th scope="row"><?php esc_html_e( 'WooCommerce', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="has_woocommerce" value="1" <?php checked( $is_edit && ! empty( $connection['has_woocommerce'] ) ); ?>>
							<?php esc_html_e( 'This site has WooCommerce installed', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enable to access WooCommerce products, orders, and other data.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="generic-only-field" style="display:none;">
					<th scope="row">
						<label for="test_endpoint"><?php esc_html_e( 'Test Endpoint', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="test_endpoint" id="test_endpoint" class="regular-text" value="<?php echo $is_edit && isset( $connection['test_endpoint'] ) ? esc_attr( $connection['test_endpoint'] ) : '/'; ?>" placeholder="/">
						<p class="description">
							<?php esc_html_e( 'API endpoint to use for connection testing (e.g., /api/health or /). Default: /', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( ! $is_edit || ! empty( $connection['enabled'] ) ); ?>>
							<?php esc_html_e( 'Connection enabled', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="number" name="cache_ttl" id="cache_ttl" class="small-text" value="<?php echo $is_edit && isset( $connection['cache_ttl'] ) ? esc_attr( $connection['cache_ttl'] ) : '300'; ?>" min="0" max="3600">
						<p class="description">
							<?php esc_html_e( 'How long to cache GET requests (in seconds). Default: 300 (5 minutes). Set to 0 to disable caching for this connection.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="wp_mcp_ai_pro_save_connection" class="button button-primary" value="<?php echo $is_edit ? esc_attr__( 'Update Connection', 'mcp-ai-wpoos-pro' ) : esc_attr__( 'Add Connection', 'mcp-ai-wpoos-pro' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'mcp-ai-wpoos-pro' ); ?>
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
			var gmailFields = document.querySelectorAll('.gmail-only-field');
			var googleDriveFields = document.querySelectorAll('.google_drive-only-field');
			var telegramFields = document.querySelectorAll('.telegram-only-field');
			var whatsappFields = document.querySelectorAll('.whatsapp-only-field');
			var slackFields = document.querySelectorAll('.slack-only-field');
			var discordFields = document.querySelectorAll('.discord-only-field');
			var teamsFields = document.querySelectorAll('.microsoft_teams-only-field');
			var messengerFields = document.querySelectorAll('.facebook_messenger-only-field');
			var webchatFields = document.querySelectorAll('.webchat-only-field');
			var meshPeerFields = document.querySelectorAll('.mesh_peer-only-field');
			var authTypeRow = document.getElementById('auth_type_row');
			var authTypeSelect = document.getElementById('auth_type');
			var urlField = document.getElementById('url');
			var urlDescription = document.getElementById('url-description');
			var urlDescriptionFlowhub = document.getElementById('url-description-flowhub');

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
			gmailFields.forEach(function(field) {
				field.style.display = 'none';
			});
			googleDriveFields.forEach(function(field) {
				field.style.display = 'none';
			});
			telegramFields.forEach(function(field) {
				field.style.display = 'none';
			});
			whatsappFields.forEach(function(field) {
				field.style.display = 'none';
			});
			slackFields.forEach(function(field) {
				field.style.display = 'none';
			});
			discordFields.forEach(function(field) {
				field.style.display = 'none';
			});
			teamsFields.forEach(function(field) {
				field.style.display = 'none';
			});
			messengerFields.forEach(function(field) {
				field.style.display = 'none';
			});
			webchatFields.forEach(function(field) {
				field.style.display = 'none';
			});
			meshPeerFields.forEach(function(field) {
				field.style.display = 'none';
			});

			// Reset URL field defaults
			urlField.readOnly = false;
			urlField.style.backgroundColor = '';
			urlDescription.style.display = 'block';
			urlDescriptionFlowhub.style.display = 'none';

			// Show/hide auth_type field based on connection type
			// Only show for WordPress and Generic API connections
			if (connectionType === 'WordPress' || connectionType === 'generic') {
				authTypeRow.style.display = 'table-row';
			} else {
				authTypeRow.style.display = 'none';
			}

			// Show fields for selected connection type
			if (connectionType === 'WordPress') {
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
				// Flowhub uses a fixed API URL
				urlField.value = 'https://api.flowhub.co';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				urlDescriptionFlowhub.style.display = 'block';
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
			} else if (connectionType === 'gmail') {
				gmailFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Gmail uses OAuth, set URL to Google's Gmail API
				urlField.value = 'https://gmail.googleapis.com';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				// Gmail doesn't use the standard auth_type, it has its own OAuth flow
				authTypeSelect.value = 'none';
			} else if (connectionType === 'google_drive') {
				googleDriveFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Google Drive uses OAuth, set URL to Google's Drive API
				urlField.value = 'https://www.googleapis.com/drive/v3';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				// Google Drive doesn't use the standard auth_type, it has its own OAuth flow
				authTypeSelect.value = 'none';
			} else if (connectionType === 'telegram') {
				telegramFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Telegram uses Bot API with bot token
				urlField.value = 'https://api.telegram.org';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'whatsapp') {
				whatsappFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// WhatsApp uses Business API
				urlField.value = 'https://graph.facebook.com/v18.0';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'slack') {
				slackFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Slack uses Web API with bot token
				urlField.value = 'https://slack.com/api';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'discord') {
				discordFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Discord uses Discord API
				urlField.value = 'https://discord.com/api/v10';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'microsoft_teams') {
				teamsFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Microsoft Teams uses Bot Framework
				urlField.value = 'https://smba.trafficmanager.net/apis';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'facebook_messenger') {
				messengerFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Facebook Messenger uses Graph API
				urlField.value = 'https://graph.facebook.com/v18.0';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'webchat') {
				webchatFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// WebChat P2P - uses local WordPress REST endpoint
				urlField.value = window.location.origin + '/wp-json/mcp-ai/v1/webhooks/webchat';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'mesh_peer') {
				meshPeerFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Mesh peer uses custom header auth with mesh API key
				authTypeSelect.value = 'custom_header';
				// URL field should remain editable for mesh peers
				urlField.readOnly = false;
				urlField.style.backgroundColor = '';
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

	/**
	 * Handle Gmail OAuth start for a remote connection.
	 *
	 * @deprecated No longer used in production code. Button now links directly to Google OAuth.
	 *             Kept for backward compatibility and test support only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_gmail_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'gmail' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not a Gmail connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Please save the Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Generate OAuth state and store connection ID.
		$state     = wp_generate_uuid4();
		$transient = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );

		set_transient(
			$transient,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Decrypt client_secret for OAuth flow.
		$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );

		$params = array(
			'client_id'              => $connection['client_id'],
			'redirect_uri'           => add_query_arg(
				array(
					'page'          => 'wp-mcp-ai-remote-sites',
					'oauth_handler' => 'gmail_oauth_callback',
				),
				admin_url( 'admin.php' )
			),
			'response_type'          => 'code',
			'scope'                  => 'https://www.googleapis.com/auth/gmail.readonly',
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);

		if ( ! empty( $connection['user_email'] ) && 'me' !== strtolower( $connection['user_email'] ) ) {
			$params['login_hint'] = $connection['user_email'];
		}

		$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle Gmail OAuth callback for a remote connection.
	 *
	 * @since 1.0.0
	 */
	protected function handle_gmail_oauth_callback() {
		// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( __( 'Google OAuth error: %s', 'mcp-ai-wpoos-pro' ), $error ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $code ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$connection_id = $state_data['connection_id'];
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Decrypt client_secret for token exchange.
		$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );

		// Exchange authorization code for tokens.
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $connection['client_id'],
					'client_secret' => $client_secret,
					'redirect_uri'  => add_query_arg(
						array(
							'page'          => 'wp-mcp-ai-remote-sites',
							'oauth_handler' => 'gmail_oauth_callback',
						),
						admin_url( 'admin.php' )
					),
					'grant_type'    => 'authorization_code',
				),
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Failed to exchange authorization code. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Google rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Invalid response from Google.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		// If no refresh token, check if we can reuse existing one.
		if ( '' === $refresh_token && ! empty( $connection['refresh_token'] ) ) {
			$refresh_token = $connection['refresh_token'];
		}

		if ( '' === $refresh_token ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'No refresh token received. Please revoke existing access and try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Get email address from profile if access token is available.
		$email_address = '';
		if ( $access_token ) {
			$profile_response = wp_remote_get(
				'https://gmail.googleapis.com/gmail/v1/users/me/profile',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
				$profile_body = json_decode( wp_remote_retrieve_body( $profile_response ), true );
				if ( isset( $profile_body['emailAddress'] ) ) {
					$email_address = sanitize_email( $profile_body['emailAddress'] );
				}
			}
		}

		// Update the connection with the new refresh token and email.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => $connection['name'],
			'url'             => $connection['url'],
			'connection_type' => 'gmail',
			'auth_type'       => 'none',
			'client_id'       => $connection['client_id'],
			'client_secret'   => '', // Keep existing (don't re-encrypt).
			'refresh_token'   => $refresh_token,
			'user_email'      => $email_address ? $email_address : $connection['user_email'],
			'enabled'         => $connection['enabled'],
		);

		// Preserve encrypted client_secret.
		$update_data['_client_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		$success_message = __( 'Gmail connected successfully!', 'mcp-ai-wpoos-pro' );
		if ( $email_address ) {
			$success_message = sprintf(
				/* translators: %s: email address */
				__( 'Gmail connected successfully for %s!', 'mcp-ai-wpoos-pro' ),
				$email_address
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( $success_message ) ) );
		exit;
	}

	/**
	 * Handle Google Drive OAuth start for a remote connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_google_drive_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'google_drive' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not a Google Drive connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Please save the Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Generate OAuth state and store connection ID.
		$state     = wp_generate_uuid4();
		$transient = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );

		set_transient(
			$transient,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Decrypt client_secret for OAuth flow.
		$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );

		$params = array(
			'client_id'              => $connection['client_id'],
			'redirect_uri'           => add_query_arg(
				array(
					'page'          => 'wp-mcp-ai-remote-sites',
					'oauth_handler' => 'google_drive_oauth_callback',
				),
				admin_url( 'admin.php' )
			),
			'response_type'          => 'code',
			'scope'                  => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly',
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);

		if ( ! empty( $connection['user_email'] ) && 'me' !== strtolower( $connection['user_email'] ) ) {
			$params['login_hint'] = $connection['user_email'];
		}

		$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle Google Drive OAuth callback for a remote connection.
	 *
	 * @since 1.0.0
	 */
	protected function handle_google_drive_oauth_callback() {
		// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( __( 'Google OAuth error: %s', 'mcp-ai-wpoos-pro' ), $error ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $code ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$connection_id = $state_data['connection_id'];
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Decrypt client_secret for token exchange.
		$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );

		// Exchange authorization code for tokens.
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $connection['client_id'],
					'client_secret' => $client_secret,
					'redirect_uri'  => add_query_arg(
						array(
							'page'          => 'wp-mcp-ai-remote-sites',
							'oauth_handler' => 'google_drive_oauth_callback',
						),
						admin_url( 'admin.php' )
					),
					'grant_type'    => 'authorization_code',
				),
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Failed to exchange authorization code. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Google rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Invalid response from Google.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		// If no refresh token, check if we can reuse existing one.
		if ( '' === $refresh_token && ! empty( $connection['refresh_token'] ) ) {
			$refresh_token = $connection['refresh_token'];
		}

		if ( '' === $refresh_token ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'No refresh token received. Please revoke existing access and try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Get email address from userinfo if access token is available.
		$email_address = '';
		if ( $access_token ) {
			$userinfo_response = wp_remote_get(
				'https://www.googleapis.com/oauth2/v2/userinfo',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( ! is_wp_error( $userinfo_response ) && 200 === wp_remote_retrieve_response_code( $userinfo_response ) ) {
				$userinfo_body = json_decode( wp_remote_retrieve_body( $userinfo_response ), true );
				if ( isset( $userinfo_body['email'] ) ) {
					$email_address = sanitize_email( $userinfo_body['email'] );
				}
			}
		}

		// Update the connection with the new refresh token and email.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => $connection['name'],
			'url'             => $connection['url'],
			'connection_type' => 'google_drive',
			'auth_type'       => 'none',
			'client_id'       => $connection['client_id'],
			'client_secret'   => '', // Keep existing (don't re-encrypt).
			'refresh_token'   => $refresh_token,
			'user_email'      => $email_address ? $email_address : $connection['user_email'],
			'folder_id'       => isset( $connection['folder_id'] ) ? $connection['folder_id'] : '',
			'enabled'         => $connection['enabled'],
		);

		// Preserve encrypted client_secret.
		$update_data['_client_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		$success_message = __( 'Google Drive connected successfully!', 'mcp-ai-wpoos-pro' );
		if ( $email_address ) {
			$success_message = sprintf(
				/* translators: %s: email address */
				__( 'Google Drive connected successfully for %s!', 'mcp-ai-wpoos-pro' ),
				$email_address
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( $success_message ) ) );
		exit;
	}
}

// Initialize the admin interface.
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Remote_Sites_Admin();

	// Initialize bidirectional sync for mesh peers.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-mesh-peer-bidirectional-sync.php';
	new WP_MCP_AI_Pro_Mesh_Peer_Bidirectional_Sync();
}
