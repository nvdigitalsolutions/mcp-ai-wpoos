<?php
/**
 * NV oOS Cloud — Provider-client adapter.
 *
 * Implements `Interface_WP_MCP_AI_Provider_Client` by delegating to the
 * concrete {@see WP_MCP_AI_NV_Cloud_Client}. Provided so the language-model
 * router can treat NV oOS Cloud uniformly alongside OpenAI, Anthropic,
 * Gemini, OpenRouter and friends.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_NV_Cloud_Provider_Client' ) ) {

	/**
	 * Adapter wrapping the NV oOS Cloud client.
	 */
	class WP_MCP_AI_NV_Cloud_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

		/**
		 * Underlying client.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Client
		 */
		protected $client;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_NV_Cloud_Client|null $client Optional concrete client.
		 */
		public function __construct( $client = null ) {
			$this->client = $client instanceof WP_MCP_AI_NV_Cloud_Client
				? $client
				: new WP_MCP_AI_NV_Cloud_Client();
		}

		/**
		 * Get the concrete client (used by the router filter).
		 *
		 * @return WP_MCP_AI_NV_Cloud_Client
		 */
		public function get_client() {
			return $this->client;
		}

		/**
		 * Send a chat-completion request.
		 *
		 * @param array $messages Messages.
		 * @param array $options  Options.
		 * @return array|WP_Error
		 */
		public function chat( $messages, $options = array() ) {
			return $this->client->create_chat_completion( $messages, $options );
		}

		/**
		 * Stream a chat-completion response.
		 *
		 * @param array    $messages Messages.
		 * @param array    $options  Options.
		 * @param callable $callback Streaming callback.
		 * @return array|WP_Error
		 */
		public function stream( $messages, $options = array(), $callback = null ) {
			$options['stream'] = true;
			if ( is_callable( $callback ) ) {
				$options['stream_callback'] = $callback;
			}
			return $this->client->create_chat_completion( $messages, $options );
		}

		/**
		 * List available models.
		 *
		 * @return array|WP_Error
		 */
		public function list_models() {
			return $this->client->list_models();
		}

		/**
		 * Provider slug.
		 *
		 * @return string
		 */
		public function get_provider_slug() {
			return WP_MCP_AI_NV_Cloud_Client::PROVIDER_SLUG;
		}
	}
}
