<?php
/**
 * Admin UI for managing remote WordPress/WooCommerce site connections.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
		add_action( 'wp_ajax_wp_mcp_ai_test_messenger_auto_reply', array( $this, 'ajax_test_messenger_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_remote_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wp_mcp_ai_discover_jetengine_ccts', array( $this, 'ajax_discover_jetengine_ccts' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_whatsapp_phone_numbers', array( $this, 'ajax_fetch_whatsapp_phone_numbers' ) );
		add_action( 'wp_ajax_wp_mcp_ai_register_whatsapp_phone_number', array( $this, 'ajax_register_whatsapp_phone_number' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_whatsapp_group', array( $this, 'ajax_create_whatsapp_group' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_google_chat_live', array( $this, 'ajax_test_google_chat_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_fetch_google_chat_spaces', array( $this, 'ajax_fetch_google_chat_spaces' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_google_chat_auto_reply', array( $this, 'ajax_test_google_chat_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_google_chat_incoming_trigger', array( $this, 'ajax_test_google_chat_incoming_trigger' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_google_chat_webhook_log', array( $this, 'ajax_get_google_chat_webhook_log' ) );
		add_action( 'wp_ajax_wp_mcp_ai_clear_google_chat_webhook_log', array( $this, 'ajax_clear_google_chat_webhook_log' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_telegram_live', array( $this, 'ajax_test_telegram_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_telegram_auto_reply', array( $this, 'ajax_test_telegram_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_telegram_send_group', array( $this, 'ajax_test_telegram_send_group' ) );
		add_action( 'wp_ajax_wp_mcp_ai_set_telegram_webhook', array( $this, 'ajax_set_telegram_webhook' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_telegram_webhook_info', array( $this, 'ajax_get_telegram_webhook_info' ) );
		add_action( 'wp_ajax_wp_mcp_ai_register_telegram_commands', array( $this, 'ajax_register_telegram_commands' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_slack_live', array( $this, 'ajax_test_slack_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_slack_auto_reply', array( $this, 'ajax_test_slack_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_discord_live', array( $this, 'ajax_test_discord_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_discord_auto_reply', array( $this, 'ajax_test_discord_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_teams_live', array( $this, 'ajax_test_teams_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_teams_auto_reply', array( $this, 'ajax_test_teams_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_generate_teams_manifest', array( $this, 'ajax_generate_teams_manifest' ) );
		add_action( 'wp_ajax_wp_mcp_ai_generate_teams_app_package', array( $this, 'ajax_generate_teams_app_package' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_office365_live', array( $this, 'ajax_test_office365_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_office365_auto_reply', array( $this, 'ajax_test_office365_auto_reply' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_icloud_live', array( $this, 'ajax_test_icloud_live' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_icloud_auto_reply', array( $this, 'ajax_test_icloud_auto_reply' ) );
	}

	/**
	 * Allow OAuth redirect hosts for remote connection types.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public function allow_google_oauth_host( $hosts ) {
		$hosts[] = 'accounts.google.com';
		$hosts[] = 'login.microsoftonline.com';
		$hosts[] = 'www.upwork.com'; // Upwork OAuth2 authorization endpoint.
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

		// Handle Upwork OAuth connect action.
		if ( 'upwork_oauth_connect' === $oauth_handler && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = sanitize_key( wp_unslash( $_GET['_wpnonce'] ) );
			$connection_id = sanitize_key( wp_unslash( $_GET['connection_id'] ) );
			if ( ! wp_verify_nonce( $nonce, 'upwork_oauth_connect_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}
			$this->handle_upwork_oauth_start( $connection_id );
		}

		// Handle Upwork OAuth callback action.
		if ( 'upwork_oauth_callback' === $oauth_handler ) {
			$this->handle_upwork_oauth_callback();
		}

		// Handle LinkedIn OAuth connect action.
		if ( 'linkedin_oauth_connect' === $oauth_handler && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = sanitize_key( wp_unslash( $_GET['_wpnonce'] ) );
			$connection_id = sanitize_key( wp_unslash( $_GET['connection_id'] ) );
			if ( ! wp_verify_nonce( $nonce, 'linkedin_oauth_connect_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}
			$this->handle_linkedin_oauth_start( $connection_id );
		}

		// Handle LinkedIn OAuth callback action.
		if ( 'linkedin_oauth_callback' === $oauth_handler ) {
			$this->handle_linkedin_oauth_callback();
		}

		// Handle Google Chat OAuth connect action.
		if ( 'google_chat_oauth_connect' === $oauth_handler && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'google_chat_oauth_connect_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			$this->handle_google_chat_oauth_start( $connection_id );
		}

		// Handle Google Chat OAuth callback action.
		if ( 'google_chat_oauth_callback' === $oauth_handler ) {
			$this->handle_google_chat_oauth_callback();
		}

		// Handle Microsoft Teams OAuth connect action.
		if ( 'teams_oauth_connect' === $oauth_handler && isset( $_GET['connection_id'] ) && isset( $_GET['_wpnonce'] ) ) {
			$nonce         = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'teams_oauth_connect_' . $connection_id ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			$this->handle_teams_oauth_start( $connection_id );
		}

		// Handle Microsoft Teams OAuth callback action.
		if ( 'teams_oauth_callback' === $oauth_handler ) {
			$this->handle_teams_oauth_callback();
		}

		// Handle save action.
		if ( isset( $_POST['wp_mcp_ai_pro_save_connection'] ) && isset( $_POST['_wpnonce'] ) ) {
			$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! wp_verify_nonce( $nonce, 'save_remote_connection' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
			}

			// Get connection type first to determine which fields to use.
				$connection_type = isset( $_POST['connection_type'] ) ? sanitize_key( wp_unslash( $_POST['connection_type'] ) ) : 'WordPress';

				// Normalise canonical casing. The select element sends lowercase values
					// (e.g., the lowercased form of the WordPress string), but downstream
					// code and existing stored data use the canonical capital-W form
					// 'WordPress'. Without this normalisation, saved connections would
					// have the wrong case and the edit-form JavaScript toggle plus other
					// type-guards would fail to match.
					// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- intentional lowercase comparison
			if ( 'wordpress' === $connection_type ) {
				$connection_type = 'WordPress';
			}

				// Map connection-type-specific fields to generic field names.
			$api_key        = '';
			$api_secret     = '';
			$client_id      = '';
			$client_secret  = '';
			$refresh_token  = '';
			$user_email     = '';
			$app_id         = '';
			$signing_secret = '';
			$dsn_name       = '';
			$gc_method      = 'service_account';

			switch ( $connection_type ) {
				case 'mesh_peer':
					$api_key = isset( $_POST['mesh_inbound_api_key'] ) ? wp_unslash( $_POST['mesh_inbound_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'twitter':
					$api_key       = isset( $_POST['twitter_api_key'] ) ? wp_unslash( $_POST['twitter_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret    = isset( $_POST['twitter_api_secret'] ) ? wp_unslash( $_POST['twitter_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$client_id     = isset( $_POST['twitter_access_token'] ) ? wp_unslash( $_POST['twitter_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$client_secret = isset( $_POST['twitter_access_token_secret'] ) ? wp_unslash( $_POST['twitter_access_token_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
				case 'quickbooks_desktop':
					$api_key  = isset( $_POST['qbd_api_key'] ) ? wp_unslash( $_POST['qbd_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$dsn_name = isset( $_POST['qbd_dsn_name'] ) ? sanitize_text_field( wp_unslash( $_POST['qbd_dsn_name'] ) ) : '';
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
				case 'upwork':
					$client_id     = isset( $_POST['upwork_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upwork_client_id'] ) ) : '';
					$client_secret = isset( $_POST['upwork_client_secret'] ) ? wp_unslash( $_POST['upwork_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$refresh_token = isset( $_POST['upwork_refresh_token'] ) ? wp_unslash( $_POST['upwork_refresh_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$user_email    = isset( $_POST['upwork_user_email'] ) ? sanitize_text_field( wp_unslash( $_POST['upwork_user_email'] ) ) : '';
					break;
				case 'linkedin':
					$client_id     = isset( $_POST['linkedin_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['linkedin_client_id'] ) ) : '';
					$client_secret = isset( $_POST['linkedin_client_secret'] ) ? wp_unslash( $_POST['linkedin_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$refresh_token = isset( $_POST['linkedin_refresh_token'] ) ? wp_unslash( $_POST['linkedin_refresh_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$user_email    = isset( $_POST['linkedin_user_email'] ) ? sanitize_text_field( wp_unslash( $_POST['linkedin_user_email'] ) ) : '';
					break;
				case 'telegram':
					$api_key = isset( $_POST['telegram_bot_token'] ) ? wp_unslash( $_POST['telegram_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'whatsapp':
					$api_key    = isset( $_POST['whatsapp_access_token'] ) ? wp_unslash( $_POST['whatsapp_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['whatsapp_app_secret'] ) ? wp_unslash( $_POST['whatsapp_app_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- app secrets must not be sanitized.
					$app_id     = isset( $_POST['whatsapp_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_app_id'] ) ) : '';
					break;
				case 'google_chat':
					$api_key       = isset( $_POST['google_chat_service_account_key'] ) ? wp_unslash( $_POST['google_chat_service_account_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$client_id     = isset( $_POST['google_chat_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_chat_client_id'] ) ) : '';
					$client_secret = isset( $_POST['google_chat_client_secret'] ) ? wp_unslash( $_POST['google_chat_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$refresh_token = isset( $_POST['google_chat_refresh_token'] ) ? wp_unslash( $_POST['google_chat_refresh_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$gc_method     = isset( $_POST['google_chat_method'] ) ? sanitize_key( wp_unslash( $_POST['google_chat_method'] ) ) : 'service_account';
					if ( ! in_array( $gc_method, array( 'service_account', 'oauth', 'webhook' ), true ) ) {
						$gc_method = 'service_account';
					}
					break;
				case 'slack':
					$api_key        = isset( $_POST['slack_bot_token'] ) ? wp_unslash( $_POST['slack_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$signing_secret = isset( $_POST['slack_signing_secret'] ) ? wp_unslash( $_POST['slack_signing_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'discord':
					$api_key = isset( $_POST['discord_bot_token'] ) ? wp_unslash( $_POST['discord_bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'microsoft_teams':
					// Outgoing Webhook security token (HMAC-SHA256 key from Teams Admin Center).
					$signing_secret = isset( $_POST['teams_security_token'] ) ? wp_unslash( $_POST['teams_security_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					// OAuth 2.0 credentials for Microsoft Graph (Azure AD app).
					$client_id     = isset( $_POST['teams_oauth_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_oauth_client_id'] ) ) : '';
					$client_secret = isset( $_POST['teams_oauth_client_secret'] ) ? wp_unslash( $_POST['teams_oauth_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					// The Microsoft Graph API Bearer token is stored in 'token'; it is handled
					// via the channel-aware 'token' entry in $connection_data below.
					break;
				case 'facebook_messenger':
					$api_key    = isset( $_POST['messenger_page_access_token'] ) ? wp_unslash( $_POST['messenger_page_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['messenger_app_secret'] ) ? wp_unslash( $_POST['messenger_app_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$app_id     = isset( $_POST['messenger_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_app_id'] ) ) : '';
					break;
				case 'webchat':
					// WebChat uses connection_id (handled separately as p2p_connection_id below).
					$api_secret = isset( $_POST['webchat_encryption_key'] ) ? wp_unslash( $_POST['webchat_encryption_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'apple_messages':
					$api_key    = isset( $_POST['apple_msp_api_key'] ) ? wp_unslash( $_POST['apple_msp_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$api_secret = isset( $_POST['apple_webhook_secret'] ) ? wp_unslash( $_POST['apple_webhook_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'office365':
					$client_id     = isset( $_POST['office365_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['office365_client_id'] ) ) : '';
					$client_secret = isset( $_POST['office365_client_secret'] ) ? wp_unslash( $_POST['office365_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'icloud':
					$api_key = isset( $_POST['icloud_api_key'] ) ? wp_unslash( $_POST['icloud_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'shopify':
					$shopify_api_mode = isset( $_POST['shopify_api_mode'] ) ? sanitize_key( wp_unslash( $_POST['shopify_api_mode'] ) ) : 'admin_api';
					if ( ! in_array( $shopify_api_mode, array( 'admin_api', 'catalog_api' ), true ) ) {
						$shopify_api_mode = 'admin_api';
					}
					if ( 'catalog_api' === $shopify_api_mode ) {
						$api_key    = isset( $_POST['shopify_catalog_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_catalog_client_id'] ) ) : '';
						$api_secret = isset( $_POST['shopify_catalog_client_secret'] ) ? wp_unslash( $_POST['shopify_catalog_client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- client secret must not be sanitized.
					} else {
						$api_key    = isset( $_POST['shopify_access_token'] ) ? wp_unslash( $_POST['shopify_access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access token must not be sanitized.
						$api_secret = isset( $_POST['shopify_storefront_token'] ) ? wp_unslash( $_POST['shopify_storefront_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- storefront token must not be sanitized.
					}
					break;
				case 'printful':
					$api_key = isset( $_POST['printful_api_key'] ) ? wp_unslash( $_POST['printful_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					break;
				case 'shipengine':
					$api_key = isset( $_POST['shipengine_api_key'] ) ? wp_unslash( $_POST['shipengine_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key must not be sanitized.
					break;
				case 'shipstation':
					$api_key    = isset( $_POST['shipstation_api_key'] ) ? wp_unslash( $_POST['shipstation_api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key must not be sanitized.
					$api_secret = isset( $_POST['shipstation_api_secret'] ) ? wp_unslash( $_POST['shipstation_api_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API secret must not be sanitized.
					break;
			}

			// For FlowHub connections, always use the fixed API URL and custom_header auth.
			$url       = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
			$auth_type = isset( $_POST['auth_type'] ) ? sanitize_key( wp_unslash( $_POST['auth_type'] ) ) : 'none';

			if ( 'flowhub' === $connection_type ) {
				$sandbox   = ! empty( $_POST['sandbox_mode'] );
				$url       = $sandbox ? 'https://api.sandbox.flowhub.co' : 'https://api.flowhub.co';
				$auth_type = 'custom_header';
			}

			// For Form Data Source connections, default to application_password auth.
			if ( 'form_data_source' === $connection_type ) {
				$auth_type = 'application_password';
			}

			// For EZuite ERP connections, always use custom_header auth.
			if ( 'ezuite_erp' === $connection_type ) {
				$auth_type = 'custom_header';
			}

			// For Gmail connections, always use the Gmail API URL.
			if ( 'gmail' === $connection_type ) {
				$url       = 'https://gmail.googleapis.com';
				$auth_type = 'none'; // Gmail uses OAuth, not standard auth types.
			}

			// For Google Drive connections, always use the Google Drive API URL.
			if ( 'google_drive' === $connection_type ) {
				$url       = 'https://www.googleapis.com/drive/v3';
				$auth_type = 'none'; // Google Drive uses OAuth, not standard auth types.
			}

			// For Upwork connections, always use the Upwork GraphQL API URL.
			if ( 'upwork' === $connection_type ) {
				$url       = 'https://api.upwork.com/graphql';
				$auth_type = 'none'; // Upwork uses OAuth, not standard auth types.
			}

			// For ShipEngine connections, always use the ShipEngine API URL.
			if ( 'shipengine' === $connection_type ) {
				$url       = 'https://api.shipengine.com';
				$auth_type = 'custom_header'; // ShipEngine uses API-Key header.
			}

			// For ShipStation connections, always use the ShipStation API URL.
			if ( 'shipstation' === $connection_type ) {
				$url       = 'https://ssapi.shipstation.com';
				$auth_type = 'basic_auth'; // ShipStation uses Basic Auth with api_key:api_secret.
			}

			// For chat channel connections, set appropriate API URLs.
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

			if ( 'google_chat' === $connection_type ) {
				$url       = 'https://chat.googleapis.com/v1';
				$auth_type = 'none';
			}

			if ( 'twitter' === $connection_type ) {
				$url       = 'https://api.twitter.com/2';
				$auth_type = 'none';
			}

			if ( 'apple_messages' === $connection_type ) {
				// The MSP API URL is entered by the user (varies per MSP provider).
				$url       = isset( $_POST['apple_msp_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['apple_msp_api_url'] ) ) : $url;
				$auth_type = 'none';
			}

			if ( 'office365' === $connection_type ) {
				$url       = 'https://graph.microsoft.com/v1.0';
				$auth_type = 'none'; // Office 365 uses OAuth via Azure AD.
			}

			if ( 'icloud' === $connection_type ) {
				// The gateway API URL is entered by the user (varies per gateway).
				$url       = isset( $_POST['icloud_gateway_url'] ) ? esc_url_raw( wp_unslash( $_POST['icloud_gateway_url'] ) ) : $url;
				$auth_type = 'none';
			}

			// For Shopify connections, set the URL based on API mode and use appropriate auth.
			if ( 'shopify' === $connection_type ) {
				// $shopify_api_mode was resolved in the credential switch above.
				if ( ! isset( $shopify_api_mode ) ) {
					$shopify_api_mode = 'admin_api';
				}
				if ( 'catalog_api' === $shopify_api_mode ) {
					// Catalog API is global — no store domain needed.
					$url       = 'https://discover.shopifyapps.com';
					$auth_type = 'none'; // Catalog API uses a JWT bearer obtained dynamically.
				} else {
					$shop_domain = isset( $_POST['shopify_shop_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_shop_domain'] ) ) : '';
					// Strip scheme prefix and trailing slash if user included them.
					$shop_domain = preg_replace( '#^https?://#i', '', $shop_domain );
					$shop_domain = rtrim( $shop_domain, '/' );
					// Append .myshopify.com if no dot is present (bare store name supplied).
					if ( ! empty( $shop_domain ) && false === strpos( $shop_domain, '.' ) ) {
						$shop_domain .= '.myshopify.com';
					}
					$url       = ! empty( $shop_domain ) ? 'https://' . $shop_domain : $url;
					$auth_type = 'custom_header'; // Admin API uses X-Shopify-Access-Token header.
				}
			}

			// For Printful connections, always use the fixed API URL.
			if ( 'printful' === $connection_type ) {
				$url       = 'https://api.printful.com';
				$auth_type = 'none'; // Bearer token auth is handled directly in get_auth_headers().
			}

			// For mesh peer connections, use custom_header auth with mesh API key.
			if ( 'mesh_peer' === $connection_type ) {
				$auth_type = 'custom_header';
			}

			// Resolve the shared verify_token field (used by WhatsApp, Messenger, and Google Chat).
			// Use connection_type to select the correct field so hidden form fields from other
			// connection types (which are still submitted with empty values) don't take precedence.
			$verify_token = '';
			if ( 'google_chat' === $connection_type && isset( $_POST['google_chat_audience'] ) ) {
				$verify_token = sanitize_text_field( wp_unslash( $_POST['google_chat_audience'] ) );
			} elseif ( 'facebook_messenger' === $connection_type && isset( $_POST['messenger_verify_token'] ) ) {
				$verify_token = sanitize_text_field( wp_unslash( $_POST['messenger_verify_token'] ) );
			} elseif ( isset( $_POST['whatsapp_verify_token'] ) ) {
				$verify_token = sanitize_text_field( wp_unslash( $_POST['whatsapp_verify_token'] ) );
			}

			// Resolve and validate the Telegram webhook secret token.
			// Only valid tokens (A–Z, a–z, 0–9, _ and –; 1–256 chars) are accepted; empty means "no change".
			$telegram_secret_token = '';
			if ( isset( $_POST['telegram_secret_token'] ) ) {
				$raw_secret = wp_unslash( $_POST['telegram_secret_token'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
				$raw_secret = trim( (string) $raw_secret );
				if ( '' !== $raw_secret ) {
					if ( WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_secret_token( $raw_secret ) ) {
						$telegram_secret_token = $raw_secret;
					} else {
						wp_die(
							esc_html__( 'Webhook Secret Token may only contain A–Z, a–z, 0–9, underscores and hyphens (1–256 characters).', 'mcp-ai-wpoos-pro' ),
							esc_html__( 'Invalid Input', 'mcp-ai-wpoos-pro' ),
							array( 'back_link' => true )
						);
					}
				}
			}

			$connection_data = array(
				'id'                             => isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '',
				'name'                           => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'url'                            => $url,
				'connection_type'                => $connection_type,
				'auth_type'                      => $auth_type,
				'username'                       => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
				'password'                       => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				// For Teams, the Graph API Bearer token comes from the channel-specific field; all others use the generic 'token' POST field.
				'token'                          => 'microsoft_teams' === $connection_type
					? ( isset( $_POST['teams_graph_token'] ) ? wp_unslash( $_POST['teams_graph_token'] ) : '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					: ( isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'consumer_key'                   => isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '',
				'consumer_secret'                => isset( $_POST['consumer_secret'] ) ? wp_unslash( $_POST['consumer_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'api_key'                        => $api_key,
				'api_secret'                     => $api_secret,
				// HMAC-SHA256 signing secret (Slack events / Teams outgoing webhook security token).
				'signing_secret'                 => $signing_secret,
				'client_id'                      => $client_id,
				'client_secret'                  => $client_secret,
				'app_id'                         => $app_id ? $app_id : ( isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : ( isset( $_POST['teams_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_app_id'] ) ) : '' ) ),
				'app_secret'                     => isset( $_POST['app_secret'] ) ? wp_unslash( $_POST['app_secret'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'location_id'                    => isset( $_POST['location_id'] ) ? sanitize_text_field( wp_unslash( $_POST['location_id'] ) ) : '',
				'company_id'                     => isset( $_POST['company_id'] ) ? sanitize_text_field( wp_unslash( $_POST['company_id'] ) ) : '',
				'store_id'                       => isset( $_POST['printful_store_id'] ) ? sanitize_text_field( wp_unslash( $_POST['printful_store_id'] ) ) : '',
				'sandbox_mode'                   => ! empty( $_POST['sandbox_mode'] ),
				'has_woocommerce'                => ! empty( $_POST['has_woocommerce'] ),
				// FlowHub proxy fields.
				'proxy_enabled'                  => ! empty( $_POST['flowhub_proxy_enabled'] ),
				'proxy_url'                      => isset( $_POST['flowhub_proxy_url'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_proxy_url'] ) ) : '',
				'proxy_username'                 => isset( $_POST['flowhub_proxy_username'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_proxy_username'] ) ) : '',
				'proxy_password'                 => isset( $_POST['flowhub_proxy_password'] ) ? wp_unslash( $_POST['flowhub_proxy_password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password; stored as-is for encryption.
				'enabled'                        => ! empty( $_POST['enabled'] ),
				'cache_ttl'                      => isset( $_POST['cache_ttl'] ) ? max( 0, min( 3600, absint( $_POST['cache_ttl'] ) ) ) : 300,
				'test_endpoint'                  => isset( $_POST['test_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['test_endpoint'] ) ) : '',
				// Gmail-specific fields.
				'refresh_token'                  => $refresh_token,
				'user_email'                     => $user_email,
				// Google Drive-specific fields.
				'folder_id'                      => isset( $_POST['google_drive_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_drive_folder_id'] ) ) : '',
				// Upwork-specific fields.
				'upwork_username'                => isset( $_POST['upwork_user_email'] ) ? sanitize_text_field( wp_unslash( $_POST['upwork_user_email'] ) ) : '',
				'upwork_mode'                    => isset( $_POST['upwork_mode'] ) && in_array( $_POST['upwork_mode'], array( 'api', 'web_search' ), true )
					? sanitize_key( wp_unslash( $_POST['upwork_mode'] ) )
					: 'api',
				'upwork_search_query'            => isset( $_POST['upwork_search_query'] ) ? sanitize_text_field( wp_unslash( $_POST['upwork_search_query'] ) ) : '',
				'upwork_search_category'         => isset( $_POST['upwork_search_category'] ) ? sanitize_text_field( wp_unslash( $_POST['upwork_search_category'] ) ) : '',
				'upwork_search_job_type'         => isset( $_POST['upwork_search_job_type'] ) && in_array( $_POST['upwork_search_job_type'], array( 'hourly', 'fixed' ), true )
					? sanitize_key( wp_unslash( $_POST['upwork_search_job_type'] ) )
					: '',
				// Telegram-specific fields.
				'bot_username'                   => isset( $_POST['telegram_bot_username'] ) ? sanitize_text_field( wp_unslash( $_POST['telegram_bot_username'] ) ) : '',
				'secret_token'                   => $telegram_secret_token,
				'enable_groups'                  => ! empty( $_POST['telegram_enable_groups'] ),
				'enable_channels'                => ! empty( $_POST['telegram_enable_channels'] ),
				'enable_web_login'               => ! empty( $_POST['telegram_enable_web_login'] ),
				'web_login_redirect_url'         => isset( $_POST['telegram_web_login_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['telegram_web_login_redirect_url'] ) ) : '',
				'auto_create_wp_user'            => ! empty( $_POST['telegram_auto_create_wp_user'] ),
				'new_user_role'                  => isset( $_POST['telegram_new_user_role'] ) ? sanitize_key( wp_unslash( $_POST['telegram_new_user_role'] ) ) : 'subscriber',
				'allowed_chat_ids'               => isset( $_POST['telegram_allowed_chat_ids'] )
					? array_values(
						array_filter(
							array_map(
								static function ( $id ) {
									return sanitize_text_field( trim( $id ) );
								},
								explode( ',', wp_unslash( $_POST['telegram_allowed_chat_ids'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in array_map above.
							),
							static function ( $id ) {
								return '' !== $id && preg_match( '/^-?\d+$/', $id );
							}
						)
					)
					: array(),
				// Welcome message & client settings.
				'welcome_message'                => isset( $_POST['telegram_welcome_message'] )
					? sanitize_textarea_field( wp_unslash( $_POST['telegram_welcome_message'] ) )
					: '',
				'parse_mode'                     => ( isset( $_POST['telegram_parse_mode'] ) && in_array( $_POST['telegram_parse_mode'], array( 'HTML', 'Markdown', 'MarkdownV2' ), true ) )
					? sanitize_text_field( wp_unslash( $_POST['telegram_parse_mode'] ) )
					: 'HTML',
				'disabled_commands'              => isset( $_POST['telegram_disabled_commands'] ) && is_array( $_POST['telegram_disabled_commands'] )
					? array_map( 'sanitize_key', wp_unslash( $_POST['telegram_disabled_commands'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map.
					: array(),
				'command_descriptions'           => ( isset( $_POST['telegram_command_descriptions'] ) && is_array( $_POST['telegram_command_descriptions'] ) )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['telegram_command_descriptions'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map.
					: array(),
				// Mini App settings.
				'enable_mini_app'                => ! empty( $_POST['telegram_enable_mini_app'] ),
				'mini_app_assistant_id'          => isset( $_POST['telegram_mini_app_assistant_id'] ) ? absint( $_POST['telegram_mini_app_assistant_id'] ) : 0,
				'mini_app_template'              => isset( $_POST['telegram_mini_app_template'] ) ? sanitize_key( wp_unslash( $_POST['telegram_mini_app_template'] ) ) : '',
				'mini_app_woo_source'            => ( isset( $_POST['telegram_mini_app_woo_source'] ) && 'remote' === $_POST['telegram_mini_app_woo_source'] ) ? 'remote' : 'local',
				'mini_app_woo_connection_id'     => isset( $_POST['telegram_mini_app_woo_connection_id'] ) ? sanitize_key( wp_unslash( $_POST['telegram_mini_app_woo_connection_id'] ) ) : '',
				'mini_app_shopify_connection_id' => isset( $_POST['telegram_mini_app_shopify_connection_id'] ) ? sanitize_key( wp_unslash( $_POST['telegram_mini_app_shopify_connection_id'] ) ) : '',
				'mini_app_flowhub_connection_id' => isset( $_POST['telegram_mini_app_flowhub_connection_id'] ) ? sanitize_key( wp_unslash( $_POST['telegram_mini_app_flowhub_connection_id'] ) ) : '',
				// WhatsApp-specific fields.
				'phone_number_id'                => isset( $_POST['whatsapp_phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_phone_number_id'] ) ) : '',
				'display_phone_number'           => isset( $_POST['whatsapp_display_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_display_phone_number'] ) ) : '',
				'channel_url'                    => isset( $_POST['whatsapp_channel_url'] ) ? esc_url_raw( wp_unslash( $_POST['whatsapp_channel_url'] ) ) : '',
				'group_id'                       => isset( $_POST['whatsapp_group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_group_id'] ) ) : '',
				'business_account_id'            => isset( $_POST['whatsapp_business_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_business_account_id'] ) ) : '',
				'system_user_id'                 => isset( $_POST['whatsapp_system_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_system_user_id'] ) ) : '',
				'channel_description'            => isset( $_POST['whatsapp_channel_description'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_channel_description'] ) ) : '',
				'verify_token'                   => $verify_token,
				'graph_api_version'              => isset( $_POST['whatsapp_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['whatsapp_graph_api_version'] ) ) : ( isset( $_POST['messenger_graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_graph_api_version'] ) ) : '' ),
				// Slack-specific fields.
				'workspace_id'                   => isset( $_POST['slack_workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slack_workspace_id'] ) ) : '',
				'slack_bot_user_id'              => isset( $_POST['slack_bot_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['slack_bot_user_id'] ) ) : '',
				// Discord-specific fields.
				'application_id'                 => isset( $_POST['discord_application_id'] ) ? sanitize_text_field( wp_unslash( $_POST['discord_application_id'] ) ) : '',
				'guild_id'                       => isset( $_POST['discord_guild_id'] ) ? sanitize_text_field( wp_unslash( $_POST['discord_guild_id'] ) ) : '',
				'public_key'                     => isset( $_POST['discord_public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['discord_public_key'] ) ) : '',
				// Microsoft Teams / Office 365-specific fields.
				'tenant_id'                      => 'office365' === $connection_type
					? ( isset( $_POST['office365_tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['office365_tenant_id'] ) ) : '' )
					: ( isset( $_POST['teams_tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['teams_tenant_id'] ) ) : '' ),
				// Facebook Messenger-specific fields.
				'page_id'                        => isset( $_POST['messenger_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['messenger_page_id'] ) ) : '',
				// Chat-channel setting: only reply when the assistant is @mentioned.
				// Each channel uses a channel-prefixed field name so that hidden form fields
				// from other channel types do not conflict with the active one.
				'require_mention'                => (
					( 'telegram' === $connection_type && ! empty( $_POST['telegram_require_mention'] ) ) ||
					( 'slack' === $connection_type && ! empty( $_POST['slack_require_mention'] ) ) ||
					( 'discord' === $connection_type && ! empty( $_POST['discord_require_mention'] ) ) ||
					( 'microsoft_teams' === $connection_type && ! empty( $_POST['teams_require_mention'] ) ) ||
					( 'facebook_messenger' === $connection_type && ! empty( $_POST['messenger_require_mention'] ) )
				),
				// WebChat-specific fields.
				'p2p_connection_id'              => isset( $_POST['webchat_connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webchat_connection_id'] ) ) : '',
				// Google Chat-specific fields.
				'google_chat_space'              => isset( $_POST['google_chat_space'] ) ? sanitize_text_field( wp_unslash( $_POST['google_chat_space'] ) ) : '',
				'reply_webhook_url'              => isset( $_POST['google_chat_reply_webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['google_chat_reply_webhook_url'] ) ) : '',
				'disable_oidc_verification'      => ! empty( $_POST['google_chat_disable_oidc_verification'] ),
				'connection_method'              => $gc_method,
				// Twitter/X-specific fields.
				'twitter_user_id'                => isset( $_POST['twitter_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter_user_id'] ) ) : '',
				// Apple Messages for Business-specific fields.
				'business_id'                    => isset( $_POST['apple_business_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apple_business_id'] ) ) : '',
				// iCloud Drive-specific fields.
				'gateway_api_url'                => isset( $_POST['icloud_gateway_url'] ) ? esc_url_raw( wp_unslash( $_POST['icloud_gateway_url'] ) ) : '',
				// Office 365 / iCloud: per-service toggles (e.g. outlook_mail, onedrive, icloud_drive).
				'enabled_services'               => $this->resolve_enabled_services( $connection_type ),
				// Office 365 per-service settings.
				'outlook_mailbox_folder'         => isset( $_POST['outlook_mailbox_folder'] ) ? sanitize_text_field( wp_unslash( $_POST['outlook_mailbox_folder'] ) ) : '',
				'onedrive_folder_path'           => isset( $_POST['onedrive_folder_path'] ) ? sanitize_text_field( wp_unslash( $_POST['onedrive_folder_path'] ) ) : '',
				// iCloud per-service settings.
				'icloud_default_folder_id'       => isset( $_POST['icloud_default_folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['icloud_default_folder_id'] ) ) : '',
				// Shopify-specific fields.
				'shopify_api_version'            => 'shopify' === $connection_type && isset( $_POST['shopify_api_version'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['shopify_api_version'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
					? sanitize_text_field( wp_unslash( $_POST['shopify_api_version'] ) )
					: ( 'shopify' === $connection_type ? '2025-01' : '' ),
				'shopify_api_mode'               => 'shopify' === $connection_type && isset( $shopify_api_mode )
					? $shopify_api_mode
					: ( 'shopify' === $connection_type ? 'admin_api' : '' ),
				'shopify_catalog_shop_id'        => 'shopify' === $connection_type && isset( $_POST['shopify_catalog_shop_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
					? sanitize_text_field( wp_unslash( $_POST['shopify_catalog_shop_id'] ) )
					: '',
				// ShipEngine-specific fields.
				'shipengine_carrier_id'          => 'shipengine' === $connection_type && isset( $_POST['shipengine_carrier_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
					? sanitize_text_field( wp_unslash( $_POST['shipengine_carrier_id'] ) )
					: '',
				// ShipStation-specific fields.
				'shipstation_carrier_code'       => 'shipstation' === $connection_type && isset( $_POST['shipstation_carrier_code'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
					? sanitize_text_field( wp_unslash( $_POST['shipstation_carrier_code'] ) )
					: ( 'shipstation' === $connection_type ? 'stamps_com' : '' ),
				// QuickBooks Desktop (QODBC) specific fields.
				'dsn_name'                       => $dsn_name,
				// Channel routing: assistants assigned to auto-reply on this connection (used by all chat-channel types).
				'assigned_assistant_ids'         => isset( $_POST['assigned_assistant_ids'] ) && is_array( $_POST['assigned_assistant_ids'] )
					? array_values( array_map( 'absint', wp_unslash( $_POST['assigned_assistant_ids'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					: array(),
				// LinkedIn-specific fields.
				'linkedin_mode'                  => isset( $_POST['linkedin_mode'] ) && in_array( $_POST['linkedin_mode'], array( 'api', 'web_search' ), true )
					? sanitize_key( wp_unslash( $_POST['linkedin_mode'] ) )
					: 'api',
				'linkedin_search_query'          => isset( $_POST['linkedin_search_query'] ) ? sanitize_text_field( wp_unslash( $_POST['linkedin_search_query'] ) ) : '',
				'linkedin_search_location'       => isset( $_POST['linkedin_search_location'] ) ? sanitize_text_field( wp_unslash( $_POST['linkedin_search_location'] ) ) : '',
				// WordPress/WooCommerce granular access controls.
				'post_type_access'               => $this->resolve_post_type_access(),
				'wc_resource_access'             => $this->resolve_wc_resource_access(),
				'custom_post_types'              => isset( $_POST['custom_post_types'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_post_types'] ) ) : '',
				// JetEngine CCT access controls.
				'jetengine_cct_access'           => $this->resolve_jetengine_cct_access(),
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
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; nonce not required for read operations.
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

			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag. ?>
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
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag. ?>
				<?php $error_message = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['test_success'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag.
				$editing      = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
				$test_results = $editing ? get_transient( 'wp_mcp_ai_test_result_' . $editing ) : false;
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Connection test successful!', 'mcp-ai-wpoos-pro' ); ?></strong></p>
					<?php if ( $test_results && is_array( $test_results ) ) : ?>
						<ul style="margin: 10px 0; padding-left: 20px;">
							<?php if ( isset( $test_results['whatsapp'] ) && $test_results['whatsapp'] ) : ?>
								<?php if ( ! empty( $test_results['phone_number'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: WhatsApp phone number */ __( 'Phone Number: %s', 'mcp-ai-wpoos-pro' ), $test_results['phone_number'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['verified_name'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: verified business name */ __( 'Verified Name: %s', 'mcp-ai-wpoos-pro' ), $test_results['verified_name'] ) ); ?></li>
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
										echo wp_kses_post( sprintf( /* translators: 1: color hex code, 2: quality rating label */ __( 'Quality Rating: <span style="color: %1$s; font-weight: bold;">%2$s</span>', 'mcp-ai-wpoos-pro' ), $quality_color, $quality_upper ) );
										?>
									</li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['business_name'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: business profile name */ __( 'Business Profile: %s', 'mcp-ai-wpoos-pro' ), $test_results['business_name'] ) ); ?></li>
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
							<?php elseif ( isset( $test_results['telegram'] ) && $test_results['telegram'] ) : ?>
								<?php if ( ! empty( $test_results['bot_name'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: Telegram bot name */ __( 'Bot Name: %s', 'mcp-ai-wpoos-pro' ), $test_results['bot_name'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['bot_username'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: Telegram bot username */ __( 'Bot Username: @%s', 'mcp-ai-wpoos-pro' ), ltrim( $test_results['bot_username'], '@' ) ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['bot_id'] ) ) : ?>
									<li><?php echo esc_html( sprintf( /* translators: %s: Telegram bot ID */ __( 'Bot ID: %s', 'mcp-ai-wpoos-pro' ), $test_results['bot_id'] ) ); ?></li>
								<?php endif; ?>
								<?php if ( ! empty( $test_results['webhook_url'] ) ) : ?>
									<li>
										<?php esc_html_e( 'Webhook URL:', 'mcp-ai-wpoos-pro' ); ?>
										<code style="background: #f0f0f0; padding: 2px 6px; font-size: 12px;"><?php echo esc_html( $test_results['webhook_url'] ); ?></code>
									</li>
								<?php endif; ?>
							<?php elseif ( isset( $test_results['site_name'] ) ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: %s: remote site name */ __( 'Site: %s', 'mcp-ai-wpoos-pro' ), $test_results['site_name'] ) ); ?></li>
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
						<?php if ( isset( $test_results['notice'] ) && ! empty( $test_results['notice'] ) ) : ?>
							<p style="color: #2271b1; font-size: 13px;">ℹ <?php echo esc_html( $test_results['notice'] ); ?></p>
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
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag. ?>
				<?php $test_error = isset( $_GET['test_error'] ) ? sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) : ''; ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( urldecode( $test_error ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['oauth_success'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag. ?>
				<?php $oauth_success = isset( $_GET['oauth_success'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_success'] ) ) : ''; ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( urldecode( $oauth_success ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $editing || isset( $_GET['add'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only query flag. ?>
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

								// Define labels and colors for each connection type.
								$type_labels = array(
									'wordpress'          => __( 'WordPress', 'mcp-ai-wpoos-pro' ),
									'mesh_peer'          => __( 'Mesh Peer', 'mcp-ai-wpoos-pro' ),
									'generic'            => __( 'Generic REST API', 'mcp-ai-wpoos-pro' ),
									'isams'              => __( 'iSAMS', 'mcp-ai-wpoos-pro' ),
									'flowhub'            => __( 'Flowhub', 'mcp-ai-wpoos-pro' ),
									'payhere'            => __( 'PayHere', 'mcp-ai-wpoos-pro' ),
									'quickbooks'         => __( 'QuickBooks', 'mcp-ai-wpoos-pro' ),
									'quickbooks_desktop' => __( 'QB Desktop', 'mcp-ai-wpoos-pro' ),
									'ezuite_erp'         => __( 'EZuite ERP', 'mcp-ai-wpoos-pro' ),
									'gmail'              => __( 'Gmail', 'mcp-ai-wpoos-pro' ),
									'google_drive'       => __( 'Google Drive', 'mcp-ai-wpoos-pro' ),
									'upwork'             => __( 'Upwork', 'mcp-ai-wpoos-pro' ),
									'telegram'           => __( 'Telegram', 'mcp-ai-wpoos-pro' ),
									'whatsapp'           => __( 'WhatsApp', 'mcp-ai-wpoos-pro' ),
									'slack'              => __( 'Slack', 'mcp-ai-wpoos-pro' ),
									'discord'            => __( 'Discord', 'mcp-ai-wpoos-pro' ),
									'microsoft_teams'    => __( 'MS Teams', 'mcp-ai-wpoos-pro' ),
									'facebook_messenger' => __( 'Messenger', 'mcp-ai-wpoos-pro' ),
									'webchat'            => __( 'WebChat', 'mcp-ai-wpoos-pro' ),
									'google_chat'        => __( 'Google Chat', 'mcp-ai-wpoos-pro' ),
									'twitter'            => __( 'Twitter / X', 'mcp-ai-wpoos-pro' ),
									'apple_messages'     => __( 'Apple Messages for Business', 'mcp-ai-wpoos-pro' ),
									'office365'          => __( 'Office 365', 'mcp-ai-wpoos-pro' ),
									'icloud'             => __( 'iCloud Drive', 'mcp-ai-wpoos-pro' ),
									'shopify'            => __( 'Shopify', 'mcp-ai-wpoos-pro' ),
									'printful'           => __( 'Printful', 'mcp-ai-wpoos-pro' ),
									'shipengine'         => __( 'ShipEngine', 'mcp-ai-wpoos-pro' ),
									'shipstation'        => __( 'ShipStation V1', 'mcp-ai-wpoos-pro' ),
								);

								$type_colors = array(
									'wordpress'          => '#2271b1',
									'mesh_peer'          => '#7e57c2', // Purple - same as MESH badge in ai_peer.
									'generic'            => '#50575e',
									'isams'              => '#d63638',
									'flowhub'            => '#00a32a',
									'payhere'            => '#f0b849',
									'quickbooks'         => '#2c9f47',
									'quickbooks_desktop' => '#1a7a32',
									'ezuite_erp'         => '#8c50a7',
									'gmail'              => '#ea4335', // Google red color.
									'google_drive'       => '#4285f4', // Google blue color.
									'upwork'             => '#14a800', // Upwork green.
									'telegram'           => '#0088cc', // Telegram blue.
									'whatsapp'           => '#25d366', // WhatsApp green.
									'slack'              => '#4a154b', // Slack purple.
									'discord'            => '#5865f2', // Discord blurple.
									'microsoft_teams'    => '#6264a7', // Teams purple.
									'facebook_messenger' => '#0084ff', // Messenger blue.
									'webchat'            => '#ff6b6b', // WebChat coral red.
									'google_chat'        => '#1a73e8', // Google Chat blue.
									'twitter'            => '#000000', // X (formerly Twitter) black.
									'apple_messages'     => '#555555', // Apple dark grey.
									'office365'          => '#d83b01', // Microsoft Office orange.
									'icloud'             => '#3693f5', // iCloud blue.
									'shopify'            => '#96bf48', // Shopify green.
									'printful'           => '#e5675b', // Printful coral.
									'shipengine'         => '#0072ce', // ShipStation API blue.
									'shipstation'        => '#f26522', // ShipStation V1 orange.
								);

								$type_label       = isset( $type_labels[ $connection_type ] ) ? $type_labels[ $connection_type ] : $connection_type;
								$type_badge_color = isset( $type_colors[ $connection_type ] ) ? $type_colors[ $connection_type ] : '#50575e';
								?>
								<span style="display: inline-block; padding: 2px 8px; background: <?php echo esc_attr( $type_badge_color ); ?>; color: white; border-radius: 3px; font-size: 11px;">
									<?php echo esc_html( $type_label ); ?>
								</span>
								<?php if ( in_array( $connection_type, array( 'wordpress', 'WordPress' ), true ) && ! empty( $connection['has_woocommerce'] ) ) : ?>
									<span style="display: inline-block; padding: 2px 8px; background: #96588a; color: white; border-radius: 3px; font-size: 11px; margin-left: 4px;">WC</span>
								<?php endif; ?>
											</td>
												<td>
												<?php
												// For WhatsApp channels, show phone number link, channel description, and unique channel link.
												if ( 'whatsapp' === $connection_type ) {
													$wa_display = array();
													if ( ! empty( $connection['channel_url'] ) ) {
														// Prefer a custom channel URL (e.g. WhatsApp Group invite link) when set.
														$wa_display[] = '<a href="' . esc_url( $connection['channel_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $connection['channel_url'] ) . '</a>';
													} elseif ( ! empty( $connection['display_phone_number'] ) ) {
														$wa_phone_digits = preg_replace( '/[^0-9]/', '', $connection['display_phone_number'] );
														if ( ! empty( $wa_phone_digits ) ) {
															$wa_phone_link = 'https://wa.me/' . $wa_phone_digits;
															$wa_display[]  = '<a href="' . esc_url( $wa_phone_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $connection['display_phone_number'] ) . '</a>';
														} else {
															$wa_display[] = esc_html( $connection['display_phone_number'] );
														}
													} elseif ( ! empty( $connection['phone_number_id'] ) ) {
														$wa_display[] = esc_html__( 'Phone ID:', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( substr( $connection['phone_number_id'], 0, 8 ) . '…' );
													}
													if ( ! empty( $connection['channel_description'] ) ) {
														$wa_display[] = '<em>' . esc_html( $connection['channel_description'] ) . '</em>';
													}
													if ( ! empty( $connection_id ) ) {
														$channel_webhook = home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp/' . $connection_id );
														$wa_display[]    = '<small><a href="' . esc_url( $channel_webhook ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Channel Webhook', 'mcp-ai-wpoos-pro' ) . '</a></small>';
													}
													echo ! empty( $wa_display ) ? implode( '<br>', $wa_display ) : esc_html( $connection['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												} else {
													echo esc_html( $connection['url'] );
												}
												?>
							</td>
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id ) ); ?>" title="<?php esc_attr_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-edit" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?></span>
								</a> |
								<a href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'action'   => 'test',
											'connection_id' => $connection_id,
											'_wpnonce' => wp_create_nonce( 'test_connection_' . $connection_id ),
										),
										admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' )
									)
								);
								?>
											" title="<?php esc_attr_e( 'Test', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?></span>
								</a> |
								<a href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'action'   => 'delete',
											'connection_id' => $connection_id,
											'_wpnonce' => wp_create_nonce( 'delete_connection_' . $connection_id ),
										),
										admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' )
									)
								);
								?>
											" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this connection?', 'mcp-ai-wpoos-pro' ); ?>');" style="color: #b32d2e;" title="<?php esc_attr_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></span>
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
		$editing = $is_edit && isset( $connection['id'] ) ? $connection['id'] : '';
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
							<option value="wordpress" <?php selected( strtolower( (string) $connection_type ), 'WordPress' ); ?>>
								<?php esc_html_e( 'WordPress / WooCommerce', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="mesh_peer" <?php selected( $connection_type, 'mesh_peer' ); ?>>
								<?php esc_html_e( 'Mesh Peer (Distributed AI)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="generic" <?php selected( $connection_type, 'generic' ); ?>>
								<?php esc_html_e( 'Generic REST API', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="form_data_source" <?php selected( $connection_type, 'form_data_source' ); ?>>
								<?php esc_html_e( 'Form Data Source (JFB / Elementor)', 'mcp-ai-wpoos-pro' ); ?>
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
							<option value="quickbooks_desktop" <?php selected( $connection_type, 'quickbooks_desktop' ); ?>>
								<?php esc_html_e( 'QuickBooks Desktop (QODBC)', 'mcp-ai-wpoos-pro' ); ?>
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
							<option value="upwork" <?php selected( $connection_type, 'upwork' ); ?>>
								<?php esc_html_e( 'Upwork (Freelance Marketplace)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="linkedin" <?php selected( $connection_type, 'linkedin' ); ?>>
								<?php esc_html_e( 'LinkedIn (Professional Network)', 'mcp-ai-wpoos-pro' ); ?>
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
							<option value="google_chat" <?php selected( $connection_type, 'google_chat' ); ?>>
								<?php esc_html_e( 'Google Chat (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="twitter" <?php selected( $connection_type, 'twitter' ); ?>>
								<?php esc_html_e( 'Twitter / X (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="apple_messages" <?php selected( $connection_type, 'apple_messages' ); ?>>
								<?php esc_html_e( 'Apple Messages for Business / iMessage (Chat Channel)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="office365" <?php selected( $connection_type, 'office365' ); ?>>
								<?php esc_html_e( 'Office 365 (Outlook / OneDrive)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="icloud" <?php selected( $connection_type, 'icloud' ); ?>>
								<?php esc_html_e( 'iCloud Drive (Cloud Storage)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="shopify" <?php selected( $connection_type, 'shopify' ); ?>>
								<?php esc_html_e( 'Shopify (E-Commerce Platform)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="printful" <?php selected( $connection_type, 'printful' ); ?>>
								<?php esc_html_e( 'Printful (Print-on-Demand)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="shipengine" <?php selected( $connection_type, 'shipengine' ); ?>>
								<?php esc_html_e( 'ShipStation API (Recommended)', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="shipstation" <?php selected( $connection_type, 'shipstation' ); ?>>
								<?php esc_html_e( 'ShipStation V1 API (Legacy)', 'mcp-ai-wpoos-pro' ); ?>
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

				<!-- Type-specific fields for Shopify -->
				<tr class="shopify-only-field" style="display: none;">
					<th scope="row">
						<label for="shopify_api_mode"><?php esc_html_e( 'API Mode', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php
						$saved_shopify_mode = $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['shopify_api_mode'] )
							? $connection['shopify_api_mode']
							: 'admin_api';
						?>
						<select name="shopify_api_mode" id="shopify_api_mode" onchange="toggleShopifyApiMode(this.value)">
							<option value="admin_api" <?php selected( $saved_shopify_mode, 'admin_api' ); ?>><?php esc_html_e( 'Admin API — store management (shpat_ / shpca_)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="catalog_api" <?php selected( $saved_shopify_mode, 'catalog_api' ); ?>><?php esc_html_e( 'Catalog API — global product search for agents (shpss_)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Admin API connects to a specific store. Catalog API queries the global Shopify product catalog for agentic commerce using Dev Dashboard credentials.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Admin API sub-fields -->
				<tr class="shopify-only-field shopify-admin-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_shop_domain"><?php esc_html_e( 'Shop Domain', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="shopify_shop_domain" id="shopify_shop_domain" class="regular-text"
							value="
							<?php
							$is_shopify_edit = $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' );
							$is_catalog_edit = $is_shopify_edit && isset( $connection['shopify_api_mode'] ) && 'catalog_api' === $connection['shopify_api_mode'];
							echo $is_shopify_edit && ! $is_catalog_edit ? esc_attr( preg_replace( '#^https?://#i', '', rtrim( $connection['url'], '/' ) ) ) : '';
							?>
							"
							autocomplete="off" placeholder="mystore.myshopify.com">
						<p class="description"><?php esc_html_e( 'Your Shopify store domain, e.g. mystore.myshopify.com. You can also enter just the store name and .myshopify.com will be appended automatically.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="shopify-only-field shopify-admin-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_access_token"><?php esc_html_e( 'Admin API Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="shopify_access_token" id="shopify_access_token" class="regular-text" value="" autocomplete="new-password" placeholder="shpat_… / shpca_…">
						<?php if ( $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing Admin API access token. Generate this token in your Shopify Admin → Apps → Develop apps → API credentials.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Shopify Admin API access token (shpat_… for public apps, shpca_… for custom apps). Generated in Shopify Admin → Apps → Develop apps → API credentials. Sent as the X-Shopify-Access-Token header.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="shopify-only-field shopify-admin-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_storefront_token"><?php esc_html_e( 'Storefront API Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="shopify_storefront_token" id="shopify_storefront_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing Storefront API token, or clear it to remove.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Optional. Shopify Storefront API access token for customer-facing operations (reading published products without admin access).', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="shopify-only-field shopify-admin-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_api_version"><?php esc_html_e( 'Admin API Version', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="shopify_api_version" id="shopify_api_version" class="regular-text"
							value="<?php echo $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['shopify_api_version'] ) ? esc_attr( $connection['shopify_api_version'] ) : '2025-01'; ?>"
							autocomplete="off" placeholder="2025-01">
						<p class="description"><?php esc_html_e( 'Shopify Admin GraphQL API version in YYYY-MM format (e.g. 2025-01). Defaults to 2025-01. See Shopify API versioning docs for available versions.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php
						// Show deprecation warning when the configured version is older than the latest known stable.
						$saved_version = $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['shopify_api_version'] )
							? $connection['shopify_api_version']
							: '2025-01';
						if ( class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
							$latest = defined( 'WP_MCP_AI_Shopify_Client::LATEST_KNOWN_VERSION' )
								? WP_MCP_AI_Shopify_Client::LATEST_KNOWN_VERSION
								: '2025-04';
							if ( version_compare( $saved_version, $latest, '<' ) ) :
								?>
								<p style="color: #d63638; font-weight: 500; margin-top: 6px;">
									<span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
									<?php
									printf(
										/* translators: 1: current version, 2: latest version */
										esc_html__( 'Your API version (%1$s) is older than the latest stable release (%2$s). Shopify deprecates API versions after 12 months. Consider updating to avoid disruptions.', 'mcp-ai-wpoos-pro' ),
										esc_html( $saved_version ),
										esc_html( $latest )
									);
									?>
								</p>
								<?php
							endif;
						}
						?>
					</td>
				</tr>

				<!-- Catalog API sub-fields -->
				<tr class="shopify-only-field shopify-catalog-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_catalog_client_id"><?php esc_html_e( 'Catalog API Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="shopify_catalog_client_id" id="shopify_catalog_client_id" class="regular-text" value="" autocomplete="off" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
						<?php if ( $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing Client ID. Obtain this from your Shopify Dev Dashboard (dev.shopify.com).', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Catalog API client ID from the Shopify Dev Dashboard (dev.shopify.com). Used together with the client secret to obtain a JWT bearer token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="shopify-only-field shopify-catalog-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_catalog_client_secret"><?php esc_html_e( 'Catalog API Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="shopify_catalog_client_secret" id="shopify_catalog_client_secret" class="regular-text" value="" autocomplete="new-password" placeholder="shpss_…">
						<?php if ( $is_edit && 'shopify' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing client secret. Obtain this from your Shopify Dev Dashboard (dev.shopify.com). Begins with shpss_.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Catalog API client secret from the Shopify Dev Dashboard (shpss_…). Used with client_id to obtain a JWT bearer token for the global Catalog API. Stored encrypted.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="shopify-only-field shopify-catalog-api-field" style="display: none;">
					<th scope="row">
						<label for="shopify_catalog_shop_id"><?php esc_html_e( 'Shop ID (recommended)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="shopify_catalog_shop_id" id="shopify_catalog_shop_id" class="regular-text"
							value="<?php echo esc_attr( $is_edit && $is_catalog_edit && ! empty( $connection['shopify_catalog_shop_id'] ) ? $connection['shopify_catalog_shop_id'] : '' ); ?>"
							autocomplete="off" placeholder="12345678901 or gid://shopify/Shop/12345678901">
						<p class="description"><?php esc_html_e( 'Your Shopify numeric Shop ID or GID (e.g. 12345678901 or gid://shopify/Shop/12345678901). Limits Catalog API search results to only your store products. Without this, the Catalog API returns results from all Shopify stores globally. Find your Shop ID in the Shopify admin URL or via the Admin API (shop { id }).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="shopify-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Shopify Setup Guide', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px 16px; border-radius: 4px;">
							<!-- Admin API guide -->
							<div class="shopify-admin-api-guide">
								<p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Admin API — How to create an access token:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
								<ol style="margin: 0 0 16px; padding-left: 20px; line-height: 1.8;">
									<li><?php esc_html_e( 'In your Shopify Admin, go to Settings → Apps and sales channels → Develop apps.', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Click "Create an app" and give it a name (e.g. NV oOS AI).', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Under "API credentials", click "Configure Admin API scopes" and select the required permissions (read_products, write_products, read_orders, read_customers, etc.).', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Click "Save" then "Install app" to generate the access token.', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Copy the Admin API access token (shpat_… or shpca_…) and paste it above.', 'mcp-ai-wpoos-pro' ); ?></li>
								</ol>
							</div>
							<!-- Catalog API guide -->
							<div class="shopify-catalog-api-guide" style="display: none;">
								<p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Catalog API — How to create Dev Dashboard credentials:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
								<ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
									<li><?php esc_html_e( 'Go to the Shopify Dev Dashboard at dev.shopify.com and sign in.', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Create a new API key (app) and copy the Client ID and Client Secret (shpss_…).', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'Paste the Client ID and Client Secret above. A JWT bearer token is obtained automatically on each request (tokens expire in ~60 minutes and are cached).', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( 'To limit results to your store only, enter your numeric Shop ID above. Find it in your Shopify admin URL (the number after /store/) or via the Admin GraphQL API query: { shop { id } }.', 'mcp-ai-wpoos-pro' ); ?></li>
								</ol>
							</div>
						</div>
					</td>
				</tr>

				<tr class="shopify-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox / Development Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'This is a Shopify development / test store', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Development stores are created from the Shopify Partners dashboard. Enabling this flag helps differentiate test environments from production stores in logs and alerts.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for Printful -->
				<tr class="printful-only-field" style="display: none;">
					<th scope="row">
						<label for="printful_api_key"><?php esc_html_e( 'API Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="printful_api_key" id="printful_api_key" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Printful API token from the Developer Portal. Private tokens do not expire until manually deleted. Stored encrypted.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="printful-only-field" style="display: none;">
					<th scope="row">
						<label for="printful_store_id"><?php esc_html_e( 'Store ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="printful_store_id" id="printful_store_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['store_id'] ) ? esc_attr( $connection['store_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Required only for account-level tokens. Sent as the X-PF-Store-Id header. Leave empty for store-level tokens.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="printful-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox / Development Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'This is a Printful development / test store', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Printful does not have a separate sandbox API. This flag marks the connection as a development store for logging and alerting purposes.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Type-specific fields for ShipEngine -->
				<tr class="shipengine-only-field" style="display: none;">
					<th scope="row">
						<label for="shipengine_api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="shipengine_api_key" id="shipengine_api_key" class="regular-text"
							value="" autocomplete="off">
						<?php if ( $is_edit && 'shipengine' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your ShipEngine API key from the ShipEngine dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="shipengine-only-field" style="display: none;">
					<th scope="row">
						<label for="shipengine_carrier_id"><?php esc_html_e( 'Carrier ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="shipengine_carrier_id" id="shipengine_carrier_id" class="regular-text"
							value="
							<?php
							$is_shipengine_edit = $is_edit && 'shipengine' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' );
							echo $is_shipengine_edit && ! empty( $connection['shipengine_carrier_id'] ) ? esc_attr( $connection['shipengine_carrier_id'] ) : '';
							?>
							"
							autocomplete="off" placeholder="se-123456">
						<p class="description"><?php esc_html_e( 'The ShipEngine carrier ID for USPS (e.g. "se-123456"). Find this under Carriers in your ShipEngine dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="shipengine-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && 'shipengine' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'Enable sandbox/test mode', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use a ShipEngine sandbox API key (starts with TEST_) for testing. Sandbox data is isolated from production.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="shipengine-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'ShipStation API Setup Guide', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px 16px; border-radius: 4px;">
							<p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'How to get your ShipStation API credentials:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
							<ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
								<li><?php esc_html_e( 'Sign up or log in at app.shipengine.com.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Go to Settings → API Keys and create a new API key.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'For sandbox testing, create a sandbox key (starts with TEST_) and enable "Sandbox Mode" above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Go to Carriers → Connect a carrier and connect your USPS account.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the carrier ID (e.g. "se-123456") from the Carriers page.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 8px 0 0;">
								<strong><?php esc_html_e( 'API Reference:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<a href="https://shipengine.github.io/shipengine-openapi/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ShipStation API (OpenAPI Documentation)', 'mcp-ai-wpoos-pro' ); ?></a>
							</p>
							<p style="margin: 4px 0 0; color: #646970; font-style: italic;">
								<?php esc_html_e( 'ShipStation API (formerly ShipEngine) is the recommended default carrier integration.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</div>
					</td>
				</tr>

				<!-- Type-specific fields for ShipStation -->
				<tr class="shipstation-only-field" style="display: none;">
					<th scope="row">
						<label for="shipstation_api_key"><?php esc_html_e( 'API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="shipstation_api_key" id="shipstation_api_key" class="regular-text"
							value="" autocomplete="off">
						<?php if ( $is_edit && 'shipstation' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your ShipStation API key from Settings → API Settings.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="shipstation-only-field" style="display: none;">
					<th scope="row">
						<label for="shipstation_api_secret"><?php esc_html_e( 'API Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="shipstation_api_secret" id="shipstation_api_secret" class="regular-text"
							value="" autocomplete="off">
						<?php if ( $is_edit && 'shipstation' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your ShipStation API secret from Settings → API Settings.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="shipstation-only-field" style="display: none;">
					<th scope="row">
						<label for="shipstation_carrier_code"><?php esc_html_e( 'Carrier Code', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="shipstation_carrier_code" id="shipstation_carrier_code" class="regular-text"
							value="
							<?php
							$is_shipstation_edit = $is_edit && 'shipstation' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' );
							echo $is_shipstation_edit && ! empty( $connection['shipstation_carrier_code'] ) ? esc_attr( $connection['shipstation_carrier_code'] ) : 'stamps_com';
							?>
							"
							autocomplete="off" placeholder="stamps_com">
						<p class="description"><?php esc_html_e( 'Carrier code in ShipStation (default: "stamps_com" for USPS). Other options: "fedex", "ups", etc.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="shipstation-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && 'shipstation' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'Enable sandbox/test mode', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use ShipStation sandbox API credentials for testing. Generate sandbox keys from your ShipStation test environment.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="shipstation-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'ShipStation V1 Setup Guide', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px 16px; border-radius: 4px;">
							<p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'How to get your ShipStation V1 API credentials:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
							<ol style="margin: 0; padding-left: 20px; line-height: 1.8;">
								<li><?php esc_html_e( 'Log in to your ShipStation account at ss.shipstation.com.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Go to Settings → Account → API Settings.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Click "Generate API Keys" if you haven\'t already.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the API Key and API Secret and paste them above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'For sandbox testing, enable "Sandbox Mode" above and use your sandbox API credentials.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 4px 0 0; color: #646970; font-style: italic;">
								<?php esc_html_e( 'Note: This is the legacy ShipStation V1 API. For new integrations, use "ShipStation API" (Recommended) instead.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</div>
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

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Sandbox / Development Mode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="sandbox_mode" value="1" <?php checked( $is_edit && ! empty( $connection['sandbox_mode'] ) ); ?>>
							<?php esc_html_e( 'Use Flowhub Sandbox environment (api.sandbox.flowhub.co)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Enable when testing against Flowhub\'s sandbox API instead of production. Uses separate credentials.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_proxy_enabled"><?php esc_html_e( 'Enable Proxy', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="flowhub_proxy_enabled" id="flowhub_proxy_enabled" value="1"
								<?php checked( $is_edit && ! empty( $connection['proxy_enabled'] ) ); ?> />
							<?php esc_html_e( 'Route FlowHub API requests through a proxy server', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use when FlowHub blocks requests from your server location. Services like Webshare offer free/cheap HTTP proxies.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_proxy_url"><?php esc_html_e( 'Proxy URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_proxy_url" id="flowhub_proxy_url"
							value="<?php echo $is_edit && isset( $connection['proxy_url'] ) ? esc_attr( $connection['proxy_url'] ) : ''; ?>"
							class="regular-text" placeholder="proxy.example.com:8080" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Proxy hostname and port (e.g., p.webshare.io:80). Supports HTTP proxies.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_proxy_username"><?php esc_html_e( 'Proxy Username', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="flowhub_proxy_username" id="flowhub_proxy_username"
							value="<?php echo $is_edit && isset( $connection['proxy_username'] ) ? esc_attr( $connection['proxy_username'] ) : ''; ?>"
							class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Optional — only needed if your proxy requires authentication.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="flowhub-only-field" style="display: none;">
					<th scope="row">
						<label for="flowhub_proxy_password"><?php esc_html_e( 'Proxy Password', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="flowhub_proxy_password" id="flowhub_proxy_password"
							value="" class="regular-text" autocomplete="off" />
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing proxy password.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
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

				<!-- Type-specific fields for QuickBooks Desktop (QODBC) -->
				<tr class="quickbooks_desktop-only-field" style="display: none;">
					<th scope="row">
						<label for="qbd_api_key"><?php esc_html_e( 'Relay API Key', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="qbd_api_key" id="qbd_api_key" class="large-text" rows="2" autocomplete="off"></textarea>
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing key. Shared secret or Bearer token used to authenticate with the QODBC relay API.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Shared secret or Bearer token used to authenticate with the QODBC relay API. Leave blank if the relay does not require authentication.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="quickbooks_desktop-only-field" style="display: none;">
					<th scope="row">
						<label for="qbd_dsn_name"><?php esc_html_e( 'ODBC DSN Name', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="qbd_dsn_name" id="qbd_dsn_name" class="regular-text" value="<?php echo $is_edit && isset( $connection['dsn_name'] ) ? esc_attr( $connection['dsn_name'] ) : ''; ?>" autocomplete="off" placeholder="QuickBooks Data">
						<p class="description"><?php esc_html_e( 'The ODBC Data Source Name configured on the relay server (e.g. "QuickBooks Data" or "QuickBooks Data QRemote"). Optional — the relay may have a default DSN.', 'mcp-ai-wpoos-pro' ); ?></p>
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

				<!-- Type-specific fields for Upwork -->
				<tr class="upwork-only-field" style="display: none;">
					<th scope="row">
						<label for="upwork_mode"><?php esc_html_e( 'Connection Mode', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php
						$saved_upwork_mode = $is_edit && 'upwork' === $connection_type && ! empty( $connection['upwork_mode'] )
							? $connection['upwork_mode']
							: 'api';
						?>
						<select name="upwork_mode" id="upwork_mode" onchange="toggleUpworkMode(this.value)">
							<option value="api" <?php selected( $saved_upwork_mode, 'api' ); ?>><?php esc_html_e( 'API — direct Upwork GraphQL access (requires OAuth)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="web_search" <?php selected( $saved_upwork_mode, 'web_search' ); ?>><?php esc_html_e( 'Web Search — AI-powered job discovery (no OAuth needed)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'API mode uses the Upwork GraphQL API for real-time results. Web Search mode uses AI-powered web search for job discovery without requiring OAuth credentials.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Upwork Web Search criteria fields (shown when mode is web_search) -->
				<tr class="upwork-only-field upwork-web-search-field" style="display: none;">
					<th scope="row">
						<label for="upwork_search_query"><?php esc_html_e( 'Default Search Keywords', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="upwork_search_query" id="upwork_search_query" class="regular-text" value="<?php echo $is_edit && isset( $connection['upwork_search_query'] ) ? esc_attr( $connection['upwork_search_query'] ) : ''; ?>" autocomplete="off" placeholder="e.g. WordPress developer, PHP, Elementor">
						<p class="description"><?php esc_html_e( 'Default keywords used when searching Upwork via web search. Leave empty to use CRM toolkit defaults.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-web-search-field" style="display: none;">
					<th scope="row">
						<label for="upwork_search_category"><?php esc_html_e( 'Default Category', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="upwork_search_category" id="upwork_search_category" class="regular-text" value="<?php echo $is_edit && isset( $connection['upwork_search_category'] ) ? esc_attr( $connection['upwork_search_category'] ) : ''; ?>" autocomplete="off" placeholder="e.g. Web, Mobile & Software Dev">
						<p class="description"><?php esc_html_e( 'Default job category filter for web search. Leave empty for all categories.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-web-search-field" style="display: none;">
					<th scope="row">
						<label for="upwork_search_job_type"><?php esc_html_e( 'Default Job Type', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php $saved_upwork_job_type = $is_edit && isset( $connection['upwork_search_job_type'] ) ? $connection['upwork_search_job_type'] : ''; ?>
						<select name="upwork_search_job_type" id="upwork_search_job_type">
							<option value="" <?php selected( $saved_upwork_job_type, '' ); ?>><?php esc_html_e( '— All Types —', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="hourly" <?php selected( $saved_upwork_job_type, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="fixed" <?php selected( $saved_upwork_job_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed Price', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default job type filter. Leave empty for all types.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Upwork API credential fields (shown when mode is api) -->
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row">
						<label for="upwork_client_id"><?php esc_html_e( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="upwork_client_id" id="upwork_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Upwork app Client ID from the API access page.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row">
						<label for="upwork_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="upwork_client_secret" id="upwork_client_secret" class="regular-text" value="" autocomplete="new-password">
						<p class="description">
							<?php if ( $is_edit && ! empty( $connection['client_secret'] ) ) : ?>
								<strong><?php esc_html_e( '(saved — leave blank to keep existing)', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php else : ?>
								<?php esc_html_e( 'Your Upwork app Client Secret.', 'mcp-ai-wpoos-pro' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$upwork_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'upwork_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<input type="text" readonly="readonly" value="<?php echo esc_url( $upwork_redirect_uri ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Add this URL as an Authorized Redirect URI in your Upwork app settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row">
						<label for="upwork_refresh_token"><?php esc_html_e( 'Refresh Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="upwork_refresh_token" id="upwork_refresh_token" class="large-text" rows="3" autocomplete="off"></textarea>
						<p class="description">
							<?php if ( $is_edit && ! empty( $connection['refresh_token'] ) ) : ?>
								<strong><?php esc_html_e( '(saved — use the connect button to refresh)', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php else : ?>
								<?php esc_html_e( 'Leave empty and use the Connect button below to obtain a refresh token via OAuth.', 'mcp-ai-wpoos-pro' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row">
						<label for="upwork_user_email"><?php esc_html_e( 'Upwork Username (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="upwork_user_email" id="upwork_user_email" class="regular-text" value="<?php echo $is_edit && isset( $connection['user_email'] ) ? esc_attr( $connection['user_email'] ) : ''; ?>" autocomplete="off" placeholder="your-upwork-username">
						<p class="description"><?php esc_html_e( 'Your Upwork username (auto-populated after OAuth connect).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<?php if ( $is_edit && 'upwork' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
				<tr class="upwork-only-field upwork-api-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Connect Upwork Account', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$upwork_oauth_url = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'upwork_oauth_connect',
								'connection_id' => $connection['id'],
								'_wpnonce'      => wp_create_nonce( 'upwork_oauth_connect_' . $connection['id'] ),
							),
							admin_url( 'admin.php' )
						);
						?>
						<a href="<?php echo esc_url( $upwork_oauth_url ); ?>" class="button button-primary">
							<?php esc_html_e( '🔗 Connect Upwork Account', 'mcp-ai-wpoos-pro' ); ?>
						</a>
						<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
							<span class="dashicons dashicons-yes" style="color: green; vertical-align: middle; margin-left: 8px;"></span>
							<span style="color: green;">
								<?php
								if ( ! empty( $connection['user_email'] ) ) {
									echo esc_html(
										sprintf(
											/* translators: %s: Upwork username */
											__( 'Connected as: %s', 'mcp-ai-wpoos-pro' ),
											$connection['user_email']
										)
									);
								} else {
									esc_html_e( 'Connected', 'mcp-ai-wpoos-pro' );
								}
								?>
							</span>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Click to authorize this plugin to access your Upwork account via OAuth 2.0.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>

				<!-- Type-specific fields for LinkedIn -->
				<tr class="linkedin-only-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_mode"><?php esc_html_e( 'Connection Mode', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php
						$saved_linkedin_mode = $is_edit && 'linkedin' === $connection_type && ! empty( $connection['linkedin_mode'] )
							? $connection['linkedin_mode']
							: 'api';
						?>
						<select name="linkedin_mode" id="linkedin_mode" onchange="toggleLinkedinMode(this.value)">
							<option value="api" <?php selected( $saved_linkedin_mode, 'api' ); ?>><?php esc_html_e( 'API — direct LinkedIn REST access (requires OAuth)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="web_search" <?php selected( $saved_linkedin_mode, 'web_search' ); ?>><?php esc_html_e( 'Web Search — AI-powered job discovery (no OAuth needed)', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'API mode uses the LinkedIn REST API for real-time results. Web Search mode uses AI-powered web search for job discovery without requiring OAuth credentials.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- LinkedIn Web Search criteria fields (shown when mode is web_search) -->
				<tr class="linkedin-only-field linkedin-web-search-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_search_query"><?php esc_html_e( 'Default Search Keywords', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="linkedin_search_query" id="linkedin_search_query" class="regular-text" value="<?php echo $is_edit && isset( $connection['linkedin_search_query'] ) ? esc_attr( $connection['linkedin_search_query'] ) : ''; ?>" autocomplete="off" placeholder="e.g. WordPress developer, PHP, remote">
						<p class="description"><?php esc_html_e( 'Default keywords used when searching LinkedIn via web search. Leave empty to use CRM toolkit defaults.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="linkedin-only-field linkedin-web-search-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_search_location"><?php esc_html_e( 'Default Location', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="linkedin_search_location" id="linkedin_search_location" class="regular-text" value="<?php echo $is_edit && isset( $connection['linkedin_search_location'] ) ? esc_attr( $connection['linkedin_search_location'] ) : ''; ?>" autocomplete="off" placeholder="e.g. United States, Remote, London">
						<p class="description"><?php esc_html_e( 'Default location filter for web search. Leave empty for all locations.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- LinkedIn API credential fields (shown when mode is api) -->
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_client_id"><?php esc_html_e( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="linkedin_client_id" id="linkedin_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your LinkedIn app Client ID from the LinkedIn Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="linkedin_client_secret" id="linkedin_client_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php $is_edit ? esc_attr_e( 'Leave blank to keep existing secret', 'mcp-ai-wpoos-pro' ) : ''; ?>">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep the existing client secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your LinkedIn app Client Secret. Stored encrypted.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$linkedin_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'linkedin_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<code style="word-break: break-all;"><?php echo esc_url( $linkedin_redirect_uri ); ?></code>
						<p class="description"><?php esc_html_e( 'Add this URL as an Authorized Redirect URI in your LinkedIn app settings.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_refresh_token"><?php esc_html_e( 'Refresh Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="linkedin_refresh_token" id="linkedin_refresh_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php $is_edit ? esc_attr_e( 'Auto-populated by OAuth flow', 'mcp-ai-wpoos-pro' ) : ''; ?>">
						<p class="description"><?php esc_html_e( 'Auto-populated after completing the OAuth flow. Do not enter manually.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row">
						<label for="linkedin_user_email"><?php esc_html_e( 'LinkedIn Email (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="linkedin_user_email" id="linkedin_user_email" class="regular-text" value="<?php echo $is_edit && isset( $connection['user_email'] ) ? esc_attr( $connection['user_email'] ) : ''; ?>" autocomplete="off" placeholder="you@example.com">
						<p class="description"><?php esc_html_e( 'Your LinkedIn account email (auto-populated after OAuth connect).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<?php if ( $is_edit && 'linkedin' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
				<tr class="linkedin-only-field linkedin-api-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Connect LinkedIn Account', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$linkedin_oauth_url = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'linkedin_oauth_connect',
								'connection_id' => $connection['id'],
								'_wpnonce'      => wp_create_nonce( 'linkedin_oauth_connect_' . $connection['id'] ),
							),
							admin_url( 'admin.php' )
						);
						?>
						<a href="<?php echo esc_url( $linkedin_oauth_url ); ?>" class="button button-primary">
							<?php esc_html_e( '🔗 Connect LinkedIn Account', 'mcp-ai-wpoos-pro' ); ?>
						</a>
						<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
							<span class="dashicons dashicons-yes" style="color: green; vertical-align: middle; margin-left: 8px;"></span>
							<span style="color: green;">
								<?php
								if ( ! empty( $connection['user_email'] ) ) {
									echo esc_html(
										sprintf(
											/* translators: %s: LinkedIn email */
											__( 'Connected as: %s', 'mcp-ai-wpoos-pro' ),
											$connection['user_email']
										)
									);
								} else {
									esc_html_e( 'Connected', 'mcp-ai-wpoos-pro' );
								}
								?>
							</span>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Click to authorize this plugin to access your LinkedIn account via OAuth 2.0.', 'mcp-ai-wpoos-pro' ); ?></p>
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
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Channel-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/telegram/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;">
								<?php esc_html_e( 'Use this URL when registering the webhook with Telegram (via Set Webhook below). Each Telegram bot has its own dedicated endpoint so that multiple bots can receive updates independently.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all bots):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use this generic URL only if a single bot serves all connections. The channel-specific URL above is preferred.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL to register with Telegram.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_secret_token"><?php esc_html_e( 'Webhook Secret Token', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="telegram_secret_token" id="telegram_secret_token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to auto-generate a secure 64-character token', 'mcp-ai-wpoos-pro' ); ?>">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Required for Telegram webhook authentication. Leave blank to keep the existing secret token, or enter a new value to rotate it. Telegram will send this value in the X-Telegram-Bot-Api-Secret-Token header on every update; the plugin rejects updates that do not include a matching token. Allowed characters: A–Z, a–z, 0–9, _ and –.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Required for Telegram webhook authentication. Leave blank and a secure 64-character token will be generated automatically when you click "Set Webhook". Telegram will send this value in the X-Telegram-Bot-Api-Secret-Token header on every update; the plugin rejects updates that do not include a matching token. Allowed characters: A–Z, a–z, 0–9, _ and –.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tg_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$tg_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="telegram_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $tg_assistants as $tg_assistant ) : ?>
								<option value="<?php echo esc_attr( $tg_assistant->ID ); ?>"<?php selected( in_array( $tg_assistant->ID, $tg_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $tg_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages sent to your Telegram bot.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Enable Group Chats', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="telegram_enable_groups" id="telegram_enable_groups" value="1" <?php checked( $is_edit && ! empty( $connection['enable_groups'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Allow the bot to respond in group and supergroup chats', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the bot will process and reply to messages in Telegram groups and supergroups. When disabled, the bot only responds in private (direct) chats.', 'mcp-ai-wpoos-pro' ); ?></p>
						<p class="description"><strong><?php esc_html_e( 'Privacy Mode:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'You may need to disable Privacy Mode via @BotFather (/setprivacy → Disable) so Telegram delivers all group messages to the bot.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Require Mention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="telegram_require_mention" id="telegram_require_mention" value="1" <?php checked( $is_edit && ! empty( $connection['require_mention'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Only reply when the bot is @mentioned or the message is a reply to the bot', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Applies to groups only. In private chats the bot always replies. When unchecked (default), the bot replies to every group message. When checked, the bot only auto-replies in groups when explicitly @mentioned, when an assigned assistant @slug is mentioned, or when the message is a direct reply to one of the bot\'s own messages. Fill in the Bot Username above for reliable mention detection.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Allowed Chat IDs', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$tg_allowed_ids = '';
						if ( $is_edit && isset( $connection['allowed_chat_ids'] ) && is_array( $connection['allowed_chat_ids'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
							$tg_allowed_ids = implode( ', ', array_map( 'esc_attr', $connection['allowed_chat_ids'] ) );
						}
						?>
						<textarea name="telegram_allowed_chat_ids" id="telegram_allowed_chat_ids" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'e.g. 123456789, -1001234567890', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $tg_allowed_ids ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Enter Telegram user IDs and/or chat IDs (comma-separated) that are allowed to interact with this bot. When the list is non-empty, messages from any ID not on the list will be silently ignored. Leave blank to allow everyone to chat with the bot.', 'mcp-ai-wpoos-pro' ); ?></p>
						<p class="description"><?php esc_html_e( 'Use positive integers for user IDs (e.g. 123456789) and negative integers for group/channel IDs (e.g. -1001234567890). You can find chat IDs by forwarding a message to @userinfobot on Telegram.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Bot Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="telegram_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Bot Token', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="telegram_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Calls the Telegram Bot API (getMe + getWebhookInfo) to verify your token and check the current webhook status. Works before saving.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="telegram_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="telegram_test_auto_reply_chat_id" class="regular-text" placeholder="<?php esc_attr_e( '123456789 or -1001234567890 or @channelname (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Chat ID, group ID, or @channel username (optional — if provided, the AI reply will be sent to this private chat, group, or channel via Telegram)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="telegram_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="telegram_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="telegram_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="telegram_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Send to Group/Channel', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="telegram_test_group_chat_id" class="regular-text" placeholder="<?php esc_attr_e( '-1001234567890, @channelname, or https://t.me/groupname', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Group/supergroup ID (negative number), @channel username, or a t.me link (e.g. https://t.me/groupname). Note: private invite links (t.me/+hash) cannot be used — use the numeric chat ID instead.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="telegram_test_group_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message to send…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="telegram_test_group_send_btn" class="button button-secondary">
								<?php esc_html_e( 'Send Test Message', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="telegram_test_group_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Send a direct message to a Telegram group, supergroup, or channel to verify outgoing message delivery. Requires the connection to be saved with a valid bot token. The bot must be a member of the group/channel.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="telegram_test_group_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Webhook Actions', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
							<button type="button" id="telegram_set_webhook_btn" class="button button-primary">
								<?php esc_html_e( 'Set Webhook', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<button type="button" id="telegram_check_webhook_btn" class="button button-secondary">
								<?php esc_html_e( 'Check Webhook Status', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="telegram_webhook_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Set Webhook registers the URL above with Telegram (calls setWebhook). Check Webhook Status retrieves current webhook info from Telegram (calls getWebhookInfo). Requires the bot token to be saved or entered above.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="telegram_webhook_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Web Login', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="telegram_enable_web_login" id="telegram_enable_web_login" value="1" <?php checked( $is_edit && ! empty( $connection['enable_web_login'] ) ); ?>>
							<?php esc_html_e( 'Enable Telegram Web Login', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to Telegram Web Login docs */
								esc_html__( 'Allow users to log in to your website using their Telegram account. Uses the %s. Requires you to set your site\'s domain in @BotFather with /setdomain.', 'mcp-ai-wpoos-pro' ),
								'<a href="https://core.telegram.org/widgets/login" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Telegram Login Widget', 'mcp-ai-wpoos-pro' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_web_login_redirect_url"><?php esc_html_e( 'Web Login Callback URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" readonly="readonly" value="<?php echo esc_url( rest_url( 'mcp-ai/v1/telegram-login' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description"><?php esc_html_e( 'Set this URL as the auth-url in the Login Widget (see the shortcode below). Telegram will redirect users here after authentication.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_web_login_redirect_url"><?php esc_html_e( 'After-Login Redirect URL (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="url" name="telegram_web_login_redirect_url" id="telegram_web_login_redirect_url" class="large-text" value="<?php echo $is_edit && isset( $connection['web_login_redirect_url'] ) ? esc_url( $connection['web_login_redirect_url'] ) : ''; ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Where to redirect the user after a successful Web Login. Leave blank to redirect to the home page. Use the wp_mcp_ai_telegram_login_redirect_url filter for dynamic redirects.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Login Widget Shortcode', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" readonly="readonly" value="[mcp_ai_telegram_login]" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description">
							<?php esc_html_e( 'Add this shortcode to any page or widget to display the Telegram Login button. Optional attributes:', 'mcp-ai-wpoos-pro' ); ?>
							<code>bot_username="mybot"</code>,
							<code>button_size="large|medium|small"</code>,
							<code>corner_radius="10"</code>,
							<code>request_access="write"</code>,
							<code>show_avatar="1|0"</code>,
							<code>lang="en"</code>.
						</p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'WordPress Account Creation', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						// Default to enabled for new connections and for existing connections
						// that pre-date this setting (backwards compatibility).
						$tg_auto_create_checked = ! $is_edit
							|| ! array_key_exists( 'auto_create_wp_user', $connection )
							|| ! empty( $connection['auto_create_wp_user'] );
						?>
						<label>
							<input type="checkbox" name="telegram_auto_create_wp_user" id="telegram_auto_create_wp_user" value="1" <?php checked( $tg_auto_create_checked ); ?>>
							<?php esc_html_e( 'Automatically create a WordPress account for new Telegram users', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, a WordPress account is created automatically the first time a user signs in via the Telegram Login Widget or Telegram Mini App, using the role configured below. Disable to require manual account linking.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_new_user_role"><?php esc_html_e( 'New User Role', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$saved_tg_role  = $is_edit && ! empty( $connection['new_user_role'] ) ? $connection['new_user_role'] : 'subscriber';
						$wp_roles_obj   = wp_roles();
						$all_role_names = $wp_roles_obj->get_names();
						?>
						<select name="telegram_new_user_role" id="telegram_new_user_role">
							<?php foreach ( $all_role_names as $role_key => $role_name ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $saved_tg_role, $role_key ); ?>><?php echo esc_html( translate_user_role( $role_name ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'WordPress role assigned to newly-created Telegram users. "Subscriber" is recommended for public-facing bots. Raise this for internal/team bots only.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Enable Channel Posts', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="telegram_enable_channels" id="telegram_enable_channels" value="1" <?php checked( $is_edit && ! empty( $connection['enable_channels'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Allow the bot to read and auto-reply to posts in Telegram Channels where it is an admin', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, incoming channel posts and edited channel posts are forwarded to the AI assistant for a reply. The bot must be an admin of the channel with post and message permissions.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_welcome_message"><?php esc_html_e( 'Welcome Message', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tg_welcome = $is_edit && isset( $connection['welcome_message'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? $connection['welcome_message']
							: '';
						?>
						<textarea name="telegram_welcome_message" id="telegram_welcome_message" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Leave blank to use the default welcome message shown to users when they send /start.', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_textarea( $tg_welcome ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Custom text sent to users who send the /start command. Leave blank to use the default welcome message. You may use Markdown formatting: **bold**, *italic*, `code`.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Slash Commands', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$tg_disabled_cmds = $is_edit && isset( $connection['disabled_commands'] ) && is_array( $connection['disabled_commands'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? $connection['disabled_commands']
							: array();
						$tg_cmd_descs     = $is_edit && isset( $connection['command_descriptions'] ) && is_array( $connection['command_descriptions'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? $connection['command_descriptions']
							: array();
						$tg_builtin_cmds  = array(
							'start'       => __( 'Start the bot &amp; see welcome message', 'mcp-ai-wpoos-pro' ),
							'help'        => __( 'Show available commands', 'mcp-ai-wpoos-pro' ),
							'tools'       => __( 'Browse AI tools', 'mcp-ai-wpoos-pro' ),
							'vectorstore' => __( 'Get vector store info for this assistant', 'mcp-ai-wpoos-pro' ),
							'balance'     => __( 'Check credits balance', 'mcp-ai-wpoos-pro' ),
							'app'         => __( 'Open the Mini App', 'mcp-ai-wpoos-pro' ),
							'settings'    => __( 'Open Mini App settings', 'mcp-ai-wpoos-pro' ),
							'status'      => __( 'Check bot connection status', 'mcp-ai-wpoos-pro' ),
							'cancel'      => __( 'Reset conversation history', 'mcp-ai-wpoos-pro' ),
						);
						// Merge dynamically registered slash commands from the global handler.
						global $wp_mcp_ai_slash_command_handler;
						if ( $wp_mcp_ai_slash_command_handler instanceof WP_MCP_AI_Slash_Command_Handler ) {
							foreach ( $wp_mcp_ai_slash_command_handler->get_commands() as $dyn_name => $dyn_config ) {
								if ( ! isset( $tg_builtin_cmds[ $dyn_name ] ) ) {
									$tg_builtin_cmds[ $dyn_name ] = ! empty( $dyn_config['description'] )
										? esc_html( $dyn_config['description'] )
										: esc_html( $dyn_name );
								}
							}
						}
						?>
						<table style="border-collapse: collapse; width: 100%;">
							<thead>
								<tr>
									<th style="text-align: left; padding: 4px 8px; width: 30px;"><?php esc_html_e( 'Disable', 'mcp-ai-wpoos-pro' ); ?></th>
									<th style="text-align: left; padding: 4px 8px; width: 120px;"><?php esc_html_e( 'Command', 'mcp-ai-wpoos-pro' ); ?></th>
									<th style="text-align: left; padding: 4px 8px;"><?php esc_html_e( 'Description (shown in Telegram)', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $tg_builtin_cmds as $cmd_name => $cmd_default_desc ) : ?>
									<?php $cmd_enabled = ! in_array( $cmd_name, $tg_disabled_cmds, true ); ?>
									<?php $cmd_desc = isset( $tg_cmd_descs[ $cmd_name ] ) ? $tg_cmd_descs[ $cmd_name ] : ''; ?>
									<tr>
										<td style="padding: 4px 8px; text-align: center; vertical-align: middle;">
											<input type="checkbox" name="telegram_disabled_commands[]" value="<?php echo esc_attr( $cmd_name ); ?>" id="telegram_cmd_disable_<?php echo esc_attr( $cmd_name ); ?>"
												style="transform: scale(1.2);"
												<?php
												if ( ! $cmd_enabled ) :
													?>
													checked="checked"<?php endif; ?>>
										</td>
										<td style="padding: 4px 8px; vertical-align: middle;">
											<label for="telegram_cmd_disable_<?php echo esc_attr( $cmd_name ); ?>" style="font-family: monospace; font-size: 13px;">/<?php echo esc_html( $cmd_name ); ?></label>
										</td>
										<td style="padding: 4px 8px;">
											<input type="text" name="telegram_command_descriptions[<?php echo esc_attr( $cmd_name ); ?>]"
												class="regular-text" style="width: 100%;"
												value="<?php echo esc_attr( $cmd_desc ); ?>"
												placeholder="<?php echo esc_attr( $cmd_default_desc ); ?>">
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description" style="margin-top: 8px;"><?php esc_html_e( 'Check "Disable" to hide a command from users (it will not appear in the Telegram command menu and will be silently ignored or handled by the AI). Descriptions appear as hints in the Telegram "/" menu when "Register Commands" is clicked. Leave blank to use the default.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div style="margin-top: 10px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
							<button type="button" id="telegram_register_commands_btn" class="button button-secondary" <?php echo $is_edit ? '' : 'disabled="disabled"'; ?>>
								<?php esc_html_e( 'Register Commands with Telegram', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="telegram_register_commands_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<?php if ( ! $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Save the connection first to enable command registration.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Sends the enabled commands to Telegram (setMyCommands). Users will see these in the "/" command menu. Save the connection before registering.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<div id="telegram_register_commands_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_parse_mode"><?php esc_html_e( 'AI Reply Format', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tg_parse_mode = $is_edit && isset( $connection['parse_mode'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? $connection['parse_mode']
							: 'HTML';
						?>
						<select name="telegram_parse_mode" id="telegram_parse_mode">
							<option value="HTML" <?php selected( $tg_parse_mode, 'HTML' ); ?>><?php esc_html_e( 'HTML (recommended — supports bold, italic, links)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Markdown" <?php selected( $tg_parse_mode, 'Markdown' ); ?>><?php esc_html_e( 'Markdown', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="MarkdownV2" <?php selected( $tg_parse_mode, 'MarkdownV2' ); ?>><?php esc_html_e( 'MarkdownV2', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Parse mode used when sending AI-generated replies to Telegram. HTML is recommended because the plugin automatically converts Markdown output from the AI model to Telegram-compatible HTML. Change only if you have a specific reason.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Mini App', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						// Default to enabled for new connections and for existing connections
						// that pre-date this setting (backwards compatibility).
						$tg_mini_app_enabled = ! $is_edit
							|| ! array_key_exists( 'enable_mini_app', $connection )
							|| ! empty( $connection['enable_mini_app'] );
						?>
						<label>
							<input type="checkbox" name="telegram_enable_mini_app" id="telegram_enable_mini_app" value="1" <?php checked( $tg_mini_app_enabled ); ?>>
							<?php esc_html_e( 'Enable the Telegram Mini App for this bot', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, the /app command and deep-link buttons open the built-in Telegram Mini App (Web App). The Mini App URL below must be set as the bot\'s menu button URL in @BotFather via /setmenubutton.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<p class="description" style="margin-top: 6px;">
							<strong><?php esc_html_e( 'Mini App URL:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php
							// Use the per-connection URL when editing so each bot has a unique Mini App address.
							$_tma_url = ( $is_edit && '' !== $editing )
								? rest_url( 'mcp-ai/v1/telegram-mini-app/' . sanitize_key( $editing ) )
								: rest_url( 'mcp-ai/v1/telegram-mini-app' );
							?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( $_tma_url ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; display: inline-block; max-width: 460px; vertical-align: middle; margin-left: 6px;">
							<?php if ( $is_edit && '' !== $editing ) : ?>
								<p class="description" style="margin-top: 4px;">
									<?php esc_html_e( 'This URL is unique to this bot. Use it when configuring the Mini App in @BotFather so that each bot resolves its own settings and credentials.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
						</p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label for="telegram_mini_app_assistant_id"><?php esc_html_e( 'Mini App Assistant', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tg_ma_assistants   = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$tg_ma_assistant_id = $is_edit && isset( $connection['mini_app_assistant_id'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? absint( $connection['mini_app_assistant_id'] )
							: 0;
						?>
						<select name="telegram_mini_app_assistant_id" id="telegram_mini_app_assistant_id">
							<option value="0"><?php esc_html_e( '— Use first Assigned Assistant (default) —', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $tg_ma_assistants as $tg_ma_post ) : ?>
								<option value="<?php echo esc_attr( $tg_ma_post->ID ); ?>" <?php selected( $tg_ma_assistant_id, $tg_ma_post->ID ); ?>>
									<?php echo esc_html( $tg_ma_post->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Choose a dedicated assistant for the Telegram Mini App. Leave as default to use the first assistant from the "Assigned Assistants" list above. This allows different AI personas for the in-app chat versus direct bot messages.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Mini App Template', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tg_ma_template = ( $is_edit && isset( $connection['mini_app_template'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) )
							? sanitize_key( $connection['mini_app_template'] )
							: '';

						// Load template registry to populate the dropdown.
						if ( ! class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' ) ) {
							$_tpl_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-mini-app-templates.php';
							if ( file_exists( $_tpl_file ) ) {
								require_once $_tpl_file;
							}
						}

						$all_templates = class_exists( 'WP_MCP_AI_Telegram_Mini_App_Template_Registry' )
							? WP_MCP_AI_Telegram_Mini_App_Template_Registry::get_all_meta()
							: array();
						?>
						<select name="telegram_mini_app_template" id="telegram_mini_app_template">
							<option value=""><?php esc_html_e( '— Use global default template —', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $all_templates as $tpl ) : ?>
								<option value="<?php echo esc_attr( $tpl['slug'] ); ?>" <?php selected( $tg_ma_template, $tpl['slug'] ); ?>>
									<?php echo esc_html( $tpl['icon'] . ' ' . $tpl['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Choose a Mini App template for this specific bot connection. Overrides the global template set in Chat Channels settings. Leave blank to use the global default.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<?php
				/*
				 * WooCommerce Data Source row – visible only when an e-commerce Mini App
				 * template (woo_shop or ecommerce) is selected for this connection.
				 */
				$tg_woo_source        = ( $is_edit && isset( $connection['mini_app_woo_source'] ) && 'telegram' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) )
					? sanitize_key( $connection['mini_app_woo_source'] )
					: 'local';
				$tg_woo_connection_id = ( $is_edit && isset( $connection['mini_app_woo_connection_id'] ) )
					? sanitize_key( $connection['mini_app_woo_connection_id'] )
					: '';

				// Gather all WordPress/WooCommerce remote connections for the dropdown.
				$_woo_remote_connections = array();
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $_rc ) {
						if ( isset( $_rc['connection_type'] ) && in_array( $_rc['connection_type'], array( 'wordpress', 'WordPress' ), true ) && ! empty( $_rc['enabled'] ) ) {
							$_woo_remote_connections[] = $_rc;
						}
					}
				}
				?>
				<tr class="telegram-only-field tma-woo-source-row" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'WooCommerce Data Source', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<fieldset>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="telegram_mini_app_woo_source" id="tma-woo-source-local" value="local"
									<?php checked( $tg_woo_source, 'local' ); ?>>
								<?php esc_html_e( 'Local Store — use WooCommerce on this WordPress site', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="telegram_mini_app_woo_source" id="tma-woo-source-remote" value="remote"
									<?php checked( $tg_woo_source, 'remote' ); ?>>
								<?php esc_html_e( 'Remote Connection — use a configured WooCommerce remote site', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<div id="tma-woo-remote-wrap" style="margin-top:8px;padding-left:20px;<?php echo 'remote' === $tg_woo_source ? '' : 'display:none'; ?>">
								<select name="telegram_mini_app_woo_connection_id" id="telegram_mini_app_woo_connection_id">
									<option value=""><?php esc_html_e( '— Select a remote WooCommerce connection —', 'mcp-ai-wpoos-pro' ); ?></option>
									<?php foreach ( $_woo_remote_connections as $_rc ) : ?>
										<option value="<?php echo esc_attr( $_rc['id'] ); ?>" <?php selected( $tg_woo_connection_id, $_rc['id'] ); ?>>
											<?php echo esc_html( ( $_rc['name'] ?? $_rc['id'] ) . ' (' . ( $_rc['url'] ?? '' ) . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( empty( $_woo_remote_connections ) ) : ?>
									<p class="description" style="color:#d63638;">
										<?php
										printf(
											/* translators: %s: URL to remote sites admin page */
											esc_html__( 'No WordPress remote connections found. %s first.', 'mcp-ai-wpoos-pro' ),
											'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ) . '">' . esc_html__( 'Add one in Remote Sites', 'mcp-ai-wpoos-pro' ) . '</a>'
										);
										?>
									</p>
								<?php endif; ?>
							</div>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Choose where the WooCommerce Shop Mini App reads its product and order data from. "Local Store" uses WooCommerce installed on this site. "Remote Connection" lets you power the Mini App from a separate WooCommerce store configured in Remote Sites.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<?php
				/*
				 * Shopify Data Source row – visible only when a Shopify Mini App
				 * template (shopify_shop or jewelry_shop) is selected for this connection.
				 */
				$tg_shopify_connection_id = ( $is_edit && isset( $connection['mini_app_shopify_connection_id'] ) )
					? sanitize_key( $connection['mini_app_shopify_connection_id'] )
					: '';

				// Gather all Shopify remote connections for the dropdown.
				$_shopify_remote_connections = array();
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $_rc ) {
						if ( isset( $_rc['connection_type'] ) && 'shopify' === $_rc['connection_type'] && ! empty( $_rc['enabled'] ) ) {
							$_shopify_remote_connections[] = $_rc;
						}
					}
				}
				?>
				<tr class="telegram-only-field tma-shopify-source-row" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Shopify Data Source', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="telegram_mini_app_shopify_connection_id" id="telegram_mini_app_shopify_connection_id">
							<option value=""><?php esc_html_e( '— Select a Shopify connection —', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $_shopify_remote_connections as $_rc ) : ?>
								<option value="<?php echo esc_attr( $_rc['id'] ); ?>" <?php selected( $tg_shopify_connection_id, $_rc['id'] ); ?>>
									<?php echo esc_html( ( $_rc['name'] ?? $_rc['id'] ) . ' (' . ( $_rc['url'] ?? '' ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( empty( $_shopify_remote_connections ) ) : ?>
							<p class="description" style="color:#d63638;">
								<?php
								printf(
									/* translators: %s: link to remote sites admin page */
									esc_html__( 'No Shopify remote connections found. %s first.', 'mcp-ai-wpoos-pro' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ) . '">' . esc_html__( 'Add one in Remote Sites', 'mcp-ai-wpoos-pro' ) . '</a>'
								);
								?>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Choose the Shopify store connection that this Mini App reads its product and order data from. Configure Shopify connections in Remote Sites.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<?php
				/*
				 * Flowhub Data Source row – visible only when a Flowhub Mini App
				 * template (flowhub_ecommerce) is selected for this connection.
				 */
				$tg_flowhub_connection_id = ( $is_edit && isset( $connection['mini_app_flowhub_connection_id'] ) )
					? sanitize_key( $connection['mini_app_flowhub_connection_id'] )
					: '';

				// Gather all Flowhub remote connections for the dropdown.
				$_flowhub_remote_connections = array();
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $_rc ) {
						if ( isset( $_rc['connection_type'] ) && 'flowhub' === $_rc['connection_type'] && ! empty( $_rc['enabled'] ) ) {
							$_flowhub_remote_connections[] = $_rc;
						}
					}
				}
				?>
				<tr class="telegram-only-field tma-flowhub-source-row" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Flowhub Data Source', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<select name="telegram_mini_app_flowhub_connection_id" id="telegram_mini_app_flowhub_connection_id">
							<option value=""><?php esc_html_e( '— Select a Flowhub connection —', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $_flowhub_remote_connections as $_rc ) : ?>
								<option value="<?php echo esc_attr( $_rc['id'] ); ?>" <?php selected( $tg_flowhub_connection_id, $_rc['id'] ); ?>>
									<?php echo esc_html( ( $_rc['name'] ?? $_rc['id'] ) . ' (' . ( $_rc['url'] ?? '' ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( empty( $_flowhub_remote_connections ) ) : ?>
							<p class="description" style="color:#d63638;">
								<?php
								printf(
									/* translators: %s: link to remote sites admin page */
									esc_html__( 'No Flowhub remote connections found. %s first.', 'mcp-ai-wpoos-pro' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ) . '">' . esc_html__( 'Add one in Remote Sites', 'mcp-ai-wpoos-pro' ) . '</a>'
								);
								?>
							</p>
						<?php endif; ?>
						<p class="description">
							<?php esc_html_e( 'Choose the Flowhub connection that this Mini App reads its product data from. Configure Flowhub connections in Remote Sites.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<script>
				( function() {
					/* Toggle the WooCommerce / Shopify / Flowhub Data Source rows when the template changes. */
					var tplSel      = document.getElementById( 'telegram_mini_app_template' );
					var wooRow      = document.querySelector( '.tma-woo-source-row' );
					var shopifyRow  = document.querySelector( '.tma-shopify-source-row' );
					var flowhubRow  = document.querySelector( '.tma-flowhub-source-row' );
					var remoteWrap  = document.getElementById( 'tma-woo-remote-wrap' );
					var radioLocal  = document.getElementById( 'tma-woo-source-local' );
					var radioRemote = document.getElementById( 'tma-woo-source-remote' );

					var wooTemplates     = [ 'woo_shop', 'ecommerce' ];
					var shopifyTemplates = [ 'shopify_shop', 'jewelry_shop', 'shopify_ecommerce' ];
					var flowhubTemplates = [ 'flowhub_ecommerce' ];

					function toggleDataSourceRows() {
						if ( ! tplSel ) { return; }
						var val = tplSel.value;
						if ( wooRow )     { wooRow.style.display     = wooTemplates.indexOf( val ) !== -1     ? '' : 'none'; }
						if ( shopifyRow ) { shopifyRow.style.display = shopifyTemplates.indexOf( val ) !== -1 ? '' : 'none'; }
						if ( flowhubRow ) { flowhubRow.style.display = flowhubTemplates.indexOf( val ) !== -1 ? '' : 'none'; }
					}

					function toggleRemoteWrap() {
						if ( ! remoteWrap ) { return; }
						remoteWrap.style.display = ( radioRemote && radioRemote.checked ) ? 'block' : 'none';
					}

					if ( tplSel ) {
						tplSel.addEventListener( 'change', toggleDataSourceRows );
						toggleDataSourceRows();
					}
					if ( radioLocal )  { radioLocal.addEventListener( 'change', toggleRemoteWrap ); }
					if ( radioRemote ) { radioRemote.addEventListener( 'change', toggleRemoteWrap ); }
				}() );
				</script>

				<tr class="telegram-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Bot Creation Guide', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<details>
							<summary style="cursor: pointer; font-weight: 600; color: #2271b1;"><?php esc_html_e( 'How to create a Telegram bot with @BotFather', 'mcp-ai-wpoos-pro' ); ?></summary>
							<ol style="margin: 10px 0 0 16px; line-height: 1.8;">
								<li><?php esc_html_e( 'Open Telegram and start a chat with @BotFather (https://t.me/BotFather).', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Send the /newbot command.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Choose a display name for your bot (e.g. "My Site Support Bot").', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Choose a username ending in "bot" (e.g. "mysitesupport_bot"). This becomes your bot\'s @handle.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the bot token that BotFather sends (format: 1234567890:ABCdef…) and paste it into the Bot Token field above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Optionally, use /setdescription, /setabouttext, and /setuserpic to customize your bot.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection, then click Set Webhook to register the webhook URL with Telegram automatically.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'To enable Web Login: tick "Enable Telegram Web Login" above and send /setdomain to @BotFather to authorize your site\'s domain.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin-top: 8px; font-size: 13px;">
								<?php
								printf(
									/* translators: %s: link to Telegram Bot API docs */
									esc_html__( 'For advanced configuration see the %s.', 'mcp-ai-wpoos-pro' ),
									'<a href="https://core.telegram.org/bots/api" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Telegram Bot API documentation', 'mcp-ai-wpoos-pro' ) . '</a>'
								);
								?>
							</p>
						</details>
					</td>
				</tr>

				<!-- Type-specific fields for WhatsApp -->
				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_channel_description"><?php esc_html_e( 'Channel Description', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_channel_description" id="whatsapp_channel_description" class="regular-text" value="<?php echo $is_edit && isset( $connection['channel_description'] ) ? esc_attr( $connection['channel_description'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. Customer Support, Sales Enquiries', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional label to identify this WhatsApp channel. Useful when managing multiple channels connected to different phone numbers.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

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
						<label for="whatsapp_app_id"><?php esc_html_e( 'App ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_app_id" id="whatsapp_app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Meta App ID (found in Meta App Dashboard → Settings → Basic). Together with the App Secret above, this enables automatic access token renewal when the token expires.', 'mcp-ai-wpoos-pro' ); ?></p>
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
					<th scope="row">
						<label for="whatsapp_channel_url"><?php esc_html_e( 'Channel URL (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="url" name="whatsapp_channel_url" id="whatsapp_channel_url" class="regular-text" value="<?php echo $is_edit && isset( $connection['channel_url'] ) ? esc_url( $connection['channel_url'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. https://chat.whatsapp.com/…', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Optional. Enter a custom channel URL (e.g. a WhatsApp Group invite link from the Groups Management API) to use instead of the auto-generated phone number link. When provided, this URL will be shown as the Channel Link and used for the QR code.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="whatsapp_group_id"><?php esc_html_e( 'Group ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="whatsapp_group_id" id="whatsapp_group_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['group_id'] ) ? esc_attr( $connection['group_id'] ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. 120363…@g.us', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Optional. When set, AI auto-replies will be sent to this WhatsApp group instead of the individual sender. The business phone must be a member of the group. Use the Create Group tool below or paste a group ID from the Groups Management API.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Create Group', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 8px;">
							<div>
								<label for="whatsapp_group_subject" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Group Name (Subject)', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="whatsapp_group_subject" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'My Group Name', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off" style="width: 220px;">
							</div>
							<div style="flex: 1; min-width: 200px;">
								<label for="whatsapp_group_description" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Description (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="text" id="whatsapp_group_description" class="regular-text" maxlength="512" placeholder="<?php esc_attr_e( 'Group description…', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="off" style="width: 100%;">
							</div>
						</div>
						<div>
							<button type="button" id="whatsapp_create_group_btn" class="button button-secondary">
								<?php esc_html_e( 'Create WhatsApp Group', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="whatsapp_create_group_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Creates a new WhatsApp group via the Groups Management API. The business phone number must have the whatsapp_business_messaging permission. On success, the Group ID and Channel URL fields are populated automatically — save the connection to persist them.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="whatsapp_create_group_result" style="display: none; margin-top: 8px;"></div>
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
					<th scope="row"><?php esc_html_e( 'Register Phone Number', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;">
							<div>
								<label for="whatsapp_register_pin" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Two-Step Verification PIN', 'mcp-ai-wpoos-pro' ); ?></label>
								<input type="password" id="whatsapp_register_pin" class="regular-text" maxlength="6" pattern="[0-9]{6}" placeholder="<?php esc_attr_e( '6-digit PIN', 'mcp-ai-wpoos-pro' ); ?>" autocomplete="new-password" style="width: 140px; letter-spacing: 0.15em;">
							</div>
							<div>
								<button type="button" id="whatsapp_register_phone_btn" class="button button-primary">
									<?php esc_html_e( 'Register Phone Number', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<span id="whatsapp_register_phone_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'If you receive error #133010 (Account not registered), click here to register your WhatsApp Business phone number with the Cloud API. Enter the 6-digit two-step verification PIN for this number, then click Register. Your Access Token and Phone Number ID must be saved first.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="whatsapp_register_phone_result" style="display: none; margin-top: 8px;"></div>
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
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Channel-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;">
								<?php esc_html_e( 'Use this URL in the Meta Developer Dashboard for this channel. Each WhatsApp channel has its own dedicated endpoint so that separate Meta Apps can send webhooks independently.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all channels):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use this generic URL only if all channels share the same Meta App. The channel-specific URL above is preferred.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL for use in the Meta Developer Dashboard.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="whatsapp-only-field" style="display: none;">
					<th scope="row">
						<label for="assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$wa_assistants       = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'whatsapp' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $wa_assistants as $wa_assistant ) : ?>
								<option value="<?php echo esc_attr( $wa_assistant->ID ); ?>"<?php selected( in_array( $wa_assistant->ID, $saved_assistant_ids, true ) ); ?>>
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
						$wa_phone_id      = $is_edit && isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
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
						// Prefer a custom channel URL over the auto-generated phone-number link.
						$wa_custom_url = $is_edit && ! empty( $connection['channel_url'] ) ? $connection['channel_url'] : '';
						if ( ! empty( $wa_custom_url ) || ! empty( $wa_display_phone ) ) :
							if ( ! empty( $wa_custom_url ) ) {
								$wa_link = $wa_custom_url;
							} else {
								// Normalise phone number for wa.me link (digits only).
								$wa_phone_digits = preg_replace( '/[^0-9]/', '', $wa_display_phone );
								$wa_link         = 'https://wa.me/' . $wa_phone_digits;
							}
							?>
							<div>
								<p style="margin: 0 0 8px 0;"><?php esc_html_e( 'Users can scan this QR code to start a WhatsApp conversation with your business number.', 'mcp-ai-wpoos-pro' ); ?></p>
								<?php
								$qr_result = wp_mcp_ai_generate_qr_code( $wa_link, 'data-url' );
								if ( ! is_wp_error( $qr_result ) ) :
									?>
									<img src="<?php echo esc_attr( $qr_result ); ?>" alt="<?php esc_attr_e( 'WhatsApp Channel QR Code', 'mcp-ai-wpoos-pro' ); ?>" style="width: 180px; height: 180px; border: 1px solid #ddd; border-radius: 4px; display: block; margin-bottom: 8px;">
								<?php else : ?>
									<p class="description" style="margin-bottom: 8px;"><?php esc_html_e( 'QR code generation requires Node.js on your server. Use the channel link below with an external QR generator.', 'mcp-ai-wpoos-pro' ); ?></p>
								<?php endif; ?>
								<p style="margin: 0 0 4px 0;">
									<strong><?php esc_html_e( 'Channel Link:', 'mcp-ai-wpoos-pro' ); ?></strong>
									<a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $wa_link ); ?></a>
								</p>
							</div>
						<?php else : ?>
							<p class="description">
								<?php
								if ( $is_edit && ! empty( $wa_phone_id ) ) {
									esc_html_e( 'Enter a Channel URL or your display phone number in the fields above, or run "Test Connection" to retrieve the phone number automatically. Members can then use the generated QR code or link to start a conversation, and the assigned assistant will respond.', 'mcp-ai-wpoos-pro' );
								} else {
									esc_html_e( 'Save the connection with a Phone Number ID and optionally a Channel URL or Display Phone Number to generate a QR code and channel link. Members can scan the QR or use the link to message your WhatsApp number, and the assigned assistant will respond automatically.', 'mcp-ai-wpoos-pro' );
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
								<li><?php esc_html_e( 'Add an optional Channel Description to label this number (e.g. "Customer Support", "Sales Enquiries")', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign one or more AI Assistants — they will respond to members who message via the QR code or channel link', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Create a secure Verify Token (random string)', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection, then click "Test Connection" to verify your credentials instantly', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the channel-specific Webhook URL shown above and configure it in your Meta Developer Dashboard along with the Verify Token', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'To add more WhatsApp numbers, click "Add New Connection" and repeat — each channel gets its own dedicated webhook URL', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0 0 6px 0; font-size: 13px; color: #2271b1;">
								ℹ <strong><?php esc_html_e( 'Multiple Channels:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'You can connect multiple WhatsApp numbers by creating a separate connection for each one. Every connection receives its own channel-specific webhook URL (shown in the Webhook URL field after saving), so separate Meta Apps route to their respective channels independently.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 6px 0; font-size: 13px; color: #2271b1;">
								ℹ <strong><?php esc_html_e( 'Advanced Access:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'The whatsapp_business_messaging permission is required for sending and receiving messages. Apply for Advanced Access via email in the Meta App Review portal. Quality Rating and phone display fields require the separate whatsapp_business_management permission.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0; font-size: 13px;">
								<strong><?php esc_html_e( 'Need help?', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php
								$docs_path = WP_MCP_AI_PRO_PATH . 'docs/WHATSAPP_SETUP_GUIDE.md';
								if ( file_exists( $docs_path ) ) {
									echo wp_kses_post(
										sprintf(
										/* translators: %s: link to setup guide */
											__( 'See our <a href="%s" target="_blank">complete WhatsApp setup guide</a> for detailed instructions.', 'mcp-ai-wpoos-pro' ),
											esc_url( WP_MCP_AI_PRO_URL . 'docs/WHATSAPP_SETUP_GUIDE.md' )
										)
									);
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
					<td colspan="2" style="padding: 0;">
						<div style="background: #f7f5fb; border-left: 4px solid #4a154b; padding: 14px 16px 10px; margin-bottom: 2px;">
							<h3 style="margin: 0 0 6px; font-size: 14px; color: #1d2327;">
								<?php esc_html_e( 'Slack Channel Connection Settings', 'mcp-ai-wpoos-pro' ); ?>
							</h3>
							<p style="margin: 0; font-size: 13px; color: #50575e;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to api.slack.com/apps */
										__( 'Configure your Slack bot credentials from %s. After saving, use the Webhook URL below in your Slack app\'s Event Subscriptions to enable AI auto-replies.', 'mcp-ai-wpoos-pro' ),
										'<a href="' . esc_url( 'https://api.slack.com/apps' ) . '" target="_blank" rel="noopener noreferrer">api.slack.com/apps</a>'
									),
									array(
										'a' => array(
											'href'   => true,
											'target' => true,
											'rel'    => true,
										),
									)
								);
								?>
							</p>
						</div>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label for="slack_bot_token"><?php esc_html_e( 'Bot Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="slack_bot_token" id="slack_bot_token" class="regular-text" value="" autocomplete="new-password" placeholder="xoxb-your-bot-token">
						<?php if ( $is_edit && ! empty( $connection['api_key'] ) && 'slack' === ( $connection['connection_type'] ?? '' ) ) : ?>
							<p class="description" style="color:#00a32a;">&#10003; <?php esc_html_e( 'Bot token is saved. Leave blank to keep the existing token, or enter a new one to replace it.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php elseif ( $is_edit ) : ?>
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
						<?php if ( $is_edit && ! empty( $connection['signing_secret'] ) && 'slack' === ( $connection['connection_type'] ?? '' ) ) : ?>
							<p class="description" style="color:#00a32a;">&#10003; <?php esc_html_e( 'Signing secret is saved. Leave blank to keep the existing secret, or enter a new one to replace it.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php elseif ( $is_edit ) : ?>
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
						<!-- Hidden field: bot Slack user ID (U-prefixed). Auto-populated by the Test Bot Token button. -->
						<input type="hidden" name="slack_bot_user_id" id="slack_bot_user_id" value="<?php echo $is_edit && ! empty( $connection['slack_bot_user_id'] ) ? esc_attr( $connection['slack_bot_user_id'] ) : ''; ?>">
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Workspace-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/slack/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;">
								<?php esc_html_e( 'Use this URL when configuring Event Subscriptions in your Slack app (Request URL field). Each Slack workspace has its own dedicated endpoint so that multiple workspaces can receive events independently.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all workspaces):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/slack' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use this generic URL only if a single Slack workspace is configured. The workspace-specific URL above is preferred.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/slack' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a workspace-specific webhook URL to configure in your Slack app Event Subscriptions.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row">
						<label for="slack_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$sl_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$sl_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'slack' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="slack_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $sl_assistants as $sl_assistant ) : ?>
								<option value="<?php echo esc_attr( $sl_assistant->ID ); ?>"<?php selected( in_array( $sl_assistant->ID, $sl_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $sl_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages sent to this Slack workspace.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Require Mention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="slack_require_mention" id="slack_require_mention" value="1" <?php checked( $is_edit && ! empty( $connection['require_mention'] ) && 'slack' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Only reply when the assistant is @mentioned', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the bot only auto-replies to messages that explicitly @mention one of its assigned assistants. Useful for shared Slack channels where the bot should stay quiet unless addressed directly.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Required Scopes', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<p class="description" style="margin-bottom: 8px;">
							<?php esc_html_e( 'Your Slack app (api.slack.com/apps) must have the following OAuth Bot Token Scopes enabled, and the app must be reinstalled to the workspace after adding scopes. See:', 'mcp-ai-wpoos-pro' ); ?>
							<a href="https://docs.slack.dev/reference/scopes/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Slack Scopes Reference', 'mcp-ai-wpoos-pro' ); ?></a>
						</p>
						<table class="widefat striped" style="max-width: 640px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'OAuth Scope', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Required For', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td><code>chat:write</code></td><td><?php esc_html_e( 'Sending AI replies to channels and DMs', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>app_mentions:read</code></td><td><?php esc_html_e( '@mention detection — subscribe to the app_mention event so the bot replies when @mentioned in any channel', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>channels:history</code></td><td><?php esc_html_e( 'Reading messages in public channels (message.channels event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>groups:history</code></td><td><?php esc_html_e( 'Reading messages in private channels (message.groups event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>im:history</code></td><td><?php esc_html_e( 'Reading direct messages sent to the bot (message.im event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>mpim:history</code></td><td><?php esc_html_e( 'Reading messages in multi-person DMs (message.mpim event)', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>channels:read</code></td><td><?php esc_html_e( 'Listing and identifying public channels', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>groups:read</code></td><td><?php esc_html_e( 'Listing and identifying private channels', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>im:read</code></td><td><?php esc_html_e( 'Listing direct message conversations', 'mcp-ai-wpoos-pro' ); ?></td></tr>
								<tr><td><code>users:read</code></td><td><?php esc_html_e( 'Looking up user display names and info', 'mcp-ai-wpoos-pro' ); ?></td></tr>
							</tbody>
						</table>
						<p class="description" style="margin-top: 8px;">
							<strong><?php esc_html_e( 'Event Subscriptions:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php esc_html_e( 'In your Slack app under Event Subscriptions → Subscribe to bot events, add: app_mention (for @mentions), message.channels (public channels), message.groups (private channels), message.im (DMs), message.mpim (group DMs).', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Bot Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="slack_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Bot Token', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="slack_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Calls the Slack API (auth.test) to verify your bot token. Works before saving.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="slack_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="slack-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="slack_test_auto_reply_channel" class="regular-text" placeholder="<?php esc_attr_e( '#general or C0123456789 (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Channel name or ID (optional — if provided, the AI reply will be sent to this Slack channel)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="slack_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="slack_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="slack_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="slack_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
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

				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label for="discord_public_key"><?php esc_html_e( 'Public Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="discord_public_key" id="discord_public_key" class="regular-text" value="<?php echo $is_edit && isset( $connection['public_key'] ) && 'discord' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['public_key'] ) : ''; ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Ed25519 public key from Discord Developer Portal', 'mcp-ai-wpoos-pro' ); ?>">
						<p class="description"><?php esc_html_e( 'Ed25519 public key from the Discord Developer Portal. Used to verify the signature of every incoming interaction request. Found under General Information → Public Key.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row">
						<label for="discord_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$ds_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$ds_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'discord' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="discord_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $ds_assistants as $ds_assistant ) : ?>
								<option value="<?php echo esc_attr( $ds_assistant->ID ); ?>"<?php selected( in_array( $ds_assistant->ID, $ds_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $ds_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages sent to this Discord bot.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Require Mention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="discord_require_mention" id="discord_require_mention" value="1" <?php checked( $is_edit && ! empty( $connection['require_mention'] ) && 'discord' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Only reply when the assistant is @mentioned', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the bot only auto-replies to messages that explicitly @mention one of its assigned assistants. Useful for shared Discord servers where the bot should stay quiet unless addressed directly.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Bot Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="discord_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Bot Token', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="discord_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Calls the Discord API (users/@me) to verify your bot token. Works before saving.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="discord_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="discord-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="discord_test_auto_reply_channel" class="regular-text" placeholder="<?php esc_attr_e( '123456789012345678 (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Channel ID (optional — if provided, the AI reply will be sent to this Discord channel)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="discord_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="discord_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="discord_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="discord_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<!-- Type-specific fields for Microsoft Teams -->
				<tr class="microsoft_teams-only-field" style="display: none;">
					<td colspan="2" style="padding: 0;">
						<div style="background: #f0f2f7; border-left: 4px solid #6264a7; padding: 14px 16px 10px; margin-bottom: 2px;">
							<h3 style="margin: 0 0 6px; font-size: 14px; color: #1d2327;">
								<?php esc_html_e( 'Microsoft Teams Connection Settings', 'mcp-ai-wpoos-pro' ); ?>
							</h3>
							<p style="margin: 0 0 8px; font-size: 13px; color: #50575e;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to Microsoft Teams outgoing webhooks documentation */
										__( 'Connect your WordPress site to Microsoft Teams by creating an <a href="%s" target="_blank" rel="noopener noreferrer">Outgoing Webhook</a> in Teams. Each Teams organisation can have its own dedicated webhook connection, allowing multiple tenants to connect independently.', 'mcp-ai-wpoos-pro' ),
										'https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-outgoing-webhook'
									),
									array(
										'a' => array(
											'href'   => true,
											'target' => true,
											'rel'    => true,
										),
									)
								);
								?>
							</p>
							<ol style="margin: 0 0 0 18px; font-size: 13px; color: #50575e;">
								<li style="margin-bottom: 4px;"><?php esc_html_e( 'Save this connection first to generate your connection-specific Webhook URL below.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li style="margin-bottom: 4px;">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: link to Microsoft Teams Admin Center */
											__( 'In Microsoft Teams, open a team, click <strong>… More options</strong> → <strong>Manage team</strong> → <strong>Apps</strong> → <strong>Create outgoing webhook</strong> (or visit the <a href="%s" target="_blank" rel="noopener noreferrer">Teams Admin Center</a> → Apps → Manage apps → Outgoing webhooks).', 'mcp-ai-wpoos-pro' ),
											'https://admin.teams.microsoft.com/'
										),
										array(
											'strong' => array(),
											'a'      => array(
												'href'   => true,
												'target' => true,
												'rel'    => true,
											),
										)
									);
									?>
								</li>
								<li style="margin-bottom: 4px;"><?php esc_html_e( 'Paste the Webhook URL (from the field below) as the Callback URL. Copy the Security Token shown by Teams into the Security Token field here.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Optionally add a Microsoft Graph Access Token to enable proactive reply capabilities via the Graph API.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_app_id"><?php esc_html_e( 'App ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="teams_app_id" id="teams_app_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['app_id'] ) ? esc_attr( $connection['app_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Your Azure AD application ID (for reference only). Not required for outgoing webhook connections.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_security_token"><?php esc_html_e( 'Security Token (Signing Secret)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['signing_secret'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'Security token is saved. Leave blank to keep the existing token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="teams_security_token" id="teams_security_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( ! $is_edit || empty( $connection['signing_secret'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'The HMAC-SHA256 security token shown when creating the outgoing webhook in the Microsoft Teams Admin Center. Used to verify that requests genuinely originate from Teams.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_graph_token"><?php esc_html_e( 'Microsoft Graph Access Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['token'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'Access token is saved. Leave blank to keep the existing token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="teams_graph_token" id="teams_graph_token" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Optional: Microsoft Graph API Bearer token used to post AI replies directly to Teams channels via the Graph API. Obtain via Azure AD application credentials or admin consent. Leave blank if you only need incoming message processing.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_tenant_id"><?php esc_html_e( 'Tenant ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="teams_tenant_id" id="teams_tenant_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['tenant_id'] ) ? esc_attr( $connection['tenant_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional: Azure AD tenant ID. When provided, the OAuth flow is scoped to this tenant; otherwise "common" (multi-tenant) is used.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Microsoft Teams: 1-click OAuth connect via Azure AD -->
				<tr class="microsoft_teams-only-field" style="display: none;">
					<td colspan="2" style="padding: 0;">
						<div style="background: #eef0f9; border-left: 4px solid #6264a7; padding: 12px 16px 8px; margin-bottom: 2px;">
							<h4 style="margin: 0 0 4px; font-size: 13px; color: #1d2327;">
								<?php esc_html_e( '1-Click Microsoft Graph Connect (Optional)', 'mcp-ai-wpoos-pro' ); ?>
							</h4>
							<p style="margin: 0; font-size: 12px; color: #50575e;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to Azure AD app registration documentation */
										__( 'Register an <a href="%s" target="_blank" rel="noopener noreferrer">Azure AD application</a>, enter its credentials below, then click the connect button to automatically obtain a Microsoft Graph access token. This enables the bot to send proactive replies to Teams channels and chats without manual token management.', 'mcp-ai-wpoos-pro' ),
										'https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade'
									),
									array(
										'a' => array(
											'href'   => true,
											'target' => true,
											'rel'    => true,
										),
									)
								);
								?>
							</p>
						</div>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_oauth_client_id"><?php esc_html_e( 'Azure AD Client ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="teams_oauth_client_id" id="teams_oauth_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'The Application (client) ID from your Azure AD app registration. Used for the 1-click OAuth connect flow.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_oauth_client_secret"><?php esc_html_e( 'Azure AD Client Secret (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['client_secret'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'Client secret is saved. Leave blank to keep the existing secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="teams_oauth_client_secret" id="teams_oauth_client_secret" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'The client secret value from your Azure AD app registration. Stored encrypted. Used for the 1-click OAuth connect flow.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'OAuth Redirect URI', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$teams_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'teams_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<input type="text" readonly="readonly" value="<?php echo esc_url( $teams_redirect_uri ); ?>" class="large-text code" id="teams_oauth_redirect_uri" style="background-color: #f0f0f0;">
						<p class="description">
							<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php esc_html_e( 'Add this exact URL to the "Redirect URIs" in your Azure AD app registration (under Authentication → Web).', 'mcp-ai-wpoos-pro' ); ?>
							&nbsp;<a href="https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Azure Portal', 'mcp-ai-wpoos-pro' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span></a>
						</p>
					</td>
				</tr>

				<!-- Microsoft Teams: 1-click connect button -->
				<?php if ( $is_edit && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
					<tr class="microsoft_teams-only-field" style="display: none;">
						<th scope="row">
							<label><?php esc_html_e( '1-Click Connect', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php
							$teams_has_credentials = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] );
							$teams_oauth_url       = $teams_has_credentials
								? wp_nonce_url(
									add_query_arg(
										array(
											'page' => 'wp-mcp-ai-remote-sites',
											'oauth_handler' => 'teams_oauth_connect',
											'connection_id' => $connection['id'],
										),
										admin_url( 'admin.php' )
									),
									'teams_oauth_connect_' . $connection['id']
								)
								: '#';
							?>
							<a href="<?php echo esc_url( $teams_oauth_url ); ?>" class="button button-primary" <?php echo $teams_has_credentials ? '' : 'aria-disabled="true" style="opacity:0.5;cursor:not-allowed;" onclick="return false;"'; ?>>
								<span class="dashicons dashicons-microsoft" style="margin-top: 3px; font-size: 16px; vertical-align: middle;"></span>
								<?php esc_html_e( 'Connect with Microsoft', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<?php if ( ! $teams_has_credentials ) : ?>
								<p class="description" style="color: #d63638; margin-top: 6px;">
									<span class="dashicons dashicons-warning"></span>
									<?php esc_html_e( 'Enter and save the Azure AD Client ID and Client Secret above before using this button.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description" style="margin-top: 6px;">
									<?php esc_html_e( 'Click to authorize this connection with your Microsoft account. You will be redirected to Microsoft login and then returned here with tokens automatically saved.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $connection['refresh_token'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
								<p class="description" style="color: #46b450; margin-top: 4px;">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'This connection is already authorized via OAuth. The Graph token is managed automatically. Click the button above to re-authorize if needed.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Outgoing Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Connection-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<div style="display: flex; gap: 6px; align-items: center; margin-bottom: 6px;">
								<input type="text" readonly="readonly" id="teams_webhook_url_specific" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/teams/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; flex: 1;">
								<button type="button" class="button button-secondary wp-mcp-ai-copy-btn" data-copy-target="teams_webhook_url_specific" title="<?php esc_attr_e( 'Copy to clipboard', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> <?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<p class="description" style="margin-bottom: 10px;">
								<?php esc_html_e( 'Register this URL as the Callback URL when creating an Outgoing Webhook in Microsoft Teams. Each Teams organisation has its own dedicated endpoint so that multiple tenants can receive messages independently.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all tenants):', 'mcp-ai-wpoos-pro' ); ?></p>
							<div style="display: flex; gap: 6px; align-items: center;">
								<input type="text" readonly="readonly" id="teams_webhook_url_generic" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/teams' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; flex: 1;">
								<button type="button" class="button button-secondary wp-mcp-ai-copy-btn" data-copy-target="teams_webhook_url_generic" title="<?php esc_attr_e( 'Copy to clipboard', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> <?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Use this generic URL only if a single Teams tenant is configured. The connection-specific URL above is preferred for multi-tenant setups.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<div style="display: flex; gap: 6px; align-items: center;">
								<input type="text" readonly="readonly" id="teams_webhook_url_generic" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/teams' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; flex: 1;">
								<button type="button" class="button button-secondary wp-mcp-ai-copy-btn" data-copy-target="teams_webhook_url_generic" title="<?php esc_attr_e( 'Copy to clipboard', 'mcp-ai-wpoos-pro' ); ?>">
									<span class="dashicons dashicons-clipboard" style="margin-top: 3px;"></span> <?php esc_html_e( 'Copy', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Save this connection first to get a connection-specific webhook URL to register in Microsoft Teams. Each connection gets its own dedicated URL, enabling multiple Teams organisations to connect independently.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row">
						<label for="teams_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$ms_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$ms_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="teams_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $ms_assistants as $ms_assistant ) : ?>
								<option value="<?php echo esc_attr( $ms_assistant->ID ); ?>"<?php selected( in_array( $ms_assistant->ID, $ms_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $ms_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages sent to this Teams bot.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Require Mention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="teams_require_mention" id="teams_require_mention" value="1" <?php checked( $is_edit && ! empty( $connection['require_mention'] ) && 'microsoft_teams' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Only reply when the assistant is @mentioned', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the bot only auto-replies to messages that explicitly @mention one of its assigned assistants. Useful for shared Teams channels where the bot should stay quiet unless addressed directly.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="teams_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Graph Token', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="teams_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Verifies the Microsoft Graph Access Token by calling the Graph API. Requires a valid Graph token to be saved.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="teams_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 2; min-width: 250px;">
								<textarea id="teams_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="teams_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="teams_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="teams_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Declarative Agent Manifest', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="teams_generate_manifest_btn" class="button button-secondary">
							<?php esc_html_e( 'Generate Manifest', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="teams_manifest_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to Microsoft 365 Copilot declarative agent documentation */
								esc_html__( 'Generates a Microsoft 365 Copilot %s JSON file pre-filled with this connection\'s settings. Save the connection before generating. Download the file and upload it to the Microsoft Teams Developer Portal to create a declarative agent.', 'mcp-ai-wpoos-pro' ),
								'<a href="https://learn.microsoft.com/en-us/microsoft-365-copilot/extensibility/overview-declarative-agent" target="_blank" rel="noopener noreferrer">' . esc_html__( 'declarative agent manifest', 'mcp-ai-wpoos-pro' ) . '</a>'
							);
							?>
						</p>
						<div id="teams_manifest_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="microsoft_teams-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Teams App Package', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="teams_generate_app_package_btn" class="button button-secondary">
							<?php esc_html_e( 'Download App Package (.zip)', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="teams_app_package_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: link to Teams Developer Portal, 2: link to Teams app sideloading docs */
									__( 'Generates a complete <a href="%1$s" target="_blank" rel="noopener noreferrer">Teams Bot app package</a> (.zip) containing <code>manifest.json</code> and icons. Save the connection first, then upload the package via <strong>Teams Developer Portal → Apps → Import app</strong> or <a href="%2$s" target="_blank" rel="noopener noreferrer">sideload it directly into Teams</a>.', 'mcp-ai-wpoos-pro' ),
									'https://dev.teams.microsoft.com/apps',
									'https://learn.microsoft.com/en-us/microsoftteams/platform/concepts/deploy-and-publish/apps-upload'
								),
								array(
									'a'      => array(
										'href'   => true,
										'target' => true,
										'rel'    => true,
									),
									'strong' => array(),
									'code'   => array(),
								)
							);
							?>
						</p>
						<div id="teams_app_package_result" style="display: none; margin-top: 8px;"></div>
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
								<code>pages_messaging</code>
							</p>
						</div>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row">
						<label for="messenger_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$msng_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$msng_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'facebook_messenger' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="messenger_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $msng_assistants as $msng_assistant ) : ?>
								<option value="<?php echo esc_attr( $msng_assistant->ID ); ?>"<?php selected( in_array( $msng_assistant->ID, $msng_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $msng_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages received on this Facebook Page.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Require Mention', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="messenger_require_mention" id="messenger_require_mention" value="1" <?php checked( $is_edit && ! empty( $connection['require_mention'] ) && 'facebook_messenger' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ); ?>>
							<?php esc_html_e( 'Only reply when the assistant is @mentioned', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the bot only auto-replies to messages that explicitly @mention one of its assigned assistants. Useful when the Page also handles manual replies and the bot should only intervene when addressed directly.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="facebook_messenger-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="messenger_test_auto_reply_recipient_id" class="regular-text" placeholder="<?php esc_attr_e( 'PSID e.g. 123456789 (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Recipient PSID (optional — if provided, the AI reply will be sent via Messenger)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="messenger_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="messenger_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="messenger_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="messenger_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
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

				<!-- Type-specific fields for Google Chat -->

				<!-- Connection Method Selector -->
				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Connection Method', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						// Determine the previously saved method (for edit mode).
						$gc_saved_method = 'service_account'; // default.
						if ( $is_edit && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
							if ( ! empty( $connection['connection_method'] ) ) {
								// Use the explicitly saved method.
								$gc_saved_method = $connection['connection_method'];
							} elseif ( ! empty( $connection['reply_webhook_url'] ) && empty( $connection['api_key'] ) && empty( $connection['client_id'] ) ) {
								// Legacy fallback: infer from populated fields.
								$gc_saved_method = 'webhook';
							} elseif ( ! empty( $connection['client_id'] ) || ! empty( $connection['refresh_token'] ) ) {
								$gc_saved_method = 'oauth';
							}
						}
						?>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Connection Method', 'mcp-ai-wpoos-pro' ); ?></legend>
							<label style="display: inline-flex; align-items: center; margin-right: 20px; cursor: pointer;">
								<input type="radio" name="google_chat_method" id="gc_method_service_account" value="service_account" style="margin-right: 6px;" <?php checked( $gc_saved_method, 'service_account' ); ?>>
								<strong><?php esc_html_e( 'Service Account', 'mcp-ai-wpoos-pro' ); ?></strong>&nbsp;<span style="font-weight:normal; color:#555;"><?php esc_html_e( '(recommended — full bot API access)', 'mcp-ai-wpoos-pro' ); ?></span>
							</label>
							<label style="display: inline-flex; align-items: center; margin-right: 20px; cursor: pointer;">
								<input type="radio" name="google_chat_method" id="gc_method_oauth" value="oauth" style="margin-right: 6px;" <?php checked( $gc_saved_method, 'oauth' ); ?>>
								<strong><?php esc_html_e( 'OAuth 2.0', 'mcp-ai-wpoos-pro' ); ?></strong>&nbsp;<span style="font-weight:normal; color:#555;"><?php esc_html_e( '(1-click user authorization)', 'mcp-ai-wpoos-pro' ); ?></span>
							</label>
							<label style="display: inline-flex; align-items: center; cursor: pointer;">
								<input type="radio" name="google_chat_method" id="gc_method_webhook" value="webhook" style="margin-right: 6px;" <?php checked( $gc_saved_method, 'webhook' ); ?>>
								<strong><?php esc_html_e( 'Incoming Webhook', 'mcp-ai-wpoos-pro' ); ?></strong>&nbsp;<span style="font-weight:normal; color:#555;"><?php esc_html_e( '(simplest — outbound-only to one space)', 'mcp-ai-wpoos-pro' ); ?></span>
							</label>
						</fieldset>
						<p class="description" style="margin-top: 8px;">
							<?php esc_html_e( 'Choose how NV oOS authenticates with Google Chat. Service Account is best for bots that need to read and write across multiple spaces. OAuth 2.0 grants user-level access. Incoming Webhook is the simplest option when you only need to post outbound messages to a single space.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<!-- Service Account fields -->
				<tr class="google_chat-only-field gc-method-sa gc-method-oauth" style="display: none;">
					<th scope="row">
						<label for="google_chat_service_account_key"><?php esc_html_e( 'Service Account JSON Key', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="google_chat_service_account_key" id="google_chat_service_account_key" class="large-text" rows="6" autocomplete="off" placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN RSA PRIVATE KEY-----\n...","client_email":"...@....iam.gserviceaccount.com",...}'></textarea>
						<?php if ( $is_edit && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['api_key'] ) ) : ?>
							<p class="description">
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php esc_html_e( 'Service Account key is set. Leave blank to keep the existing key, or paste a new key to replace it.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						<?php elseif ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Paste the full contents of your Service Account JSON key file here to replace the stored key. Leave blank to keep the existing key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Paste the full contents of your Google Service Account JSON key file (downloaded from Google Cloud Console). The key must grant the Chat API scope (https://www.googleapis.com/auth/chat.bot). Access tokens are generated automatically and cached — you never need to refresh them manually.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"></th>
					<td colspan="2">
						<hr style="border-top: 1px solid #ddd; margin: 4px 0;">
						<p style="margin: 4px 0; font-size: 12px; color: #777; text-align: center;">
							<?php esc_html_e( '— or connect via OAuth 2.0 (recommended for 1-click setup) —', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<hr style="border-top: 1px solid #ddd; margin: 4px 0;">
						<?php if ( ! $is_edit ) : ?>
							<p style="margin: 4px 0; font-size: 12px; color: #777; text-align: center;">
								<em><?php esc_html_e( 'Save this connection first — the 1-click OAuth connect button will appear here once the connection is saved.', 'mcp-ai-wpoos-pro' ); ?></em>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_client_id"><?php esc_html_e( 'OAuth Client ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="google_chat_client_id" id="google_chat_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console. Required for 1-click OAuth connect.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_client_secret"><?php esc_html_e( 'OAuth Client Secret', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="password" name="google_chat_client_secret" id="google_chat_client_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['client_secret'] ) ) : ?>
							<p class="description">
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php esc_html_e( 'Client secret is set. Leave blank to keep existing secret, or enter a new one to replace it.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth 2.0 Client Secret from Google Cloud Console. Required for 1-click OAuth connect.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Authorized Redirect URI', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$google_chat_redirect_uri = add_query_arg(
							array(
								'page'          => 'wp-mcp-ai-remote-sites',
								'oauth_handler' => 'google_chat_oauth_callback',
							),
							admin_url( 'admin.php' )
						);
						?>
						<input type="text" readonly="readonly" value="<?php echo esc_url( $google_chat_redirect_uri ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
						<p class="description">
							<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
							<?php esc_html_e( 'Copy this exact URL and add it to the "Authorized redirect URIs" in your Google Cloud Console OAuth 2.0 credentials.', 'mcp-ai-wpoos-pro' ); ?>
							<br>
							<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Open Google Cloud Console', 'mcp-ai-wpoos-pro' ); ?> <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-top;"></span>
							</a>
						</p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_refresh_token"><?php esc_html_e( 'Refresh Token (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<textarea name="google_chat_refresh_token" id="google_chat_refresh_token" class="large-text" rows="3" autocomplete="off"></textarea>
						<?php if ( $is_edit && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['refresh_token'] ) ) : ?>
							<p class="description">
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php esc_html_e( 'Refresh token is set. Leave blank to keep existing token, or paste a new token to replace it.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth refresh token. Obtained automatically via the 1-click connect button below, or paste manually.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Google Chat OAuth 1-click connect button -->
				<?php if ( $is_edit && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
					<tr class="google_chat-only-field" style="display: none;">
						<th scope="row">
							<label><?php esc_html_e( '1-Click OAuth Connect', 'mcp-ai-wpoos-pro' ); ?></label>
						</th>
						<td>
							<?php
							$gc_has_credentials = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] );
							$gc_oauth_url       = $gc_has_credentials
								? wp_nonce_url(
									add_query_arg(
										array(
											'page' => 'wp-mcp-ai-remote-sites',
											'oauth_handler' => 'google_chat_oauth_connect',
											'connection_id' => $connection['id'],
										),
										admin_url( 'admin.php' )
									),
									'google_chat_oauth_connect_' . $connection['id']
								)
								: '#';
							?>
							<a href="<?php echo esc_url( $gc_oauth_url ); ?>" class="button button-secondary" <?php echo $gc_has_credentials ? '' : 'aria-disabled="true" style="opacity:0.5;cursor:not-allowed;" onclick="return false;"'; ?>>
								<span class="dashicons dashicons-google" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Connect to Google Chat', 'mcp-ai-wpoos-pro' ); ?>
							</a>
							<?php if ( ! $gc_has_credentials ) : ?>
								<p class="description" style="color: #d63638;">
									<span class="dashicons dashicons-warning"></span>
									<?php esc_html_e( 'Enter and save the OAuth Client ID and Client Secret above before using this button.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p class="description">
									<?php esc_html_e( 'Click to authorize this connection with your Google account and obtain an access token and refresh token automatically.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $connection['refresh_token'] ) ) : ?>
								<p class="description" style="color: #46b450;">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'This connection is already authorized via OAuth. Click the button above to re-authorize if needed.', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_audience"><?php esc_html_e( 'Audience URL (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="google_chat_audience" id="google_chat_audience" class="regular-text" value="<?php echo $is_edit && isset( $connection['verify_token'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['verify_token'] ) : ''; ?>" placeholder="<?php echo esc_attr( home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' ) ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'The audience URL used to validate the Google OIDC token sent by Google Chat. Set this to your webhook URL (shown below). Leave blank to skip audience verification (less secure).', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_disable_oidc_verification"><?php esc_html_e( 'Disable OIDC Verification', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="google_chat_disable_oidc_verification" id="google_chat_disable_oidc_verification" value="1" <?php checked( $is_edit && isset( $connection['disable_oidc_verification'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) && ! empty( $connection['disable_oidc_verification'] ) ); ?>>
							<?php esc_html_e( 'Accept incoming webhook events without validating the Google OIDC Bearer token.', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Enable this only if Google Chat messages are not being received and you suspect the Authorization header is being stripped by your server, a proxy, or a WAF (e.g., Cloudflare). This mirrors the behavior of the Telegram integration when no secret token is configured. Not recommended for production — enable the Audience URL above instead for secure verification.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Channel-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;">
								<?php esc_html_e( 'Use this URL in the Google Cloud Console as your bot endpoint. Each Google Chat connection has its own dedicated endpoint for independent routing.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all connections):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use the generic URL only if all connections share the same Google Cloud project. The channel-specific URL above is preferred.', 'mcp-ai-wpoos-pro' ); ?></p>
							<div style="background: #fff8e1; border-left: 4px solid #f9a825; padding: 10px 12px; margin-top: 12px;">
								<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;">
									<span class="dashicons dashicons-shield" style="color: #f9a825;"></span>
									<?php esc_html_e( 'Cloudflare / Proxy Fallback URL:', 'mcp-ai-wpoos-pro' ); ?>
								</p>
								<input type="text" readonly="readonly" value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'action' => 'wp_mcp_ai_google_chat_webhook',
											'connection_id' => $connection['id'],
										),
										admin_url( 'admin-ajax.php' )
									)
								);
								?>
																				" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 4px;">
								<p class="description"><?php esc_html_e( 'Use this URL instead if Cloudflare, a WAF, or another proxy is blocking the standard /wp-json/ endpoint above. This alternative route bypasses REST API restrictions while applying the same Google OIDC token security.', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL for use in the Google Cloud Console.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_space"><?php esc_html_e( 'Google Chat Space (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
							<input type="text" name="google_chat_space" id="google_chat_space" class="regular-text" value="<?php echo $is_edit && isset( $connection['google_chat_space'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['google_chat_space'] ) : ''; ?>" placeholder="spaces/AAAAxxxxxx" autocomplete="off">
							<button type="button" id="wp-mcp-ai-gc-fetch-spaces-btn" class="button button-secondary">
								<?php esc_html_e( 'Fetch Spaces', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</div>
						<div id="wp-mcp-ai-gc-fetch-spaces-result" style="margin-top:8px;"></div>
						<p class="description"><?php esc_html_e( 'Enter a Google Chat space resource name (e.g., spaces/AAAAxxxxxx) to route messages from that specific space to this connection\'s assistants. Leave blank to handle all spaces. Click \'Fetch Spaces\' to retrieve your bot\'s spaces automatically using the Access Token above.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_reply_webhook_url"><?php esc_html_e( 'Incoming Webhook URL (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="url" name="google_chat_reply_webhook_url" id="google_chat_reply_webhook_url" class="large-text" value="<?php echo $is_edit && isset( $connection['reply_webhook_url'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_url( $connection['reply_webhook_url'] ) : ''; ?>" placeholder="https://chat.googleapis.com/v1/spaces/AAAAxxxxxx/messages?key=…&amp;token=…" autocomplete="off">
						<p class="description">
							<?php esc_html_e( 'Paste the incoming webhook URL for the Google Chat space (created in Space Settings → Apps &amp; integrations → Manage webhooks). When provided, the AI assistant will use this URL to reply without requiring OAuth or Service Account credentials. This is the simplest setup option for bots responding in a single space.', 'mcp-ai-wpoos-pro' ); ?>
							<?php
							printf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								esc_url( 'https://developers.google.com/workspace/chat/quickstart/webhooks' ),
								esc_html__( 'Learn more about Google Chat incoming webhooks', 'mcp-ai-wpoos-pro' )
							);
							?>
						</p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row">
						<label for="google_chat_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$gc_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$gc_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'google_chat' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="google_chat_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $gc_assistants as $gc_assistant ) : ?>
								<option value="<?php echo esc_attr( $gc_assistant->ID ); ?>"<?php selected( in_array( $gc_assistant->ID, $gc_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $gc_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first selected assistant will automatically reply to messages sent to your Google Chat bot.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="google_chat_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Google Chat Connection', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="google_chat_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Paste your Service Account JSON key above, then click to verify your credentials with the Google Chat API.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="google_chat_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="google_chat_test_auto_reply_space" class="regular-text" placeholder="<?php esc_attr_e( 'spaces/AAAAxxxxxx (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Space name (optional — if provided, the AI reply will be sent to this Google Chat space)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="google_chat_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="google_chat_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="google_chat_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming message and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="google_chat_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Incoming Trigger', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="google_chat_test_incoming_space" class="regular-text" placeholder="<?php esc_attr_e( 'spaces/AAAAxxxxxx (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Space name (optional — leave blank to use the space configured on this connection)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="google_chat_test_incoming_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a simulated incoming message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="google_chat_test_incoming_trigger_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Incoming Trigger', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="google_chat_test_incoming_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Simulates the full incoming Google Chat message pipeline (webhook receipt → AI reply → optional send to space) without a real Google Chat message. Saves the connection first. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="google_chat_test_incoming_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Webhook Log', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
							<button type="button" id="google_chat_fetch_log_btn" class="button button-secondary">
								<?php esc_html_e( 'Fetch Recent Webhook Events', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<button type="button" id="google_chat_clear_log_btn" class="button button-link" style="color: #d63638;">
								<?php esc_html_e( 'Clear Log', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="google_chat_log_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Shows up to 25 of the most recent webhook requests received from Google Chat, including rejected requests. Use this to diagnose why messages are not reaching your site — a rejected entry with a reason explains the problem. If no entries appear after sending a message from Google Chat, the request is not reaching WordPress at all (check the webhook URL configured in Google Cloud Console, firewall, or Cloudflare settings).', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="google_chat_log_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="google_chat-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #1a73e8; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Option A: 1-Click OAuth Connect (recommended)', 'mcp-ai-wpoos-pro' ); ?></p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Open Google Cloud Console (console.cloud.google.com) and enable the Google Chat API for your project.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Go to APIs &amp; Services → Credentials and create an OAuth 2.0 Client ID (Web application type).', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Add the Authorized Redirect URI shown above to the allowed redirect URIs for that credential.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter the OAuth Client ID and Client Secret above, then save the connection.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Click "Connect to Google Chat" to authorize with your Google account and obtain a refresh token automatically.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'In the Google Chat API → Configuration, set the bot endpoint URL to the Webhook URL shown above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign one or more AI Assistants and enable the connection.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Option B: Service Account JSON Key', 'mcp-ai-wpoos-pro' ); ?></p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Create a service account under IAM & Admin → Service Accounts; grant it the Chat API scope (https://www.googleapis.com/auth/chat.bot).', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Download the JSON key file and paste its contents into the Service Account JSON Key field above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'In the Google Chat API → Configuration, set the bot endpoint URL to the Webhook URL shown above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the Webhook URL into the Audience URL field — Google uses this URL as the OIDC token audience to authenticate incoming requests.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Click \'Fetch Spaces\' to retrieve your bot\'s spaces, then assign Assistants, save, and enable the connection.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Option C: Incoming Webhook URL (simplest — single-space bots)', 'mcp-ai-wpoos-pro' ); ?></p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'In the Google Chat space, click the space name → Apps &amp; integrations → Manage webhooks → Add webhook.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Copy the generated webhook URL (starts with https://chat.googleapis.com/v1/spaces/…) and paste it into the Incoming Webhook URL field above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'In Google Cloud Console, configure the bot endpoint URL to the Webhook URL shown above (still required so Google Chat can send events to the assistant).', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign Assistants, save, and enable the connection. No OAuth credentials are needed for this option.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0; font-size: 13px; color: #2271b1;">
								ℹ <strong><?php esc_html_e( 'OAuth scopes:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<code>https://www.googleapis.com/auth/chat.messages</code>
								<code>https://www.googleapis.com/auth/chat.spaces.readonly</code>
								&nbsp;|&nbsp;
								<strong><?php esc_html_e( 'Service Account scope:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<code>https://www.googleapis.com/auth/chat.bot</code>
							</p>
						</div>
					</td>
				</tr>
				<!-- Type-specific fields for Twitter / X -->
				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_api_key"><?php esc_html_e( 'API Key (Consumer Key)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="twitter_api_key" id="twitter_api_key" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API Key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Twitter/X app API Key (Consumer Key) from the Developer Portal. Used for OAuth 1.0a request signing and webhook HMAC-SHA256 signature validation.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_api_secret"><?php esc_html_e( 'API Secret Key (Consumer Secret)', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="twitter_api_secret" id="twitter_api_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing API Secret Key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Your Twitter/X app API Secret Key (Consumer Secret) from the Developer Portal. This is used to sign OAuth 1.0a requests and to validate incoming webhook event signatures (HMAC-SHA256 in the X-Twitter-Webhooks-Signature header).', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_access_token"><?php esc_html_e( 'Access Token', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="twitter_access_token" id="twitter_access_token" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing Access Token.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth 1.0a Access Token for the app owner user. Used together with the Access Token Secret to send Direct Messages on behalf of the authenticated user. Generate in the Developer Portal under your app → Keys and Tokens.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_access_token_secret"><?php esc_html_e( 'Access Token Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="password" name="twitter_access_token_secret" id="twitter_access_token_secret" class="regular-text" value="" autocomplete="new-password">
						<?php if ( $is_edit ) : ?>
							<p class="description"><?php esc_html_e( 'Leave blank to keep existing Access Token Secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'OAuth 1.0a Access Token Secret paired with the Access Token above. Both are required to sign DM send requests.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_user_id"><?php esc_html_e( 'App Owner Twitter User ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="twitter_user_id" id="twitter_user_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['twitter_user_id'] ) && 'twitter' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['twitter_user_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Twitter/X numeric user ID (e.g. 123456789). When set, the webhook controller uses this to avoid replying to messages sent by the bot itself, preventing reply loops. Find it via twidentity.com or the v2 /users/by/username/:username endpoint.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Channel-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/twitter/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Register this URL in the Twitter Developer Portal under your app → Edit → App details → Website URL / Callback URLs, and in the Account Activity API environment settings.', 'mcp-ai-wpoos-pro' ); ?></p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all connections):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/twitter' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use the channel-specific URL above when possible so each Twitter app routes to its own connection.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/twitter' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL for use in the Twitter Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row">
						<label for="twitter_assigned_assistant_ids"><?php esc_html_e( 'Assigned Assistants', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$tw_assistants          = get_posts(
							array(
								'post_type'      => 'mcp_ai_assistant',
								'posts_per_page' => -1,
								'post_status'    => 'publish',
								'orderby'        => 'title',
								'order'          => 'ASC',
							)
						);
						$tw_saved_assistant_ids = $is_edit && isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] ) && 'twitter' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' )
							? array_map( 'absint', $connection['assigned_assistant_ids'] )
							: array();
						?>
						<select name="assigned_assistant_ids[]" id="twitter_assigned_assistant_ids" multiple="multiple" class="regular-text" size="5" style="min-height: 80px;">
							<?php foreach ( $tw_assistants as $tw_assistant ) : ?>
								<option value="<?php echo esc_attr( $tw_assistant->ID ); ?>"<?php selected( in_array( $tw_assistant->ID, $tw_saved_assistant_ids, true ) ); ?>>
									<?php echo esc_html( $tw_assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Hold Ctrl/Cmd to select multiple assistants. The first assigned assistant will automatically reply to incoming Twitter/X Direct Messages via the Account Activity API.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>

				<tr class="twitter-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #000000; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Go to developer.twitter.com and create a new app (or use an existing one) under a Project.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Under app → Settings → User authentication settings, enable OAuth 1.0a and set permissions to Read and Write and Direct Messages.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'From app → Keys and Tokens, generate your API Key, API Secret Key, Access Token, and Access Token Secret and enter them above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Apply for Account Activity API access (Free tier supports 1 environment). Create a dev environment in the Developer Portal.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection to get a channel-specific Webhook URL, then register it via the Account Activity API or using the "Manage Twitter Webhook" AI tool.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'After registration Twitter will send a CRC challenge (GET request with ?crc_token=…) to verify the URL — this is handled automatically.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Subscribe the user to account events using the "Manage Twitter Webhook" AI tool (action: subscribe) or via the Twitter API directly.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign AI Assistants above, then save and enable — incoming DMs will be answered automatically.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
							<p style="margin: 0; font-size: 13px; color: #2271b1;">
								ℹ <strong><?php esc_html_e( 'Signature validation:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php esc_html_e( 'Twitter signs each POST event with HMAC-SHA256 (base64-encoded) using your Consumer Secret in the X-Twitter-Webhooks-Signature header. The API Secret Key above enables automatic signature verification on every incoming event.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							</div>
					</td>
				</tr>

				<!-- Type-specific fields for Apple Messages for Business (iMessage) -->
				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row">
						<label for="apple_msp_api_url"><?php esc_html_e( 'MSP API URL', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="apple_msp_api_url" id="apple_msp_api_url" class="regular-text" value="<?php echo $is_edit && isset( $connection['url'] ) && 'apple_messages' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_url( $connection['url'] ) : ''; ?>" placeholder="https://api.your-msp.com/v1/apple/messages" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Base URL of your approved Messaging Service Provider (MSP) REST API endpoint. Each MSP (e.g. Infobip, Zendesk, CM.com) provides its own URL.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row">
						<label for="apple_msp_api_key"><?php esc_html_e( 'MSP API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['api_key'] ) && 'apple_messages' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'API key is saved. Leave blank to keep the existing key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="apple_msp_api_key" id="apple_msp_api_key" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'API key or bearer token issued by your MSP for authenticating Apple Messages for Business API requests.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row">
						<label for="apple_webhook_secret"><?php esc_html_e( 'Webhook Signing Secret (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['api_secret'] ) && 'apple_messages' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'Signing secret is saved. Leave blank to keep the existing secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="apple_webhook_secret" id="apple_webhook_secret" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'HMAC-SHA256 signing secret provided by your MSP for validating incoming webhook events. Leave blank to skip signature verification.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row">
						<label for="apple_business_id"><?php esc_html_e( 'Apple Business ID (Optional)', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="apple_business_id" id="apple_business_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['business_id'] ) && 'apple_messages' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['business_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Apple Messages for Business identifier issued during Apple Business Registration. Required when sending outbound messages via the AI tools.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<p style="margin: 0 0 6px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Channel-specific URL (recommended):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/apple-messages/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0; margin-bottom: 6px;">
							<p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Register this URL with your MSP as the webhook endpoint to receive Apple Messages for Business events.', 'mcp-ai-wpoos-pro' ); ?></p>
							<p style="margin: 0 0 4px 0; font-weight: 600; font-size: 13px;"><?php esc_html_e( 'Generic URL (all connections):', 'mcp-ai-wpoos-pro' ); ?></p>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/apple-messages' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Use the channel-specific URL above when possible so each MSP configuration routes to its own connection.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/apple-messages' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL for your MSP configuration.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="apple_messages-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #555555; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Register your business at register.apple.com/messages to obtain an Apple Business ID.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Choose an approved Messaging Service Provider (MSP) such as Infobip, Zendesk, CM.com, or LivePerson and obtain your MSP API credentials.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter the MSP API URL, API Key, and optional Webhook Signing Secret above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection to generate a channel-specific Webhook URL, then register it with your MSP.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign AI Assistants to this connection so incoming iMessages are answered automatically.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>
					</td>
				</tr>

				<!-- Type-specific fields for Office 365 (Outlook / OneDrive) -->
				<tr class="office365-only-field" style="display: none;">
					<th scope="row">
						<label for="office365_client_id"><?php esc_html_e( 'Application (Client) ID', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" name="office365_client_id" id="office365_client_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['client_id'] ) && 'office365' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['client_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Azure AD application (client) ID. Can be the same as Microsoft Teams.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row">
						<label for="office365_client_secret"><?php esc_html_e( 'Client Secret', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['client_secret'] ) && 'office365' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'Client secret is saved. Leave blank to keep the existing secret.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="office365_client_secret" id="office365_client_secret" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Your Azure AD client secret for authenticating Microsoft Graph API requests.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row">
						<label for="office365_tenant_id"><?php esc_html_e( 'Tenant ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="office365_tenant_id" id="office365_tenant_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['tenant_id'] ) && 'office365' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_attr( $connection['tenant_id'] ) : ''; ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your Azure AD tenant ID.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Enabled Services', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$o365_saved_services = $is_edit && isset( $connection['enabled_services'] ) && is_array( $connection['enabled_services'] ) ? $connection['enabled_services'] : array( 'outlook_mail', 'onedrive' );
						$o365_services       = array(
							'outlook_mail' => __( 'Outlook Mail — send and read emails (Mail.ReadWrite, Mail.Send)', 'mcp-ai-wpoos-pro' ),
							'onedrive'     => __( 'OneDrive — list, read, and upload files (Files.ReadWrite.All)', 'mcp-ai-wpoos-pro' ),
						);
						?>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Enabled Services', 'mcp-ai-wpoos-pro' ); ?></span></legend>
							<?php foreach ( $o365_services as $service_key => $service_label ) : ?>
								<label style="display: block; margin-bottom: 6px;">
									<input type="checkbox" name="office365_enabled_services[]" value="<?php echo esc_attr( $service_key ); ?>" <?php checked( in_array( $service_key, $o365_saved_services, true ) ); ?>>
									<?php echo esc_html( $service_label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Select which Office 365 services to enable for this connection. Only the selected services will have their AI tools activated. Each service requires the listed Microsoft Graph API permissions.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- Outlook Mail service settings -->
				<tr class="office365-only-field office365-service-outlook_mail" style="display: none;">
					<th scope="row">
						<label for="outlook_mailbox_folder"><?php esc_html_e( 'Outlook — Mailbox Folder', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="outlook_mailbox_folder" id="outlook_mailbox_folder" class="regular-text" value="<?php echo $is_edit && isset( $connection['outlook_mailbox_folder'] ) ? esc_attr( $connection['outlook_mailbox_folder'] ) : ''; ?>" placeholder="inbox" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Default mailbox folder for reading messages (e.g. inbox, sentitems, drafts). Leave blank to default to inbox.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- OneDrive service settings -->
				<tr class="office365-only-field office365-service-onedrive" style="display: none;">
					<th scope="row">
						<label for="onedrive_folder_path"><?php esc_html_e( 'OneDrive — Default Folder Path', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="onedrive_folder_path" id="onedrive_folder_path" class="regular-text" value="<?php echo $is_edit && isset( $connection['onedrive_folder_path'] ) ? esc_attr( $connection['onedrive_folder_path'] ) : ''; ?>" placeholder="Documents/Projects" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Default folder path for file operations (e.g. Documents/Projects). Leave blank for the root of OneDrive.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/outlook/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Register this URL with Microsoft Graph subscriptions to receive new mail notifications.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/outlook' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #d83b01; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Register an application in the Azure Portal (or reuse the one from Microsoft Teams).', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Add Microsoft Graph API permissions for each enabled service above and grant admin consent.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter your Application (Client) ID, Client Secret, and Tenant ID above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection, then configure a Microsoft Graph subscription with the webhook URL above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign AI Assistants to auto-reply to incoming Outlook emails.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="office365_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test Office 365 Connection', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="office365_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Calls the Microsoft Graph API to verify your credentials and retrieve the current user profile. Works before saving if credentials are entered above.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="office365_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="office365-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 1; min-width: 200px;">
								<input type="text" id="office365_test_auto_reply_recipient" class="regular-text" placeholder="<?php esc_attr_e( 'user@example.com (optional)', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;">
								<p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Recipient email (optional — if provided, the AI reply will be sent via Outlook)', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div style="flex: 2; min-width: 250px;">
								<textarea id="office365_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="office365_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="office365_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming email and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="office365_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<!-- Type-specific fields for iCloud Drive -->
				<tr class="icloud-only-field" style="display: none;">
					<th scope="row">
						<label for="icloud_gateway_url"><?php esc_html_e( 'Gateway API URL', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="url" name="icloud_gateway_url" id="icloud_gateway_url" class="regular-text" value="<?php echo $is_edit && isset( $connection['gateway_api_url'] ) && 'icloud' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ? esc_url( $connection['gateway_api_url'] ) : ''; ?>" placeholder="https://gateway.example.com/api/icloud" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Base URL of your iCloud Drive gateway/proxy service endpoint.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row">
						<label for="icloud_api_key"><?php esc_html_e( 'Gateway API Key', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['api_key'] ) && 'icloud' === ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) : ?>
							<p class="description" style="margin-bottom: 6px;"><?php esc_html_e( 'API key is saved. Leave blank to keep the existing key.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
						<input type="password" name="icloud_api_key" id="icloud_api_key" class="regular-text" value="" autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'API key for authenticating with the iCloud Drive gateway service.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Enabled Services', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php
						$icloud_saved_services = $is_edit && isset( $connection['enabled_services'] ) && is_array( $connection['enabled_services'] ) ? $connection['enabled_services'] : array( 'icloud_drive' );
						$icloud_services       = array(
							'icloud_drive' => __( 'iCloud Drive — list, read, and upload files', 'mcp-ai-wpoos-pro' ),
						);
						?>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Enabled Services', 'mcp-ai-wpoos-pro' ); ?></span></legend>
							<?php foreach ( $icloud_services as $service_key => $service_label ) : ?>
								<label style="display: block; margin-bottom: 6px;">
									<input type="checkbox" name="icloud_enabled_services[]" value="<?php echo esc_attr( $service_key ); ?>" <?php checked( in_array( $service_key, $icloud_saved_services, true ) ); ?>>
									<?php echo esc_html( $service_label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Select which iCloud services to enable for this connection. Only the selected services will have their AI tools activated.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<!-- iCloud Drive service settings -->
				<tr class="icloud-only-field icloud-service-icloud_drive" style="display: none;">
					<th scope="row">
						<label for="icloud_default_folder_id"><?php esc_html_e( 'iCloud Drive — Default Folder ID', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<input type="text" name="icloud_default_folder_id" id="icloud_default_folder_id" class="regular-text" value="<?php echo $is_edit && isset( $connection['icloud_default_folder_id'] ) ? esc_attr( $connection['icloud_default_folder_id'] ) : ''; ?>" placeholder="" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Default folder ID for file operations. Leave blank to list files from the root of iCloud Drive.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row">
						<label><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos-pro' ); ?></label>
					</th>
					<td>
						<?php if ( $is_edit && ! empty( $connection['id'] ) ) : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/icloud/' . $connection['id'] ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Register this URL with your iCloud gateway to receive file change notifications.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php else : ?>
							<input type="text" readonly="readonly" value="<?php echo esc_url( home_url( '/wp-json/mcp-ai/v1/webhooks/icloud' ) ); ?>" class="large-text code" onclick="this.select();" style="background-color: #f0f0f0;">
							<p class="description"><?php esc_html_e( 'Save this connection first to get a channel-specific webhook URL.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row"></th>
					<td>
						<div style="background: #f0f6fc; border-left: 4px solid #3693f5; padding: 12px; margin-top: 10px;">
							<p style="margin: 0 0 8px 0; font-weight: 600;">
								<?php esc_html_e( 'Quick Setup Guide', 'mcp-ai-wpoos-pro' ); ?>
							</p>
							<ol style="margin: 0 0 8px 20px; font-size: 13px;">
								<li><?php esc_html_e( 'Deploy or configure your iCloud Drive gateway/proxy service.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Enter the Gateway API URL and API Key above.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Save this connection to get a channel-specific webhook URL for file change notifications.', 'mcp-ai-wpoos-pro' ); ?></li>
								<li><?php esc_html_e( 'Assign AI Assistants to auto-reply on file events.', 'mcp-ai-wpoos-pro' ); ?></li>
							</ol>
						</div>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" id="icloud_test_connection_btn" class="button button-secondary">
							<?php esc_html_e( 'Test iCloud Connection', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span id="icloud_test_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Calls the iCloud gateway API to verify your credentials and connectivity. Works before saving if credentials are entered above.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="icloud_test_result" style="display: none; margin-top: 8px;"></div>
					</td>
				</tr>

				<tr class="icloud-only-field" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start;">
							<div style="flex: 2; min-width: 250px;">
								<textarea id="icloud_test_auto_reply_msg" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Enter a test message…', 'mcp-ai-wpoos-pro' ); ?>" style="width: 100%;"></textarea>
							</div>
						</div>
						<div style="margin-top: 8px;">
							<button type="button" id="icloud_test_auto_reply_btn" class="button button-secondary">
								<?php esc_html_e( 'Test Auto-Reply', 'mcp-ai-wpoos-pro' ); ?>
							</button>
							<span id="icloud_test_auto_reply_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Save the connection first, then use this to simulate an incoming file event and see the AI-generated reply. Requires at least one Assigned Assistant.', 'mcp-ai-wpoos-pro' ); ?></p>
						<div id="icloud_test_auto_reply_result" style="display: none; margin-top: 8px;"></div>
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

				<tr class="wordpress-only-field">
					<th scope="row"><?php esc_html_e( 'Post Type Access Controls', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$pt_access_enabled = $is_edit && ! empty( $connection['post_type_access'] );
						$pt_access         = $is_edit && isset( $connection['post_type_access'] ) ? $connection['post_type_access'] : array();
						$custom_pt_raw     = $is_edit && isset( $connection['custom_post_types'] ) ? $connection['custom_post_types'] : '';
						?>
						<label style="display:block; margin-bottom:8px;">
							<input type="checkbox" name="enable_pt_access_controls" id="enable_pt_access_controls" value="1"
								<?php checked( $pt_access_enabled ); ?>
								onchange="document.getElementById('pt_access_controls_section').style.display=this.checked?'block':'none';">
							<strong><?php esc_html_e( 'Restrict post type access (leave unchecked to allow all post types with read access)', 'mcp-ai-wpoos-pro' ); ?></strong>
						</label>
						<div id="pt_access_controls_section" style="<?php echo $pt_access_enabled ? '' : 'display:none;'; ?> margin-left:24px;">
							<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which post types can be accessed and which CRUD operations are permitted. Unchecked post types will be blocked.', 'mcp-ai-wpoos-pro' ); ?></p>
							<table class="widefat striped" style="max-width:560px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Post Type', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Read', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Create', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Update', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$built_in_pt = array(
										'post'       => __( 'Posts', 'mcp-ai-wpoos-pro' ),
										'page'       => __( 'Pages', 'mcp-ai-wpoos-pro' ),
										'attachment' => __( 'Media / Attachments', 'mcp-ai-wpoos-pro' ),
									);

									// Auto-discover public custom post types registered on this site.
									$discovered_pts = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
									foreach ( $discovered_pts as $slug => $obj ) {
										if ( ! isset( $built_in_pt[ $slug ] ) ) {
											/* translators: %1$s: post type singular label, %2$s: post type slug */
											$built_in_pt[ $slug ] = sprintf( __( '%1$s (%2$s)', 'mcp-ai-wpoos-pro' ), $obj->labels->singular_name, $slug );
										}
									}

									// Add saved custom post types (for post types not auto-discovered, e.g. non-public or remote-only).
									if ( ! empty( $custom_pt_raw ) ) {
										foreach ( explode( ',', $custom_pt_raw ) as $cpt_slug ) {
											$cpt_slug = sanitize_key( trim( $cpt_slug ) );
											if ( ! empty( $cpt_slug ) && ! isset( $built_in_pt[ $cpt_slug ] ) ) {
												$built_in_pt[ $cpt_slug ] = $cpt_slug;
											}
										}
									}

									foreach ( $built_in_pt as $pt_slug => $pt_label ) :
										$pt_ops = isset( $pt_access[ $pt_slug ] ) ? (array) $pt_access[ $pt_slug ] : array();
										?>
									<tr>
										<td><strong><?php echo esc_html( $pt_label ); ?></strong> <code><?php echo esc_html( $pt_slug ); ?></code></td>
										<td><input type="checkbox" name="pt_<?php echo esc_attr( $pt_slug ); ?>_read" value="1" <?php checked( in_array( 'read', $pt_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="pt_<?php echo esc_attr( $pt_slug ); ?>_create" value="1" <?php checked( in_array( 'create', $pt_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="pt_<?php echo esc_attr( $pt_slug ); ?>_update" value="1" <?php checked( in_array( 'update', $pt_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="pt_<?php echo esc_attr( $pt_slug ); ?>_delete" value="1" <?php checked( in_array( 'delete', $pt_ops, true ) ); ?>></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description" style="margin-top:8px;">
								<label for="custom_post_types"><strong><?php esc_html_e( 'Additional custom post types (comma-separated slugs):', 'mcp-ai-wpoos-pro' ); ?></strong></label><br>
								<input type="text" name="custom_post_types" id="custom_post_types" class="regular-text"
									value="<?php echo esc_attr( $custom_pt_raw ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. product,event,team', 'mcp-ai-wpoos-pro' ); ?>">
								<span class="description"><?php esc_html_e( 'Public custom post types are auto-discovered. Use this field to add non-public or remote-only post types. Save and re-open the connection to see new entries in the table above.', 'mcp-ai-wpoos-pro' ); ?></span>
							</p>
						</div>
					</td>
				</tr>

				<tr class="wordpress-only-field">
					<th scope="row"><?php esc_html_e( 'WooCommerce Resource Controls', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$wc_access_enabled = $is_edit && ! empty( $connection['wc_resource_access'] );
						$wc_access         = $is_edit && isset( $connection['wc_resource_access'] ) ? $connection['wc_resource_access'] : array();
						?>
						<label style="display:block; margin-bottom:8px;">
							<input type="checkbox" name="enable_wc_access_controls" id="enable_wc_access_controls" value="1"
								<?php checked( $wc_access_enabled ); ?>
								onchange="document.getElementById('wc_access_controls_section').style.display=this.checked?'block':'none';">
							<strong><?php esc_html_e( 'Restrict WooCommerce resource access (leave unchecked to allow all resources with read access)', 'mcp-ai-wpoos-pro' ); ?></strong>
						</label>
						<div id="wc_access_controls_section" style="<?php echo $wc_access_enabled ? '' : 'display:none;'; ?> margin-left:24px;">
							<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which WooCommerce resources can be accessed and which CRUD operations are permitted. This requires WooCommerce to be enabled above.', 'mcp-ai-wpoos-pro' ); ?></p>
							<table class="widefat striped" style="max-width:560px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Resource', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Read', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Create', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Update', 'mcp-ai-wpoos-pro' ); ?></th>
										<th><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$wc_resources = array(
										'products'   => __( 'Products', 'mcp-ai-wpoos-pro' ),
										'orders'     => __( 'Orders', 'mcp-ai-wpoos-pro' ),
										'customers'  => __( 'Customers', 'mcp-ai-wpoos-pro' ),
										'categories' => __( 'Product Categories', 'mcp-ai-wpoos-pro' ),
									);

									foreach ( $wc_resources as $res_slug => $res_label ) :
										$res_ops = isset( $wc_access[ $res_slug ] ) ? (array) $wc_access[ $res_slug ] : array();
										?>
									<tr>
										<td><strong><?php echo esc_html( $res_label ); ?></strong></td>
										<td><input type="checkbox" name="wc_<?php echo esc_attr( $res_slug ); ?>_read" value="1" <?php checked( in_array( 'read', $res_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="wc_<?php echo esc_attr( $res_slug ); ?>_create" value="1" <?php checked( in_array( 'create', $res_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="wc_<?php echo esc_attr( $res_slug ); ?>_update" value="1" <?php checked( in_array( 'update', $res_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="wc_<?php echo esc_attr( $res_slug ); ?>_delete" value="1" <?php checked( in_array( 'delete', $res_ops, true ) ); ?>></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</td>
				</tr>

			<tr class="wordpress-only-field">
				<th scope="row"><?php esc_html_e( 'JetEngine CCT Access Controls', 'mcp-ai-wpoos-pro' ); ?></th>
				<td>
					<?php
					$je_access_enabled = $is_edit && ! empty( $connection['jetengine_cct_access'] );
					$je_access         = $is_edit && isset( $connection['jetengine_cct_access'] ) ? $connection['jetengine_cct_access'] : array();
					?>
					<label style="display:block; margin-bottom:8px;">
						<input type="checkbox" name="enable_je_access_controls" id="enable_je_access_controls" value="1"
							<?php checked( $je_access_enabled ); ?>
							onchange="document.getElementById('je_access_controls_section').style.display=this.checked?'block':'none';">
						<strong><?php esc_html_e( 'Restrict JetEngine CCT access (leave unchecked to auto-discover and allow all CCTs with read access)', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label>
					<div id="je_access_controls_section" style="<?php echo $je_access_enabled ? '' : 'display:none;'; ?> margin-left:24px;">
						<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Select which JetEngine CCTs can be accessed and which CRUD operations are permitted. Use the Discover button to fetch available CCTs from the remote site after saving the connection.', 'mcp-ai-wpoos-pro' ); ?></p>
						<?php if ( $is_edit && $editing ) : ?>
							<p>
								<button type="button" id="je_discover_ccts_btn" class="button"
									data-connection-id="<?php echo esc_attr( $editing ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'discover_jetengine_ccts' ) ); ?>">
									<?php esc_html_e( 'Discover CCTs from Remote', 'mcp-ai-wpoos-pro' ); ?>
								</button>
								<span id="je_discover_spinner" class="spinner" style="float: none; vertical-align: middle; display: none;"></span>
							</p>
							<div id="je_discover_result" style="display: none; margin: 10px 0;"></div>
						<?php endif; ?>
						<table class="widefat striped" style="max-width:560px;" id="je_access_table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'CCT', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Read', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Create', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Update', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Delete', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="je_access_tbody">
								<?php foreach ( $je_access as $cct_slug => $cct_ops ) : ?>
									<?php $cct_ops = (array) $cct_ops; ?>
									<tr>
										<td><strong><?php echo esc_html( $cct_slug ); ?></strong></td>
										<td><input type="checkbox" name="je_<?php echo esc_attr( $cct_slug ); ?>_read" value="1" <?php checked( in_array( 'read', $cct_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="je_<?php echo esc_attr( $cct_slug ); ?>_create" value="1" <?php checked( in_array( 'create', $cct_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="je_<?php echo esc_attr( $cct_slug ); ?>_update" value="1" <?php checked( in_array( 'update', $cct_ops, true ) ); ?>></td>
										<td><input type="checkbox" name="je_<?php echo esc_attr( $cct_slug ); ?>_delete" value="1" <?php checked( in_array( 'delete', $cct_ops, true ) ); ?>></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
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
		var wpMcpAiAjax = typeof ajaxurl !== 'undefined' ? ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
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
			var quickbooksDesktopFields = document.querySelectorAll('.quickbooks_desktop-only-field');
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
			var googleChatFields = document.querySelectorAll('.google_chat-only-field');
			var twitterFields = document.querySelectorAll('.twitter-only-field');
			var appleMessagesFields = document.querySelectorAll('.apple_messages-only-field');
			var office365Fields = document.querySelectorAll('.office365-only-field');
			var icloudFields = document.querySelectorAll('.icloud-only-field');
			var shopifyFields = document.querySelectorAll('.shopify-only-field');
			var printfulFields = document.querySelectorAll('.printful-only-field');
			var shipengineFields = document.querySelectorAll('.shipengine-only-field');
			var shipstationFields = document.querySelectorAll('.shipstation-only-field');
			var upworkFields = document.querySelectorAll('.upwork-only-field');
			var linkedinFields = document.querySelectorAll('.linkedin-only-field');
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
			quickbooksDesktopFields.forEach(function(field) {
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
			googleChatFields.forEach(function(field) {
				field.style.display = 'none';
			});
			twitterFields.forEach(function(field) {
				field.style.display = 'none';
			});
			appleMessagesFields.forEach(function(field) {
				field.style.display = 'none';
			});
			office365Fields.forEach(function(field) {
				field.style.display = 'none';
			});
			icloudFields.forEach(function(field) {
				field.style.display = 'none';
			});
			shopifyFields.forEach(function(field) {
				field.style.display = 'none';
			});
			printfulFields.forEach(function(field) {
				field.style.display = 'none';
			});
			shipengineFields.forEach(function(field) {
				field.style.display = 'none';
			});
			shipstationFields.forEach(function(field) {
				field.style.display = 'none';
			});
			upworkFields.forEach(function(field) {
				field.style.display = 'none';
			});
			linkedinFields.forEach(function(field) {
				field.style.display = 'none';
			});

			// Reset URL field defaults
			urlField.readOnly = false;
			urlField.style.backgroundColor = '';
			urlDescription.style.display = 'block';
			urlDescriptionFlowhub.style.display = 'none';

			// Show/hide auth_type field based on connection type
				// Only show for WordPress and Generic API connections.
					// The select element sends lowercase values;
					// toLowerCase() normalises both old and new casing.
				var ctLower = (connectionType || '').toLowerCase();
				
				if (ctLower === <?php echo wp_json_encode( strtolower( 'WordPress' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText ?> || ctLower === 'generic') {
					authTypeRow.style.display = 'table-row';
				// Sync the credential fields (username/password/token/consumer keys)
				// with the current auth_type dropdown value so the correct fields
				// are visible — previously they could stay hidden in an inconsistent
				// state left over from a previously selected connection type.
				if (typeof toggleAuthFields === 'function' && authTypeSelect) {
					toggleAuthFields(authTypeSelect.value);
				}
			} else {
				authTypeRow.style.display = 'none';
				// Hide all auth credential fields when switching away from WordPress/Generic.
				if (typeof toggleAuthFields === 'function') {
					toggleAuthFields('none');
				}
			}

			// Show fields for selected connection type
				if (ctLower === <?php echo wp_json_encode( strtolower( 'WordPress' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText ?>) {
					wordpressFields.forEach(function(field) {
						field.style.display = 'table-row';
					});
				} else if (ctLower === 'generic') {
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
			} else if (connectionType === 'quickbooks_desktop') {
				quickbooksDesktopFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// QuickBooks Desktop uses custom_header auth (Bearer token to relay).
				authTypeSelect.value = 'custom_header';
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
			} else if (connectionType === 'upwork') {
				upworkFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Upwork uses OAuth, set URL to Upwork GraphQL API
				urlField.value = 'https://api.upwork.com/graphql';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				// Upwork doesn't use the standard auth_type, it has its own OAuth flow
				authTypeSelect.value = 'none';
				// Apply the saved mode selection.
				var upworkModeSelect = document.getElementById('upwork_mode');
				if (upworkModeSelect) { toggleUpworkMode(upworkModeSelect.value); }
			} else if (connectionType === 'linkedin') {
				linkedinFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// LinkedIn uses OAuth, set URL to LinkedIn API
				urlField.value = 'https://api.linkedin.com/rest';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				// LinkedIn doesn't use the standard auth_type, it has its own OAuth flow
				authTypeSelect.value = 'none';
				// Apply the saved mode selection.
				var linkedinModeSelect = document.getElementById('linkedin_mode');
				if (linkedinModeSelect) { toggleLinkedinMode(linkedinModeSelect.value); }
			} else if (connectionType === 'telegram') {
				telegramFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Re-apply template-based data source row visibility so only the
				// relevant row (WooCommerce or Shopify) is shown, not both.
				var _tplSel = document.getElementById( 'telegram_mini_app_template' );
				if ( _tplSel ) {
					var _val          = _tplSel.value;
					var _wooRow       = document.querySelector( '.tma-woo-source-row' );
					var _shopifyRow   = document.querySelector( '.tma-shopify-source-row' );
					var _flowhubRow   = document.querySelector( '.tma-flowhub-source-row' );
					var _wooTpls      = [ 'woo_shop', 'ecommerce' ];
					var _shopifyTpls  = [ 'shopify_shop', 'jewelry_shop', 'shopify_ecommerce' ];
					var _flowhubTpls  = [ 'flowhub_ecommerce' ];
					if ( _wooRow )     { _wooRow.style.display     = _wooTpls.indexOf( _val ) !== -1     ? 'table-row' : 'none'; }
					if ( _shopifyRow ) { _shopifyRow.style.display = _shopifyTpls.indexOf( _val ) !== -1 ? 'table-row' : 'none'; }
					if ( _flowhubRow ) { _flowhubRow.style.display = _flowhubTpls.indexOf( _val ) !== -1 ? 'table-row' : 'none'; }
				}
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
			} else if (connectionType === 'google_chat') {
				googleChatFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Google Chat uses the Chat API
				urlField.value = 'https://chat.googleapis.com/v1';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'twitter') {
				twitterFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Twitter/X uses API v2
				urlField.value = 'https://api.twitter.com/2';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'apple_messages') {
				appleMessagesFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Apple Messages for Business — MSP API URL is entered by the user
				urlField.readOnly = false;
				urlField.style.backgroundColor = '';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'office365') {
				office365Fields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Office 365 uses Microsoft Graph API
				urlField.value = 'https://graph.microsoft.com/v1.0';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
				// Show/hide per-service settings based on checkbox state
				toggleServiceSettings('office365');
			} else if (connectionType === 'icloud') {
				icloudFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// iCloud Drive — gateway API URL is entered by the user
				urlField.readOnly = false;
				urlField.style.backgroundColor = '';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
				// Show/hide per-service settings based on checkbox state
				toggleServiceSettings('icloud');
			} else if (connectionType === 'shopify') {
				shopifyFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// URL is derived from shop domain (Admin API) or fixed (Catalog API) — hide the generic URL input.
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'custom_header';
				// Apply the current API mode sub-field state.
				var shopifyModeSelect = document.getElementById('shopify_api_mode');
				var currentMode = shopifyModeSelect ? shopifyModeSelect.value : 'admin_api';
				toggleShopifyApiMode(currentMode);
			} else if (connectionType === 'printful') {
				printfulFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				// Printful uses a fixed API URL and Bearer token auth.
				urlField.value = 'https://api.printful.com';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'none';
			} else if (connectionType === 'shipengine') {
				shipengineFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				urlField.value = 'https://api.shipengine.com';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'custom_header';
			} else if (connectionType === 'shipstation') {
				shipstationFields.forEach(function(field) {
					field.style.display = 'table-row';
				});
				urlField.value = 'https://ssapi.shipstation.com';
				urlField.readOnly = true;
				urlField.style.backgroundColor = '#f0f0f0';
				urlDescription.style.display = 'none';
				authTypeSelect.value = 'basic_auth';
			}
		}

		/**
		 * Show/hide Shopify Admin API vs Catalog API sub-fields based on the selected mode.
		 *
		 * @param {string} mode 'admin_api' or 'catalog_api'
		 */
		function toggleShopifyApiMode(mode) {
			var adminFields   = document.querySelectorAll('.shopify-admin-api-field');
			var catalogFields = document.querySelectorAll('.shopify-catalog-api-field');
			var adminGuide    = document.querySelector('.shopify-admin-api-guide');
			var catalogGuide  = document.querySelector('.shopify-catalog-api-guide');
			var urlField      = document.getElementById('url');

			if (mode === 'catalog_api') {
				adminFields.forEach(function(f) { f.style.display = 'none'; });
				catalogFields.forEach(function(f) { f.style.display = 'table-row'; });
				if (adminGuide)   { adminGuide.style.display   = 'none'; }
				if (catalogGuide) { catalogGuide.style.display = 'block'; }
				// Catalog API always uses a fixed URL.
				if (urlField) { urlField.value = 'https://discover.shopifyapps.com'; }
			} else {
				adminFields.forEach(function(f) { f.style.display = 'table-row'; });
				catalogFields.forEach(function(f) { f.style.display = 'none'; });
				if (adminGuide)   { adminGuide.style.display   = 'block'; }
				if (catalogGuide) { catalogGuide.style.display = 'none'; }
				// Admin API URL is derived from shop domain.
				var shopDomainField = document.getElementById('shopify_shop_domain');
				if (urlField && shopDomainField) {
					var domain = shopDomainField.value.replace(/^https?:\/\//i, '').replace(/\/$/, '');
					if (domain && domain.indexOf('.') === -1) {
						domain += '.myshopify.com';
					}
					urlField.value = domain ? 'https://' + domain : '';
				}
			}
		}

		/**
		 * Show/hide Upwork API vs Web Search sub-fields based on the selected mode.
		 *
		 * @param {string} mode 'api' or 'web_search'
		 */
		function toggleUpworkMode(mode) {
			var apiFields   = document.querySelectorAll('.upwork-api-field');
			var searchFields = document.querySelectorAll('.upwork-web-search-field');

			if (mode === 'web_search') {
				apiFields.forEach(function(f) { f.style.display = 'none'; });
				searchFields.forEach(function(f) { f.style.display = 'table-row'; });
			} else {
				apiFields.forEach(function(f) { f.style.display = 'table-row'; });
				searchFields.forEach(function(f) { f.style.display = 'none'; });
			}
		}

		/**
		 * Show/hide LinkedIn API vs Web Search sub-fields based on the selected mode.
		 *
		 * @param {string} mode 'api' or 'web_search'
		 */
		function toggleLinkedinMode(mode) {
			var apiFields   = document.querySelectorAll('.linkedin-api-field');
			var searchFields = document.querySelectorAll('.linkedin-web-search-field');

			if (mode === 'web_search') {
				apiFields.forEach(function(f) { f.style.display = 'none'; });
				searchFields.forEach(function(f) { f.style.display = 'table-row'; });
			} else {
				apiFields.forEach(function(f) { f.style.display = 'table-row'; });
				searchFields.forEach(function(f) { f.style.display = 'none'; });
			}
		}

		/**
		 * Show/hide per-service settings rows based on service checkbox state.
		 *
		 * Rows carry a CSS class like "office365-service-outlook_mail" or "icloud-service-icloud_drive".
		 * When the corresponding service checkbox is checked the row is visible; otherwise hidden.
		 */
		function toggleServiceSettings(platform) {
			var checkboxes = document.querySelectorAll('input[name="' + platform + '_enabled_services[]"]');
			checkboxes.forEach(function(cb) {
				var rows = document.querySelectorAll('.' + platform + '-service-' + cb.value);
				rows.forEach(function(row) {
					row.style.display = cb.checked ? 'table-row' : 'none';
				});
			});
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

			// Office 365 / iCloud service checkboxes: toggle per-service settings on change.
			['office365', 'icloud'].forEach(function(platform) {
				var checkboxes = document.querySelectorAll('input[name="' + platform + '_enabled_services[]"]');
				checkboxes.forEach(function(cb) {
					cb.addEventListener('change', function() {
						toggleServiceSettings(platform);
					});
				});
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

			// Shopify: listen for API mode changes to toggle sub-fields.
			var shopifyModeSelect = document.getElementById('shopify_api_mode');
			if (shopifyModeSelect) {
				shopifyModeSelect.addEventListener('change', function() {
					toggleShopifyApiMode(this.value);
				});
			}

			// Upwork: listen for mode changes to toggle API vs web search sub-fields.
			var upworkModeSelect = document.getElementById('upwork_mode');
			if (upworkModeSelect) {
				upworkModeSelect.addEventListener('change', function() {
					toggleUpworkMode(this.value);
				});
				// Also apply on initial load if Upwork is the selected type.
				if (connectionType === 'upwork') { toggleUpworkMode(upworkModeSelect.value); }
			}

			// LinkedIn: listen for mode changes to toggle API vs web search sub-fields.
			var linkedinModeSelect = document.getElementById('linkedin_mode');
			if (linkedinModeSelect) {
				linkedinModeSelect.addEventListener('change', function() {
					toggleLinkedinMode(this.value);
				});
				// Also apply on initial load if LinkedIn is the selected type.
				if (connectionType === 'linkedin') { toggleLinkedinMode(linkedinModeSelect.value); }
			}

			// Shopify shop domain input: update the (read-only) URL field in real time (Admin API only).
			var shopifyDomainInput = document.getElementById('shopify_shop_domain');
			if (shopifyDomainInput) {
				shopifyDomainInput.addEventListener('input', function() {
					var urlField = document.getElementById('url');
					if (urlField && urlField.readOnly) {
						var domain = this.value.replace(/^https?:\/\//i, '').replace(/\/$/, '');
						if (domain && domain.indexOf('.') === -1) {
							domain += '.myshopify.com';
						}
						urlField.value = domain ? 'https://' + domain : '';
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

			// Telegram: inline Test Bot Token button (works before saving).
			var tgTestBtn     = document.getElementById('telegram_test_connection_btn');
			var tgTestSpinner = document.getElementById('telegram_test_spinner');
			var tgTestResult  = document.getElementById('telegram_test_result');
			if (tgTestBtn) {
				tgTestBtn.addEventListener('click', function() {
					var botToken     = document.getElementById('telegram_bot_token').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!botToken && !connectionId) {
						if (tgTestResult) {
							tgTestResult.style.display = 'block';
							tgTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Bot Token first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					tgTestBtn.disabled = true;
					if (tgTestSpinner) { tgTestSpinner.style.display = 'inline-block'; }
					if (tgTestResult)  { tgTestResult.style.display = 'none'; tgTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_telegram_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_telegram_live' ) ); ?>);
					if (botToken) { data.append('bot_token', botToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							tgTestBtn.disabled = false;
							if (tgTestSpinner) { tgTestSpinner.style.display = 'none'; }
							if (!tgTestResult) { return; }
							tgTestResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Bot token verified!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.bot_name)     { items.push(<?php echo wp_json_encode( __( 'Name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.bot_name); }
									if (d.bot_username) {
										items.push(<?php echo wp_json_encode( __( 'Username:', 'mcp-ai-wpoos-pro' ) ); ?> + ' @' + d.bot_username);
										var userEl = document.getElementById('telegram_bot_username');
										if (userEl && !userEl.value) { userEl.value = '@' + d.bot_username; }
									}
									if (d.bot_id)       { items.push(<?php echo wp_json_encode( __( 'Bot ID:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.bot_id); }
									if (d.webhook_url) {
										items.push(<?php echo wp_json_encode( __( 'Webhook URL:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.webhook_url + '</code>');
									} else {
										items.push(<?php echo wp_json_encode( __( 'Webhook: not set', 'mcp-ai-wpoos-pro' ) ); ?>);
									}
									if (typeof d.pending_updates !== 'undefined') {
										items.push(<?php echo wp_json_encode( __( 'Pending updates:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.pending_updates);
									}
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) {
										html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>';
									}
									if (d.webhook_last_error) {
										html += '<p style="margin:6px 0 0;color:#d63638;font-size:13px;">✕ <?php echo esc_js( __( 'Last webhook error:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.webhook_last_error + '</p>';
									}
								}
								html += '</div>';
								tgTestResult.innerHTML = html;
							} else {
								tgTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							tgTestBtn.disabled = false;
							if (tgTestSpinner) { tgTestSpinner.style.display = 'none'; }
							if (tgTestResult) {
								tgTestResult.style.display = 'block';
								tgTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Telegram: Test Auto-Reply button.
			var tgAutoReplyBtn     = document.getElementById('telegram_test_auto_reply_btn');
			var tgAutoReplySpinner = document.getElementById('telegram_test_auto_reply_spinner');
			var tgAutoReplyResult  = document.getElementById('telegram_test_auto_reply_result');
			if (tgAutoReplyBtn) {
				tgAutoReplyBtn.addEventListener('click', function() {
					var msgEl    = document.getElementById('telegram_test_auto_reply_msg');
					var chatIdEl = document.getElementById('telegram_test_auto_reply_chat_id');
					var msg      = msgEl    ? msgEl.value.trim()    : '';
					var chatId   = chatIdEl ? chatIdEl.value.trim() : '';

					if (!msg) {
						if (tgAutoReplyResult) {
							tgAutoReplyResult.style.display = 'block';
							tgAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					tgAutoReplyBtn.disabled = true;
					if (tgAutoReplySpinner) { tgAutoReplySpinner.style.display = 'inline-block'; }
					if (tgAutoReplyResult)  { tgAutoReplyResult.style.display = 'none'; tgAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_telegram_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_telegram_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (chatId) { data.append('test_chat_id', chatId); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							tgAutoReplyBtn.disabled = false;
							if (tgAutoReplySpinner) { tgAutoReplySpinner.style.display = 'none'; }
							if (!tgAutoReplyResult) { return; }
							tgAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #229ED9;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'Reply sent to the test chat/group/channel via Telegram.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
								} else if (chatId && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but sending via Telegram failed.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
								}
								html += '</div>';
								tgAutoReplyResult.innerHTML = html;
							} else {
								tgAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							tgAutoReplyBtn.disabled = false;
							if (tgAutoReplySpinner) { tgAutoReplySpinner.style.display = 'none'; }
							if (tgAutoReplyResult) {
								tgAutoReplyResult.style.display = 'block';
								tgAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Telegram: Test Send to Group/Channel button.
			var tgGroupSendBtn     = document.getElementById('telegram_test_group_send_btn');
			var tgGroupSendSpinner = document.getElementById('telegram_test_group_spinner');
			var tgGroupSendResult  = document.getElementById('telegram_test_group_result');
			if (tgGroupSendBtn) {
				tgGroupSendBtn.addEventListener('click', function() {
					var chatIdEl = document.getElementById('telegram_test_group_chat_id');
					var msgEl    = document.getElementById('telegram_test_group_msg');
					var chatId   = chatIdEl ? chatIdEl.value.trim() : '';
					var msg      = msgEl    ? msgEl.value.trim()    : '';

					if (!chatId) {
						if (tgGroupSendResult) {
							tgGroupSendResult.style.display = 'block';
							tgGroupSendResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a group/channel chat ID, @username, or t.me link.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					// Parse t.me links: extract @username from public links.
					// Private invite links (t.me/+hash) cannot be resolved to a chat ID.
					var tmeMatch = chatId.match(/^https?:\/\/t\.me\/\+/);
					if (tmeMatch) {
						if (tgGroupSendResult) {
							tgGroupSendResult.style.display = 'block';
							tgGroupSendResult.innerHTML = '<div class="notice notice-warning inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Private invite links (t.me/+…) cannot be used to send messages via the API. Please use the numeric chat ID (e.g. -1001234567890) or a public @username instead. You can find the chat ID in the plugin logs after the bot receives a message in the group.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}
					var tmePublicMatch = chatId.match(/^https?:\/\/t\.me\/([a-zA-Z][a-zA-Z0-9_]{4,})$/);
					if (tmePublicMatch) {
						chatId = '@' + tmePublicMatch[1];
					}

					if (!msg) {
						if (tgGroupSendResult) {
							tgGroupSendResult.style.display = 'block';
							tgGroupSendResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					tgGroupSendBtn.disabled = true;
					if (tgGroupSendSpinner) { tgGroupSendSpinner.style.display = 'inline-block'; }
					if (tgGroupSendResult)  { tgGroupSendResult.style.display = 'none'; tgGroupSendResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_telegram_send_group');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_telegram_send_group' ) ); ?>);
					data.append('test_chat_id', chatId);
					data.append('test_message', msg);
					var connIdEl2 = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl2) { data.append('connection_id', connIdEl2.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							tgGroupSendBtn.disabled = false;
							if (tgGroupSendSpinner) { tgGroupSendSpinner.style.display = 'none'; }
							if (!tgGroupSendResult) { return; }
							tgGroupSendResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Message sent successfully!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.chat_title) {
									html += '<p style="margin:6px 0 0;">' + <?php echo wp_json_encode( __( 'Delivered to:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <strong>' + d.chat_title.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</strong>';
									if (d.chat_type) { html += ' (' + d.chat_type + ')'; }
									html += '</p>';
								}
								html += '</div>';
								tgGroupSendResult.innerHTML = html;
							} else {
								tgGroupSendResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to send message.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							tgGroupSendBtn.disabled = false;
							if (tgGroupSendSpinner) { tgGroupSendSpinner.style.display = 'none'; }
							if (tgGroupSendResult) {
								tgGroupSendResult.style.display = 'block';
								tgGroupSendResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Telegram: Set Webhook button.
			var tgSetWebhookBtn  = document.getElementById('telegram_set_webhook_btn');
			var tgCheckWebhookBtn = document.getElementById('telegram_check_webhook_btn');
			var tgWebhookSpinner = document.getElementById('telegram_webhook_spinner');
			var tgWebhookResult  = document.getElementById('telegram_webhook_result');
			if (tgSetWebhookBtn) {
				tgSetWebhookBtn.addEventListener('click', function() {
					var botToken     = document.getElementById('telegram_bot_token').value.trim();
					var secretToken  = document.getElementById('telegram_secret_token') ? document.getElementById('telegram_secret_token').value.trim() : '';
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!botToken && !connectionId) {
						if (tgWebhookResult) {
							tgWebhookResult.style.display = 'block';
							tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Bot Token or save the connection first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					tgSetWebhookBtn.disabled = true;
					if (tgCheckWebhookBtn) { tgCheckWebhookBtn.disabled = true; }
					if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'inline-block'; }
					if (tgWebhookResult)  { tgWebhookResult.style.display = 'none'; tgWebhookResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_set_telegram_webhook');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_set_telegram_webhook' ) ); ?>);
					if (botToken) { data.append('bot_token', botToken); }
					if (secretToken) { data.append('secret_token', secretToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							tgSetWebhookBtn.disabled = false;
							if (tgCheckWebhookBtn) { tgCheckWebhookBtn.disabled = false; }
							if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'none'; }
							if (!tgWebhookResult) { return; }
							tgWebhookResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Webhook set successfully!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.webhook_url) {
									html += '<p style="margin:4px 0 0;font-size:13px;">' + <?php echo wp_json_encode( __( 'Webhook URL:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.webhook_url + '</code></p>';
								}
								html += '</div>';
								tgWebhookResult.innerHTML = html;
							} else {
								tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to set webhook.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							tgSetWebhookBtn.disabled = false;
							if (tgCheckWebhookBtn) { tgCheckWebhookBtn.disabled = false; }
							if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'none'; }
							if (tgWebhookResult) {
								tgWebhookResult.style.display = 'block';
								tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Telegram: Check Webhook Status button.
			if (tgCheckWebhookBtn) {
				tgCheckWebhookBtn.addEventListener('click', function() {
					var botToken     = document.getElementById('telegram_bot_token').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!botToken && !connectionId) {
						if (tgWebhookResult) {
							tgWebhookResult.style.display = 'block';
							tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Bot Token or save the connection first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					if (tgSetWebhookBtn) { tgSetWebhookBtn.disabled = true; }
					tgCheckWebhookBtn.disabled = true;
					if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'inline-block'; }
					if (tgWebhookResult)  { tgWebhookResult.style.display = 'none'; tgWebhookResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_get_telegram_webhook_info');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_get_telegram_webhook_info' ) ); ?>);
					if (botToken) { data.append('bot_token', botToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							if (tgSetWebhookBtn) { tgSetWebhookBtn.disabled = false; }
							tgCheckWebhookBtn.disabled = false;
							if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'none'; }
							if (!tgWebhookResult) { return; }
							tgWebhookResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-info inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Webhook Status', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.webhook_url) {
										items.push(<?php echo wp_json_encode( __( 'URL:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.webhook_url + '</code>');
									} else {
										items.push(<?php echo wp_json_encode( __( 'URL: not set', 'mcp-ai-wpoos-pro' ) ); ?>);
									}
									if (typeof d.pending_updates !== 'undefined') {
										items.push(<?php echo wp_json_encode( __( 'Pending updates:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.pending_updates);
									}
									if (d.has_custom_certificate) {
										items.push(<?php echo wp_json_encode( __( 'Custom certificate: Yes', 'mcp-ai-wpoos-pro' ) ); ?>);
									}
									if (d.max_connections) {
										items.push(<?php echo wp_json_encode( __( 'Max connections:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.max_connections);
									}
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.last_error_message) {
										html += '<p style="margin:6px 0 0;color:#d63638;font-size:13px;">✕ <?php echo esc_js( __( 'Last error:', 'mcp-ai-wpoos-pro' ) ); ?> ' + d.last_error_message + '</p>';
									}
									if (d.warning) {
										html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>';
									}
								}
								html += '</div>';
								tgWebhookResult.innerHTML = html;
							} else {
								tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to retrieve webhook status.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							if (tgSetWebhookBtn) { tgSetWebhookBtn.disabled = false; }
							tgCheckWebhookBtn.disabled = false;
							if (tgWebhookSpinner) { tgWebhookSpinner.style.display = 'none'; }
							if (tgWebhookResult) {
								tgWebhookResult.style.display = 'block';
								tgWebhookResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Telegram: Register Commands with Telegram button.
			var tgRegisterCmdsBtn     = document.getElementById('telegram_register_commands_btn');
			var tgRegisterCmdsSpinner = document.getElementById('telegram_register_commands_spinner');
			var tgRegisterCmdsResult  = document.getElementById('telegram_register_commands_result');
			var tgRegisterCmdsConnId  = <?php echo wp_json_encode( $is_edit && ! empty( $connection['id'] ) ? $connection['id'] : '' ); ?>;

			if (tgRegisterCmdsBtn) {
				tgRegisterCmdsBtn.addEventListener('click', function() {
					tgRegisterCmdsBtn.disabled = true;
					if (tgRegisterCmdsSpinner) { tgRegisterCmdsSpinner.style.display = 'inline-block'; }
					if (tgRegisterCmdsResult)  { tgRegisterCmdsResult.style.display = 'none'; tgRegisterCmdsResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_register_telegram_commands');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_register_telegram_commands' ) ); ?>);
					data.append('connection_id', tgRegisterCmdsConnId);

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							tgRegisterCmdsBtn.disabled = false;
							if (tgRegisterCmdsSpinner) { tgRegisterCmdsSpinner.style.display = 'none'; }
							if (!tgRegisterCmdsResult) { return; }
							tgRegisterCmdsResult.style.display = 'block';
							if (result.success) {
								tgRegisterCmdsResult.innerHTML = '<div class="notice notice-success inline" style="margin:0;"><p>✓ ' + (result.data && result.data.message ? result.data.message : <?php echo wp_json_encode( __( 'Commands registered successfully.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							} else {
								tgRegisterCmdsResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to register commands.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							tgRegisterCmdsBtn.disabled = false;
							if (tgRegisterCmdsSpinner) { tgRegisterCmdsSpinner.style.display = 'none'; }
							if (tgRegisterCmdsResult) {
								tgRegisterCmdsResult.style.display = 'block';
								tgRegisterCmdsResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
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

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
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


			// WhatsApp: Register Phone Number button.
			var waRegisterBtn     = document.getElementById('whatsapp_register_phone_btn');
			var waRegisterSpinner = document.getElementById('whatsapp_register_phone_spinner');
			var waRegisterResult  = document.getElementById('whatsapp_register_phone_result');
			if (waRegisterBtn) {
				waRegisterBtn.addEventListener('click', function() {
					var pinEl         = document.getElementById('whatsapp_register_pin');
					var pin           = pinEl ? pinEl.value.trim() : '';
					var connIdEl      = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId  = connIdEl ? connIdEl.value.trim() : '';
					var accessToken   = document.getElementById('whatsapp_access_token') ? document.getElementById('whatsapp_access_token').value.trim() : '';
					var phoneNumberId = document.getElementById('whatsapp_phone_number_id') ? document.getElementById('whatsapp_phone_number_id').value.trim() : '';
					var versionEl     = document.getElementById('whatsapp_graph_api_version');
					var apiVersion    = versionEl ? versionEl.value.trim() : '';

					if (!/^[0-9]{6}$/.test(pin)) {
						if (waRegisterResult) {
							waRegisterResult.style.display = 'block';
							waRegisterResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your 6-digit two-step verification PIN.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					if (!connectionId && (!accessToken || !phoneNumberId)) {
						if (waRegisterResult) {
							waRegisterResult.style.display = 'block';
							waRegisterResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please save the connection first, or enter your Access Token and Phone Number ID.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					waRegisterBtn.disabled = true;
					if (waRegisterSpinner) { waRegisterSpinner.style.display = 'inline-block'; }
					if (waRegisterResult)  { waRegisterResult.style.display = 'none'; waRegisterResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_register_whatsapp_phone_number');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_register_whatsapp_phone_number' ) ); ?>);
					data.append('pin', pin);
					if (connectionId)  { data.append('connection_id', connectionId); }
					if (accessToken)   { data.append('access_token', accessToken); }
					if (phoneNumberId) { data.append('phone_number_id', phoneNumberId); }
					if (apiVersion)    { data.append('graph_api_version', apiVersion); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							waRegisterBtn.disabled = false;
							if (waRegisterSpinner) { waRegisterSpinner.style.display = 'none'; }
							if (!waRegisterResult) { return; }
							waRegisterResult.style.display = 'block';
							if (result.success) {
								waRegisterResult.innerHTML = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Phone number registered successfully! You can now use auto-reply.', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p></div>';
								if (pinEl) { pinEl.value = ''; }
							} else {
								var errMsg = (result.data && result.data.message) ? result.data.message : (result.data || <?php echo wp_json_encode( __( 'Registration failed. Please check your PIN and credentials.', 'mcp-ai-wpoos-pro' ) ); ?>);
								var hint   = (result.data && result.data.hint) ? result.data.hint : '';
								var html   = '<div class="notice notice-error inline" style="margin:0;"><p>' + errMsg + '</p>';
								if (hint) { html += '<p style="margin:4px 0 0;font-style:italic;font-size:12px;">' + hint.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>'; }
								html += '</div>';
								waRegisterResult.innerHTML = html;
							}
						})
						.catch(function() {
							waRegisterBtn.disabled = false;
							if (waRegisterSpinner) { waRegisterSpinner.style.display = 'none'; }
							if (waRegisterResult) {
								waRegisterResult.style.display = 'block';
								waRegisterResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// WhatsApp: Create Group button (Groups Management API).
			var waCreateGroupBtn     = document.getElementById('whatsapp_create_group_btn');
			var waCreateGroupSpinner = document.getElementById('whatsapp_create_group_spinner');
			var waCreateGroupResult  = document.getElementById('whatsapp_create_group_result');
			if (waCreateGroupBtn) {
				waCreateGroupBtn.addEventListener('click', function() {
					var subject       = (document.getElementById('whatsapp_group_subject') || {}).value || '';
					var description   = (document.getElementById('whatsapp_group_description') || {}).value || '';
					var accessToken   = (document.getElementById('whatsapp_access_token') || {}).value || '';
					var phoneNumberId = (document.getElementById('whatsapp_phone_number_id') || {}).value || '';
					var connIdEl      = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId  = connIdEl ? connIdEl.value.trim() : '';
					var versionEl     = document.getElementById('whatsapp_graph_api_version');
					var apiVersion    = versionEl ? versionEl.value.trim() : '';

					if (!subject.trim()) {
						if (waCreateGroupResult) {
							waCreateGroupResult.style.display = 'block';
							waCreateGroupResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a Group Name (Subject).', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}
					if (!connectionId && (!accessToken || !phoneNumberId)) {
						if (waCreateGroupResult) {
							waCreateGroupResult.style.display = 'block';
							waCreateGroupResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please save the connection first, or enter your Access Token and Phone Number ID.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					waCreateGroupBtn.disabled = true;
					if (waCreateGroupSpinner) { waCreateGroupSpinner.style.display = 'inline-block'; }
					if (waCreateGroupResult)  { waCreateGroupResult.style.display = 'none'; waCreateGroupResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_create_whatsapp_group');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_create_whatsapp_group' ) ); ?>);
					data.append('subject', subject.trim());
					if (description.trim()) { data.append('description', description.trim()); }
					if (connectionId)  { data.append('connection_id', connectionId); }
					if (accessToken)   { data.append('access_token', accessToken); }
					if (phoneNumberId) { data.append('phone_number_id', phoneNumberId); }
					if (apiVersion)    { data.append('graph_api_version', apiVersion); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							waCreateGroupBtn.disabled = false;
							if (waCreateGroupSpinner) { waCreateGroupSpinner.style.display = 'none'; }
							if (!waCreateGroupResult) { return; }
							waCreateGroupResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var inviteLink = d && d.invite_link ? d.invite_link : '';
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Group created successfully!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.group_id) {
									html += '<p>' + <?php echo wp_json_encode( __( 'Group ID:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.group_id + '</code></p>';
									// Auto-populate the Group ID field so it is saved with the connection.
									var groupIdField = document.getElementById('whatsapp_group_id');
									if (groupIdField) {
										groupIdField.value = d.group_id;
									}
								}
								if (inviteLink) {
									html += '<p>' + <?php echo wp_json_encode( __( 'Invite Link:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <a href="' + inviteLink + '" target="_blank" rel="noopener noreferrer">' + inviteLink + '</a></p>';
									// Auto-populate the Channel URL field.
									var channelUrlField = document.getElementById('whatsapp_channel_url');
									if (channelUrlField) {
										channelUrlField.value = inviteLink;
										html += '<p style="color:#00a32a;">' + <?php echo wp_json_encode( __( 'Group ID and Channel URL fields have been populated. Save the connection to persist them.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
									}
								}
								html += '</div>';
								waCreateGroupResult.innerHTML = html;
							} else {
								var errMsg = (result.data && result.data.message) ? result.data.message : (result.data || <?php echo wp_json_encode( __( 'Failed to create group.', 'mcp-ai-wpoos-pro' ) ); ?>);
								var hint   = (result.data && result.data.hint) ? '<br><em>' + result.data.hint + '</em>' : '';
								waCreateGroupResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + errMsg + hint + '</p></div>';
							}
						})
						.catch(function() {
							waCreateGroupBtn.disabled = false;
							if (waCreateGroupSpinner) { waCreateGroupSpinner.style.display = 'none'; }
							if (waCreateGroupResult) {
								waCreateGroupResult.style.display = 'block';
								waCreateGroupResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
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

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
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

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
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


			// Google Chat: Fetch Spaces button.
			var gcFetchSpacesBtn    = document.getElementById('wp-mcp-ai-gc-fetch-spaces-btn');
			var gcFetchSpacesResult = document.getElementById('wp-mcp-ai-gc-fetch-spaces-result');
			if (gcFetchSpacesBtn) {
			gcFetchSpacesBtn.addEventListener('click', function() {
			var tokenEl     = document.getElementById('google_chat_service_account_key');
			var accessToken = tokenEl ? tokenEl.value.trim() : '';
			var connIdEl    = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
			var connectionId = connIdEl ? connIdEl.value.trim() : '';

			if (!accessToken && !connectionId) {
			if (gcFetchSpacesResult) {
			gcFetchSpacesResult.innerHTML = '<span style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Please paste your Service Account JSON key first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
			}
			return;
			}

			gcFetchSpacesBtn.disabled    = true;
			gcFetchSpacesBtn.textContent = <?php echo wp_json_encode( __( 'Fetching…', 'mcp-ai-wpoos-pro' ) ); ?>;
			if (gcFetchSpacesResult) { gcFetchSpacesResult.innerHTML = ''; }

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_fetch_google_chat_spaces');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_fetch_google_chat_spaces' ) ); ?>);
			data.append('access_token', accessToken);
			if (connectionId) { data.append('connection_id', connectionId); }

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(r) {
			if (!r.ok) { throw new Error(r.status); }
			return r.json();
			})
			.then(function(json) {
			gcFetchSpacesBtn.disabled    = false;
			gcFetchSpacesBtn.textContent = <?php echo wp_json_encode( __( 'Fetch Spaces', 'mcp-ai-wpoos-pro' ) ); ?>;
			if (!gcFetchSpacesResult) { return; }
			if (!json.success) {
			gcFetchSpacesResult.innerHTML = '<span style="color:#d63638;">' + (json.data && json.data.message ? json.data.message : <?php echo wp_json_encode( __( 'Failed to fetch spaces.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</span>';
			return;
			}
			var spaces = json.data.spaces || [];
			if (!spaces.length) {
			gcFetchSpacesResult.innerHTML = '<span style="color:#646970;">' + <?php echo wp_json_encode( __( 'No spaces found. Make sure your bot is added to at least one Google Chat space.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
			return;
			}
			if (spaces.length === 1) {
			document.getElementById('google_chat_space').value = spaces[0].name;
			gcFetchSpacesResult.innerHTML = '<span style="color:#00a32a;">' + <?php echo wp_json_encode( __( 'Space set automatically:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + (spaces[0].displayName || spaces[0].name) + ' (' + spaces[0].name + ')</span>';
			} else {
			var sel = '<select id="wp-mcp-ai-gc-space-select" style="max-width:400px;">';
			sel += '<option value="">' + <?php echo wp_json_encode( __( '-- Select a space --', 'mcp-ai-wpoos-pro' ) ); ?> + '</option>';
			spaces.forEach(function(s) {
			sel += '<option value="' + s.name + '">' + (s.displayName ? s.displayName + ' – ' : '') + s.name + '</option>';
			});
			sel += '</select> <button type="button" id="wp-mcp-ai-gc-space-apply" class="button">' + <?php echo wp_json_encode( __( 'Use Selected', 'mcp-ai-wpoos-pro' ) ); ?> + '</button>';
			gcFetchSpacesResult.innerHTML = sel;
			document.getElementById('wp-mcp-ai-gc-space-apply').addEventListener('click', function() {
			var selEl = document.getElementById('wp-mcp-ai-gc-space-select');
			if (selEl && selEl.value) {
			document.getElementById('google_chat_space').value = selEl.value;
			gcFetchSpacesResult.innerHTML = '<span style="color:#00a32a;">' + <?php echo wp_json_encode( __( 'Space applied.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
			}
			});
			}
			})
			.catch(function() {
			gcFetchSpacesBtn.disabled    = false;
			gcFetchSpacesBtn.textContent = <?php echo wp_json_encode( __( 'Fetch Spaces', 'mcp-ai-wpoos-pro' ) ); ?>;
			if (gcFetchSpacesResult) {
			gcFetchSpacesResult.innerHTML = '<span style="color:#d63638;">' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</span>';
			}
			});
			});
			}

			// Google Chat: Test Connection button.
			var gcTestBtn     = document.getElementById('google_chat_test_connection_btn');
			var gcTestSpinner = document.getElementById('google_chat_test_spinner');
			var gcTestResult  = document.getElementById('google_chat_test_result');
			if (gcTestBtn) {
			gcTestBtn.addEventListener('click', function() {
			var accessToken  = document.getElementById('google_chat_service_account_key').value.trim();
			var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
			var connectionId = connIdEl ? connIdEl.value.trim() : '';

			if (!accessToken && !connectionId) {
			if (gcTestResult) {
			gcTestResult.style.display = 'block';
			gcTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please paste your Service Account JSON key first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			return;
			}

			gcTestBtn.disabled = true;
			if (gcTestSpinner) { gcTestSpinner.style.display = 'inline-block'; }
			if (gcTestResult)  { gcTestResult.style.display = 'none'; gcTestResult.innerHTML = ''; }

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_test_google_chat_live');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_google_chat_live' ) ); ?>);
			data.append('access_token', accessToken);
			if (connectionId) { data.append('connection_id', connectionId); }

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
			})
			.then(function(result) {
			gcTestBtn.disabled = false;
			if (gcTestSpinner) { gcTestSpinner.style.display = 'none'; }
			if (!gcTestResult) { return; }
			gcTestResult.style.display = 'block';
			if (result.success) {
			var d    = result.data;
			var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection test successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
			if (d && d.mode === 'webhook_only') {
			html += '<p style="margin:4px 0 0;">' + <?php echo wp_json_encode( __( 'Webhook-only mode: OIDC verification is disabled and replies will be sent via the configured Incoming Webhook URL. No Service Account or OAuth credentials are required.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
			} else if (d && typeof d === 'object') {
			var items = [];
			if (d.space_count !== undefined) { items.push(<?php echo wp_json_encode( __( 'Spaces accessible:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.space_count); }
			if (d.bot_name)  { items.push(<?php echo wp_json_encode( __( 'Bot name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.bot_name); }
			if (items.length) {
			html += '<ul style="margin:8px 0;padding-left:20px;">';
			items.forEach(function(item) { html += '<li>' + item + '</li>'; });
			html += '</ul>';
			}
			}
			html += '</div>';
			gcTestResult.innerHTML = html;
			} else {
			gcTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
			}
			})
			.catch(function() {
			gcTestBtn.disabled = false;
			if (gcTestSpinner) { gcTestSpinner.style.display = 'none'; }
			if (gcTestResult) {
			gcTestResult.style.display = 'block';
			gcTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			});
			});
			}

			// Google Chat: Test Auto-Reply button.
			var gcAutoReplyBtn     = document.getElementById('google_chat_test_auto_reply_btn');
			var gcAutoReplySpinner = document.getElementById('google_chat_test_auto_reply_spinner');
			var gcAutoReplyResult  = document.getElementById('google_chat_test_auto_reply_result');
			if (gcAutoReplyBtn) {
			gcAutoReplyBtn.addEventListener('click', function() {
			var msgEl = document.getElementById('google_chat_test_auto_reply_msg');
			var spaceEl = document.getElementById('google_chat_test_auto_reply_space');
			var msg   = msgEl   ? msgEl.value.trim()   : '';
			var space = spaceEl ? spaceEl.value.trim() : '';

			if (!msg) {
			if (gcAutoReplyResult) {
			gcAutoReplyResult.style.display = 'block';
			gcAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			return;
			}

			gcAutoReplyBtn.disabled = true;
			if (gcAutoReplySpinner) { gcAutoReplySpinner.style.display = 'inline-block'; }
			if (gcAutoReplyResult)  { gcAutoReplyResult.style.display = 'none'; gcAutoReplyResult.innerHTML = ''; }

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_test_google_chat_auto_reply');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_google_chat_auto_reply' ) ); ?>);
			data.append('test_message', msg);
			if (space) { data.append('test_space', space); }
			var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
			if (connIdEl) { data.append('connection_id', connIdEl.value); }

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
			})
			.then(function(result) {
			gcAutoReplyBtn.disabled = false;
			if (gcAutoReplySpinner) { gcAutoReplySpinner.style.display = 'none'; }
			if (!gcAutoReplyResult) { return; }
			gcAutoReplyResult.style.display = 'block';
			if (result.success) {
			var d    = result.data;
			var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
			if (d && d.ai_reply) {
			html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #1a73e8;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
			}
			if (d && d.sent) {
			html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'Reply sent to the test space via Google Chat.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
			} else if (space && d && !d.sent) {
			var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
			html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but sending via Google Chat failed.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
			}
			html += '</div>';
			gcAutoReplyResult.innerHTML = html;
			} else {
			gcAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
			}
			})
			.catch(function() {
			gcAutoReplyBtn.disabled = false;
			if (gcAutoReplySpinner) { gcAutoReplySpinner.style.display = 'none'; }
			if (gcAutoReplyResult) {
			gcAutoReplyResult.style.display = 'block';
			gcAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			});
			});
			}

			// Google Chat: Test Incoming Trigger button.
			var gcIncomingBtn     = document.getElementById('google_chat_test_incoming_trigger_btn');
			var gcIncomingSpinner = document.getElementById('google_chat_test_incoming_spinner');
			var gcIncomingResult  = document.getElementById('google_chat_test_incoming_result');
			if (gcIncomingBtn) {
			gcIncomingBtn.addEventListener('click', function() {
			var msgEl   = document.getElementById('google_chat_test_incoming_msg');
			var spaceEl = document.getElementById('google_chat_test_incoming_space');
			var msg     = msgEl   ? msgEl.value.trim()   : '';
			var space   = spaceEl ? spaceEl.value.trim() : '';

			if (!msg) {
			if (gcIncomingResult) {
			gcIncomingResult.style.display = 'block';
			gcIncomingResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			return;
			}

			gcIncomingBtn.disabled = true;
			if (gcIncomingSpinner) { gcIncomingSpinner.style.display = 'inline-block'; }
			if (gcIncomingResult)  { gcIncomingResult.style.display = 'none'; gcIncomingResult.innerHTML = ''; }

			var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
			var connId   = connIdEl ? connIdEl.value.trim() : '';

			if (!connId) {
			gcIncomingBtn.disabled = false;
			if (gcIncomingSpinner) { gcIncomingSpinner.style.display = 'none'; }
			if (gcIncomingResult) {
			gcIncomingResult.style.display = 'block';
			gcIncomingResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Save the connection first to get a Connection ID.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			return;
			}

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_test_google_chat_incoming_trigger');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_google_chat_incoming_trigger' ) ); ?>);
			data.append('connection_id', connId);
			data.append('test_message', msg);
			if (space) { data.append('test_space', space); }

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
			})
			.then(function(result) {
			gcIncomingBtn.disabled = false;
			if (gcIncomingSpinner) { gcIncomingSpinner.style.display = 'none'; }
			if (!gcIncomingResult) { return; }
			gcIncomingResult.style.display = 'block';
			if (result.success) {
			var d    = result.data;
			var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Incoming trigger test passed!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
			if (d && d.ai_reply) {
			html += '<p style="margin:6px 0 2px;">' + <?php echo wp_json_encode( __( 'AI reply generated:', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
			html += '<blockquote style="margin:4px 0 4px 16px;border-left:3px solid #1a73e8;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
			}
			if (d && d.webhook_url) {
			html += '<p style="margin:6px 0 2px;font-size:12px;color:#646970;">' + <?php echo wp_json_encode( __( 'Webhook URL for Google Cloud Console:', 'mcp-ai-wpoos-pro' ) ); ?> + '<br><code style="font-size:11px;">' + d.webhook_url.replace(/</g,'&lt;') + '</code></p>';
			}
			if (d && d.sent) {
			html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'AI reply sent to the Google Chat space successfully.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
			} else if (d && d.space_name && !d.sent) {
			var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
			html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but could not be sent to Google Chat.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
			} else if (!d || !d.space_name) {
			html += '<p style="margin:4px 0 0;color:#646970;font-size:13px;">' + <?php echo wp_json_encode( __( 'No space configured — reply was not sent (add a space name above to test sending).', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
			}
			html += '</div>';
			gcIncomingResult.innerHTML = html;
			} else {
			gcIncomingResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Incoming trigger test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
			}
			})
			.catch(function() {
			gcIncomingBtn.disabled = false;
			if (gcIncomingSpinner) { gcIncomingSpinner.style.display = 'none'; }
			if (gcIncomingResult) {
			gcIncomingResult.style.display = 'block';
			gcIncomingResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			});
			});
			}

			// Google Chat: Fetch Webhook Log button.
			var gcFetchLogBtn     = document.getElementById('google_chat_fetch_log_btn');
			var gcClearLogBtn     = document.getElementById('google_chat_clear_log_btn');
			var gcLogSpinner      = document.getElementById('google_chat_log_spinner');
			var gcLogResult       = document.getElementById('google_chat_log_result');

			function gcEscHtml(str) {
			return String(str || '').replace(/[&<>"']/g, function(c) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
			});
			}

			function gcFormatLogTable(entries) {
			if (!entries || !entries.length) {
			return '<div class="notice notice-warning inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'No webhook events recorded yet. Send a message from Google Chat and then click Fetch again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			var statusLabels = {
			'accepted': '<span style="color:#00a32a;font-weight:600;">✓ Accepted</span>',
			'rejected': '<span style="color:#d63638;font-weight:600;">✗ Rejected</span>',
			'processed': '<span style="color:#00a32a;">✓ Processed</span>'
			};
			var html = '<div style="overflow-x:auto;margin-top:4px;">';
			html += '<table class="widefat striped" style="font-size:12px;">';
			html += '<thead><tr>';
			html += '<th style="white-space:nowrap;">' + <?php echo wp_json_encode( __( 'Time (UTC)', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '<th>' + <?php echo wp_json_encode( __( 'Status', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '<th>' + <?php echo wp_json_encode( __( 'Event Type', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '<th>' + <?php echo wp_json_encode( __( 'Space', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '<th>' + <?php echo wp_json_encode( __( 'Detail / Reason', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '<th>' + <?php echo wp_json_encode( __( 'IP', 'mcp-ai-wpoos-pro' ) ); ?> + '</th>';
			html += '</tr></thead><tbody>';
			entries.forEach(function(e) {
			var statusHtml = statusLabels[e.status] || ('<span>' + gcEscHtml(e.status) + '</span>');
			html += '<tr>';
			html += '<td style="white-space:nowrap;">' + gcEscHtml(e.ts) + '</td>';
			html += '<td>' + statusHtml + '</td>';
			html += '<td>' + gcEscHtml(e.event_type || '—') + '</td>';
			html += '<td style="max-width:180px;word-break:break-all;">' + gcEscHtml(e.space || '—') + '</td>';
			html += '<td style="max-width:300px;word-break:break-word;">' + gcEscHtml(e.reason) + '</td>';
			html += '<td style="white-space:nowrap;">' + gcEscHtml(e.ip) + '</td>';
			html += '</tr>';
			});
			html += '</tbody></table></div>';
			return html;
			}

			if (gcFetchLogBtn) {
			gcFetchLogBtn.addEventListener('click', function() {
			gcFetchLogBtn.disabled = true;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'inline-block'; }
			if (gcLogResult)  { gcLogResult.style.display = 'none'; gcLogResult.innerHTML = ''; }

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_get_google_chat_webhook_log');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_get_google_chat_webhook_log' ) ); ?>);

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
			})
			.then(function(result) {
			gcFetchLogBtn.disabled = false;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'none'; }
			if (!gcLogResult) { return; }
			gcLogResult.style.display = 'block';
			if (result.success) {
			gcLogResult.innerHTML = gcFormatLogTable(result.data);
			} else {
			gcLogResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to fetch log.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
			}
			})
			.catch(function() {
			gcFetchLogBtn.disabled = false;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'none'; }
			if (gcLogResult) {
			gcLogResult.style.display = 'block';
			gcLogResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
			}
			});
			});
			}

			if (gcClearLogBtn) {
			gcClearLogBtn.addEventListener('click', function() {
			if (!window.confirm(<?php echo wp_json_encode( __( 'Clear the Google Chat webhook log? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ); ?>)) { return; }
			gcClearLogBtn.disabled = true;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'inline-block'; }

			var data = new FormData();
			data.append('action', 'wp_mcp_ai_clear_google_chat_webhook_log');
			data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_clear_google_chat_webhook_log' ) ); ?>);

			fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function(response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
			})
			.then(function(result) {
			gcClearLogBtn.disabled = false;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'none'; }
			if (gcLogResult) {
			gcLogResult.style.display = 'block';
			gcLogResult.innerHTML = result.success
			? '<div class="notice notice-success inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Webhook log cleared.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>'
			: '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Failed to clear log.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
			}
			})
			.catch(function() {
			gcClearLogBtn.disabled = false;
			if (gcLogSpinner) { gcLogSpinner.style.display = 'none'; }
			if (gcLogResult) {
			gcLogResult.style.display = 'block';
			gcLogResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
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

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							msngGenerateTokenBtn.disabled = false;
							if (result.success) {
								var tokenInput  = document.getElementById('messenger_page_access_token');

								tokenInput.value = result.data.access_token;
								tokenInput.type = 'text';
								if (msngTokenToggleBtn) {
									msngTokenToggleBtn.textContent = <?php echo wp_json_encode( __( 'Hide', 'mcp-ai-wpoos-pro' ) ); ?>;
									msngTokenToggleBtn.setAttribute('aria-label', <?php echo wp_json_encode( __( 'Hide access token', 'mcp-ai-wpoos-pro' ) ); ?>);
								}
								statusEl.style.color = '#00a32a';
								statusEl.textContent = <?php echo wp_json_encode( __( '✓ App Access Token generated and populated. Please save the connection to apply the new token.', 'mcp-ai-wpoos-pro' ) ); ?>;
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
					var accessToken  = document.getElementById('messenger_page_access_token').value.trim();
					var pageId       = document.getElementById('messenger_page_id') ? document.getElementById('messenger_page_id').value.trim() : '';
					var apiVersion   = document.getElementById('messenger_graph_api_version') ? document.getElementById('messenger_graph_api_version').value : 'v21.0';
					var connIdField  = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdField ? connIdField.value : '';

					// Allow empty field when editing — the server will use the saved token.
					if (!accessToken && !connectionId) {
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
					if (connectionId) { data.append('connection_id', connectionId); }
					var waVersionEl = document.getElementById('whatsapp_graph_api_version');
					if (waVersionEl && waVersionEl.value) { data.append('graph_api_version', waVersionEl.value); }
					data.append('page_id', pageId);
					data.append('api_version', apiVersion);

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
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

			// Messenger: Test Auto-Reply button.
			var msngAutoReplyBtn     = document.getElementById('messenger_test_auto_reply_btn');
			var msngAutoReplySpinner = document.getElementById('messenger_test_auto_reply_spinner');
			var msngAutoReplyResult  = document.getElementById('messenger_test_auto_reply_result');
			if (msngAutoReplyBtn) {
				msngAutoReplyBtn.addEventListener('click', function() {
					var msgEl         = document.getElementById('messenger_test_auto_reply_msg');
					var recipientIdEl = document.getElementById('messenger_test_auto_reply_recipient_id');
					var msg           = msgEl         ? msgEl.value.trim()         : '';
					var recipientId   = recipientIdEl ? recipientIdEl.value.trim() : '';

					if (!msg) {
						if (msngAutoReplyResult) {
							msngAutoReplyResult.style.display = 'block';
							msngAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					msngAutoReplyBtn.disabled = true;
					if (msngAutoReplySpinner) { msngAutoReplySpinner.style.display = 'inline-block'; }
					if (msngAutoReplyResult)  { msngAutoReplyResult.style.display = 'none'; msngAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_messenger_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_messenger_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (recipientId) { data.append('test_recipient_id', recipientId); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							msngAutoReplyBtn.disabled = false;
							if (msngAutoReplySpinner) { msngAutoReplySpinner.style.display = 'none'; }
							if (!msngAutoReplyResult) { return; }
							msngAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #0084ff;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'Reply sent to the recipient via Messenger.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
								} else if (recipientId && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but sending via Messenger failed.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
								}
								html += '</div>';
								msngAutoReplyResult.innerHTML = html;
							} else {
								msngAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							msngAutoReplyBtn.disabled = false;
							if (msngAutoReplySpinner) { msngAutoReplySpinner.style.display = 'none'; }
							if (msngAutoReplyResult) {
								msngAutoReplyResult.style.display = 'block';
								msngAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Office 365: inline Test Connection button.
			var o365TestBtn     = document.getElementById('office365_test_connection_btn');
			var o365TestSpinner = document.getElementById('office365_test_spinner');
			var o365TestResult  = document.getElementById('office365_test_result');
			if (o365TestBtn) {
				o365TestBtn.addEventListener('click', function() {
					var clientId     = document.getElementById('office365_client_id').value.trim();
					var clientSecret = document.getElementById('office365_client_secret').value.trim();
					var tenantId     = document.getElementById('office365_tenant_id').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!clientId && !connectionId) {
						if (o365TestResult) {
							o365TestResult.style.display = 'block';
							o365TestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Application (Client) ID first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					o365TestBtn.disabled = true;
					if (o365TestSpinner) { o365TestSpinner.style.display = 'inline-block'; }
					if (o365TestResult)  { o365TestResult.style.display = 'none'; o365TestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_office365_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_office365_live' ) ); ?>);
					if (clientId)     { data.append('client_id', clientId); }
					if (clientSecret) { data.append('client_secret', clientSecret); }
					if (tenantId)     { data.append('tenant_id', tenantId); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							o365TestBtn.disabled = false;
							if (o365TestSpinner) { o365TestSpinner.style.display = 'none'; }
							if (!o365TestResult) { return; }
							o365TestResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.display_name) { items.push(<?php echo wp_json_encode( __( 'Display Name:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.display_name); }
									if (d.mail)         { items.push(<?php echo wp_json_encode( __( 'Email:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.mail); }
									if (d.tenant_id)    { items.push(<?php echo wp_json_encode( __( 'Tenant:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.tenant_id); }
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) { html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>'; }
								}
								html += '</div>';
								o365TestResult.innerHTML = html;
							} else {
								o365TestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							o365TestBtn.disabled = false;
							if (o365TestSpinner) { o365TestSpinner.style.display = 'none'; }
							if (o365TestResult) {
								o365TestResult.style.display = 'block';
								o365TestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Office 365: Test Auto-Reply button.
			var o365AutoReplyBtn     = document.getElementById('office365_test_auto_reply_btn');
			var o365AutoReplySpinner = document.getElementById('office365_test_auto_reply_spinner');
			var o365AutoReplyResult  = document.getElementById('office365_test_auto_reply_result');
			if (o365AutoReplyBtn) {
				o365AutoReplyBtn.addEventListener('click', function() {
					var msgEl       = document.getElementById('office365_test_auto_reply_msg');
					var recipientEl = document.getElementById('office365_test_auto_reply_recipient');
					var msg         = msgEl       ? msgEl.value.trim()       : '';
					var recipient   = recipientEl ? recipientEl.value.trim() : '';

					if (!msg) {
						if (o365AutoReplyResult) {
							o365AutoReplyResult.style.display = 'block';
							o365AutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					o365AutoReplyBtn.disabled = true;
					if (o365AutoReplySpinner) { o365AutoReplySpinner.style.display = 'inline-block'; }
					if (o365AutoReplyResult)  { o365AutoReplyResult.style.display = 'none'; o365AutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_office365_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_office365_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (recipient) { data.append('test_recipient', recipient); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							o365AutoReplyBtn.disabled = false;
							if (o365AutoReplySpinner) { o365AutoReplySpinner.style.display = 'none'; }
							if (!o365AutoReplyResult) { return; }
							o365AutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #d83b01;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ <?php echo esc_js( __( 'Reply sent to the recipient via Outlook.', 'mcp-ai-wpoos-pro' ) ); ?></p>';
								} else if (recipient && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ <?php echo esc_js( __( 'AI reply generated but sending via Outlook failed.', 'mcp-ai-wpoos-pro' ) ); ?>' + sendErr + '</p>';
								}
								html += '</div>';
								o365AutoReplyResult.innerHTML = html;
							} else {
								o365AutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							o365AutoReplyBtn.disabled = false;
							if (o365AutoReplySpinner) { o365AutoReplySpinner.style.display = 'none'; }
							if (o365AutoReplyResult) {
								o365AutoReplyResult.style.display = 'block';
								o365AutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// iCloud: inline Test Connection button.
			var icloudTestBtn     = document.getElementById('icloud_test_connection_btn');
			var icloudTestSpinner = document.getElementById('icloud_test_spinner');
			var icloudTestResult  = document.getElementById('icloud_test_result');
			if (icloudTestBtn) {
				icloudTestBtn.addEventListener('click', function() {
					var gatewayUrl   = document.getElementById('icloud_gateway_url').value.trim();
					var apiKey       = document.getElementById('icloud_api_key').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!gatewayUrl && !connectionId) {
						if (icloudTestResult) {
							icloudTestResult.style.display = 'block';
							icloudTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter your Gateway API URL first.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					icloudTestBtn.disabled = true;
					if (icloudTestSpinner) { icloudTestSpinner.style.display = 'inline-block'; }
					if (icloudTestResult)  { icloudTestResult.style.display = 'none'; icloudTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_icloud_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_icloud_live' ) ); ?>);
					if (gatewayUrl)   { data.append('gateway_url', gatewayUrl); }
					if (apiKey)       { data.append('api_key', apiKey); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							icloudTestBtn.disabled = false;
							if (icloudTestSpinner) { icloudTestSpinner.style.display = 'none'; }
							if (!icloudTestResult) { return; }
							icloudTestResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Connection successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && typeof d === 'object') {
									var items = [];
									if (d.gateway_url) { items.push(<?php echo wp_json_encode( __( 'Gateway:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.gateway_url); }
									if (d.status)      { items.push(<?php echo wp_json_encode( __( 'Status:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.status); }
									if (d.message)     { items.push(d.message); }
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) { html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>'; }
								}
								html += '</div>';
								icloudTestResult.innerHTML = html;
							} else {
								icloudTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							icloudTestBtn.disabled = false;
							if (icloudTestSpinner) { icloudTestSpinner.style.display = 'none'; }
							if (icloudTestResult) {
								icloudTestResult.style.display = 'block';
								icloudTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// iCloud: Test Auto-Reply button.
			var icloudAutoReplyBtn     = document.getElementById('icloud_test_auto_reply_btn');
			var icloudAutoReplySpinner = document.getElementById('icloud_test_auto_reply_spinner');
			var icloudAutoReplyResult  = document.getElementById('icloud_test_auto_reply_result');
			if (icloudAutoReplyBtn) {
				icloudAutoReplyBtn.addEventListener('click', function() {
					var msgEl = document.getElementById('icloud_test_auto_reply_msg');
					var msg   = msgEl ? msgEl.value.trim() : '';

					if (!msg) {
						if (icloudAutoReplyResult) {
							icloudAutoReplyResult.style.display = 'block';
							icloudAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					icloudAutoReplyBtn.disabled = true;
					if (icloudAutoReplySpinner) { icloudAutoReplySpinner.style.display = 'inline-block'; }
					if (icloudAutoReplyResult)  { icloudAutoReplyResult.style.display = 'none'; icloudAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_icloud_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_icloud_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							icloudAutoReplyBtn.disabled = false;
							if (icloudAutoReplySpinner) { icloudAutoReplySpinner.style.display = 'none'; }
							if (!icloudAutoReplyResult) { return; }
							icloudAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #3693f5;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								html += '</div>';
								icloudAutoReplyResult.innerHTML = html;
							} else {
								icloudAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							icloudAutoReplyBtn.disabled = false;
							if (icloudAutoReplySpinner) { icloudAutoReplySpinner.style.display = 'none'; }
							if (icloudAutoReplyResult) {
								icloudAutoReplyResult.style.display = 'block';
								icloudAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Slack: Test Bot Token button.
			var slackTestBtn     = document.getElementById('slack_test_connection_btn');
			var slackTestSpinner = document.getElementById('slack_test_spinner');
			var slackTestResult  = document.getElementById('slack_test_result');
			if (slackTestBtn) {
				slackTestBtn.addEventListener('click', function() {
					var botToken     = document.getElementById('slack_bot_token').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					slackTestBtn.disabled = true;
					if (slackTestSpinner) { slackTestSpinner.style.display = 'inline-block'; }
					if (slackTestResult) { slackTestResult.style.display = 'none'; slackTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_slack_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_slack_live' ) ); ?>);
					if (botToken) { data.append('bot_token', botToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							slackTestBtn.disabled = false;
							if (slackTestSpinner) { slackTestSpinner.style.display = 'none'; }
							if (!slackTestResult) { return; }
							slackTestResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								// Auto-populate the hidden slack_bot_user_id field so the bot's
								// Slack user ID is saved with the connection. Used to detect
								// native Slack @mentions (<@USER_ID>) in incoming messages.
								if (d && d.user_id) {
									var botUserIdEl = document.getElementById('slack_bot_user_id');
									if (botUserIdEl) { botUserIdEl.value = d.user_id; }
								}
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Slack connection successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d) {
									html += '<ul style="margin:8px 0;padding-left:20px;">';
									if (d.team)    { html += '<li>' + <?php echo wp_json_encode( __( 'Team:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.team + '</li>'; }
									if (d.bot_user) { html += '<li>' + <?php echo wp_json_encode( __( 'Bot User:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.bot_user + (d.user_id ? ' (<code>' + d.user_id + '</code>)' : '') + '</li>'; }
									if (d.team_id)  { html += '<li>' + <?php echo wp_json_encode( __( 'Team ID:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.team_id + '</code></li>'; }
									html += '</ul>';
									// Warn if the signing secret field is empty — without it,
									// HMAC validation will reject all Slack webhook events.
									var signingSecretEl = document.getElementById('slack_signing_secret');
									var signingSecretEmpty = !signingSecretEl || signingSecretEl.value.trim() === '';
									var signingSecretSaved = <?php echo wp_json_encode( $is_edit && ! empty( $connection['signing_secret'] ) && 'slack' === ( $connection['connection_type'] ?? '' ) ); ?>;
									if (signingSecretEmpty && !signingSecretSaved) {
										html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">&#9888; ' + <?php echo wp_json_encode( __( 'Signing Secret is not yet configured. Without it, all Slack webhook events are rejected and the bot will not respond. Enter the Signing Secret from your Slack app\'s Basic Information page.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
									}
								}
								html += '</div>';
								slackTestResult.innerHTML = html;
							} else {
								slackTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							slackTestBtn.disabled = false;
							if (slackTestSpinner) { slackTestSpinner.style.display = 'none'; }
							if (slackTestResult) {
								slackTestResult.style.display = 'block';
								slackTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Slack: Test Auto-Reply button.
			var slackAutoReplyBtn     = document.getElementById('slack_test_auto_reply_btn');
			var slackAutoReplySpinner = document.getElementById('slack_test_auto_reply_spinner');
			var slackAutoReplyResult  = document.getElementById('slack_test_auto_reply_result');
			if (slackAutoReplyBtn) {
				slackAutoReplyBtn.addEventListener('click', function() {
					var msgEl     = document.getElementById('slack_test_auto_reply_msg');
					var channelEl = document.getElementById('slack_test_auto_reply_channel');
					var msg       = msgEl     ? msgEl.value.trim()     : '';
					var channel   = channelEl ? channelEl.value.trim() : '';

					if (!msg) {
						if (slackAutoReplyResult) {
							slackAutoReplyResult.style.display = 'block';
							slackAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					slackAutoReplyBtn.disabled = true;
					if (slackAutoReplySpinner) { slackAutoReplySpinner.style.display = 'inline-block'; }
					if (slackAutoReplyResult)  { slackAutoReplyResult.style.display = 'none'; slackAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_slack_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_slack_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (channel) { data.append('test_channel', channel); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							slackAutoReplyBtn.disabled = false;
							if (slackAutoReplySpinner) { slackAutoReplySpinner.style.display = 'none'; }
							if (!slackAutoReplyResult) { return; }
							slackAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #4A154B;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ ' + <?php echo wp_json_encode( __( 'Reply sent to the Slack channel.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
								} else if (channel && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ ' + <?php echo wp_json_encode( __( 'AI reply generated but sending to Slack failed.', 'mcp-ai-wpoos-pro' ) ); ?> + sendErr + '</p>';
								}
								html += '</div>';
								slackAutoReplyResult.innerHTML = html;
							} else {
								slackAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							slackAutoReplyBtn.disabled = false;
							if (slackAutoReplySpinner) { slackAutoReplySpinner.style.display = 'none'; }
							if (slackAutoReplyResult) {
								slackAutoReplyResult.style.display = 'block';
								slackAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Discord: Test Bot Token button.
			var discordTestBtn     = document.getElementById('discord_test_connection_btn');
			var discordTestSpinner = document.getElementById('discord_test_spinner');
			var discordTestResult  = document.getElementById('discord_test_result');
			if (discordTestBtn) {
				discordTestBtn.addEventListener('click', function() {
					var botToken     = document.getElementById('discord_bot_token').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					discordTestBtn.disabled = true;
					if (discordTestSpinner) { discordTestSpinner.style.display = 'inline-block'; }
					if (discordTestResult) { discordTestResult.style.display = 'none'; discordTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_discord_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_discord_live' ) ); ?>);
					if (botToken) { data.append('bot_token', botToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							discordTestBtn.disabled = false;
							if (discordTestSpinner) { discordTestSpinner.style.display = 'none'; }
							if (!discordTestResult) { return; }
							discordTestResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Discord connection successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d) {
									html += '<ul style="margin:8px 0;padding-left:20px;">';
									if (d.bot_username) { html += '<li>' + <?php echo wp_json_encode( __( 'Bot:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + d.bot_username + '</li>'; }
									if (d.bot_id)       { html += '<li>' + <?php echo wp_json_encode( __( 'Bot ID:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + d.bot_id + '</code></li>'; }
									html += '</ul>';
								}
								html += '</div>';
								discordTestResult.innerHTML = html;
							} else {
								discordTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							discordTestBtn.disabled = false;
							if (discordTestSpinner) { discordTestSpinner.style.display = 'none'; }
							if (discordTestResult) {
								discordTestResult.style.display = 'block';
								discordTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Discord: Test Auto-Reply button.
			var discordAutoReplyBtn     = document.getElementById('discord_test_auto_reply_btn');
			var discordAutoReplySpinner = document.getElementById('discord_test_auto_reply_spinner');
			var discordAutoReplyResult  = document.getElementById('discord_test_auto_reply_result');
			if (discordAutoReplyBtn) {
				discordAutoReplyBtn.addEventListener('click', function() {
					var msgEl     = document.getElementById('discord_test_auto_reply_msg');
					var channelEl = document.getElementById('discord_test_auto_reply_channel');
					var msg       = msgEl     ? msgEl.value.trim()     : '';
					var channel   = channelEl ? channelEl.value.trim() : '';

					if (!msg) {
						if (discordAutoReplyResult) {
							discordAutoReplyResult.style.display = 'block';
							discordAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					discordAutoReplyBtn.disabled = true;
					if (discordAutoReplySpinner) { discordAutoReplySpinner.style.display = 'inline-block'; }
					if (discordAutoReplyResult)  { discordAutoReplyResult.style.display = 'none'; discordAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_discord_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_discord_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					if (channel) { data.append('test_channel', channel); }
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							discordAutoReplyBtn.disabled = false;
							if (discordAutoReplySpinner) { discordAutoReplySpinner.style.display = 'none'; }
							if (!discordAutoReplyResult) { return; }
							discordAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #5865F2;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								if (d && d.sent) {
									html += '<p style="margin:4px 0 0;color:#00a32a;font-size:13px;">✓ ' + <?php echo wp_json_encode( __( 'Reply sent to the Discord channel.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
								} else if (channel && d && !d.sent) {
									var sendErr = (d && d.send_error) ? ' (' + d.send_error + ')' : '';
									html += '<p style="margin:4px 0 0;color:#d63638;font-size:13px;">⚠ ' + <?php echo wp_json_encode( __( 'AI reply generated but sending to Discord failed.', 'mcp-ai-wpoos-pro' ) ); ?> + sendErr + '</p>';
								}
								html += '</div>';
								discordAutoReplyResult.innerHTML = html;
							} else {
								discordAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							discordAutoReplyBtn.disabled = false;
							if (discordAutoReplySpinner) { discordAutoReplySpinner.style.display = 'none'; }
							if (discordAutoReplyResult) {
								discordAutoReplyResult.style.display = 'block';
								discordAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Microsoft Teams: Test Graph Token button.
			var teamsTestBtn     = document.getElementById('teams_test_connection_btn');
			var teamsTestSpinner = document.getElementById('teams_test_spinner');
			var teamsTestResult  = document.getElementById('teams_test_result');
			if (teamsTestBtn) {
				teamsTestBtn.addEventListener('click', function() {
					var graphToken   = document.getElementById('teams_graph_token').value.trim();
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					teamsTestBtn.disabled = true;
					if (teamsTestSpinner) { teamsTestSpinner.style.display = 'inline-block'; }
					if (teamsTestResult) { teamsTestResult.style.display = 'none'; teamsTestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_teams_live');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_teams_live' ) ); ?>);
					if (graphToken) { data.append('graph_token', graphToken); }
					if (connectionId) { data.append('connection_id', connectionId); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							teamsTestBtn.disabled = false;
							if (teamsTestSpinner) { teamsTestSpinner.style.display = 'none'; }
							if (!teamsTestResult) { return; }
							teamsTestResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Teams connection successful!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.display_name) {
									html += '<p style="margin:6px 0 0;">' + <?php echo wp_json_encode( __( 'Authenticated as:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <strong>' + d.display_name + '</strong></p>';
								}
								html += '</div>';
								teamsTestResult.innerHTML = html;
							} else {
								teamsTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Connection test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							teamsTestBtn.disabled = false;
							if (teamsTestSpinner) { teamsTestSpinner.style.display = 'none'; }
							if (teamsTestResult) {
								teamsTestResult.style.display = 'block';
								teamsTestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Microsoft Teams: Test Auto-Reply button.
			var teamsAutoReplyBtn     = document.getElementById('teams_test_auto_reply_btn');
			var teamsAutoReplySpinner = document.getElementById('teams_test_auto_reply_spinner');
			var teamsAutoReplyResult  = document.getElementById('teams_test_auto_reply_result');
			if (teamsAutoReplyBtn) {
				teamsAutoReplyBtn.addEventListener('click', function() {
					var msgEl = document.getElementById('teams_test_auto_reply_msg');
					var msg   = msgEl ? msgEl.value.trim() : '';

					if (!msg) {
						if (teamsAutoReplyResult) {
							teamsAutoReplyResult.style.display = 'block';
							teamsAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Please enter a test message.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					teamsAutoReplyBtn.disabled = true;
					if (teamsAutoReplySpinner) { teamsAutoReplySpinner.style.display = 'inline-block'; }
					if (teamsAutoReplyResult)  { teamsAutoReplyResult.style.display = 'none'; teamsAutoReplyResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_test_teams_auto_reply');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_test_teams_auto_reply' ) ); ?>);
					data.append('test_message', msg);
					var connIdEl = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					if (connIdEl) { data.append('connection_id', connIdEl.value); }

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							teamsAutoReplyBtn.disabled = false;
							if (teamsAutoReplySpinner) { teamsAutoReplySpinner.style.display = 'none'; }
							if (!teamsAutoReplyResult) { return; }
							teamsAutoReplyResult.style.display = 'block';
							if (result.success) {
								var d    = result.data;
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'AI reply generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								if (d && d.ai_reply) {
									html += '<blockquote style="margin:8px 0 4px 16px;border-left:3px solid #6264A7;padding-left:8px;white-space:pre-wrap;">' + d.ai_reply.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</blockquote>';
								}
								html += '</div>';
								teamsAutoReplyResult.innerHTML = html;
							} else {
								teamsAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Auto-reply test failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							teamsAutoReplyBtn.disabled = false;
							if (teamsAutoReplySpinner) { teamsAutoReplySpinner.style.display = 'none'; }
							if (teamsAutoReplyResult) {
								teamsAutoReplyResult.style.display = 'block';
								teamsAutoReplyResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Microsoft Teams: Generate Manifest button.
			const teamsManifestBtn     = document.getElementById('teams_generate_manifest_btn');
			const teamsManifestSpinner = document.getElementById('teams_manifest_spinner');
			const teamsManifestResult  = document.getElementById('teams_manifest_result');
			if (teamsManifestBtn) {
				teamsManifestBtn.addEventListener('click', function() {
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!connectionId) {
						if (teamsManifestResult) {
							teamsManifestResult.style.display = 'block';
							teamsManifestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Save the connection first, then generate the manifest.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					teamsManifestBtn.disabled = true;
					if (teamsManifestSpinner) { teamsManifestSpinner.style.display = 'inline-block'; }
					if (teamsManifestResult)  { teamsManifestResult.style.display = 'none'; teamsManifestResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_generate_teams_manifest');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_generate_teams_manifest' ) ); ?>);
					data.append('connection_id', connectionId);

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							teamsManifestBtn.disabled = false;
							if (teamsManifestSpinner) { teamsManifestSpinner.style.display = 'none'; }
							if (!teamsManifestResult) { return; }
							teamsManifestResult.style.display = 'block';
							if (result.success) {
								var manifestJson = JSON.stringify(result.data.manifest, null, 2);
								var blob = new Blob([manifestJson], { type: 'application/json' });
								var url  = URL.createObjectURL(blob);
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'Manifest generated!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								html += '<p style="margin:6px 0 0;"><a href="' + url + '" download="declarativeAgent.json" class="button button-primary">' + <?php echo wp_json_encode( __( 'Download declarativeAgent.json', 'mcp-ai-wpoos-pro' ) ); ?> + '</a></p>';
								html += '<p style="margin:8px 0 0;">' + <?php echo wp_json_encode( __( 'Upload this file to the Microsoft Teams Developer Portal to register a declarative agent.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
								html += '</div>';
								teamsManifestResult.innerHTML = html;
								setTimeout(function() { URL.revokeObjectURL(url); }, 60000);
							} else {
								teamsManifestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Manifest generation failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							teamsManifestBtn.disabled = false;
							if (teamsManifestSpinner) { teamsManifestSpinner.style.display = 'none'; }
							if (teamsManifestResult) {
								teamsManifestResult.style.display = 'block';
								teamsManifestResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}


			// Microsoft Teams: Download App Package button.
			const teamsAppPackageBtn     = document.getElementById('teams_generate_app_package_btn');
			const teamsAppPackageSpinner = document.getElementById('teams_app_package_spinner');
			const teamsAppPackageResult  = document.getElementById('teams_app_package_result');
			if (teamsAppPackageBtn) {
				teamsAppPackageBtn.addEventListener('click', function() {
					var connIdEl     = document.getElementById('connection_id') || document.querySelector('input[name="connection_id"]');
					var connectionId = connIdEl ? connIdEl.value.trim() : '';

					if (!connectionId) {
						if (teamsAppPackageResult) {
							teamsAppPackageResult.style.display = 'block';
							teamsAppPackageResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Save the connection first, then download the app package.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
						}
						return;
					}

					teamsAppPackageBtn.disabled = true;
					if (teamsAppPackageSpinner) { teamsAppPackageSpinner.style.display = 'inline-block'; }
					if (teamsAppPackageResult)  { teamsAppPackageResult.style.display = 'none'; teamsAppPackageResult.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_generate_teams_app_package');
					data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'wp_mcp_ai_generate_teams_app_package' ) ); ?>);
					data.append('connection_id', connectionId);

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							teamsAppPackageBtn.disabled = false;
							if (teamsAppPackageSpinner) { teamsAppPackageSpinner.style.display = 'none'; }
							if (!teamsAppPackageResult) { return; }
							teamsAppPackageResult.style.display = 'block';
							if (result.success) {
								var d = result.data;
								var byteArray = Uint8Array.from(atob(d.zip_base64), function(c) { return c.charCodeAt(0); });
								var blob = new Blob([byteArray], { type: 'application/zip' });
								var url  = URL.createObjectURL(blob);
								var html = '<div class="notice notice-success inline" style="margin:0;"><p><strong>' + <?php echo wp_json_encode( __( 'App package ready!', 'mcp-ai-wpoos-pro' ) ); ?> + '</strong></p>';
								html += '<p style="margin:6px 0 4px;"><a href="' + url + '" download="' + (d.filename || 'teams-bot.zip') + '" class="button button-primary">' + <?php echo wp_json_encode( __( 'Download teams-bot.zip', 'mcp-ai-wpoos-pro' ) ); ?> + '</a></p>';
								html += '<p style="margin:6px 0 0;font-size:12px;">' + <?php echo wp_json_encode( __( 'Upload this .zip via Teams Developer Portal → Apps → Import app, or sideload it directly into a Teams channel.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p>';
								html += '</div>';
								teamsAppPackageResult.innerHTML = html;
								setTimeout(function() { URL.revokeObjectURL(url); }, 60000);
							} else {
								teamsAppPackageResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'App package generation failed.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							teamsAppPackageBtn.disabled = false;
							if (teamsAppPackageSpinner) { teamsAppPackageSpinner.style.display = 'none'; }
							if (teamsAppPackageResult) {
								teamsAppPackageResult.style.display = 'block';
								teamsAppPackageResult.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'mcp-ai-wpoos-pro' ) ); ?> + '</p></div>';
							}
						});
				});
			}

			// Copy-to-clipboard buttons for webhook URLs and other read-only fields.
			document.querySelectorAll('.wp-mcp-ai-copy-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var targetId = btn.getAttribute('data-copy-target');
					var targetEl = targetId ? document.getElementById(targetId) : null;
					if (!targetEl) { return; }
					var textToCopy = targetEl.value || targetEl.innerText || '';
					if (!textToCopy) { return; }
					var originalHtml = btn.innerHTML;
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(textToCopy).then(function() {
							btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> <?php echo esc_js( __( 'Copied!', 'mcp-ai-wpoos-pro' ) ); ?>';
							setTimeout(function() { btn.innerHTML = originalHtml; }, 2000);
						}).catch(function() {
							targetEl.select();
							document.execCommand('copy');
						});
					} else {
						targetEl.select();
						try {
							document.execCommand('copy');
							btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> <?php echo esc_js( __( 'Copied!', 'mcp-ai-wpoos-pro' ) ); ?>';
							setTimeout(function() { btn.innerHTML = originalHtml; }, 2000);
						} catch (e) { /* ignore */ }
					}
				});
			});

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

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
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
									if (d.slack) {
										var escH = function(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
										if (d.team)        { items.push(<?php echo wp_json_encode( __( 'Team:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + escH(String(d.team)) + (d.team_id ? ' (<code>' + escH(String(d.team_id)) + '</code>)' : '')); }
										if (d.bot_user)    { items.push(<?php echo wp_json_encode( __( 'Bot User:', 'mcp-ai-wpoos-pro' ) ); ?> + ' ' + escH(String(d.bot_user)) + (d.bot_user_id ? ' (<code>' + escH(String(d.bot_user_id)) + '</code>)' : '')); }
										if (d.webhook_url) { items.push(<?php echo wp_json_encode( __( 'Event Subscriptions URL:', 'mcp-ai-wpoos-pro' ) ); ?> + ' <code>' + escH(String(d.webhook_url)) + '</code>'); }
									}
									if (d.message && !d.phone_number && !d.site_name && !d.slack) { items.push(d.message); }
									if (items.length) {
										html += '<ul style="margin:8px 0;padding-left:20px;">';
										items.forEach(function(item) { html += '<li>' + item + '</li>'; });
										html += '</ul>';
									}
									if (d.warning) { html += '<p style="margin:6px 0 0;color:#b45309;font-size:13px;">⚠ ' + d.warning + '</p>'; }
									if (d.quality_note) { html += '<p style="margin:6px 0 0;color:#2271b1;font-size:13px;">ℹ ' + d.quality_note + '</p>'; }
									if (d.notice) { html += '<p style="margin:6px 0 0;color:#2271b1;font-size:13px;">ℹ ' + d.notice + '</p>'; }
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

			// JetEngine CCT discovery button.
			var jeDiscoverBtn = document.getElementById('je_discover_ccts_btn');
			if (jeDiscoverBtn) {
				jeDiscoverBtn.addEventListener('click', function() {
					var connectionId = jeDiscoverBtn.getAttribute('data-connection-id');
					var nonce        = jeDiscoverBtn.getAttribute('data-nonce');
					var spinner      = document.getElementById('je_discover_spinner');
					var resultDiv    = document.getElementById('je_discover_result');

					jeDiscoverBtn.disabled = true;
					if (spinner) { spinner.style.display = 'inline-block'; }
					if (resultDiv) { resultDiv.style.display = 'none'; resultDiv.innerHTML = ''; }

					var data = new FormData();
					data.append('action', 'wp_mcp_ai_discover_jetengine_ccts');
					data.append('nonce', nonce);
					data.append('connection_id', connectionId);

					fetch(wpMcpAiAjax, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(response) {
							if (!response.ok) { throw new Error('HTTP ' + response.status); }
							return response.json();
						})
						.then(function(result) {
							jeDiscoverBtn.disabled = false;
							if (spinner) { spinner.style.display = 'none'; }
							if (!resultDiv) { return; }
							resultDiv.style.display = 'block';

							if (result.success && result.data && result.data.ccts && result.data.ccts.length > 0) {
								var tbody = document.getElementById('je_access_tbody');
								var existingRows = {};
								// Preserve existing row selections.
								if (tbody) {
									var rows = tbody.querySelectorAll('tr');
									rows.forEach(function(row) {
										var nameCell = row.querySelector('td:first-child strong');
										if (nameCell) {
											var slug = nameCell.textContent.trim();
											var checks = row.querySelectorAll('input[type=checkbox]');
											existingRows[slug] = [];
											checks.forEach(function(cb, i) {
												existingRows[slug].push(cb.checked);
											});
										}
									});
								}

								if (tbody) {
									tbody.innerHTML = '';
									result.data.ccts.forEach(function(cct) {
										var prev = existingRows[cct.slug] || [true, false, false, false];
										var tr = document.createElement('tr');
										tr.innerHTML =
											'<td><strong>' + cct.slug + '</strong>' + (cct.label && cct.label !== cct.slug ? ' <small>(' + cct.label + ')</small>' : '') + '</td>' +
											'<td><input type="checkbox" name="je_' + cct.slug + '_read" value="1"' + (prev[0] ? ' checked' : '') + '></td>' +
											'<td><input type="checkbox" name="je_' + cct.slug + '_create" value="1"' + (prev[1] ? ' checked' : '') + '></td>' +
											'<td><input type="checkbox" name="je_' + cct.slug + '_update" value="1"' + (prev[2] ? ' checked' : '') + '></td>' +
											'<td><input type="checkbox" name="je_' + cct.slug + '_delete" value="1"' + (prev[3] ? ' checked' : '') + '></td>';
										tbody.appendChild(tr);
									});
								}

								resultDiv.innerHTML = '<div class="notice notice-success inline" style="margin:0;"><p>' +
									<?php echo wp_json_encode( __( 'Discovered CCTs from remote site. Check the operations you want to allow, then save the connection.', 'mcp-ai-wpoos-pro' ) ); ?> +
									'</p></div>';
							} else if (result.success) {
								resultDiv.innerHTML = '<div class="notice notice-warning inline" style="margin:0;"><p>' +
									<?php echo wp_json_encode( __( 'No JetEngine CCTs found on the remote site. Ensure JetEngine is installed and at least one CCT has REST endpoints enabled (JetEngine → Custom Content Types → Edit CCT → enable "Register get items/item REST API Endpoint").', 'mcp-ai-wpoos-pro' ) ); ?> +
									'</p></div>';
							} else {
								resultDiv.innerHTML = '<div class="notice notice-error inline" style="margin:0;"><p>' + (result.data || <?php echo wp_json_encode( __( 'Discovery failed. The remote site may not have JetEngine REST endpoints enabled.', 'mcp-ai-wpoos-pro' ) ); ?>) + '</p></div>';
							}
						})
						.catch(function() {
							jeDiscoverBtn.disabled = false;
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
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( /* translators: %s: OAuth error from Google */ __( 'Google OAuth error: %s', 'mcp-ai-wpoos-pro' ), $error ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
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
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( /* translators: %s: OAuth error from Google */ __( 'Google OAuth error: %s', 'mcp-ai-wpoos-pro' ), $error ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
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
	 * Handle Google Chat OAuth start for a remote connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_google_chat_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'google_chat' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not a Google Chat connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Please save the OAuth Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Generate OAuth state and store connection ID.
		$state     = wp_generate_uuid4();
		$transient = 'wp_mcp_ai_google_chat_oauth_state_' . md5( $state );

		set_transient(
			$transient,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$params = array(
			'client_id'              => $connection['client_id'],
			'redirect_uri'           => add_query_arg(
				array(
					'page'          => 'wp-mcp-ai-remote-sites',
					'oauth_handler' => 'google_chat_oauth_callback',
				),
				admin_url( 'admin.php' )
			),
			'response_type'          => 'code',
			'scope'                  => 'https://www.googleapis.com/auth/chat.messages https://www.googleapis.com/auth/chat.spaces.readonly',
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);

		$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle Google Chat OAuth callback for a remote connection.
	 *
	 * @since 1.0.0
	 */
	protected function handle_google_chat_oauth_callback() {
		// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( /* translators: %s: OAuth error from Google */ __( 'Google OAuth error: %s', 'mcp-ai-wpoos-pro' ), $error ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_google_chat_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
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
							'oauth_handler' => 'google_chat_oauth_callback',
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

		// Update the connection with the new refresh token.
		$update_data = array(
			'id'                     => $connection_id,
			'name'                   => $connection['name'],
			'url'                    => $connection['url'],
			'connection_type'        => 'google_chat',
			'auth_type'              => 'none',
			'client_id'              => $connection['client_id'],
			'client_secret'          => '', // Keep existing (don't re-encrypt).
			'refresh_token'          => $refresh_token,
			'enabled'                => $connection['enabled'],
			'google_chat_space'      => isset( $connection['google_chat_space'] ) ? $connection['google_chat_space'] : '',
			'verify_token'           => isset( $connection['verify_token'] ) ? $connection['verify_token'] : '',
			'assigned_assistant_ids' => isset( $connection['assigned_assistant_ids'] ) ? $connection['assigned_assistant_ids'] : array(),
		);

		// Preserve encrypted client_secret and api_key.
		$update_data['_client_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		$success_message = __( 'Google Chat connected successfully!', 'mcp-ai-wpoos-pro' );
		if ( $email_address ) {
			$success_message = sprintf(
				/* translators: %s: email address */
				__( 'Google Chat connected successfully for %s!', 'mcp-ai-wpoos-pro' ),
				$email_address
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( $success_message ) ) );
		exit;
	}
	/**
	 * Handle Microsoft Teams OAuth 2.0 start for a remote connection.
	 *
	 * Redirects to the Microsoft identity platform to begin the OAuth 2.0
	 * authorization code flow. The tenant is taken from the stored tenant_id
	 * (single-tenant) or falls back to "common" (multi-tenant).
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_teams_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'microsoft_teams' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not a Microsoft Teams connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Please save the Azure AD Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Generate OAuth state and store connection ID in a short-lived transient (CSRF protection).
		$state     = wp_generate_uuid4();
		$transient = 'wp_mcp_ai_teams_oauth_state_' . md5( $state );

		set_transient(
			$transient,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		// Use the stored tenant_id for single-tenant apps; fall back to "common" for multi-tenant.
		$tenant = ! empty( $connection['tenant_id'] ) ? sanitize_text_field( $connection['tenant_id'] ) : 'common';

		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'teams_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Microsoft Graph scopes required for Teams bot reply functionality.
		$scopes = implode(
			' ',
			array(
				'https://graph.microsoft.com/Chat.ReadWrite',
				'https://graph.microsoft.com/ChannelMessage.Send',
				'offline_access',
				'openid',
				'profile',
				'email',
			)
		);

		$params = array(
			'client_id'     => $connection['client_id'],
			'response_type' => 'code',
			'redirect_uri'  => $redirect_uri,
			'scope'         => $scopes,
			'response_mode' => 'query',
			'state'         => $state,
			'prompt'        => 'select_account',
		);

		$authorize_url = add_query_arg(
			$params,
			'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/authorize'
		);

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle Microsoft Teams OAuth 2.0 callback.
	 *
	 * Exchanges the authorization code for an access token and refresh token,
	 * then stores them in the connection record. The access token is saved to
	 * the existing `token` field (used by the Teams webhook controller) and the
	 * refresh token to the `refresh_token` field for automatic renewal.
	 *
	 * @since 1.0.0
	 */
	protected function handle_teams_oauth_callback() {
		// OAuth callback parameters from Microsoft. No nonce verification required – state provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

		if ( $error ) {
			$message = $error_description ? $error_description : $error;
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( sprintf( /* translators: %s: OAuth error from Microsoft */ __( 'Microsoft OAuth error: %s', 'mcp-ai-wpoos-pro' ), $message ) ) ) );
			exit;
		}

		$transient_key = 'wp_mcp_ai_teams_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $code ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'No authorization code received from Microsoft.', 'mcp-ai-wpoos-pro' ) ) ) );
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

		$tenant = ! empty( $connection['tenant_id'] ) ? sanitize_text_field( $connection['tenant_id'] ) : 'common';

		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'teams_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Exchange authorization code for tokens.
		$response = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $connection['client_id'],
					'client_secret' => $client_secret,
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
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
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Microsoft rejected the authorization. Please check your Azure AD app configuration.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Invalid response from Microsoft.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';
		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$expires_in    = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;

		if ( '' === $access_token ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'No access token received from Microsoft.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// If no refresh token returned, reuse the existing one (Microsoft may not return it on every auth).
		if ( '' === $refresh_token && ! empty( $connection['refresh_token'] ) ) {
			$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] );
		}

		// Retrieve the signed-in user's display name and email from Microsoft Graph.
		$display_name  = '';
		$email_address = '';
		$me_response   = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( ! is_wp_error( $me_response ) && 200 === (int) wp_remote_retrieve_response_code( $me_response ) ) {
			$me_body = json_decode( wp_remote_retrieve_body( $me_response ), true );
			if ( is_array( $me_body ) ) {
				$display_name  = isset( $me_body['displayName'] ) ? sanitize_text_field( $me_body['displayName'] ) : '';
				$email_address = isset( $me_body['mail'] ) ? sanitize_email( $me_body['mail'] ) : '';
				if ( ! $email_address && isset( $me_body['userPrincipalName'] ) ) {
					$email_address = sanitize_email( $me_body['userPrincipalName'] );
				}
			}
		}

		// Build the update data preserving all existing connection fields.
		$update_data = array(
			'id'                     => $connection_id,
			'name'                   => $connection['name'],
			'url'                    => $connection['url'],
			'connection_type'        => 'microsoft_teams',
			'auth_type'              => 'none',
			'client_id'              => $connection['client_id'],
			// Empty string + _client_secret_encrypted flag instructs save_connection to
			// preserve the existing encrypted value (same pattern as Google OAuth callbacks).
			'client_secret'          => '',
			'token'                  => $access_token,
			'refresh_token'          => $refresh_token,
			'token_expiry'           => time() + $expires_in,
			'tenant_id'              => isset( $connection['tenant_id'] ) ? $connection['tenant_id'] : '',
			'app_id'                 => isset( $connection['app_id'] ) ? $connection['app_id'] : '',
			// Empty string + _signing_secret_encrypted flag preserves the existing encrypted value.
			'signing_secret'         => '',
			'require_mention'        => ! empty( $connection['require_mention'] ),
			'enabled'                => $connection['enabled'],
			'assigned_assistant_ids' => isset( $connection['assigned_assistant_ids'] ) ? $connection['assigned_assistant_ids'] : array(),
		);

		// Flags tell save_connection to copy the existing encrypted values rather than
		// treating the empty strings above as a request to clear those fields.
		$update_data['_client_secret_encrypted']  = true;
		$update_data['_signing_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		$success_message = __( 'Microsoft Teams connected successfully via OAuth!', 'mcp-ai-wpoos-pro' );
		if ( $display_name || $email_address ) {
			$identity        = $display_name ? $display_name : $email_address;
			$success_message = sprintf(
				/* translators: %s: display name or email address of the authenticated Microsoft account */
				__( 'Microsoft Teams connected successfully for %s! Graph token saved automatically.', 'mcp-ai-wpoos-pro' ),
				$identity
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( $success_message ) ) );
		exit;
	}

	/**
	 * AJAX handler: generate a complete Microsoft Teams Bot app package (.zip).
	 *
	 * Creates a Teams-compatible app package containing:
	 * - manifest.json  (Teams app manifest v1.17 with bot definition)
	 * - color.png      (192×192 colour icon placeholder)
	 * - outline.png    (32×32 outline icon placeholder)
	 *
	 * The .zip is returned as a base64-encoded string so the browser can trigger
	 * a client-side download without a temporary file on disk.
	 *
	 * @since 1.0.0
	 */
	public function ajax_generate_teams_app_package() {
		check_ajax_referer( 'wp_mcp_ai_generate_teams_app_package', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			wp_send_json_error( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Resolve bot name and description.
		$bot_name = isset( $connection['name'] ) && '' !== trim( $connection['name'] )
			? sanitize_text_field( $connection['name'] )
			: get_bloginfo( 'name' );

		$assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		$description = sprintf(
			/* translators: %s: site URL */
			__( 'AI assistant powered by NV oOS at %s', 'mcp-ai-wpoos-pro' ),
			home_url()
		);

		if ( ! empty( $assistant_ids ) ) {
			$assistant_post = get_post( $assistant_ids[0] );
			if ( $assistant_post instanceof WP_Post ) {
				$description = sprintf(
					/* translators: 1: assistant title, 2: site URL */
					__( '%1$s — AI assistant powered by NV oOS at %2$s', 'mcp-ai-wpoos-pro' ),
					$assistant_post->post_title,
					home_url()
				);
			}
		}

		// Use existing App ID as bot ID, or generate a placeholder UUID.
		$bot_id = ! empty( $connection['app_id'] ) ? sanitize_text_field( $connection['app_id'] ) : wp_generate_uuid4();

		$valid_domain = wp_parse_url( home_url(), PHP_URL_HOST );

		// Build the Teams app manifest (schema v1.17).
		$manifest = array(
			'$schema'         => 'https://developer.microsoft.com/en-us/json-schemas/teams/v1.17/MicrosoftTeams.schema.json',
			'manifestVersion' => '1.17',
			'version'         => '1.0.0',
			'id'              => $bot_id,
			'packageName'     => 'com.nvoos.teams.' . $connection_id,
			'developer'       => array(
				'name'          => sanitize_text_field( get_bloginfo( 'name' ) ),
				'websiteUrl'    => home_url(),
				'privacyUrl'    => home_url( '/privacy-policy' ),
				'termsOfUseUrl' => home_url( '/terms-of-service' ),
			),
			'name'            => array(
				'short' => mb_substr( $bot_name, 0, 30 ),
				'full'  => mb_substr( $bot_name . ' — NV oOS AI', 0, 100 ),
			),
			'description'     => array(
				'short' => mb_substr( $bot_name . ' AI assistant', 0, 80 ),
				'full'  => mb_substr( $description, 0, 4000 ),
			),
			'icons'           => array(
				'color'   => 'color.png',
				'outline' => 'outline.png',
			),
			'accentColor'     => '#6264A7',
			'bots'            => array(
				array(
					'botId'                => $bot_id,
					'needsChannelSelector' => false,
					'isNotificationOnly'   => false,
					'scopes'               => array( 'team', 'personal', 'groupchat' ),
					'supportsFiles'        => false,
					'commandLists'         => array(
						array(
							'scopes'   => array( 'team', 'personal', 'groupchat' ),
							'commands' => array(
								array(
									'title'       => __( 'Help', 'mcp-ai-wpoos-pro' ),
									'description' => __( 'Get help from the AI assistant', 'mcp-ai-wpoos-pro' ),
								),
							),
						),
					),
				),
			),
			'permissions'     => array( 'identity', 'messageTeamMembers' ),
			'validDomains'    => array( $valid_domain ),
		);

		/**
		 * Filters the Teams Bot app manifest before packaging.
		 *
		 * @param array  $manifest      The manifest array.
		 * @param string $connection_id The Teams connection ID.
		 * @param array  $connection    The full connection data.
		 */
		$manifest = apply_filters( 'wp_mcp_ai_teams_bot_app_manifest', $manifest, $connection_id, $connection );

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $manifest_json ) {
			wp_send_json_error( __( 'Failed to encode app manifest as JSON.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Build minimal placeholder PNG icons for the Teams app package.
		// color.png  : 192×192 purple placeholder icon (Teams colour icon requirement).
		// outline.png: 32×32  purple outline placeholder icon (Teams outline icon requirement).
		// For production deployments, replace these with a proper branded icon.
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$color_png_b64   = 'iVBORw0KGgoAAAANSUhEUgAAAMAAAADAAQMAAABCs85oAAAABlBMVEViZKcAAADJ9rBRAAAAFElEQVR42mP4z8BQDwQMoxpGNQAAYqkAAV/XhQAAAABJRU5ErkJggg==';
		$outline_png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAMAAABEpIrGAAAABlBMVEX///8AAABVwtN+AAAAGUlEQVR42mP4z8BQDwQMoxpGNQAAcsIAAeMLN78AAAAASUVORK5CYII=';
		$color_png       = base64_decode( $color_png_b64 );
		$outline_png     = base64_decode( $outline_png_b64 );
		// phpcs:enable

		// Package the files into a ZIP archive.
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( __( 'ZipArchive extension is not available on this server. Please install the php-zip extension.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp_file = wp_tempnam( 'teams-bot-' . $connection_id . '.zip' );
		if ( ! $tmp_file ) {
			wp_send_json_error( __( 'Could not create a temporary file. Please check server write permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp file cleanup after failed ZIP open.
			wp_send_json_error( __( 'Could not open ZIP archive for writing.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'color.png', $color_png );
		$zip->addFromString( 'outline.png', $outline_png );
		$zip->close();

		$zip_contents = @file_get_contents( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local generated ZIP in memory.
		@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp file cleanup after ZIP read.

		if ( false === $zip_contents ) {
			wp_send_json_error( __( 'Could not read the generated ZIP file.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$filename = sanitize_file_name( 'teams-bot-' . $connection_id . '.zip' );

		wp_send_json_success(
			array(
				'zip_base64' => base64_encode( $zip_contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'filename'   => $filename,
			)
		);
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
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
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
				$retry_response   = wp_remote_get(
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
		$quality                   = 'UNKNOWN';
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
	/**
	 * AJAX handler: register a WhatsApp Business phone number with the Cloud API.
	 *
	 * Calls POST /{PHONE_NUMBER_ID}/register on the Meta Graph API so that the
	 * phone number can send and receive messages, resolving error #133010
	 * "Account not registered".
	 *
	 * Accepts: pin, connection_id (or access_token + phone_number_id), graph_api_version, nonce (POST).
	 * Returns JSON success or error.
	 *
	 * @since 1.0.0
	 */
	public function ajax_register_whatsapp_phone_number() {
		check_ajax_referer( 'wp_mcp_ai_register_whatsapp_phone_number', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Validate the 6-digit numeric PIN.
		$pin = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : '';
		if ( ! preg_match( '/^[0-9]{6}$/', $pin ) ) {
			wp_send_json_error( __( 'A valid 6-digit two-step verification PIN is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Resolve the access token: prefer the live field value; fall back to stored connection.
		$access_token = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token = trim( (string) $access_token );

		$phone_number_id = isset( $_POST['phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) ) : '';

		if ( empty( $access_token ) || empty( $phone_number_id ) ) {
			$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			if ( ! empty( $connection_id ) ) {
				$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( empty( $access_token ) && ! empty( $stored['api_key'] ) ) {
					$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored['api_key'] );
				}
				if ( empty( $phone_number_id ) && ! empty( $stored['phone_number_id'] ) ) {
					$phone_number_id = sanitize_text_field( $stored['phone_number_id'] );
				}
			}
		}

		if ( empty( $access_token ) ) {
			wp_send_json_error( __( 'Access Token is required. Save the connection first or enter your Access Token in the form.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( empty( $phone_number_id ) ) {
			wp_send_json_error( __( 'Phone Number ID is required. Save the connection first or enter your Phone Number ID in the form.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$raw_version       = isset( $_POST['graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['graph_api_version'] ) ) : '';
		$graph_api_version = ( preg_match( '/^v\d+\.\d+$/', $raw_version ) ) ? $raw_version : 'v19.0';

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/register',
			rawurlencode( $graph_api_version ),
			rawurlencode( $phone_number_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'pin'               => $pin,
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			wp_send_json_error( __( 'Failed to encode the registration request.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_post(
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

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to WhatsApp API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_error = is_array( $decoded ) && isset( $decoded['error'] ) ? $decoded['error'] : array();

		if ( 200 !== $http_code || ! empty( $api_error ) ) {
			$error_message = is_array( $api_error ) && isset( $api_error['message'] ) && is_string( $api_error['message'] )
				? $api_error['message']
				: __( 'Registration request failed.', 'mcp-ai-wpoos-pro' );

			$hint = '';
			if ( is_array( $api_error ) && isset( $api_error['code'] ) ) {
				$meta_code = (int) $api_error['code'];
				if ( 100 === $meta_code && isset( $api_error['error_subcode'] ) && 33 === (int) $api_error['error_subcode'] ) {
					$hint = __( 'Phone Number ID not found. Verify the Phone Number ID in Meta Developer Dashboard (App → WhatsApp → API Setup). This is different from the WhatsApp Business Account ID (WABA ID).', 'mcp-ai-wpoos-pro' );
				} elseif ( 368 === $meta_code || 190 === $meta_code ) {
					$hint = __( 'Invalid or expired access token. Generate a new System User Access Token from Meta Business Suite (Business Settings → System Users) with the whatsapp_business_management permission.', 'mcp-ai-wpoos-pro' );
				} elseif ( 135000 === $meta_code ) {
					$hint = __( 'Incorrect PIN. Please verify your two-step verification PIN and try again. If you have not set a PIN yet, enable two-step verification in the Meta Developer Dashboard for this phone number first.', 'mcp-ai-wpoos-pro' );
				}
			}

			wp_send_json_error(
				array(
					'message' => esc_html( $error_message ),
					'hint'    => $hint,
				)
			);
			return;
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler to test WhatsApp auto-reply functionality.
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
							} elseif ( 133010 === $meta_code ) {
								$result['send_error_hint'] = __( 'Your WhatsApp Business phone number is not yet registered with the Cloud API. Use the Register Phone Number button in this connection\'s settings to complete registration. Before registering, ensure the number is not active on the WhatsApp or WhatsApp Business app (delete the account from the app first), and if it was previously on the on-premises API, deregister it there first. See the official Meta documentation: https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/registration', 'mcp-ai-wpoos-pro' );
							} elseif ( 190 === $meta_code ) {
								// Token is invalid or expired — attempt automatic refresh then retry.
								$refreshed_token = WP_MCP_AI_Pro_Remote_Site_Manager::refresh_whatsapp_token(
									$connection,
									$connection_id,
									$access_token,
									$graph_api_version
								);

								if ( false !== $refreshed_token ) {
									$retry_payload = wp_json_encode( $payload );
									if ( false !== $retry_payload ) {
										$retry_result = wp_remote_post(
											$endpoint,
											array(
												'headers' => array(
													'Content-Type'  => 'application/json',
													'Authorization' => 'Bearer ' . $refreshed_token,
												),
												'timeout' => 20,
												'body'    => $retry_payload,
											)
										);

										if ( ! is_wp_error( $retry_result ) && 200 === (int) wp_remote_retrieve_response_code( $retry_result ) ) {
											$result['sent'] = true;
											unset( $result['send_error'] );
										}
									}
								}

								if ( ! $result['sent'] ) {
									if ( 463 === $meta_subcode ) {
										$result['send_error_hint'] = __( 'Your WhatsApp access token has expired. Automatic refresh was attempted but failed. To enable automatic refresh, add your Meta App ID to this connection\'s settings and ensure the App has admin access to the System User. Alternatively, generate a new permanent System User Access Token from Meta Business Suite (Business Settings → System Users) with the whatsapp_business_messaging and whatsapp_business_management permissions.', 'mcp-ai-wpoos-pro' );
									} else {
										$result['send_error_hint'] = __( 'Your WhatsApp access token is invalid or expired. Automatic refresh was attempted but failed. To enable automatic refresh, add your Meta App ID to this connection\'s settings and ensure the App has admin access to the System User. Alternatively, generate a new permanent System User Access Token from Meta Business Suite (Business Settings → System Users) with the whatsapp_business_messaging and whatsapp_business_management permissions.', 'mcp-ai-wpoos-pro' );
									}
								}
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

		$response = wp_remote_post(
			'https://graph.facebook.com/oauth/access_token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => array(
					'client_id'     => $app_id,
					'client_secret' => $app_secret,
					'grant_type'    => 'client_credentials',
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
	 * Verifies the access token by calling GET /me on the Meta Graph API and returns page name
	 * on success. Using /me avoids querying /{page-id} directly, which requires the
	 * pages_read_engagement permission, Page Public Content Access, or Page Public Metadata Access
	 * features. When a page_id is supplied the returned id is compared against it to confirm
	 * the token belongs to the expected page.
	 *
	 * Accepts: access_token, page_id (optional), api_version, connection_id (optional), nonce (POST).
	 * When connection_id is provided and the access_token is empty or is an App Access Token,
	 * the handler falls back to the saved Page Access Token stored in the database.
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

		$access_token  = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized as sanitize_text_field() can truncate valid token characters.
		$access_token  = trim( (string) $access_token );
		$page_id       = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : '';
		$api_version   = isset( $_POST['api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['api_version'] ) ) : 'v21.0';
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		// Validate API version format.
		if ( ! preg_match( '/^v\d+\.\d+$/', $api_version ) ) {
			$api_version = 'v21.0';
		}

		// When editing an existing connection and the form field is empty (or contains an
		// App Access Token from the "Generate" button), fall back to the saved Page Access
		// Token stored in the database so the test reflects the actual saved credentials.
		if ( ! empty( $connection_id ) ) {
			$is_form_app_token = (bool) preg_match( '/^\d+\|[A-Za-z0-9_\-]+$/', $access_token );
			if ( empty( $access_token ) || $is_form_app_token ) {
				$saved_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( $saved_connection && ! empty( $saved_connection['api_key'] ) ) {
					$saved_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $saved_connection['api_key'] );
					if ( ! empty( $saved_token ) && $saved_token !== $access_token ) {
						$access_token = $saved_token;
					}
				}
			}
		}

		if ( empty( $access_token ) ) {
			wp_send_json_error( __( 'Access Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Detect App Access Tokens (format: {AppID}|{hash}, e.g. "1704482943846642|EVQCBBJ0mXtyjMW6Z4fGgZkGrVA").
		// App Access Tokens cannot call /me — that endpoint is reserved for User/Page tokens and returns
		// "An active access token must be used to query information about the current user" (HTTP 400).
		// Route App Access Tokens to /app, which returns the app's own identity and works without error.
		$is_app_token = (bool) preg_match( '/^\d+\|[A-Za-z0-9_\-]+$/', $access_token );

		if ( $is_app_token ) {
			// App Access Token: verify via /app endpoint.
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/app?fields=id,name&access_token=%s',
				$api_version,
				rawurlencode( $access_token )
			);
		} else {
			// Page/User Access Token: query /me — returns the page's own data without requiring
			// pages_read_engagement, Page Public Content Access, or Page Public Metadata Access.
			// Requesting only id and name to stay within standard token permissions.
			$endpoint = sprintf(
				'https://graph.facebook.com/%s/me?fields=id,name',
				$api_version
			);
		}

		$request_args = array(
			'timeout' => 15,
		);

		if ( ! $is_app_token ) {
			// Page/User tokens: pass via Authorization header.
			$request_args['headers'] = array(
				'Authorization' => 'Bearer ' . $access_token,
			);
		}

		$response = wp_remote_get( $endpoint, $request_args );

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

		$returned_id = isset( $body['id'] ) ? $body['id'] : '';
		$token_type  = $is_app_token ? __( 'App Access Token', 'mcp-ai-wpoos-pro' ) : __( 'Page Access Token', 'mcp-ai-wpoos-pro' );

		$result = array(
			'page_name'  => isset( $body['name'] ) ? $body['name'] : '',
			'page_id'    => $returned_id,
			'token_type' => $token_type,
			'message'    => __( 'Messenger connection successful! Credentials are valid.', 'mcp-ai-wpoos-pro' ),
		);

		if ( $is_app_token ) {
			// Warn that App Access Tokens cannot send Messenger messages.
			$result['warning'] = __( 'App Access Token detected. To send messages via Messenger, obtain a Page Access Token with pages_messaging permission from Meta Business Suite or Graph API Explorer.', 'mcp-ai-wpoos-pro' );
		} elseif ( ! empty( $page_id ) && $returned_id !== $page_id ) {
			// Page token is valid but represents a different page.
			$result['warning'] = __( 'The token is valid but the Page ID returned by the API does not match the Page ID provided. Ensure you are using a Page Access Token for the correct page.', 'mcp-ai-wpoos-pro' );
		} elseif ( empty( $page_id ) ) {
			// No page_id provided — advise the user.
			$result['warning'] = __( 'No Page ID provided. To validate that the token matches your intended page, enter a Page ID in the optional field above.', 'mcp-ai-wpoos-pro' );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: create a WhatsApp group via the Groups Management API.
	 *
	 * Calls POST /{phone-number-id}/groups on the Meta Graph API and returns
	 * the new group ID and invite link.
	 *
	 * Accepts (POST): subject, description (optional), access_token,
	 *   phone_number_id, connection_id, graph_api_version, nonce.
	 * Returns JSON with group_id and invite_link on success.
	 *
	 * @since 1.0.0
	 */
	public function ajax_create_whatsapp_group() {
		check_ajax_referer( 'wp_mcp_ai_create_whatsapp_group', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		if ( empty( $subject ) ) {
			wp_send_json_error( array( 'message' => __( 'Group name (subject) is required.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		// Resolve credentials: prefer live form values, fall back to stored connection.
		$access_token    = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- access tokens must not be sanitized.
		$access_token    = trim( (string) $access_token );
		$phone_number_id = isset( $_POST['phone_number_id'] ) ? sanitize_text_field( wp_unslash( $_POST['phone_number_id'] ) ) : '';

		if ( empty( $access_token ) || empty( $phone_number_id ) ) {
			$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			if ( ! empty( $connection_id ) ) {
				$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
				if ( empty( $access_token ) && ! empty( $stored['api_key'] ) ) {
					$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored['api_key'] );
				}
				if ( empty( $phone_number_id ) && ! empty( $stored['phone_number_id'] ) ) {
					$phone_number_id = sanitize_text_field( $stored['phone_number_id'] );
				}
			}
		}

		if ( empty( $access_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Access Token is required. Save the connection first or enter it in the form.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		if ( empty( $phone_number_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Phone Number ID is required. Save the connection first or enter it in the form.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$raw_version       = isset( $_POST['graph_api_version'] ) ? sanitize_text_field( wp_unslash( $_POST['graph_api_version'] ) ) : '';
		$graph_api_version = ( preg_match( '/^v\d+\.\d+$/', $raw_version ) ) ? $raw_version : 'v22.0';

		// Build the Groups Management API endpoint.
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/groups',
			rawurlencode( $graph_api_version ),
			rawurlencode( $phone_number_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'subject'           => $subject,
		);
		if ( ! empty( $description ) ) {
			$payload['description'] = $description;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			wp_send_json_error( array( 'message' => __( 'Failed to encode the group creation request.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$response = wp_remote_post(
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

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to connect to WhatsApp API: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					),
				)
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_error = is_array( $decoded ) && isset( $decoded['error'] ) ? $decoded['error'] : array();

		if ( $http_code < 200 || $http_code >= 300 || ! empty( $api_error ) ) {
			$error_message = is_array( $api_error ) && isset( $api_error['message'] ) && is_string( $api_error['message'] )
				? $api_error['message']
				: __( 'Group creation failed.', 'mcp-ai-wpoos-pro' );

			$hint = '';
			if ( is_array( $api_error ) && isset( $api_error['code'] ) ) {
				$meta_code = (int) $api_error['code'];
				if ( 190 === $meta_code || 368 === $meta_code ) {
					$hint = __( 'Invalid or expired access token. Generate a new System User Access Token with the whatsapp_business_messaging permission.', 'mcp-ai-wpoos-pro' );
				} elseif ( 10 === $meta_code || 200 === $meta_code ) {
					$hint = __( 'Insufficient permissions. Ensure your access token has the whatsapp_business_messaging permission with Advanced Access.', 'mcp-ai-wpoos-pro' );
				} elseif ( 131215 === $meta_code ) {
					$hint = __( 'This phone number is not eligible to access the WhatsApp Groups API. The Groups Management API is a restricted feature that requires separate approval from Meta. Apply for access via the Meta Business Help Center or your Meta Business Manager.', 'mcp-ai-wpoos-pro' );
				}
			}

			wp_send_json_error(
				array(
					'message' => esc_html( $error_message ),
					'hint'    => $hint,
				)
			);
			return;
		}

		// The API returns the new group ID. Fetch the invite link from the group.
		$group_id    = isset( $decoded['id'] ) ? sanitize_text_field( $decoded['id'] ) : '';
		$invite_link = '';

		if ( ! empty( $group_id ) ) {
			$invite_url = add_query_arg(
				array( 'fields' => 'invite_link' ),
				sprintf( 'https://graph.facebook.com/%s/%s', rawurlencode( $graph_api_version ), rawurlencode( $group_id ) )
			);

			$invite_response = wp_remote_get(
				$invite_url,
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
					'timeout' => 15,
				)
			);

			if ( ! is_wp_error( $invite_response ) ) {
				$invite_data = json_decode( wp_remote_retrieve_body( $invite_response ), true );
				if ( is_array( $invite_data ) && ! empty( $invite_data['invite_link'] ) ) {
					$invite_link = esc_url_raw( $invite_data['invite_link'] );
				}
			}
		}

		wp_send_json_success(
			array(
				'group_id'    => $group_id,
				'invite_link' => $invite_link,
			)
		);
	}

	/**
	 * AJAX handler: test Google Chat connection with the provided access token.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_google_chat_live() {
		check_ajax_referer( 'wp_mcp_ai_test_google_chat_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// The JS passes the field value (service account JSON key) as 'access_token' for backwards compat.
		$service_account_key = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- key JSON must not be sanitized.
		$service_account_key = trim( (string) $service_account_key );

		// Always load the stored connection so we can fall back to OAuth credentials if needed.
		$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;

		// Fall back to stored service account key when the field is left blank.
		if ( empty( $service_account_key ) && ! empty( $stored_connection['api_key'] ) ) {
			$service_account_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
		}

		// Load the helper class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-google-service-account.php';
		}

		// Resolve an access token — supports Service Account JSON, legacy raw tokens, and OAuth refresh tokens.
		$access_token = '';

		if ( strlen( $service_account_key ) > 0 && '{' === $service_account_key[0] ) {
			$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
				$service_account_key,
				'https://www.googleapis.com/auth/chat.bot'
			);
			if ( is_wp_error( $token_result ) ) {
				// If the JSON is the wrong credential type and OAuth credentials are stored, fall through to OAuth.
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf -- Intentional fall-through to OAuth below.
				if ( 'wp_mcp_ai_gc_sa_wrong_key_type' === $token_result->get_error_code() && ! empty( $stored_connection['refresh_token'] ) ) {
					// Fall through to OAuth fallback below.
				} else {
					wp_send_json_error(
						sprintf(
						/* translators: %s: error message */
							__( 'Failed to obtain access token from Service Account key: %s', 'mcp-ai-wpoos-pro' ),
							$token_result->get_error_message()
						)
					);
				}
			} else {
				$access_token = $token_result;
			}
		} elseif ( strlen( $service_account_key ) > 0 ) {
			// Legacy raw access token.
			$access_token = $service_account_key;
		}

		// OAuth fallback: use stored refresh token when no service account key produced a token.
		if ( '' === $access_token && $stored_connection && ! empty( $stored_connection['refresh_token'] ) ) {
			$oauth_client_id     = ! empty( $stored_connection['client_id'] ) ? $stored_connection['client_id'] : '';
			$oauth_client_secret = ! empty( $stored_connection['client_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['client_secret'] ) : '';
			$oauth_refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['refresh_token'] );
			$oauth_result        = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_refresh_token(
				$oauth_client_id,
				$oauth_client_secret,
				$oauth_refresh_token
			);
			if ( is_wp_error( $oauth_result ) ) {
				wp_send_json_error(
					sprintf(
					/* translators: %s: error message */
						__( 'Failed to obtain access token via OAuth: %s', 'mcp-ai-wpoos-pro' ),
						$oauth_result->get_error_message()
					)
				);
				return;
			}
			$access_token = $oauth_result;
		}

		if ( '' === $access_token ) {
			// Webhook-only mode: no SA/OAuth credentials, but OIDC verification is disabled
			// and a reply webhook URL is configured. Incoming messages are accepted without
			// token validation, and outbound replies go through the webhook URL — no
			// service-account or OAuth credentials are needed for this setup.
			if (
			$stored_connection &&
			! empty( $stored_connection['disable_oidc_verification'] ) &&
			! empty( $stored_connection['reply_webhook_url'] ) &&
			preg_match( '#^https://chat\.googleapis\.com/v1/spaces/[a-zA-Z0-9_-]+/messages\?#', $stored_connection['reply_webhook_url'] )
			) {
				wp_send_json_success(
					array(
						'mode'        => 'webhook_only',
						'space_count' => 0,
					)
				);
				return;
			}

			wp_send_json_error( __( 'No valid credentials found. Please provide a Service Account JSON key or set up OAuth via the 1-click connect button.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Call the Google Chat spaces.list endpoint — this is the canonical way to verify
		// a service account token has the chat.bot scope and can reach the API.
		$response = wp_remote_get(
			'https://chat.googleapis.com/v1/spaces?pageSize=10',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to connect to Google Chat API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid response from Google Chat API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
				/* translators: 1: status code, 2: error message */
					__( 'Google Chat API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$error_msg
				)
			);
			return;
		}

		$spaces      = isset( $body['spaces'] ) && is_array( $body['spaces'] ) ? $body['spaces'] : array();
		$space_count = count( $spaces );

		wp_send_json_success(
			array(
				'space_count' => $space_count,
			)
		);
	}

	/**
	 * AJAX handler: fetch Google Chat spaces accessible by the service account.
	 *
	 * @since 1.0.0
	 */
	public function ajax_fetch_google_chat_spaces() {
		check_ajax_referer( 'wp_mcp_ai_fetch_google_chat_spaces', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// The JS passes the field value (service account JSON key) as 'access_token' for backwards compat.
		$service_account_key = isset( $_POST['access_token'] ) ? wp_unslash( $_POST['access_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- key JSON must not be sanitized.
		$service_account_key = trim( (string) $service_account_key );

		// Always load the stored connection so we can fall back to OAuth credentials if needed.
		$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;

		// Fall back to stored service account key when the field is left blank.
		if ( empty( $service_account_key ) && ! empty( $stored_connection['api_key'] ) ) {
			$service_account_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
		}

		// Load the helper class.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-google-service-account.php';
		}

		// Resolve an access token — supports Service Account JSON, legacy raw tokens, and OAuth refresh tokens.
		$access_token = '';

		if ( strlen( $service_account_key ) > 0 && '{' === $service_account_key[0] ) {
			$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
				$service_account_key,
				'https://www.googleapis.com/auth/chat.bot'
			);
			if ( is_wp_error( $token_result ) ) {
				// If the JSON is the wrong credential type and OAuth credentials are stored, fall through to OAuth.
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf -- Intentional fall-through to OAuth below.
				if ( 'wp_mcp_ai_gc_sa_wrong_key_type' === $token_result->get_error_code() && ! empty( $stored_connection['refresh_token'] ) ) {
					// Fall through to OAuth fallback below.
				} else {
					wp_send_json_error(
						array(
							'message' => sprintf(
							/* translators: %s: error message */
								__( 'Failed to obtain access token from Service Account key: %s', 'mcp-ai-wpoos-pro' ),
								$token_result->get_error_message()
							),
						)
					);
					return;
				}
			} else {
				$access_token = $token_result;
			}
		} elseif ( strlen( $service_account_key ) > 0 ) {
			// Legacy raw access token.
			$access_token = $service_account_key;
		}

		// OAuth fallback: use stored refresh token when no service account key produced a token.
		if ( '' === $access_token && $stored_connection && ! empty( $stored_connection['refresh_token'] ) ) {
			$oauth_client_id     = ! empty( $stored_connection['client_id'] ) ? $stored_connection['client_id'] : '';
			$oauth_client_secret = ! empty( $stored_connection['client_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['client_secret'] ) : '';
			$oauth_refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['refresh_token'] );
			$oauth_result        = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_refresh_token(
				$oauth_client_id,
				$oauth_client_secret,
				$oauth_refresh_token
			);
			if ( is_wp_error( $oauth_result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
						/* translators: %s: error message */
							__( 'Failed to obtain access token via OAuth: %s', 'mcp-ai-wpoos-pro' ),
							$oauth_result->get_error_message()
						),
					)
				);
				return;
			}
			$access_token = $oauth_result;
		}

		if ( '' === $access_token ) {
			wp_send_json_error( array( 'message' => __( 'No valid credentials found. Please provide a Service Account JSON key or set up OAuth via the 1-click connect button.', 'mcp-ai-wpoos-pro' ) ) );
			return;
		}

		$all_spaces = array();
		$page_token = '';

		do {
			$url = 'https://chat.googleapis.com/v1/spaces?pageSize=100';
			if ( $page_token ) {
				$url = add_query_arg( 'pageToken', rawurlencode( $page_token ), $url );
			}

			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
						/* translators: %s: error message */
							__( 'Failed to connect to Google Chat API: %s', 'mcp-ai-wpoos-pro' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $status_code ) {
				$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid response from Google Chat API.', 'mcp-ai-wpoos-pro' );
				wp_send_json_error(
					array(
						'message' => sprintf(
						/* translators: 1: status code, 2: error message */
							__( 'Google Chat API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
							$status_code,
							$error_msg
						),
					)
				);
				return;
			}

			if ( isset( $body['spaces'] ) && is_array( $body['spaces'] ) ) {
				$all_spaces = array_merge( $all_spaces, $body['spaces'] );
			}

			$page_token = isset( $body['nextPageToken'] ) ? sanitize_text_field( $body['nextPageToken'] ) : '';

		} while ( ! empty( $page_token ) );

		// Return only the fields needed by the UI.
		$spaces = array_map(
			function ( $space ) {
				return array(
					'name'        => isset( $space['name'] ) ? sanitize_text_field( $space['name'] ) : '',
					'displayName' => isset( $space['displayName'] ) ? sanitize_text_field( $space['displayName'] ) : '',
					'spaceType'   => isset( $space['spaceType'] ) ? sanitize_text_field( $space['spaceType'] ) : '',
				);
			},
			$all_spaces
		);

		wp_send_json_success( array( 'spaces' => $spaces ) );
	}

	/**
	 * AJAX handler: simulate an incoming Google Chat message and return the AI reply.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_google_chat_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_google_chat_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_space    = isset( $_POST['test_space'] ) ? sanitize_text_field( wp_unslash( $_POST['test_space'] ) ) : '';

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

		// If a space was provided, send the reply via the Google Chat API.
		$has_api_key = ! empty( $connection['api_key'] );
		$has_oauth   = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) && ! empty( $connection['refresh_token'] );
		if ( ! empty( $test_space ) && ( $has_api_key || $has_oauth ) ) {
			// Validate space format: must match spaces/{spaceId}.
			if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $test_space ) ) {
				$result['send_error'] = __( 'Invalid space format. Must be spaces/AAAAxxxxxx.', 'mcp-ai-wpoos-pro' );
				wp_send_json_success( $result );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-google-service-account.php';
			}

			$gc_access_token = '';

			// Try service account key first.
			if ( $has_api_key ) {
				$gc_raw_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
				if ( '' !== $gc_raw_key ) {
					if ( strlen( $gc_raw_key ) > 0 && '{' === $gc_raw_key[0] ) {
						$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
							$gc_raw_key,
							'https://www.googleapis.com/auth/chat.bot'
						);
						if ( ! is_wp_error( $token_result ) ) {
								$gc_access_token = (string) $token_result;
						}
					} else {
						$gc_access_token = $gc_raw_key;
					}
				}
			}

			// Fall back to OAuth refresh token when no service account token was obtained.
			if ( '' === $gc_access_token && $has_oauth ) {
				$oauth_client_id     = $connection['client_id'];
				$oauth_client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );
				$oauth_refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] );
				$token_result        = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_refresh_token(
					$oauth_client_id,
					$oauth_client_secret,
					$oauth_refresh_token
				);
				if ( ! is_wp_error( $token_result ) ) {
					$gc_access_token = (string) $token_result;
				}
			}

			if ( '' !== $gc_access_token ) {
				// Google Chat API: 4096-character limit for text messages.
				$chat_body = wp_strip_all_tags( $ai_reply );
				$chat_body = html_entity_decode( $chat_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( mb_strlen( $chat_body ) > 4096 ) {
					$chat_body = mb_substr( $chat_body, 0, 4093 ) . '...';
				}

				$endpoint = 'https://chat.googleapis.com/v1/' . $test_space . '/messages';

				$payload = array(
					'text' => $chat_body,
				);

				$send_body = wp_json_encode( $payload );
				if ( false !== $send_body ) {
					$send_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array(
								'Content-Type'  => 'application/json',
								'Authorization' => 'Bearer ' . $gc_access_token,
							),
							'timeout' => 20,
							'body'    => $send_body,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_error_body      = ! is_wp_error( $send_result ) ? json_decode( wp_remote_retrieve_body( $send_result ), true ) : null;
						$result['send_error'] = isset( $send_error_body['error']['message'] )
						? $send_error_body['error']['message']
						: ( is_wp_error( $send_result ) ? $send_result->get_error_message() : __( 'Unknown send error.', 'mcp-ai-wpoos-pro' ) );
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: simulate an incoming Google Chat MESSAGE webhook event.
	 *
	 * Bypasses OIDC token validation (admin-only) and runs the full incoming
	 * trigger pipeline — connection lookup, AI reply generation, and optionally
	 * sending the reply to a real Google Chat space — so the end-to-end flow
	 * can be verified without waiting for a live Google Chat message.
	 *
	 * Accepts (POST): connection_id, test_message, test_space (optional), nonce.
	 * Returns JSON success with ai_reply, sent (bool), and webhook_url, or an
	 * error string.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_google_chat_incoming_trigger() {
		check_ajax_referer( 'wp_mcp_ai_test_google_chat_incoming_trigger', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_space    = isset( $_POST['test_space'] ) ? sanitize_text_field( wp_unslash( $_POST['test_space'] ) ) : '';

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

		if ( empty( $connection['enabled'] ) ) {
			wp_send_json_error( __( 'This connection is not enabled. Enable it and save before testing.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		// Fall back to the global default assistant when none are directly assigned.
		if ( empty( $assigned_assistant_ids ) ) {
			$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
			if ( ! empty( $automation_rules['default_assistant_id'] ) ) {
				$assigned_assistant_ids = array( absint( $automation_rules['default_assistant_id'] ) );
			}
		}

		if ( empty( $assigned_assistant_ids ) ) {
			wp_send_json_error( __( 'No assistants are assigned to this connection. Please assign at least one assistant and save before testing.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Resolve the space name: prefer explicit test_space, fall back to connection config.
		$space_name = $test_space;
		if ( '' === $space_name && ! empty( $connection['google_chat_space'] ) ) {
			$space_name = sanitize_text_field( $connection['google_chat_space'] );
		}

		$assistant_id = $assigned_assistant_ids[0];

		// Build the AI request directly (mirrors handle_google_chat_reply_job logic,
		// but runs synchronously so the admin sees the result immediately).
		$rest_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$rest_request->set_body_params(
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
		$rest_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $rest_request );
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

		// Extract the assistant reply text.
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
			'ai_reply'    => $ai_reply,
			'sent'        => false,
			'space_name'  => $space_name,
			'webhook_url' => home_url( '/wp-json/mcp-ai/v1/webhooks/google-chat/' . $connection_id ),
		);

		// If a space name is available and credentials are present, send the reply
		// via the Google Chat API to complete the full end-to-end test.
		$has_api_key     = ! empty( $connection['api_key'] );
		$has_oauth       = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) && ! empty( $connection['refresh_token'] );
		$has_webhook_url = ! empty( $connection['reply_webhook_url'] )
			&& preg_match( '#^https://chat\.googleapis\.com/v1/spaces/[a-zA-Z0-9_-]+/messages\?#', $connection['reply_webhook_url'] );

		if ( '' !== $space_name && ( $has_api_key || $has_oauth ) ) {
			if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $space_name ) ) {
				$result['send_error'] = __( 'Invalid space format. Must be spaces/AAAAxxxxxx.', 'mcp-ai-wpoos-pro' );
				wp_send_json_success( $result );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/chat-channels/class-wp-mcp-ai-pro-google-service-account.php';
			}

			$gc_access_token = '';

			if ( $has_api_key ) {
				$gc_raw_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
				if ( '' !== $gc_raw_key ) {
					if ( strlen( $gc_raw_key ) > 0 && '{' === $gc_raw_key[0] ) {
						$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
							$gc_raw_key,
							'https://www.googleapis.com/auth/chat.bot'
						);
						if ( ! is_wp_error( $token_result ) ) {
							$gc_access_token = (string) $token_result;
						}
					} else {
						$gc_access_token = $gc_raw_key;
					}
				}
			}

			if ( '' === $gc_access_token && $has_oauth ) {
				$oauth_client_id     = $connection['client_id'];
				$oauth_client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );
				$oauth_refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] );
				$token_result        = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_refresh_token(
					$oauth_client_id,
					$oauth_client_secret,
					$oauth_refresh_token
				);
				if ( ! is_wp_error( $token_result ) ) {
					$gc_access_token = (string) $token_result;
				}
			}

			if ( '' !== $gc_access_token ) {
				$chat_body = wp_strip_all_tags( $ai_reply );
				$chat_body = html_entity_decode( $chat_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( mb_strlen( $chat_body ) > 4096 ) {
					$chat_body = mb_substr( $chat_body, 0, 4093 ) . '...';
				}

				$endpoint  = 'https://chat.googleapis.com/v1/' . $space_name . '/messages';
				$send_body = wp_json_encode( array( 'text' => $chat_body ) );

				if ( false !== $send_body ) {
					$send_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array(
								'Content-Type'  => 'application/json',
								'Authorization' => 'Bearer ' . $gc_access_token,
							),
							'timeout' => 20,
							'body'    => $send_body,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_error_body      = ! is_wp_error( $send_result ) ? json_decode( wp_remote_retrieve_body( $send_result ), true ) : null;
						$result['send_error'] = isset( $send_error_body['error']['message'] )
							? $send_error_body['error']['message']
							: ( is_wp_error( $send_result ) ? $send_result->get_error_message() : __( 'Unknown send error.', 'mcp-ai-wpoos-pro' ) );
					}
				}
			} else {
				$result['send_error'] = __( 'Could not obtain an access token from the stored credentials.', 'mcp-ai-wpoos-pro' );
			}
		} elseif ( $has_webhook_url ) {
			// --- Fallback: send via incoming webhook URL (no OAuth needed) ---
			$chat_body = wp_strip_all_tags( $ai_reply );
			$chat_body = html_entity_decode( $chat_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( mb_strlen( $chat_body ) > 4096 ) {
				$chat_body = mb_substr( $chat_body, 0, 4093 ) . '...';
			}

			$send_body = wp_json_encode( array( 'text' => $chat_body ) );

			if ( false !== $send_body ) {
				$send_result = wp_remote_post(
					$connection['reply_webhook_url'],
					array(
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 20,
						'body'    => $send_body,
					)
				);

				if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
					$result['sent'] = true;
				} else {
					$send_error_body      = ! is_wp_error( $send_result ) ? json_decode( wp_remote_retrieve_body( $send_result ), true ) : null;
					$result['send_error'] = isset( $send_error_body['error']['message'] )
						? $send_error_body['error']['message']
						: ( is_wp_error( $send_result ) ? $send_result->get_error_message() : __( 'Unknown send error.', 'mcp-ai-wpoos-pro' ) );
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: test the Facebook Messenger auto-reply flow.
	 *
	 * Sends the supplied test message to the first assigned assistant via the
	 * internal chat REST endpoint and returns the AI-generated reply. Optionally
	 * delivers the reply to a Messenger recipient (PSID) using the Send API.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_messenger_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_messenger_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id  = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message   = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_recipient = isset( $_POST['test_recipient_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_recipient_id'] ) ) : '';

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

		// If a recipient PSID was provided, send the reply via the Messenger Send API.
		if ( ! empty( $test_recipient ) && ! empty( $connection['api_key'] ) ) {
			$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			if ( '' !== $access_token ) {
				$graph_api_version = isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
					? sanitize_text_field( $connection['graph_api_version'] )
					: 'v21.0';

				// Messenger does not render HTML; strip tags and cap at 2000 characters.
				$msng_body = wp_strip_all_tags( $ai_reply );
				$msng_body = html_entity_decode( $msng_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( mb_strlen( $msng_body ) > 2000 ) {
					$msng_body = mb_substr( $msng_body, 0, 1997 ) . '...';
				}

				$endpoint = sprintf(
					'https://graph.facebook.com/%s/me/messages',
					rawurlencode( $graph_api_version )
				);

				$payload = array(
					'recipient' => array( 'id' => $test_recipient ),
					'message'   => array( 'text' => $msng_body ),
				);

				$body = wp_json_encode( $payload );
				if ( false !== $body ) {
					$send_result = wp_remote_post(
						add_query_arg( 'access_token', $access_token, $endpoint ),
						array(
							'headers' => array( 'Content-Type' => 'application/json' ),
							'timeout' => 20,
							'body'    => $body,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_body            = is_wp_error( $send_result ) ? '' : wp_remote_retrieve_body( $send_result );
						$send_error_decoded   = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
						$result['send_error'] = is_wp_error( $send_result )
							? $send_result->get_error_message()
							: ( isset( $send_error_decoded['error']['message'] ) ? $send_error_decoded['error']['message'] : $send_body );
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: test the Telegram auto-reply flow.
	 *
	 * Simulates an incoming Telegram message by calling the internal chat endpoint
	 * with the first assigned assistant for the connection. If a chat ID is provided
	 * the AI reply is also sent via the Telegram Bot API so the end-to-end flow
	 * (webhook → AI → send) can be verified from the admin UI.
	 *
	 * Accepts (POST): connection_id, test_message, test_chat_id (optional), nonce.
	 * Returns JSON success with ai_reply and sent (bool), or an error string.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test_telegram_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_telegram_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_chat_id  = isset( $_POST['test_chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_chat_id'] ) ) : '';

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

		// If a chat ID was provided, send the reply via the Telegram Bot API.
		if ( ! empty( $test_chat_id ) && ! empty( $connection['api_key'] ) ) {
			$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			if ( '' !== $bot_token ) {
				// Telegram enforces a 4096-character limit for text messages.
				$tg_body = wp_strip_all_tags( $ai_reply );
				$tg_body = html_entity_decode( $tg_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( mb_strlen( $tg_body ) > 4096 ) {
					$tg_body = mb_substr( $tg_body, 0, 4093 ) . '...';
				}

				$endpoint = 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendMessage';

				$payload = array(
					'chat_id' => $test_chat_id,
					'text'    => $tg_body,
				);

				$body = wp_json_encode( $payload );
				if ( false !== $body ) {
					$send_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array(
								'Content-Type' => 'application/json',
							),
							'timeout' => 20,
							'body'    => $body,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_body            = is_wp_error( $send_result ) ? '' : wp_remote_retrieve_body( $send_result );
						$send_error_decoded   = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
						$result['send_error'] = is_wp_error( $send_result )
							? $send_result->get_error_message()
							: ( isset( $send_error_decoded['description'] ) ? $send_error_decoded['description'] : $send_body );
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Send a test message directly to a Telegram group, supergroup, or channel.
	 *
	 * Accepts (POST): connection_id, test_chat_id, test_message, nonce.
	 * The chat ID can be a numeric group/supergroup ID (negative number) or an @channel username.
	 */
	public function ajax_test_telegram_send_group() {
		check_ajax_referer( 'wp_mcp_ai_test_telegram_send_group', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_chat_id  = isset( $_POST['test_chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_chat_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( empty( $test_chat_id ) ) {
			wp_send_json_error( __( 'Please enter a group/channel chat ID or @username.', 'mcp-ai-wpoos-pro' ) );
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

		if ( empty( $connection['api_key'] ) ) {
			wp_send_json_error( __( 'No bot token found for this connection. Save the connection with a valid bot token first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Could not decrypt bot token.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Telegram enforces a 4096-character limit for text messages.
		$tg_body = wp_strip_all_tags( $test_message );
		$tg_body = html_entity_decode( $tg_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $tg_body ) > 4096 ) {
			$tg_body = mb_substr( $tg_body, 0, 4093 ) . '...';
		}

		$endpoint = 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendMessage';

		$payload = array(
			'chat_id' => $test_chat_id,
			'text'    => $tg_body,
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			wp_send_json_error( __( 'Failed to encode message payload.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$send_result = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $send_result ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$send_result->get_error_message()
				)
			);
			return;
		}

		$status_code   = (int) wp_remote_retrieve_response_code( $send_result );
		$response_body = json_decode( wp_remote_retrieve_body( $send_result ), true );

		if ( 200 !== $status_code || empty( $response_body['ok'] ) ) {
			$description = isset( $response_body['description'] ) ? $response_body['description'] : __( 'Unknown Telegram API error.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$description
				)
			);
			return;
		}

		$result_data = isset( $response_body['result'] ) ? $response_body['result'] : array();
		$chat_info   = isset( $result_data['chat'] ) ? $result_data['chat'] : array();

		wp_send_json_success(
			array(
				'chat_title' => isset( $chat_info['title'] ) ? $chat_info['title'] : '',
				'chat_type'  => isset( $chat_info['type'] ) ? $chat_info['type'] : '',
				'message_id' => isset( $result_data['message_id'] ) ? $result_data['message_id'] : '',
			)
		);
	}

	/**
	 * AJAX handler: Test Office 365 connection by obtaining a token from Microsoft Graph.
	 *
	 * Accepts: client_id, client_secret, tenant_id, connection_id, nonce (POST).
	 * Falls back to stored credentials when fields are left blank.
	 */
	public function ajax_test_office365_live() {
		check_ajax_referer( 'wp_mcp_ai_test_office365_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? wp_unslash( $_POST['client_secret'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secrets must not be sanitized.
		$client_secret = trim( (string) $client_secret );
		$tenant_id     = isset( $_POST['tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_id'] ) ) : '';

		// Fall back to stored credentials.
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( $stored_connection && 'office365' === ( isset( $stored_connection['connection_type'] ) ? $stored_connection['connection_type'] : '' ) ) {
				if ( empty( $client_id ) && ! empty( $stored_connection['client_id'] ) ) {
					$client_id = $stored_connection['client_id'];
				}
				if ( empty( $client_secret ) && ! empty( $stored_connection['client_secret'] ) ) {
					$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['client_secret'] );
				}
				if ( empty( $tenant_id ) && ! empty( $stored_connection['tenant_id'] ) ) {
					$tenant_id = $stored_connection['tenant_id'];
				}
			}
		}

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			wp_send_json_error( __( 'Application (Client) ID and Client Secret are required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$tenant = ! empty( $tenant_id ) ? $tenant_id : 'common';

		// Request an access token using client credentials grant.
		$token_url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

		$token_response = wp_remote_post(
			$token_url,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'scope'         => 'https://graph.microsoft.com/.default',
				),
			)
		);

		if ( is_wp_error( $token_response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Microsoft identity platform: %s', 'mcp-ai-wpoos-pro' ),
					$token_response->get_error_message()
				)
			);
			return;
		}

		$token_code = wp_remote_retrieve_response_code( $token_response );
		$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );

		if ( 200 !== (int) $token_code || empty( $token_data['access_token'] ) ) {
			$error_desc = isset( $token_data['error_description'] ) ? $token_data['error_description'] : __( 'Failed to obtain an access token.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Microsoft Graph authentication error: %s', 'mcp-ai-wpoos-pro' ),
					$error_desc
				)
			);
			return;
		}

		$result = array(
			'display_name' => '',
			'mail'         => '',
			'tenant_id'    => $tenant,
		);

		// Try calling the /organization endpoint to verify the token works.
		$org_response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/organization',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token_data['access_token'],
				),
			)
		);

		if ( ! is_wp_error( $org_response ) && 200 === (int) wp_remote_retrieve_response_code( $org_response ) ) {
			$org_data = json_decode( wp_remote_retrieve_body( $org_response ), true );
			if ( ! empty( $org_data['value'][0] ) ) {
				$org                    = $org_data['value'][0];
				$result['display_name'] = isset( $org['displayName'] ) ? $org['displayName'] : '';
				$result['tenant_id']    = isset( $org['id'] ) ? $org['id'] : $tenant;
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Test Office 365 auto-reply by simulating an incoming email.
	 *
	 * Uses the same pattern as the Telegram auto-reply test: calls the internal chat
	 * endpoint with the first assigned assistant and returns the AI reply.
	 */
	public function ajax_test_office365_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_office365_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id  = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message   = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_recipient = isset( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';

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

		// If a recipient email was provided, try to send via Outlook Mail API.
		if ( ! empty( $test_recipient ) && ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) ) {
			$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );
			$tenant        = ! empty( $connection['tenant_id'] ) ? $connection['tenant_id'] : 'common';
			$token_url     = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

			$token_response = wp_remote_post(
				$token_url,
				array(
					'timeout' => 15,
					'body'    => array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $connection['client_id'],
						'client_secret' => $client_secret,
						'scope'         => 'https://graph.microsoft.com/.default',
					),
				)
			);

			if ( ! is_wp_error( $token_response ) ) {
				$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
				if ( ! empty( $token_data['access_token'] ) ) {
					$mail_body = wp_json_encode(
						array(
							'message' => array(
								'subject'      => __( 'Auto-Reply Test', 'mcp-ai-wpoos-pro' ),
								'body'         => array(
									'contentType' => 'Text',
									'content'     => wp_strip_all_tags( $ai_reply ),
								),
								'toRecipients' => array(
									array(
										'emailAddress' => array(
											'address' => $test_recipient,
										),
									),
								),
							),
						)
					);

					if ( false !== $mail_body ) {
						// Graph API: /users/{userPrincipalName}/sendMail sends FROM that
						// user's mailbox (requires Mail.Send application permission).
						// The test_recipient email doubles as the sender UPN, creating a
						// self-addressed test email — a common pattern for verifying the
						// end-to-end mail flow without requiring a separate "from" address.
						$send_result = wp_remote_post(
							'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $test_recipient ) . '/sendMail',
							array(
								'headers' => array(
									'Authorization' => 'Bearer ' . $token_data['access_token'],
									'Content-Type'  => 'application/json',
								),
								'timeout' => 20,
								'body'    => $mail_body,
							)
						);

						$send_code = wp_remote_retrieve_response_code( $send_result );
						if ( ! is_wp_error( $send_result ) && ( 202 === (int) $send_code || 200 === (int) $send_code ) ) {
							$result['sent'] = true;
						} else {
							$send_body            = is_wp_error( $send_result ) ? '' : wp_remote_retrieve_body( $send_result );
							$send_error_decoded   = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
							$result['send_error'] = is_wp_error( $send_result )
								? $send_result->get_error_message()
								: ( isset( $send_error_decoded['error']['message'] ) ? $send_error_decoded['error']['message'] : $send_body );
						}
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Test iCloud Drive gateway connection.
	 *
	 * Sends a simple GET request to the gateway API URL with the API key
	 * to verify connectivity. Falls back to stored credentials when fields
	 * are left blank.
	 */
	public function ajax_test_icloud_live() {
		check_ajax_referer( 'wp_mcp_ai_test_icloud_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$gateway_url = isset( $_POST['gateway_url'] ) ? esc_url_raw( wp_unslash( $_POST['gateway_url'] ) ) : '';
		$api_key     = isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys must not be sanitized.
		$api_key     = trim( (string) $api_key );

		// Fall back to stored credentials.
		if ( empty( $gateway_url ) || empty( $api_key ) ) {
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( $stored_connection && 'icloud' === ( isset( $stored_connection['connection_type'] ) ? $stored_connection['connection_type'] : '' ) ) {
				if ( empty( $gateway_url ) && ! empty( $stored_connection['gateway_api_url'] ) ) {
					$gateway_url = $stored_connection['gateway_api_url'];
				}
				if ( empty( $api_key ) && ! empty( $stored_connection['api_key'] ) ) {
					$api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
				}
			}
		}

		if ( empty( $gateway_url ) ) {
			wp_send_json_error( __( 'Gateway API URL is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Gateway API Key is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_get(
			$gateway_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to iCloud gateway: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code >= 400 ) {
			wp_send_json_error(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'iCloud gateway returned HTTP %d. Please check your credentials and gateway URL.', 'mcp-ai-wpoos-pro' ),
					$response_code
				)
			);
			return;
		}

		$result = array(
			'gateway_url' => $gateway_url,
			'status'      => sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP %d', 'mcp-ai-wpoos-pro' ),
				$response_code
			),
			'message'     => __( 'Gateway is reachable and accepted the request.', 'mcp-ai-wpoos-pro' ),
		);

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Test iCloud auto-reply by simulating an incoming file event.
	 *
	 * Uses the same pattern as the Telegram auto-reply test.
	 */
	public function ajax_test_icloud_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_icloud_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';

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

		wp_send_json_success( array( 'ai_reply' => $ai_reply ) );
	}

	/**
	 * AJAX handler: Fetch the Google Chat webhook receipt log.
	 *
	 * Returns up to 25 recent webhook events stored by
	 * WP_MCP_AI_Google_Chat_Webhook_Controller::store_webhook_log_entry().
	 * Each entry includes the UTC timestamp, status, rejection reason or detail,
	 * event type, space name, and masked client IP.
	 */
	public function ajax_get_google_chat_webhook_log() {
		check_ajax_referer( 'wp_mcp_ai_get_google_chat_webhook_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$log = get_option( 'wp_mcp_ai_gc_webhook_log', array() );

		wp_send_json_success( is_array( $log ) ? $log : array() );
	}

	/**
	 * AJAX handler: Clear the Google Chat webhook receipt log.
	 */
	public function ajax_clear_google_chat_webhook_log() {
		check_ajax_referer( 'wp_mcp_ai_clear_google_chat_webhook_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		delete_option( 'wp_mcp_ai_gc_webhook_log' );

		wp_send_json_success( array( 'cleared' => true ) );
	}

	/**
	 * AJAX handler: Test Telegram bot token (getMe + getWebhookInfo).
	 *
	 * Works before saving — falls back to the stored bot token when the field is left blank.
	 */
	public function ajax_test_telegram_live() {
		check_ajax_referer( 'wp_mcp_ai_test_telegram_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token     = isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tokens must not be sanitized.
		$bot_token     = trim( (string) $bot_token );
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		// Fall back to stored token when the field is blank.
		if ( empty( $bot_token ) ) {
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}
		}

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Bot Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
			wp_send_json_error( __( 'The token format is invalid. A Telegram bot token looks like: 1234567890:ABCdefGHIjklMNOpqrsTUVwxyz. Obtain yours from @BotFather on Telegram.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$api_base    = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
		$get_me_resp = wp_remote_get( $api_base . '/getMe', array( 'timeout' => 15 ) );

		if ( is_wp_error( $get_me_resp ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$get_me_resp->get_error_message()
				)
			);
			return;
		}

		$get_me_code = wp_remote_retrieve_response_code( $get_me_resp );
		$get_me_data = json_decode( wp_remote_retrieve_body( $get_me_resp ), true );

		if ( 200 !== (int) $get_me_code || empty( $get_me_data['ok'] ) ) {
			$description = isset( $get_me_data['description'] ) ? $get_me_data['description'] : __( 'Invalid response from Telegram API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$description
				)
			);
			return;
		}

		$bot    = isset( $get_me_data['result'] ) ? $get_me_data['result'] : array();
		$result = array(
			'bot_id'          => isset( $bot['id'] ) ? $bot['id'] : '',
			'bot_username'    => isset( $bot['username'] ) ? $bot['username'] : '',
			'bot_name'        => isset( $bot['first_name'] ) ? $bot['first_name'] : '',
			'webhook_url'     => '',
			'pending_updates' => 0,
		);

		// Retrieve current webhook info.
		$wh_resp = wp_remote_get( $api_base . '/getWebhookInfo', array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $wh_resp ) && 200 === (int) wp_remote_retrieve_response_code( $wh_resp ) ) {
			$wh_data = json_decode( wp_remote_retrieve_body( $wh_resp ), true );
			if ( ! empty( $wh_data['ok'] ) && isset( $wh_data['result'] ) ) {
				$wh                        = $wh_data['result'];
				$result['webhook_url']     = isset( $wh['url'] ) ? $wh['url'] : '';
				$result['pending_updates'] = isset( $wh['pending_update_count'] ) ? (int) $wh['pending_update_count'] : 0;
				if ( ! empty( $wh['last_error_message'] ) ) {
					$result['webhook_last_error'] = $wh['last_error_message'];
				}
				$expected_url = ! empty( $connection_id )
					? home_url( '/wp-json/mcp-ai/v1/webhooks/telegram/' . $connection_id )
					: home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' );
				if ( empty( $result['webhook_url'] ) ) {
					$result['warning'] = sprintf(
						/* translators: %s: expected webhook URL */
						__( 'No webhook is set. Click Set Webhook to register: %s', 'mcp-ai-wpoos-pro' ),
						$expected_url
					);
				} elseif ( false === strpos( $result['webhook_url'], home_url( '/' ) ) ) {
					$result['warning'] = sprintf(
						/* translators: 1: current webhook URL, 2: expected URL */
						__( 'Webhook points to a different site (%1$s). Expected: %2$s', 'mcp-ai-wpoos-pro' ),
						$result['webhook_url'],
						$expected_url
					);
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Register this site's webhook URL with Telegram (setWebhook).
	 */
	public function ajax_set_telegram_webhook() {
		check_ajax_referer( 'wp_mcp_ai_set_telegram_webhook', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Guard: the Telegram webhook REST route
		// (/wp-json/mcp-ai/v1/webhooks/telegram[/{connection_id}]) is registered by
		// WP_MCP_AI_Telegram_Webhook_Controller, which is only loaded when the
		// Chat Channels Toolkit is enabled (see chat-channels-toolkit-init.php and
		// the require_once in mcp-ai-wpoos-pro.php). Registering a webhook with
		// Telegram while the toolkit is disabled succeeds at the Telegram API but
		// every subsequent update is delivered to a route that does not exist,
		// producing "Wrong response from the webhook: 404 Not Found" in Telegram's
		// webhook status. Refuse to call setWebhook until the toolkit is active.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_chat_channels_toolkit'] ) ) {
			wp_send_json_error(
				__( 'The Chat Channels Toolkit is disabled. Enable it under NV oOS → Tools → Toolkits before registering a Telegram webhook, otherwise Telegram will receive 404 Not Found for every incoming update.', 'mcp-ai-wpoos-pro' )
			);
			return;
		}

		$bot_token     = isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bot_token     = trim( (string) $bot_token );
		$secret_token  = isset( $_POST['secret_token'] ) ? wp_unslash( $_POST['secret_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$secret_token  = trim( (string) $secret_token );
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		// Fall back to stored credentials for both bot_token and secret_token
		// independently. The secret_token must be resolved even when the user
		// provides a bot_token in the form, otherwise the setWebhook call
		// omits the secret and Telegram stops sending the verification header,
		// resulting in 403 Forbidden on subsequent webhook deliveries.
		// Only fetch the stored connection when at least one value is missing.
		if ( ( empty( $bot_token ) || empty( $secret_token ) ) && ! empty( $connection_id ) ) {
			$stored_connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( empty( $bot_token ) && $stored_connection && ! empty( $stored_connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}

			if ( empty( $secret_token ) && $stored_connection && ! empty( $stored_connection['secret_token'] ) ) {
				$secret_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['secret_token'] );
			}
		}

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Bot Token is required. Save the connection first or enter the token above.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
			wp_send_json_error( __( 'The bot token format is invalid.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Auto-generate a secret_token when none is configured so that the
		// webhook controller can always verify the X-Telegram-Bot-Api-Secret-Token
		// header. Without this, setWebhook omits the secret_token parameter,
		// Telegram does not send the header on deliveries, and our
		// validate_webhook_secret() rejects every update with 403 Forbidden.
		if ( empty( $secret_token ) && ! empty( $connection_id ) ) {
			$secret_token = wp_generate_password( 64, false );

			// Persist the generated token on the connection so that
			// validate_webhook_secret() can retrieve it later.
			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $all_connections ) && isset( $all_connections[ $connection_id ] ) ) {
				$all_connections[ $connection_id ]['secret_token'] = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $secret_token );
				update_option( 'wp_mcp_ai_pro_remote_sites', $all_connections );
			}
		}

		// Validate secret token characters (A–Z, a–z, 0–9, _ and – only; 1–256 chars).
		if ( ! empty( $secret_token ) && ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_secret_token( $secret_token ) ) {
			wp_send_json_error( __( 'Webhook Secret Token may only contain A–Z, a–z, 0–9, underscores and hyphens (1–256 characters).', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$webhook_url = ! empty( $connection_id )
			? home_url( '/wp-json/mcp-ai/v1/webhooks/telegram/' . $connection_id )
			: home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' );

		// Webhook URL must use HTTPS (Telegram requirement).
		if ( 0 !== strpos( $webhook_url, 'https://' ) ) {
			wp_send_json_error( __( 'Telegram requires a webhook URL using HTTPS. Ensure your site is configured with an HTTPS home URL (Settings → General → WordPress Address).', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Include all update types handled by the webhook controller so that
		// Telegram delivers channel posts, membership changes, inline queries,
		// pre-checkout queries and payment notifications alongside messages.
		$body = array(
			'url'             => $webhook_url,
			'max_connections' => 40,
			'allowed_updates' => array(
				'message',
				'edited_message',
				'channel_post',
				'edited_channel_post',
				'callback_query',
				'inline_query',
				'my_chat_member',
				'chat_member',
				'pre_checkout_query',
			),
		);

		if ( ! empty( $secret_token ) ) {
			$body['secret_token'] = $secret_token;
		}

		$api_base = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
		$response = wp_remote_post(
			$api_base . '/setWebhook',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
			$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response from Telegram API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$description
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'webhook_url' => $webhook_url,
				'message'     => __( 'Webhook registered successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * AJAX handler: Retrieve webhook info from Telegram (getWebhookInfo).
	 */
	public function ajax_get_telegram_webhook_info() {
		check_ajax_referer( 'wp_mcp_ai_get_telegram_webhook_info', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token     = isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bot_token     = trim( (string) $bot_token );
		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		// Fall back to stored token.
		if ( empty( $bot_token ) ) {
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}
		}

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Bot Token is required. Save the connection first or enter the token above.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
			wp_send_json_error( __( 'The bot token format is invalid.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$api_base = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
		$response = wp_remote_get( $api_base . '/getWebhookInfo', array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
			$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response from Telegram API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$description
				)
			);
			return;
		}

		$wh     = isset( $data['result'] ) ? $data['result'] : array();
		$result = array(
			'webhook_url'            => isset( $wh['url'] ) ? $wh['url'] : '',
			'pending_updates'        => isset( $wh['pending_update_count'] ) ? (int) $wh['pending_update_count'] : 0,
			'has_custom_certificate' => ! empty( $wh['has_custom_certificate'] ),
			'max_connections'        => isset( $wh['max_connections'] ) ? (int) $wh['max_connections'] : 0,
			'last_error_message'     => isset( $wh['last_error_message'] ) ? $wh['last_error_message'] : '',
		);

		$expected_url = ! empty( $connection_id )
			? home_url( '/wp-json/mcp-ai/v1/webhooks/telegram/' . $connection_id )
			: home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' );
		if ( empty( $result['webhook_url'] ) ) {
			$result['warning'] = sprintf(
				/* translators: %s: expected webhook URL */
				__( 'No webhook is set. Click Set Webhook to register: %s', 'mcp-ai-wpoos-pro' ),
				$expected_url
			);
		} elseif ( false === strpos( $result['webhook_url'], home_url( '/' ) ) ) {
			$result['warning'] = sprintf(
				/* translators: 1: current webhook URL, 2: expected URL */
				__( 'Webhook points to a different site (%1$s). Expected: %2$s', 'mcp-ai-wpoos-pro' ),
				$result['webhook_url'],
				$expected_url
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Register slash commands with Telegram (setMyCommands).
	 *
	 * Reads enabled commands and their descriptions from the saved connection
	 * and calls the Telegram Bot API setMyCommands endpoint to make them
	 * appear in the "/" command menu for users.
	 *
	 * Accepts (POST): connection_id, nonce.
	 */
	public function ajax_register_telegram_commands() {
		check_ajax_referer( 'wp_mcp_ai_register_telegram_commands', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( empty( $connection ) || empty( $connection['api_key'] ) ) {
			wp_send_json_error( __( 'Connection not found or missing bot token. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Could not decrypt the bot token. Re-enter and save the bot token.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
			wp_send_json_error( __( 'The stored bot token format is invalid.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$disabled_commands    = isset( $connection['disabled_commands'] ) && is_array( $connection['disabled_commands'] ) ? $connection['disabled_commands'] : array();
		$command_descriptions = isset( $connection['command_descriptions'] ) && is_array( $connection['command_descriptions'] ) ? $connection['command_descriptions'] : array();

		// Default descriptions for each built-in command.
		$defaults = array(
			'start'    => __( 'Start the bot & see welcome message', 'mcp-ai-wpoos-pro' ),
			'help'     => __( 'Show available commands', 'mcp-ai-wpoos-pro' ),
			'tools'    => __( 'Browse AI tools', 'mcp-ai-wpoos-pro' ),
			'balance'  => __( 'Check credits balance', 'mcp-ai-wpoos-pro' ),
			'app'      => __( 'Open the Mini App', 'mcp-ai-wpoos-pro' ),
			'settings' => __( 'Open Mini App settings', 'mcp-ai-wpoos-pro' ),
			'status'   => __( 'Check bot connection status', 'mcp-ai-wpoos-pro' ),
			'cancel'   => __( 'Reset conversation history', 'mcp-ai-wpoos-pro' ),
		);

		$commands = array();

		foreach ( $defaults as $cmd_name => $default_desc ) {
			if ( in_array( $cmd_name, $disabled_commands, true ) ) {
				continue;
			}
			$desc       = isset( $command_descriptions[ $cmd_name ] ) && '' !== trim( $command_descriptions[ $cmd_name ] )
				? sanitize_text_field( $command_descriptions[ $cmd_name ] )
				: $default_desc;
			$commands[] = array(
				'command'     => $cmd_name,
				'description' => substr( $desc, 0, 256 ),
			);
		}

		if ( empty( $commands ) ) {
			// No enabled commands — delete all registered commands.
			$endpoint = sprintf( 'https://api.telegram.org/bot%s/deleteMyCommands', rawurlencode( $bot_token ) );
			$response = wp_remote_post( $endpoint, array( 'timeout' => 20 ) );
		} else {
			$endpoint = sprintf( 'https://api.telegram.org/bot%s/setMyCommands', rawurlencode( $bot_token ) );
			$body     = wp_json_encode( array( 'commands' => $commands ) );

			if ( false === $body ) {
				wp_send_json_error( __( 'Failed to encode command list.', 'mcp-ai-wpoos-pro' ) );
				return;
			}

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 20,
					'body'    => $body,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data['ok'] ) ) {
			$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response from Telegram API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$description
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'registered' => count( $commands ),
				'message'    => empty( $commands )
					? __( 'All commands removed from Telegram.', 'mcp-ai-wpoos-pro' )
					: sprintf(
						/* translators: %d: number of commands registered */
						_n( '%d command registered with Telegram.', '%d commands registered with Telegram.', count( $commands ), 'mcp-ai-wpoos-pro' ),
						count( $commands )
					),
			)
		);
	}

	/**
	 * AJAX handler: Test Slack bot token by calling the auth.test API.
	 *
	 * Accepts (POST): bot_token, connection_id, nonce.
	 */
	public function ajax_test_slack_live() {
		check_ajax_referer( 'wp_mcp_ai_test_slack_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token = isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tokens must not be sanitized.
		$bot_token = trim( (string) $bot_token );

		// Fall back to stored token when the field is blank.
		if ( empty( $bot_token ) ) {
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}
		}

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Bot Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_post(
			'https://slack.com/api/auth.test',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $bot_token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Slack API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['ok'] ) ) {
			$error   = isset( $body['error'] ) ? $body['error'] : 'unknown_error';
			$message = $this->get_slack_friendly_error_message( $error );
			wp_send_json_error( $message );
			return;
		}

		wp_send_json_success(
			array(
				'team'     => isset( $body['team'] ) ? $body['team'] : '',
				'bot_user' => isset( $body['user'] ) ? $body['user'] : '',
				'team_id'  => isset( $body['team_id'] ) ? $body['team_id'] : '',
				'user_id'  => isset( $body['user_id'] ) ? $body['user_id'] : '',
			)
		);
	}

	/**
	 * Map a Slack API error code to a human-readable, actionable error message.
	 *
	 * @param string $error_code Slack API error code (e.g. 'account_inactive').
	 * @return string Translated error message with actionable guidance.
	 */
	protected function get_slack_friendly_error_message( $error_code ) {
		$known = array(
			'account_inactive'   => __( 'Slack API error: account_inactive — The bot account associated with this token has been deactivated. Please check that your Slack app is still installed in the workspace and that the bot user has not been removed. Generate a new Bot Token from your Slack app configuration (api.slack.com/apps) and update the token here.', 'mcp-ai-wpoos-pro' ),
			'invalid_auth'       => __( 'Slack API error: invalid_auth — The bot token is invalid or has been revoked. Please generate a new token from your Slack app and update it here.', 'mcp-ai-wpoos-pro' ),
			'token_revoked'      => __( 'Slack API error: token_revoked — This token has been revoked. Please reinstall your Slack app to the workspace and update the token here.', 'mcp-ai-wpoos-pro' ),
			'not_authed'         => __( 'Slack API error: not_authed — No bot token was provided. Please enter a valid Bot Token (xoxb-).', 'mcp-ai-wpoos-pro' ),
			'missing_scope'      => __( 'Slack API error: missing_scope — The bot token does not have the required OAuth scopes. Please update your Slack app permissions and reinstall the app.', 'mcp-ai-wpoos-pro' ),
			'org_login_required' => __( 'Slack API error: org_login_required — The token requires re-authentication at the organization level. Please reinstall your Slack app.', 'mcp-ai-wpoos-pro' ),
			'ekm_access_denied'  => __( 'Slack API error: ekm_access_denied — Access was denied by Enterprise Key Management. Please contact your Slack workspace administrator.', 'mcp-ai-wpoos-pro' ),
		);

		if ( isset( $known[ $error_code ] ) ) {
			return $known[ $error_code ];
		}

		return sprintf(
			/* translators: %s: Slack API error code */
			__( 'Slack API error: %s', 'mcp-ai-wpoos-pro' ),
			$error_code
		);
	}

	/**
	 * AJAX handler: Simulate an incoming Slack message and return the AI reply.
	 * Optionally sends the reply to a Slack channel via chat.postMessage.
	 *
	 * Accepts (POST): connection_id, test_message, test_channel (optional), nonce.
	 */
	public function ajax_test_slack_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_slack_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_channel  = isset( $_POST['test_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['test_channel'] ) ) : '';

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

		// If a channel was provided, send the reply via the Slack API.
		if ( ! empty( $test_channel ) && ! empty( $connection['api_key'] ) ) {
			$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			if ( '' !== $bot_token ) {
				// Load the Slack event controller so we can use its static
				// markdown-to-mrkdwn converter and Block Kit builder.
				$slack_controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-slack-event-controller.php';
				if ( file_exists( $slack_controller_file ) && ! class_exists( 'WP_MCP_AI_Slack_Event_Controller' ) ) {
					require_once $slack_controller_file;
				}

				// Convert the AI Markdown response to Slack mrkdwn so the reply
				// renders with proper formatting (bold, italic, code, links) instead
				// of showing raw Markdown syntax in the Slack channel.
				$mrkdwn_content = class_exists( 'WP_MCP_AI_Slack_Event_Controller' )
					? WP_MCP_AI_Slack_Event_Controller::convert_markdown_to_mrkdwn( $ai_reply )
					: wp_strip_all_tags( $ai_reply );

				$blocks = class_exists( 'WP_MCP_AI_Slack_Event_Controller' )
					? WP_MCP_AI_Slack_Event_Controller::build_slack_blocks( $mrkdwn_content )
					: array();

				$fallback_text = wp_strip_all_tags( $ai_reply );

				$post_data = array(
					'channel' => $test_channel,
					'text'    => $fallback_text,
				);

				if ( ! empty( $blocks ) ) {
					$post_data['blocks'] = $blocks;
				}

				$payload = wp_json_encode( $post_data );

				if ( false !== $payload ) {
					$send_result = wp_remote_post(
						'https://slack.com/api/chat.postMessage',
						array(
							'headers' => array(
								'Authorization' => 'Bearer ' . $bot_token,
								'Content-Type'  => 'application/json; charset=utf-8',
							),
							'timeout' => 20,
							'body'    => $payload,
						)
					);

					if ( ! is_wp_error( $send_result ) ) {
						$send_body = json_decode( wp_remote_retrieve_body( $send_result ), true );
						if ( ! empty( $send_body['ok'] ) ) {
							$result['sent'] = true;
						} else {
							$send_error_code      = isset( $send_body['error'] ) ? $send_body['error'] : 'unknown_error';
							$result['send_error'] = $this->get_slack_friendly_error_message( $send_error_code );
						}
					} else {
						$result['send_error'] = $send_result->get_error_message();
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Test Discord bot token by calling the users/@me API.
	 *
	 * Accepts (POST): bot_token, connection_id, nonce.
	 */
	public function ajax_test_discord_live() {
		check_ajax_referer( 'wp_mcp_ai_test_discord_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$bot_token = isset( $_POST['bot_token'] ) ? wp_unslash( $_POST['bot_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tokens must not be sanitized.
		$bot_token = trim( (string) $bot_token );

		// Fall back to stored token when the field is blank.
		if ( empty( $bot_token ) ) {
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['api_key'] );
			}
		}

		if ( empty( $bot_token ) ) {
			wp_send_json_error( __( 'Bot Token is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_get(
			'https://discord.com/api/v10/users/@me',
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $bot_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Discord API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = isset( $body['message'] ) ? $body['message'] : __( 'Invalid response from Discord API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Discord API error: %s', 'mcp-ai-wpoos-pro' ),
					$error_msg
				)
			);
			return;
		}

		$username = isset( $body['username'] ) ? $body['username'] : '';
		// Discord migrated to unique usernames (2023+); discriminator '0' means the new system is in use.
		$discriminator = isset( $body['discriminator'] ) && '0' !== $body['discriminator'] ? '#' . $body['discriminator'] : '';

		wp_send_json_success(
			array(
				'bot_username' => $username . $discriminator,
				'bot_id'       => isset( $body['id'] ) ? $body['id'] : '',
			)
		);
	}

	/**
	 * AJAX handler: Simulate an incoming Discord message and return the AI reply.
	 * Optionally sends the reply to a Discord channel via the Bot API.
	 *
	 * Accepts (POST): connection_id, test_message, test_channel (optional), nonce.
	 */
	public function ajax_test_discord_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_discord_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$test_channel  = isset( $_POST['test_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['test_channel'] ) ) : '';

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

		// If a channel ID was provided, send the reply via the Discord Bot API.
		if ( ! empty( $test_channel ) && ! empty( $connection['api_key'] ) ) {
			$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			if ( '' !== $bot_token ) {
				$discord_body = wp_strip_all_tags( $ai_reply );
				$discord_body = html_entity_decode( $discord_body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				// Discord enforces a 2000-character limit for text messages.
				if ( mb_strlen( $discord_body ) > 2000 ) {
					$discord_body = mb_substr( $discord_body, 0, 1997 ) . '...';
				}

				$endpoint = 'https://discord.com/api/v10/channels/' . rawurlencode( $test_channel ) . '/messages';
				$payload  = wp_json_encode( array( 'content' => $discord_body ) );

				if ( false !== $payload ) {
					$send_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array(
								'Authorization' => 'Bot ' . $bot_token,
								'Content-Type'  => 'application/json',
							),
							'timeout' => 20,
							'body'    => $payload,
						)
					);

					if ( ! is_wp_error( $send_result ) && 200 === (int) wp_remote_retrieve_response_code( $send_result ) ) {
						$result['sent'] = true;
					} else {
						$send_body            = is_wp_error( $send_result ) ? '' : wp_remote_retrieve_body( $send_result );
						$send_error_decoded   = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
						$result['send_error'] = is_wp_error( $send_result )
							? $send_result->get_error_message()
							: ( isset( $send_error_decoded['message'] ) ? $send_error_decoded['message'] : $send_body );
					}
				}
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Test Microsoft Teams Graph token by calling the Microsoft Graph /me endpoint.
	 *
	 * Accepts (POST): graph_token, connection_id, nonce.
	 */
	public function ajax_test_teams_live() {
		check_ajax_referer( 'wp_mcp_ai_test_teams_live', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$graph_token = isset( $_POST['graph_token'] ) ? wp_unslash( $_POST['graph_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tokens must not be sanitized.
		$graph_token = trim( (string) $graph_token );

		// Fall back to stored token when the field is blank.
		if ( empty( $graph_token ) ) {
			$connection_id     = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
			$stored_connection = ! empty( $connection_id ) ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;
			if ( ! empty( $stored_connection['token'] ) ) {
				$graph_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_connection['token'] );
			}
		}

		if ( empty( $graph_token ) ) {
			wp_send_json_error( __( 'Microsoft Graph Access Token is required. Enter it in the Graph Token field or save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $graph_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Microsoft Graph API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid response from Microsoft Graph API.', 'mcp-ai-wpoos-pro' );
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Microsoft Graph API error: %s', 'mcp-ai-wpoos-pro' ),
					$error_msg
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'display_name' => isset( $body['displayName'] ) ? $body['displayName'] : '',
				'user_id'      => isset( $body['id'] ) ? $body['id'] : '',
				'mail'         => isset( $body['mail'] ) ? $body['mail'] : '',
			)
		);
	}

	/**
	 * AJAX handler: Simulate an incoming Teams message and return the AI reply.
	 *
	 * Accepts (POST): connection_id, test_message, nonce.
	 */
	public function ajax_test_teams_auto_reply() {
		check_ajax_referer( 'wp_mcp_ai_test_teams_auto_reply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
		$test_message  = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';

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

		wp_send_json_success(
			array(
				'ai_reply' => $ai_reply,
			)
		);
	}

	/**
	 * AJAX handler: Generate a Microsoft 365 Copilot declarative agent manifest.
	 *
	 * Builds a declarativeAgent.json manifest pre-filled with data from the
	 * saved Teams connection (name, App ID, assistant instructions, site URL).
	 * The manifest is returned inline so the browser can offer it as a download
	 * without writing any files to disk.
	 *
	 * Reference: https://learn.microsoft.com/en-us/microsoft-365-copilot/extensibility/overview-declarative-agent
	 *
	 * Accepts (POST): connection_id, nonce.
	 */
	public function ajax_generate_teams_manifest() {
		check_ajax_referer( 'wp_mcp_ai_generate_teams_manifest', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required. Save the connection first.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			wp_send_json_error( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Resolve display name: prefer connection name, fall back to site title.
		$agent_name = isset( $connection['name'] ) && '' !== trim( $connection['name'] )
			? sanitize_text_field( $connection['name'] )
			: get_bloginfo( 'name' );

		// Retrieve the first assigned assistant to populate instructions.
		$assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		$instructions    = '';
		$assistant_title = '';
		if ( ! empty( $assistant_ids ) ) {
			$assistant_post = get_post( $assistant_ids[0] );
			if ( $assistant_post instanceof WP_Post && 'publish' === get_post_status( $assistant_post ) ) {
				$assistant_title = $assistant_post->post_title;
				// System prompt is stored in post meta.
				$system_prompt = get_post_meta( $assistant_post->ID, '_wp_mcp_ai_system_prompt', true );
				if ( ! empty( $system_prompt ) ) {
					$instructions = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $system_prompt ) ) );
				}
			}
		}

		if ( '' === $instructions ) {
			/* translators: %s: agent name */
			$instructions = sprintf( __( 'You are %s, a helpful AI assistant.', 'mcp-ai-wpoos-pro' ), $agent_name );
		}

		$site_url = home_url();

		/**
		 * Declarative agent manifest (schema v1.5).
		 *
		 * @see https://learn.microsoft.com/en-us/microsoft-365-copilot/extensibility/declarative-agent-manifest-1.5
		 */
		$manifest = array(
			'$schema'               => 'https://developer.microsoft.com/json-schemas/copilot/declarative-agent/v1.5/schema.json',
			'version'               => 'v1.5',
			'name'                  => $agent_name,
			'description'           => '' !== $assistant_title
				? sprintf(
					/* translators: 1: assistant title, 2: site URL */
					__( '%1$s — AI assistant powered by NV oOS at %2$s', 'mcp-ai-wpoos-pro' ),
					$assistant_title,
					$site_url
				)
				: sprintf(
					/* translators: %s: site URL */
					__( 'AI assistant powered by NV oOS at %s', 'mcp-ai-wpoos-pro' ),
					$site_url
				),
			'instructions'          => $instructions,
			'conversation_starters' => array(
				array(
					'title' => __( 'Get started', 'mcp-ai-wpoos-pro' ),
					'text'  => __( 'What can you help me with?', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'title' => __( 'About this assistant', 'mcp-ai-wpoos-pro' ),
					'text'  => __( 'Tell me about your capabilities.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);

		// Include App ID as the bot_id when available.
		$app_id = isset( $connection['app_id'] ) ? sanitize_text_field( $connection['app_id'] ) : '';
		if ( '' !== $app_id ) {
			$manifest['bot'] = array( 'bot_id' => $app_id );
		}

		/**
		 * Filters the declarative agent manifest array before it is returned.
		 *
		 * @param array  $manifest      The manifest array.
		 * @param string $connection_id The Teams connection ID.
		 * @param array  $connection    The full connection data.
		 */
		$manifest = apply_filters( 'wp_mcp_ai_teams_declarative_agent_manifest', $manifest, $connection_id, $connection );

		wp_send_json_success( array( 'manifest' => $manifest ) );
	}

	/**
	 * Resolve enabled_services from POST data based on connection type.
	 *
	 * Office 365 connections use office365_enabled_services[] and iCloud connections
	 * use icloud_enabled_services[]. Each value is validated against the known service
	 * keys for the respective connection type.
	 *
	 * @param string $connection_type The connection type being saved.
	 * @return array Sanitised list of enabled service keys, or empty array if not applicable.
	 */
	private function resolve_enabled_services( $connection_type ) {
		$allowed = array();
		$field   = '';

		if ( 'office365' === $connection_type ) {
			$allowed = array( 'outlook_mail', 'onedrive' );
			$field   = 'office365_enabled_services';
		} elseif ( 'icloud' === $connection_type ) {
			$allowed = array( 'icloud_drive' );
			$field   = 'icloud_enabled_services';
		}

		if ( empty( $field ) || ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		$raw = array_map( 'sanitize_key', wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return array_values( array_intersect( $raw, $allowed ) );
	}

	/**
	 * Build the post_type_access map from the submitted form data.
	 *
	 * Each built-in post type has four checkbox fields in the form:
	 * pt_{slug}_read, pt_{slug}_create, pt_{slug}_update, pt_{slug}_delete.
	 * Custom post types listed in the `custom_post_types` text field receive
	 * the same treatment.  Only 'read', 'create', 'update', and 'delete' are
	 * accepted as operation values.
	 *
	 * Note: nonce verification is performed by the calling method
	 * handle_actions() before this helper is invoked.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sanitized post_type_access map, e.g.
	 *               array( 'post' => array( 'read' ), 'page' => array( 'read', 'create' ) ).
	 *               Returns an empty array when access controls are not configured
	 *               (all post types allowed, read-only – backward compatible).
	 */
	private function resolve_post_type_access() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['enable_pt_access_controls'] ) ) {
			return array();
		}

		$valid_operations = array( 'read', 'create', 'update', 'delete' );

		$built_in_types = array( 'post', 'page', 'attachment' );

		// Auto-discover public custom post types registered on this site.
		$discovered_types = array_keys( get_post_types( array( 'public' => true, '_builtin' => false ), 'names' ) );

		// Merge custom post types from the text field (for non-public or remote-only types).
		$custom_raw   = isset( $_POST['custom_post_types'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_post_types'] ) ) : '';
		$custom_types = array();

		if ( ! empty( $custom_raw ) ) {
			foreach ( explode( ',', $custom_raw ) as $cpt ) {
				$slug = sanitize_key( trim( $cpt ) );
				if ( ! empty( $slug ) ) {
					$custom_types[] = $slug;
				}
			}
		}

		$all_types = array_unique( array_merge( $built_in_types, $discovered_types, $custom_types ) );
		$access    = array();

		foreach ( $all_types as $post_type ) {
			$ops = array();

			foreach ( $valid_operations as $op ) {
				$field = 'pt_' . $post_type . '_' . $op;
				if ( ! empty( $_POST[ $field ] ) ) {
					$ops[] = $op;
				}
			}

			// Only include post types where at least one operation is enabled.
			if ( ! empty( $ops ) ) {
				$access[ $post_type ] = $ops;
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $access;
	}

	/**
	 * Build the wc_resource_access map from the submitted form data.
	 *
	 * Each WooCommerce resource has four checkbox fields:
	 * wc_{resource}_read, wc_{resource}_create, wc_{resource}_update, wc_{resource}_delete.
	 *
	 * Note: nonce verification is performed by the calling method
	 * handle_actions() before this helper is invoked.
	 *
	 * @since 1.0.0
	 *
	 * @return array Sanitized wc_resource_access map, e.g.
	 *               array( 'products' => array( 'read', 'create' ), 'orders' => array( 'read' ) ).
	 *               Returns an empty array when WooCommerce access controls are not configured.
	 */
	private function resolve_wc_resource_access() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['enable_wc_access_controls'] ) ) {
			return array();
		}

		$valid_operations = array( 'read', 'create', 'update', 'delete' );
		$wc_resources     = array( 'products', 'orders', 'customers', 'categories' );
		$access           = array();

		foreach ( $wc_resources as $resource ) {
			$ops = array();

			foreach ( $valid_operations as $op ) {
				$field = 'wc_' . $resource . '_' . $op;
				if ( ! empty( $_POST[ $field ] ) ) {
					$ops[] = $op;
				}
			}

			// Only include resources where at least one operation is enabled.
			if ( ! empty( $ops ) ) {
				$access[ $resource ] = $ops;
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $access;
	}

	/**
	 * Build the jetengine_cct_access map from the submitted form data.
	 *
	 * Each JetEngine CCT has four checkbox fields:
	 * je_{slug}_read, je_{slug}_create, je_{slug}_update, je_{slug}_delete.
	 * CCT slugs are discovered from the form field names (je_*_read).
	 *
	 * Note: nonce verification is performed by the calling method
	 * handle_actions() before this helper is invoked.
	 *
	 * @since 1.2.0
	 *
	 * @return array Sanitized jetengine_cct_access map, e.g.
	 *               array( 'attendees' => array( 'read', 'create' ), 'inventory' => array( 'read' ) ).
	 *               Returns an empty array when JetEngine access controls are not configured.
	 */
	private function resolve_jetengine_cct_access() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['enable_je_access_controls'] ) ) {
			return array();
		}

		$valid_operations = array( 'read', 'create', 'update', 'delete' );
		$access           = array();

		// Discover CCT slugs from the form field names (je_{slug}_read).
		foreach ( array_keys( $_POST ) as $field ) {
			if ( ! preg_match( '/^je_(.+)_read$/', $field, $matches ) ) {
				continue;
			}

			$cct_slug = sanitize_key( $matches[1] );
			$ops      = array();

			foreach ( $valid_operations as $op ) {
				$op_field = 'je_' . $cct_slug . '_' . $op;
				if ( ! empty( $_POST[ $op_field ] ) ) {
					$ops[] = $op;
				}
			}

			if ( ! empty( $ops ) ) {
				$access[ $cct_slug ] = $ops;
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $access;
	}

	/**
	 * AJAX handler: Discover JetEngine CCTs on a remote connection.
	 *
	 * Calls the remote site's /wp-json/jet-cct/v1/ endpoint to enumerate
	 * available CCTs with REST endpoints enabled.
	 *
	 * @since 1.2.0
	 */
	public function ajax_discover_jetengine_ccts() {
		check_ajax_referer( 'discover_jetengine_ccts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

		if ( empty( $connection_id ) ) {
			wp_send_json_error( __( 'Connection ID is required.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			wp_send_json_error( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$ccts = WP_MCP_AI_Pro_Remote_Site_Manager::discover_jetengine_ccts( $connection );

		if ( is_wp_error( $ccts ) ) {
			wp_send_json_error( $ccts->get_error_message() );
			return;
		}

		if ( empty( $ccts ) ) {
			wp_send_json_success( array( 'ccts' => array() ) );
			return;
		}

		$result = array();
		foreach ( $ccts as $slug => $cct ) {
			$result[] = array(
				'slug'  => $slug,
				'label' => isset( $cct['label'] ) ? $cct['label'] : $slug,
			);
		}

		wp_send_json_success( array( 'ccts' => $result ) );
	}

	/**
	 * Start the Upwork OAuth 2.0 authorization flow.
	 *
	 * Redirects the user to the Upwork authorization endpoint after storing
	 * a CSRF state token in a transient.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_upwork_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'upwork' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not an Upwork connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Please save the Client ID and Client Secret before connecting.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$state     = wp_generate_uuid4();
		$transient = 'wp_mcp_ai_upwork_oauth_state_' . md5( $state );

		set_transient(
			$transient,
			array(
				'user_id'       => get_current_user_id(),
				'connection_id' => $connection_id,
				'time'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$params = array(
			'response_type' => 'code',
			'client_id'     => $connection['client_id'],
			'redirect_uri'  => add_query_arg(
				array(
					'page'          => 'wp-mcp-ai-remote-sites',
					'oauth_handler' => 'upwork_oauth_callback',
				),
				admin_url( 'admin.php' )
			),
			'state'         => $state,
		);

		$authorize_url = add_query_arg( $params, 'https://www.upwork.com/ab/account-security/oauth2/authorize' );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle the Upwork OAuth 2.0 callback after user authorisation.
	 *
	 * Exchanges the authorisation code for tokens, fetches the user's Upwork
	 * display name, and saves the updated connection credentials.
	 *
	 * @since 1.0.0
	 */
	protected function handle_upwork_oauth_callback() {
		// OAuth callback — state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			wp_safe_redirect(
				admin_url(
					'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode(
						sprintf(
						/* translators: %s: OAuth error message */
							__( 'Upwork OAuth error: %s', 'mcp-ai-wpoos-pro' ),
							$error
						)
					)
				)
			);
			exit;
		}

		$transient_key = 'wp_mcp_ai_upwork_oauth_state_' . md5( $state );
		$state_data    = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( empty( $code ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'No authorization code received from Upwork.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$connection_id = $state_data['connection_id'];
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );

		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'upwork_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		// Exchange authorisation code for tokens.
		$response = wp_remote_post(
			'https://www.upwork.com/api/v3/oauth2/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $connection['client_id'],
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
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
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Upwork rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'Invalid response from Upwork.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		// Fall back to existing refresh_token if none returned (Upwork may omit it on re-auth).
		if ( '' === $refresh_token && ! empty( $connection['refresh_token'] ) ) {
			$refresh_token = $connection['refresh_token'];
		}

		if ( '' === $refresh_token ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'No refresh token received. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Fetch the connected user's display name.
		$upwork_username = '';
		if ( $access_token ) {
			$user_query    = '{ viewer { id nid name { fullName } } }';
			$user_response = wp_remote_post(
				'https://api.upwork.com/graphql',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'    => wp_json_encode( array( 'query' => $user_query ) ),
				)
			);
			if ( ! is_wp_error( $user_response ) && 200 === wp_remote_retrieve_response_code( $user_response ) ) {
				$user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
				if ( isset( $user_data['data']['viewer']['name']['fullName'] ) ) {
					$upwork_username = sanitize_text_field( $user_data['data']['viewer']['name']['fullName'] );
				}
			}
		}

		// Save the updated connection data.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => $connection['name'],
			'url'             => $connection['url'],
			'connection_type' => 'upwork',
			'auth_type'       => 'none',
			'client_id'       => $connection['client_id'],
			'client_secret'   => '',
			'refresh_token'   => $refresh_token,
			'user_email'      => $upwork_username ? $upwork_username : ( isset( $connection['user_email'] ) ? $connection['user_email'] : '' ),
			'enabled'         => $connection['enabled'],
		);
		// Flag already-encrypted client_secret to prevent double-encryption.
		$update_data['_client_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		$success_message = __( 'Upwork account connected successfully!', 'mcp-ai-wpoos-pro' );
		if ( $upwork_username ) {
			$success_message = sprintf(
				/* translators: %s: Upwork username */
				__( 'Upwork account connected successfully for %s!', 'mcp-ai-wpoos-pro' ),
				$upwork_username
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( $success_message ) ) );
		exit;
	}

	/**
	 * Handle LinkedIn OAuth 2.0 connect — redirect to LinkedIn authorization.
	 *
	 * @since 2.10.0
	 * @param string $connection_id Connection ID.
	 */
	protected function handle_linkedin_oauth_start( $connection_id ) {
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		if ( 'linkedin' !== $connection['connection_type'] ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'This is not a LinkedIn connection.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Build the LinkedIn authorization URL.
		$state  = wp_generate_uuid4();
		$scopes = array( 'openid', 'profile', 'email' );

		// Store state for CSRF verification.
		set_transient( 'wp_mcp_ai_linkedin_oauth_state_' . md5( $state ), $connection_id, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $connection['client_id'],
			'redirect_uri'  => add_query_arg(
				array(
					'page'          => 'wp-mcp-ai-remote-sites',
					'oauth_handler' => 'linkedin_oauth_callback',
				),
				admin_url( 'admin.php' )
			),
			'state'         => $state,
			'scope'         => implode( ' ', $scopes ),
		);

		$authorize_url = add_query_arg( $params, 'https://www.linkedin.com/oauth/v2/authorization' );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle the LinkedIn OAuth 2.0 callback after user authorization.
	 *
	 * @since 2.10.0
	 */
	protected function handle_linkedin_oauth_callback() {
		// OAuth callback — state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			wp_safe_redirect(
				admin_url(
					'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode(
						sprintf(
							/* translators: %s: OAuth error message */
							__( 'LinkedIn OAuth error: %s', 'mcp-ai-wpoos-pro' ),
							$error
						)
					)
				)
			);
			exit;
		}

		$transient_key = 'wp_mcp_ai_linkedin_oauth_state_' . md5( $state );
		$connection_id = get_transient( $transient_key );

		if ( ! $connection_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Invalid or expired OAuth state. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		delete_transient( $transient_key );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&error=' . rawurlencode( __( 'Connection not found.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Exchange authorization code for tokens.
		$redirect_uri = add_query_arg(
			array(
				'page'          => 'wp-mcp-ai-remote-sites',
				'oauth_handler' => 'linkedin_oauth_callback',
			),
			admin_url( 'admin.php' )
		);

		$token_response = wp_remote_post(
			'https://www.linkedin.com/oauth/v2/accessToken',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $connection['client_id'],
					'client_secret' => WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] ),
					'redirect_uri'  => $redirect_uri,
				),
			)
		);

		if ( is_wp_error( $token_response ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $token_response->get_error_message() ) ) );
			exit;
		}

		$status  = wp_remote_retrieve_response_code( $token_response );
		$body    = wp_remote_retrieve_body( $token_response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $status || ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			$err_msg = isset( $decoded['error_description'] ) ? $decoded['error_description'] : __( 'Unknown token exchange error.', 'mcp-ai-wpoos-pro' );
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $err_msg ) ) );
			exit;
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		// Fall back to existing refresh_token if none returned.
		if ( '' === $refresh_token && ! empty( $connection['refresh_token'] ) ) {
			$refresh_token = $connection['refresh_token'];
		}

		if ( '' === $refresh_token ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( __( 'No refresh token received. Please try again.', 'mcp-ai-wpoos-pro' ) ) ) );
			exit;
		}

		// Save the updated connection data.
		$update_data = array(
			'id'              => $connection_id,
			'name'            => $connection['name'],
			'url'             => $connection['url'],
			'connection_type' => 'linkedin',
			'auth_type'       => 'none',
			'client_id'       => $connection['client_id'],
			'client_secret'   => '',
			'refresh_token'   => $refresh_token,
			'user_email'      => isset( $connection['user_email'] ) ? $connection['user_email'] : '',
			'enabled'         => $connection['enabled'],
		);
		// Flag already-encrypted client_secret to prevent double-encryption.
		$update_data['_client_secret_encrypted'] = true;

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update_data );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id . '&oauth_success=' . rawurlencode( __( 'LinkedIn account connected successfully!', 'mcp-ai-wpoos-pro' ) ) ) );
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
