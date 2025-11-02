<?php
/**
 * Language model router.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes chat completion requests to the configured language model provider.
 */
class WP_MCP_AI_Language_Model_Router {

	/**
	 * OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	protected $openai_client;

	/**
	 * Gemini client instance.
	 *
	 * @var WP_MCP_AI_Gemini_Client
	 */
	protected $gemini_client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_OpenAI_Client $openai_client  OpenAI client instance.
	 * @param WP_MCP_AI_Gemini_Client $gemini_client Gemini client instance.
	 */
	public function __construct( WP_MCP_AI_OpenAI_Client $openai_client, WP_MCP_AI_Gemini_Client $gemini_client ) {
		$this->openai_client = $openai_client;
		$this->gemini_client = $gemini_client;
	}

	/**
	 * Dispatch a chat completion request to the appropriate provider.
	 *
	 * @param array $messages Sanitized message payload.
	 * @param array $options  Request options.
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$provider = isset( $options['provider'] ) ? sanitize_key( $options['provider'] ) : '';

		if ( empty( $provider ) ) {
			$provider = 'openai';
		}

		switch ( $provider ) {
			case 'gemini':
				return $this->gemini_client->create_chat_completion( $messages, $options );

			case 'openai':
			default:
				return $this->openai_client->create_chat_completion( $messages, $options );
		}
	}
}
