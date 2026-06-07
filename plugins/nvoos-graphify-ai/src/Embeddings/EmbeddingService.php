<?php
/**
 * Embedding Service — generates vector embeddings via AI provider APIs.
 *
 * Communicates with provider embedding endpoints (OpenAI-compatible)
 * to produce dense vectors for graph nodes. Vectors are stored in
 * the nvoos_graphify_embeddings table via NvoosGraphify\Graph\Db.
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Embeddings;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface;

class EmbeddingService {

	private const DEFAULT_MODEL = 'text-embedding-3-small';

	public function __construct(
		private readonly SettingsStoreInterface $settings,
		private readonly ClientInterface $http,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Generate an embedding vector for a single text string.
	 *
	 * @param string $text     Input text to embed.
	 * @param string $provider Provider slug (default: configured default).
	 * @param string $model    Embedding model (default: text-embedding-3-small).
	 *
	 * @return array{vector: float[], dim: int, model: string}|mixed  Vector or error.
	 */
	public function embed( string $text, string $provider = '', string $model = '' ): mixed {
		$text = \trim( $text );
		if ( '' === $text ) {
			return $this->errors->create( 'empty_text', 'Cannot generate embedding for empty text.' );
		}

		$provider = '' !== $provider ? $provider : $this->settings->getDefaultProvider();
		$model    = '' !== $model ? $model : self::DEFAULT_MODEL;

		$apiKey = $this->resolveApiKey( $provider );
		if ( null === $apiKey ) {
			return $this->errors->create(
				'missing_api_key',
				"No API key configured for {$provider}. Embedding generation requires an API key.",
				array( 'status' => 400 ),
			);
		}

		$baseUrl = $this->resolveBaseUrl( $provider );

		$payload = array(
			'input' => $text,
			'model' => $model,
		);

		$body = \wp_json_encode( $payload, \JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->errors->create( 'json_encode_failed', 'Failed to encode embedding request payload.' );
		}

		$headers = array(
			'Authorization' => "Bearer {$apiKey}",
			'Content-Type'  => 'application/json',
		);

		try {
			$request    = new \Nyholm\Psr7\Request( 'POST', $baseUrl . '/embeddings', $headers, $body );
			$response   = $this->http->sendRequest( $request );
			$statusCode = $response->getStatusCode();

			if ( $statusCode >= 400 ) {
				$errBody = (string) $response->getBody();
				$errData = \json_decode( $errBody, true );
				$msg     = is_array( $errData ) && isset( $errData['error']['message'] )
					? $errData['error']['message']
					: "Embedding API returned status {$statusCode}.";

				return $this->errors->create( "http_{$statusCode}", $msg, array( 'status' => $statusCode ) );
			}

			$data = \json_decode( (string) $response->getBody(), true );

			if ( ! is_array( $data ) || ! isset( $data['data'][0]['embedding'] ) ) {
				return $this->errors->create(
					'invalid_response',
					'Embedding API returned an unexpected response format.',
				);
			}

			$vector = $data['data'][0]['embedding'];

			return array(
				'vector' => $vector,
				'dim'    => \count( $vector ),
				'model'  => $model,
			);

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create( 'http_request_failed', "Embedding request failed: {$e->getMessage()}" );
		}
	}

	/**
	 * Generate embeddings for multiple texts in a batch.
	 *
	 * @param string[] $texts    Input texts.
	 * @param string   $provider Provider slug.
	 * @param string   $model    Embedding model.
	 *
	 * @return array<int, array{vector: float[], dim: int}>|mixed  Vectors or error.
	 */
	public function embedBatch( array $texts, string $provider = '', string $model = '' ): mixed {
		$provider = '' !== $provider ? $provider : $this->settings->getDefaultProvider();
		$model    = '' !== $model ? $model : self::DEFAULT_MODEL;

		$apiKey = $this->resolveApiKey( $provider );
		if ( null === $apiKey ) {
			return $this->errors->create( 'missing_api_key', "No API key for {$provider}.", array( 'status' => 400 ) );
		}

		$baseUrl  = $this->resolveBaseUrl( $provider );
		$filtered = \array_values( \array_filter( $texts, static fn( $t ) => '' !== \trim( $t ) ) );

		if ( array() === $filtered ) {
			return array();
		}

		$payload = array(
			'input' => $filtered,
			'model' => $model,
		);

		$body = \wp_json_encode( $payload, \JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->errors->create( 'json_encode_failed', 'Failed to encode batch embedding request payload.' );
		}

		$headers = array(
			'Authorization' => "Bearer {$apiKey}",
			'Content-Type'  => 'application/json',
		);

		try {
			$request    = new \Nyholm\Psr7\Request( 'POST', $baseUrl . '/embeddings', $headers, $body );
			$response   = $this->http->sendRequest( $request );
			$statusCode = $response->getStatusCode();

			if ( $statusCode >= 400 ) {
				return $this->errors->create( "http_{$statusCode}", (string) $response->getBody(), array( 'status' => $statusCode ) );
			}

			$data = \json_decode( (string) $response->getBody(), true );
			if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
				return $this->errors->create( 'invalid_response', 'Unexpected batch embedding response.' );
			}

			$results = array();
			foreach ( $data['data'] as $item ) {
				if ( isset( $item['embedding'] ) ) {
					$results[] = array(
						'vector' => $item['embedding'],
						'dim'    => \count( $item['embedding'] ),
					);
				}
			}
			return $results;

		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return $this->errors->create( 'http_request_failed', $e->getMessage() );
		}
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	private function resolveApiKey( string $provider ): ?string {
		$apiKey = $this->settings->getApiKey( $provider );
		if ( ( null === $apiKey || '' === $apiKey ) && in_array( $provider, array( 'ollama', 'lm_studio' ), true ) ) {
			// Local providers can't do embeddings — fall back to OpenAI.
			return $this->settings->getApiKey( 'openai' );
		}
		return ( null !== $apiKey && '' !== $apiKey ) ? $apiKey : null;
	}

	private function resolveBaseUrl( string $provider ): string {
		$custom = $this->settings->getApiBaseUrl( $provider );
		if ( is_string( $custom ) && '' !== $custom ) {
			return \untrailingslashit( $custom );
		}

		$defaults = array(
			'openai'       => 'https://api.openai.com/v1',
			'deepseek'     => 'https://api.deepseek.com/v1',
			'openrouter'   => 'https://openrouter.ai/api/v1',
			'digitalocean' => 'https://inference.do-ai.run/v1',
			'kimi'         => 'https://api.moonshot.cn/v1',
			'baseten'      => 'https://api.baseten.co/v1',
		);

		return $defaults[ $provider ] ?? 'https://api.openai.com/v1';
	}
}
