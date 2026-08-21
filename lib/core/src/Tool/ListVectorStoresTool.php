<?php
/**
 * List Vector Stores — list OpenAI vector stores.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_List_Vector_Stores.
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

class ListVectorStoresTool extends AbstractTool {

	public function __construct(
		ErrorFactoryInterface $errors,
		private readonly SettingsStoreInterface $settings,
		private readonly HttpClientInterface $http,
	) {
		parent::__construct( $errors );
	}

	public function getSlug(): string {
		return 'list_vector_stores';
	}

	public function getName(): string {
		return 'List Vector Stores';
	}

	public function getDescription(): string {
		return 'Lists all OpenAI vector stores with optional filtering and pagination.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'limit'  => array(
					'type'        => 'integer',
					'description' => 'Maximum results (1-100). Default: 20.',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order'  => array(
					'type'        => 'string',
					'description' => 'Sort order: asc or desc. Default: desc.',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
				'after'  => array(
					'type'        => 'string',
					'description' => 'Cursor for forward pagination.',
				),
				'before' => array(
					'type'        => 'string',
					'description' => 'Cursor for reverse pagination.',
				),
			),
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$apiKey = $this->settings->getApiKey( 'openai' );

		if ( null === $apiKey || '' === $apiKey ) {
			return $this->errors->create( 'missing_key', 'No OpenAI API key configured.' );
		}

		$query  = array();
		$limit  = $this->intParam( $arguments, 'limit', 20 );
		$limit  = \max( 1, \min( 100, $limit ) );
		$order  = $this->stringParam( $arguments, 'order', 'desc' );
		$after  = $this->stringParam( $arguments, 'after' );
		$before = $this->stringParam( $arguments, 'before' );

		$query['limit'] = (string) $limit;
		$query['order'] = $order;

		if ( '' !== $after ) {
			$query['after'] = $after;
		}
		if ( '' !== $before ) {
			$query['before'] = $before;
		}

		$url = 'https://api.openai.com/v1/vector_stores?' . \http_build_query( $query );

		try {
			$response = $this->http->send(
				'GET',
				$url,
				array(
					'Authorization' => "Bearer {$apiKey}",
				),
			);

			$data = \json_decode( $response->body, true );

			if ( $response->statusCode >= 400 ) {
				$errMsg = $data['error']['message'] ?? 'OpenAI API error.';
				return $this->errors->create( 'openai_error', $errMsg );
			}

			$stores   = $data['data'] ?? array();
			$hasMore  = $data['has_more'] ?? false;

			return $this->collection(
				\sprintf( 'Found %d vector store(s).', \count( $stores ) ),
				$stores,
				\count( $stores ),
			);

		} catch ( \Throwable $e ) {
			return $this->errors->create( 'request_failed', "OpenAI API request failed: {$e->getMessage()}" );
		}
	}
}
