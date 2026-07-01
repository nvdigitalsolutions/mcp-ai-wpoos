<?php
/**
 * Kimi (Moonshot AI) Provider Client Adapter
 *
 * Implements Interface_WP_MCP_AI_Provider_Client by delegating to the
 * concrete WP_MCP_AI_Kimi_Client class.
 *
 * @package WP_MCP_AI
 * @since   2026.07
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php';

/**
 * Kimi (Moonshot AI) implementation of the Provider Client interface.
 *
 * Moonshot AI exposes an OpenAI-compatible REST API, so the adapter follows
 * the same delegation pattern used by OpenAI, Gemini, Anthropic, and DeepSeek
 * adapters.
 *
 * @since 2026.07
 */
class WP_MCP_AI_Kimi_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

	/**
	 * The underlying Kimi client.
	 *
	 * @var WP_MCP_AI_Kimi_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Kimi_Client|null $client Optional concrete client. Defaults to a new instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_Kimi_Client ? $client : new WP_MCP_AI_Kimi_Client();
	}

	/**
	 * Send a chat-completion request.
	 *
	 * @param array $messages  Conversation messages in OpenAI-compatible format.
	 * @param array $options   Provider-specific options (model, temperature, tools, etc.).
	 * @return array|WP_Error Normalised response or WP_Error on failure.
	 */
	public function chat( $messages, $options = array() ) {
		return $this->client->create_chat_completion( $messages, $options );
	}

	/**
	 * Stream a chat-completion response via callback.
	 *
	 * Kimi's SSE streaming format is identical to OpenAI's. The
	 * concrete client handles streaming via `do_realtime_curl_stream()`.
	 *
	 * @param array    $messages  Conversation messages.
	 * @param array    $options   Provider-specific options.
	 * @param callable $callback  Called for each streamed token/chunk.
	 * @return array|WP_Error Final response or WP_Error on failure.
	 */
	public function stream( $messages, $options = array(), $callback = null ) {
		$options['stream'] = true;

		if ( is_callable( $callback ) ) {
			$options['stream_callback'] = $callback;
		}

		return $this->client->create_chat_completion( $messages, $options );
	}

	/**
	 * Return a list of available Kimi models.
	 *
	 * @return array|WP_Error Array of model identifiers or WP_Error on failure.
	 */
	public function list_models() {
		return $this->client->list_models();
	}

	/**
	 * Return the provider slug.
	 *
	 * @return string
	 */
	public function get_provider_slug() {
		return 'kimi';
	}
}
