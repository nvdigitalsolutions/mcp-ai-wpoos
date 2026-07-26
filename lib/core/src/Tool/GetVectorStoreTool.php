<?php
/**
 * Get Vector Store — retrieve OpenAI vector store details.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Get_Vector_Store.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GetVectorStoreTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'get_vector_store';
	}

	public function getName(): string {
		return 'Get Vector Store';
	}

	public function getDescription(): string {
		return 'Retrieves detailed information about a specific OpenAI vector store including file counts, status, and metadata.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'vector_store_id' => array(
					'type'        => 'string',
					'description' => 'The ID of the vector store to retrieve.',
				),
			),
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$storeId = $this->stringParam( $arguments, 'vector_store_id' );

		// Fall back to assistant-configured store ID.
		if ( '' === $storeId ) {
			$storeId = (string) ( $context['assistant_config']['vector_store_id'] ?? '' );
		}

		if ( '' === $storeId ) {
			return $this->errors->validationFailed(
				'No vector store ID provided.',
				array( 'vector_store_id' => array( 'Vector store ID is required.' ) ),
			);
		}

		$apiKey = $this->settings->getApiKey( 'openai' );

		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );
		}

		try {
			$response = $this->http->send(
				'GET',
				"https://api.openai.com/v1/vector_stores/{$storeId}",
				array(
					'Authorization' => "Bearer {$apiKey}",
					'OpenAI-Beta'   => 'assistants=v2',
				),
			);

			$data = \json_decode( $response->body, true );

			if ( $response->statusCode >= 400 ) {
				$errMsg = $data['error']['message'] ?? 'OpenAI API error.';
				return $this->errors->create( 'openai_error', $errMsg );
			}

			return $this->success(
				"Vector store \"{$data['name']}\" retrieved (Status: {$data['status']}).",
				array(
					'id'             => $data['id'] ?? $storeId,
					'name'           => $data['name'] ?? null,
					'status'         => $data['status'] ?? null,
					'file_counts'    => $data['file_counts'] ?? array(),
					'created_at'     => $data['created_at'] ?? null,
					'last_active_at' => $data['last_active_at'] ?? null,
					'expires_after'  => $data['expires_after'] ?? null,
					'expires_at'     => $data['expires_at'] ?? null,
					'metadata'       => $data['metadata'] ?? array(),
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "OpenAI API request failed: {$e->getMessage()}" );
		}
	}
}
