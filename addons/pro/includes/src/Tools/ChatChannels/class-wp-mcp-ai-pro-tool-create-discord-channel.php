<?php
/**
 * Tool that creates a Discord channel.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating Discord channels via the Discord Bot API.
 */
class WP_MCP_AI_Pro_Tool_Create_Discord_Channel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Discord requests.
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
		return 'create_discord_channel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Discord Channel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new channel in a Discord server (guild) using the Discord Bot API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'    => array(
					'type'        => 'string',
					'description' => __( 'Discord bot token used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'guild_id' => array(
					'type'        => 'string',
					'description' => __( 'Discord server (guild) ID where the channel will be created.', 'mcp-ai-wpoos-pro' ),
				),
				'name'     => array(
					'type'        => 'string',
					'description' => __( 'Name of the channel to create (2-100 characters, lowercase with dashes).', 'mcp-ai-wpoos-pro' ),
				),
				'type'     => array(
					'type'        => 'integer',
					'description' => __( 'Type of channel (0 = text, 2 = voice, 4 = category, 5 = announcement, default: 0).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'enum'        => array( 0, 2, 4, 5 ),
				),
			),
			'required'             => array( 'token', 'guild_id', 'name' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_create_discord_channel_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create Discord channels.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_discord_token', __( 'A valid Discord bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$guild_id = isset( $arguments['guild_id'] ) ? sanitize_text_field( $arguments['guild_id'] ) : '';

		if ( '' === $guild_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_guild_id', __( 'A guild ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$name = isset( $arguments['name'] ) ? $this->sanitize_channel_name( $arguments['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_channel_name', __( 'A channel name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$type = isset( $arguments['type'] ) ? absint( $arguments['type'] ) : 0;

		if ( ! in_array( $type, array( 0, 2, 4, 5 ), true ) ) {
			$type = 0;
		}

		$endpoint = 'https://discord.com/api/v10/guilds/' . $guild_id . '/channels';

		$payload = array(
			'name' => $name,
			'type' => $type,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Discord request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'discord_create_channel_request',
			'Creating Discord channel.',
			array(
				'endpoint' => $endpoint,
				'guild_id' => $guild_id,
				'name'     => $name,
				'type'     => $type,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bot ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_create_discord_channel_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Discord create channel request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_discord_http_error',
				__( 'The Discord API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 201 !== $code ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Discord API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Discord create channel request was not successful.',
				array(
					'http_code' => $code,
					'guild_id'  => $guild_id,
					'name'      => $name,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_discord_api_error',
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
	 * Sanitize a Discord bot token.
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
	 * Sanitize Discord channel name.
	 *
	 * @param string $name Raw channel name.
	 * @return string
	 */
	protected function sanitize_channel_name( $name ) {
		if ( ! is_string( $name ) ) {
			return '';
		}

		$name = trim( $name );

		if ( '' === $name ) {
			return '';
		}

		return $name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates Discord channels.
			'external-api',         // Calls Discord Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
