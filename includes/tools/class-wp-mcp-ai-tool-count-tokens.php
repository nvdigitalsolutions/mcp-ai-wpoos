<?php
/**
 * Tool for estimating token counts for text and messages.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimates token counts using the plugin's token budget manager.
 *
 * Note: OpenAI does not provide a dedicated token counting API endpoint.
 * This tool uses a heuristic estimation (approximately 4 characters per token)
 * for planning and budgeting purposes. For production-critical token counting,
 * consider using OpenAI's tiktoken library on the client side.
 */
class WP_MCP_AI_Tool_Count_Tokens implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Number of tokens added per message for formatting (im_start, role, im_end).
	 */
	const TOKENS_PER_MESSAGE_OVERHEAD = 3;

	/**
	 * Number of tokens for priming the assistant's reply.
	 */
	const ASSISTANT_REPLY_PRIMING_TOKENS = 3;

	/**
	 * Message formatting overhead tokens for heuristic method.
	 */
	const HEURISTIC_MESSAGE_OVERHEAD_TOKENS = 4;

	/**
	 * Default safety margin percentage (10%).
	 */
	const DEFAULT_SAFETY_MARGIN = 0.1;

	/**
	 * Tiktoken fully qualified class name.
	 */
	const TIKTOKEN_CLASS = 'Rahul900day\\Tiktoken\\Tiktoken';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'count_tokens';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Count Tokens', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Estimates token counts for text or messages. Supports two methods: accurate tiktoken tokenizer (default) or fast heuristic estimation (~4 chars/token). Useful for planning requests and managing token budgets.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'     => array(
					'type'        => 'string',
					'description' => __( 'Plain text to count tokens for. Mutually exclusive with messages parameter.', 'wp-mcp-ai' ),
				),
				'messages' => array(
					'type'        => 'array',
					'description' => __( 'Array of chat messages to count tokens for. Each message should have role and content properties. Mutually exclusive with text parameter.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'role'    => array(
								'type'        => 'string',
								'description' => __( 'Message role (system, user, assistant, tool).', 'wp-mcp-ai' ),
							),
							'content' => array(
								'type'        => 'string',
								'description' => __( 'Message content text.', 'wp-mcp-ai' ),
							),
						),
					),
				),
				'model'    => array(
					'type'        => 'string',
					'description' => __( 'Optional model identifier to get context limit information (e.g., gpt-4o, gpt-4o-mini, gemini-1.5-pro).', 'wp-mcp-ai' ),
				),
				'method'   => array(
					'type'        => 'string',
					'enum'        => array( 'heuristic', 'tiktoken', 'auto' ),
					'description' => __( 'Token counting method: "heuristic" uses ~4 chars/token estimate (fast), "tiktoken" uses OpenAI\'s tokenizer (accurate), "auto" tries tiktoken and falls back to heuristic. Default: auto.', 'wp-mcp-ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads/analyzes data, does not modify state.
			'local-only',           // No external API calls - uses local heuristics and bundled tiktoken encodings.
			'requires-capability',  // Requires user to be logged in.
			'cacheable',            // Results are deterministic for same inputs with same method.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Basic authentication check - any logged-in user can estimate tokens.
		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_unauthorized',
				__( 'You must be logged in to use the token counting tool.', 'wp-mcp-ai' )
			);
		}

		// Validate that either text or messages is provided, but not both.
		$has_text     = isset( $arguments['text'] ) && is_string( $arguments['text'] );
		$has_messages = isset( $arguments['messages'] ) && is_array( $arguments['messages'] );

		if ( ! $has_text && ! $has_messages ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Either text or messages parameter must be provided.', 'wp-mcp-ai' )
			);
		}

		if ( $has_text && $has_messages ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Only one of text or messages parameter should be provided, not both.', 'wp-mcp-ai' )
			);
		}

		// Token budget manager is now loaded via services-init.php - no need to require it here.
		// The class is available globally after services-init.php is loaded in mcp-ai-wpoos.php.

		// Determine counting method.
		$method = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'auto';
		if ( ! in_array( $method, array( 'heuristic', 'tiktoken', 'auto' ), true ) ) {
			$method = 'auto';
		}

		$estimated_tokens = 0;
		$details          = array();
		$counting_method  = 'heuristic'; // Track which method was actually used.

		// Count tokens for plain text.
		if ( $has_text ) {
			$text = sanitize_textarea_field( $arguments['text'] );

			// Try tiktoken if requested or in auto mode.
			if ( 'heuristic' !== $method ) {
				$tiktoken_result = $this->count_tokens_with_tiktoken( $text, $arguments );
				if ( ! is_wp_error( $tiktoken_result ) ) {
					$estimated_tokens = $tiktoken_result['token_count'];
					$counting_method  = 'tiktoken';
				} elseif ( 'tiktoken' === $method ) {
					// Tiktoken was explicitly requested but failed.
					return $tiktoken_result;
				}
				// If auto mode and tiktoken failed, fall through to heuristic.
			}

			// Use heuristic if tiktoken wasn't used or failed in auto mode.
			if ( 'heuristic' === $counting_method ) {
				$estimated_tokens = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $text );
			}

			$details = array(
				'type'        => 'text',
				'text_length' => function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ),
			);
		}

		// Count tokens for messages array.
		if ( $has_messages ) {
			$messages = $arguments['messages'];

			// Try tiktoken if requested or in auto mode.
			if ( 'heuristic' !== $method ) {
				$tiktoken_result = $this->count_tokens_with_tiktoken( $messages, $arguments );
				if ( ! is_wp_error( $tiktoken_result ) ) {
					$estimated_tokens = $tiktoken_result['token_count'];
					$counting_method  = 'tiktoken';
					$details          = array(
						'type'          => 'messages',
						'message_count' => $tiktoken_result['message_count'],
					);
				} elseif ( 'tiktoken' === $method ) {
					// Tiktoken was explicitly requested but failed.
					return $tiktoken_result;
				}
				// If auto mode and tiktoken failed, fall through to heuristic.
			}

			// Use heuristic if tiktoken wasn't used or failed in auto mode.
			if ( 'heuristic' === $counting_method ) {
				// Validate and sanitize messages.
				$sanitized_messages = array();
				foreach ( $messages as $message ) {
					if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
						continue;
					}

					$role    = sanitize_text_field( $message['role'] );
					$content = isset( $message['content'] ) ? sanitize_textarea_field( $message['content'] ) : '';

					$sanitized_messages[] = array(
						'role'    => $role,
						'content' => $content,
					);

					// Estimate tokens for this message.
					$estimated_tokens += WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $role );
					$estimated_tokens += WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $content );
					// Add message formatting overhead.
					$estimated_tokens += self::HEURISTIC_MESSAGE_OVERHEAD_TOKENS;
				}

				$details = array(
					'type'          => 'messages',
					'message_count' => count( $sanitized_messages ),
				);
			}
		}

		// Build the response.
		$summary_parts = array(
			sprintf( __( 'Estimated tokens: %d', 'wp-mcp-ai' ), $estimated_tokens ),
		);
		if ( $has_messages ) {
			$summary_parts[] = sprintf( __( '(%d messages)', 'wp-mcp-ai' ), $details['message_count'] );
		}

		$response = array(
			'summary'          => implode( ' ', $summary_parts ),
			'estimated_tokens' => $estimated_tokens,
			'counting_method'  => $counting_method,
			'details'          => $details,
		);

		// Add model information if requested.
		if ( isset( $arguments['model'] ) && is_string( $arguments['model'] ) ) {
			$model       = sanitize_text_field( $arguments['model'] );
			$model_limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( $model );
			$tpm_limit   = WP_MCP_AI_Token_Budget_Manager::get_model_tpm_limit( $model );
			$rpm_limit   = WP_MCP_AI_Token_Budget_Manager::get_model_rpm_limit( $model );

			$response['model_info'] = array(
				'model'                => $model,
				'context_limit_tokens' => $model_limit,
				'tokens_per_minute'    => $tpm_limit,
				'requests_per_minute'  => $rpm_limit,
				'usage_percentage'     => $model_limit > 0 ? round( ( $estimated_tokens / $model_limit ) * 100, 2 ) : 0,
			);

			// Add budget recommendations.
			$safety_margin           = self::DEFAULT_SAFETY_MARGIN;
			$safe_limit              = (int) ( $model_limit * ( 1 - $safety_margin ) );
			$remaining_tokens        = max( 0, $safe_limit - $estimated_tokens );
			$response['budget_info'] = array(
				'safe_limit_tokens'  => $safe_limit,
				'remaining_tokens'   => $remaining_tokens,
				'exceeds_safe_limit' => $estimated_tokens > $safe_limit,
				'recommendation'     => $estimated_tokens > $safe_limit
					? __( 'Token count exceeds safe limit. Consider truncating messages or switching to a model with a larger context window.', 'wp-mcp-ai' )
					: __( 'Token count is within safe limits.', 'wp-mcp-ai' ),
			);
		}

		// Add disclaimer about estimation accuracy.
		if ( 'heuristic' === $counting_method ) {
			$response['disclaimer'] = __(
				'This is a heuristic estimation (~4 chars per token). For more accurate counts, use method="tiktoken" or ensure the tiktoken-php library is installed.',
				'wp-mcp-ai'
			);
		} else {
			$response['disclaimer'] = __(
				'Token count calculated using OpenAI\'s tiktoken tokenizer for accurate results.',
				'wp-mcp-ai'
			);
		}

		return $response;
	}

	/**
	 * Count tokens using the tiktoken library.
	 *
	 * @param string|array $input      Text string or messages array.
	 * @param array        $arguments  Tool arguments (may include model).
	 * @return array|WP_Error Array with token_count and message_count, or WP_Error on failure.
	 */
	protected function count_tokens_with_tiktoken( $input, array $arguments = array() ) {
		// Check if tiktoken-php is available.
		if ( ! class_exists( self::TIKTOKEN_CLASS ) ) {
			return new WP_Error(
				'wp_mcp_ai_tiktoken_unavailable',
				__( 'The tiktoken-php library is not installed. Run "composer install" to enable accurate token counting.', 'wp-mcp-ai' )
			);
		}

		try {
			// Determine the encoding to use based on model.
			$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gpt-4o';

			// Get the appropriate encoder for the model.
			$encoder = $this->get_tiktoken_encoder_for_model( $model );

			if ( is_string( $input ) ) {
				// Simple text counting.
				$tokens = $encoder->encode( $input );
				return array(
					'token_count'   => count( $tokens ),
					'message_count' => 0,
				);
			} elseif ( is_array( $input ) ) {
				// Message array counting - need to account for message formatting.
				$total_tokens  = 0;
				$message_count = 0;

				foreach ( $input as $message ) {
					if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
						continue;
					}

					++$message_count;
					// Add overhead for message formatting.
					$total_tokens += self::TOKENS_PER_MESSAGE_OVERHEAD;

					// Count role tokens.
					if ( isset( $message['role'] ) ) {
						$role_tokens   = $encoder->encode( $message['role'] );
						$total_tokens += count( $role_tokens );
					}

					// Count content tokens.
					if ( isset( $message['content'] ) ) {
						$content_tokens = $encoder->encode( $message['content'] );
						$total_tokens  += count( $content_tokens );
					}

					// Count name tokens if present.
					if ( isset( $message['name'] ) ) {
						$name_tokens   = $encoder->encode( $message['name'] );
						$total_tokens += count( $name_tokens );
						--$total_tokens; // Role is omitted if name is present.
					}
				}

				// Add tokens for priming the assistant's reply.
				$total_tokens += self::ASSISTANT_REPLY_PRIMING_TOKENS;

				return array(
					'token_count'   => $total_tokens,
					'message_count' => $message_count,
				);
			}

			return new WP_Error(
				'wp_mcp_ai_invalid_input',
				__( 'Invalid input type for tiktoken counting.', 'wp-mcp-ai' )
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_tiktoken_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Tiktoken error: %s', 'wp-mcp-ai' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Get the appropriate tiktoken encoder for a model.
	 *
	 * @param string $model Model identifier.
	 * @return object Tiktoken encoder instance.
	 */
	protected function get_tiktoken_encoder_for_model( $model ) {
		// Map common models to their encodings.
		// Most modern models use cl100k_base encoding.
		$encoding_map = array(
			'gpt-4'         => 'cl100k_base',
			'gpt-4o'        => 'o200k_base',
			'gpt-4o-mini'   => 'o200k_base',
			'gpt-3.5-turbo' => 'cl100k_base',
			'text-davinci'  => 'p50k_base',
		);

		// Check for exact match or prefix match.
		$encoding = 'cl100k_base'; // Default encoding.
		foreach ( $encoding_map as $model_prefix => $enc ) {
			if ( 0 === strpos( $model, $model_prefix ) ) {
				$encoding = $enc;
				break;
			}
		}

		// Get encoder for the encoding type.
		return \Rahul900day\Tiktoken\Tiktoken::getEncoding( $encoding );
	}
}
