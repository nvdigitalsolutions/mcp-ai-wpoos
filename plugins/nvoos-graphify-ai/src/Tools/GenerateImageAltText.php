<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Chat\ChatService;

/**
 * Generate image alt text using AI.
 */
class GenerateImageAltText extends AbstractAiTool {
	public function getSlug(): string { return 'ai_generate_image_alt_text'; }
	public function getName(): string { return __( 'Generate Image Alt Text', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Generate accessibility-friendly alt text for an image using AI.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer', 'description' => 'WordPress attachment ID.' ),
				'image_url'     => array( 'type' => 'string', 'description' => 'Image URL to analyze.' ),
			),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$attachmentId = absint( $arguments['attachment_id'] ?? 0 );
		$imageUrl     = esc_url_raw( $arguments['image_url'] ?? '' );

		if ( $attachmentId ) {
			$imageUrl = wp_get_attachment_url( $attachmentId );
		}

		if ( empty( $imageUrl ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Image URL or attachment ID required.', 'nvoos-graphify-ai' ) );
		}

		$messages = array(
			array( 'role' => 'user', 'content' => array(
				array( 'type' => 'text', 'text' => 'Describe this image in one concise sentence for accessibility (alt text).' ),
				array( 'type' => 'image_url', 'image_url' => array( 'url' => $imageUrl ) ),
			) ),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$altText = trim( $result['content'] ?? '' );
		if ( $attachmentId && ! empty( $altText ) ) {
			update_post_meta( $attachmentId, '_wp_attachment_image_alt', $altText );
		}

		return array( 'success' => true, 'alt_text' => $altText, 'attachment_id' => $attachmentId );
	}
}
