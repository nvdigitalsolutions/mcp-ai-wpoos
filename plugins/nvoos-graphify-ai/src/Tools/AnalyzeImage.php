<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Chat\ChatService;

/**
 * Analyze an image using AI vision capabilities.
 */
class AnalyzeImage extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_analyze_image'; }
	public function getName(): string {
		return __( 'Analyze Image', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Analyze an image using AI vision. Describe content, detect objects, extract text, or answer questions about the image.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'image_url' => array(
					'type'        => 'string',
					'description' => 'URL of the image to analyze.',
				),
				'question'  => array(
					'type'        => 'string',
					'description' => 'Optional question about the image.',
				),
			),
			'required'   => array( 'image_url' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$imageUrl = esc_url_raw( $arguments['image_url'] ?? '' );
		$question = sanitize_text_field( $arguments['question'] ?? '' );

		if ( empty( $imageUrl ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Image URL is required.', 'nvoos-graphify-ai' ) );
		}

		$prompt = $question ?: 'Describe this image in detail.';

		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $prompt,
					),
					array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => $imageUrl ),
					),
				),
			),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'analysis'  => $result['content'] ?? '',
			'image_url' => $imageUrl,
		);
	}
}
