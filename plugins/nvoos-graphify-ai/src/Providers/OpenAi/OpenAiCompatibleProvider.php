<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Providers\OpenAi;

use NvoosGraphifyAi\Contracts\ProviderClient;
use NvoosGraphifyAi\Settings;

/**
 * Base class for OpenAI-compatible provider APIs.
 *
 * Many AI providers (DeepSeek, OpenRouter, GitHub Models, LM Studio, etc.)
 * expose an OpenAI-compatible REST API. This class handles the common
 * HTTP transport and response parsing. Subclasses only need to define
 * the base URL, default model, and provider slug.
 *
 * @since 1.0.0
 */
abstract class OpenAiCompatibleProvider implements ProviderClient {

	/** @return string API base URL (e.g. 'https://api.deepseek.com'). */
	abstract protected function apiBase(): string;

	/** @return string Default model (e.g. 'deepseek-chat'). */
	abstract protected function defaultModel(): string;

	/**
	 * Send a chat-completion request.
	 *
	 * @param array $messages Conversation messages in OpenAI format.
	 * @param array $options  Options (model, temperature, etc.).
	 * @return array|\WP_Error
	 */
	public function chat( array $messages, array $options = array() ) {
		$apiKey = Settings::getApiKey( $this->getProviderSlug() );
		if ( empty( $apiKey ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_missing_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'API key not configured for %s.', 'nvoos-graphify-ai' ),
					$this->getProviderSlug()
				)
			);
		}

		$model = $options['model'] ?? $this->defaultModel();

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ),
			'max_tokens'  => $options['max_tokens'] ?? (int) Settings::get( 'ai_max_tokens', 4096 ),
		);

		if ( ! empty( $options['tools'] ) ) {
			$body['tools'] = $options['tools'];
		}

		$response = wp_remote_post(
			$this->apiBase() . '/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			)
		);

		return $this->parseResponse( $response, $model );
	}

	/**
	 * Stream a chat-completion response via callback.
	 *
	 * @param array         $messages Conversation messages.
	 * @param array         $options  Provider-specific options.
	 * @param callable|null $callback Called for each streamed token/chunk.
	 * @return array|\WP_Error
	 */
	public function stream( array $messages, array $options = array(), ?callable $callback = null ) {
		$apiKey = Settings::getApiKey( $this->getProviderSlug() );
		if ( empty( $apiKey ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_missing_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'API key not configured for %s.', 'nvoos-graphify-ai' ),
					$this->getProviderSlug()
				)
			);
		}

		$model = $options['model'] ?? $this->defaultModel();

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ),
			'max_tokens'  => $options['max_tokens'] ?? (int) Settings::get( 'ai_max_tokens', 4096 ),
			'stream'      => true,
		);

		if ( ! empty( $options['tools'] ) ) {
			$body['tools'] = $options['tools'];
		}

		$response = wp_remote_post(
			$this->apiBase() . '/chat/completions',
			array(
				'headers'  => array(
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'     => wp_json_encode( $body ),
				'timeout'  => 120,
				'stream'   => true,
				'filename' => null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content      = '';
		$usage        = array();
		$toolCalls    = array();
		$finishReason = '';

		$rawBody = wp_remote_retrieve_body( $response );
		$lines   = explode( "\n", $rawBody );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) || 0 !== strpos( $line, 'data: ' ) ) {
				continue;
			}

			$json = substr( $line, 6 );
			if ( '[DONE]' === $json ) {
				if ( is_callable( $callback ) ) {
					$callback( '', true );
				}
				continue;
			}

			$chunk = json_decode( $json, true );
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$delta = $chunk['choices'][0]['delta'] ?? array();
			$token = $delta['content'] ?? '';

			if ( ! empty( $token ) ) {
				$content .= $token;
			}

			if ( ! empty( $delta['tool_calls'] ) ) {
				foreach ( $delta['tool_calls'] as $tc ) {
					$idx = $tc['index'] ?? 0;
					if ( ! isset( $toolCalls[ $idx ] ) ) {
						$toolCalls[ $idx ] = array(
							'id'       => $tc['id'] ?? '',
							'type'     => 'function',
							'function' => array(
								'name'      => $tc['function']['name'] ?? '',
								'arguments' => '',
							),
						);
					}
					if ( ! empty( $tc['function']['name'] ) ) {
						$toolCalls[ $idx ]['function']['name'] = $tc['function']['name'];
					}
					if ( ! empty( $tc['function']['arguments'] ) ) {
						$toolCalls[ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
					}
				}
			}

			if ( isset( $chunk['usage'] ) ) {
				$usage = $chunk['usage'];
			}

			if ( isset( $chunk['choices'][0]['finish_reason'] ) ) {
				$finishReason = $chunk['choices'][0]['finish_reason'];
			}

			if ( is_callable( $callback ) ) {
				$callback( $token, false );
			}
		}

		if ( is_callable( $callback ) ) {
			$callback( '', true );
		}

		return array(
			'content'       => $content,
			'usage'         => $usage,
			'model'         => $model,
			'finish_reason' => $finishReason,
			'tool_calls'    => array_values( $toolCalls ),
		);
	}

	/**
	 * Fetch available models (not supported by all providers).
	 *
	 * @return array|\WP_Error
	 */
	public function listModels() {
		$apiKey = Settings::getApiKey( $this->getProviderSlug() );
		if ( empty( $apiKey ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_missing_key',
				__( 'API key not configured.', 'nvoos-graphify-ai' )
			);
		}

		$response = wp_remote_get(
			$this->apiBase() . '/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $apiKey,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
			return array();
		}

		return array_map(
			static fn( array $m ): string => $m['id'] ?? '',
			$data['data']
		);
	}

	/**
	 * Parse a non-streaming chat completion response.
	 *
	 * @param array|\WP_Error $response The HTTP response.
	 * @param string          $model    The requested model.
	 * @return array|\WP_Error
	 */
	protected function parseResponse( $response, string $model ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			$errorMessage = $data['error']['message'] ?? sprintf( 'HTTP %d', $status );
			return new \WP_Error(
				'nvoos_graphify_ai_provider_error',
				sprintf(
					/* translators: 1: provider slug, 2: error message */
					__( '%1$s API error: %2$s', 'nvoos-graphify-ai' ),
					$this->getProviderSlug(),
					$errorMessage
				)
			);
		}

		$choice = $data['choices'][0] ?? array();
		$msg    = $choice['message'] ?? array();

		return array(
			'content'       => $msg['content'] ?? '',
			'usage'         => $data['usage'] ?? array(),
			'model'         => $data['model'] ?? $model,
			'finish_reason' => $choice['finish_reason'] ?? null,
			'tool_calls'    => $msg['tool_calls'] ?? array(),
			'raw'           => $data,
		);
	}
}
