<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

/**
 * Browser-native sentiment analysis via client-side Transformers.js.
 */
class AnalyzeSentiment extends AbstractAiTool {
	public function getSlug(): string { return 'ai_analyze_sentiment'; }
	public function getName(): string { return __( 'Analyze Sentiment', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Analyze the sentiment of text using browser-native AI. Returns sentiment score and label.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text' => array( 'type' => 'string', 'description' => 'Text to analyze.' ),
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
			'client_method'     => 'sentiment',
			'client_arguments'  => array( 'text' => $text ),
		);
	}
}
