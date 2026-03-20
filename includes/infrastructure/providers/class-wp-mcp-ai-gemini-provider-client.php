<?php
/**
 * Gemini Provider Client Adapter
 *
 * Implements Interface_WP_MCP_AI_Provider_Client by delegating to the
 * concrete WP_MCP_AI_Gemini_Client class.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-provider-client.php';

/**
 * Gemini implementation of the Provider Client interface.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Gemini_Provider_Client implements Interface_WP_MCP_AI_Provider_Client {

	/**
	 * The underlying Gemini client.
	 *
	 * @var WP_MCP_AI_Gemini_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Gemini_Client|null $client Optional concrete client. Defaults to a new instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof WP_MCP_AI_Gemini_Client ? $client : new WP_MCP_AI_Gemini_Client();
	}

	/**
	 * Send a chat-completion request.
	 *
	 * @param array $messages  Conversation messages.
	 * @param array $options   Provider-specific options.
	 * @return array|WP_Error Normalised response or WP_Error on failure.
	 */
	public function chat( $messages, $options = array() ) {
		return $this->client->create_chat_completion( $messages, $options );
	}

	/**
	 * Stream a chat-completion response via callback.
	 *
	 * @param array    $messages  Conversation messages.
	 * @param array    $options   Provider-specific options.
	 * @param callable $callback  Called for each streamed token/chunk.
	 * @return array|WP_Error Final response or WP_Error on failure.
	 */
	public function stream( $messages, $options = array(), $callback = null ) {
		return $this->client->stream_chat_completion( $messages, $options, $callback );
	}

	/**
	 * Return a list of available Gemini models.
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
		return 'gemini';
	}
}
