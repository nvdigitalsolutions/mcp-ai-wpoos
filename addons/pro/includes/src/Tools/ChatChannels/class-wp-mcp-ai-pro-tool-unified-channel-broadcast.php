<?php
/**
 * Tool that broadcasts messages across multiple chat channels simultaneously.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for broadcasting messages to multiple chat platforms simultaneously.
 */
class WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'unified_channel_broadcast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Unified Channel Broadcast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Broadcasts a message across multiple chat channels (Telegram, Slack, Discord, Teams, Messenger, WhatsApp) simultaneously.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Text message to broadcast to all channels.', 'mcp-ai-wpoos-pro' ),
				),
				'channels'    => array(
					'type'        => 'array',
					'description' => __( 'Array of channel names to broadcast to: telegram, slack, discord, teams, messenger, whatsapp.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp' ),
					),
				),
				'credentials' => array(
					'type'        => 'object',
					'description' => __( 'Credentials object containing tokens/keys for each channel.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'telegram' => array(
							'type'       => 'object',
							'properties' => array(
								'token'   => array( 'type' => 'string' ),
								'chat_id' => array( 'type' => 'string' ),
							),
						),
						'slack'     => array(
							'type'       => 'object',
							'properties' => array(
								'token'   => array( 'type' => 'string' ),
								'channel' => array( 'type' => 'string' ),
							),
						),
						'discord'   => array(
							'type'       => 'object',
							'properties' => array(
								'token'      => array( 'type' => 'string' ),
								'channel_id' => array( 'type' => 'string' ),
							),
						),
						'teams'     => array(
							'type'       => 'object',
							'properties' => array(
								'token'      => array( 'type' => 'string' ),
								'team_id'    => array( 'type' => 'string' ),
								'channel_id' => array( 'type' => 'string' ),
							),
						),
						'messenger' => array(
							'type'       => 'object',
							'properties' => array(
								'access_token' => array( 'type' => 'string' ),
								'recipient_id' => array( 'type' => 'string' ),
							),
						),
						'whatsapp'  => array(
							'type'       => 'object',
							'properties' => array(
								'access_token'    => array( 'type' => 'string' ),
								'phone_number_id' => array( 'type' => 'string' ),
								'to'              => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'required'             => array( 'message', 'channels', 'credentials' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_unified_channel_broadcast_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to broadcast messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$message = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'A message is required for broadcasting.', 'mcp-ai-wpoos-pro' ) );
		}

		$channels = isset( $arguments['channels'] ) && is_array( $arguments['channels'] ) ? $arguments['channels'] : array();
		if ( empty( $channels ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_channels', __( 'At least one channel must be specified.', 'mcp-ai-wpoos-pro' ) );
		}

		$credentials = isset( $arguments['credentials'] ) && is_array( $arguments['credentials'] ) ? $arguments['credentials'] : array();
		if ( empty( $credentials ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'Channel credentials are required.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'unified_channel_broadcast_request',
			'Broadcasting message to multiple channels.',
			array(
				'channels'      => $channels,
				'message_length' => strlen( $message ),
			)
		);

		$results = array(
			'success'  => array(),
			'failures' => array(),
		);

		// Broadcast to each channel.
		foreach ( $channels as $channel ) {
			$channel = sanitize_text_field( $channel );

			if ( ! isset( $credentials[ $channel ] ) ) {
				$results['failures'][ $channel ] = array(
					'error' => __( 'Missing credentials for this channel.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$result = $this->send_to_channel( $channel, $message, $credentials[ $channel ], $context );

			if ( is_wp_error( $result ) ) {
				$results['failures'][ $channel ] = array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
					'data'  => $result->get_error_data(),
				);
			} else {
				$results['success'][ $channel ] = $result;
			}
		}

		// Add summary.
		$results['summary'] = array(
			'total_channels'      => count( $channels ),
			'successful_channels' => count( $results['success'] ),
			'failed_channels'     => count( $results['failures'] ),
		);

		return $results;
	}

	/**
	 * Send message to a specific channel.
	 *
	 * @param string $channel     Channel name.
	 * @param string $message     Message text.
	 * @param array  $credentials Channel credentials.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error
	 */
	protected function send_to_channel( $channel, $message, array $credentials, array $context ) {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();

		switch ( $channel ) {
			case 'telegram':
				if ( ! isset( $credentials['token'], $credentials['chat_id'] ) ) {
					return new WP_Error( 'missing_telegram_credentials', __( 'Token and chat_id are required for Telegram.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_telegram_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'Telegram tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'token'   => $credentials['token'],
						'chat_id' => $credentials['chat_id'],
						'text'    => $message,
					),
					$context
				);

			case 'slack':
				if ( ! isset( $credentials['token'], $credentials['channel'] ) ) {
					return new WP_Error( 'missing_slack_credentials', __( 'Token and channel are required for Slack.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_slack_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'Slack tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'token'   => $credentials['token'],
						'channel' => $credentials['channel'],
						'text'    => $message,
					),
					$context
				);

			case 'discord':
				if ( ! isset( $credentials['token'], $credentials['channel_id'] ) ) {
					return new WP_Error( 'missing_discord_credentials', __( 'Token and channel_id are required for Discord.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_discord_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'Discord tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'token'      => $credentials['token'],
						'channel_id' => $credentials['channel_id'],
						'content'    => $message,
					),
					$context
				);

			case 'teams':
				if ( ! isset( $credentials['token'], $credentials['team_id'], $credentials['channel_id'] ) ) {
					return new WP_Error( 'missing_teams_credentials', __( 'Token, team_id, and channel_id are required for Teams.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_teams_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'Teams tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'token'      => $credentials['token'],
						'team_id'    => $credentials['team_id'],
						'channel_id' => $credentials['channel_id'],
						'content'    => $message,
					),
					$context
				);

			case 'messenger':
				if ( ! isset( $credentials['access_token'], $credentials['recipient_id'] ) ) {
					return new WP_Error( 'missing_messenger_credentials', __( 'Access token and recipient_id are required for Messenger.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_messenger_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'Messenger tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'access_token' => $credentials['access_token'],
						'recipient_id' => $credentials['recipient_id'],
						'message'      => $message,
					),
					$context
				);

			case 'whatsapp':
				if ( ! isset( $credentials['access_token'], $credentials['phone_number_id'], $credentials['to'] ) ) {
					return new WP_Error( 'missing_whatsapp_credentials', __( 'Access token, phone_number_id, and to are required for WhatsApp.', 'mcp-ai-wpoos-pro' ) );
				}

				$tool = $tool_registry->get_tool( 'send_whatsapp_message' );
				if ( ! $tool ) {
					return new WP_Error( 'tool_not_found', __( 'WhatsApp tool not found.', 'mcp-ai-wpoos-pro' ) );
				}

				return $tool->execute(
					array(
						'access_token'    => $credentials['access_token'],
						'phone_number_id' => $credentials['phone_number_id'],
						'to'              => $credentials['to'],
						'text'            => $message,
					),
					$context
				);

			default:
				return new WP_Error(
					'unsupported_channel',
					sprintf(
						/* translators: %s: channel name */
						__( 'Channel "%s" is not supported.', 'mcp-ai-wpoos-pro' ),
						$channel
					)
				);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends messages.
			'external-api',         // Calls multiple external APIs.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
