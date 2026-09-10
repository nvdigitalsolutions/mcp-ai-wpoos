<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-create-google-chat-space.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-create-google-chat-space.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the base-owned interface/logger/restrict/cron-manager
 * requires gain exists-check seams resolving from the addon's D8-compat `src/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
// Standalone seam (documented deviation): the base-owned logger require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}
require_once __DIR__ . '/class-wp-mcp-ai-pro-google-service-account.php';

/**
 * Provides a tool for creating Google Chat spaces via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Google Chat requests.
	 */
	const DEFAULT_TIMEOUT = 20;

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
		return 'create_google_chat_space';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Google Chat Space', 'nvoos-content-graph-pro' );
	}

	/**
	 * Google Chat API scope for bot operations.
	 */
	const CHAT_BOT_SCOPE = 'https://www.googleapis.com/auth/chat.bot';

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new Google Chat space using the Google Chat API v1.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'service_account_key' => array(
					'type'        => 'string',
					'description' => __( 'Google Service Account JSON key (contents of the downloaded .json key file). Used to generate an OAuth 2.0 access token automatically.', 'nvoos-content-graph-pro' ),
				),
				'access_token'        => array(
					'type'        => 'string',
					'description' => __( 'OAuth 2.0 access token for authentication. Use service_account_key instead for automatic token management.', 'nvoos-content-graph-pro' ),
				),
				'display_name'        => array(
					'type'        => 'string',
					'description' => __( 'Display name for the new space.', 'nvoos-content-graph-pro' ),
				),
				'space_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of space to create (default: SPACE).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE' ),
					'default'     => 'SPACE',
				),
			),
			'required'             => array( 'display_name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_create_google_chat_space_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create Google Chat spaces.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$access_token = $this->resolve_access_token( $arguments, $context );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token or Service Account JSON key is required.', 'nvoos-content-graph-pro' ) );
		}

		$display_name = isset( $arguments['display_name'] ) ? sanitize_text_field( $arguments['display_name'] ) : '';

		if ( '' === $display_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_display_name', __( 'A display name is required.', 'nvoos-content-graph-pro' ) );
		}

		$space_type    = isset( $arguments['space_type'] ) ? sanitize_text_field( $arguments['space_type'] ) : 'SPACE';
		$allowed_types = array( 'SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE' );

		if ( ! in_array( $space_type, $allowed_types, true ) ) {
			$space_type = 'SPACE';
		}

		$endpoint = 'https://chat.googleapis.com/v1/spaces';

		$payload = array(
			'spaceType'   => $space_type,
			'displayName' => $display_name,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Google Chat request payload.', 'nvoos-content-graph-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_create_space_request',
			'Creating Google Chat space.',
			array(
				'endpoint'     => $endpoint,
				'display_name' => $display_name,
				'space_type'   => $space_type,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_create_google_chat_space_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat create space request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_chat_http_error',
				__( 'The Google Chat API request failed to send.', 'nvoos-content-graph-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code && 201 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Google Chat API returned an error.', 'nvoos-content-graph-pro' );

			WP_MCP_AI_Logger::log_error(
				'Google Chat create space request was not successful.',
				array(
					'http_code'    => $code,
					'display_name' => $display_name,
					'error'        => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_google_chat_api_error',
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
	 * Resolve an OAuth 2.0 access token from arguments.
	 *
	 * Prefers service_account_key (automatic token exchange) over a raw access_token.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return string|WP_Error Access token string or error.
	 */
	protected function resolve_access_token( array $arguments, array $context ) {
		$service_account_key = isset( $arguments['service_account_key'] ) ? trim( (string) $arguments['service_account_key'] ) : '';

		if ( '' !== $service_account_key ) {
			$timeout = (int) apply_filters( 'wp_mcp_ai_create_google_chat_space_token_timeout', 15, $context, $arguments );
			return WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key( $service_account_key, self::CHAT_BOT_SCOPE, $timeout );
		}

		return isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';
	}

	/**
	 * Sanitize an OAuth access token.
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
			'write',                // Creates Google Chat spaces.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
