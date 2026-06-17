<?php
/**
 * DigitalOcean Provider Client Adapter
 *
 * Implements Interface_WP_MCP_AI_Provider_Client by delegating to the
 * concrete WP_MCP_AI_DigitalOcean_Client class.
 *
 * @package WP_MCP_AI
 * @since   1.1.16
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php';

/**
 * DigitalOcean implementation of the Provider Client interface.
 *
 * @since 1.1.16
 */
class WP_MCP_AI_DigitalOcean_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

	/**
	 * The underlying DigitalOcean client.
	 *
	 * @var WP_MCP_AI_DigitalOcean_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_DigitalOcean_Client|null $client Optional concrete client. Defaults to a new instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_DigitalOcean_Client ? $client : new WP_MCP_AI_DigitalOcean_Client();
	}

	/**
	 * Send a chat-completion request.
	 *
	 * @param array $messages Conversation messages in OpenAI-compatible format.
	 * @param array $options  Provider-specific options (model, temperature, tools, etc.).
	 * @return array|WP_Error Normalised response or WP_Error on failure.
	 */
	public function chat( $messages, $options = array() ) {
		return $this->client->create_chat_completion( $messages, $options );
	}

	/**
	 * Stream a chat-completion response via callback.
	 *
	 * DigitalOcean Serverless Inference uses the same SSE protocol as OpenAI,
	 * so this delegates to `create_chat_completion` with `stream => true`.
	 *
	 * @param array    $messages Conversation messages.
	 * @param array    $options  Provider-specific options.
	 * @param callable $callback Called for each streamed token/chunk.
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
	 * Return a list of available DigitalOcean models.
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
		return 'digitalocean';
	}
}
