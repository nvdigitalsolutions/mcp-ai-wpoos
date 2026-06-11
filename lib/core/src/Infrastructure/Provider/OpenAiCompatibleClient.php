<?php
/**
 * OpenAI-compatible provider client base.
 *
 * Many AI providers (DeepSeek, OpenRouter, Kimi, DigitalOcean, NVIDIA NIM,
 * Cloudflare, HuggingFace, Ollama, LM Studio) expose OpenAI-compatible
 * `/chat/completions` endpoints. This base class eliminates duplication
 * across those providers — each only needs to set its slug and default URL.
 *
 * Providers that diverge from the OpenAI schema (Gemini, Anthropic)
 * extend AbstractProviderClient directly.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

/**
 * Base for providers whose API is compatible with OpenAI's /v1/chat/completions.
 */
abstract class OpenAiCompatibleClient extends AbstractProviderClient {

	/**
	 * Whether this provider requires an API key.
	 *
	 * Local providers (Ollama, LM Studio) override to return false.
	 */
	protected function requiresApiKey(): bool {
		return true;
	}

	public function chat( array $messages, array $options = array() ): mixed {
		$apiKey = $this->getApiKey();

		if ( $this->requiresApiKey() && '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$model   = $this->resolveModel( $options );
		$baseUrl = $this->getBaseUrl();

		$payload = array(
			'model'    => $model,
			'messages' => $messages,
		);

		if ( isset( $options['temperature'] ) ) {
			$payload['temperature'] = (float) $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$payload['max_tokens'] = (int) $options['max_tokens'];
		}
		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = $options['tools'];
		}
		if ( ! empty( $options['tool_choice'] ) ) {
			$payload['tool_choice'] = $options['tool_choice'];
		}
		if ( isset( $options['top_p'] ) ) {
			$payload['top_p'] = (float) $options['top_p'];
		}
		if ( ! empty( $options['stream'] ) ) {
			$payload['stream'] = (bool) $options['stream'];
		}

		// Pre-flight context-window validation.
		$preflight = $this->validateContextWindow( $payload, $model );
		if ( null !== $preflight && ! empty( $preflight['data']['warning'] ) ) {
			// Soft warning — log but do not block.
			// Platforms may hook here for observability.
		}
		if ( null !== $preflight && empty( $preflight['data']['warning'] ) ) {
			return $this->contextWindowError( $preflight );
		}

		try {
			$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return $this->errors->create(
				'json_encode_failed',
				'Failed to encode chat request payload.',
				array( 'error' => $e->getMessage() ),
			);
		}

		$headers = $this->buildAuthHeaders( $apiKey );

		try {
			$response   = $this->http->send( 'POST', $baseUrl . '/chat/completions', $headers, $body );
			$body       = $response->body;
			$statusCode = $response->statusCode;

			if ( $statusCode >= 400 ) {
				return $this->parseError( $statusCode, $body );
			}

			$data = \json_decode( $body, true );

			return is_array( $data ) ? $data : $this->errors->create(
				'invalid_response',
				'Provider returned an unexpected response format.',
				array( 'raw' => $body ),
			);

		} catch ( \Exception $e ) {
			return $this->errors->create(
				'http_request_failed',
				"API request failed: {$e->getMessage()}",
			);
		}
	}

	public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
		$apiKey = $this->getApiKey();

		if ( $this->requiresApiKey() && '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$model   = $this->resolveModel( $options );
		$baseUrl = $this->getBaseUrl();

		$payload = array(
			'model'    => $model,
			'messages' => $messages,
			'stream'   => true,
		);

		if ( isset( $options['temperature'] ) ) {
			$payload['temperature'] = (float) $options['temperature'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$payload['max_tokens'] = (int) $options['max_tokens'];
		}
		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = $options['tools'];
		}
		if ( ! empty( $options['tool_choice'] ) ) {
			$payload['tool_choice'] = $options['tool_choice'];
		}
		if ( isset( $options['top_p'] ) ) {
			$payload['top_p'] = (float) $options['top_p'];
		}

		// Pre-flight context-window validation.
		$preflight = $this->validateContextWindow( $payload, $model );
		if ( null !== $preflight && ! empty( $preflight['data']['warning'] ) ) {
			// Soft warning — log but do not block.
		}
		if ( null !== $preflight && empty( $preflight['data']['warning'] ) ) {
			return $this->contextWindowError( $preflight );
		}

		try {
			$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return $this->errors->create(
				'json_encode_failed',
				'Failed to encode stream request payload.',
				array( 'error' => $e->getMessage() ),
			);
		}

		$headers = $this->buildAuthHeaders( $apiKey );

		try {
			$request  = new \Nyholm\Psr7\Request(
				'POST',
				$baseUrl . '/chat/completions',
				$headers,
				$body,
			);
			$response = $this->http->sendRequest( $request );

			$statusCode = $response->getStatusCode();

			if ( $statusCode >= 400 ) {
				return $this->parseError( $statusCode, (string) $response->getBody() );
			}

			// Parse SSE stream.
			$streamBody = (string) $response->getBody();
			$assembled  = '';
			$finish     = 'stop';
			$toolCalls  = array();

			foreach ( \preg_split( "/\r?\n/", $streamBody ) as $line ) {
				$line = \trim( $line );

				if ( '' === $line || 0 === \strpos( $line, ':' ) ) {
					continue;
				}

				if ( 0 === \strpos( $line, 'data: ' ) ) {
					$data = \substr( $line, 6 );

					if ( '[DONE]' === $data ) {
						break;
					}

					$chunk = \json_decode( $data, true );
					if ( ! is_array( $chunk ) ) {
						continue;
					}

					$delta  = $chunk['choices'][0]['delta'] ?? array();
					$token  = $delta['content'] ?? '';
					$finish = $chunk['choices'][0]['finish_reason'] ?? null;

					if ( '' !== $token ) {
						$assembled .= $token;

						if ( null !== $onChunk ) {
							$onChunk( $token );
						}
					}

					// Accumulate tool call deltas.
					if ( ! empty( $delta['tool_calls'] ) ) {
						foreach ( $delta['tool_calls'] as $tc ) {
							$idx = (int) ( $tc['index'] ?? 0 );
							if ( ! isset( $toolCalls[ $idx ] ) ) {
								$toolCalls[ $idx ] = array(
									'id'       => $tc['id'] ?? '',
									'type'     => 'function',
									'function' => array(
										'name'      => '',
										'arguments' => '',
									),
								);
							}
							if ( ! empty( $tc['id'] ) ) {
								$toolCalls[ $idx ]['id'] = $tc['id'];
							}
							if ( ! empty( $tc['function']['name'] ) ) {
								$toolCalls[ $idx ]['function']['name'] = $tc['function']['name'];
							}
							if ( isset( $tc['function']['arguments'] ) ) {
								$toolCalls[ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
							}
						}
					}
				}
			}

			// Build the final normalised message.
			$message = array(
				'role'    => 'assistant',
				'content' => $assembled,
			);

			// Re-index tool calls.
			$toolCalls = \array_values( $toolCalls );
			if ( array() !== $toolCalls ) {
				$message['tool_calls'] = $toolCalls;
			}

			$finish = $finish ?? 'stop';

			return array(
				'id'      => '',
				'object'  => 'chat.completion',
				'model'   => $model,
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => $message,
						'finish_reason' => $finish,
					),
				),
			);

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create(
				'http_request_failed',
				"Stream request failed: {$e->getMessage()}",
			);
		}
	}

	public function listModels(): mixed {
		$apiKey = $this->getApiKey();

		if ( $this->requiresApiKey() && '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$baseUrl = $this->getBaseUrl();
		$headers = $this->buildAuthHeaders( $apiKey );

		try {
			$response = $this->http->send( 'GET', $baseUrl . '/models', $headers );
			$data     = \json_decode( $response->body, true );

			if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
				return array();
			}

			$models = array();
			foreach ( $data['data'] as $m ) {
				if ( is_array( $m ) && isset( $m['id'] ) ) {
					$models[] = $m['id'];
				}
			}
			\sort( $models );
			return $models;
		} catch ( \Exception $e ) {
			return $this->errors->create( 'list_models_failed', $e->getMessage() );
		}
	}

	protected function parseError( int $statusCode, string $body ): mixed {
		$data = \json_decode( $body, true );
		$msg  = is_array( $data ) && isset( $data['error']['message'] )
			? $data['error']['message']
			: 'API returned status ' . $statusCode;

		if ( 429 === $statusCode ) {
			return $this->errors->rateLimited( $msg );
		}

		return $this->errors->create( "http_{$statusCode}", $msg, array( 'status' => $statusCode ) );
	}
}
