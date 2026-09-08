<?php
/**
 * Webhook Status admin page (ecosystem port - Wave F6, remote-sites admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in monolith
 * installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps with the `src/` root; per-mode seams - the base-owned
 * `WP_MCP_AI_PATH` Google OAuth/Calendar requires gain `defined( 'WP_MCP_AI_PATH' )` guards and the
 * not-yet-ported client/page requires (composio, telegram mini-app templates, google service account,
 * slack event controller, mesh-peer bidirectional sync) gain `file_exists()` guards with graceful
 * standalone degrade until those files land.
 *
 * Pro Webhook Status Admin Page
 *
 * Registers a standalone admin page under the NV oOS Pro Dashboard menu
 * for viewing and testing all active/configured webhook connections
 * (Telegram, WhatsApp, Google Chat, Slack, Discord, etc.).
 *
 * Industry-standard features:
 * - Summary status cards (total endpoints, active, errors)
 * - Per-connection health check with expected vs actual URL comparison
 * - One-click "Check Status" and "Set Webhook" actions
 * - Last error display with timestamps
 * - Pending update counts and connection metadata
 * - Color-coded health badges
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Webhook_Status_Page' ) ) {
	/**
	 * Standalone admin page for viewing and testing webhook connections.
	 *
	 * Registers the page under the NV oOS Pro Dashboard submenu and provides
	 * a unified interface for monitoring all webhook-capable connections
	 * configured in the plugin.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Webhook_Status_Page {

		/**
		 * Admin page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-webhook-status';

		/**
		 * AJAX nonce action name.
		 */
		const NONCE_ACTION = 'wp_mcp_ai_webhook_status';

		/**
		 * Actual WordPress hook name returned by add_submenu_page().
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Connection types that support webhooks.
		 *
		 * @var array
		 */
		private static $webhook_types = array(
			'telegram',
			'whatsapp',
			'slack',
			'discord',
			'microsoft_teams',
			'facebook_messenger',
			'google_chat',
			'twitter',
			'apple_messages',
		);

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Priority 26: parent nvoos-pro-dashboard menu registers at priority 25.
			add_action( 'admin_menu', array( $this, 'register_page' ), 26 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_wp_mcp_ai_webhook_status_check', array( $this, 'ajax_check_webhook_status' ) );
			add_action( 'wp_ajax_wp_mcp_ai_webhook_status_check_all', array( $this, 'ajax_check_all_webhooks' ) );
			add_action( 'wp_ajax_wp_mcp_ai_webhook_status_set', array( $this, 'ajax_set_webhook' ) );
			add_action( 'wp_ajax_wp_mcp_ai_webhook_status_delete', array( $this, 'ajax_delete_webhook' ) );
		}

		/**
		 * Register the admin submenu page.
		 *
		 * @return void
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Webhook Status', 'nvoos-content-graph-pro' ),
				__( 'Webhook Status', 'nvoos-content-graph-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the Webhook Status page.
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			$is_page = ! empty( $this->page_hook ) && $hook === $this->page_hook;

			if ( ! $is_page ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
				$is_page = isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) );
			}

			if ( ! $is_page ) {
				return;
			}

			wp_enqueue_style( 'dashicons' );
		}

		// -----------------------------------------------------------------
		// Helpers
		// -----------------------------------------------------------------

		/**
		 * Get all webhook-capable connections.
		 *
		 * @return array Array of connections keyed by connection ID.
		 */
		public static function get_webhook_connections() {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$manager_file = defined( 'NVOOS_CONTENT_GRAPH_PRO_PATH' )
					? NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-remote-site-manager.php'
					: '';
				if ( $manager_file && file_exists( $manager_file ) ) {
					require_once $manager_file;
				} else {
					return array();
				}
			}

			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( ! is_array( $all_connections ) ) {
				return array();
			}

			$webhook_connections = array();
			foreach ( $all_connections as $id => $connection ) {
				$type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';
				if ( in_array( $type, self::$webhook_types, true ) ) {
					$connection['id']           = $id;
					$webhook_connections[ $id ] = $connection;
				}
			}

			return $webhook_connections;
		}

		/**
		 * Get the expected webhook URL for a connection.
		 *
		 * @param string $connection_id   Connection ID.
		 * @param string $connection_type Connection type.
		 * @return string Expected webhook URL.
		 */
		public static function get_expected_webhook_url( $connection_id, $connection_type ) {
			$route_map = array(
				'telegram'           => 'webhooks/telegram',
				'whatsapp'           => 'webhooks/whatsapp',
				'slack'              => 'webhooks/slack',
				'discord'            => 'webhooks/discord',
				'microsoft_teams'    => 'webhooks/teams',
				'facebook_messenger' => 'webhooks/messenger',
				'google_chat'        => 'webhooks/google-chat',
				'twitter'            => 'webhooks/twitter',
				'apple_messages'     => 'webhooks/apple-messages',
			);

			$route = isset( $route_map[ $connection_type ] ) ? $route_map[ $connection_type ] : '';
			if ( empty( $route ) ) {
				return '';
			}

			$base_url = home_url( '/wp-json/mcp-ai/v1/' . $route );

			// Per-connection URL.
			if ( ! empty( $connection_id ) ) {
				return $base_url . '/' . $connection_id;
			}

			return $base_url;
		}

		/**
		 * Get a human-readable label for a connection type.
		 *
		 * @param string $type Connection type.
		 * @return string Label.
		 */
		public static function get_type_label( $type ) {
			$labels = array(
				'telegram'           => __( 'Telegram', 'nvoos-content-graph-pro' ),
				'whatsapp'           => __( 'WhatsApp', 'nvoos-content-graph-pro' ),
				'slack'              => __( 'Slack', 'nvoos-content-graph-pro' ),
				'discord'            => __( 'Discord', 'nvoos-content-graph-pro' ),
				'microsoft_teams'    => __( 'MS Teams', 'nvoos-content-graph-pro' ),
				'facebook_messenger' => __( 'Messenger', 'nvoos-content-graph-pro' ),
				'google_chat'        => __( 'Google Chat', 'nvoos-content-graph-pro' ),
				'twitter'            => __( 'Twitter / X', 'nvoos-content-graph-pro' ),
				'apple_messages'     => __( 'Apple Messages', 'nvoos-content-graph-pro' ),
			);

			return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
		}

		/**
		 * Get the badge color for a connection type.
		 *
		 * @param string $type Connection type.
		 * @return string Hex color.
		 */
		public static function get_type_color( $type ) {
			$colors = array(
				'telegram'           => '#0088cc',
				'whatsapp'           => '#25d366',
				'slack'              => '#4a154b',
				'discord'            => '#5865f2',
				'microsoft_teams'    => '#6264a7',
				'facebook_messenger' => '#0084ff',
				'google_chat'        => '#00ac47',
				'twitter'            => '#1da1f2',
				'apple_messages'     => '#34c759',
			);

			return isset( $colors[ $type ] ) ? $colors[ $type ] : '#50575e';
		}

		/**
		 * Check webhook status for a single Telegram connection.
		 *
		 * @param array $connection Connection data array.
		 * @return array Status result array.
		 */
		public static function check_telegram_webhook( $connection ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Remote Site Manager not available.', 'nvoos-content-graph-pro' ),
				);
			}

			$bot_token = '';
			if ( ! empty( $connection['api_key'] ) ) {
				$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			}

			if ( empty( $bot_token ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'No bot token configured.', 'nvoos-content-graph-pro' ),
				);
			}

			if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Bot token format is invalid.', 'nvoos-content-graph-pro' ),
				);
			}

			// Call Telegram API: getMe.
			$api_base    = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
			$get_me_resp = wp_remote_get( $api_base . '/getMe', array( 'timeout' => 15 ) );

			$bot_info = array();
			if ( ! is_wp_error( $get_me_resp ) && 200 === (int) wp_remote_retrieve_response_code( $get_me_resp ) ) {
				$get_me_data = json_decode( wp_remote_retrieve_body( $get_me_resp ), true );
				if ( ! empty( $get_me_data['ok'] ) && isset( $get_me_data['result'] ) ) {
					$bot_info = array(
						'bot_id'       => isset( $get_me_data['result']['id'] ) ? (int) $get_me_data['result']['id'] : 0,
						'bot_username' => isset( $get_me_data['result']['username'] ) ? $get_me_data['result']['username'] : '',
						'bot_name'     => isset( $get_me_data['result']['first_name'] ) ? $get_me_data['result']['first_name'] : '',
					);
				}
			}

			// Call Telegram API: getWebhookInfo.
			$wh_resp = wp_remote_get( $api_base . '/getWebhookInfo', array( 'timeout' => 15 ) );

			if ( is_wp_error( $wh_resp ) ) {
				return array(
					'status'   => 'error',
					'message'  => sprintf(
						/* translators: %s: error message */
						__( 'Failed to connect to Telegram API: %s', 'nvoos-content-graph-pro' ),
						$wh_resp->get_error_message()
					),
					'bot_info' => $bot_info,
				);
			}

			$code = wp_remote_retrieve_response_code( $wh_resp );
			$data = json_decode( wp_remote_retrieve_body( $wh_resp ), true );

			if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
				$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response.', 'nvoos-content-graph-pro' );
				return array(
					'status'   => 'error',
					'message'  => sprintf(
						/* translators: %s: error description */
						__( 'Telegram API error: %s', 'nvoos-content-graph-pro' ),
						$description
					),
					'bot_info' => $bot_info,
				);
			}

			$wh              = isset( $data['result'] ) ? $data['result'] : array();
			$connection_id   = isset( $connection['id'] ) ? $connection['id'] : '';
			$webhook_url     = isset( $wh['url'] ) ? $wh['url'] : '';
			$expected_url    = self::get_expected_webhook_url( $connection_id, 'telegram' );
			$url_matches     = ! empty( $webhook_url ) && false !== strpos( $webhook_url, home_url( '/' ) );
			$pending         = isset( $wh['pending_update_count'] ) ? (int) $wh['pending_update_count'] : 0;
			$last_error      = isset( $wh['last_error_message'] ) ? $wh['last_error_message'] : '';
			$last_error_ts   = isset( $wh['last_error_date'] ) ? (int) $wh['last_error_date'] : 0;
			$max_conn        = isset( $wh['max_connections'] ) ? (int) $wh['max_connections'] : 0;
			$allowed_updates = isset( $wh['allowed_updates'] ) ? $wh['allowed_updates'] : array();

			$status = 'ok';
			if ( empty( $webhook_url ) ) {
				$status = 'not_set';
			} elseif ( ! $url_matches ) {
				$status = 'mismatch';
			} elseif ( ! empty( $last_error ) ) {
				$status = 'warning';
			}

			// Check whether a secret_token is configured on this connection.
			// When missing, the webhook controller rejects every delivery with
			// 403 Forbidden because it cannot verify the header Telegram sends.
			$has_secret_token = ! empty( $connection['secret_token'] );

			return array(
				'status'           => $status,
				'webhook_url'      => $webhook_url,
				'expected_url'     => $expected_url,
				'url_matches'      => $url_matches,
				'pending_updates'  => $pending,
				'last_error'       => $last_error,
				'last_error_date'  => $last_error_ts > 0 ? gmdate( 'Y-m-d H:i:s', $last_error_ts ) : '',
				'max_connections'  => $max_conn,
				'allowed_updates'  => $allowed_updates,
				'bot_info'         => $bot_info,
				'has_custom_cert'  => ! empty( $wh['has_custom_certificate'] ),
				'has_secret_token' => $has_secret_token,
			);
		}

		/**
		 * Check webhook status for a non-Telegram connection.
		 *
		 * These platforms don't have a native getWebhookInfo API, so we
		 * report configuration status and the expected endpoint URL.
		 *
		 * @param array $connection Connection data array.
		 * @return array Status result array.
		 */
		public static function check_generic_webhook( $connection ) {
			$connection_id   = isset( $connection['id'] ) ? $connection['id'] : '';
			$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';
			$enabled         = ! empty( $connection['enabled'] );
			$expected_url    = self::get_expected_webhook_url( $connection_id, $connection_type );
			$has_credentials = ! empty( $connection['api_key'] ) || ! empty( $connection['access_token'] );

			// For Google Chat check for service account or OAuth credentials.
			if ( 'google_chat' === $connection_type ) {
				$has_credentials = ! empty( $connection['api_key'] ) || ! empty( $connection['refresh_token'] ) || ! empty( $connection['reply_webhook_url'] );
			}

			$status = 'configured';
			if ( ! $enabled ) {
				$status = 'disabled';
			} elseif ( ! $has_credentials ) {
				$status = 'no_credentials';
			}

			$verify_token = '';
			if ( ! empty( $connection['verify_token'] ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$verify_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['verify_token'] );
			}

			return array(
				'status'          => $status,
				'webhook_url'     => $expected_url,
				'expected_url'    => $expected_url,
				'has_credentials' => $has_credentials,
				'verify_token'    => ! empty( $verify_token ) ? substr( $verify_token, 0, 8 ) . '...' : '',
			);
		}

		// -----------------------------------------------------------------
		// AJAX Handlers
		// -----------------------------------------------------------------

		/**
		 * AJAX: Check webhook status for a single connection.
		 *
		 * @return void
		 */
		public function ajax_check_webhook_status() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

			if ( empty( $connection_id ) ) {
				wp_send_json_error( __( 'Connection ID is required.', 'nvoos-content-graph-pro' ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				wp_send_json_error( __( 'Remote Site Manager not available.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( ! $connection ) {
				wp_send_json_error( __( 'Connection not found.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection['id'] = $connection_id;
			$type             = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';

			if ( 'telegram' === $type ) {
				$result = self::check_telegram_webhook( $connection );
			} else {
				$result = self::check_generic_webhook( $connection );
			}

			$result['connection_id'] = $connection_id;
			$result['checked_at']    = current_time( 'mysql' );

			wp_send_json_success( $result );
		}

		/**
		 * AJAX: Check webhook status for all connections.
		 *
		 * @return void
		 */
		public function ajax_check_all_webhooks() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connections = self::get_webhook_connections();
			$results     = array();

			foreach ( $connections as $id => $connection ) {
				$connection['id'] = $id;
				$type             = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';

				if ( 'telegram' === $type ) {
					$result = self::check_telegram_webhook( $connection );
				} else {
					$result = self::check_generic_webhook( $connection );
				}

				$result['connection_id']   = $id;
				$result['connection_name'] = isset( $connection['name'] ) ? $connection['name'] : '';
				$result['connection_type'] = $type;
				$result['checked_at']      = current_time( 'mysql' );

				$results[ $id ] = $result;
			}

			wp_send_json_success( $results );
		}

		/**
		 * AJAX: Set/register webhook for a Telegram connection.
		 *
		 * @return void
		 */
		public function ajax_set_webhook() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

			if ( empty( $connection_id ) ) {
				wp_send_json_error( __( 'Connection ID is required.', 'nvoos-content-graph-pro' ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				wp_send_json_error( __( 'Remote Site Manager not available.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( ! $connection ) {
				wp_send_json_error( __( 'Connection not found.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';

			if ( 'telegram' !== $type ) {
				wp_send_json_error( __( 'Only Telegram connections support programmatic webhook registration.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$bot_token = ! empty( $connection['api_key'] )
				? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] )
				: '';

			if ( empty( $bot_token ) || ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
				wp_send_json_error( __( 'Bot token is missing or invalid.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$secret_token = '';
			if ( ! empty( $connection['secret_token'] ) ) {
				$secret_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['secret_token'] );
			}

			// Auto-generate a secret_token when none is configured so that the
			// webhook controller can always verify the X-Telegram-Bot-Api-Secret-Token
			// header. Without this, setWebhook omits the secret_token parameter,
			// Telegram does not send the header on deliveries, and our
			// validate_webhook_secret() rejects every update with 403 Forbidden.
			if ( empty( $secret_token ) ) {
				$secret_token = wp_generate_password( 64, false );

				// Persist the generated token on the connection so that
				// validate_webhook_secret() can retrieve it later.
				$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
				if ( is_array( $all_connections ) && isset( $all_connections[ $connection_id ] ) ) {
					$all_connections[ $connection_id ]['secret_token'] = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $secret_token );
					update_option( 'wp_mcp_ai_pro_remote_sites', $all_connections );
				}
			}

			$webhook_url = self::get_expected_webhook_url( $connection_id, 'telegram' );

			if ( 0 !== strpos( $webhook_url, 'https://' ) ) {
				wp_send_json_error( __( 'Telegram requires a webhook URL using HTTPS. Your site must use SSL.', 'nvoos-content-graph-pro' ) );
				return;
			}

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
						__( 'Failed to connect to Telegram API: %s', 'nvoos-content-graph-pro' ),
						$response->get_error_message()
					)
				);
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
				$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response.', 'nvoos-content-graph-pro' );
				wp_send_json_error(
					sprintf(
						/* translators: %s: error description */
						__( 'Telegram API error: %s', 'nvoos-content-graph-pro' ),
						$description
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'webhook_url' => $webhook_url,
					'message'     => __( 'Webhook registered successfully.', 'nvoos-content-graph-pro' ),
				)
			);
		}

		/**
		 * AJAX: Delete/remove webhook for a Telegram connection.
		 *
		 * @return void
		 */
		public function ajax_delete_webhook() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection_id = isset( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';

			if ( empty( $connection_id ) ) {
				wp_send_json_error( __( 'Connection ID is required.', 'nvoos-content-graph-pro' ) );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				wp_send_json_error( __( 'Remote Site Manager not available.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( ! $connection ) {
				wp_send_json_error( __( 'Connection not found.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';

			if ( 'telegram' !== $type ) {
				wp_send_json_error( __( 'Only Telegram connections support programmatic webhook deletion.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$bot_token = ! empty( $connection['api_key'] )
				? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] )
				: '';

			if ( empty( $bot_token ) || ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_bot_token( $bot_token ) ) {
				wp_send_json_error( __( 'Bot token is missing or invalid.', 'nvoos-content-graph-pro' ) );
				return;
			}

			$api_base = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
			$response = wp_remote_post(
				$api_base . '/deleteWebhook',
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'drop_pending_updates' => false ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to connect to Telegram API: %s', 'nvoos-content-graph-pro' ),
						$response->get_error_message()
					)
				);
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
				$description = isset( $data['description'] ) ? $data['description'] : __( 'Invalid response.', 'nvoos-content-graph-pro' );
				wp_send_json_error(
					sprintf(
						/* translators: %s: error description */
						__( 'Telegram API error: %s', 'nvoos-content-graph-pro' ),
						$description
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message' => __( 'Webhook deleted successfully.', 'nvoos-content-graph-pro' ),
				)
			);
		}

		// -----------------------------------------------------------------
		// Page Rendering
		// -----------------------------------------------------------------

		/**
		 * Render the Webhook Status admin page.
		 *
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-pro' ) );
			}

			$connections = self::get_webhook_connections();

			// Compute summary counts.
			$total    = count( $connections );
			$active   = 0;
			$inactive = 0;
			$telegram = 0;

			foreach ( $connections as $conn ) {
				if ( ! empty( $conn['enabled'] ) ) {
					++$active;
				} else {
					++$inactive;
				}
				if ( isset( $conn['connection_type'] ) && 'telegram' === $conn['connection_type'] ) {
					++$telegram;
				}
			}

			$nonce    = wp_create_nonce( self::NONCE_ACTION );
			$ajax_url = admin_url( 'admin-ajax.php' );
			?>
			<div class="wrap wp-mcp-ai-webhook-status-page">
				<h1>
					<span class="dashicons dashicons-rest-api" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Webhook Status', 'nvoos-content-graph-pro' ); ?>
					<span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;margin-left:10px;text-transform:uppercase;letter-spacing:.5px;">PRO</span>
				</h1>

				<p class="description" style="margin-bottom:20px;">
					<?php esc_html_e( 'Monitor and manage all webhook endpoints configured for your chat channel connections. Use Check Status to verify each endpoint with its external service, and Set Webhook to register Telegram endpoints.', 'nvoos-content-graph-pro' ); ?>
				</p>

				<?php $this->render_summary_cards( $total, $active, $inactive, $telegram ); ?>

				<?php if ( $total > 0 ) : ?>
					<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0 15px;">
						<div>
							<button type="button" class="button button-primary" id="wp-mcp-ai-check-all-btn">
								<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;margin-right:4px;"></span>
								<?php esc_html_e( 'Check All Webhooks', 'nvoos-content-graph-pro' ); ?>
							</button>
							<span id="wp-mcp-ai-check-all-spinner" class="spinner" style="float:none;margin-top:0;"></span>
						</div>
						<div>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button">
								<span class="dashicons dashicons-admin-links" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;margin-right:4px;"></span>
								<?php esc_html_e( 'Manage Connections', 'nvoos-content-graph-pro' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<?php $this->render_connections_table( $connections ); ?>

				<?php $this->render_page_scripts( $nonce, $ajax_url ); ?>
			</div>

			<style>
				.wp-mcp-ai-webhook-status-page .summary-cards {
					display: flex;
					gap: 16px;
					flex-wrap: wrap;
					margin: 15px 0;
				}
				.wp-mcp-ai-webhook-status-page .summary-card {
					flex: 1;
					min-width: 150px;
					max-width: 220px;
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 8px;
					padding: 16px 20px;
					text-align: center;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
				}
				.wp-mcp-ai-webhook-status-page .summary-card .card-number {
					font-size: 32px;
					font-weight: 700;
					line-height: 1.2;
				}
				.wp-mcp-ai-webhook-status-page .summary-card .card-label {
					font-size: 13px;
					color: #50575e;
					margin-top: 4px;
				}
				.wp-mcp-ai-webhook-status-page .type-badge {
					display: inline-block;
					padding: 2px 8px;
					color: #fff;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					white-space: nowrap;
				}
				.wp-mcp-ai-webhook-status-page .health-badge {
					display: inline-flex;
					align-items: center;
					gap: 4px;
					padding: 3px 10px;
					border-radius: 12px;
					font-size: 12px;
					font-weight: 600;
					white-space: nowrap;
				}
				.wp-mcp-ai-webhook-status-page .health-badge.ok { background: #d4edda; color: #155724; }
				.wp-mcp-ai-webhook-status-page .health-badge.warning { background: #fff3cd; color: #856404; }
				.wp-mcp-ai-webhook-status-page .health-badge.error { background: #f8d7da; color: #721c24; }
				.wp-mcp-ai-webhook-status-page .health-badge.not-set { background: #e2e3e5; color: #383d41; }
				.wp-mcp-ai-webhook-status-page .health-badge.mismatch { background: #fff3cd; color: #856404; }
				.wp-mcp-ai-webhook-status-page .health-badge.configured { background: #cce5ff; color: #004085; }
				.wp-mcp-ai-webhook-status-page .health-badge.disabled { background: #e2e3e5; color: #6c757d; }
				.wp-mcp-ai-webhook-status-page .health-badge.no-credentials { background: #f8d7da; color: #721c24; }
				.wp-mcp-ai-webhook-status-page .health-badge.unchecked { background: #f5f5f5; color: #666; }
				.wp-mcp-ai-webhook-status-page .health-badge.checking { background: #e8f4fd; color: #2271b1; }
				.wp-mcp-ai-webhook-status-page .webhook-details {
					font-size: 12px;
					color: #50575e;
					margin-top: 4px;
				}
				.wp-mcp-ai-webhook-status-page .webhook-details code {
					background: #f0f0f1;
					padding: 2px 6px;
					border-radius: 3px;
					font-size: 11px;
					word-break: break-all;
				}
				.wp-mcp-ai-webhook-status-page .webhook-error {
					color: #d63638;
					font-size: 12px;
					margin-top: 4px;
				}
				.wp-mcp-ai-webhook-status-page .webhook-warning {
					color: #b45309;
					font-size: 12px;
					margin-top: 4px;
				}
				.wp-mcp-ai-webhook-status-page .actions-cell .button {
					margin: 2px 4px 2px 0;
				}
			</style>
			<?php
		}

		/**
		 * Render summary status cards.
		 *
		 * @param int $total    Total webhook connections.
		 * @param int $active   Active (enabled) connections.
		 * @param int $inactive Inactive connections.
		 * @param int $telegram Telegram-specific connections.
		 * @return void
		 */
		private function render_summary_cards( $total, $active, $inactive, $telegram ) {
			?>
			<div class="summary-cards">
				<div class="summary-card">
					<div class="card-number" style="color:#2271b1;"><?php echo esc_html( $total ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Total Webhook Endpoints', 'nvoos-content-graph-pro' ); ?></div>
				</div>
				<div class="summary-card">
					<div class="card-number" style="color:#00a32a;"><?php echo esc_html( $active ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Active Connections', 'nvoos-content-graph-pro' ); ?></div>
				</div>
				<div class="summary-card">
					<div class="card-number" style="color:#d63638;"><?php echo esc_html( $inactive ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Inactive / Disabled', 'nvoos-content-graph-pro' ); ?></div>
				</div>
				<div class="summary-card">
					<div class="card-number" style="color:#0088cc;"><?php echo esc_html( $telegram ); ?></div>
					<div class="card-label"><?php esc_html_e( 'Telegram Bots', 'nvoos-content-graph-pro' ); ?></div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the connections table.
		 *
		 * @param array $connections Webhook connections keyed by ID.
		 * @return void
		 */
		private function render_connections_table( $connections ) {
			if ( empty( $connections ) ) {
				?>
				<div class="notice notice-info" style="margin-top:20px;">
					<p>
						<?php esc_html_e( 'No webhook-capable connections configured yet.', 'nvoos-content-graph-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&add=1' ) ); ?>">
							<?php esc_html_e( 'Add a new connection', 'nvoos-content-graph-pro' ); ?>
						</a>
					</p>
				</div>
				<?php
				return;
			}
			?>
			<table class="wp-list-table widefat fixed striped" id="wp-mcp-ai-webhook-table">
				<thead>
					<tr>
						<th style="width:18%;"><?php esc_html_e( 'Connection', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Type', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:8%;"><?php esc_html_e( 'Enabled', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Health', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:26%;"><?php esc_html_e( 'Webhook URL', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Details', 'nvoos-content-graph-pro' ); ?></th>
						<th style="width:16%;"><?php esc_html_e( 'Actions', 'nvoos-content-graph-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $connections as $connection_id => $connection ) : ?>
						<?php
						$type    = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';
						$name    = isset( $connection['name'] ) ? $connection['name'] : $connection_id;
						$enabled = ! empty( $connection['enabled'] );
						$is_tg   = 'telegram' === $type;
						$color   = self::get_type_color( $type );
						$label   = self::get_type_label( $type );
						$exp_url = self::get_expected_webhook_url( $connection_id, $type );
						?>
						<tr data-connection-id="<?php echo esc_attr( $connection_id ); ?>" data-connection-type="<?php echo esc_attr( $type ); ?>">
							<td>
								<strong><?php echo esc_html( $name ); ?></strong>
								<div style="font-size:11px;color:#999;margin-top:2px;">
									<code style="font-size:10px;"><?php echo esc_html( $connection_id ); ?></code>
								</div>
							</td>
							<td>
								<span class="type-badge" style="background:<?php echo esc_attr( $color ); ?>;">
									<?php echo esc_html( $label ); ?>
								</span>
							</td>
							<td>
								<?php if ( $enabled ) : ?>
									<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Yes', 'nvoos-content-graph-pro' ); ?></span>
								<?php else : ?>
									<span style="color:#999;">&#10007; <?php esc_html_e( 'No', 'nvoos-content-graph-pro' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="health-cell">
								<span class="health-badge unchecked"><?php esc_html_e( 'Not checked', 'nvoos-content-graph-pro' ); ?></span>
							</td>
							<td>
								<div class="webhook-details">
									<strong><?php esc_html_e( 'Expected:', 'nvoos-content-graph-pro' ); ?></strong>
									<code><?php echo esc_html( $exp_url ); ?></code>
								</div>
								<div class="webhook-details actual-url" style="display:none;">
									<strong><?php esc_html_e( 'Actual:', 'nvoos-content-graph-pro' ); ?></strong>
									<code class="actual-url-value"></code>
								</div>
							</td>
							<td class="details-cell">
								<span class="webhook-details">&mdash;</span>
							</td>
							<td class="actions-cell">
								<button type="button" class="button button-small wp-mcp-ai-check-btn" data-connection-id="<?php echo esc_attr( $connection_id ); ?>">
									<span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span>
									<?php esc_html_e( 'Check', 'nvoos-content-graph-pro' ); ?>
								</button>
								<?php if ( $is_tg ) : ?>
									<button type="button" class="button button-small button-primary wp-mcp-ai-set-btn" data-connection-id="<?php echo esc_attr( $connection_id ); ?>">
										<span class="dashicons dashicons-admin-links" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span>
										<?php esc_html_e( 'Set', 'nvoos-content-graph-pro' ); ?>
									</button>
									<button type="button" class="button button-small button-link-delete wp-mcp-ai-delete-btn" data-connection-id="<?php echo esc_attr( $connection_id ); ?>">
										<?php esc_html_e( 'Remove', 'nvoos-content-graph-pro' ); ?>
									</button>
								<?php endif; ?>
								<span class="spinner" style="float:none;margin-top:0;"></span>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . $connection_id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Edit Connection', 'nvoos-content-graph-pro' ); ?>">
									<span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render inline JavaScript for the page.
		 *
		 * @param string $nonce    Nonce value.
		 * @param string $ajax_url Admin AJAX URL.
		 * @return void
		 */
		private function render_page_scripts( $nonce, $ajax_url ) {
			?>
			<script>
			(function() {
				'use strict';

				var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
				var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;

				/* ----------------------------------------------------------
				 * Helpers
				 * -------------------------------------------------------- */

				function getStatusBadge(status) {
					var map = {
						'ok':             { label: '✓ OK',             cls: 'ok' },
						'warning':        { label: '⚠ Warning',       cls: 'warning' },
						'error':          { label: '✕ Error',          cls: 'error' },
						'not_set':        { label: '○ Not Set',        cls: 'not-set' },
						'mismatch':       { label: '⚠ URL Mismatch',  cls: 'mismatch' },
						'configured':     { label: '✓ Configured',    cls: 'configured' },
						'disabled':       { label: '— Disabled',       cls: 'disabled' },
						'no_credentials': { label: '✕ No Credentials', cls: 'no-credentials' },
						'checking':       { label: '⟳ Checking…',     cls: 'checking' }
					};
					var info = map[status] || { label: status, cls: 'unchecked' };
					return '<span class="health-badge ' + info.cls + '">' + info.label + '</span>';
				}

				function ajaxPost(action, extraData, callback) {
					var data = new FormData();
					data.append('action', action);
					data.append('nonce', NONCE);
					if (extraData) {
						for (var key in extraData) {
							if (extraData.hasOwnProperty(key)) {
								data.append(key, extraData[key]);
							}
						}
					}
					fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r) {
							if (!r.ok) { throw new Error('HTTP ' + r.status); }
							return r.json();
						})
						.then(function(result) { callback(null, result); })
						.catch(function(err) { callback(err.message || 'Request failed'); });
				}

				function setRowChecking(row) {
					var healthCell = row.querySelector('.health-cell');
					if (healthCell) {
						healthCell.innerHTML = getStatusBadge('checking');
					}
				}

				function updateRow(row, data) {
					/* Health badge */
					var healthCell = row.querySelector('.health-cell');
					if (healthCell) {
						healthCell.innerHTML = getStatusBadge(data.status);
					}

					/* Actual URL (Telegram only) */
					var actualUrlDiv = row.querySelector('.actual-url');
					var actualUrlVal = row.querySelector('.actual-url-value');
					if (actualUrlDiv && actualUrlVal) {
						if (data.webhook_url && data.webhook_url !== data.expected_url) {
							actualUrlVal.textContent = data.webhook_url;
							actualUrlDiv.style.display = 'block';
						} else if (data.webhook_url) {
							actualUrlVal.textContent = data.webhook_url;
							actualUrlDiv.style.display = 'block';
						} else {
							actualUrlDiv.style.display = 'none';
						}
					}

					/* Details cell */
					var detailsCell = row.querySelector('.details-cell');
					if (detailsCell) {
						var html = '';
						if (typeof data.pending_updates !== 'undefined') {
							html += '<div class="webhook-details">' + <?php echo wp_json_encode( __( 'Pending:', 'nvoos-content-graph-pro' ) ); ?> + ' ' + data.pending_updates + '</div>';
						}
						if (data.max_connections) {
							html += '<div class="webhook-details">' + <?php echo wp_json_encode( __( 'Max Conn:', 'nvoos-content-graph-pro' ) ); ?> + ' ' + data.max_connections + '</div>';
						}
						if (data.has_secret_token === false) {
							html += '<div class="webhook-warning">⚠ ' + <?php echo wp_json_encode( __( 'No secret token — click Set Webhook to auto-generate one', 'nvoos-content-graph-pro' ) ); ?> + '</div>';
						}
						if (data.bot_info && data.bot_info.bot_username) {
							html += '<div class="webhook-details">@' + data.bot_info.bot_username + '</div>';
						}
						if (data.has_credentials === false) {
							html += '<div class="webhook-warning">' + <?php echo wp_json_encode( __( 'No credentials configured', 'nvoos-content-graph-pro' ) ); ?> + '</div>';
						}
						if (data.last_error) {
							html += '<div class="webhook-error">✕ ' + data.last_error + '</div>';
							if (data.last_error_date) {
								html += '<div class="webhook-details" style="color:#999;">' + data.last_error_date + '</div>';
							}
						}
						if (data.message) {
							html += '<div class="webhook-error">' + data.message + '</div>';
						}
						if (data.checked_at) {
							html += '<div class="webhook-details" style="color:#aaa;margin-top:4px;">⏱ ' + data.checked_at + '</div>';
						}
						detailsCell.innerHTML = html || '<span class="webhook-details">&mdash;</span>';
					}
				}

				function setSpinner(row, active) {
					var spinner = row.querySelector('.actions-cell .spinner');
					if (spinner) {
						spinner.classList.toggle('is-active', !!active);
					}
				}

				/* ----------------------------------------------------------
				 * Single connection: Check Status
				 * -------------------------------------------------------- */
				document.querySelectorAll('.wp-mcp-ai-check-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var connId = btn.getAttribute('data-connection-id');
						var row    = btn.closest('tr');
						setRowChecking(row);
						setSpinner(row, true);
						btn.disabled = true;

						ajaxPost('wp_mcp_ai_webhook_status_check', { connection_id: connId }, function(err, result) {
							setSpinner(row, false);
							btn.disabled = false;
							if (err) {
								updateRow(row, { status: 'error', message: err });
								return;
							}
							if (result.success) {
								updateRow(row, result.data);
							} else {
								updateRow(row, { status: 'error', message: result.data || 'Unknown error' });
							}
						});
					});
				});

				/* ----------------------------------------------------------
				 * Single connection: Set Webhook (Telegram only)
				 * -------------------------------------------------------- */
				document.querySelectorAll('.wp-mcp-ai-set-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var connId = btn.getAttribute('data-connection-id');
						var row    = btn.closest('tr');
						if (!window.confirm(<?php echo wp_json_encode( __( 'Register this webhook with Telegram?', 'nvoos-content-graph-pro' ) ); ?>)) {
							return;
						}
						setSpinner(row, true);
						btn.disabled = true;

						ajaxPost('wp_mcp_ai_webhook_status_set', { connection_id: connId }, function(err, result) {
							setSpinner(row, false);
							btn.disabled = false;
							if (err) {
								updateRow(row, { status: 'error', message: err });
								return;
							}
							if (result.success) {
								/* Re-check after successful set */
								setRowChecking(row);
								setSpinner(row, true);
								ajaxPost('wp_mcp_ai_webhook_status_check', { connection_id: connId }, function(err2, result2) {
									setSpinner(row, false);
									if (result2 && result2.success) {
										updateRow(row, result2.data);
									}
								});
							} else {
								updateRow(row, { status: 'error', message: result.data || 'Failed to set webhook' });
							}
						});
					});
				});

				/* ----------------------------------------------------------
				 * Single connection: Delete Webhook (Telegram only)
				 * -------------------------------------------------------- */
				document.querySelectorAll('.wp-mcp-ai-delete-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var connId = btn.getAttribute('data-connection-id');
						var row    = btn.closest('tr');
						if (!window.confirm(<?php echo wp_json_encode( __( 'Remove the webhook for this Telegram bot? The bot will stop receiving messages until a new webhook is set.', 'nvoos-content-graph-pro' ) ); ?>)) {
							return;
						}
						setSpinner(row, true);
						btn.disabled = true;

						ajaxPost('wp_mcp_ai_webhook_status_delete', { connection_id: connId }, function(err, result) {
							setSpinner(row, false);
							btn.disabled = false;
							if (err) {
								updateRow(row, { status: 'error', message: err });
								return;
							}
							if (result.success) {
								updateRow(row, { status: 'not_set', message: result.data.message || 'Webhook removed', webhook_url: '' });
							} else {
								updateRow(row, { status: 'error', message: result.data || 'Failed to delete webhook' });
							}
						});
					});
				});

				/* ----------------------------------------------------------
				 * Check All Webhooks
				 * -------------------------------------------------------- */
				var checkAllBtn     = document.getElementById('wp-mcp-ai-check-all-btn');
				var checkAllSpinner = document.getElementById('wp-mcp-ai-check-all-spinner');

				if (checkAllBtn) {
					checkAllBtn.addEventListener('click', function() {
						checkAllBtn.disabled = true;
						if (checkAllSpinner) { checkAllSpinner.classList.add('is-active'); }

						/* Set all rows to "checking" */
						document.querySelectorAll('#wp-mcp-ai-webhook-table tbody tr').forEach(function(row) {
							setRowChecking(row);
						});

						ajaxPost('wp_mcp_ai_webhook_status_check_all', {}, function(err, result) {
							checkAllBtn.disabled = false;
							if (checkAllSpinner) { checkAllSpinner.classList.remove('is-active'); }

							if (err) {
								alert('Error: ' + err);
								return;
							}

							if (result.success && typeof result.data === 'object') {
								for (var connId in result.data) {
									if (!result.data.hasOwnProperty(connId)) { continue; }
									var row = document.querySelector('tr[data-connection-id="' + connId + '"]');
									if (row) {
										updateRow(row, result.data[connId]);
									}
								}
							}
						});
					});
				}

			})();
			</script>
			<?php
		}
	}
}

// Instantiate the page — mirrors the schedule-manager-page pattern.
if ( defined( 'NVOOS_CONTENT_GRAPH_PRO_PATH' ) ) {
	new WP_MCP_AI_Pro_Webhook_Status_Page();
}
