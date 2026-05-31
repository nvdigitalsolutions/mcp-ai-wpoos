<?php
/** @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;

class ClientTranslateTextTool extends AbstractClientSideTool {
	public function getSlug(): string {
		return 'client_translate_text'; }
	public function getName(): string {
		return 'Translate Text (Client)'; }
	public function getDescription(): string {
		return 'Translates text between 200+ languages using Transformers.js. Runs client-side.'; }
	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'        => array(
					'type'        => 'string',
					'description' => 'Text to translate',
				),
				'source_lang' => array(
					'type'        => 'string',
					'description' => 'Source language code',
				),
				'target_lang' => array(
					'type'        => 'string',
					'description' => 'Target language code',
					'default'     => 'en',
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
			'Translation will run in the browser using Transformers.js.',
			array(
				'client_side' => true,
				'model'       => 'Xenova/nllb-200-distilled-600M',
			)
		);
	}
}
