<?php
/**
 * Z.AI (Zhipu AI / GLM) Provider Client Adapter
 *
 * Implements Interface_WP_MCP_AI_Provider_Client by delegating to the
 * concrete WP_MCP_AI_ZAI_Client class.
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
 * Z.AI (GLM) implementation of the Provider Client interface.
 *
 * Z.AI exposes an OpenAI-compatible REST API at /api/paas/v4, so the
 * adapter follows the same delegation pattern used by all other providers.
 *
 * @since 2026.07
 */
class WP_MCP_AI_ZAI_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

	/**
	 * The underlying Z.AI client.
	 *
	 * @var WP_MCP_AI_ZAI_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_ZAI_Client|null $client Optional concrete client. Defaults to a new instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_ZAI_Client ? $client : new WP_MCP_AI_ZAI_Client();
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
	 * Z.AI's SSE streaming format is identical to OpenAI's. The
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
	 * Return a list of available Z.AI models.
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
		return 'zai';
	}
}
