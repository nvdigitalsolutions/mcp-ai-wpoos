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
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

/**
 * Base for providers whose API is compatible with OpenAI's /v1/chat/completions.
 */
abstract class OpenAiCompatibleClient extends AbstractProviderClient {

	public function chat( array $messages, array $options = array() ): mixed {
		$apiKey = $this->getApiKey();

		if ( '' === $apiKey ) {
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
			$request  = new \Nyholm\Psr7\Request(
				'POST',
				$baseUrl . '/chat/completions',
				$headers,
				$body,
			);
			$response = $this->http->sendRequest( $request );

			$body       = (string) $response->getBody();
			$statusCode = $response->getStatusCode();

			if ( $statusCode >= 400 ) {
				return $this->parseError( $statusCode, $body );
			}

			$data = \json_decode( $body, true );

			return is_array( $data ) ? $data : $this->errors->create(
				'invalid_response',
				'Provider returned an unexpected response format.',
				array( 'raw' => $body ),
			);

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create(
				'http_request_failed',
				"API request failed: {$e->getMessage()}",
			);
		}
	}

	public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
		$options['stream'] = true;
		return $this->chat( $messages, $options );
	}

	public function listModels(): mixed {
		$apiKey = $this->getApiKey();

		if ( '' === $apiKey ) {
			return $this->missingApiKeyError();
		}

		$baseUrl = $this->getBaseUrl();
		$headers = $this->buildAuthHeaders( $apiKey );

		try {
			$request  = new \Nyholm\Psr7\Request( 'GET', $baseUrl . '/models', $headers );
			$response = $this->http->sendRequest( $request );
			$data     = \json_decode( (string) $response->getBody(), true );

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
