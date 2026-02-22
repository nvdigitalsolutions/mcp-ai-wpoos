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
		add_action( 'wp_ajax_wp_mcp_ai_test_whatsapp_live', array( $this, 'ajax_test_whatsapp_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_whatsapp_auto_reply', array( $this, 'ajax_test_whatsapp_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_generate_messenger_token', array( $this, 'ajax_generate_messenger_token' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_messenger_live', array( $this, 'ajax_test_messenger_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_remote_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_whatsapp_phone_numbers', array( $this, 'ajax_fetch_whatsapp_phone_numbers' ) );
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

			// Store test results in transient for detailed display.
			if ( ! is_wp_error( $result ) ) {
				set_transient( 'wp_mcp_ai_test_result_' . $connection_id, $result, 60 );
			}

			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id );

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
			$connection_type = isset( $_POST['connection_type'] ) ? sanitize_key( wp_unslash( $_POST['connection_type'] ) ) : 'wordpress';

			// Map connection-type-specific fields to generic field names.
			$api_key       = '';
			$api_secret    = '';
			$client_id     = '';
			$client_secret = '';
			$refresh_token = '';
			$user_email    = '';
			$app_id        = '';

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
					$api_key    = isset( $_POST['whatsapp_access_token'] ) ? wp_unslash( $_POST['whatsapp_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['whatsapp_app_secret'] ) ? wp_unslash( $_POST['whatsapp_app_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- app secrets must not be sanitized.
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
					$app_id     = isset( $_POST['messenger_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_app_id'] ) ) : '';
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
				$graph_api_version = isset( $_POST['whatsapp_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_graph_api_version'] ) ) : 'v21.0';
				if ( ! preg_match( '/^v\d+\.\d+$/', $graph_api_version ) ) {
					$graph_api_version = 'v21.0';
				}
				$url       = 'https://graph.facebook.com/' . $graph_api_version;
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
				$graph_api_version = isset( $_POST['messenger_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_graph_api_version'] ) ) : 'v21.0';
				if ( ! preg_match( '/^v\d+\.\d+$/', $graph_api_version ) ) {
					$graph_api_version = 'v21.0';
				}
				$url       = 'https://graph.facebook.com/' . $graph_api_version;
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
				'app_id'          => $app_id ? $app_id : ( isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : ( isset( $_POST['teams_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_app_id'] ) ) : '' ) ),
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
				'phone_number_id'      => isset( $_POST['whatsapp_phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_phone_number_id'] ) ) : '',
				'display_phone_number' => isset( $_POST['whatsapp_display_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_display_phone_number'] ) ) : '',
				'business_account_id'  => isset( $_POST['whatsapp_business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_business_account_id'] ) ) : '',
				'system_user_id'       => isset( $_POST['whatsapp_system_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_system_user_id'] ) ) : '',
				'verify_token'    => isset( $_POST['whatsapp_verify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_verify_token'] ) ) : ( isset( $_POST['messenger_verify_token'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_verify_token'] ) ) : '' ),
				'graph_api_version' => isset( $_POST['whatsapp_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_graph_api_version'] ) ) : ( isset( $_POST['messenger_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_graph_api_version'] ) ) : '' ),
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
				// WhatsApp channel routing: assistants assigned to listen on this channel.
				'assigned_assistant_ids' => isset( $_POST['assigned_assistant_ids'] ) && is_array( $_POST['assigned_assistant_ids'] )
					? array_values( array_map( 'absint', wp_unslash( $_POST['assigned_assistant_ids'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					: array(),
			);

			$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( $result->get_error_message() ) ) );
			} else {
				// Redirect back to the edit page so the user can verify the saved data.
				$saved_connection_id = is_string( $result ) ? $result : ( isset( $connection_data['id'] ) ? $connection_data['id'] : '' );
				if ( $saved_connection_id ) {
					wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . rawurlencode( $saved_connection_id ) . '&saved=1' ) );
				} else {
					wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&saved=1' ) );
				}
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
				<?php
				$editing      = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
				$test_results = $editing ? get_transient( 'wp_mcp_ai_test_result_' . $editing ) : false;
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Connection test successful!', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<?php if ( $test_results && is_array( $test_results ) ) : ?>
						<ul style="margin: 10px 0; padding-left: 20px;">
							<?php if ( isset( $test_results['whatsapp'] ) && $test_results['whatsapp'] ) : ?>
								<?php if ( ! empty( $test_results['phone_number'] ) ) : ?>
									<li><?php echo esc_html( sprintf( __( 'Phone Number: %s', 'mcp-ai-wpoos-pro' ), $test_results['phone_number'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['verified_name'] ) ) : ?>
									<li><?php echo esc_html( sprintf( __( 'Verified Name: %s', 'mcp-ai-wpoos-pro' ), $test_results['verified_name'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['quality_rating'] ) ) : ?>
									<li>
										<?php
										$quality_upper = strtoupper( $test_results['quality_rating'] );
										if ( 'GREEN' === $quality_upper ) {
											$quality_color = '#00a32a';
										} elseif ( 'YELLOW' === $quality_upper ) {
											$quality_color = '#f0b849';
										} elseif ( 'UNKNOWN' === $quality_upper ) {
											$quality_color = '#777777';
										} else {
											$quality_color = '#d63638';
										}
										echo wp_kses_post( sprintf( __( 'Quality Rating: <span style="color: %1$s; font-weight: bold;">%2$s</span>', 'mcp-ai-wpoos-pro' ), $quality_color, $quality_upper ) );
										?>
									</li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['business_name'] ) ) : ?>
									<li><?php echo esc_html( sprintf( __( 'Business Profile: %s', 'mcp-ai-wpoos-pro' ), $test_results['business_name'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( isset( $test_results['has_app_secret'] ) && $test_results['has_app_secret'] ) : ?>
									<li style="color: #00a32a;">✓ <?php esc_html_e( 'App Secret configured (webhook signatures will be validated)', 'mcp-ai-wpoos-pro' ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['webhook_url'] ) ) : ?>
									<li>
										<?php esc_html_e( 'Webhook URL:', 'mcp-ai-wpoos-pro' ); ?>
										<code style="background: #f0f0f0; padding: 2px 6px; font-size: 12px;"><?php echo esc_html( $test_results['webhook_url'] ); ?></code>
									</li>
								<?php endif; ?>
							<?php elseif ( isset( $test_results['site_name'] ) ) : ?>
								<li><?php echo esc_html( sprintf( __( 'Site: %s', 'mcp-ai-wpoos-pro' ), $test_results['site_name'] ) ); ?></li>
								<?php if ( isset( $test_results['woocommerce'] ) && $test_results['woocommerce'] ) : ?>
									<li style="color: #00a32a;">✓ <?php esc_html_e( 'WooCommerce detected', 'mcp-ai-wpoos-pro' ); ?></li>
								<?php endif; ?>
							<?php endif; ?>
						</ul>
						<?php if ( isset( $test_results['warning'] ) && ! empty( $test_results['warning'] ) ) : ?>
							<p style="color: #b45309; font-size: 13px;">⚠ <?php echo esc_html( $test_results['warning'] ); ?></p>
						<?php endif; ?>
						<?php if ( isset( $test_results['quality_note'] ) && ! empty( $test_results['quality_note'] ) ) : ?>
							<p style="color: #2271b1; font-size: 13px;">ℹ <?php echo esc_html( $test_results['quality_note'] ); ?></p>
						<?php endif; ?>
						<?php
						// Clean up transient after displaying.
						if ( $editing ) {
							delete_transient( 'wp_mcp_ai_test_result_' . $editing );
						}
						?>
					<?php endif; ?>
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
								<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'test', 'connection_id' => $connection_id, '_wpnonce' => wp_create_nonce( 'test_connection_' . $connection_id ) ), admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ) ); ?>">
									<?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?>
								</a> |
								<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'delete', 'connection_id' => $connection_id, '_wpnonce' => wp_create_nonce( 'delete_connection_' . $connection_id ) ), admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this connection?', 'mcp-ai-wpoos-pro' ); ?>');" style="color: #b32d2e;">
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
						$connection_type = $is_edit && isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'wordpress';
						?>
						<select name="connection_type" id="connection_type" class="regular-text" required>
							<option value="wordpress" <?php selected( $connection_type, 'wordpress' ); ?>>
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
						<label for="whatsapp_system_user_id"><?php esc_html_e( 'System User ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_system_user_id" id="whatsapp_system_user_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['system_user_id'] ) ? esc_attr( $connection['system_user_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Meta Business System User ID. Found in Meta Business Suite → Business Settings → System Users. Required for server-to-server Cloud API calls and used to validate the System User Access Token.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_graph_api_version"><?php esc_html_e( 'Cloud API Version', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$saved_wa_version = $is_edit && isset( $connection['graph_api_version'] ) && $connection['graph_api_version'] ? $connection['graph_api_version'] : 'v21.0';
						$wa_versions      = array( 'v22.0', 'v21.0', 'v20.0', 'v19.0', 'v18.0' );
						?>
						<select name="whatsapp_graph_api_version" id="whatsapp_graph_api_version" class="regular-text">
							<?php foreach ( $wa_versions as $ver ) : ?>
								<option value="<?php echo esc_attr( $ver ); ?>" <?php selected( $saved_wa_version, $ver ); ?>><?php echo esc_html( $ver ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'WhatsApp Cloud API version for API requests. Select the latest version supported by your Meta app.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_access_token"><?php esc_html_e( 'Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="whatsapp_access_token" id="whatsapp_access_token" class="regular-text" value="" autocomplete="new-password">
						<button type="button" id="whatsapp_access_token_toggle" class="button button-small" style="margin-left: 5px; vertical-align: middle;" aria-label="<?php esc_attr_e( 'Hide access token', 'mcp-ai-wpoos-pro' ); ?>"><?php esc_html_e( 'Hide', 'mcp-ai-wpoos-pro' ); ?></button>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your WhatsApp Cloud API System User Access Token. This must be a System User Access Token (from Meta Business Suite → Business Settings → System Users) or a User Access Token with the whatsapp_business_messaging permission — NOT an App Access Token. App Access Tokens (format: {app_id}|{hash}) cannot send or receive WhatsApp messages.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_app_secret"><?php esc_html_e( 'App Secret', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="whatsapp_app_secret" id="whatsapp_app_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing App Secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Meta App Secret (found in Meta App Dashboard → Settings → Basic). Required if your app has "Require App Secret Proof" enabled. Also used to validate incoming webhook signatures.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_phone_number_id"><?php esc_html_e( 'Phone Number ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
							<input type="text" name="whatsapp_phone_number_id" id="whatsapp_phone_number_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['phone_number_id'] ) ? esc_attr( $connection['phone_number_id'] ) : ''; ?>" autocomplete="off">
							<button type="button" id="wp-mcp-ai-wa-lookup-phone-btn" class="button button-secondary">
								<?php esc_html_e( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</div>
						<div id="wp-mcp-ai-wa-lookup-phone-result" style="margin-top:8px;"></div>
						<p class="description">
							<?php esc_html_e( 'Enter your Phone Number ID manually, or click "Retrieve Phone Numbers" to fetch it automatically using the Business Account ID and Access Token above. Find it in the Meta Developer Dashboard: select your app → WhatsApp → API Setup → "Phone Number ID" (not the WABA ID shown in Meta Business Manager).', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_display_phone_number"><?php esc_html_e( 'Display Phone Number', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_display_phone_number" id="whatsapp_display_phone_number" class="regular-text" value="<?php echo $is_edit && isset( $connection['display_phone_number'] ) ? esc_attr( $connection['display_phone_number'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. +1 555 000 1234', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Optional. Enter your WhatsApp display phone number (e.g. +1 555 000 1234) to generate a QR code and channel link for members. This is auto-populated when you run "Test Connection" and the token has sufficient permissions.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="whatsapp_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test WhatsApp Connection', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="whatsapp_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Enter your Access Token and Phone Number ID above, then click to verify your credentials with the Meta API.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="whatsapp_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="whatsapp_test_auto_reply_to" class="regular-text" placeholder="<?php esc_attr_e( '+1 555 000 1234 (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'To number (optional — if provided, the AI reply will be sent via WhatsApp)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="whatsapp_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="whatsapp_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="whatsapp_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="whatsapp_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
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

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$wa_assistants = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $wa_assistants as $wa_assistant ) :
								$is_selected = in_array( $wa_assistant->ID, $saved_assistant_ids, true ) ? 'selected="selected"' : '';
								?>
								<option value="<?php echo esc_attr( $wa_assistant->ID ); ?>" <?php echo esc_attr( $is_selected ); ?>>
									<?php echo esc_html( $wa_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. Selected assistants will automatically respond to members who message this WhatsApp number via the QR code or channel link below.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Channel QR Code', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$wa_phone_id     = $is_edit && isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
						$wa_display_phone = '';
						if ( $is_edit ) {
							// Prefer the manually saved display phone number.
							if ( ! empty( $connection['display_phone_number'] ) ) {
								$wa_display_phone = $connection['display_phone_number'];
							} elseif ( ! empty( $wa_phone_id ) ) {
								// Fall back to a cached test result if available.
								$wa_test_result = get_transient( 'wp_mcp_ai_test_result_' . ( isset( $connection['id'] ) ? $connection['id'] : '' ) );
								if ( ! empty( $wa_test_result['phone_number'] ) ) {
									$wa_display_phone = $wa_test_result['phone_number'];
								}
							}
						}
						if ( ! empty( $wa_display_phone ) ) :
							// Normalise phone number for wa.me link (digits only).
							$wa_phone_digits = preg_replace( '/[^0-9]/', '', $wa_display_phone );
							$wa_link         = 'https://wa.me/' . $wa_phone_digits;
							?>
							<div>
								<p style="margin: 0 0 8px 0;"><?php esc_html_e( 'Users can scan this QR code to start a WhatsApp conversation with your business number.', 'mcp-ai-wpoos-pro' ); ?></p>
								<canvas id="wa_channel_qr_canvas" width="180" height="180" style="border: 1px solid #ddd; border-radius: 4px; display: block; margin-bottom: 8px;"></canvas>
								<p style="margin: 0 0 4px 0;">
									<strong><?php esc_html_e( 'Channel Link:', 'mcp-ai-wpoos-pro' ); ?></strong>
									<a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $wa_link ); ?></a>
								</p>
								<p class="description"><?php esc_html_e( 'Copy the link above into any QR generator to create a printable QR code.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<script>
							( function() {
								var canvas  = document.getElementById( 'wa_channel_qr_canvas' );
								if ( ! canvas ) { return; }
								var link    = <?php echo wp_json_encode( $wa_link ); ?>;
								var ctx     = canvas.getContext( '2d' );
								var size    = 180;
								var cellSz  = 6;
								var modules = Math.floor( size / cellSz );
								/* Simple 2-D barcode placeholder rendered locally — no external request.
								   A full QR library can be enqueued separately; this shows the link. */
								ctx.fillStyle = '#ffffff';
								ctx.fillRect( 0, 0, size, size );
								ctx.fillStyle = '#25d366';
								ctx.font = 'bold 11px sans-serif';
								ctx.textAlign = 'center';
								ctx.fillText( '💬 WhatsApp', size / 2, size / 2 - 8 );
								ctx.fillStyle = '#000000';
								ctx.font = '9px monospace';
								ctx.fillText( link.replace( 'https://', '' ), size / 2, size / 2 + 10 );
								ctx.strokeStyle = '#25d366';
								ctx.lineWidth = 3;
								ctx.strokeRect( 4, 4, size - 8, size - 8 );
							} )();
							</script>
						<?php else : ?>
							<p class="description">
								<?php
								if ( $is_edit && ! empty( $wa_phone_id ) ) {
									esc_html_e( 'Enter your display phone number in the "Display Phone Number" field above, or run "Test Connection" to retrieve it automatically. Members can then use the generated QR code or link to start a conversation, and the assigned assistant will respond.', 'mcp-ai-wpoos-pro' );
								} else {
									esc_html_e( 'Save the connection with a Phone Number ID and optional Display Phone Number to generate a QR code and channel link. Members can scan the QR or use the link to message your WhatsApp number, and the assigned assistant will respond automatically.', 'mcp-ai-wpoos-pro' );
								}
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #25d366; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Get credentials from Meta Developer Dashboard (developers.facebook.com)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Get your System User Access Token from Meta Business Suite (Business Settings → System Users)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter your Phone Number ID and Display Phone Number (e.g. +1 555 000 1234)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign one or more AI Assistants — they will respond to members who message via the QR code or channel link', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Create a secure Verify Token (random string)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection, then click "Test Connection" to verify your credentials instantly', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Configure webhook in Meta dashboard using the Webhook URL and Verify Token', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0 0 6px 0; font-size: 13px; color: #2271b1;">
								ℹ <strong><?php esc_html_e( 'Advanced Access:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'The whatsapp_business_messaging permission is required for sending and receiving messages. Apply for Advanced Access via email in the Meta App Review portal. Quality Rating and phone display fields require the separate whatsapp_business_management permission.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0; font-size: 13px;">
								<strong><?php esc_html_e( 'Need help?', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php
								$docs_path = WP_MCP_AI_PRO_PATH . 'docs/WHATSAPP_SETUP_GUIDE.md';
								if ( file_exists( $docs_path ) ) {
									echo wp_kses_post( sprintf(
										/* translators: %s: link to setup guide */
										__( 'See our <a href="%s" target="_blank">complete WhatsApp setup guide</a> for detailed instructions.', 'mcp-ai-wpoos-pro' ),
										esc_url( WP_MCP_AI_PRO_URL . 'docs/WHATSAPP_SETUP_GUIDE.md' )
									) );
								} else {
									esc_html_e( 'See the complete setup guide in the plugin documentation.', 'mcp-ai-wpoos-pro' );
								}
								?>
							</p>
						</div>
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
						<label for="messenger_app_id"><?php esc_html_e( 'App ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="messenger_app_id" id="messenger_app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your App ID from the Meta Developer Dashboard. Required to generate an App Access Token.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_page_access_token"><?php esc_html_e( 'Page Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="messenger_page_access_token" id="messenger_page_access_token" class="regular-text" value="" autocomplete="new-password">
						<button type="button" id="messenger_access_token_toggle" class="button button-small" style="margin-left: 5px; vertical-align: middle;" aria-label="<?php esc_attr_e( 'Hide access token', 'mcp-ai-wpoos-pro' ); ?>"><?php esc_html_e( 'Hide', 'mcp-ai-wpoos-pro' ); ?></button>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing page access token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Facebook Page Access Token. Use "Generate App Access Token" below, or obtain a long-lived Page Access Token from Meta Business Suite or Graph API Explorer.', 'mcp-ai-wpoos-pro' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Your Facebook app secret from Meta Developer Dashboard (required for webhook signature validation).', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Generate App Access Token', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="messenger_generate_token_btn" class="button button-secondary">
							<?php esc_html_e( 'Generate App Access Token', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="messenger_token_status" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Enter your App ID and App Secret above, then click to generate an App Access Token. For full Page messaging, obtain a long-lived Page Access Token from Meta Business Suite.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_page_id"><?php esc_html_e( 'Page ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="messenger_page_id" id="messenger_page_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['page_id'] ) ? esc_attr( $connection['page_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Facebook Page ID. Used to verify connection and display page details during test.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="messenger_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Messenger Connection', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="messenger_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Enter your Page Access Token above, then click to verify credentials with the Meta API.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="messenger_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_graph_api_version"><?php esc_html_e( 'Graph API Version', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$saved_msng_version = $is_edit && isset( $connection['graph_api_version'] ) && $connection['graph_api_version'] ? $connection['graph_api_version'] : 'v21.0';
						$msng_versions      = array( 'v22.0', 'v21.0', 'v20.0', 'v19.0', 'v18.0' );
						?>
						<select name="messenger_graph_api_version" id="messenger_graph_api_version" class="regular-text">
							<?php foreach ( $msng_versions as $ver ) : ?>
								<option value="<?php echo esc_attr( $ver ); ?>" <?php selected( $saved_msng_version, $ver ); ?>><?php echo esc_html( $ver ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Meta Graph API version for Messenger API requests. Select the latest version supported by your Meta app.', 'mcp-ai-wpoos-pro' ); ?></p>
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

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #1877f2; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Get your App ID and App Secret from Meta Developer Dashboard (developers.facebook.com)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter your App ID and App Secret, then click "Generate App Access Token" for a server-level token', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'For full Page messaging, obtain a long-lived Page Access Token: go to Graph API Explorer → select your app → generate token with pages_messaging permission → exchange for long-lived token', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter your Page ID (optional, for reference)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Create a secure Verify Token (any random string)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection, then click "Test Connection" to verify your credentials', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Configure webhook in Meta dashboard using the Webhook URL, Verify Token, and subscribe to: messages, messaging_postbacks, messaging_optins', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0; font-size: 13px;">
								<strong><?php esc_html_e( 'Required permissions:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<code>pages_messaging</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>
							</p>
						</div>
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
				<?php if ( $is_edit && $editing ) : ?>
					<button type="button" id="wp_mcp_ai_test_connection_btn" class="button"
						data-connection-id="<?php echo esc_attr( $editing ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'test_connection_ajax' ) ); ?>">
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?>
					</button>
					<span id="wp_mcp_ai_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
				<?php endif; ?>
			</p>
			<?php if ( $is_edit && $editing ) : ?>
				<div id="wp_mcp_ai_test_result" style="display: none; margin-top: 10px;"></div>
			<?php endif; ?>
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
			if (connectionType === 'wordpress' || connectionType === 'generic') {
				authTypeRow.style.display = 'table-row';
			} else {
				authTypeRow.style.display = 'none';
			}

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
				// WhatsApp uses Cloud API — version is driven by the Cloud API Version dropdown
				var waVersionSelect = document.getElementById('whatsapp_graph_api_version');
				var waVersion = waVersionSelect ? waVersionSelect.value : 'v21.0';
				urlField.value = 'https://graph.facebook.com/' + waVersion;
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
				// Facebook Messenger uses Graph API — version is driven by the Graph API Version dropdown
				var msngVersionSelect = document.getElementById('messenger_graph_api_version');
				var msngVersion = msngVersionSelect ? msngVersionSelect.value : 'v21.0';
				urlField.value = 'https://graph.facebook.com/' + msngVersion;
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

			// WhatsApp Cloud API version change: update the URL field accordingly
			var waVersionSelect = document.getElementById('whatsapp_graph_api_version');
			if (waVersionSelect) {
				waVersionSelect.addEventListener('change', function() {
					var urlField = document.getElementById('url');
					if (urlField && urlField.readOnly) {
						urlField.value = 'https://graph.facebook.com/' + this.value;
					}
				});
			}

			// Facebook Messenger API version change: update the URL field accordingly
			var msngVersionSelect = document.getElementById('messenger_graph_api_version');
			if (msngVersionSelect) {
				msngVersionSelect.addEventListener('change', function() {
					var urlField = document.getElementById('url');
					if (urlField && urlField.readOnly) {
						urlField.value = 'https://graph.facebook.com/' + this.value;
					}
				});
			}

			// WhatsApp: Access Token show/hide toggle button.
			var tokenToggleBtn = document.getElementById('whatsapp_access_token_toggle');
			if (tokenToggleBtn) {
				tokenToggleBtn.addEventListener('click', function() {
					var tokenInput = document.getElementById('whatsapp_access_token');
					if (tokenInput.type === 'password') {
						tokenInput.type = 'text';
						tokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>;
						tokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>);
					} else {
						tokenInput.type = 'password';
						tokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Show', 'mcp-ai-wpoos-pro' ) ); ?>;
						tokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Show access token', 'mcp-ai-wpoos-pro' ) ); ?>);
					}
				});
			}

			// WhatsApp: inline Test Connection button (works before saving).
			var waTestBtn     = document.getElementById('whatsapp_test_connection_btn');
			var waTestSpinner = document.getElementById('whatsapp_test_spinner');
			var waTestResult  = document.getElementById('whatsapp_test_result');
			if (waTestBtn) {
				waTestBtn.addEventListener('click', function() {
					var accessToken    = document.getElementById('whatsapp_access_token').value.trim();
					var phoneNumberId  = document.getElementById('whatsapp_phone_number_id').value.trim();
					var connectionIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId   = connectionIdEl ? connectionIdEl.value.trim() : '';

					if (!accessToken && !connectionId) {
						if (waTestResult) {
							waTestResult.style.display = 'block';
							waTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Access Token first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}
					if (!phoneNumberId) {
						if (waTestResult) {
							waTestResult.style.display = 'block';
							waTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Phone Number ID first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					waTestBtn.disabled = true;
					if (waTestSpinner) { waTestSpinner.style.display = 'inline-block'; }
					if (waTestResult)  { waTestResult.style.display = 'none'; waTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_whatsapp_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_whatsapp_live' ) ); ?>);
					data.append('access_token', accessToken);
					var waVersionEl = document.getElementById('whatsapp_graph_api_version');
					if (waVersionEl && waVersionEl.value) { data.append('graph_api_version', waVersionEl.value); }
					data.append('phone_number_id', phoneNumberId);
					var appSecretEl = document.getElementById('whatsapp_app_secret');
					var appSecret = appSecretEl ? appSecretEl.value.trim() : '';
					if (appSecret) { data.append('app_secret', appSecret); }
					if (connectionId) { data.append('connection_id', connectionId); }
					var apiVersionEl = document.getElementById('whatsapp_graph_api_version');
					if (apiVersionEl) { data.append('graph_api_version', apiVersionEl.value.trim()); }

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							waTestBtn.disabled = false;
							if (waTestSpinner) { waTestSpinner.style.display = 'none'; }
							if (!waTestResult) { return; }
							waTestResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection test successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.phone_number)   { items.push(<?php echo wp_json_encode( __( 'Phone Number:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.phone_number); }
									if (d.verified_name)  { items.push(<?php echo wp_json_encode( __( 'Verified Name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.verified_name); }
									if (d.quality_rating) {
										var qRating = d.quality_rating.toUpperCase();
										var qColor = qRating === 'GREEN' ? '#00a32a' : (qRating === 'YELLOW' ? '#f0b849' : (qRating === 'UNKNOWN' ? '#777777' : '#d63638'));
										items.push(<?php echo wp_json_encode( __( 'Quality Rating:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <span style="color:' + qColor + ';font-weight:bold;">' + qRating + '</span>');
									}
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) {
										html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>';
									}
									if (d.quality_note) {
										html += '<p style="margin:6px 0 0;color:#2271b1;font-size:13px;">ℹ ' + d.quality_note + '</p>';
									}
									if (d.phone_number) {
										var dispInput = document.getElementById('whatsapp_display_phone_number');
										if (dispInput && !dispInput.value) {
											dispInput.value = d.phone_number;
										}
									}
								}
								html += '</div>';
								waTestResult.innerHTML = html;
							} else {
								waTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							waTestBtn.disabled = false;
							if (waTestSpinner) { waTestSpinner.style.display = 'none'; }
							if (waTestResult) {
								waTestResult.style.display = 'block';
								waTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}


			// WhatsApp: Test Auto-Reply button.
			var waAutoReplyBtn     = document.getElementById('whatsapp_test_auto_reply_btn');
			var waAutoReplySpinner = document.getElementById('whatsapp_test_auto_reply_spinner');
			var waAutoReplyResult  = document.getElementById('whatsapp_test_auto_reply_result');
			if (waAutoReplyBtn) {
				waAutoReplyBtn.addEventListener('click', function() {
					var msgEl = document.getElementById('whatsapp_test_auto_reply_msg');
					var toEl  = document.getElementById('whatsapp_test_auto_reply_to');
					var msg   = msgEl ? msgEl.value.trim() : '';
					var to    = toEl  ? toEl.value.trim()  : '';

					if (!msg) {
						if (waAutoReplyResult) {
							waAutoReplyResult.style.display = 'block';
							waAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					waAutoReplyBtn.disabled = true;
					if (waAutoReplySpinner) { waAutoReplySpinner.style.display = 'inline-block'; }
					if (waAutoReplyResult)  { waAutoReplyResult.style.display = 'none'; waAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_whatsapp_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_whatsapp_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (to) { data.append('test_to', to); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							waAutoReplyBtn.disabled = false;
							if (waAutoReplySpinner) { waAutoReplySpinner.style.display = 'none'; }
							if (!waAutoReplyResult) { return; }
							waAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #25d366;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'Reply sent to the test number via WhatsApp.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
								} else if (to && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but sending via WhatsApp failed.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
									if (d.send_error_hint) {
										html += '<p style="margin:2px 0 0;color:#d63638;font-size:12px;font-style:italic;">' + d.send_error_hint.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>';
									}
								}
								html += '</div>';
								waAutoReplyResult.innerHTML = html;
							} else {
								waAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							waAutoReplyBtn.disabled = false;
							if (waAutoReplySpinner) { waAutoReplySpinner.style.display = 'none'; }
							if (waAutoReplyResult) {
								waAutoReplyResult.style.display = 'block';
								waAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// WhatsApp: Retrieve Phone Numbers lookup button.
			var waLookupBtn    = document.getElementById('wp-mcp-ai-wa-lookup-phone-btn');
			var waLookupResult = document.getElementById('wp-mcp-ai-wa-lookup-phone-result');
			if (waLookupBtn) {
				waLookupBtn.addEventListener('click', function() {
					var wabaIdEl    = document.getElementById('whatsapp_business_account_id');
					var wabaId      = wabaIdEl ? wabaIdEl.value.trim() : '';
					var tokenEl     = document.getElementById('whatsapp_access_token');
					var accessToken = tokenEl ? tokenEl.value.trim() : '';

					if (!wabaId || !accessToken) {
						if (waLookupResult) {
							waLookupResult.innerHTML = '<span style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Please enter both a Business Account ID and an Access Token first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
						}
						return;
					}

					waLookupBtn.disabled    = true;
					waLookupBtn.textContent = <?php echo wp_json_encode( __( 'Retrieving…', 'mcp-ai-wpoos-pro' ) ); ?>;
					if (waLookupResult) { waLookupResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_fetch_whatsapp_phone_numbers');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_fetch_whatsapp_phone_numbers' ) ); ?>);
					data.append('business_account_id', wabaId);
					data.append('access_token', accessToken);
					var waVersionEl = document.getElementById('whatsapp_graph_api_version');
					if (waVersionEl && waVersionEl.value) { data.append('graph_api_version', waVersionEl.value); }

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r) {
							if (!r.ok) { throw new Error(r.status); }
							return r.json();
						})
						.then(function(json) {
							waLookupBtn.disabled    = false;
							waLookupBtn.textContent = <?php echo wp_json_encode( __( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ) ); ?>;
							if (!waLookupResult) { return; }
							if (!json.success) {
								waLookupResult.innerHTML = '<span style="color:#d63638;">' + (json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'Failed to retrieve phone numbers.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</span>';
								return;
							}
							var phones = json.data.phone_numbers;
							if (phones.length === 1) {
								document.getElementById('whatsapp_phone_number_id').value = phones[0].id;
								waLookupResult.innerHTML = '<span style="color:#00a32a;">' + <?php echo wp_json_encode( __( 'Phone number ID set automatically.', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + phones[0].display_name + ' (' + phones[0].id + ')</span>';
							} else {
								var sel = '<select id="wp-mcp-ai-wa-phone-select" style="max-width:350px;">';
								sel += '<option value="">' + <?php echo wp_json_encode( __( '-- Select a phone number --', 'mcp-ai-wpoos-pro' ) ); ?> + '</option>';
								phones.forEach(function(p) {
									sel += '<option value="' + p.id + '">' + p.display_name + (p.verified_name ? ' – ' + p.verified_name : '') + ' (' + p.id + ')</option>';
								});
								sel += '</select> <button type="button" id="wp-mcp-ai-wa-phone-apply" class="button">' + <?php echo wp_json_encode( __( 'Use Selected', 'mcp-ai-wpoos-pro' ) ); ?> + '</button>';
								waLookupResult.innerHTML = sel;
								document.getElementById('wp-mcp-ai-wa-phone-apply').addEventListener('click', function() {
									var selEl = document.getElementById('wp-mcp-ai-wa-phone-select');
									if (selEl && selEl.value) {
										document.getElementById('whatsapp_phone_number_id').value = selEl.value;
										waLookupResult.innerHTML = '<span style="color:#00a32a;">' + <?php echo wp_json_encode( __( 'Phone Number ID applied.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
									}
								});
							}
						})
						.catch(function() {
							waLookupBtn.disabled    = false;
							waLookupBtn.textContent = <?php echo wp_json_encode( __( 'Retrieve Phone Numbers', 'mcp-ai-wpoos-pro' ) ); ?>;
							if (waLookupResult) {
								waLookupResult.innerHTML = '<span style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
							}
						});
				});
			}


			// Messenger: Access Token show/hide toggle button.
			var msngTokenToggleBtn = document.getElementById('messenger_access_token_toggle');
			if (msngTokenToggleBtn) {
				msngTokenToggleBtn.addEventListener('click', function() {
					var tokenInput = document.getElementById('messenger_page_access_token');
					if (tokenInput.type === 'password') {
						tokenInput.type = 'text';
						msngTokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>;
						msngTokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>);
					} else {
						tokenInput.type = 'password';
						msngTokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Show', 'mcp-ai-wpoos-pro' ) ); ?>;
						msngTokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Show access token', 'mcp-ai-wpoos-pro' ) ); ?>);
					}
				});
			}

			// Messenger: Generate App Access Token button.
			var msngGenerateTokenBtn = document.getElementById('messenger_generate_token_btn');
			if (msngGenerateTokenBtn) {
				msngGenerateTokenBtn.addEventListener('click', function() {
					var appId     = document.getElementById('messenger_app_id').value.trim();
					var appSecret = document.getElementById('messenger_app_secret').value.trim();
					var statusEl  = document.getElementById('messenger_token_status');

					if (!appId) {
						statusEl.style.display = 'inline';
						statusEl.style.color = '#d63638';
						statusEl.textContent = <?php echo wp_json_encode( __( 'Please enter your App ID first.', 'mcp-ai-wpoos-pro' ) ); ?>;
						return;
					}
					if (!appSecret) {
						statusEl.style.display = 'inline';
						statusEl.style.color = '#d63638';
						statusEl.textContent = <?php echo wp_json_encode( __( 'Please enter your App Secret first.', 'mcp-ai-wpoos-pro' ) ); ?>;
						return;
					}

					msngGenerateTokenBtn.disabled = true;
					statusEl.style.display = 'inline';
					statusEl.style.color = '#646970';
					statusEl.textContent = <?php echo wp_json_encode( __( 'Generating…', 'mcp-ai-wpoos-pro' ) ); ?>;

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_generate_messenger_token');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_generate_messenger_token' ) ); ?>);
					data.append('app_id', appId);
					data.append('app_secret', appSecret);

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							msngGenerateTokenBtn.disabled = false;
							if (result.success) {
								var tokenInput = document.getElementById('messenger_page_access_token');
								tokenInput.value = result.data.access_token;
								tokenInput.type = 'text';
								if (msngTokenToggleBtn) {
									msngTokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>;
									msngTokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>);
								}
								statusEl.style.color = '#00a32a';
								statusEl.textContent = <?php echo wp_json_encode( __( '✓ App Access Token generated and populated.', 'mcp-ai-wpoos-pro' ) ); ?>;
							} else {
								statusEl.style.color = '#d63638';
								statusEl.textContent = result.data || <?php echo wp_json_encode( __( 'Failed to generate token.', 'mcp-ai-wpoos-pro' ) ); ?>;
							}
						})
						.catch(function() {
							msngGenerateTokenBtn.disabled = false;
							statusEl.style.color = '#d63638';
							statusEl.textContent = <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?>;
						});
				});
			}

			// Messenger: inline Test Connection button (works before saving).
			var msngTestBtn     = document.getElementById('messenger_test_connection_btn');
			var msngTestSpinner = document.getElementById('messenger_test_spinner');
			var msngTestResult  = document.getElementById('messenger_test_result');
			if (msngTestBtn) {
				msngTestBtn.addEventListener('click', function() {
					var accessToken = document.getElementById('messenger_page_access_token').value.trim();
					var pageId      = document.getElementById('messenger_page_id') ? document.getElementById('messenger_page_id').value.trim() : '';
					var apiVersion  = document.getElementById('messenger_graph_api_version') ? document.getElementById('messenger_graph_api_version').value : 'v21.0';

					if (!accessToken) {
						if (msngTestResult) {
							msngTestResult.style.display = 'block';
							msngTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Page Access Token first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					msngTestBtn.disabled = true;
					if (msngTestSpinner) { msngTestSpinner.style.display = 'inline-block'; }
					if (msngTestResult)  { msngTestResult.style.display = 'none'; msngTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_messenger_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_messenger_live' ) ); ?>);
					data.append('access_token', accessToken);
					var waVersionEl = document.getElementById('whatsapp_graph_api_version');
					if (waVersionEl && waVersionEl.value) { data.append('graph_api_version', waVersionEl.value); }
					data.append('page_id', pageId);
					data.append('api_version', apiVersion);

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							msngTestBtn.disabled = false;
							if (msngTestSpinner) { msngTestSpinner.style.display = 'none'; }
							if (!msngTestResult) { return; }
							msngTestResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection test successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.page_name)                         { items.push(<?php echo wp_json_encode( __( 'Page Name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.page_name); }
									if (d.page_id)                           { items.push(<?php echo wp_json_encode( __( 'Page ID:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.page_id); }
									if (d.category)                          { items.push(<?php echo wp_json_encode( __( 'Category:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.category); }
									if (d.fan_count !== undefined && d.fan_count !== '') { items.push(<?php echo wp_json_encode( __( 'Followers:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.fan_count); }
									if (d.token_type)                        { items.push(<?php echo wp_json_encode( __( 'Token Type:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.token_type); }
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) { html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>'; }
									if (d.quality_note) { html += '<p style="margin:6px 0 0;color:#2271b1;font-size:13px;">ℹ ' + d.quality_note + '</p>'; }
								}
								html += '</div>';
								msngTestResult.innerHTML = html;
							} else {
								msngTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							msngTestBtn.disabled = false;
							if (msngTestSpinner) { msngTestSpinner.style.display = 'none'; }
							if (msngTestResult) {
								msngTestResult.style.display = 'block';
								msngTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// 1-click connection test button (edit page, saved connections).
			var testBtn = document.getElementById('wp_mcp_ai_test_connection_btn');
			if (testBtn) {
				testBtn.addEventListener('click', function() {
					var connectionId = testBtn.getAttribute('data-connection-id');
					var nonce        = testBtn.getAttribute('data-nonce');
					var spinner      = document.getElementById('wp_mcp_ai_test_spinner');
					var resultDiv    = document.getElementById('wp_mcp_ai_test_result');

					testBtn.disabled = true;
					if (spinner) { spinner.style.display = 'inline-block'; }
					if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_remote_connection');
					data.append('nonce', nonce);
					data.append('connection_id', connectionId);

					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							testBtn.disabled = false;
							if (spinner) { spinner.style.display = 'none'; }
							if (!resultDiv) { return; }
							resultDiv.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection test successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.phone_number)   { items.push(<?php echo wp_json_encode( __( 'Phone Number:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.phone_number); }
									if (d.verified_name)  { items.push(<?php echo wp_json_encode( __( 'Verified Name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.verified_name); }
									if (d.quality_rating) {
										var qRating = d.quality_rating.toUpperCase();
										var qColor = qRating === 'GREEN' ? '#00a32a' : (qRating === 'YELLOW' ? '#f0b849' : (qRating === 'UNKNOWN' ? '#777777' : '#d63638'));
										items.push(<?php echo wp_json_encode( __( 'Quality Rating:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <span style="color:' + qColor + ';font-weight:bold;">' + qRating + '</span>');
									}
									if (d.business_name)  { items.push(<?php echo wp_json_encode( __( 'Business Profile:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.business_name); }
									if (d.site_name)      { items.push(<?php echo wp_json_encode( __( 'Site:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.site_name); }
									if (d.message && !d.phone_number && !d.site_name) { items.push(d.message); }
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) { html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>'; }
									if (d.quality_note) { html += '<p style="margin:6px 0 0;color:#2271b1;font-size:13px;">ℹ ' + d.quality_note + '</p>'; }
								}
								html += '</div>';
								resultDiv.innerHTML = html;
							} else {
								resultDiv.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							testBtn.disabled = false;
							if (spinner) { spinner.style.display = 'none'; }
							if (resultDiv) {
								resultDiv.style.display = 'block';
								resultDiv.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}
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

	/**
	 * AJAX handler: test a saved remote connection.
	 *
	 * Accepts: connection_id, nonce (POST).
	 * Returns JSON with test results on success, or error message on failure.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'test_connection_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Fetch WhatsApp phone numbers from the Facebook Graph API.
	 *
	 * Calls https://graph.facebook.com/{version}/{waba_id}/phone_numbers using the
	 * provided system user access token and returns the list of phone numbers.
	 * The API version defaults to v22.0 but respects the graph_api_version POST parameter.
	 *
	 * @since 1.0.0
	 */
	public function ajax_fetch_whatsapp_phone_numbers() {
		check_ajax_referer( 'wp_mcp_ai_fetch_whatsapp_phone_numbers', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$business_account_id = isset( $_POST['business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['business_account_id'] ) ) : '';
		$access_token        = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token        = trim( (string) $access_token );

		if ( empty( $business_account_id ) || empty( $access_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Business Account ID and Access Token are required.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$raw_version       = isset( $_POST['graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['graph_api_version'] ) ) : '';
		$graph_api_version = ( preg_match( '/^v\d+\.\d+$/', $raw_version ) ) ? $raw_version : 'v22.0';

		$api_url = add_query_arg(
			array( 'fields' => 'id,display_phone_number,verified_name' ),
			sprintf( 'https://graph.facebook.com/%s/%s/phone_numbers', $graph_api_version, rawurlencode( $business_account_id ) )
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Connection to the Facebook Graph API failed. Please check your network and try again.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || isset( $data['error'] ) ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Failed to retrieve phone numbers.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		$phone_numbers = array();
		if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $phone ) {
				$phone_numbers[] = array(
					'id'            => isset( $phone['id'] ) ? sanitize_text_field( $phone['id'] ) : '',
					'display_name'  => isset( $phone['display_phone_number'] ) ? sanitize_text_field( $phone['display_phone_number'] ) : '',
					'verified_name' => isset( $phone['verified_name'] ) ? sanitize_text_field( $phone['verified_name'] ) : '',
				);
			}
		}

		if ( empty( $phone_numbers ) ) {
			wp_send_json_error( array( 'message' => __( 'No phone numbers found for this Business Account ID.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		wp_send_json_success( array( 'phone_numbers' => $phone_numbers ) );
	}

	/**
	 * AJAX handler: test a WhatsApp connection using credentials posted directly from the form.
	 *
	 * Accepts: access_token, phone_number_id, nonce (POST).
	 * Returns JSON with connection details on success, or error message on failure.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_whatsapp_live() {
		check_ajax_referer( 'wp_mcp_ai_test_whatsapp_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$access_token    = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token    = trim( (string) $access_token );
		$phone_number_id = isset( $_POST['phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) ) : '';
		$app_secret      = isset( $_POST['app_secret'] ) ? wp_unslash( $_POST['app_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- app secrets must not be sanitized as sanitize_text_field() can truncate valid characters.
		$app_secret      = trim( (string) $app_secret );

		// When the access token field is left blank (e.g. on page reload), fall back to the
		// stored credentials for the connection being edited so the test can proceed without
		// the user having to re-enter sensitive credentials.
		if ( empty( $access_token ) ) {
			$connection_id      = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection  = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['api_key'] ) ) {
				$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}
			if ( empty( $app_secret ) && ! empty( $stored_connection['api_secret'] ) ) {
				$app_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_secret'] );
			}
		}

		// Compute appsecret_proof only when the user explicitly provides the App Secret in the
		// test form. appsecret_proof is only required when the Meta app has "Require App Secret
		// Proof for Server API calls" enabled in App Dashboard → Settings → Advanced.
		$appsecret_proof = ! empty( $app_secret ) ? hash_hmac( 'sha256', $access_token, $app_secret ) : '';

		if ( empty( $access_token ) ) {
			wp_send_json_error( __( 'Access Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( empty( $phone_number_id ) ) {
			wp_send_json_error( __( 'Phone Number ID is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Reject App Access Tokens — they have the format "{numeric_app_id}|{hash}" and cannot
		// send or receive WhatsApp Cloud API messages. Users must supply a System User
		// Access Token (obtained from Meta Business Suite → System Users) or a User
		// Access Token with the whatsapp_business_messaging permission.
		// Meta App Access Tokens always start with the numeric App ID followed by a pipe,
		// so a leading-digits-pipe pattern is a reliable and specific heuristic.
		if ( 1 === preg_match( '/^\d+\|/', $access_token ) ) {
			wp_send_json_error(
				__( 'The token you entered appears to be a Meta App Access Token (format: {app_id}|{hash}). App Access Tokens cannot send or receive WhatsApp messages via the Cloud API. Please enter a System User Access Token from Meta Business Suite (Business Settings → System Users) or a User Access Token with the whatsapp_business_messaging permission.', 'mcp-ai-wpoos-pro' )
			);
			return;
		}

		// Verify the token against the WhatsApp Cloud API phone number endpoint.
		// Only request fields accessible with whatsapp_business_messaging permission.
		// quality_rating requires whatsapp_business_management and causes a 403 with App Access Tokens.
		$raw_version       = isset( $_POST['graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['graph_api_version'] ) ) : '';
		$graph_api_version = ( preg_match( '/^v\d+\.\d+$/', $raw_version ) ) ? $raw_version : 'v22.0';
		$phone_query_args  = array( 'fields' => 'display_phone_number,verified_name' );
		if ( $appsecret_proof ) {
			$phone_query_args['appsecret_proof'] = $appsecret_proof;
		}
		$phone_endpoint = add_query_arg(
			$phone_query_args,
			sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) )
		);

		$phone_response = wp_remote_get(
			$phone_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $phone_response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to WhatsApp API: %s', 'mcp-ai-wpoos-pro' ),
					$phone_response->get_error_message()
				)
			);
			return;
		}

		$phone_code = wp_remote_retrieve_response_code( $phone_response );
		$phone_data = json_decode( wp_remote_retrieve_body( $phone_response ), true );

		$limited_field_access = false;
		if ( 200 !== (int) $phone_code ) {
			$fb_error_code    = isset( $phone_data['error']['code'] ) ? (int) $phone_data['error']['code'] : 0;
			$fb_error_subcode = isset( $phone_data['error']['error_subcode'] ) ? (int) $phone_data['error']['error_subcode'] : 0;
			$error_message    = isset( $phone_data['error']['message'] ) ? $phone_data['error']['message'] : __( 'Invalid response from WhatsApp API.', 'mcp-ai-wpoos-pro' );

			// Error code 100 with subcode 33 means the object (phone number) does not exist
			// or the token lacks permissions. The most common cause is entering the WhatsApp
			// Business Account ID (WABA ID) instead of the Phone Number ID. Fail immediately
			// with an actionable message instead of silently falling back to "limited access".
			if ( 100 === $fb_error_code && 33 === $fb_error_subcode ) {
				wp_send_json_error(
					__( 'Phone Number ID not found. The ID you entered does not match any WhatsApp phone number accessible with your access token. Make sure you are entering the Phone Number ID from the Meta Developer Dashboard (select your app → WhatsApp → API Setup → "Phone Number ID") — not the WhatsApp Business Account ID (WABA ID) visible in Meta Business Manager or Facebook Business pages. These are different numbers.', 'mcp-ai-wpoos-pro' )
				);
				return;
			}

			// When appsecret_proof is invalid (HTTP 400), the stored app secret does not
			// match the app or the app does not require it.  Clear appsecret_proof and retry
			// without it so the connection test can still succeed with a valid access token.
			if ( 400 === (int) $phone_code && $appsecret_proof && false !== stripos( $error_message, 'appsecret_proof' ) ) {
				$appsecret_proof  = '';
				$retry_query_args = array( 'fields' => 'display_phone_number,verified_name' );
				$retry_endpoint   = add_query_arg(
					$retry_query_args,
					sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) )
				);
				$retry_response = wp_remote_get(
					$retry_endpoint,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
						),
						'timeout' => 15,
					)
				);
				if ( ! is_wp_error( $retry_response ) && 200 === (int) wp_remote_retrieve_response_code( $retry_response ) ) {
					$phone_data = json_decode( wp_remote_retrieve_body( $retry_response ), true );
				} else {
					wp_send_json_error(
						sprintf(
							/* translators: 1: status code, 2: error message */
							__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
							$phone_code,
							$error_message
						)
					);
					return;
				}

			// When no appsecret_proof was sent but Meta still returns 400 "Invalid appsecret_proof",
			// the app has "Require App Secret Proof" enabled.  Guide the user to enter the App Secret.
			} elseif ( 400 === (int) $phone_code && ! $appsecret_proof && false !== stripos( $error_message, 'appsecret_proof' ) ) {
				wp_send_json_error(
					__( 'The Meta app requires App Secret Proof for API calls. Please enter your Meta App Secret in the App Secret field and try again.', 'mcp-ai-wpoos-pro' )
				);
				return;

			// When the token lacks field-level access (Facebook error code 200 = permission
			// error on a specific field), fall back to the base endpoint which returns only
			// the phone number ID.  This lets tokens with whatsapp_business_messaging scope
			// for sending but without phone-number field read permissions still pass.
			} elseif ( 403 === (int) $phone_code && 200 === $fb_error_code ) {
				$fallback_base     = sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) );
				$fallback_endpoint = $appsecret_proof ? add_query_arg( 'appsecret_proof', $appsecret_proof, $fallback_base ) : $fallback_base;
				$fallback_response = wp_remote_get(
					$fallback_endpoint,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
						),
						'timeout' => 15,
					)
				);
				if ( ! is_wp_error( $fallback_response ) && 200 === (int) wp_remote_retrieve_response_code( $fallback_response ) ) {
					$phone_data           = json_decode( wp_remote_retrieve_body( $fallback_response ), true );
					$limited_field_access = true;
				} else {
					// Check if the fallback also returned a field-permission error (FB code 200).
					// This means the token is valid but lacks whatsapp_business_management permission
					// to read phone number display fields. Tokens with whatsapp_business_messaging
					// scope (granted via Meta Advanced Access / email approval) can still send and
					// receive messages — only cosmetic display fields are unavailable.
					$fallback_http_code  = ! is_wp_error( $fallback_response ) ? (int) wp_remote_retrieve_response_code( $fallback_response ) : 0;
					$fallback_body       = ! is_wp_error( $fallback_response ) ? json_decode( wp_remote_retrieve_body( $fallback_response ), true ) : array();
					$fallback_error_code = isset( $fallback_body['error']['code'] ) ? (int) $fallback_body['error']['code'] : 0;

					if ( 403 === $fallback_http_code && 200 === $fallback_error_code ) {
						$phone_data           = array();
						$limited_field_access = true;
					} else {
						wp_send_json_error(
							sprintf(
								/* translators: 1: status code, 2: error message */
								__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
								$phone_code,
								$error_message
							)
						);
						return;
					}
				}

			// When the API returns HTTP 400 with FB error code 100 ("Tried accessing nonexisting
			// field"), the token cannot read display_phone_number or verified_name as explicit
			// field parameters. Fall back to the base phone number endpoint which returns default
			// fields for tokens with sufficient permissions, or just the ID for messaging-only tokens.
			} elseif ( 400 === (int) $phone_code && 100 === $fb_error_code ) {
				$fallback_base     = sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) );
				$fallback_endpoint = $appsecret_proof ? add_query_arg( 'appsecret_proof', $appsecret_proof, $fallback_base ) : $fallback_base;
				$fallback_response = wp_remote_get(
					$fallback_endpoint,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
						),
						'timeout' => 15,
					)
				);
				if ( ! is_wp_error( $fallback_response ) && 200 === (int) wp_remote_retrieve_response_code( $fallback_response ) ) {
					$phone_data           = json_decode( wp_remote_retrieve_body( $fallback_response ), true );
					$limited_field_access = true;
				} else {
					$fallback_http_code  = ! is_wp_error( $fallback_response ) ? (int) wp_remote_retrieve_response_code( $fallback_response ) : 0;
					$fallback_body       = ! is_wp_error( $fallback_response ) ? json_decode( wp_remote_retrieve_body( $fallback_response ), true ) : array();
					$fallback_error_code = isset( $fallback_body['error']['code'] ) ? (int) $fallback_body['error']['code'] : 0;

					if ( ( 403 === $fallback_http_code && 200 === $fallback_error_code ) || ( 400 === $fallback_http_code && 100 === $fallback_error_code ) ) {
						$phone_data           = array();
						$limited_field_access = true;
					} else {
						wp_send_json_error(
							sprintf(
								/* translators: 1: status code, 2: error message */
								__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
								$phone_code,
								$error_message
							)
						);
						return;
					}
				}
			} else {
				wp_send_json_error(
					sprintf(
						/* translators: 1: status code, 2: error message */
						__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
						$phone_code,
						$error_message
					)
				);
				return;
			}
		}

		// Optionally fetch quality_rating as a separate request — requires whatsapp_business_management permission.
		// Treat a 403 response as advisory only (quality_rating requires
		// whatsapp_business_management, not whatsapp_business_messaging).
		$quality                  = 'UNKNOWN';
		$quality_permission_denied = false;

		$quality_query_args = array( 'fields' => 'quality_rating' );
		if ( $appsecret_proof ) {
			$quality_query_args['appsecret_proof'] = $appsecret_proof;
		}
		$quality_endpoint = add_query_arg(
			$quality_query_args,
			sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) )
		);
		$quality_response = wp_remote_get(
			$quality_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);
		if ( ! is_wp_error( $quality_response ) && 200 === (int) wp_remote_retrieve_response_code( $quality_response ) ) {
			$quality_data = json_decode( wp_remote_retrieve_body( $quality_response ), true );
			if ( isset( $quality_data['quality_rating'] ) ) {
				$quality = strtoupper( $quality_data['quality_rating'] );
			}
		} else {
			// Non-200 response or WP error means the token lacks whatsapp_business_management permission.
			$quality_permission_denied = true;
		}

		$result = array(
			'phone_number'   => isset( $phone_data['display_phone_number'] ) ? $phone_data['display_phone_number'] : '',
			'verified_name'  => isset( $phone_data['verified_name'] ) ? $phone_data['verified_name'] : '',
			'quality_rating' => $quality,
			'message'        => __( 'WhatsApp connection successful! Phone number verified and API credentials valid.', 'mcp-ai-wpoos-pro' ),
		);

		// Detect when a WhatsApp Business Account (WABA) ID was entered instead of a
		// Phone Number ID. The Meta Graph API returns HTTP 200 for WABA IDs, but the
		// response contains no phone-number-specific fields (display_phone_number or
		// verified_name). When field access is not limited by token permissions, the
		// absence of these fields reliably indicates a WABA ID was entered.
		if ( ! $limited_field_access && '' === $result['phone_number'] && '' === $result['verified_name'] ) {
			wp_send_json_error(
				__( 'The ID you entered does not appear to be a WhatsApp Phone Number ID — the Meta API returned no phone-number details. This usually means the WhatsApp Business Account (WABA) ID was entered instead of the Phone Number ID. To find your Phone Number ID: go to the Meta Developer Dashboard → select your app → WhatsApp → API Setup → copy the "Phone Number ID" field (it is different from the WABA ID shown in Meta Business Manager).', 'mcp-ai-wpoos-pro' )
			);
			return;
		}

		// When quality is UNKNOWN, add an explanatory note (not a warning — messaging is unaffected).
		if ( 'UNKNOWN' === $quality ) {
			if ( $quality_permission_denied ) {
				// The quality API call failed — the token likely lacks whatsapp_business_management.
				$result['quality_note'] = __( 'Quality Rating is UNKNOWN because it requires the whatsapp_business_management permission. This does not affect messaging. If you have whatsapp_business_messaging access (enabled via Meta Advanced Access / email approval), your bot will send and receive messages normally.', 'mcp-ai-wpoos-pro' );
			} else {
				// The API call succeeded but Meta has not yet assigned a quality rating to this number
				// (e.g. new number or insufficient messaging volume). This is normal for new accounts.
				$result['quality_note'] = __( 'Quality Rating is UNKNOWN — Meta has not yet assigned a rating to this phone number. This is normal for new numbers or those with low messaging volume and does not affect messaging.', 'mcp-ai-wpoos-pro' );
			}
		}

		// Note when the token lacks permission to read phone-number details.
		if ( $limited_field_access ) {
			$result['warning'] = __( 'Note: Phone number display details are unavailable (requires whatsapp_business_management permission). If your access token has the whatsapp_business_messaging scope — enabled via Meta Advanced Access (email approval) — messaging will work normally. Enter your display phone number manually in the "Display Phone Number" field to generate the channel QR code and link.', 'mcp-ai-wpoos-pro' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: test the WhatsApp auto-reply flow.
	 *
	 * Simulates an incoming WhatsApp message by calling the internal chat endpoint
	 * with the first assigned assistant for the connection. If a recipient phone
	 * number is provided the AI reply is also sent via the WhatsApp Cloud API so
	 * the end-to-end flow (webhook → AI → send) can be verified from the admin UI.
	 *
	 * Accepts (POST): connection_id, test_message, test_to (optional), nonce.
	 * Returns JSON success with ai_reply and sent (bool), or an error string.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_whatsapp_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_whatsapp_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_to       = isset( $_POST['test_to'] ) ? sanitize_text_field( wp_unslash( $_POST['test_to'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( '' === $test_message ) {
			wp_send_json_error( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			wp_send_json_error( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			wp_send_json_error( __( 'No assistants are assigned to this connection. Please assign at least one assistant and save before testing auto-reply.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$assistant_id = $assigned_assistant_ids[0];

		// Call the internal chat REST endpoint using an admin user context.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => $test_message,
					),
				),
				'stream'       => false,
			)
		);

		$original_user_id = get_current_user_id();
		// The current user is already an admin (capability check above), but we
		// need to set the nonce so the chat endpoint accepts the internal request.
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			$error_data = $response->get_data();
			$code       = is_array( $error_data ) && isset( $error_data['code'] ) ? $error_data['code'] : 'unknown_error';
			wp_send_json_error(
				sprintf(
					/* translators: %s: error code */
					__( 'The AI assistant returned an error (%s). Check that the assistant is configured correctly and that your AI provider credentials are valid.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
			return;
		}

		// Extract the assistant reply text from the chat endpoint response.
		$ai_reply = '';
		$data     = $response->get_data();
		if ( is_array( $data ) ) {
			$llm_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
			$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();
			if ( ! empty( $choices ) ) {
				$first_choice = reset( $choices );
				if ( isset( $first_choice['message']['content'] ) && is_string( $first_choice['message']['content'] ) ) {
					$ai_reply = $first_choice['message']['content'];
				}
			}
		}

		if ( '' === $ai_reply ) {
			wp_send_json_error( __( 'The AI assistant returned an empty reply. Check the assistant configuration and AI provider settings.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$result = array(
			'ai_reply' => $ai_reply,
			'sent'     => false,
		);

		// If a recipient number was provided, send the reply via the WhatsApp Cloud API.
		if ( ! empty( $test_to ) && ! empty( $connection['api_key'] ) && ! empty( $connection['phone_number_id'] ) ) {
			$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			if ( '' !== $access_token ) {
				$graph_api_version = isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
					? sanitize_text_field( $connection['graph_api_version'] )
					: 'v19.0';
				$phone_number_id   = sanitize_text_field( $connection['phone_number_id'] );
				$sanitized_to      = preg_replace( '/[^0-9+]/', '', $test_to );

				// WhatsApp does not render HTML. Strip tags and decode HTML entities so the
				// message body contains plain text only, and enforce the 4096-character limit.
				$whatsapp_body = wp_strip_all_tags( $ai_reply );
				$whatsapp_body = html_entity_decode( $whatsapp_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( mb_strlen( $whatsapp_body ) > 4096 ) {
					$whatsapp_body = mb_substr( $whatsapp_body, 0, 4093 ) . '...';
				}

				$endpoint = sprintf(
					'https://graph.facebook.com/%s/%s/messages',
					rawurlencode( $graph_api_version ),
					rawurlencode( $phone_number_id )
				);

				$payload = array(
					'messaging_product' => 'whatsapp',
					'to'                => $sanitized_to,
					'type'              => 'text',
					'text'              => array( 'body' => $whatsapp_body ),
				);

				$body = wp_json_encode( $payload );
				if ( false !== $body ) {
					$send_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array(
								'Content-Type'  => 'application/json',
								'Authorization' => 'Bearer ' . $access_token,
							),
							'timeout' => 20,
							'body'    => $body,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_body            = is_wp_error( $send_result ) ? '' : wp_remote_retrieve_body( $send_result );
						$result['send_error'] = is_wp_error( $send_result )
							? $send_result->get_error_message()
							: $send_body;

						// Surface a user-friendly hint for the most common Meta API errors.
						$error_data = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
						if ( is_array( $error_data ) && isset( $error_data['error'] ) && is_array( $error_data['error'] ) && isset( $error_data['error']['code'] ) ) {
							$meta_code    = (int) $error_data['error']['code'];
							$meta_subcode = isset( $error_data['error']['error_subcode'] ) ? (int) $error_data['error']['error_subcode'] : 0;
							if ( 100 === $meta_code && 33 === $meta_subcode ) {
								$result['send_error_hint'] = __( 'Phone Number ID not found. The ID configured for this connection does not match any WhatsApp phone number accessible with your access token. Find the correct Phone Number ID in the Meta Developer Dashboard: select your app → WhatsApp → API Setup → "Phone Number ID". This is different from the WhatsApp Business Account ID (WABA ID) shown in Meta Business Manager or Facebook Business pages.', 'mcp-ai-wpoos-pro' );
							}
						}
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: generate a Facebook Messenger App Access Token from Meta's API.
	 *
	 * Uses the client_credentials grant to obtain an App Access Token.
	 * For full Page-level Messenger messaging a long-lived Page Access Token
	 * should be obtained from the Meta Graph API Explorer or Meta Business Suite.
	 *
	 * Accepts: app_id, app_secret, nonce (POST).
	 * Returns JSON with access_token on success, or error message on failure.
	 *
	 * @since 1.0.0
	 */
	public function ajax_generate_messenger_token() {
		check_ajax_referer( 'wp_mcp_ai_generate_messenger_token', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$app_id     = isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '';
		$app_secret = isset( $_POST['app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['app_secret'] ) ) : '';

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			wp_send_json_error( __( 'App ID and App Secret are required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'grant_type'    => 'client_credentials',
				),
				'https://graph.facebook.com/oauth/access_token'
			),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( __( 'Could not connect to Meta API. Please check your credentials and try again.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $status_code || empty( $body['access_token'] ) ) {
			$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Failed to retrieve token from Meta API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error( $error_message );
			return;
		}

		wp_send_json_success( array( 'access_token' => sanitize_text_field( $body['access_token'] ) ) );
	}

	/**
	 * AJAX handler: test a Facebook Messenger connection using credentials posted directly from the form.
	 *
	 * Verifies the access token by calling GET /me or GET /{page-id} on the Meta Graph API
	 * and returns page name, category, and follower count on success.
	 *
	 * Accepts: access_token, page_id (optional), api_version, nonce (POST).
	 * Returns JSON with page details on success, or error message on failure.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_messenger_live() {
		check_ajax_referer( 'wp_mcp_ai_test_messenger_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$access_token = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token = trim( (string) $access_token );
		$page_id      = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : '';
		$api_version  = isset( $_POST['api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['api_version'] ) ) : 'v21.0';

		// Validate API version format.
		if ( ! preg_match( '/^v\d+\.\d+$/', $api_version ) ) {
			$api_version = 'v21.0';
		}

		if ( empty( $access_token ) ) {
			wp_send_json_error( __( 'Access Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// If a page ID is provided, query that page directly; otherwise query /me (app token).
		$target  = ! empty( $page_id ) ? rawurlencode( $page_id ) : 'me';
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s?fields=id,name,category,fan_count',
			$api_version,
			$target
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Messenger API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $status_code ) {
			$error_message = __( 'Invalid response from Messenger API.', 'mcp-ai-wpoos-pro' );
			if ( isset( $body['error']['message'] ) ) {
				$error_message = $body['error']['message'];
			}
			wp_send_json_error(
				sprintf(
					/* translators: 1: status code, 2: error message */
					__( 'Messenger API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$error_message
				)
			);
			return;
		}

		$result = array(
			'page_name'  => isset( $body['name'] ) ? $body['name'] : '',
			'page_id'    => isset( $body['id'] ) ? $body['id'] : '',
			'category'   => isset( $body['category'] ) ? $body['category'] : '',
			'fan_count'  => isset( $body['fan_count'] ) ? (int) $body['fan_count'] : '',
			'token_type' => ! empty( $page_id ) ? __( 'Page Access Token', 'mcp-ai-wpoos-pro' ) : __( 'App Access Token', 'mcp-ai-wpoos-pro' ),
			'message'    => __( 'Messenger connection successful! Credentials are valid.', 'mcp-ai-wpoos-pro' ),
		);

		// Warn when an App Access Token is used — it can verify identity but cannot send messages.
		if ( empty( $page_id ) ) {
			$result['warning'] = __( 'App Access Token detected. To send messages via Messenger, obtain a Page Access Token with pages_messaging permission from Meta Business Suite or Graph API Explorer.', 'mcp-ai-wpoos-pro' );
		}

		wp_send_json_success( $result );
	}
}

// Initialize the admin interface.
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Remote_Sites_Admin();

	// Initialize bidirectional sync for mesh peers.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-mesh-peer-bidirectional-sync.php';
	new WP_MCP_AI_Pro_Mesh_Peer_Bidirectional_Sync();
}
