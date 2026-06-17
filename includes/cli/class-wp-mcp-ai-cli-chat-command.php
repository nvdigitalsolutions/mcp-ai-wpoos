<?php
/**
 * WP-CLI command for sending test chat messages to assistants.
 *
 * @package WP_MCP_AI
 * @since   1.1.30
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Send a one-shot chat message to an assistant via the language model router.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Chat_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Send a message to an AI assistant.
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : The message to send.
	 *
	 * [--assistant=<id>]
	 * : Assistant post ID (default: site default assistant).
	 *
	 * [--model=<model>]
	 * : Override the assistant's model.
	 *
	 * [--provider=<provider>]
	 * : Override the AI provider (openai, gemini, anthropic, etc.).
	 *
	 * [--temperature=<float>]
	 * : Model temperature (0.0–2.0).
	 *
	 * [--max-tokens=<number>]
	 * : Maximum output tokens.
	 *
	 * [--stream]
	 * : Stream the response token-by-token.
	 *
	 * [--format=<format>]
	 * : Output format for non-streaming mode (text, json).
	 * ---
	 * default: text
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai chat "What is the capital of France?"
	 *     $ wp mcp-ai chat "Explain recursion" --assistant=42 --stream
	 *     $ wp mcp-ai chat "Write a haiku" --provider=gemini --model=gemini-3.5-flash
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$message      = sanitize_textarea_field( (string) ( $args[0] ?? '' ) );
		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : $this->default_assistant_id();
		$model        = sanitize_text_field( (string) ( $assoc_args['model'] ?? '' ) );
		$provider     = sanitize_key( (string) ( $assoc_args['provider'] ?? '' ) );
		$temperature  = isset( $assoc_args['temperature'] ) ? (float) $assoc_args['temperature'] : null;
		$max_tokens   = isset( $assoc_args['max-tokens'] ) ? absint( $assoc_args['max-tokens'] ) : null;
		$stream       = WP_CLI\Utils\get_flag_value( $assoc_args, 'stream', false );
		$format       = $assoc_args['format'] ?? 'text';

		if ( '' === $message ) {
			$this->error( __( 'Message is required.', 'mcp-ai-wpoos' ) );
		}

		// Resolve assistant config.
		$assistant = $this->get_assistant_config( $assistant_id );
		if ( is_wp_error( $assistant ) ) {
			$this->error( $assistant->get_error_message() );
		}

		// Build messages payload.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $assistant['system_prompt'] ?? '',
			),
			array(
				'role'    => 'user',
				'content' => $message,
			),
		);

		// Build options.
		$options = array();
		if ( null !== $temperature ) {
			$options['temperature'] = $temperature;
		}
		if ( null !== $max_tokens ) {
			$options['max_completion_tokens'] = $max_tokens;
		}
		if ( '' !== $model ) {
			$options['model'] = $model;
		}
		if ( '' !== $provider ) {
			$options['provider'] = $provider;
		}

		// Get the model router.
		if ( ! class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$this->error( __( 'Language model router not available.', 'mcp-ai-wpoos' ) );
		}

		$router = new WP_MCP_AI_Language_Model_Router();

		WP_CLI::log(
			sprintf(
				/* translators: %d: assistant post ID */
				__( 'Sending to assistant #%d…', 'mcp-ai-wpoos' ),
				$assistant_id
			)
		);

		if ( $stream ) {
			$this->stream_response( $router, $messages, $options, $assistant );
		} else {
			$this->render_response( $router, $messages, $options, $assistant, $format );
		}
	}

	/**
	 * Stream the response token-by-token.
	 *
	 * @param object $router    Language model router.
	 * @param array  $messages  Chat messages.
	 * @param array  $options   Model options.
	 * @param array  $assistant Assistant config.
	 */
	private function stream_response( $router, $messages, $options, $assistant ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $assistant used for context
		$options['stream'] = true;

		$result = $router->route_to_provider( $messages, $options );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		// If the result has a stream callback, use it.
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			WP_CLI::log( $result['choices'][0]['message']['content'] );
		} elseif ( isset( $result['content'] ) ) {
			WP_CLI::log( $result['content'] );
		} else {
			$this->warning( __( 'No content in response.', 'mcp-ai-wpoos' ) );
		}
	}

	/**
	 * Render a non-streaming response.
	 *
	 * @param object $router    Language model router.
	 * @param array  $messages  Chat messages.
	 * @param array  $options   Model options.
	 * @param array  $assistant Assistant config.
	 * @param string $format    Output format.
	 */
	private function render_response( $router, $messages, $options, $assistant, $format ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $assistant used for context
		$start   = microtime( true );
		$result  = $router->route_to_provider( $messages, $options );
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$content = '';

		// Extract content from various response shapes.
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			$content = $result['choices'][0]['message']['content'];
		} elseif ( isset( $result['content'][0]['text'] ) ) {
			$content = $result['content'][0]['text'];
		} elseif ( isset( $result['content'] ) && is_string( $result['content'] ) ) {
			$content = $result['content'];
		}

		if ( '' === $content ) {
			$this->warning( __( 'No content in response. Raw result:', 'mcp-ai-wpoos' ) );
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'json' === $format ) {
			$output = array(
				'content'    => $content,
				'model'      => $result['model'] ?? '',
				'provider'   => $options['provider'] ?? $assistant['provider'] ?? '',
				'usage'      => $result['usage'] ?? null,
				'elapsed_ms' => $elapsed,
			);
			WP_CLI::log( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( $content );
			WP_CLI::log( '' );
			if ( isset( $result['model'] ) ) {
				WP_CLI::log(
					WP_CLI::colorize(
						'%8' . sprintf(
							/* translators: %1$s: model name, %2$s: elapsed time in ms */
							__( 'Model: %1$s | Time: %2$s ms', 'mcp-ai-wpoos' ),
							$result['model'],
							$elapsed
						) . '%n'
					)
				);
			}
			if ( isset( $result['usage'] ) ) {
				$usage = $result['usage'];
				WP_CLI::log(
					WP_CLI::colorize(
						'%8' . sprintf(
							/* translators: %1$d: prompt tokens, %2$d: completion tokens */
							__( 'Tokens: %1$d in / %2$d out', 'mcp-ai-wpoos' ),
							$usage['prompt_tokens'] ?? 0,
							$usage['completion_tokens'] ?? 0
						) . '%n'
					)
				);
			}
		}
	}

	/**
	 * Get assistant configuration.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array|WP_Error
	 */
	private function get_assistant_config( $assistant_id ) {
		if ( 0 === $assistant_id ) {
			// Fallback to a bare config when no assistant is configured.
			return array(
				'system_prompt' => __( 'You are a helpful AI assistant.', 'mcp-ai-wpoos' ),
				'provider'      => '',
				'model'         => '',
			);
		}

		$post = get_post( $assistant_id );
		if ( ! $post || 'mcp_ai_assistant' !== $post->post_type ) {
			return new WP_Error(
				'assistant_not_found',
				sprintf(
					/* translators: %d: assistant post ID */
					__( 'Assistant #%d not found.', 'mcp-ai-wpoos' ),
					$assistant_id
				)
			);
		}

		return array(
			'system_prompt' => get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true ) ? get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true ) : '',
			'provider'      => get_post_meta( $assistant_id, '_wp_mcp_ai_provider', true ) ? get_post_meta( $assistant_id, '_wp_mcp_ai_provider', true ) : '',
			'model'         => get_post_meta( $assistant_id, '_wp_mcp_ai_model', true ) ? get_post_meta( $assistant_id, '_wp_mcp_ai_model', true ) : '',
			'temperature'   => (float) ( get_post_meta( $assistant_id, '_wp_mcp_ai_temperature', true ) ? get_post_meta( $assistant_id, '_wp_mcp_ai_temperature', true ) : 1.0 ),
		);
	}

	/**
	 * Get the default assistant ID from plugin settings.
	 *
	 * @return int
	 */
	private function default_assistant_id() {
		if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return absint( WP_MCP_AI_Settings_Registry::get_setting( 'default_assistant', 0 ) );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return absint( $settings['default_assistant'] ?? 0 );
	}
}

WP_CLI::add_command( 'mcp-ai chat', 'WP_MCP_AI_CLI_Chat_Command' );
