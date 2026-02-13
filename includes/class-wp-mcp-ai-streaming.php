<?php
/**
 * Simple streaming AI client for internal tool orchestration.
 *
 * Provides a lightweight wrapper for sending simple prompts to AI models
 * for tool orchestration workflows (e.g., prompt optimization, content enhancement).
 * Uses the configured default provider and model from plugin settings.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Streaming class
 *
 * Singleton class for sending simple AI requests during tool orchestration.
 * This is intentionally lightweight and uses synchronous requests.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Streaming {

	/**
	 * Singleton instance
	 *
	 * @var WP_MCP_AI_Streaming|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return WP_MCP_AI_Streaming Streaming client instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Language Model Router instance.
	 *
	 * @var WP_MCP_AI_Language_Model_Router|null
	 */
	private $router = null;

	/**
	 * Private constructor to enforce singleton
	 */
	private function __construct() {
		// Router will be lazy-loaded when needed.
	}

	/**
	 * Get or create the Language Model Router instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_MCP_AI_Language_Model_Router|WP_Error Router instance or error if dependencies unavailable.
	 */
	private function get_router() {
		if ( null !== $this->router ) {
			return $this->router;
		}

		// Use the DI container if available.
		if ( class_exists( 'WP_MCP_AI_Container' ) ) {
			$container = WP_MCP_AI_Container::get_instance();
			if ( $container->has( 'router' ) ) {
				$this->router = $container->get( 'router' );
				return $this->router;
			}
		}

		// Fallback: create router manually.
		if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			return new WP_Error(
				'router_unavailable',
				__( 'Language Model Router is not available.', 'mcp-ai-wpoos' )
			);
		}

		// Create client instances.
		$openai_client = new WP_MCP_AI_OpenAI_Client();
		$gemini_client = new WP_MCP_AI_Gemini_Client();

		$this->router = new WP_MCP_AI_Language_Model_Router( $openai_client, $gemini_client );

		return $this->router;
	}

	/**
	 * Send a simple message to the AI and get a response.
	 *
	 * This method is designed for internal tool orchestration use cases
	 * like prompt optimization or content enhancement. It uses the
	 * default AI provider configured in plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt    The prompt/message to send to the AI.
	 * @param array  $options   Optional. Additional options for the request.
	 *                          - model: Override the default model
	 *                          - max_tokens: Maximum tokens in response (default: 500)
	 *                          - temperature: Sampling temperature (default: 0.7)
	 * @return array|WP_Error Response array with 'content' key, or WP_Error on failure.
	 */
	public function send_message( $prompt, $options = array() ) {
		if ( empty( $prompt ) || ! is_string( $prompt ) ) {
			return new WP_Error(
				'invalid_prompt',
				__( 'Prompt must be a non-empty string.', 'mcp-ai-wpoos' )
			);
		}

		// Get the router instance.
		$router = $this->get_router();
		if ( is_wp_error( $router ) ) {
			return $router;
		}

		// Get plugin settings to determine which provider to use.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$provider = isset( $settings['provider'] ) ? $settings['provider'] : 'openai';

		// Determine which model to use.
		$model = isset( $options['model'] ) ? $options['model'] : $this->get_default_model( $provider );

		// Build messages array for the API call.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		// Prepare request options for the router.
		$request_options = array(
			'provider'    => $provider,
			'model'       => $model,
			'max_tokens'  => isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 500,
			'temperature' => isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : 0.7,
			'timeout'     => 30, // Reasonable timeout for orchestration requests.
		);

		try {
			$response = $router->create_chat_completion( $messages, $request_options );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'streaming_request_failed',
					'Orchestration AI request failed',
					array(
						'provider' => $provider,
						'model'    => $model,
						'error'    => $response->get_error_message(),
					)
				);
				return $response;
			}

			// Extract content from response.
			// The response format varies by provider, so we need to handle different structures.
			$content = $this->extract_content_from_response( $response, $provider );

			if ( empty( $content ) ) {
				return new WP_Error(
					'empty_response',
					__( 'AI returned an empty response.', 'mcp-ai-wpoos' ),
					array( 'response' => $response )
				);
			}

			return array(
				'content'  => $content,
				'provider' => $provider,
				'model'    => $model,
			);

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'streaming_exception',
				'Exception during orchestration AI request',
				array(
					'provider'  => $provider,
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'request_exception',
				sprintf(
					/* translators: %s: exception message */
					__( 'AI request failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Get the default model for a given provider.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider Provider identifier (openai, gemini, ollama, etc.).
	 * @return string Model identifier.
	 */
	private function get_default_model( $provider ) {
		$defaults = array(
			'openai'     => 'gpt-4o-mini',
			'gemini'     => 'gemini-2.0-flash-exp',
			'ollama'     => 'llama3.2',
			'anthropic'  => 'claude-3-haiku-20240307',
			'huggingface' => 'mistralai/Mistral-7B-Instruct-v0.2',
		);

		$default_model = isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : 'gpt-4o-mini';

		/**
		 * Filter the default model for orchestration requests.
		 *
		 * @since 1.0.0
		 *
		 * @param string $default_model Default model identifier.
		 * @param string $provider      Provider identifier.
		 */
		return apply_filters( 'wp_mcp_ai_streaming_default_model', $default_model, $provider );
	}

	/**
	 * Extract content from API response based on provider.
	 *
	 * Different AI providers return responses in different formats.
	 * This method normalizes them to extract the text content.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $response API response.
	 * @param string $provider Provider identifier.
	 * @return string Extracted content.
	 */
	private function extract_content_from_response( $response, $provider ) {
		// OpenAI format: response['choices'][0]['message']['content']
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return trim( $response['choices'][0]['message']['content'] );
		}

		// Gemini format: response['candidates'][0]['content']['parts'][0]['text']
		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return trim( $response['candidates'][0]['content']['parts'][0]['text'] );
		}

		// Anthropic format: response['content'][0]['text']
		if ( isset( $response['content'][0]['text'] ) ) {
			return trim( $response['content'][0]['text'] );
		}

		// Ollama format: response['message']['content']
		if ( isset( $response['message']['content'] ) ) {
			return trim( $response['message']['content'] );
		}

		// Fallback: check if response itself is a string (some simpler APIs).
		if ( is_string( $response ) ) {
			return trim( $response );
		}

		// Fallback: check for a direct 'content' field.
		if ( isset( $response['content'] ) && is_string( $response['content'] ) ) {
			return trim( $response['content'] );
		}

		// Log unexpected response format for debugging.
		WP_MCP_AI_Logger::log_error(
			'unexpected_response_format',
			'Could not extract content from AI response',
			array(
				'provider'      => $provider,
				'response_keys' => is_array( $response ) ? array_keys( $response ) : 'not_array',
			)
		);

		return '';
	}
}
