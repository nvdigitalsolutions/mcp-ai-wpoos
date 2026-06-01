<?php
/**
 * Create Text Embeddings — generates vector embeddings for text.
 *
 * Calls the OpenAI Embeddings API. Zero WordPress dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class CreateTextEmbeddingsTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_text_embeddings'; }
	public function getName(): string {
		return 'Create Text Embeddings'; }

	public function getDescription(): string {
		return 'Generates vector embeddings for text using the OpenAI Embeddings API. Useful for semantic search and similarity comparison.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'       => array(
					'type'        => 'string',
					'description' => 'The text to embed.',
				),
				'model'      => array(
					'type'        => 'string',
					'description' => 'Embedding model. Default: text-embedding-3-small.',
					'default'     => 'text-embedding-3-small',
				),
				'dimensions' => array(
					'type'        => 'integer',
					'description' => 'Output dimensions (only for text-embedding-3 models).',
				),
			),
			'required'             => array( 'text' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text  = $this->stringParam( $arguments, 'text' );
		$model = $this->stringParam( $arguments, 'model', 'text-embedding-3-small' );

		if ( '' === $text ) {
			return $this->errors->validationFailed(
				'The text parameter is required.',
				array( 'text' => array( 'Text to embed is required.' ) ),
			);
		}

		$apiKey = $this->settings->getApiKey( 'openai' );
		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_api_key', 'No OpenAI API key configured.', array( 'status' => 400 ) );
		}

		$baseUrl = $this->settings->getApiBaseUrl( 'openai' ) ?? 'https://api.openai.com/v1';

		$payload = array(
			'model' => $model,
			'input' => $text,
		);

		$dimensions = $this->intParam( $arguments, 'dimensions' );
		if ( $dimensions > 0 ) {
			$payload['dimensions'] = $dimensions;
		}

		try {
			$body     = \json_encode( $payload );
			$request  = new \Nyholm\Psr7\Request(
				'POST',
				$baseUrl . '/embeddings',
				array(
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => 'application/json',
				),
				$body,
			);
			$response = $this->http->sendRequest( $request );
			$data     = \json_decode( (string) $response->getBody(), true );

			if ( ! is_array( $data ) || ! isset( $data['data'][0]['embedding'] ) ) {
				return $this->errors->create( 'embeddings_failed', 'OpenAI returned an unexpected embeddings response.' );
			}

			$embedding = $data['data'][0]['embedding'];
			$usage     = $data['usage']['total_tokens'] ?? 0;

			return $this->success(
				'Embeddings generated.',
				array(
					'model'       => $data['model'] ?? $model,
					'dimensions'  => \count( $embedding ),
					'tokens_used' => $usage,
					'embedding'   => $embedding,
				)
			);

		} catch ( \Exception $e ) {
			return $this->errors->create( 'embeddings_failed', $e->getMessage() );
		}
	}
}
