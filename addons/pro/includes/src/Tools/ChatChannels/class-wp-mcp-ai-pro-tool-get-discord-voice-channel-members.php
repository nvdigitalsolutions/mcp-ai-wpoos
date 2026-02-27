<?php
/**
 * Tool that retrieves the members currently in a Discord voice channel.
 *
 * Supports OpenClaw's 2026.2.21 voice-channel feature set by providing
 * visibility into which users (and bots) are present in a given voice channel,
 * enabling agents to make context-aware decisions before joining or
 * announcing in voice channels.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving voice channel members via the Discord Bot API.
 *
 * Fetches the channel object for a voice channel and extracts the embedded
 * voice_states array, which lists every user currently in that channel along
 * with their mute/deaf status, stream state, and video state.
 */
class WP_MCP_AI_Pro_Tool_Get_Discord_Voice_Channel_Members implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Discord API requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Discord voice channel type constant.
	 */
	const DISCORD_CHANNEL_TYPE_VOICE = 2;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true – no additional dependencies required.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_discord_voice_channel_members';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Discord Voice Channel Members', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the list of users currently present in a Discord voice channel, including their mute, deaf, stream, and video states. Requires the bot to have VIEW_CHANNEL permission on the target channel.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'      => array(
					'type'        => 'string',
					'description' => __( 'Discord bot token used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'channel_id' => array(
					'type'        => 'string',
					'description' => __( 'ID of the Discord voice channel to inspect.', 'mcp-ai-wpoos-pro' ),
				),
				'guild_id'   => array(
					'type'        => 'string',
					'description' => __( 'Optional guild (server) ID for additional context. When provided, guild voice-states are used to cross-reference member display names.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'token', 'channel_id' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_discord_voice_channel_members_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Discord voice channel members.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_discord_token', __( 'A valid Discord bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$channel_id = isset( $arguments['channel_id'] ) ? sanitize_text_field( $arguments['channel_id'] ) : '';

		if ( '' === $channel_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_channel_id', __( 'A channel ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$guild_id = isset( $arguments['guild_id'] ) ? sanitize_text_field( $arguments['guild_id'] ) : '';

		// GET /channels/{channel.id} – returns the full channel object including voice_states.
		$endpoint = 'https://discord.com/api/v10/channels/' . $channel_id;

		WP_MCP_AI_Logger::log_event(
			'discord_get_voice_channel_members',
			'Retrieving Discord voice channel members.',
			array(
				'channel_id' => $channel_id,
				'guild_id'   => $guild_id,
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_discord_voice_channel_members_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Discord get channel request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_discord_http_error',
				__( 'The Discord API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$channel = json_decode( $body, true );

		if ( null === $channel ) {
			$channel = array();
		}

		if ( 200 !== $code ) {
			$message = isset( $channel['message'] ) ? $channel['message'] : __( 'Discord API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Discord get channel request was not successful.',
				array(
					'http_code'  => $code,
					'channel_id' => $channel_id,
					'error'      => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_discord_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $channel,
				)
			);
		}

		// Verify the channel is actually a voice channel.
		$channel_type = isset( $channel['type'] ) ? (int) $channel['type'] : -1;

		if ( self::DISCORD_CHANNEL_TYPE_VOICE !== $channel_type ) {
			return new WP_Error(
				'wp_mcp_ai_not_voice_channel',
				__( 'The specified channel is not a voice channel.', 'mcp-ai-wpoos-pro' ),
				array( 'channel_type' => $channel_type )
			);
		}

		$voice_states = isset( $channel['voice_states'] ) && is_array( $channel['voice_states'] ) ? $channel['voice_states'] : array();

		$members = array();

		foreach ( $voice_states as $state ) {
			$member_info = array(
				'user_id'  => isset( $state['user_id'] ) ? sanitize_text_field( $state['user_id'] ) : '',
				'mute'     => ! empty( $state['mute'] ),
				'deaf'     => ! empty( $state['deaf'] ),
				'self_mute' => ! empty( $state['self_mute'] ),
				'self_deaf' => ! empty( $state['self_deaf'] ),
				'self_stream' => ! empty( $state['self_stream'] ),
				'self_video' => ! empty( $state['self_video'] ),
				'suppress'  => ! empty( $state['suppress'] ),
			);

			// Include member display name when available.
			if ( isset( $state['member']['user']['username'] ) ) {
				$member_info['username'] = sanitize_text_field( $state['member']['user']['username'] );
			}

			if ( isset( $state['member']['nick'] ) ) {
				$member_info['nickname'] = sanitize_text_field( $state['member']['nick'] );
			}

			$members[] = $member_info;
		}

		return array(
			'channel_id'   => $channel_id,
			'channel_name' => isset( $channel['name'] ) ? sanitize_text_field( $channel['name'] ) : '',
			'member_count' => count( $members ),
			'members'      => $members,
		);
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'read-only',           // Reads voice channel presence only.
			'external-api',        // Calls Discord Bot API.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
