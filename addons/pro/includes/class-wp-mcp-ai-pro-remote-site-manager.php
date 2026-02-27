<?php
/**
 * Remote Site Connection Manager.
 *
 * Manages connections to remote WordPress/WooCommerce sites.
 * Stores connection credentials, handles authentication, and provides
 * a centralized interface for managing multiple remote site connections.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages remote WordPress/WooCommerce site connections.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Remote_Site_Manager {

	/**
	 * Option name for storing remote site connections.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_pro_remote_sites';

	/**
	 * Supported authentication types.
	 *
	 * @var array<string>
	 */
	const AUTH_TYPES = array( 'application_password', 'basic_auth', 'jwt', 'woocommerce', 'custom_header', 'none' );

	/**
	 * Get all configured remote site connections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of site connections.
	 */
	public static function get_all_connections() {
		$connections = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $connections ) ) {
			return array();
		}

		// Migrate connection IDs to lowercase if needed.
		$connections = self::migrate_connection_ids( $connections );

		return $connections;
	}

	/**
	 * Get a specific remote site connection by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array|null Connection data or null if not found.
	 */
	public static function get_connection( $connection_id ) {
		$connections   = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( isset( $connections[ $connection_id ] ) ) {
			return $connections[ $connection_id ];
		}

		return null;
	}

	/**
	 * Add or update a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection_data Connection data.
	 * @return string|WP_Error Connection ID on success, WP_Error on failure.
	 */
	public static function save_connection( $connection_data ) {
		$connections = self::get_all_connections();

		// Generate or use existing connection ID.
		$is_update = false;
		if ( empty( $connection_data['id'] ) ) {
			$connection_id = self::generate_connection_id();
		} else {
			$connection_id = sanitize_key( $connection_data['id'] );
			$is_update     = isset( $connections[ $connection_id ] );
		}

		// If updating and password/token fields are empty, preserve existing values.
		if ( $is_update ) {
			$existing_connection = $connections[ $connection_id ];

			// Preserve existing password if not provided.
			if ( empty( $connection_data['password'] ) && ! empty( $existing_connection['password'] ) ) {
				$connection_data['password'] = $existing_connection['password'];
				// Mark as already encrypted.
				$connection_data['_password_encrypted'] = true;
			}

			// Preserve existing token if not provided.
			if ( empty( $connection_data['token'] ) && ! empty( $existing_connection['token'] ) ) {
				$connection_data['token'] = $existing_connection['token'];
				// Mark as already encrypted.
				$connection_data['_token_encrypted'] = true;
			}

			// Preserve existing consumer_key if not provided.
			if ( empty( $connection_data['consumer_key'] ) && ! empty( $existing_connection['consumer_key'] ) ) {
				$connection_data['consumer_key']            = $existing_connection['consumer_key'];
				$connection_data['_consumer_key_encrypted'] = true;
			}

			// Preserve existing consumer_secret if not provided.
			if ( empty( $connection_data['consumer_secret'] ) && ! empty( $existing_connection['consumer_secret'] ) ) {
				$connection_data['consumer_secret']            = $existing_connection['consumer_secret'];
				$connection_data['_consumer_secret_encrypted'] = true;
			}

			// Preserve existing api_key if not provided.
			if ( empty( $connection_data['api_key'] ) && ! empty( $existing_connection['api_key'] ) ) {
				$connection_data['api_key']            = $existing_connection['api_key'];
				$connection_data['_api_key_encrypted'] = true;
			}

			// Preserve existing api_secret if not provided.
			if ( empty( $connection_data['api_secret'] ) && ! empty( $existing_connection['api_secret'] ) ) {
				$connection_data['api_secret']            = $existing_connection['api_secret'];
				$connection_data['_api_secret_encrypted'] = true;
			}

			// Preserve existing client_id if not provided.
			if ( empty( $connection_data['client_id'] ) && ! empty( $existing_connection['client_id'] ) ) {
				$connection_data['client_id'] = $existing_connection['client_id'];
			}

			// Preserve existing client_secret if not provided.
			if ( empty( $connection_data['client_secret'] ) && ! empty( $existing_connection['client_secret'] ) ) {
				$connection_data['client_secret']            = $existing_connection['client_secret'];
				$connection_data['_client_secret_encrypted'] = true;
			}

			// Preserve existing app_id if not provided.
			if ( empty( $connection_data['app_id'] ) && ! empty( $existing_connection['app_id'] ) ) {
				$connection_data['app_id'] = $existing_connection['app_id'];
			}

			// Preserve existing app_secret if not provided.
			if ( empty( $connection_data['app_secret'] ) && ! empty( $existing_connection['app_secret'] ) ) {
				$connection_data['app_secret']            = $existing_connection['app_secret'];
				$connection_data['_app_secret_encrypted'] = true;
			}

			// Preserve existing refresh_token (Gmail) if not provided.
			if ( empty( $connection_data['refresh_token'] ) && ! empty( $existing_connection['refresh_token'] ) ) {
				$connection_data['refresh_token']            = $existing_connection['refresh_token'];
				$connection_data['_refresh_token_encrypted'] = true;
			}

			// Preserve existing user_email (Gmail) if not provided.
			if ( empty( $connection_data['user_email'] ) && ! empty( $existing_connection['user_email'] ) ) {
				$connection_data['user_email'] = $existing_connection['user_email'];
			}

			// Preserve existing folder_id (Google Drive) if not provided.
			if ( empty( $connection_data['folder_id'] ) && ! empty( $existing_connection['folder_id'] ) ) {
				$connection_data['folder_id'] = $existing_connection['folder_id'];
			}

			// Preserve existing bot_username (Telegram) if not provided.
			if ( empty( $connection_data['bot_username'] ) && ! empty( $existing_connection['bot_username'] ) ) {
				$connection_data['bot_username'] = $existing_connection['bot_username'];
			}

			// Preserve existing enable_web_login (Telegram) if not provided.
			if ( ! isset( $connection_data['enable_web_login'] ) && isset( $existing_connection['enable_web_login'] ) ) {
				$connection_data['enable_web_login'] = $existing_connection['enable_web_login'];
			}

			// Preserve existing web_login_redirect_url (Telegram) if not provided.
			if ( ! isset( $connection_data['web_login_redirect_url'] ) && isset( $existing_connection['web_login_redirect_url'] ) ) {
				$connection_data['web_login_redirect_url'] = $existing_connection['web_login_redirect_url'];
			}

			// Preserve existing WhatsApp-specific fields if not provided.
			if ( empty( $connection_data['phone_number_id'] ) && ! empty( $existing_connection['phone_number_id'] ) ) {
				$connection_data['phone_number_id'] = $existing_connection['phone_number_id'];
			}

			if ( empty( $connection_data['business_account_id'] ) && ! empty( $existing_connection['business_account_id'] ) ) {
				$connection_data['business_account_id'] = $existing_connection['business_account_id'];
			}

			if ( empty( $connection_data['system_user_id'] ) && ! empty( $existing_connection['system_user_id'] ) ) {
				$connection_data['system_user_id'] = $existing_connection['system_user_id'];
			}

			// For Google Chat the Audience URL (verify_token) is an optional field that is always
			// rendered and submitted in the edit form, so allow the user to clear it.
			// For WhatsApp and Messenger the verify_token is a required webhook secret; preserve
			// the stored value when the submitted field is empty to avoid accidental erasure.
			$saved_connection_type = isset( $connection_data['connection_type'] ) ? $connection_data['connection_type'] : '';
			if ( empty( $connection_data['verify_token'] ) && ! empty( $existing_connection['verify_token'] )
				&& 'google_chat' !== $saved_connection_type ) {
				$connection_data['verify_token'] = $existing_connection['verify_token'];
			}

			if ( empty( $connection_data['graph_api_version'] ) && ! empty( $existing_connection['graph_api_version'] ) ) {
				$connection_data['graph_api_version'] = $existing_connection['graph_api_version'];
			}

			if ( empty( $connection_data['display_phone_number'] ) && ! empty( $existing_connection['display_phone_number'] ) ) {
				$connection_data['display_phone_number'] = $existing_connection['display_phone_number'];
			}

			if ( empty( $connection_data['channel_description'] ) && ! empty( $existing_connection['channel_description'] ) ) {
				$connection_data['channel_description'] = $existing_connection['channel_description'];
			}

			if ( empty( $connection_data['channel_url'] ) && ! empty( $existing_connection['channel_url'] ) ) {
				$connection_data['channel_url'] = $existing_connection['channel_url'];
			}

			if ( empty( $connection_data['group_id'] ) && ! empty( $existing_connection['group_id'] ) ) {
				$connection_data['group_id'] = $existing_connection['group_id'];
			}

			// Preserve existing workspace_id (Slack) if not provided.
			if ( empty( $connection_data['workspace_id'] ) && ! empty( $existing_connection['workspace_id'] ) ) {
				$connection_data['workspace_id'] = $existing_connection['workspace_id'];
			}

			// Preserve existing Discord-specific fields if not provided.
			if ( empty( $connection_data['application_id'] ) && ! empty( $existing_connection['application_id'] ) ) {
				$connection_data['application_id'] = $existing_connection['application_id'];
			}

			if ( empty( $connection_data['guild_id'] ) && ! empty( $existing_connection['guild_id'] ) ) {
				$connection_data['guild_id'] = $existing_connection['guild_id'];
			}

			if ( empty( $connection_data['public_key'] ) && ! empty( $existing_connection['public_key'] ) ) {
				$connection_data['public_key'] = $existing_connection['public_key'];
			}

			// Preserve existing tenant_id (Microsoft Teams) if not provided.
			if ( empty( $connection_data['tenant_id'] ) && ! empty( $existing_connection['tenant_id'] ) ) {
				$connection_data['tenant_id'] = $existing_connection['tenant_id'];
			}

			// Preserve existing signing_secret (Slack / Teams outgoing webhook) if not provided.
			if ( empty( $connection_data['signing_secret'] ) && ! empty( $existing_connection['signing_secret'] ) ) {
				$connection_data['signing_secret']            = $existing_connection['signing_secret'];
				$connection_data['_signing_secret_encrypted'] = true;
			}

			// Preserve existing secret_token (Telegram webhook) if not provided.
			if ( empty( $connection_data['secret_token'] ) && ! empty( $existing_connection['secret_token'] ) ) {
				$connection_data['secret_token']            = $existing_connection['secret_token'];
				$connection_data['_secret_token_encrypted'] = true;
			}

			// Preserve existing page_id (Facebook Messenger) if not provided.
			if ( empty( $connection_data['page_id'] ) && ! empty( $existing_connection['page_id'] ) ) {
				$connection_data['page_id'] = $existing_connection['page_id'];
			}

			// Preserve existing p2p_connection_id (WebChat) if not provided.
			if ( empty( $connection_data['p2p_connection_id'] ) && ! empty( $existing_connection['p2p_connection_id'] ) ) {
				$connection_data['p2p_connection_id'] = $existing_connection['p2p_connection_id'];
			}

			// Preserve existing google_chat_space (Google Chat) if not provided.
			if ( empty( $connection_data['google_chat_space'] ) && ! empty( $existing_connection['google_chat_space'] ) ) {
				$connection_data['google_chat_space'] = $existing_connection['google_chat_space'];
			}

			// Preserve existing reply_webhook_url (Google Chat incoming webhook) if not provided.
			if ( empty( $connection_data['reply_webhook_url'] ) && ! empty( $existing_connection['reply_webhook_url'] ) ) {
				$connection_data['reply_webhook_url'] = $existing_connection['reply_webhook_url'];
			}

			// Preserve existing assigned_assistant_ids (WhatsApp channel routing) if not provided.
			if ( ! isset( $connection_data['assigned_assistant_ids'] ) && ! empty( $existing_connection['assigned_assistant_ids'] ) ) {
				$connection_data['assigned_assistant_ids'] = $existing_connection['assigned_assistant_ids'];
			}

			// Preserve existing test_endpoint if not provided.
			if ( empty( $connection_data['test_endpoint'] ) && ! empty( $existing_connection['test_endpoint'] ) ) {
				$connection_data['test_endpoint'] = $existing_connection['test_endpoint'];
			}

			// Preserve existing cache_ttl if not provided.
			if ( ! isset( $connection_data['cache_ttl'] ) && isset( $existing_connection['cache_ttl'] ) ) {
				$connection_data['cache_ttl'] = $existing_connection['cache_ttl'];
			}

			// Preserve existing location_id if not provided.
			if ( empty( $connection_data['location_id'] ) && ! empty( $existing_connection['location_id'] ) ) {
				$connection_data['location_id'] = $existing_connection['location_id'];
			}

			// Preserve existing company_id if not provided.
			if ( empty( $connection_data['company_id'] ) && ! empty( $existing_connection['company_id'] ) ) {
				$connection_data['company_id'] = $existing_connection['company_id'];
			}

			// Preserve existing sandbox_mode if not provided.
			if ( ! isset( $connection_data['sandbox_mode'] ) && isset( $existing_connection['sandbox_mode'] ) ) {
				$connection_data['sandbox_mode'] = $existing_connection['sandbox_mode'];
			}

			// Preserve created timestamp.
			if ( ! isset( $connection_data['created'] ) && ! empty( $existing_connection['created'] ) ) {
				$connection_data['created'] = $existing_connection['created'];
			}
		}

		$validation = self::validate_connection_data( $connection_data );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Prepare connection data.
		$connection = array(
			'id'              => $connection_id,
			'name'            => sanitize_text_field( $connection_data['name'] ),
			'url'             => esc_url_raw( trailingslashit( $connection_data['url'] ) ),
			'connection_type' => isset( $connection_data['connection_type'] ) ? sanitize_key( $connection_data['connection_type'] ) : 'wordpress',
			'auth_type'       => sanitize_key( $connection_data['auth_type'] ),
			'username'        => isset( $connection_data['username'] ) ? sanitize_text_field( $connection_data['username'] ) : '',
			'password'        => isset( $connection_data['password'] ) ? $connection_data['password'] : '',
			'token'           => isset( $connection_data['token'] ) ? $connection_data['token'] : '',
			'consumer_key'    => isset( $connection_data['consumer_key'] ) ? $connection_data['consumer_key'] : '',
			'consumer_secret' => isset( $connection_data['consumer_secret'] ) ? $connection_data['consumer_secret'] : '',
			'api_key'         => isset( $connection_data['api_key'] ) ? $connection_data['api_key'] : '',
			'api_secret'      => isset( $connection_data['api_secret'] ) ? $connection_data['api_secret'] : '',
			'client_id'       => isset( $connection_data['client_id'] ) ? sanitize_text_field( $connection_data['client_id'] ) : '',
			'client_secret'   => isset( $connection_data['client_secret'] ) ? $connection_data['client_secret'] : '',
			'app_id'          => isset( $connection_data['app_id'] ) ? sanitize_text_field( $connection_data['app_id'] ) : '',
			'app_secret'      => isset( $connection_data['app_secret'] ) ? $connection_data['app_secret'] : '',
			'location_id'     => isset( $connection_data['location_id'] ) ? sanitize_text_field( $connection_data['location_id'] ) : '',
			'company_id'      => isset( $connection_data['company_id'] ) ? sanitize_text_field( $connection_data['company_id'] ) : '',
			'sandbox_mode'    => ! empty( $connection_data['sandbox_mode'] ),
			'has_woocommerce' => ! empty( $connection_data['has_woocommerce'] ),
			'enabled'         => ! empty( $connection_data['enabled'] ),
			'created'         => isset( $connection_data['created'] ) ? $connection_data['created'] : current_time( 'mysql' ),
			'updated'         => current_time( 'mysql' ),
			// Gmail-specific fields.
			'refresh_token'   => isset( $connection_data['refresh_token'] ) ? $connection_data['refresh_token'] : '',
			'user_email'      => isset( $connection_data['user_email'] ) ? sanitize_email( $connection_data['user_email'] ) : '',
			// Google Drive-specific fields.
			'folder_id'       => isset( $connection_data['folder_id'] ) ? sanitize_text_field( $connection_data['folder_id'] ) : '',
			// Telegram-specific fields.
			'bot_username'    => isset( $connection_data['bot_username'] ) ? sanitize_text_field( $connection_data['bot_username'] ) : '',
			// Telegram Web Login feature flag and after-login redirect URL.
			'enable_web_login'       => ! empty( $connection_data['enable_web_login'] ),
			'web_login_redirect_url' => isset( $connection_data['web_login_redirect_url'] ) ? esc_url_raw( $connection_data['web_login_redirect_url'] ) : '',
			// WhatsApp-specific fields.
			'phone_number_id'     => isset( $connection_data['phone_number_id'] ) ? sanitize_text_field( $connection_data['phone_number_id'] ) : '',
			'display_phone_number' => isset( $connection_data['display_phone_number'] ) ? sanitize_text_field( $connection_data['display_phone_number'] ) : '',
			'business_account_id' => isset( $connection_data['business_account_id'] ) ? sanitize_text_field( $connection_data['business_account_id'] ) : '',
			'system_user_id'      => isset( $connection_data['system_user_id'] ) ? sanitize_text_field( $connection_data['system_user_id'] ) : '',
			'verify_token'        => isset( $connection_data['verify_token'] ) ? sanitize_text_field( $connection_data['verify_token'] ) : '',
			'channel_description' => isset( $connection_data['channel_description'] ) ? sanitize_text_field( $connection_data['channel_description'] ) : '',
			'channel_url'         => isset( $connection_data['channel_url'] ) ? esc_url_raw( $connection_data['channel_url'] ) : '',
			'group_id'            => isset( $connection_data['group_id'] ) ? sanitize_text_field( $connection_data['group_id'] ) : '',
			// Slack-specific fields.
			'workspace_id'    => isset( $connection_data['workspace_id'] ) ? sanitize_text_field( $connection_data['workspace_id'] ) : '',
			// Discord-specific fields.
			'application_id'  => isset( $connection_data['application_id'] ) ? sanitize_text_field( $connection_data['application_id'] ) : '',
			'guild_id'        => isset( $connection_data['guild_id'] ) ? sanitize_text_field( $connection_data['guild_id'] ) : '',
			// Discord Ed25519 public key for interaction signature verification.
			'public_key'      => isset( $connection_data['public_key'] ) ? sanitize_text_field( $connection_data['public_key'] ) : '',
			// Microsoft Teams-specific fields.
			'tenant_id'       => isset( $connection_data['tenant_id'] ) ? sanitize_text_field( $connection_data['tenant_id'] ) : '',
			// HMAC-SHA256 signing secret (Slack Events API / Teams outgoing webhooks).
			'signing_secret'  => isset( $connection_data['signing_secret'] ) ? $connection_data['signing_secret'] : '',
			// Telegram webhook secret token (X-Telegram-Bot-Api-Secret-Token).
			'secret_token'    => isset( $connection_data['secret_token'] ) ? $connection_data['secret_token'] : '',
			// Facebook Messenger-specific fields.
			'page_id'         => isset( $connection_data['page_id'] ) ? sanitize_text_field( $connection_data['page_id'] ) : '',
			// Graph API version (WhatsApp and Facebook Messenger).
			'graph_api_version' => isset( $connection_data['graph_api_version'] ) && preg_match( '/^v\d+\.\d+$/', $connection_data['graph_api_version'] ) ? $connection_data['graph_api_version'] : '',
			// WebChat P2P-specific fields.
			'p2p_connection_id' => isset( $connection_data['p2p_connection_id'] ) ? sanitize_text_field( $connection_data['p2p_connection_id'] ) : '',
			// Google Chat-specific fields.
			'google_chat_space'  => isset( $connection_data['google_chat_space'] ) ? sanitize_text_field( $connection_data['google_chat_space'] ) : '',
			// Google Chat incoming webhook URL for sending AI replies (no OAuth needed).
			'reply_webhook_url'  => isset( $connection_data['reply_webhook_url'] ) ? esc_url_raw( $connection_data['reply_webhook_url'] ) : '',
			// When true, OIDC token validation is skipped for incoming webhook events.
			// Useful for environments where the Authorization header is stripped by a proxy or WAF.
			'disable_oidc_verification' => ! empty( $connection_data['disable_oidc_verification'] ),
			// WhatsApp channel routing: assistant IDs listening on this channel.
			'assigned_assistant_ids' => isset( $connection_data['assigned_assistant_ids'] ) && is_array( $connection_data['assigned_assistant_ids'] )
				? array_values( array_map( 'absint', $connection_data['assigned_assistant_ids'] ) )
				: array(),
			// Generic API test endpoint.
			'test_endpoint'   => isset( $connection_data['test_endpoint'] ) ? sanitize_text_field( $connection_data['test_endpoint'] ) : '',
			// Cache TTL.
			'cache_ttl'       => isset( $connection_data['cache_ttl'] ) ? max( 0, min( 3600, absint( $connection_data['cache_ttl'] ) ) ) : 300,
		);

		// Encrypt sensitive data (only if not already encrypted).
		if ( ! empty( $connection['password'] ) && empty( $connection_data['_password_encrypted'] ) ) {
			$connection['password'] = self::encrypt_value( $connection['password'] );
		}

		if ( ! empty( $connection['token'] ) && empty( $connection_data['_token_encrypted'] ) ) {
			$connection['token'] = self::encrypt_value( $connection['token'] );
		}

		if ( ! empty( $connection['consumer_key'] ) && empty( $connection_data['_consumer_key_encrypted'] ) ) {
			$connection['consumer_key'] = self::encrypt_value( $connection['consumer_key'] );
		}

		if ( ! empty( $connection['consumer_secret'] ) && empty( $connection_data['_consumer_secret_encrypted'] ) ) {
			$connection['consumer_secret'] = self::encrypt_value( $connection['consumer_secret'] );
		}

		if ( ! empty( $connection['api_key'] ) && empty( $connection_data['_api_key_encrypted'] ) ) {
			$connection['api_key'] = self::encrypt_value( $connection['api_key'] );
		}

		if ( ! empty( $connection['api_secret'] ) && empty( $connection_data['_api_secret_encrypted'] ) ) {
			$connection['api_secret'] = self::encrypt_value( $connection['api_secret'] );
		}

		if ( ! empty( $connection['client_secret'] ) && empty( $connection_data['_client_secret_encrypted'] ) ) {
			$connection['client_secret'] = self::encrypt_value( $connection['client_secret'] );
		}

		if ( ! empty( $connection['app_secret'] ) && empty( $connection_data['_app_secret_encrypted'] ) ) {
			$connection['app_secret'] = self::encrypt_value( $connection['app_secret'] );
		}

		if ( ! empty( $connection['refresh_token'] ) && empty( $connection_data['_refresh_token_encrypted'] ) ) {
			$connection['refresh_token'] = self::encrypt_value( $connection['refresh_token'] );
		}

		if ( ! empty( $connection['signing_secret'] ) && empty( $connection_data['_signing_secret_encrypted'] ) ) {
			$connection['signing_secret'] = self::encrypt_value( $connection['signing_secret'] );
		}

		if ( ! empty( $connection['secret_token'] ) && empty( $connection_data['_secret_token_encrypted'] ) ) {
			$connection['secret_token'] = self::encrypt_value( $connection['secret_token'] );
		}

		$connections[ $connection_id ] = $connection;

		$updated = update_option( self::OPTION_NAME, $connections );

		if ( false === $updated && ! isset( $connections[ $connection_id ] ) ) {
			// update_option returns false if the value is the same, which shouldn't happen here
			// but also returns false on actual failure. Check if it was actually saved.
			$saved_connections = get_option( self::OPTION_NAME, array() );
			if ( ! isset( $saved_connections[ $connection_id ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_save_failed',
					__( 'Failed to save connection. Please try again.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		/**
		 * Fires after a remote site connection is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @param array  $connection    Connection data.
		 */
		do_action( 'wp_mcp_ai_pro_remote_site_saved', $connection_id, $connection );

		return $connection_id;
	}

	/**
	 * Update the encrypted API key (access token) stored for a connection.
	 *
	 * This is a lightweight alternative to calling the full save_connection()
	 * when only the access token needs to change (e.g. after an automatic
	 * token refresh).
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id  Connection ID.
	 * @param string $new_token      Plain-text new access token.
	 * @return bool True on success, false on failure.
	 */
	public static function update_api_key( $connection_id, $new_token ) {
		$connections   = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( ! isset( $connections[ $connection_id ] ) || '' === (string) $new_token ) {
			return false;
		}

		$connections[ $connection_id ]['api_key'] = self::encrypt_value( $new_token );

		return (bool) update_option( self::OPTION_NAME, $connections );
	}

	/**
	 * Attempt to automatically refresh a WhatsApp access token using stored Meta app credentials.
	 *
	 * Two strategies are tried in order:
	 *
	 * 1. **fb_exchange_token** — exchanges the current access token for a new long-lived
	 *    User Access Token (~60 days). Works when the existing token is still valid or
	 *    only mildly expired.
	 *
	 * 2. **System User token generation** — obtains an App Access Token via the
	 *    `client_credentials` grant and then calls `POST /{system_user_id}/access_tokens`
	 *    to mint a new never-expiring System User token. This works even when the previous
	 *    token has fully expired, provided the app has admin access to the system user.
	 *
	 * When a new token is obtained it is automatically persisted via {@see update_api_key()}.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $connection        Full connection data array (encrypted secrets are decrypted internally).
	 * @param string $connection_id     Connection ID used to persist the refreshed token.
	 * @param string $current_token     Current (possibly expired) plain-text access token.
	 * @param string $graph_api_version Graph API version string (e.g. 'v21.0').
	 * @return string|false New plain-text access token on success, false when refresh is not possible.
	 */
	public static function refresh_whatsapp_token( array $connection, $connection_id, $current_token, $graph_api_version ) {
		$app_id     = isset( $connection['app_id'] ) ? trim( (string) $connection['app_id'] ) : '';
		$app_secret = isset( $connection['api_secret'] ) ? trim( (string) self::decrypt_value( $connection['api_secret'] ) ) : '';

		if ( '' === $app_id || '' === $app_secret ) {
			return false;
		}

		// --- Strategy 1: fb_exchange_token ----------------------------------------.
		$exchange_url = add_query_arg(
			array(
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $app_id,
				'client_secret'     => $app_secret,
				'fb_exchange_token' => $current_token,
			),
			sprintf( 'https://graph.facebook.com/%s/oauth/access_token', rawurlencode( $graph_api_version ) )
		);

		$exchange_response = wp_remote_get(
			$exchange_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $exchange_response ) && 200 === (int) wp_remote_retrieve_response_code( $exchange_response ) ) {
			$exchange_body = json_decode( wp_remote_retrieve_body( $exchange_response ), true );
			if ( is_array( $exchange_body ) && ! empty( $exchange_body['access_token'] ) ) {
				$new_token = trim( (string) $exchange_body['access_token'] );
				if ( '' !== $new_token ) {
					if ( $new_token !== $current_token ) {
						// A new token was returned — save and return it.
						self::update_api_key( $connection_id, $new_token );
						if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
							WP_MCP_AI_Logger::log_event(
								'whatsapp_token_refreshed',
								'WhatsApp access token refreshed via fb_exchange_token.',
								array( 'connection_id' => $connection_id )
							);
						}
						return $new_token;
					}
					// Same token returned — it is still valid; return it as-is
					// without persisting (nothing changed) and skip Strategy 2.
					return $new_token;
				}
			}
		}

		// --- Strategy 2: System User token generation ------------------------------.
		$system_user_id = isset( $connection['system_user_id'] ) ? trim( (string) $connection['system_user_id'] ) : '';
		if ( '' === $system_user_id ) {
			return false;
		}

		// Step 2a: obtain an App Access Token.
		$app_token_url = add_query_arg(
			array(
				'client_id'     => $app_id,
				'client_secret' => $app_secret,
				'grant_type'    => 'client_credentials',
			),
			sprintf( 'https://graph.facebook.com/%s/oauth/access_token', rawurlencode( $graph_api_version ) )
		);

		$app_token_response = wp_remote_get(
			$app_token_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $app_token_response ) || 200 !== (int) wp_remote_retrieve_response_code( $app_token_response ) ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'WhatsApp token refresh: failed to obtain App Access Token.',
					array( 'connection_id' => $connection_id )
				);
			}
			return false;
		}

		$app_token_body = json_decode( wp_remote_retrieve_body( $app_token_response ), true );
		$app_token      = is_array( $app_token_body ) && ! empty( $app_token_body['access_token'] ) ? trim( (string) $app_token_body['access_token'] ) : '';
		if ( '' === $app_token ) {
			return false;
		}

		// Step 2b: generate a new System User Access Token.
		$sys_token_url = sprintf(
			'https://graph.facebook.com/%s/%s/access_tokens',
			rawurlencode( $graph_api_version ),
			rawurlencode( $system_user_id )
		);

		$sys_token_response = wp_remote_post(
			$sys_token_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $app_token,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'business_app' => $app_id,
						'scope'        => 'whatsapp_business_messaging,whatsapp_business_management',
					)
				),
			)
		);

		if ( is_wp_error( $sys_token_response ) || 200 !== (int) wp_remote_retrieve_response_code( $sys_token_response ) ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'WhatsApp token refresh: System User token generation failed.',
					array(
						'connection_id'  => $connection_id,
						'system_user_id' => substr( $system_user_id, 0, 4 ) . '***',
					)
				);
			}
			return false;
		}

		$sys_token_body = json_decode( wp_remote_retrieve_body( $sys_token_response ), true );
		$new_token      = is_array( $sys_token_body ) && ! empty( $sys_token_body['access_token'] ) ? trim( (string) $sys_token_body['access_token'] ) : '';
		if ( '' === $new_token ) {
			return false;
		}

		self::update_api_key( $connection_id, $new_token );
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'whatsapp_token_refreshed',
				'WhatsApp access token refreshed via System User token generation.',
				array( 'connection_id' => $connection_id )
			);
		}
		return $new_token;
	}

	/**
	 * Delete a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID to delete.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_connection( $connection_id ) {
		$connections   = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( ! isset( $connections[ $connection_id ] ) ) {
			return false;
		}

		/**
		 * Fires before a remote site connection is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 */
		do_action( 'wp_mcp_ai_pro_remote_site_deleted', $connection_id );

		unset( $connections[ $connection_id ] );

		return update_option( self::OPTION_NAME, $connections );
	}

	/**
	 * Test a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $connection Connection data array or connection ID.
	 * @return array|WP_Error Test results on success, WP_Error on failure.
	 */
	public static function test_connection( $connection ) {
		if ( is_string( $connection ) ) {
			$connection = self::get_connection( $connection );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_connection',
					__( 'Connection not found.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$validation = self::validate_connection_data( $connection );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';

		// Handle Mesh Peer connections separately.
		if ( 'mesh_peer' === $connection_type ) {
			return self::test_mesh_peer_connection( $connection );
		}

		// Handle Telegram connections separately.
		if ( 'telegram' === $connection_type ) {
			return self::test_telegram_connection( $connection );
		}

		// Handle WhatsApp connections separately.
		if ( 'whatsapp' === $connection_type ) {
			return self::test_whatsapp_connection( $connection );
		}

		// Handle Flowhub connections separately.
		if ( 'flowhub' === $connection_type ) {
			return self::test_flowhub_connection( $connection );
		}

		// Handle EZuite ERP connections separately.
		if ( 'ezuite_erp' === $connection_type ) {
			return self::test_ezuite_connection( $connection );
		}

		// Handle Google Chat connections separately.
		if ( 'google_chat' === $connection_type ) {
			return self::test_google_chat_connection( $connection );
		}

		// Handle Gmail connections separately.
		if ( 'gmail' === $connection_type ) {
			return array(
				'success' => true,
				'gmail'   => true,
				'message' => __( 'Gmail OAuth credentials saved. Complete the OAuth flow via the connect button to finish setup.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Handle Google Drive connections separately.
		if ( 'google_drive' === $connection_type ) {
			return array(
				'success'      => true,
				'google_drive' => true,
				'message'      => __( 'Google Drive OAuth credentials saved. Complete the OAuth flow via the connect button to finish setup.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Test basic WordPress REST API access.
		$response = self::make_request( $connection, 'wp/v2/types' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = array(
			'success'     => true,
			'wordpress'   => true,
			'woocommerce' => false,
			'site_name'   => '',
			'site_url'    => $connection['url'],
			'message'     => __( 'Connection successful.', 'mcp-ai-wpoos-pro' ),
		);

		// Test WooCommerce API access if enabled.
		if ( ! empty( $connection['has_woocommerce'] ) ) {
			$wc_response = self::make_request( $connection, 'wc/v3/system_status' );

			if ( ! is_wp_error( $wc_response ) ) {
				$results['woocommerce'] = true;
			}
		}

		// Get site info.
		$site_info = self::make_request( $connection, 'wp/v2' );

		if ( ! is_wp_error( $site_info ) && isset( $site_info['name'] ) ) {
			$results['site_name'] = $site_info['name'];
		}

		return $results;
	}

	/**
	 * Test Google Chat API connection.
	 *
	 * Supports Service Account JSON key, OAuth refresh token, or OAuth
	 * Client ID + Secret only (partial setup — OAuth flow not yet completed).
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_google_chat_connection( $connection ) {
		$has_api_key     = ! empty( $connection['api_key'] );
		$has_refresh     = ! empty( $connection['refresh_token'] );
		$has_credentials = ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] );

		if ( ! $has_api_key && ! $has_refresh && ! $has_credentials ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_google_chat_credentials',
				__( 'No credentials configured for this Google Chat connection. Please add a Service Account JSON key or complete the OAuth setup (OAuth Client ID and Client Secret).', 'mcp-ai-wpoos-pro' )
			);
		}

		// If only OAuth client credentials are present (no service account key and no refresh
		// token) we cannot make a live API call yet — the OAuth flow must be completed first.
		if ( ! $has_api_key && ! $has_refresh ) {
			return array(
				'success'     => true,
				'google_chat' => true,
				'partial'     => true,
				'message'     => __( 'OAuth credentials saved. Complete the OAuth flow via the "Connect to Google Chat" button to finish setup and obtain a refresh token.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Load the Google Service Account helper.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-google-service-account.php';
		}

		$access_token = '';

		// Try Service Account JSON key first.
		if ( $has_api_key ) {
			$api_key      = self::decrypt_value( $connection['api_key'] );
			$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
				$api_key,
				'https://www.googleapis.com/auth/chat.bot'
			);

			if ( ! is_wp_error( $token_result ) ) {
				$access_token = $token_result;
			} elseif ( $has_refresh ) {
				// Service account failed; fall through to OAuth refresh token below.
			} else {
				return $token_result;
			}
		}

		// Fall back to OAuth refresh token.
		if ( '' === $access_token && $has_refresh ) {
			$client_id     = isset( $connection['client_id'] ) ? $connection['client_id'] : '';
			$client_secret = ! empty( $connection['client_secret'] ) ? self::decrypt_value( $connection['client_secret'] ) : '';
			$refresh_token = self::decrypt_value( $connection['refresh_token'] );

			$token_result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_refresh_token(
				$client_id,
				$client_secret,
				$refresh_token
			);

			if ( is_wp_error( $token_result ) ) {
				return $token_result;
			}

			$access_token = $token_result;
		}

		if ( '' === $access_token ) {
			return new WP_Error(
				'wp_mcp_ai_pro_google_chat_token_error',
				__( 'Failed to obtain a Google access token. Please check your credentials.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify the token by calling the Google Chat spaces.list endpoint.
		$response = wp_remote_get(
			'https://chat.googleapis.com/v1/spaces?pageSize=1',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_google_chat_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Google Chat API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid response from Google Chat API.', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_pro_google_chat_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__( 'Google Chat API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$error_msg
				)
			);
		}

		$spaces      = isset( $body['spaces'] ) && is_array( $body['spaces'] ) ? $body['spaces'] : array();
		$space_count = count( $spaces );

		return array(
			'success'     => true,
			'google_chat' => true,
			/* translators: %d: number of accessible Google Chat spaces */
			'message'     => sprintf( _n( 'Google Chat connection successful. %d space accessible.', 'Google Chat connection successful. %d spaces accessible.', $space_count, 'mcp-ai-wpoos-pro' ), $space_count ),
			'space_count' => $space_count,
		);
	}

	/**
	 * Test Flowhub API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_flowhub_connection( $connection ) {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		$connection_id = isset( $connection['id'] ) ? $connection['id'] : null;
		$client        = new WP_MCP_AI_Flowhub_Client( $connection_id );

		// Test with a simple inventory request.
		$response = $client->get_inventory( array( 'limit' => 1 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = array(
			'success' => true,
			'flowhub' => true,
			'message' => __( 'Flowhub connection successful. API credentials verified.', 'mcp-ai-wpoos-pro' ),
		);

		// Add inventory count if available.
		if ( isset( $response['total'] ) ) {
			$results['inventory_count'] = absint( $response['total'] );
			/* translators: %d: number of inventory items */
			$results['message'] = sprintf( __( 'Flowhub connection successful. Found %d inventory items.', 'mcp-ai-wpoos-pro' ), $results['inventory_count'] );
		}

		return $results;
	}

	/**
	 * Test EZuite ERP API connection.
	 *
	 * Makes a simple API call to verify the connection and API key.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_ezuite_connection( $connection ) {
		// Validate required fields.
		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_url',
				__( 'EZuite API URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['api_key'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_key',
				__( 'EZuite API key is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Decrypt the API key.
		$api_key = self::decrypt_value( $connection['api_key'] );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_api_key',
				__( 'Invalid or corrupted API key.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare a simple test request - use LX_ItemPull with a limit to minimize data.
		$url = untrailingslashit( $connection['url'] );

		$request_body = array(
			'API_Key'    => $api_key,
			'API_Action' => 'LX_ItemPull',
			'API_Body'   => array(
				array(
					'Location_Code' => 'ALL',
					'Limit'         => 1, // Only fetch 1 item to test connection.
				),
			),
		);

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $request_body ),
		);

		// Make the request.
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to EZuite API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_pro_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'EZuite API returned error status %d. Please check your API URL and credentials.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		// Parse the JSON response.
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_response',
				__( 'EZuite API returned invalid JSON response.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check the response status.
		$ezuite_status = isset( $data['Status_Code'] ) ? absint( $data['Status_Code'] ) : 0;

		if ( 200 !== $ezuite_status ) {
			$error_message = isset( $data['Message'] ) ? sanitize_text_field( $data['Message'] ) : __( 'Unknown error', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_pro_ezuite_error',
				sprintf(
					/* translators: 1: status code, 2: error message */
					__( 'EZuite API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$ezuite_status,
					$error_message
				)
			);
		}

		// Connection successful!
		$results = array(
			'success'    => true,
			'ezuite_erp' => true,
			'api_url'    => $connection['url'],
			'message'    => __( 'EZuite ERP connection successful. API credentials verified.', 'mcp-ai-wpoos-pro' ),
		);

		// Add item count if available in response.
		if ( isset( $data['Response_Body'] ) && is_array( $data['Response_Body'] ) ) {
			$item_count = count( $data['Response_Body'] );
			if ( $item_count > 0 ) {
				/* translators: %d: number of items retrieved */
				$results['message'] = sprintf( __( 'EZuite ERP connection successful. Retrieved %d test item(s).', 'mcp-ai-wpoos-pro' ), $item_count );
			}
		}

		return $results;
	}

	/**
	 * Validate a Telegram bot token format.
	 *
	 * Telegram bot tokens follow the format: {numeric_id}:{alphanumeric_string_≥30_chars}
	 *
	 * @param string $bot_token The bot token to validate.
	 * @return bool True when the token format is valid, false otherwise.
	 */
	public static function is_valid_telegram_bot_token( $bot_token ) {
		return 1 === preg_match( '/^\d+:[A-Za-z0-9_-]{30,}$/', (string) $bot_token );
	}

	/**
	 * Validate a Telegram webhook secret token format.
	 *
	 * Per the Telegram Bot API, secret tokens may only contain A–Z, a–z, 0–9, underscores
	 * and hyphens (1–256 characters).
	 *
	 * @param string $secret_token The secret token to validate.
	 * @return bool True when the secret token format is valid, false otherwise.
	 */
	public static function is_valid_telegram_secret_token( $secret_token ) {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,256}$/', (string) $secret_token );
	}

	/**
	 * Test Telegram Bot API connection.
	 *
	 * Tests Telegram connection by calling getMe and getWebhookInfo on the Bot API.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_telegram_connection( $connection ) {
		$bot_token = isset( $connection['api_key'] ) ? self::decrypt_value( $connection['api_key'] ) : '';

		if ( empty( $bot_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_telegram_missing_token',
				__( 'Telegram bot token is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate token format: numeric_id:alphanum_string (at least 30 chars total).
		if ( ! self::is_valid_telegram_bot_token( $bot_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_telegram_invalid_token',
				__( 'The token format is invalid. A Telegram bot token looks like: 1234567890:ABCdefGHIjklMNOpqrsTUVwxyz. Obtain your token from @BotFather on Telegram.', 'mcp-ai-wpoos-pro' )
			);
		}

		$api_base = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );

		// Call getMe to verify the bot token and retrieve bot identity.
		$get_me_response = wp_remote_get(
			$api_base . '/getMe',
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $get_me_response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_telegram_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to Telegram API: %s', 'mcp-ai-wpoos-pro' ),
					$get_me_response->get_error_message()
				)
			);
		}

		$get_me_code = wp_remote_retrieve_response_code( $get_me_response );
		$get_me_data = json_decode( wp_remote_retrieve_body( $get_me_response ), true );

		if ( 200 !== (int) $get_me_code || empty( $get_me_data['ok'] ) ) {
			$tg_description = isset( $get_me_data['description'] ) ? $get_me_data['description'] : __( 'Invalid response from Telegram API.', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_pro_telegram_api_error',
				sprintf(
					/* translators: %s: error description */
					__( 'Telegram API error: %s', 'mcp-ai-wpoos-pro' ),
					$tg_description
				)
			);
		}

		$bot = isset( $get_me_data['result'] ) ? $get_me_data['result'] : array();

		$results = array(
			'success'      => true,
			'telegram'     => true,
			'bot_id'       => isset( $bot['id'] ) ? $bot['id'] : '',
			'bot_username' => isset( $bot['username'] ) ? $bot['username'] : '',
			'bot_name'     => isset( $bot['first_name'] ) ? $bot['first_name'] : '',
			'can_join_groups'        => ! empty( $bot['can_join_groups'] ),
			'can_read_all_messages'  => ! empty( $bot['can_read_all_group_messages'] ),
			'supports_inline_queries' => ! empty( $bot['supports_inline_queries'] ),
			'webhook_url'  => '',
			'pending_updates' => 0,
			'message'      => __( 'Telegram bot token verified successfully.', 'mcp-ai-wpoos-pro' ),
		);

		// Call getWebhookInfo to retrieve the current webhook configuration.
		$webhook_response = wp_remote_get(
			$api_base . '/getWebhookInfo',
			array( 'timeout' => 15 )
		);

		if ( ! is_wp_error( $webhook_response ) && 200 === (int) wp_remote_retrieve_response_code( $webhook_response ) ) {
			$webhook_data = json_decode( wp_remote_retrieve_body( $webhook_response ), true );
			if ( ! empty( $webhook_data['ok'] ) && isset( $webhook_data['result'] ) ) {
				$wh = $webhook_data['result'];
				$results['webhook_url']     = isset( $wh['url'] ) ? $wh['url'] : '';
				$results['pending_updates'] = isset( $wh['pending_update_count'] ) ? (int) $wh['pending_update_count'] : 0;
				if ( ! empty( $wh['last_error_message'] ) ) {
					$results['webhook_last_error'] = $wh['last_error_message'];
				}
				$expected_url = home_url( '/wp-json/mcp-ai/v1/webhooks/telegram' );
				if ( empty( $results['webhook_url'] ) ) {
					$results['warning'] = sprintf(
						/* translators: %s: expected webhook URL */
						__( 'No webhook is set. Use the Set Webhook button or set it manually to: %s', 'mcp-ai-wpoos-pro' ),
						$expected_url
					);
				} elseif ( false === strpos( $results['webhook_url'], home_url( '/' ) ) ) {
					$results['warning'] = sprintf(
						/* translators: 1: current webhook URL, 2: expected URL */
						__( 'Webhook is set to a different site (%1$s). Expected: %2$s', 'mcp-ai-wpoos-pro' ),
						$results['webhook_url'],
						$expected_url
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Test WhatsApp Business API connection.
	 *
	 * Tests WhatsApp connection by verifying phone number and attempting to retrieve business profile.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_whatsapp_connection( $connection ) {
		// Validate required fields.
		$access_token    = isset( $connection['api_key'] ) ? self::decrypt_value( $connection['api_key'] ) : '';
		$phone_number_id = isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
		$app_secret      = isset( $connection['api_secret'] ) ? self::decrypt_value( $connection['api_secret'] ) : '';

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_missing_token',
				__( 'WhatsApp access token is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $phone_number_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_missing_phone_id',
				__( 'WhatsApp phone number ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Reject App Access Tokens — they have the format "{numeric_app_id}|{hash}" and cannot
		// send or receive messages via the WhatsApp Cloud API. Users must supply a
		// System User Access Token from Meta Business Suite or a User Access Token
		// with the whatsapp_business_messaging permission.
		// Meta App Access Tokens always begin with the numeric App ID followed by a pipe,
		// so a leading-digits-pipe pattern is a reliable and specific heuristic.
		if ( 1 === preg_match( '/^\d+\|/', $access_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_app_token',
				__( 'The access token appears to be a Meta App Access Token (format: {app_id}|{hash}). App Access Tokens cannot send or receive WhatsApp messages via the Cloud API. Please use a System User Access Token from Meta Business Suite (Business Settings → System Users) or a User Access Token with the whatsapp_business_messaging permission.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Extract the Graph API version from the connection URL, falling back to v21.0.
		$graph_api_version = 'v21.0';
		if ( isset( $connection['url'] ) && preg_match( '#graph\.facebook\.com/(v\d+\.\d+)#', $connection['url'], $version_matches ) ) {
			$graph_api_version = $version_matches[1];
		} elseif ( isset( $connection['graph_api_version'] ) && preg_match( '/^v\d+\.\d+$/', $connection['graph_api_version'] ) ) {
			$graph_api_version = $connection['graph_api_version'];
		}

		// Compute appsecret_proof (HMAC-SHA256 of the access token keyed with the app secret).
		// Required when the Meta app has "Require App Secret Proof for Server API calls" enabled
		// in App Dashboard → Settings → Advanced.
		$appsecret_proof = ! empty( $app_secret ) ? hash_hmac( 'sha256', $access_token, $app_secret ) : '';

		// Test 1: Get phone number info.
		// Only request fields accessible with whatsapp_business_messaging permission.
		// quality_rating requires whatsapp_business_management and will cause a 403 with App Access Tokens.
		$phone_query_args = array( 'fields' => 'display_phone_number,verified_name' );
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
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to WhatsApp API: %s', 'mcp-ai-wpoos-pro' ),
					$phone_response->get_error_message()
				)
			);
		}

		$phone_code = wp_remote_retrieve_response_code( $phone_response );
		$phone_body = wp_remote_retrieve_body( $phone_response );
		$phone_data = json_decode( $phone_body, true );

		$limited_field_access = false;
		if ( 200 !== $phone_code ) {
			$fb_error_code = isset( $phone_data['error']['code'] ) ? (int) $phone_data['error']['code'] : 0;
			$error_message = isset( $phone_data['error']['message'] ) ? $phone_data['error']['message'] : __( 'Invalid response from WhatsApp API.', 'mcp-ai-wpoos-pro' );

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
					return new WP_Error(
						'wp_mcp_ai_pro_whatsapp_api_error',
						sprintf(
							/* translators: 1: status code, 2: error message */
							__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
							$phone_code,
							$error_message
						)
					);
				}

			// When no appsecret_proof was sent but Meta still returns 400 "Invalid appsecret_proof",
			// the app has "Require App Secret Proof" enabled.  Guide the user to enter the App Secret.
			} elseif ( 400 === (int) $phone_code && ! $appsecret_proof && false !== stripos( $error_message, 'appsecret_proof' ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_whatsapp_appsecret_required',
					__( 'The Meta app requires App Secret Proof for API calls. Please enter your Meta App Secret in the App Secret field of this connection and try again.', 'mcp-ai-wpoos-pro' )
				);

			// When the token lacks field-level access (Facebook error code 200 = permission
			// error on a specific field), fall back to the base endpoint which returns only
			// the phone number ID.  This lets tokens that have whatsapp_business_messaging
			// for sending but cannot read phone-number fields still pass the connection test.
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
					// This means the token is valid but lacks permission to read any phone number fields.
					// Messaging will still work if the token has whatsapp_business_messaging scope.
					$fallback_http_code  = ! is_wp_error( $fallback_response ) ? (int) wp_remote_retrieve_response_code( $fallback_response ) : 0;
					$fallback_body       = ! is_wp_error( $fallback_response ) ? json_decode( wp_remote_retrieve_body( $fallback_response ), true ) : array();
					$fallback_error_code = isset( $fallback_body['error']['code'] ) ? (int) $fallback_body['error']['code'] : 0;

					if ( 403 === $fallback_http_code && 200 === $fallback_error_code ) {
						$phone_data           = array();
						$limited_field_access = true;
					} else {
						return new WP_Error(
							'wp_mcp_ai_pro_whatsapp_api_error',
							sprintf(
								/* translators: 1: status code, 2: error message */
								__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
								$phone_code,
								$error_message
							)
						);
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
						return new WP_Error(
							'wp_mcp_ai_pro_whatsapp_api_error',
							sprintf(
								/* translators: 1: status code, 2: error message */
								__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
								$phone_code,
								$error_message
							)
						);
					}
				}
			} else {
				return new WP_Error(
					'wp_mcp_ai_pro_whatsapp_api_error',
					sprintf(
						/* translators: 1: status code, 2: error message */
						__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
						$phone_code,
						$error_message
					)
				);
			}
		}

		// Extract phone number details.
		$display_phone = isset( $phone_data['display_phone_number'] ) ? $phone_data['display_phone_number'] : '';
		$verified      = isset( $phone_data['verified_name'] ) ? $phone_data['verified_name'] : '';

		// Optionally get quality rating — requires whatsapp_business_management permission.
		// This is not available with App Access Tokens, so treat it as advisory only.
		$quality              = 'unknown';
		$quality_query_args   = array( 'fields' => 'quality_rating' );
		if ( $appsecret_proof ) {
			$quality_query_args['appsecret_proof'] = $appsecret_proof;
		}
		$quality_endpoint = add_query_arg(
			$quality_query_args,
			sprintf( 'https://graph.facebook.com/%s/%s', $graph_api_version, rawurlencode( $phone_number_id ) )
		);
		$quality_response      = wp_remote_get(
			$quality_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);
		if ( ! is_wp_error( $quality_response ) && 200 === wp_remote_retrieve_response_code( $quality_response ) ) {
			$quality_body = wp_remote_retrieve_body( $quality_response );
			$quality_data = json_decode( $quality_body, true );
			if ( isset( $quality_data['quality_rating'] ) ) {
				$quality = $quality_data['quality_rating'];
			}
		}

		// Test 2: Try to get business profile (optional, may not have permissions).
		$profile_base     = sprintf( 'https://graph.facebook.com/%s/%s/whatsapp_business_profile', $graph_api_version, rawurlencode( $phone_number_id ) );
		$profile_endpoint = $appsecret_proof ? add_query_arg( 'appsecret_proof', $appsecret_proof, $profile_base ) : $profile_base;

		$profile_response = wp_remote_get(
			$profile_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		$business_name = '';
		if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
			$profile_body = wp_remote_retrieve_body( $profile_response );
			$profile_data = json_decode( $profile_body, true );

			if ( isset( $profile_data['data'][0]['about'] ) ) {
				$business_name = $profile_data['data'][0]['about'];
			}
		}

		// Build success response.
		$results = array(
			'success'         => true,
			'whatsapp'        => true,
			'phone_number'    => $display_phone,
			'verified_name'   => $verified,
			'quality_rating'  => $quality,
			'business_name'   => $business_name,
			'webhook_url'     => home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ),
			'has_app_secret'  => ! empty( $app_secret ),
			'message'         => __( 'WhatsApp connection successful! Phone number verified and API credentials valid.', 'mcp-ai-wpoos-pro' ),
		);

		// Warn if app secret is missing (needed for webhook signature validation).
		if ( empty( $app_secret ) ) {
			$results['warning'] = __( 'App secret is not configured. Webhook signature validation will be disabled. Add your app secret to enable it.', 'mcp-ai-wpoos-pro' );
		}

		// Add quality rating note if not green (only overrides when quality is actually known).
		if ( 'GREEN' !== strtoupper( $quality ) && 'unknown' !== $quality ) {
			$quality_warning = sprintf(
				/* translators: %s: quality rating */
				__( 'Note: Phone number quality rating is %s. Monitor your messaging quality to maintain good standing.', 'mcp-ai-wpoos-pro' ),
				strtoupper( $quality )
			);
			$results['warning'] = isset( $results['warning'] )
				? $results['warning'] . ' ' . $quality_warning
				: $quality_warning;
		}

		// Note when the token lacks permission to read phone-number details.
		if ( $limited_field_access ) {
			$field_note = __( 'Note: Phone number details are unavailable because the access token lacks permission to read phone number fields. Messaging will still work if the token has the whatsapp_business_messaging scope.', 'mcp-ai-wpoos-pro' );
			$results['warning'] = isset( $results['warning'] )
				? $results['warning'] . ' ' . $field_note
				: $field_note;
		}

		return $results;
	}

	/**
	 * Test mesh peer connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_mesh_peer_connection( $connection ) {
		// Use the base plugin's mesh peer tester if available.
		if ( class_exists( 'WP_MCP_AI_Mesh_Peer_Tester' ) ) {
			$peer = array(
				'name'    => isset( $connection['name'] ) ? $connection['name'] : '',
				'url'     => isset( $connection['url'] ) ? $connection['url'] : '',
				'api_key' => isset( $connection['api_key'] ) ? self::decrypt_value( $connection['api_key'] ) : '',
			);

			return WP_MCP_AI_Mesh_Peer_Tester::test_connection( $peer );
		}

		// Fallback if tester not available (shouldn't happen).
		return new WP_Error(
			'wp_mcp_ai_pro_tester_unavailable',
			__( 'Mesh peer tester not available. Please ensure the base plugin is up to date.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Make an authenticated HTTP request to a remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $connection Connection data.
	 * @param string $endpoint   API endpoint (relative to REST base).
	 * @param string $method     HTTP method (GET, POST, etc.).
	 * @param array  $body       Request body for POST/PUT requests.
	 * @return array|WP_Error Response data or error.
	 */
	public static function make_request( $connection, $endpoint, $method = 'GET', $body = array() ) {
		$connection_id = isset( $connection['id'] ) ? $connection['id'] : '';
		$start_time    = microtime( true );

		$url = self::build_api_url( $connection['url'], $endpoint, $connection );

		if ( is_wp_error( $url ) ) {
			self::record_health_metric( $connection_id, false, 0 );
			return $url;
		}

		// For WooCommerce authentication, add consumer key/secret to URL.
		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';
		if ( 'woocommerce' === $auth_type ) {
			$consumer_key    = isset( $connection['consumer_key'] ) ? self::decrypt_value( $connection['consumer_key'] ) : '';
			$consumer_secret = isset( $connection['consumer_secret'] ) ? self::decrypt_value( $connection['consumer_secret'] ) : '';

			if ( ! empty( $consumer_key ) && ! empty( $consumer_secret ) ) {
				$url = add_query_arg(
					array(
						'consumer_key'    => $consumer_key,
						'consumer_secret' => $consumer_secret,
					),
					$url
				);
			}
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => self::get_auth_headers( $connection ),
		);

		// Add compression support for large responses.
		$args['headers']['Accept-Encoding'] = 'gzip, deflate';

		if ( ! empty( $body ) && in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		// Check cache for GET requests only (read-only operations).
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key     = self::get_request_cache_key( $connection_id, $endpoint );
			$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached_result && is_array( $cached_result ) ) {
				return $cached_result;
			}
		}

		// Request deduplication - check if this exact request is already in progress.
		$dedup_key   = self::get_dedup_key( $connection_id, $endpoint, $method, $body );
		$in_progress = get_transient( $dedup_key );

		if ( false !== $in_progress ) {
			// Another request is in progress - wait briefly and check cache.
			usleep( 100000 ); // Wait 0.1 seconds.
			if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				$cache_key     = self::get_request_cache_key( $connection_id, $endpoint );
				$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );
				if ( false !== $cached_result && is_array( $cached_result ) ) {
					return $cached_result;
				}
			}
			// If no cached result yet, proceed with request (acceptable race condition).
		}

		// Mark this request as in progress.
		set_transient( $dedup_key, true, 30 );

		// Perform request with retry logic.
		$response = self::make_request_with_retry( $url, $args );

		// Clear deduplication lock.
		delete_transient( $dedup_key );

		if ( is_wp_error( $response ) ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			return new WP_Error(
				'wp_mcp_ai_pro_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP error %d', 'mcp-ai-wpoos-pro' ),
				$status_code
			);

			$decoded = json_decode( $body, true );

			if ( isset( $decoded['message'] ) ) {
				$error_message .= ': ' . $decoded['message'];
			}

			return new WP_Error( 'wp_mcp_ai_pro_http_error', $error_message );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			return new WP_Error(
				'wp_mcp_ai_pro_json_error',
				__( 'Invalid JSON response from remote site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Record successful request for health monitoring.
		$duration = microtime( true ) - $start_time;
		self::record_health_metric( $connection_id, true, $duration );

		// Cache successful GET requests.
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			// Use per-connection cache TTL if set, otherwise default to 5 minutes.
			$cache_ttl = isset( $connection['cache_ttl'] ) ? absint( $connection['cache_ttl'] ) : 5 * MINUTE_IN_SECONDS;

			// Validate cache_ttl is within acceptable range (0-3600 seconds).
			if ( $cache_ttl > 3600 ) {
				$cache_ttl = 3600; // Cap at 1 hour.
			}

			// Skip caching if TTL is 0 (disabled for this connection).
			if ( $cache_ttl > 0 ) {
				/**
				 * Filter the cache TTL for remote site requests.
				 *
				 * @param int    $cache_ttl     Cache time-to-live in seconds (default: connection setting or 300).
				 * @param string $connection_id Connection ID.
				 * @param string $endpoint      API endpoint.
				 * @param array  $connection    Full connection data.
				 */
				$cache_ttl = apply_filters( 'wp_mcp_ai_pro_remote_request_cache_ttl', $cache_ttl, $connection_id, $endpoint, $connection );

				$cache_key = self::get_request_cache_key( $connection_id, $endpoint );
				WP_MCP_AI_Cache_Helper::set( $cache_key, $decoded, $cache_ttl );
			}
		}

		return $decoded;
	}

	/**
	 * Build full API URL from base URL and endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base_url  Base site URL.
	 * @param string $endpoint  API endpoint.
	 * @param array  $connection Optional connection data for context.
	 * @return string|WP_Error Full URL or error.
	 */
	protected static function build_api_url( $base_url, $endpoint, $connection = array() ) {
		$base_url = untrailingslashit( $base_url );
		$endpoint = ltrim( $endpoint, '/' );

		// For generic REST APIs, just append the endpoint directly.
		if ( ! empty( $connection['connection_type'] ) && 'generic' === $connection['connection_type'] ) {
			$api_url = $base_url . '/' . $endpoint;
			return $api_url;
		}

		// For WordPress/WooCommerce endpoints, use /wp-json/ prefix.
		// Determine if this is a WooCommerce endpoint.
		if ( 0 === strpos( $endpoint, 'wc/' ) ) {
			$api_url = $base_url . '/wp-json/' . $endpoint;
		} else {
			$api_url = $base_url . '/wp-json/' . $endpoint;
		}

		return $api_url;
	}

	/**
	 * Get authentication headers for a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array Headers array.
	 */
	protected static function get_auth_headers( $connection ) {
		$headers = array(
			'User-Agent' => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
		);

		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';

		switch ( $auth_type ) {
			case 'application_password':
			case 'basic_auth':
				$username = isset( $connection['username'] ) ? $connection['username'] : '';
				$password = isset( $connection['password'] ) ? self::decrypt_value( $connection['password'] ) : '';

				if ( ! empty( $username ) && ! empty( $password ) ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
				}
				break;

			case 'jwt':
				$token = isset( $connection['token'] ) ? self::decrypt_value( $connection['token'] ) : '';

				if ( ! empty( $token ) ) {
					$headers['Authorization'] = 'Bearer ' . $token;
				}
				break;
		}

		return $headers;
	}

	/**
	 * Validate connection data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return true|WP_Error True if valid, WP_Error if invalid.
	 */
	protected static function validate_connection_data( $connection ) {
		if ( empty( $connection['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_name',
				__( 'Connection name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_url',
				__( 'Connection URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! filter_var( $connection['url'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_url',
				__( 'Connection URL is not valid.', 'mcp-ai-wpoos-pro' )
			);
		}

		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';

		if ( ! in_array( $auth_type, self::AUTH_TYPES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_auth',
				__( 'Invalid authentication type.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( in_array( $auth_type, array( 'application_password', 'basic_auth' ), true ) ) {
			if ( empty( $connection['username'] ) || empty( $connection['password'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_credentials',
					__( 'Username and password are required for this authentication type.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'jwt' === $auth_type && empty( $connection['token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_token',
				__( 'JWT token is required for JWT authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'woocommerce' === $auth_type ) {
			if ( empty( $connection['consumer_key'] ) || empty( $connection['consumer_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_wc_keys',
					__( 'Consumer key and consumer secret are required for WooCommerce authentication.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Validate connection type specific requirements.
		$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';

		if ( 'ezuite_erp' === $connection_type ) {
			if ( empty( $connection['api_key'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_ezuite_credentials',
					__( 'API key is required for EZuite ERP connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'isams' === $connection_type ) {
			if ( empty( $connection['api_key'] ) || empty( $connection['api_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_isams_credentials',
					__( 'API key and API secret are required for iSAMS connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'flowhub' === $connection_type ) {
			if ( empty( $connection['api_key'] ) || empty( $connection['client_id'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_flowhub_credentials',
					__( 'API key (key header) and client ID (clientId header) are required for Flowhub connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'payhere' === $connection_type ) {
			if ( empty( $connection['app_id'] ) || empty( $connection['app_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_payhere_credentials',
					__( 'App ID and app secret are required for PayHere connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'quickbooks' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_quickbooks_credentials',
					__( 'Client ID and client secret are required for QuickBooks connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'gmail' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_gmail_credentials',
					__( 'OAuth Client ID and client secret are required for Gmail connections.', 'mcp-ai-wpoos-pro' )
				);
			}
			// Note: refresh_token is optional during initial setup as it's obtained through OAuth flow
		}

		if ( 'google_drive' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_google_drive_credentials',
					__( 'OAuth Client ID and client secret are required for Google Drive connections.', 'mcp-ai-wpoos-pro' )
				);
			}
			// Note: refresh_token is optional during initial setup as it's obtained through OAuth flow
			// Note: folder_id is optional - if not provided, full drive access within granted scopes
		}

		return true;
	}

	/**
	 * Generate a unique connection ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string Connection ID.
	 */
	protected static function generate_connection_id() {
		return 'conn_' . strtolower( wp_generate_password( 12, false ) );
	}

	/**
	 * Migrate connection IDs to lowercase format.
	 *
	 * This method normalizes existing connection IDs that may have mixed case
	 * to lowercase format for consistency with sanitize_key().
	 *
	 * @since 1.0.0
	 *
	 * @param array $connections Array of connections.
	 * @return array Migrated connections array.
	 */
	protected static function migrate_connection_ids( $connections ) {
		$needs_migration = false;
		$migrated        = array();

		foreach ( $connections as $key => $connection ) {
			$lowercase_key = strtolower( $key );

			// Check if key needs migration.
			if ( $key !== $lowercase_key ) {
				$needs_migration = true;
				// Update the id field to match the new lowercase key.
				$connection['id']           = $lowercase_key;
				$migrated[ $lowercase_key ] = $connection;
			} else {
				$migrated[ $key ] = $connection;
			}
		}

		// Save migrated data if changes were made.
		if ( $needs_migration ) {
			update_option( self::OPTION_NAME, $migrated );
		}

		return $migrated;
	}

	/**
	 * Encrypt a sensitive value for storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to encrypt.
	 * @return string Encrypted value.
	 */
	public static function encrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Use WordPress auth salt for encryption key.
		$key = wp_salt( 'auth' );

		// Simple XOR encryption (WordPress doesn't have built-in encryption).
		$encrypted    = '';
		$key_length   = strlen( $key );
		$value_length = strlen( $value );

		for ( $i = 0; $i < $value_length; $i++ ) {
			$encrypted .= chr( ord( $value[ $i ] ) ^ ord( $key[ $i % $key_length ] ) );
		}

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt a sensitive value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $encrypted Encrypted value.
	 * @return string Decrypted value.
	 */
	public static function decrypt_value( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$key       = wp_salt( 'auth' );
		$encrypted = base64_decode( $encrypted );

		$decrypted        = '';
		$key_length       = strlen( $key );
		$encrypted_length = strlen( $encrypted );

		for ( $i = 0; $i < $encrypted_length; $i++ ) {
			$decrypted .= chr( ord( $encrypted[ $i ] ) ^ ord( $key[ $i % $key_length ] ) );
		}

		return $decrypted;
	}

	/**
	 * Generate cache key for remote site requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $endpoint      API endpoint with query parameters.
	 * @return string Cache key.
	 */
	protected static function get_request_cache_key( $connection_id, $endpoint ) {
		return 'remote_request_' . wp_hash( $connection_id . '_' . $endpoint );
	}

	/**
	 * Invalidate cache for a specific connection.
	 *
	 * Useful when connection settings change or when fresh data is needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return int Number of cache entries cleared.
	 */
	public static function invalidate_connection_cache( $connection_id ) {
		// Use wp_hash for consistent hashing with cache key generation.
		$hash_prefix = wp_hash( $connection_id . '_' );
		return WP_MCP_AI_Cache_Helper::delete_pattern( 'remote_request_' . substr( $hash_prefix, 0, 8 ) . '%' );
	}

	/**
	 * Make HTTP request with retry logic and exponential backoff.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error HTTP response or error.
	 */
	protected static function make_request_with_retry( $url, $args ) {
		$max_retries = 3;
		$retry_delay = 1; // Start with 1 second.

		/**
		 * Filter the maximum number of retry attempts for remote requests.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_retries Maximum retry attempts (default: 3).
		 */
		$max_retries = apply_filters( 'wp_mcp_ai_pro_remote_request_max_retries', $max_retries );

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			// Success - return response.
			if ( ! is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );
				// Retry on 5xx errors (server errors) but not 4xx (client errors).
				if ( $status_code < 500 ) {
					return $response;
				}
			}

			// If this was the last attempt, return the error.
			if ( $attempt >= $max_retries ) {
				return $response;
			}

			// Wait before retrying (exponential backoff with shorter delays).
			// Use microseconds for non-blocking behavior in web context.
			usleep( $retry_delay * 100000 ); // 0.1s, 0.2s, 0.4s
			$retry_delay *= 2; // Double the delay for next retry.
		}

		return $response;
	}

	/**
	 * Generate deduplication key for requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $endpoint      API endpoint.
	 * @param string $method        HTTP method.
	 * @param array  $body          Request body.
	 * @return string Deduplication key.
	 */
	protected static function get_dedup_key( $connection_id, $endpoint, $method, $body ) {
		$key_parts = array( $connection_id, $endpoint, $method );
		if ( ! empty( $body ) ) {
			$key_parts[] = wp_json_encode( $body );
		}
		return 'remote_dedup_' . wp_hash( implode( '|', $key_parts ) );
	}

	/**
	 * Record health metric for connection monitoring.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param bool   $success       Whether request was successful.
	 * @param float  $duration      Request duration in seconds.
	 * @return void
	 */
	protected static function record_health_metric( $connection_id, $success, $duration ) {
		if ( empty( $connection_id ) ) {
			return;
		}

		$health_key  = 'remote_health_' . sanitize_key( $connection_id );
		$health_data = get_transient( $health_key );

		if ( false === $health_data ) {
			$health_data = array(
				'success_count'  => 0,
				'failure_count'  => 0,
				'total_duration' => 0,
				'request_count'  => 0,
				'last_success'   => 0,
				'last_failure'   => 0,
			);
		}

		++$health_data['request_count'];
		$health_data['total_duration'] += $duration;

		if ( $success ) {
			++$health_data['success_count'];
			$health_data['last_success'] = time();
		} else {
			++$health_data['failure_count'];
			$health_data['last_failure'] = time();
		}

		// Store for 1 hour.
		set_transient( $health_key, $health_data, HOUR_IN_SECONDS );
	}

	/**
	 * Get health metrics for a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array Health metrics.
	 */
	public static function get_health_metrics( $connection_id ) {
		$health_key  = 'remote_health_' . sanitize_key( $connection_id );
		$health_data = get_transient( $health_key );

		if ( false === $health_data ) {
			return array(
				'success_count' => 0,
				'failure_count' => 0,
				'success_rate'  => 100,
				'avg_duration'  => 0,
				'request_count' => 0,
				'last_success'  => null,
				'last_failure'  => null,
				'status'        => 'unknown',
			);
		}

		$total_requests = $health_data['request_count'];
		$success_rate   = $total_requests > 0 ? ( $health_data['success_count'] / $total_requests ) * 100 : 100;
		$avg_duration   = $total_requests > 0 ? $health_data['total_duration'] / $total_requests : 0;

		// Determine status.
		$status = 'healthy';
		if ( $success_rate < 50 ) {
			$status = 'unhealthy';
		} elseif ( $success_rate < 80 ) {
			$status = 'degraded';
		}

		return array(
			'success_count' => $health_data['success_count'],
			'failure_count' => $health_data['failure_count'],
			'success_rate'  => round( $success_rate, 2 ),
			'avg_duration'  => round( $avg_duration, 3 ),
			'request_count' => $total_requests,
			'last_success'  => $health_data['last_success'] > 0 ? $health_data['last_success'] : null,
			'last_failure'  => $health_data['last_failure'] > 0 ? $health_data['last_failure'] : null,
			'status'        => $status,
		);
	}
}
