<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Browser-native entity extraction via client-side Transformers.js.
 */
class ExtractEntities extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_extract_entities'; }
	public function getName(): string {
		return __( 'Extract Entities', 'nvoos-content-graph-ai' ); }
	public function getDescription(): string {
		return __( 'Extract named entities from text using browser-native AI. Returns people, places, organizations, and more.', 'nvoos-content-graph-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text' => array(
					'type'        => 'string',
					'description' => 'Text to extract entities from.',
				),
			),
			'required'   => array( 'text' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text = sanitize_text_field( $arguments['text'] ?? '' );
		if ( empty( $text ) ) {
			return new \WP_Error( 'nvoos_content_graph_ai', __( 'Text is required.', 'nvoos-content-graph-ai' ) );
		}
		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'extractEntities',
			'client_arguments'  => array( 'text' => $text ),
		);
	}
}
