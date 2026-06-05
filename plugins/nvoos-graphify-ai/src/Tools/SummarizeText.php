<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

/**
 * Browser-native text summarization via client-side Transformers.js.
 */
class SummarizeText extends AbstractAiTool {
	public function getSlug(): string { return 'ai_summarize_text'; }
	public function getName(): string { return __( 'Summarize Text', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Generate a concise summary of the provided text using browser-native AI. Processes instantly without server round-trip.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'       => array( 'type' => 'string', 'description' => 'Text to summarize.' ),
				'max_length' => array( 'type' => 'integer', 'minimum' => 30, 'maximum' => 200, 'default' => 130 ),
				'min_length' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 100, 'default' => 30 ),
			),
			'required'   => array( 'text' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$text = sanitize_text_field( $arguments['text'] ?? '' );
		if ( empty( $text ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Text is required.', 'nvoos-graphify-ai' ) );
		}
		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'summarize',
			'client_arguments'  => array(
				'text'    => $text,
				'options' => array( 'maxLength' => absint( $arguments['max_length'] ?? 130 ), 'minLength' => absint( $arguments['min_length'] ?? 30 ) ),
			),
		);
	}
}
