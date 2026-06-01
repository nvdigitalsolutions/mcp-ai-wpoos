<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

class ClientAnalyzeSentimentTool extends AbstractClientSideTool {
	public function getSlug(): string {
		return 'client_analyze_sentiment'; }
	public function getName(): string {
		return 'Analyze Sentiment (Client)'; }
	public function getDescription(): string {
		return 'Analyzes sentiment of text using Transformers.js in the browser. Runs entirely client-side.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text' => array(
					'type'        => 'string',
					'description' => 'Text to analyze',
				),
			),
			'required'             => array( 'text' ),
			'additionalProperties' => false,
		); }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$missing = $this->validateText( $arguments );
		if ( null !== $missing ) {
			return $this->errors->validationFailed( "{$missing} is required.", array( $missing => array( "{$missing} is required." ) ) );
		}
		return $this->success(
			'Sentiment analysis will run in the browser using Transformers.js. The result will appear in your chat interface.',
			array(
				'client_side' => true,
				'model'       => 'Xenova/distilbert-base-uncased-finetuned-sst-2-english',
			)
		);
	}
}
