<?php
/**
 * Telegram Mini App (WebApp) Authentication Handler.
 *
 * Validates Telegram Mini App initData using HMAC-SHA256 verification
 * as described in https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 *
 * When a chat page is opened inside a Telegram Mini App, this handler validates
 * the initData provided by the Telegram WebApp SDK and issues a guest token,
 * allowing the user to authenticate inline without being redirected to a browser.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Telegram Mini App WebApp authentication.
 */
class WP_MCP_AI_Telegram_WebApp_Auth {

	/**
	 * Validate Telegram Mini App initData.
	 *
	 * The initData is a query string containing user data signed by Telegram.
	 * Validation follows the official Telegram documentation:
	 * 1. Parse the query string into key-value pairs.
	 * 2. Remove the 'hash' parameter.
	 * 3. Sort remaining pairs alphabetically by key.
	 * 4. Join with newlines as "key=value".
	 * 5. Create HMAC-SHA256 of this string using a secret key.
	 * 6. The secret key is HMAC-SHA256 of the bot token using "WebAppData" as key.
	 * 7. Compare the computed hash with the provided hash.
	 *
	 * @param string $init_data The raw initData query string from Telegram.WebApp.initData.
	 * @param string $bot_token The bot token from BotFather.
	 * @return array|WP_Error Parsed user data on success, WP_Error on failure.
	 */
	public static function validate_init_data( $init_data, $bot_token ) {
		if ( empty( $init_data ) || empty( $bot_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_missing_data',
				__( 'Missing initData or bot token.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Parse the initData query string.
		$params = array();
		parse_str( $init_data, $params );

		if ( empty( $params['hash'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_missing_hash',
				__( 'Missing hash in Telegram initData.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$provided_hash = $params['hash'];
		unset( $params['hash'] );

		// Sort parameters alphabetically by key.
		ksort( $params );

		// Build the data-check-string.
		$data_check_parts = array();
		foreach ( $params as $key => $value ) {
			$data_check_parts[] = $key . '=' . $value;
		}
		$data_check_string = implode( "\n", $data_check_parts );

		// Compute the secret key: HMAC-SHA256 of bot_token using "WebAppData" as key.
		$secret_key = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );

		// Compute the hash: HMAC-SHA256 of data_check_string using secret_key.
		$computed_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

		// Compare using timing-safe comparison.
		if ( ! hash_equals( $computed_hash, $provided_hash ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_invalid_hash',
				__( 'Invalid Telegram initData signature.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Validate auth_date is not too old (allow up to 24 hours).
		if ( ! empty( $params['auth_date'] ) ) {
			$auth_date = absint( $params['auth_date'] );
			$max_age   = DAY_IN_SECONDS;

			/**
			 * Filter the maximum age (in seconds) for Telegram initData auth_date.
			 *
			 * @param int $max_age Maximum allowed age in seconds. Default DAY_IN_SECONDS (86400).
			 */
			$max_age = apply_filters( 'wp_mcp_ai_telegram_auth_max_age', $max_age );

			if ( ( time() - $auth_date ) > $max_age ) {
				return new WP_Error(
					'wp_mcp_ai_telegram_expired',
					__( 'Telegram authentication data has expired.', 'mcp-ai-wpoos' ),
					array( 'status' => 403 )
				);
			}
		}

		// Parse the user object from initData.
		$user_data = array();
		if ( ! empty( $params['user'] ) ) {
			$user_data = json_decode( $params['user'], true );
			if ( ! is_array( $user_data ) ) {
				$user_data = array();
			}
		}

		return array(
			'valid'     => true,
			'user'      => $user_data,
			'auth_date' => isset( $params['auth_date'] ) ? absint( $params['auth_date'] ) : 0,
			'query_id'  => isset( $params['query_id'] ) ? sanitize_text_field( $params['query_id'] ) : '',
		);
	}

	/**
	 * Find the bot token for a Telegram connection.
	 *
	 * Searches active Telegram connections in the Remote Site Manager
	 * for a valid bot token.
	 *
	 * @return string Bot token or empty string if not found.
	 */
	public static function get_bot_token() {
		// Try from Remote Site Manager connections (Pro addon).
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $connections ) ) {
				foreach ( $connections as $connection ) {
					if ( isset( $connection['connection_type'] ) && 'telegram' === $connection['connection_type'] && ! empty( $connection['enabled'] ) ) {
						$api_key = isset( $connection['api_key'] ) ? $connection['api_key'] : '';
						if ( ! empty( $api_key ) ) {
							// Decrypt if encrypted.
							if ( method_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager', 'decrypt_value' ) ) {
								$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $api_key );
								if ( ! empty( $decrypted ) ) {
									return $decrypted;
								}
							}
							return $api_key;
						}
					}
				}
			}
		}

		// Try from Chat Channels settings (alternative storage).
		$settings = get_option( 'wp_mcp_ai_chat_channels_toolkit_settings', array() );
		if ( ! empty( $settings['telegram_bot_token'] ) ) {
			return $settings['telegram_bot_token'];
		}

		return '';
	}
}
