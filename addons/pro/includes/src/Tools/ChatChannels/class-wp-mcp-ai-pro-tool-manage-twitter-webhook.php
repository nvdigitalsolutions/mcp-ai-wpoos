<?php
/**
 * Tool that manages Twitter/X Account Activity API webhooks.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for registering and managing Twitter/X Account Activity API webhooks.
 *
 * Supports registering a webhook URL (POST /2/webhooks) and subscribing the
 * authenticated user to receive account activity events (POST /2/webhooks/:id/subscriptions/all).
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/enterprise/account-activity-api/api-reference
 */
class WP_MCP_AI_Pro_Tool_Manage_Twitter_Webhook implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Twitter API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true — no external plugin dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_twitter_webhook';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Twitter/X Webhook', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Registers or removes a Twitter/X Account Activity API webhook URL and manages event subscriptions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'              => array(
					'type'        => 'string',
					'enum'        => array( 'register', 'delete', 'subscribe', 'list' ),
					'description' => __( '"register" to add a webhook URL, "delete" to remove one, "subscribe" to subscribe the user to account events, "list" to view all registered webhooks.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'register',
				),
				'url'                 => array(
					'type'        => 'string',
					'description' => __( 'HTTPS webhook URL to register. Required when action is "register".', 'mcp-ai-wpoos-pro' ),
				),
				'webhook_id'          => array(
					'type'        => 'string',
					'description' => __( 'Webhook ID returned after registration. Required when action is "delete" or "subscribe".', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'             => array(
					'type'        => 'string',
					'description' => __( 'Twitter API Key / Consumer Key (OAuth 1.0a).', 'mcp-ai-wpoos-pro' ),
				),
				'api_secret'          => array(
					'type'        => 'string',
					'description' => __( 'Twitter API Secret Key / Consumer Secret (OAuth 1.0a).', 'mcp-ai-wpoos-pro' ),
				),
				'access_token'        => array(
					'type'        => 'string',
					'description' => __( 'OAuth 1.0a Access Token.', 'mcp-ai-wpoos-pro' ),
				),
				'access_token_secret' => array(
					'type'        => 'string',
					'description' => __( 'OAuth 1.0a Access Token Secret.', 'mcp-ai-wpoos-pro' ),
				),
				'env_name'            => array(
					'type'        => 'string',
					'description' => __( 'Account Activity API environment name (e.g. "production"). Required for Enterprise / Premium tiers. Omit for v2 webhooks.', 'mcp-ai-wpoos-pro' ),
					'default'     => '',
				),
			),
			'required'             => array( 'action', 'api_key', 'api_secret', 'access_token', 'access_token_secret' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_manage_twitter_webhook_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Twitter webhooks.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$action              = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'register';
		$api_key             = isset( $arguments['api_key'] ) ? trim( (string) $arguments['api_key'] ) : '';
		$api_secret          = isset( $arguments['api_secret'] ) ? trim( (string) $arguments['api_secret'] ) : '';
		$access_token        = isset( $arguments['access_token'] ) ? trim( (string) $arguments['access_token'] ) : '';
		$access_token_secret = isset( $arguments['access_token_secret'] ) ? trim( (string) $arguments['access_token_secret'] ) : '';
		$env_name            = isset( $arguments['env_name'] ) ? sanitize_text_field( $arguments['env_name'] ) : '';

		if ( ! in_array( $action, array( 'register', 'delete', 'subscribe', 'list' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Action must be "register", "delete", "subscribe", or "list".', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $api_key || '' === $api_secret || '' === $access_token || '' === $access_token_secret ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'All four OAuth 1.0a credentials are required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'register':
				return $this->register_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context );

			case 'delete':
				return $this->delete_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context );

			case 'subscribe':
				return $this->subscribe_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context );

			case 'list':
			default:
				return $this->list_webhooks( $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context );
		}
	}

	/**
	 * Register a webhook URL with the Twitter Account Activity API.
	 *
	 * @param array  $arguments           Full tool arguments.
	 * @param string $api_key             Consumer Key.
	 * @param string $api_secret          Consumer Secret.
	 * @param string $access_token        Access Token.
	 * @param string $access_token_secret Access Token Secret.
	 * @param string $env_name            Environment name (Premium/Enterprise only).
	 * @param array  $context             Execution context.
	 * @return array|WP_Error
	 */
	protected function register_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context ) {
		$url = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'], array( 'https' ) ) : '';

		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_webhook_url', __( 'A valid HTTPS URL is required to register a Twitter webhook.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build the API endpoint. Enterprise uses environment-scoped routes.
		if ( '' !== $env_name ) {
			$endpoint = 'https://api.twitter.com/1.1/account_activity/all/' . rawurlencode( $env_name ) . '/webhooks.json';
		} else {
			$endpoint = 'https://api.twitter.com/2/webhooks';
		}

		$body_params = array( 'url' => $url );
		$body        = wp_json_encode( $body_params );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the webhook registration payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$oauth_header = $this->build_oauth1_header( 'POST', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		WP_MCP_AI_Logger::log_event(
			'twitter_register_webhook_request',
			'Registering Twitter webhook URL.',
			array(
				'endpoint' => $endpoint,
				'url'      => $url,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => $oauth_header,
					'Content-Type'  => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_twitter_webhook_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		return $this->handle_api_response( $response, 'register webhook' );
	}

	/**
	 * Delete a registered Twitter webhook.
	 *
	 * @param array  $arguments           Full tool arguments.
	 * @param string $api_key             Consumer Key.
	 * @param string $api_secret          Consumer Secret.
	 * @param string $access_token        Access Token.
	 * @param string $access_token_secret Access Token Secret.
	 * @param string $env_name            Environment name (Premium/Enterprise only).
	 * @param array  $context             Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context ) {
		$webhook_id = isset( $arguments['webhook_id'] ) ? sanitize_text_field( $arguments['webhook_id'] ) : '';

		if ( '' === $webhook_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_webhook_id', __( 'A webhook_id is required to delete a webhook.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' !== $env_name ) {
			$endpoint = 'https://api.twitter.com/1.1/account_activity/all/' . rawurlencode( $env_name ) . '/webhooks/' . rawurlencode( $webhook_id ) . '.json';
		} else {
			$endpoint = 'https://api.twitter.com/2/webhooks/' . rawurlencode( $webhook_id );
		}

		$oauth_header = $this->build_oauth1_header( 'DELETE', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		WP_MCP_AI_Logger::log_event(
			'twitter_delete_webhook_request',
			'Deleting Twitter webhook.',
			array(
				'endpoint'   => $endpoint,
				'webhook_id' => $webhook_id,
			)
		);

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => $oauth_header,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_twitter_webhook_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		return $this->handle_api_response( $response, 'delete webhook' );
	}

	/**
	 * Subscribe the authenticated user to account activity events on a webhook.
	 *
	 * @param array  $arguments           Full tool arguments.
	 * @param string $api_key             Consumer Key.
	 * @param string $api_secret          Consumer Secret.
	 * @param string $access_token        Access Token.
	 * @param string $access_token_secret Access Token Secret.
	 * @param string $env_name            Environment name (Premium/Enterprise only).
	 * @param array  $context             Execution context.
	 * @return array|WP_Error
	 */
	protected function subscribe_webhook( $arguments, $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context ) {
		$webhook_id = isset( $arguments['webhook_id'] ) ? sanitize_text_field( $arguments['webhook_id'] ) : '';

		if ( '' === $webhook_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_webhook_id', __( 'A webhook_id is required to subscribe.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' !== $env_name ) {
			$endpoint = 'https://api.twitter.com/1.1/account_activity/all/' . rawurlencode( $env_name ) . '/subscriptions.json';
		} else {
			$endpoint = 'https://api.twitter.com/2/webhooks/' . rawurlencode( $webhook_id ) . '/subscriptions/all';
		}

		$oauth_header = $this->build_oauth1_header( 'POST', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		WP_MCP_AI_Logger::log_event(
			'twitter_subscribe_webhook_request',
			'Subscribing to Twitter account activity events.',
			array(
				'endpoint'   => $endpoint,
				'webhook_id' => $webhook_id,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => $oauth_header,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_twitter_webhook_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		return $this->handle_api_response( $response, 'subscribe webhook' );
	}

	/**
	 * List all registered webhooks.
	 *
	 * @param string $api_key             Consumer Key.
	 * @param string $api_secret          Consumer Secret.
	 * @param string $access_token        Access Token.
	 * @param string $access_token_secret Access Token Secret.
	 * @param string $env_name            Environment name (Premium/Enterprise only).
	 * @param array  $context             Execution context.
	 * @return array|WP_Error
	 */
	protected function list_webhooks( $api_key, $api_secret, $access_token, $access_token_secret, $env_name, $context ) {
		if ( '' !== $env_name ) {
			$endpoint = 'https://api.twitter.com/1.1/account_activity/all/' . rawurlencode( $env_name ) . '/webhooks.json';
		} else {
			$endpoint = 'https://api.twitter.com/2/webhooks';
		}

		$oauth_header = $this->build_oauth1_header( 'GET', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		WP_MCP_AI_Logger::log_event(
			'twitter_list_webhooks_request',
			'Listing Twitter webhooks.',
			array( 'endpoint' => $endpoint )
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => $oauth_header,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_twitter_webhook_timeout', self::DEFAULT_TIMEOUT, $context, array() ),
			)
		);

		return $this->handle_api_response( $response, 'list webhooks' );
	}

	/**
	 * Build an OAuth 1.0a Authorization header (HMAC-SHA1).
	 *
	 * @param string $http_method         HTTP method.
	 * @param string $url                 Full request URL.
	 * @param array  $extra_params        Additional params included in signature.
	 * @param string $consumer_key        Consumer Key.
	 * @param string $consumer_secret     Consumer Secret.
	 * @param string $access_token        Access Token.
	 * @param string $access_token_secret Access Token Secret.
	 * @return string OAuth Authorization header value.
	 */
	protected function build_oauth1_header( $http_method, $url, array $extra_params, $consumer_key, $consumer_secret, $access_token, $access_token_secret ) {
		$nonce     = wp_generate_uuid4();
		$timestamp = (string) time();

		$oauth_params = array(
			'oauth_consumer_key'     => $consumer_key,
			'oauth_nonce'            => $nonce,
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => $timestamp,
			'oauth_token'            => $access_token,
			'oauth_version'          => '1.0',
		);

		$all_params = array_merge( $extra_params, $oauth_params );
		ksort( $all_params );

		$encoded_pairs = array();
		foreach ( $all_params as $key => $value ) {
			$encoded_pairs[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}

		$param_string = implode( '&', $encoded_pairs );
		$base_string  = strtoupper( $http_method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );
		$signing_key  = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $access_token_secret );

		$oauth_params['oauth_signature'] = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			hash_hmac( 'sha1', $base_string, $signing_key, true )
		);

		$header_parts = array();
		foreach ( $oauth_params as $key => $value ) {
			$header_parts[] = rawurlencode( $key ) . '="' . rawurlencode( $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}

	/**
	 * Handle a Twitter API HTTP response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $action   Human-readable action name for logging.
	 * @return array|WP_Error
	 */
	protected function handle_api_response( $response, $action ) {
		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				sprintf( 'Twitter %s HTTP request failed.', $action ),
				array( 'error' => $response->get_error_message() )
			);

			return new WP_Error(
				'wp_mcp_ai_twitter_http_error',
				__( 'The Twitter API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $response_body, true );

		// 204 No Content is a successful response for delete/subscribe.
		if ( 204 === $http_code ) {
			return array( 'success' => true, 'http_code' => 204 );
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_detail = is_array( $decoded ) && isset( $decoded['detail'] ) ? $decoded['detail'] : $response_body;

			WP_MCP_AI_Logger::log_error(
				sprintf( 'Twitter %s returned an error.', $action ),
				array( 'http_code' => $http_code, 'error_detail' => $error_detail )
			);

			return new WP_Error(
				'wp_mcp_ai_twitter_api_error',
				esc_html(
					sprintf(
						/* translators: 1: HTTP status code, 2: API error detail */
						__( 'Twitter API returned HTTP %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
						$http_code,
						$error_detail
					)
				),
				array( 'http_code' => $http_code, 'response' => $decoded )
			);
		}

		return is_array( $decoded ) ? $decoded : array( 'raw' => $response_body, 'http_code' => $http_code );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'write',               // Registers/removes webhooks.
			'external-api',        // Calls Twitter Account Activity API.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
