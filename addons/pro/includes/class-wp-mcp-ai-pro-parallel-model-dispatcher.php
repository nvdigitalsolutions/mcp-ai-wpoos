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
		$jev_routing = null;

		// Optional Jev routing pre-step: classify the prompt so callers can
		// decide how to present or gate the comparison. Routing only — Jev
		// never replaces a chat client here, and failures are silent (the
		// comparison proceeds unchanged).
		if ( ! empty( $options['jev_routing'] ) ) {
			$jev_routing = $this->classify_prompt( $messages );
		}

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

			// Every base provider client exposes create_chat_completion(); a few
			// legacy/third-party clients expose chat_completion() instead. Neither
			// existing means the provider cannot serve chat, so fail that entry
			// gracefully rather than fataling the whole comparison.
			if ( ! method_exists( $client, 'create_chat_completion' ) && ! method_exists( $client, 'chat_completion' ) ) {
				$result['error']   = __( 'Provider client does not support chat completions.', 'mcp-ai-wpoos' );
				$result['time_ms'] = (int) ( ( microtime( true ) - $start_time ) * 1000 );
				$results[]         = $result;
				continue;
			}

			$request_options = array(
				'model'       => $model,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'stream'      => false,
			);

			$response = method_exists( $client, 'create_chat_completion' )
				? $client->create_chat_completion( $messages, $request_options )
				: $client->chat_completion( $messages, $request_options );

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

		$response = array(
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

		// Attach the routing decision when a Jev pre-step ran successfully.
		if ( is_array( $jev_routing ) && ! is_wp_error( $jev_routing ) ) {
			$response['data']['routing'] = $jev_routing;
		}

		return $response;
	}

	/**
	 * Classify a chat prompt with the Jev decision classifier.
	 *
	 * Cascade pre-step for routing: returns the task type, a complexity
	 * score, and whether the prompt likely needs a frontier model. Purely
	 * advisory — callers decide what to do with the decision. Fails open
	 * with a WP_Error when the classifier is unavailable.
	 *
	 * @since 1.9.0
	 *
	 * @param array $messages Chat messages array.
	 * @param array $options  Optional classifier options (model, timeout).
	 * @return array|WP_Error Routing decision or WP_Error.
	 */
	public function classify_prompt( $messages, $options = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-classifier.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Classifier' ) || ! WP_MCP_AI_Pro_Jev_Classifier::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_jev_unavailable',
				__( 'The Jev decision classifier is not available on this site.', 'mcp-ai-wpoos' )
			);
		}

		return WP_MCP_AI_Pro_Jev_Classifier::classify_prompt( $messages, $options );
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
