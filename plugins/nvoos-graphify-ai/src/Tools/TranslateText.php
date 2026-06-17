<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

/**
 * Browser-native text translation via client-side Transformers.js.
 */
class TranslateText extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_translate_text'; }
	public function getName(): string {
		return __( 'Translate Text', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Translate text between languages using browser-native AI. Processes instantly without server round-trip.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'        => array(
					'type'        => 'string',
					'description' => 'Text to translate.',
				),
				'source_lang' => array(
					'type'        => 'string',
					'description' => 'Source language code (e.g. en).',
				),
				'target_lang' => array(
					'type'        => 'string',
					'description' => 'Target language code (e.g. fr).',
				),
			),
			'required'   => array( 'text', 'target_lang' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text = sanitize_text_field( $arguments['text'] ?? '' );
		if ( empty( $text ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Text is required.', 'nvoos-graphify-ai' ) );
		}
		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'translate',
			'client_arguments'  => array(
				'text'       => $text,
				'sourceLang' => sanitize_text_field( $arguments['source_lang'] ?? 'en' ),
				'targetLang' => sanitize_text_field( $arguments['target_lang'] ?? 'fr' ),
			),
		);
	}
}
