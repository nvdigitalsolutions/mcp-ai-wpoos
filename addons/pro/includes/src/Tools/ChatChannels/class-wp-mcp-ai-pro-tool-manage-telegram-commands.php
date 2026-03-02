<?php
/**
 * Tool that manages Telegram bot slash commands.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for managing Telegram bot slash commands via the Bot API.
 *
 * Supports setMyCommands, deleteMyCommands and getMyCommands with full
 * BotCommandScope support.
 */
class WP_MCP_AI_Pro_Tool_Manage_Telegram_Commands implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'manage_telegram_commands';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Telegram Commands', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages slash commands registered with a Telegram bot via setMyCommands/deleteMyCommands/getMyCommands with BotCommandScope support.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'         => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used to authenticate the request.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'enum'        => array( 'set', 'delete', 'get' ),
					'description' => __( 'Action to perform: "set" to register commands, "delete" to remove them, or "get" to list current commands.', 'mcp-ai-wpoos-pro' ),
				),
				'scope'         => array(
					'type'        => 'string',
					'enum'        => array( 'default', 'all_private_chats', 'all_group_chats', 'all_chat_administrators', 'chat', 'chat_administrators', 'chat_member' ),
					'description' => __( 'BotCommandScope type for the commands.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'default',
				),
				'chat_id'       => array(
					'type'        => 'string',
					'description' => __( 'Chat identifier. Required when scope is "chat", "chat_administrators", or "chat_member".', 'mcp-ai-wpoos-pro' ),
				),
				'user_id'       => array(
					'type'        => 'integer',
					'description' => __( 'User identifier. Required when scope is "chat_member".', 'mcp-ai-wpoos-pro' ),
				),
				'language_code' => array(
					'type'        => 'string',
					'description' => __( 'Two-letter ISO 639-1 language code for the commands.', 'mcp-ai-wpoos-pro' ),
				),
				'commands'      => array(
					'type'        => 'array',
					'description' => __( 'Array of command objects with "command" and "description" properties. Required when action is "set".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'command'     => array(
								'type'        => 'string',
								'description' => __( 'Slash command name (lowercase alphanumeric and underscores, max 32 characters).', 'mcp-ai-wpoos-pro' ),
							),
							'description' => array(
								'type'        => 'string',
								'description' => __( 'Short description of the command (max 256 characters).', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'required'             => array( 'token', 'action' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_manage_telegram_commands_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Telegram commands.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		if ( ! in_array( $action, array( 'set', 'delete', 'get' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Action must be "set", "delete", or "get".', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'set':
				return $this->set_commands( $token, $arguments, $context );
			case 'delete':
				return $this->delete_commands( $token, $arguments, $context );
			default:
				return $this->get_commands( $token, $arguments, $context );
		}
	}

	/**
	 * Register slash commands for the Telegram bot.
	 *
	 * @param string $token     Telegram bot token.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function set_commands( $token, array $arguments, array $context ) {
		if ( empty( $arguments['commands'] ) || ! is_array( $arguments['commands'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_commands', __( 'A non-empty commands array is required when action is "set".', 'mcp-ai-wpoos-pro' ) );
		}

		$sanitized_commands = array();

		foreach ( $arguments['commands'] as $cmd ) {
			if ( ! is_array( $cmd ) || ! isset( $cmd['command'], $cmd['description'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_command', __( 'Each command must have "command" and "description" keys.', 'mcp-ai-wpoos-pro' ) );
			}

			$name = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '', $cmd['command'] ) );
			$name = substr( $name, 0, 32 );

			if ( '' === $name || ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_command_name', __( 'Command name must start with a lowercase letter and contain only lowercase letters, digits, and underscores (max 32 characters).', 'mcp-ai-wpoos-pro' ) );
			}

			$desc = sanitize_text_field( $cmd['description'] );
			$desc = substr( $desc, 0, 256 );

			$sanitized_commands[] = array(
				'command'     => $name,
				'description' => $desc,
			);
		}

		$scope_type = isset( $arguments['scope'] ) ? sanitize_text_field( $arguments['scope'] ) : 'default';
		$chat_id    = isset( $arguments['chat_id'] ) ? sanitize_text_field( $arguments['chat_id'] ) : '';
		$user_id    = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		$payload = array(
			'commands' => $sanitized_commands,
		);

		$scope = $this->build_scope( $scope_type, $chat_id, $user_id );

		if ( null !== $scope ) {
			$payload['scope'] = $scope;
		}

		if ( ! empty( $arguments['language_code'] ) ) {
			$payload['language_code'] = sanitize_text_field( $arguments['language_code'] );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/setMyCommands', rawurlencode( $token ) );

		WP_MCP_AI_Logger::log_event(
			'telegram_set_commands_request',
			'Setting Telegram bot commands.',
			array(
				'endpoint'      => 'https://api.telegram.org/bot***/setMyCommands',
				'command_count' => count( $sanitized_commands ),
				'scope'         => $scope_type,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_telegram_commands_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram setMyCommands request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		return $this->handle_response( $response, 'setMyCommands' );
	}

	/**
	 * Delete slash commands for the Telegram bot.
	 *
	 * @param string $token     Telegram bot token.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_commands( $token, array $arguments, array $context ) {
		$scope_type = isset( $arguments['scope'] ) ? sanitize_text_field( $arguments['scope'] ) : 'default';
		$chat_id    = isset( $arguments['chat_id'] ) ? sanitize_text_field( $arguments['chat_id'] ) : '';
		$user_id    = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		$payload = array();
		$scope   = $this->build_scope( $scope_type, $chat_id, $user_id );

		if ( null !== $scope ) {
			$payload['scope'] = $scope;
		}

		if ( ! empty( $arguments['language_code'] ) ) {
			$payload['language_code'] = sanitize_text_field( $arguments['language_code'] );
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/deleteMyCommands', rawurlencode( $token ) );

		WP_MCP_AI_Logger::log_event(
			'telegram_delete_commands_request',
			'Deleting Telegram bot commands.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/deleteMyCommands',
				'scope'    => $scope_type,
			)
		);

		$request_args = array(
			'timeout' => apply_filters( 'wp_mcp_ai_manage_telegram_commands_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
		);

		if ( ! empty( $payload ) ) {
			$body = wp_json_encode( $payload );

			if ( false === $body ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
			}

			$request_args['headers'] = array(
				'Content-Type' => 'application/json',
			);
			$request_args['body']    = $body;
		}

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram deleteMyCommands request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		return $this->handle_response( $response, 'deleteMyCommands' );
	}

	/**
	 * Retrieve the current slash commands for the Telegram bot.
	 *
	 * @param string $token     Telegram bot token.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function get_commands( $token, array $arguments, array $context ) {
		$scope_type = isset( $arguments['scope'] ) ? sanitize_text_field( $arguments['scope'] ) : 'default';
		$chat_id    = isset( $arguments['chat_id'] ) ? sanitize_text_field( $arguments['chat_id'] ) : '';
		$user_id    = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		$payload = array();
		$scope   = $this->build_scope( $scope_type, $chat_id, $user_id );

		if ( null !== $scope ) {
			$payload['scope'] = $scope;
		}

		if ( ! empty( $arguments['language_code'] ) ) {
			$payload['language_code'] = sanitize_text_field( $arguments['language_code'] );
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/getMyCommands', rawurlencode( $token ) );

		WP_MCP_AI_Logger::log_event(
			'telegram_get_commands_request',
			'Retrieving Telegram bot commands.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/getMyCommands',
				'scope'    => $scope_type,
			)
		);

		$request_args = array(
			'timeout' => apply_filters( 'wp_mcp_ai_manage_telegram_commands_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
		);

		if ( ! empty( $payload ) ) {
			$body = wp_json_encode( $payload );

			if ( false === $body ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
			}

			$request_args['headers'] = array(
				'Content-Type' => 'application/json',
			);
			$request_args['body']    = $body;
		}

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram getMyCommands request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		return $this->handle_response( $response, 'getMyCommands' );
	}

	/**
	 * Build a BotCommandScope payload for the Telegram API.
	 *
	 * @param string $scope_type Scope type identifier.
	 * @param string $chat_id    Chat identifier (required for chat-level scopes).
	 * @param int    $user_id    User identifier (required for chat_member scope).
	 * @return array|null Scope payload or null for the default scope.
	 */
	protected function build_scope( $scope_type, $chat_id, $user_id ) {
		if ( 'default' === $scope_type ) {
			return null;
		}

		$scope = array(
			'type' => 'bot_command_scope_' . $scope_type,
		);

		$chat_scopes = array( 'chat', 'chat_administrators', 'chat_member' );

		if ( in_array( $scope_type, $chat_scopes, true ) ) {
			$scope['chat_id'] = $chat_id;
		}

		if ( 'chat_member' === $scope_type ) {
			$scope['user_id'] = $user_id;
		}

		return $scope;
	}

	/**
	 * Handle Telegram API response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $method   API method name.
	 * @return array|WP_Error
	 */
	protected function handle_response( $response, $method ) {
		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['ok'] ) ) {
			$message = isset( $decoded['description'] ) ? $decoded['description'] : __( 'Telegram API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				sprintf( 'Telegram %s request was not successful.', $method ),
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
			'write',                // Modifies bot command configuration.
			'external-api',         // Calls Telegram Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
