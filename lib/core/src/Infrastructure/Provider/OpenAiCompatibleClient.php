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

		// Model-specific parameter constraints — reasoning models (o-series)
		// and the gpt-5 family reject `max_tokens` and (o-series) `temperature`.
		$payload = $this->applyModelConstraints( $payload, $model );

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
			return $this->sendWithParameterCorrection(
				$baseUrl . '/chat/completions',
				$headers,
				$payload,
				function ( string $respBody ): mixed {
					$data = \json_decode( $respBody, true );

					return is_array( $data ) ? $data : $this->errors->create(
						'invalid_response',
						'Provider returned an unexpected response format.',
						array( 'raw' => $respBody ),
					);
				}
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

		// Model-specific parameter constraints (o-series / gpt-5 family).
		$payload = $this->applyModelConstraints( $payload, $model );

		// Pre-flight context-window validation.
		$preflight = $this->validateContextWindow( $payload, $model );
		if ( null !== $preflight && ! empty( $preflight['data']['warning'] ) ) {
			// Soft warning — log but do not block.
		}
		if ( null !== $preflight && empty( $preflight['data']['warning'] ) ) {
			return $this->contextWindowError( $preflight );
		}

		$headers = $this->buildAuthHeaders( $apiKey );

		try {
			return $this->sendWithParameterCorrection(
				$baseUrl . '/chat/completions',
				$headers,
				$payload,
				function ( string $streamBody ) use ( $model, $onChunk ): mixed {
					// Parse SSE stream.
					$assembled = '';
					$finish    = 'stop';
					$toolCalls = array();

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
				}
			);
		} catch ( \Exception $e ) {
			return $this->errors->create(
				'http_request_failed',
				"Stream request failed: {$e->getMessage()}",
			);
		}
	}

	// ─── Model parameter compatibility ─────────────────────────────

	/**
	 * Send a request, transparently correcting model-incompatible
	 * parameters once when the API rejects them with a 400.
	 *
	 * OpenAI reasoning models (o-series) and the gpt-5 family reject
	 * `max_tokens` in favor of `max_completion_tokens`; o-series also
	 * reject `temperature`. Rather than hardcoding every future model,
	 * the API's own "Unsupported parameter" error drives a single
	 * correction attempt.
	 *
	 * @param string   $url          Endpoint URL.
	 * @param array    $headers      Request headers.
	 * @param array    $payload      Request payload.
	 * @param callable $parseSuccess Body parser for a 2xx response.
	 * @return mixed  Parsed result or error.
	 */
	private function sendWithParameterCorrection( string $url, array $headers, array $payload, callable $parseSuccess ): mixed {
		$attempt = 0;

		while ( true ) {
			try {
				$body = \json_encode( $payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR );
			} catch ( \JsonException $e ) {
				return $this->errors->create(
					'json_encode_failed',
					'Failed to encode request payload.',
					array( 'error' => $e->getMessage() ),
				);
			}

			$response   = $this->http->send( 'POST', $url, $headers, $body );
			$statusCode = $response->statusCode;
			$respBody   = $response->body;

			if ( $statusCode >= 400 ) {
				if ( 0 === $attempt ) {
					$corrected = $this->correctUnsupportedParameter( $statusCode, $respBody, $payload );
					if ( null !== $corrected ) {
						$payload = $corrected;
						++$attempt;
						continue;
					}
				}

				return $this->parseError( $statusCode, $respBody );
			}

			return $parseSuccess( $respBody );
		}
	}

	/**
	 * Parse an OpenAI-style "Unsupported parameter" 400 and return a
	 * corrected payload, or null when no correction applies.
	 *
	 * @param int    $statusCode HTTP status.
	 * @param string $respBody   Raw response body.
	 * @param array  $payload    Request payload that was rejected.
	 * @return array|null  Corrected payload, or null.
	 */
	private function correctUnsupportedParameter( int $statusCode, string $respBody, array $payload ): ?array {
		if ( 400 !== $statusCode ) {
			return null;
		}

		$data = \json_decode( $respBody, true );
		$msg  = is_array( $data ) && isset( $data['error']['message'] )
			? (string) $data['error']['message']
			: '';

		if ( '' === $msg || false === \strpos( $msg, 'Unsupported parameter' ) ) {
			return null;
		}

		if ( ! \preg_match( "/Unsupported parameter: '([^']+)'/", $msg, $m ) ) {
			return null;
		}

		$bad = $m[1];

		switch ( $bad ) {
			case 'max_tokens':
				if ( isset( $payload['max_tokens'] ) ) {
					$payload['max_completion_tokens'] = $payload['max_tokens'];
					unset( $payload['max_tokens'] );
					return $payload;
				}
				return null;

			case 'max_completion_tokens':
				if ( isset( $payload['max_completion_tokens'] ) ) {
					$payload['max_tokens'] = $payload['max_completion_tokens'];
					unset( $payload['max_completion_tokens'] );
					return $payload;
				}
				return null;

			default:
				// Drop the offending parameter entirely (e.g. temperature,
				// top_p on models that reject them).
				if ( isset( $payload[ $bad ] ) ) {
					unset( $payload[ $bad ] );
					return $payload;
				}
				return null;
		}
	}

	/**
	 * Apply known model-specific parameter constraints up front, so the
	 * first request already carries the parameters the model accepts.
	 *
	 * Mirrors the base plugin's reasoning-model handling
	 * (WP_MCP_AI_OpenAI_Client::is_reasoning_model): o-series models use
	 * `max_completion_tokens` and reject `temperature`; the gpt-5 family
	 * also requires `max_completion_tokens`.
	 *
	 * @param array  $payload Request payload.
	 * @param string $model   Resolved model id.
	 * @return array
	 */
	protected function applyModelConstraints( array $payload, string $model ): array {
		if ( $this->requiresMaxCompletionTokens( $model ) && isset( $payload['max_tokens'] ) ) {
			$payload['max_completion_tokens'] = $payload['max_tokens'];
			unset( $payload['max_tokens'] );
		}

		if ( $this->isReasoningModel( $model ) ) {
			// o-series reasoning models do not accept temperature.
			unset( $payload['temperature'] );
		}

		return $payload;
	}

	/**
	 * Whether a model id is an OpenAI o-series reasoning model.
	 *
	 * Same detection as the base plugin's WP_MCP_AI_OpenAI_Client:
	 * o1, o1-mini, o3, o4-mini, dated variants, etc.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private function isReasoningModel( string $model ): bool {
		return (bool) \preg_match( '/^o[0-9]+(-|$)/i', $model );
	}

	/**
	 * Whether a model requires `max_completion_tokens` instead of
	 * `max_tokens` (o-series reasoning models and the gpt-5 family).
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	private function requiresMaxCompletionTokens( string $model ): bool {
		return $this->isReasoningModel( $model ) || 0 === \strpos( $model, 'gpt-5' );
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
