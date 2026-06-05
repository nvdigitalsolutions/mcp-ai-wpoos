<?php
/** @package Nvoos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

class ClientSemanticSearchTool extends AbstractClientSideTool {
	public function getSlug(): string {
		return 'client_semantic_search'; }
	public function getName(): string {
		return 'Semantic Search (Client)'; }
	public function getDescription(): string {
		return 'Performs semantic search over text using Transformers.js embeddings. Runs client-side.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'     => array(
					'type'        => 'string',
					'description' => 'Search query',
				),
				'documents' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Array of text documents to search',
				),
			),
			'required'             => array( 'query', 'documents' ),
			'additionalProperties' => false,
		); }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$q    = $this->stringParam( $arguments, 'query' );
		$docs = $this->arrayParam( $arguments, 'documents' );
		if ( '' === $q ) {
			return $this->errors->validationFailed( 'query is required.', array( 'query' => array( 'Search query is required.' ) ) );
		}
		if ( array() === $docs ) {
			return $this->errors->validationFailed( 'documents is required.', array( 'documents' => array( 'At least one document is required.' ) ) );
		}
		return $this->success(
			'Semantic search will run in the browser using Transformers.js.',
			array(
				'client_side'    => true,
				'model'          => 'Xenova/all-MiniLM-L6-v2',
				'document_count' => count( $docs ),
			)
		);
	}
}
