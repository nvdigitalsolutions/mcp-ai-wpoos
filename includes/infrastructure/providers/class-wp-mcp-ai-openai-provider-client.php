<?php
/**
 * OpenAI Provider Client Adapter
 *
 * Implements Interface_WP_MCP_AI_Provider_Client by delegating to the
 * concrete WP_MCP_AI_OpenAI_Client class.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php';

/**
 * OpenAI implementation of the Provider Client interface.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_OpenAI_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

	/**
	 * The underlying OpenAI client.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_OpenAI_Client|null $client Optional concrete client. Defaults to a new instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_OpenAI_Client ? $client : new WP_MCP_AI_OpenAI_Client();
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
	 * OpenAI's native client does not expose a dedicated streaming method, so
	 * this delegates to `create_chat_completion` with `stream => true` in options,
	 * which causes the underlying client to handle SSE parsing internally.
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
	 * Return a list of available OpenAI models.
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
		return 'openai';
	}
}
