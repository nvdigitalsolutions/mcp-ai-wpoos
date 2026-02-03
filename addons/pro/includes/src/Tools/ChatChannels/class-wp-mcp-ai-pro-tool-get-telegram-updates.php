<?php
/**
 * Tool that retrieves Telegram bot updates.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving Telegram bot updates via the Bot API.
 */
class WP_MCP_AI_Pro_Tool_Get_Telegram_Updates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Telegram requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_telegram_updates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Telegram Updates', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves incoming updates from a Telegram bot using the Bot API getUpdates method.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'  => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used to authenticate the request.', 'mcp-ai-wpoos-pro' ),
				),
				'offset' => array(
					'type'        => 'integer',
					'description' => __( 'Identifier of the first update to be returned. Use to acknowledge processed updates.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of updates to retrieve (1-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 100,
				),
			),
			'required'             => array( 'token' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_telegram_updates_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Telegram updates.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;

		// Enforce limits.
		if ( $limit < 1 ) {
			$limit = 1;
		} elseif ( $limit > 100 ) {
			$limit = 100;
		}

		$query_params = array(
			'offset' => $offset,
			'limit'  => $limit,
		);

		$endpoint = sprintf(
			'https://api.telegram.org/bot%s/getUpdates?%s',
			rawurlencode( $token ),
			http_build_query( $query_params )
		);

		WP_MCP_AI_Logger::log_event(
			'telegram_get_updates_request',
			'Retrieving Telegram bot updates.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/getUpdates',
				'offset'   => $offset,
				'limit'    => $limit,
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_get_telegram_updates_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram getUpdates request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['ok'] ) ) {
			$message = isset( $decoded['description'] ) ? $decoded['description'] : __( 'Telegram API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Telegram getUpdates request was not successful.',
				array(
					'http_code'   => $code,
					'api_message' => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_telegram_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize a Telegram bot token.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function sanitize_token( $token ) {
		if ( ! is_string( $token ) && ! is_numeric( $token ) ) {
			return '';
		}

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return '';
		}

		return $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data.
			'external-api',         // Calls Telegram Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
