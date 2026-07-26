<?php
/**
 * WordPress-specific tool for generating SEO-optimized alt text.
 *
 * Lives in the WordPress adapter because it uses WordPress attachment APIs,
 * WP_Query, post meta, and the WP_MCP_AI language model router for AI vision.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Generates SEO-optimized alt text for images using AI vision models.
 */
class ImageAltTextOptimizerTool extends AbstractTool {

	public function getSlug(): string {
		return 'image_alt_text_optimizer';
	}

	public function getName(): string {
		return 'Image Alt Text Optimizer';
	}

	public function getDescription(): string {
		return 'Generates SEO-optimized and accessible alt text for images using AI vision models. Creates descriptive, natural alt text that improves both accessibility and image SEO.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => 'WordPress attachment ID to generate alt text for.',
					'minimum'     => 1,
				),
				'image_url'     => array(
					'type'        => 'string',
					'description' => 'Image URL to analyze (if attachment_id not provided).',
					'format'      => 'uri',
				),
				'context'       => array(
					'type'        => 'string',
					'description' => 'Context about how/where the image is used.',
				),
				'focus_keyword' => array(
					'type'        => 'string',
					'description' => 'Primary keyword to include in alt text (optional).',
				),
				'max_length'    => array(
					'type'        => 'integer',
					'description' => 'Maximum alt text length in characters.',
					'minimum'     => 50,
					'maximum'     => 125,
					'default'     => 125,
				),
				'tone'          => array(
					'type'        => 'string',
					'description' => 'Writing tone for alt text.',
					'enum'        => array( 'descriptive', 'concise', 'detailed', 'professional' ),
					'default'     => 'descriptive',
				),
				'auto_save'     => array(
					'type'        => 'boolean',
					'description' => 'Automatically save alt text to attachment.',
					'default'     => false,
				),
				'batch_mode'    => array(
					'type'        => 'boolean',
					'description' => 'Process multiple images without alt text.',
					'default'     => false,
				),
				'post_id'       => array(
					'type'        => 'integer',
					'description' => 'Post ID to process images from (for batch mode).',
					'minimum'     => 1,
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => 'Maximum number of images in batch mode.',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'anyOf'      => array(
				array( 'required' => array( 'attachment_id' ) ),
				array( 'required' => array( 'image_url' ) ),
				array( 'required' => array( 'batch_mode' ) ),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( $arguments['batch_mode'] ?? false ) {
			return $this->processBatch( $arguments, $context );
		}

		return $this->processSingleImage( $arguments, $context );
	}

	/**
	 * Process a single image for alt text generation.
	 */
	private function processSingleImage( array $arguments, array $context ): mixed {
		$validation = $this->validateArguments( $arguments );
		if ( \is_wp_error( $validation ) ) {
			return $this->errors->create(
				$validation->get_error_code(),
				$validation->get_error_message(),
			);
		}

		$image_data = $this->getImageData( $arguments );
		if ( \is_wp_error( $image_data ) ) {
			return $this->errors->create(
				$image_data->get_error_code(),
				$image_data->get_error_message(),
			);
		}

		$alt_text = $this->generateAltText( $image_data, $arguments, $context );
		if ( \is_wp_error( $alt_text ) ) {
			return $this->errors->create(
				$alt_text->get_error_code(),
				$alt_text->get_error_message(),
			);
		}

		$validation_results = $this->validateAltText( $alt_text, $arguments );

		$saved = false;
		if ( ! empty( $image_data['attachment_id'] ) && ( $arguments['auto_save'] ?? false ) ) {
			$saved = \update_post_meta(
				$image_data['attachment_id'],
				'_wp_attachment_image_alt',
				\wp_strip_all_tags( $alt_text )
			);
		}

		return $this->success(
			'Alt text generated.',
			array(
				'alt_text'           => $alt_text,
				'char_count'         => \strlen( $alt_text ),
				'attachment_id'      => $image_data['attachment_id'] ?? null,
				'image_url'          => $image_data['url'] ?? null,
				'saved'              => $saved,
				'validation'         => $validation_results,
				'best_practices_met' => $validation_results['all_passed'] ?? false,
			)
		);
	}

	/**
	 * Process multiple images in batch mode.
	 */
	private function processBatch( array $arguments, array $context ): mixed {
		$limit   = $arguments['limit'] ?? 10;
		$post_id = $arguments['post_id'] ?? 0;

		$images = $this->getImagesWithoutAltText( $post_id, $limit );

		if ( empty( $images ) ) {
			return $this->success(
				'No images without alt text found.',
				array( 'processed' => 0 )
			);
		}

		$results = array();
		foreach ( $images as $attachment_id ) {
			$single_args = \array_merge(
				$arguments,
				array( 'attachment_id' => $attachment_id, 'batch_mode' => false )
			);

			$result   = $this->processSingleImage( $single_args, $context );
			$is_error = ! isset( $result['success'] ) || ! $result['success'];

			$results[] = array(
				'attachment_id' => $attachment_id,
				'success'       => ! $is_error,
				'alt_text'      => $is_error ? null : ( $result['data']['alt_text'] ?? null ),
				'error'         => $is_error ? ( $result['message'] ?? 'Unknown error' ) : null,
			);
		}

		$success_count = \count( \array_filter( $results, static fn( $r ) => $r['success'] ) );

		return $this->success(
			'Batch processed.',
			array(
				'processed'     => \count( $results ),
				'success_count' => $success_count,
				'results'       => $results,
			)
		);
	}

	private function validateArguments( array $arguments ) {
		if ( empty( $arguments['attachment_id'] ) && empty( $arguments['image_url'] ) ) {
			return new \WP_Error( 'missing_image', 'Either attachment_id or image_url must be provided.' );
		}

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment = \get_post( $arguments['attachment_id'] );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new \WP_Error( 'invalid_attachment', 'Attachment not found.' );
			}
		}

		return true;
	}

	private function getImageData( array $arguments ): array {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = $arguments['attachment_id'];
			$image_url     = \wp_get_attachment_url( $attachment_id );
			$current_alt   = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

			return array(
				'attachment_id' => $attachment_id,
				'url'           => $image_url,
				'filename'      => \basename( $image_url ),
				'current_alt'   => $current_alt,
			);
		}

		return array(
			'url'      => $arguments['image_url'],
			'filename' => \basename( $arguments['image_url'] ),
		);
	}

	private function generateAltText( array $image_data, array $arguments, array $context = array() ) {
		$prompt = $this->buildAltTextPrompt( $image_data, $arguments );
		$client = $this->getAiClient( $arguments, $context );

		if ( \is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->complete( array(
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => array(
							array( 'type' => 'text', 'text' => $prompt ),
							array(
								'type'      => 'image_url',
								'image_url' => array( 'url' => $image_data['url'] ),
							),
						),
					),
				),
				'model'       => $arguments['model'] ?? 'gpt-4o-mini',
				'temperature' => 0.5,
				'max_tokens'  => 100,
			) );

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			return \trim( \trim( $response['content'] ?? '' ), '"' . "'" . '"' );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'alt_text_generation_failed',
				\sprintf( 'Alt text generation failed: %s', $e->getMessage() )
			);
		}
	}

	private function buildAltTextPrompt( array $image_data, array $arguments ): string {
		$max_length    = $arguments['max_length'] ?? 125;
		$tone          = $arguments['tone'] ?? 'descriptive';
		$focus_keyword = $arguments['focus_keyword'] ?? '';
		$context       = $arguments['context'] ?? '';

		$prompt  = "Generate SEO-optimized and accessible alt text for this image.\n\n";
		$prompt .= "Requirements:\n";
		$prompt .= "- Maximum {$max_length} characters\n";
		$prompt .= "- Be {$tone} and specific\n";
		$prompt .= "- Describe what's in the image naturally\n";
		$prompt .= "- Do NOT start with 'Image of' or 'Picture of'\n";
		$prompt .= "- Use proper grammar and punctuation\n";
		$prompt .= "- Avoid keyword stuffing\n";

		if ( $focus_keyword ) {
			$prompt .= "- Naturally include the keyword: '{$focus_keyword}'\n";
		}
		if ( $context ) {
			$prompt .= "- Context: {$context}\n";
		}

		$prompt .= "\nWrite only the alt text, without quotes or additional commentary.";
		return $prompt;
	}

	private function validateAltText( string $alt_text, array $arguments ): array {
		$max_length    = $arguments['max_length'] ?? 125;
		$focus_keyword = $arguments['focus_keyword'] ?? '';
		$length        = \strlen( $alt_text );

		$validation = array(
			'length_ok'     => $length <= $max_length,
			'no_prefix'     => true,
			'has_keyword'   => true,
			'not_too_short' => $length >= 10,
			'all_passed'    => true,
			'issues'        => array(),
		);

		if ( ! $validation['length_ok'] ) {
			$validation['issues'][] = \sprintf( 'Alt text too long (%d chars, max %d).', $length, $max_length );
		}
		if ( ! $validation['not_too_short'] ) {
			$validation['issues'][] = 'Alt text too short (minimum 10 characters).';
		}

		$bad_prefixes = array( 'image of', 'picture of', 'photo of', 'a picture', 'an image' );
		$alt_lower    = \strtolower( $alt_text );
		foreach ( $bad_prefixes as $prefix ) {
			if ( 0 === \strpos( $alt_lower, $prefix ) ) {
				$validation['no_prefix'] = false;
				$validation['issues'][]  = 'Alt text should not start with "Image of" or similar phrases.';
				break;
			}
		}

		if ( $focus_keyword && false === \stripos( $alt_text, $focus_keyword ) ) {
			$validation['has_keyword'] = false;
			$validation['issues'][]    = 'Focus keyword not found in alt text.';
		}

		$validation['all_passed'] = $validation['length_ok']
			&& $validation['no_prefix']
			&& $validation['not_too_short']
			&& $validation['has_keyword'];

		return $validation;
	}

	private function getImagesWithoutAltText( int $post_id, int $limit ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			),
		);

		if ( $post_id > 0 ) {
			$args['post_parent'] = $post_id;
		}

		return \get_posts( $args );
	}

	private function getAiClient( array $arguments, array $context ) {
		if ( \class_exists( 'WP_MCP_AI_Language_Model_Router' ) ) {
			$router = \WP_MCP_AI_Language_Model_Router::get_instance();
			return $router->get_client( $arguments['model'] ?? 'gpt-4o-mini' );
		}

		if ( \class_exists( 'WP_MCP_AI_Enhanced_OpenAI_Client' ) ) {
			return new \WP_MCP_AI_Enhanced_OpenAI_Client();
		}

		return new \WP_Error( 'no_ai_client', 'No AI client available for image analysis.' );
	}
}
