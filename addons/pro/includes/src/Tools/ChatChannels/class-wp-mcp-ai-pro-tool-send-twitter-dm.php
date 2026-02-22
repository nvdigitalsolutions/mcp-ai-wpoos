<?php
/**
 * Tool that sends a Twitter/X Direct Message.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending Twitter/X Direct Messages via API v2 (OAuth 1.0a).
 *
 * Uses POST /2/dm_conversations/with/:participant_id/messages which requires
 * OAuth 1.0a user context — Bearer-token-only auth cannot send DMs.
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/direct-messages/manage/api-reference/post-dm_conversations-with-participant_id-messages
 */
class WP_MCP_AI_Pro_Tool_Send_Twitter_DM implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Twitter API requests.
	 */
	const DEFAULT_TIMEOUT = 15;

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
		return 'send_twitter_dm';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Twitter/X DM', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a Direct Message to a Twitter/X user via API v2 using OAuth 1.0a authentication.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'recipient_id'        => array(
					'type'        => 'string',
					'description' => __( 'Twitter/X user ID of the DM recipient.', 'mcp-ai-wpoos-pro' ),
				),
				'text'                => array(
					'type'        => 'string',
					'description' => __( 'Text content of the Direct Message (max 10,000 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'             => array(
					'type'        => 'string',
					'description' => __( 'Twitter API Key / Consumer Key for OAuth 1.0a authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'api_secret'          => array(
					'type'        => 'string',
					'description' => __( 'Twitter API Secret Key / Consumer Secret for OAuth 1.0a authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'access_token'        => array(
					'type'        => 'string',
					'description' => __( 'OAuth 1.0a Access Token for the authenticating user.', 'mcp-ai-wpoos-pro' ),
				),
				'access_token_secret' => array(
					'type'        => 'string',
					'description' => __( 'OAuth 1.0a Access Token Secret for the authenticating user.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'recipient_id', 'text', 'api_key', 'api_secret', 'access_token', 'access_token_secret' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_twitter_dm_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Twitter DMs.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$recipient_id        = isset( $arguments['recipient_id'] ) ? sanitize_text_field( $arguments['recipient_id'] ) : '';
		$text                = isset( $arguments['text'] ) ? $this->sanitize_dm_text( $arguments['text'] ) : '';
		$api_key             = isset( $arguments['api_key'] ) ? $this->sanitize_credential( $arguments['api_key'] ) : '';
		$api_secret          = isset( $arguments['api_secret'] ) ? $this->sanitize_credential( $arguments['api_secret'] ) : '';
		$access_token        = isset( $arguments['access_token'] ) ? $this->sanitize_credential( $arguments['access_token'] ) : '';
		$access_token_secret = isset( $arguments['access_token_secret'] ) ? $this->sanitize_credential( $arguments['access_token_secret'] ) : '';

		if ( '' === $recipient_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_recipient', __( 'A recipient user ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_text', __( 'DM text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $api_key || '' === $api_secret || '' === $access_token || '' === $access_token_secret ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'All four OAuth 1.0a credentials (api_key, api_secret, access_token, access_token_secret) are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://api.twitter.com/2/dm_conversations/with/' . rawurlencode( $recipient_id ) . '/messages';

		$payload = array( 'text' => $text );
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Twitter DM request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$oauth_header = $this->build_oauth1_header( 'POST', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		WP_MCP_AI_Logger::log_event(
			'twitter_send_dm_request',
			'Sending Twitter DM.',
			array(
				'endpoint'         => 'https://api.twitter.com/2/dm_conversations/with/***/messages',
				'recipient_prefix' => substr( $recipient_id, 0, 4 ) . '***',
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => $oauth_header,
					'Content-Type'  => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_twitter_dm_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Twitter send DM request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_twitter_http_error',
				__( 'The Twitter API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $response_body, true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_detail = is_array( $decoded ) && isset( $decoded['detail'] ) ? $decoded['detail'] : $response_body;

			WP_MCP_AI_Logger::log_error(
				'Twitter send DM request returned an error.',
				array(
					'http_code'    => $http_code,
					'error_detail' => $error_detail,
				)
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

		return is_array( $decoded ) ? $decoded : array( 'raw' => $response_body );
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
	 * Sanitize DM text.
	 *
	 * @param string $text Raw text input.
	 * @return string
	 */
	protected function sanitize_dm_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim( $text );
	}

	/**
	 * Sanitize an API credential (token/key) without altering its characters.
	 *
	 * @param mixed $value Raw credential value.
	 * @return string
	 */
	protected function sanitize_credential( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'write',               // Sends Twitter DMs.
			'external-api',        // Calls Twitter API v2.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
