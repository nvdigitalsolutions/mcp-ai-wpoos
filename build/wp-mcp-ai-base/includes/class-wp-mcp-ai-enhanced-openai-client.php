<?php
/**
 * Enhanced OpenAI client wrapper with rate limiting and token budget management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the OpenAI client with rate limiting and token budget features.
 */
class WP_MCP_AI_Enhanced_OpenAI_Client {

	/**
	 * OpenAI client instance.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	protected $client;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_OpenAI_Client $client OpenAI client instance.
	 */
	public function __construct( $client = null ) {
		$this->client = $client ? $client : new WP_MCP_AI_OpenAI_Client();
	}

	/**
	 * Create chat completion with rate limiting and token budget management.
	 *
	 * @param array $messages Array of messages.
	 * @param array $options  Request options.
	 *
	 * @return array|WP_Error Response or error.
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$model    = ! empty( $options['model'] ) ? $options['model'] : WP_MCP_AI_Admin_Settings::get_default_model();

		// Apply intelligent model routing if enabled.
		if ( ! isset( $options['disable_auto_routing'] ) || ! $options['disable_auto_routing'] ) {
			$model = WP_MCP_AI_Model_Selector::select_model( $messages, $options, $model );
			// Update options with selected model.
			$options['model'] = $model;
		}

		// Validate input token limit (12k max).
		$validation = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, $model );
		if ( is_wp_error( $validation ) ) {
			WP_MCP_AI_Logger::log_error(
				'Input token limit exceeded.',
				array(
					'model'      => $model,
					'error'      => $validation->get_error_message(),
					'error_data' => $validation->get_error_data(),
				)
			);
			return $validation;
		}

		// Set explicit max_tokens for output if not already set.
		if ( ! isset( $options['max_tokens'] ) ) {
			$options['max_tokens'] = $this->calculate_max_output_tokens( $messages, $model );
		}

		// Validate TPM (Tokens Per Minute) limit before making the request.
		$max_output_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 0;
		$tpm_validation    = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );
		if ( is_wp_error( $tpm_validation ) ) {
			WP_MCP_AI_Logger::log_error(
				'TPM limit validation failed.',
				array(
					'model'      => $model,
					'error'      => $tpm_validation->get_error_message(),
					'error_data' => $tpm_validation->get_error_data(),
				)
			);
			return $tpm_validation;
		}

		// Apply token budget optimization if requested.
		if ( ! empty( $options['optimize_tokens'] ) ) {
			$messages = $this->optimize_messages_for_budget( $messages, $model, $options );
		}

		// Check if we should recommend streaming.
		if ( ! isset( $options['stream'] ) && WP_MCP_AI_Token_Budget_Manager::should_stream( $messages, $model ) ) {
			WP_MCP_AI_Logger::log_event(
				'streaming_recommended',
				'Streaming is recommended for this request due to estimated response size.',
				array( 'model' => $model )
			);
		}

		// Prepare the callable for retry logic.
		$callable = array( $this->client, 'create_chat_completion' );

		// Execute with retry logic.
		$retry_options = array(
			'max_retries'   => $this->get_max_retries( $options ),
			'initial_delay' => $this->get_initial_delay( $options ),
			'max_delay'     => $this->get_max_delay( $options ),
		);

		$result = WP_MCP_AI_Rate_Limit_Manager::execute_with_retry(
			$callable,
			array( $messages, $options ),
			$retry_options
		);

		// Handle rate limit response.
		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();

			if ( is_array( $error_data ) && isset( $error_data['status'] ) && 429 === (int) $error_data['status'] ) {
				// Extract retry-after if available.
				$retry_after = $this->extract_retry_after( $error_data );

				if ( $retry_after > 0 ) {
					// Store rate limit state.
					$service_key = 'openai_' . $model;
					WP_MCP_AI_Rate_Limit_Manager::set_rate_limit( $service_key, time() + $retry_after );

					WP_MCP_AI_Logger::log_event(
						'rate_limit_stored',
						'Rate limit state stored for future requests.',
						array(
							'service'     => $service_key,
							'retry_after' => $retry_after,
						)
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Calculate appropriate max_tokens for output based on model and input.
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model identifier.
	 *
	 * @return int Maximum output tokens.
	 */
	protected function calculate_max_output_tokens( array $messages, $model ) {
		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( $model, $messages );

		// Reserve 20% of available tokens for output, but cap at 4096.
		$max_output = min( 4096, (int) ( $budget['available'] * 0.2 ) );

		// Ensure minimum of 512 tokens for output.
		$max_output = max( 512, $max_output );

		WP_MCP_AI_Logger::log_event(
			'max_tokens_calculated',
			'Calculated max_tokens for output.',
			array(
				'model'            => $model,
				'max_tokens'       => $max_output,
				'available_budget' => $budget['available'],
			)
		);

		return $max_output;
	}

	/**
	 * Optimize messages for token budget.
	 *
	 * @param array  $messages Messages array.
	 * @param string $model    Model identifier.
	 * @param array  $options  Request options.
	 *
	 * @return array Optimized messages.
	 */
	protected function optimize_messages_for_budget( array $messages, $model, array $options ) {
		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( $model, $messages );

		WP_MCP_AI_Logger::log_event(
			'token_budget_calculated',
			'Token budget calculated for request.',
			array(
				'model'     => $model,
				'used'      => $budget['used'],
				'available' => $budget['available'],
				'limit'     => $budget['limit'],
			)
		);

		// Check if truncation is needed.
		if ( $budget['used'] > $budget['limit'] * 0.9 ) {
			WP_MCP_AI_Logger::log_event(
				'token_budget_optimization',
				'Applying token budget optimization to messages.',
				array(
					'model'           => $model,
					'original_tokens' => $budget['used'],
					'target_limit'    => $budget['limit'],
				)
			);

			$optimization_options = array(
				'max_tokens'         => (int) ( $budget['limit'] * 0.85 ),
				'enable_truncation'  => true,
				'enable_compression' => ! empty( $options['compress_whitespace'] ),
			);

			$messages = WP_MCP_AI_Token_Budget_Manager::optimize_messages(
				$messages,
				$model,
				$optimization_options
			);
		}

		return $messages;
	}

	/**
	 * Extract retry-after value from error data.
	 *
	 * @param array $error_data Error data array.
	 *
	 * @return int Retry-after in seconds, or 0 if not found.
	 */
	protected function extract_retry_after( array $error_data ) {
		// Check for reset_seconds in rate limit details.
		if ( isset( $error_data['rate_limit_reset_seconds'] ) ) {
			return absint( $error_data['rate_limit_reset_seconds'] );
		}

		// Check for Retry-After header.
		if ( isset( $error_data['headers'] ) && is_array( $error_data['headers'] ) ) {
			foreach ( $error_data['headers'] as $key => $value ) {
				if ( strtolower( $key ) === 'retry-after' ) {
					return absint( $value );
				}
			}
		}

		return 0;
	}

	/**
	 * Get max retries from options or use default.
	 *
	 * @param array $options Request options.
	 *
	 * @return int Max retries.
	 */
	protected function get_max_retries( array $options ) {
		if ( isset( $options['max_retries'] ) ) {
			return absint( $options['max_retries'] );
		}

		/**
		 * Filter the maximum number of retry attempts.
		 *
		 * @param int   $max_retries Default maximum retries.
		 * @param array $options     Request options.
		 */
		return apply_filters( 'wp_mcp_ai_max_retries', WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_RETRIES, $options );
	}

	/**
	 * Get initial delay from options or use default.
	 *
	 * @param array $options Request options.
	 *
	 * @return int Initial delay in seconds.
	 */
	protected function get_initial_delay( array $options ) {
		if ( isset( $options['initial_delay'] ) ) {
			return absint( $options['initial_delay'] );
		}

		/**
		 * Filter the initial retry delay.
		 *
		 * @param int   $initial_delay Default initial delay.
		 * @param array $options       Request options.
		 */
		return apply_filters( 'wp_mcp_ai_initial_retry_delay', WP_MCP_AI_Rate_Limit_Manager::DEFAULT_INITIAL_DELAY, $options );
	}

	/**
	 * Get max delay from options or use default.
	 *
	 * @param array $options Request options.
	 *
	 * @return int Max delay in seconds.
	 */
	protected function get_max_delay( array $options ) {
		if ( isset( $options['max_delay'] ) ) {
			return absint( $options['max_delay'] );
		}

		/**
		 * Filter the maximum retry delay.
		 *
		 * @param int   $max_delay Default maximum delay.
		 * @param array $options   Request options.
		 */
		return apply_filters( 'wp_mcp_ai_max_retry_delay', WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_DELAY, $options );
	}

	/**
	 * Split a large document for processing.
	 *
	 * @param string $content    Document content.
	 * @param string $model      Model identifier.
	 * @param int    $chunk_size Optional chunk size in tokens.
	 * @param int    $overlap    Optional overlap in tokens.
	 *
	 * @return array Array of text chunks.
	 */
	public function split_document( $content, $model, $chunk_size = null, $overlap = 0 ) {
		if ( null === $chunk_size ) {
			// Use the recommended chunk size (6-8k tokens).
			$chunk_size = WP_MCP_AI_Token_Budget_Manager::get_recommended_chunk_size( $model );
		}

		if ( 0 === $overlap ) {
			// Use default overlap.
			$overlap = WP_MCP_AI_Token_Budget_Manager::DEFAULT_CHUNK_OVERLAP;
		}

		return WP_MCP_AI_Token_Budget_Manager::split_document( $content, $chunk_size, $overlap );
	}

	/**
	 * Check if a service is currently rate limited.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return bool True if rate limited.
	 */
	public function is_rate_limited( $model ) {
		$service_key = 'openai_' . sanitize_key( $model );
		return WP_MCP_AI_Rate_Limit_Manager::is_rate_limited( $service_key );
	}

	/**
	 * Get retry-after timestamp for a model.
	 *
	 * @param string $model Model identifier.
	 *
	 * @return int|null Unix timestamp or null.
	 */
	public function get_retry_after( $model ) {
		$service_key = 'openai_' . sanitize_key( $model );
		return WP_MCP_AI_Rate_Limit_Manager::get_retry_after( $service_key );
	}

	/**
	 * Forward other method calls to the underlying client.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 *
	 * @return mixed
	 */
	public function __call( $method, $args ) {
		if ( method_exists( $this->client, $method ) ) {
			return call_user_func_array( array( $this->client, $method ), $args );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		trigger_error( 'Call to undefined method ' . esc_html( __CLASS__ . '::' . $method ) . '()', E_USER_ERROR );
	}
}
