<?php
/**
 * Manage Vector Store Files — add, remove, or list files in OpenAI vector stores.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Manage_Vector_Store_Files.
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

class ManageVectorStoreFilesTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'manage_vector_store_files';
	}

	public function getName(): string {
		return 'Manage Vector Store Files';
	}

	public function getDescription(): string {
		return 'Add, remove, or list files in an OpenAI vector store. Best formats: PDF, TXT, DOCX, MD, JSON, HTML.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'vector_store_id' => array(
					'type'        => 'string',
					'description' => 'The ID of the vector store to manage.',
				),
				'action'          => array(
					'type'        => 'string',
					'description' => 'Action: add, remove, or list.',
					'enum'        => array( 'add', 'remove', 'list' ),
				),
				'file_ids'        => array(
					'type'        => 'array',
					'description' => 'Array of OpenAI file IDs (required for add/remove).',
					'items'       => array( 'type' => 'string' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => 'Max files when listing (1-100). Default: 20.',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'           => array(
					'type'        => 'string',
					'description' => 'Sort order when listing. Default: desc.',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action = $this->stringParam( $arguments, 'action' );

		if ( '' === $action || ! \in_array( $action, array( 'add', 'remove', 'list' ), true ) ) {
			return $this->errors->validationFailed(
				'Action must be add, remove, or list.',
				array( 'action' => array( 'Invalid action.' ) ),
			);
		}

		$storeId = $this->stringParam( $arguments, 'vector_store_id' );

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

		return match ( $action ) {
			'add'    => $this->addFiles( $apiKey, $storeId, $arguments ),
			'remove' => $this->removeFiles( $apiKey, $storeId, $arguments ),
			'list'   => $this->listFiles( $apiKey, $storeId, $arguments ),
		};
	}

	private function addFiles( string $apiKey, string $storeId, array $arguments ): mixed {
		$fileIds = $this->arrayParam( $arguments, 'file_ids' );

		if ( array() === $fileIds ) {
			return $this->errors->validationFailed(
				'The file_ids parameter is required for add action.',
				array( 'file_ids' => array( 'At least one file ID is required.' ) ),
			);
		}

		$results = array();
		$errors  = array();

		foreach ( $fileIds as $fileId ) {
			try {
				$response = $this->http->send(
					'POST',
					"https://api.openai.com/v1/vector_stores/{$storeId}/files",
					array(
						'Authorization' => "Bearer {$apiKey}",
						'Content-Type'  => 'application/json',
						'OpenAI-Beta'   => 'assistants=v2',
					),
					\json_encode( array( 'file_id' => (string) $fileId ) ),
				);

				$data = \json_decode( $response->body, true );

				if ( $response->statusCode >= 400 ) {
					$errMsg = $data['error']['message'] ?? 'OpenAI API error.';
					$errors[] = array( 'file_id' => $fileId, 'error' => $errMsg );
				} else {
					$results[] = array( 'file_id' => $fileId, 'status' => $data['status'] ?? 'added' );
				}
			} catch ( \Throwable $e ) {
				$errors[] = array( 'file_id' => $fileId, 'error' => $e->getMessage() );
			}
		}

		return $this->buildFileActionResult( 'added', $results, $errors, \count( $fileIds ) );
	}

	private function removeFiles( string $apiKey, string $storeId, array $arguments ): mixed {
		$fileIds = $this->arrayParam( $arguments, 'file_ids' );

		if ( array() === $fileIds ) {
			return $this->errors->validationFailed(
				'The file_ids parameter is required for remove action.',
				array( 'file_ids' => array( 'At least one file ID is required.' ) ),
			);
		}

		$results = array();
		$errors  = array();

		foreach ( $fileIds as $fileId ) {
			try {
				$response = $this->http->send(
					'DELETE',
					"https://api.openai.com/v1/vector_stores/{$storeId}/files/{$fileId}",
					array(
						'Authorization' => "Bearer {$apiKey}",
						'OpenAI-Beta'   => 'assistants=v2',
					),
				);

				$data = \json_decode( $response->body, true );

				if ( $response->statusCode >= 400 ) {
					$errMsg = $data['error']['message'] ?? 'OpenAI API error.';
					$errors[] = array( 'file_id' => $fileId, 'error' => $errMsg );
				} else {
					$results[] = array( 'file_id' => $fileId, 'deleted' => $data['deleted'] ?? true );
				}
			} catch ( \Throwable $e ) {
				$errors[] = array( 'file_id' => $fileId, 'error' => $e->getMessage() );
			}
		}

		return $this->buildFileActionResult( 'removed', $results, $errors, \count( $fileIds ) );
	}

	private function listFiles( string $apiKey, string $storeId, array $arguments ): mixed {
		$limit = $this->intParam( $arguments, 'limit', 20 );
		$limit = \max( 1, \min( 100, $limit ) );
		$order = $this->stringParam( $arguments, 'order', 'desc' );

		$query = \http_build_query( array( 'limit' => $limit, 'order' => $order ) );
		$url   = "https://api.openai.com/v1/vector_stores/{$storeId}/files?{$query}";

		try {
			$response = $this->http->send(
				'GET',
				$url,
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

			$files   = $data['data'] ?? array();
			$hasMore = $data['has_more'] ?? false;

			return $this->collection(
				\sprintf( 'Found %d file(s).', \count( $files ) ),
				$files,
				\count( $files ),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "OpenAI API request failed: {$e->getMessage()}" );
		}
	}

	private function buildFileActionResult( string $verb, array $results, array $errors, int $total ): mixed {
		$ok  = \count( $results );
		$err = \count( $errors );

		if ( 0 === $err ) {
			return $this->success(
				"Successfully {$verb} {$ok} file(s) to vector store.",
				array( 'added' => $results, 'errors' => $errors, 'total' => $total ),
			);
		}

		return $this->success(
			"{$verb} {$ok} file(s), {$err} failed.",
			array( 'added' => $results, 'errors' => $errors, 'total' => $total ),
		);
	}
}
