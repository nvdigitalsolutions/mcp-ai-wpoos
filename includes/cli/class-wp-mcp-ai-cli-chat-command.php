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
	 * [--assistant-id=<id>]
	 * : Alias for --assistant.
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
	 * : Stream the response token-by-token. Uses native provider streaming when
	 * cURL is available and the provider supports it; otherwise simulates
	 * streaming in small chunks.
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
	 *     $ wp mcp-ai chat "Write a haiku" --provider=gemini --model=gemini-3.6-flash
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$message      = sanitize_textarea_field( (string) ( $args[0] ?? '' ) );
		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : $this->default_assistant_id();

		// Accept --assistant-id as an alias for --assistant (documented in README).
		if ( 0 === $assistant_id && isset( $assoc_args['assistant-id'] ) ) {
			$assistant_id = absint( $assoc_args['assistant-id'] );
		}

		$model       = sanitize_text_field( (string) ( $assoc_args['model'] ?? '' ) );
		$provider    = sanitize_key( (string) ( $assoc_args['provider'] ?? '' ) );
		$temperature = isset( $assoc_args['temperature'] ) ? (float) $assoc_args['temperature'] : null;
		$max_tokens  = isset( $assoc_args['max-tokens'] ) ? absint( $assoc_args['max-tokens'] ) : null;
		$stream      = WP_CLI\Utils\get_flag_value( $assoc_args, 'stream', false );
		$format      = $assoc_args['format'] ?? 'text';

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

		$router = $this->get_model_router();

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
	 * Uses native provider streaming (raw cURL, real-time token delivery)
	 * when the resolved provider supports it; otherwise falls back to
	 * simulated chunked output, matching the legacy browser chat behaviour.
	 *
	 * @param object $router    Language model router.
	 * @param array  $messages  Chat messages.
	 * @param array  $options   Model options.
	 * @param array  $assistant Assistant config.
	 */
	private function stream_response( $router, $messages, $options, $assistant ) {
		$printed_chars = 0;

		if ( $this->native_streaming_available( $options, $assistant ) ) {
			$options['stream']          = true;
			$options['stream_callback'] = function ( $chunk ) use ( &$printed_chars ) {
				$delta = isset( $chunk['choices'][0]['delta']['content'] ) ? $chunk['choices'][0]['delta']['content'] : '';
				if ( is_string( $delta ) && '' !== $delta ) {
					$this->write_stream_chunk( $delta );
					$printed_chars += strlen( $delta );
				}
			};
		} else {
			// Never send stream:true without a real-time callback: the provider
			// clients buffer SSE bodies through wp_remote_post(), whose JSON
			// response parsers cannot decode a streamed payload.
			unset( $options['stream'] );
		}

		$result = $router->create_chat_completion( $messages, $options );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		if ( $printed_chars > 0 ) {
			// Native streaming already printed the tokens; close the line.
			$this->write_stream_chunk( PHP_EOL );
			return;
		}

		// Fallback: simulate streaming over the buffered response.
		$content = $this->extract_response_content( $result );
		if ( '' === $content ) {
			$this->warning( __( 'No content in response.', 'mcp-ai-wpoos' ) );
			return;
		}

		$this->simulate_stream_output( $content );
	}

	/**
	 * Whether native provider streaming is available for this request.
	 *
	 * Mirrors the REST chat gating: real-time streaming requires cURL plus a
	 * provider whose client implements the raw-cURL SSE path, and respects the
	 * shared wp_mcp_ai_disable_native_streaming and
	 * wp_mcp_ai_native_streaming_providers filters (including the Disable
	 * Native Streaming admin setting).
	 *
	 * @param array $options   Model options.
	 * @param array $assistant Assistant config.
	 * @return bool
	 */
	private function native_streaming_available( array $options, array $assistant ) {
		if ( ! function_exists( 'curl_init' ) || (bool) apply_filters( 'wp_mcp_ai_disable_native_streaming', false ) ) {
			return false;
		}

		$native_providers = apply_filters(
			'wp_mcp_ai_native_streaming_providers',
			array( 'lm_studio', 'deepseek', 'openai', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'nvidia', 'huggingface' )
		);

		$provider = isset( $options['provider'] ) && '' !== $options['provider'] ? $options['provider'] : ( isset( $assistant['provider'] ) ? $assistant['provider'] : '' );
		$provider = sanitize_key( $provider );

		return '' !== $provider && in_array( $provider, $native_providers, true );
	}

	/**
	 * Extract the text content from a chat completion result.
	 *
	 * Handles both the OpenAI-style string content and provider content that
	 * arrives as an array of text parts (e.g. Gemini's normalized envelope).
	 *
	 * @param array $result Chat completion result.
	 * @return string
	 */
	private function extract_response_content( array $result ) {
		$content = isset( $result['choices'][0]['message']['content'] ) ? $result['choices'][0]['message']['content'] : null;

		if ( is_string( $content ) ) {
			return $content;
		}

		if ( is_array( $content ) ) {
			$texts = array();
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$texts[] = $part['text'];
				}
			}
			if ( ! empty( $texts ) ) {
				return implode( '', $texts );
			}
		}

		if ( isset( $result['content'][0]['text'] ) && is_string( $result['content'][0]['text'] ) ) {
			return $result['content'][0]['text'];
		}

		if ( isset( $result['content'] ) && is_string( $result['content'] ) ) {
			return $result['content'];
		}

		return '';
	}

	/**
	 * Write a raw chunk to STDOUT without a trailing newline.
	 *
	 * @param string $text Text chunk.
	 */
	private function write_stream_chunk( $text ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CLI stream output; HTML escaping does not apply to terminal output.
		fwrite( STDOUT, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct STDOUT write required for token streaming; WP_Filesystem does not apply to CLI streams.

		if ( function_exists( 'fflush' ) ) {
			fflush( STDOUT );
		}
	}

	/**
	 * Simulate token streaming by printing buffered text in small chunks.
	 *
	 * Mirrors the legacy browser chat fallback (50 characters per chunk with a
	 * 10ms pause between chunks, as defined by the REST handler constants).
	 *
	 * @param string $text Full response text.
	 */
	private function simulate_stream_output( $text ) {
		$chunk_size = 50;
		$delay_us   = 10000;
		$can_sleep  = function_exists( 'usleep' );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			$len = mb_strlen( $text );
			for ( $i = 0; $i < $len; $i += $chunk_size ) {
				$this->write_stream_chunk( mb_substr( $text, $i, $chunk_size ) );
				if ( $can_sleep ) {
					usleep( $delay_us );
				}
			}
		} else {
			foreach ( str_split( $text, $chunk_size ) as $chunk ) {
				$this->write_stream_chunk( $chunk );
				if ( $can_sleep ) {
					usleep( $delay_us );
				}
			}
		}

		$this->write_stream_chunk( PHP_EOL );
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
		$result  = $router->create_chat_completion( $messages, $options );
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

	/**
	 * Get the language model router.
	 *
	 * Prefers the DI container; falls back to direct construction with the
	 * minimum required clients (same pattern as the REST fallback).
	 *
	 * @return WP_MCP_AI_Language_Model_Router
	 */
	private function get_model_router() {
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container && $container->has( 'router' ) ) {
				return $container->get( 'router' );
			}
		}

		return new WP_MCP_AI_Language_Model_Router(
			new WP_MCP_AI_OpenAI_Client(),
			new WP_MCP_AI_Gemini_Client(),
			new WP_MCP_AI_Ollama_Client()
		);
	}
}

WP_CLI::add_command( 'mcp-ai chat', 'WP_MCP_AI_CLI_Chat_Command' );
