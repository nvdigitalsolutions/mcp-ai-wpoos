<?php
/**
 * Create Vector Store — OpenAI vector store creation.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Create_Vector_Store.
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

class CreateVectorStoreTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'create_vector_store';
	}

	public function getName(): string {
		return 'Create Vector Store';
	}

	public function getDescription(): string {
		return 'Creates a new OpenAI vector store for knowledge retrieval and semantic search.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'          => array(
					'type'        => 'string',
					'description' => 'Name of the vector store.',
				),
				'file_ids'      => array(
					'type'        => 'array',
					'description' => 'Optional: Array of OpenAI file IDs to add.',
					'items'       => array( 'type' => 'string' ),
				),
				'expires_after' => array(
					'type'        => 'object',
					'description' => 'Optional: Auto-expiration config.',
					'properties'  => array(
						'anchor' => array( 'type' => 'string', 'enum' => array( 'last_active_at' ) ),
						'days'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365 ),
					),
				),
				'metadata'      => array(
					'type'        => 'object',
					'description' => 'Optional: Custom metadata (max 16 pairs).',
				),
			),
			'required'   => array( 'name' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$name = $this->stringParam( $arguments, 'name' );

		if ( '' === $name ) {
			return $this->errors->validationFailed(
				'The name parameter is required.',
				array( 'name' => array( 'Vector store name is required.' ) ),
			);
		}

		$apiKey = $this->settings->getApiKey( 'openai' );

		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );
		}

		$body = array( 'name' => $name );

		$fileIds = $this->arrayParam( $arguments, 'file_ids' );
		if ( array() !== $fileIds ) {
			$body['file_ids'] = $fileIds;
		}

		$expires = $arguments['expires_after'] ?? null;
		if ( \is_array( $expires ) && array() !== $expires ) {
			$body['expires_after'] = $expires;
		}

		$metadata = $arguments['metadata'] ?? null;
		if ( \is_array( $metadata ) && array() !== $metadata ) {
			$body['metadata'] = \array_slice( $metadata, 0, 16 );
		}

		try {
			$response = $this->http->send(
				'POST',
				'https://api.openai.com/v1/vector_stores',
				array(
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				\json_encode( $body ),
			);

			$data = \json_decode( $response->body, true );

			if ( $response->statusCode >= 400 ) {
				$errMsg = $data['error']['message'] ?? 'OpenAI API error.';
				return $this->errors->create( 'openai_error', $errMsg );
			}

			return $this->success(
				"Successfully created vector store \"{$name}\" (ID: {$data['id']})",
				array(
					'id'            => $data['id'] ?? null,
					'name'          => $data['name'] ?? $name,
					'status'        => $data['status'] ?? null,
					'file_counts'   => $data['file_counts'] ?? array(),
					'created_at'    => $data['created_at'] ?? null,
					'expires_after' => $data['expires_after'] ?? null,
					'metadata'      => $data['metadata'] ?? array(),
				),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "OpenAI API request failed: {$e->getMessage()}" );
		}
	}
}
