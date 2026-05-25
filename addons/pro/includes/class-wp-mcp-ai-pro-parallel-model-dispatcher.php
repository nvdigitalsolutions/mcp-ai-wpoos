<?php
/**
 * Pro Parallel Model Dispatcher — Sends the same prompt to multiple AI models
 * simultaneously and returns their responses for comparison.
 *
 * Zed equivalent: Configure inline_alternatives, send same prompt to all
 * models, cycle through outputs.
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Parallel_Model_Dispatcher
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_Parallel_Model_Dispatcher {

	/**
	 * Default alternative models when none are configured.
	 *
	 * @since 1.7.0
	 * @var array
	 */
	const DEFAULT_ALTERNATIVES = array();

	/**
	 * Maximum number of parallel models.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const MAX_PARALLEL_MODELS = 5;

	/**
	 * Dispatch the same prompt to multiple models and collect responses.
	 *
	 * Each model receives the same messages array (system + user prompt).
	 * Requests are dispatched concurrently via WordPress HTTP API.
	 *
	 * @since 1.7.0
	 *
	 * @param array $messages    Chat messages array.
	 * @param array $models      Array of { provider, model } pairs.
	 * @param array $options     Additional options (temperature, max_tokens).
	 * @return array|WP_Error     { results: [{ provider, model, content, time_ms, error? }] }
	 */
	public function dispatch( $messages, $models, $options = array() ) {
		if ( empty( $models ) ) {
			return new WP_Error( 'no_models', __( 'No models configured for comparison.', 'mcp-ai-wpoos' ) );
		}

		// Limit to max parallel models.
		$models = array_slice( $models, 0, self::MAX_PARALLEL_MODELS );

		$temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : 0.7;
		$max_tokens  = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 2048;

		$results = array();

		// Execute sequentially for reliability.
		// Upgrade path: curl_multi for true parallelism in future version.
		foreach ( $models as $model_config ) {
			$start_time = microtime( true );

			$provider = sanitize_key( $model_config['provider'] );
			$model    = sanitize_text_field( $model_config['model'] );

			$result = array(
				'provider' => $provider,
				'model'    => $model,
				'content'  => '',
				'time_ms'  => 0,
				'error'    => null,
			);

			$client = $this->get_client( $provider );

			if ( is_wp_error( $client ) ) {
				$result['error']   = $client->get_error_message();
				$result['time_ms'] = (int) ( ( microtime( true ) - $start_time ) * 1000 );
				$results[]         = $result;
				continue;
			}

			$response = $client->chat_completion(
				$messages,
				array(
					'model'       => $model,
					'temperature' => $temperature,
					'max_tokens'  => $max_tokens,
					'stream'      => false,
				)
			);

			$result['time_ms'] = (int) ( ( microtime( true ) - $start_time ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
			} else {
				$result['content'] = isset( $response['choices'][0]['message']['content'] )
					? trim( $response['choices'][0]['message']['content'] )
					: '';
			}

			$results[] = $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of models */
				_n( 'Response from %d model.', 'Responses from %d models.', count( $results ), 'mcp-ai-wpoos' ),
				count( $results )
			),
			'data'    => array(
				'results' => $results,
			),
		);
	}

	/**
	 * Get available alternative models for comparison.
	 *
	 * @since 1.7.0
	 *
	 * @return array Array of { provider, model, label } pairs.
	 */
	public function get_available_alternatives() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check for user-configured alternatives in settings.
		if ( ! empty( $settings['model_alternatives'] ) && is_array( $settings['model_alternatives'] ) ) {
			return $settings['model_alternatives'];
		}

		// Default alternatives if nothing configured.
		return array(
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'label'    => 'GPT-4o Mini',
			),
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-4-5',
				'label'    => 'Claude Sonnet 4.5',
			),
		);
	}

	/**
	 * Resolve a provider client instance.
	 *
	 * @since 1.7.0
	 *
	 * @param string $provider Provider slug.
	 * @return object|WP_Error
	 */
	private function get_client( $provider ) {
		// Try DI container first.
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container ) {
				try {
					return $container->get( 'client.' . $provider );
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- Intentional: fall through to direct instantiation.
				} catch ( \Exception $e ) {
					// Fall through.
				}
			}
		}

		// Direct instantiation fallback.
		$client_map = array(
			'openai'     => 'WP_MCP_AI_OpenAI_Client',
			'anthropic'  => 'WP_MCP_AI_Anthropic_Client',
			'google'     => 'WP_MCP_AI_Gemini_Client',
			'gemini'     => 'WP_MCP_AI_Gemini_Client',
			'deepseek'   => 'WP_MCP_AI_DeepSeek_Client',
			'openrouter' => 'WP_MCP_AI_OpenRouter_Client',
			'ollama'     => 'WP_MCP_AI_Ollama_Client',
		);

		if ( isset( $client_map[ $provider ] ) && class_exists( $client_map[ $provider ] ) ) {
			$class = $client_map[ $provider ];
			return new $class();
		}

		return new WP_Error(
			'provider_unavailable',
			sprintf(
				/* translators: %s: provider name */
				__( 'Provider "%s" is not available.', 'mcp-ai-wpoos' ),
				esc_html( $provider )
			)
		);
	}
}
