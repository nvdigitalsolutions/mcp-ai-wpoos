<?php
/**
 * Tool that manages Telegram bot webhooks.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for managing Telegram bot webhooks via the Bot API.
 */
class WP_MCP_AI_Pro_Tool_Manage_Telegram_Webhook implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'manage_telegram_webhook';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Telegram Webhook', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Configures or deletes webhook settings for a Telegram bot.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'           => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used to authenticate the request.', 'mcp-ai-wpoos-pro' ),
				),
				'action'          => array(
					'type'        => 'string',
					'enum'        => array( 'set', 'delete' ),
					'description' => __( 'Action to perform: "set" to configure webhook or "delete" to remove it.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'set',
				),
				'url'             => array(
					'type'        => 'string',
					'description' => __( 'HTTPS URL to send updates to. Required when action is "set".', 'mcp-ai-wpoos-pro' ),
				),
				'max_connections' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum allowed number of simultaneous HTTPS connections to the webhook (1-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 40,
				),
				'secret_token'    => array(
					'type'        => 'string',
					'description' => __( 'Optional secret token (1–256 characters; A–Z, a–z, 0–9, _ and -). When set, Telegram will include this value in the X-Telegram-Bot-Api-Secret-Token header on every webhook update so the plugin can verify the request is genuinely from Telegram.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 256,
					'pattern'     => '^[A-Za-z0-9_-]+$',
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
		$required_capability = apply_filters( 'wp_mcp_ai_manage_telegram_webhook_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Telegram webhooks.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'set';

		if ( ! in_array( $action, array( 'set', 'delete' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Action must be either "set" or "delete".', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'set' === $action ) {
			return $this->set_webhook( $token, $arguments, $context );
		} else {
			return $this->delete_webhook( $token, $context );
		}
	}

	/**
	 * Set a webhook for the Telegram bot.
	 *
	 * @param string $token     Telegram bot token.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function set_webhook( $token, array $arguments, array $context ) {
		$url = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'], array( 'https' ) ) : '';

		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_webhook_url', __( 'A valid HTTPS URL is required for webhook configuration.', 'mcp-ai-wpoos-pro' ) );
		}

		$max_connections = isset( $arguments['max_connections'] ) ? absint( $arguments['max_connections'] ) : 40;

		// Enforce limits.
		if ( $max_connections < 1 ) {
			$max_connections = 1;
		} elseif ( $max_connections > 100 ) {
			$max_connections = 100;
		}

		// Resolve the webhook secret token: use an explicitly provided value or
		// fall back to the one stored on the matching connection so that
		// Telegram will send the X-Telegram-Bot-Api-Secret-Token header on
		// every update and the incoming request can be verified.
		$secret_token = isset( $arguments['secret_token'] ) ? trim( (string) $arguments['secret_token'] ) : '';

		if ( ! empty( $secret_token ) ) {
			// Validate caller-supplied token (A–Z, a–z, 0–9, _ and - only; 1–256 chars).
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}

			if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_valid_telegram_secret_token( $secret_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_secret_token',
					__( 'Webhook secret token may only contain A–Z, a–z, 0–9, underscores and hyphens (1–256 characters).', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/setWebhook', rawurlencode( $token ) );

		$payload = array(
			'url'             => $url,
			'max_connections' => $max_connections,
			'allowed_updates' => array(
				'message',
				'edited_message',
				'channel_post',
				'edited_channel_post',
				'my_chat_member',
				'chat_member',
			),
		);

		if ( ! empty( $secret_token ) ) {
			$payload['secret_token'] = $secret_token;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_set_webhook_request',
			'Setting Telegram webhook.',
			array(
				'endpoint'         => 'https://api.telegram.org/bot***/setWebhook',
				'url'              => $url,
				'max_connections'  => $max_connections,
				'secret_token_set' => ! empty( $secret_token ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_manage_telegram_webhook_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram setWebhook request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$result = $this->handle_response( $response, 'setWebhook' );

		// Automatically register default bot commands with Telegram when the
		// webhook is successfully configured (industry best practice).
		if ( ! is_wp_error( $result ) ) {
			$this->sync_default_commands( $token );
		}

		return $result;
	}

	/**
	 * Delete the webhook for the Telegram bot.
	 *
	 * @param string $token   Telegram bot token.
	 * @param array  $context Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_webhook( $token, array $context ) {
		$endpoint = sprintf( 'https://api.telegram.org/bot%s/deleteWebhook', rawurlencode( $token ) );

		WP_MCP_AI_Logger::log_event(
			'telegram_delete_webhook_request',
			'Deleting Telegram webhook.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/deleteWebhook',
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_manage_telegram_webhook_timeout', self::DEFAULT_TIMEOUT, $context, array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram deleteWebhook request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		return $this->handle_response( $response, 'deleteWebhook' );
	}

	/**
	 * Register the default bot commands with Telegram after webhook setup.
	 *
	 * Calls setMyCommands for three scopes following Telegram best practices:
	 * 1. Default scope – available everywhere as fallback.
	 * 2. All private chats – user-facing commands.
	 * 3. All group chats – group-relevant commands.
	 *
	 * Failures are logged but do not affect the webhook result since commands
	 * can always be registered later via the manage_telegram_commands tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token Bot token.
	 */
	protected function sync_default_commands( $token ) {
		$webhook_controller = 'WP_MCP_AI_Telegram_Webhook_Controller';

		if ( ! class_exists( $webhook_controller ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-telegram-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			}
		}

		if ( ! method_exists( $webhook_controller, 'get_default_commands' ) ) {
			return;
		}

		$commands = WP_MCP_AI_Telegram_Webhook_Controller::get_default_commands();

		if ( empty( $commands ) ) {
			return;
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/setMyCommands', rawurlencode( $token ) );

		// Register for default scope (fallback for all chats).
		$scopes = array(
			null, // default scope (omit scope parameter).
			array( 'type' => 'all_private_chats' ),
			array( 'type' => 'all_group_chats' ),
		);

		foreach ( $scopes as $scope ) {
			$payload = array( 'commands' => $commands );
			if ( null !== $scope ) {
				$payload['scope'] = $scope;
			}

			$body = wp_json_encode( $payload );
			if ( false === $body ) {
				continue;
			}

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => self::DEFAULT_TIMEOUT,
					'body'    => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Telegram setMyCommands failed during webhook setup.',
					array(
						'scope' => $scope ? $scope['type'] : 'default',
						'error' => $response->get_error_message(),
					)
				);
			}
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_commands_synced',
			'Default bot commands registered with Telegram.',
			array( 'command_count' => count( $commands ) )
		);
	}

	/**
	 * Handle Telegram API response.
	 *
	 * @param array|WP_Error $response   HTTP response.
	 * @param string         $method     API method name.
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
			'write',                // Modifies webhook configuration.
			'external-api',         // Calls Telegram Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
