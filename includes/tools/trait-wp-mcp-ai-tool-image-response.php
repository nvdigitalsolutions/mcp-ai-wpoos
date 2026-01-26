<?php
/**
 * Trait for adding rendered image HTML to image generation tool responses.
 *
 * This trait provides a standardized way to include WordPress IMG tags in tool
 * responses, ensuring users can see newly created images directly in the chat UI.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Image_Response
 *
 * Provides helper methods to add rendered image HTML to tool responses.
 * This ensures that image generation tools return not just URLs but actual
 * displayable IMG tags that users can see in the chat interface.
 *
 * Usage:
 * ```php
 * class My_Image_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Image_Response;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         // Generate image and get attachment_id, url, etc.
 *         $result = array(
 *             'attachment_id' => $attachment_id,
 *             'url' => $url,
 *             'text' => 'Image generated successfully',
 *         );
 *
 *         // Add rendered image HTML to response
 *         return $this->add_image_html_to_response( $result );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Image_Response {

	/**
	 * Add rendered image HTML to a tool response.
	 *
	 * This method takes an image generation result and adds a rendered IMG tag
	 * to the 'message' field, making the image immediately visible in the chat UI.
	 *
	 * @param array $result Tool result containing attachment_id and optionally text/message.
	 * @return array Modified result with image HTML added to message field.
	 */
	protected function add_image_html_to_response( array $result ) {
		// Check if we have required data.
		if ( empty( $result['attachment_id'] ) ) {
			return $result;
		}

		$attachment_id = absint( $result['attachment_id'] );

		// Verify attachment exists.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $result;
		}

		// Get alt text from various possible sources.
		$alt_text = $this->get_image_alt_text( $result );

		// Get title text.
		$title_text = isset( $result['title'] ) ? $result['title'] : get_the_title( $attachment_id );

		// Generate the image HTML.
		$image_html = $this->generate_image_html( $attachment_id, $alt_text, $title_text );

		// Get existing text message (prefer 'text' over 'message' for base content).
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered image.
		// Use double newline to separate text from image for better readability.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $image_html : $image_html;

		return $result;
	}

	/**
	 * Add rendered image HTML to responses containing multiple images.
	 *
	 * For tools that generate multiple images (variations, edits), this adds
	 * rendered IMG tags for each image in the result.
	 *
	 * @param array $result Tool result containing 'images' array.
	 * @return array Modified result with image HTML added.
	 */
	protected function add_multiple_images_html_to_response( array $result ) {
		// Check if we have images array.
		if ( empty( $result['data']['images'] ) || ! is_array( $result['data']['images'] ) ) {
			return $result;
		}

		$images_html = array();

		foreach ( $result['data']['images'] as $image ) {
			if ( empty( $image['attachment_id'] ) ) {
				continue;
			}

			$attachment_id = absint( $image['attachment_id'] );

			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$alt_text   = isset( $image['alt'] ) ? $image['alt'] : get_the_title( $attachment_id );
			$title_text = isset( $image['title'] ) ? $image['title'] : get_the_title( $attachment_id );

			$images_html[] = $this->generate_image_html( $attachment_id, $alt_text, $title_text );
		}

		// Get existing text message.
		$text_message = isset( $result['data']['text'] ) ? $result['data']['text'] : ( isset( $result['data']['message'] ) ? $result['data']['message'] : '' );

		// Combine text message with rendered images.
		if ( ! empty( $images_html ) ) {
			$all_images_html           = implode( "\n\n", $images_html );
			$result['data']['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $all_images_html : $all_images_html;
		}

		return $result;
	}

	/**
	 * Generate clean, optimized image HTML tag.
	 *
	 * Creates a simple IMG tag without the bloat of wp_get_attachment_image()'s
	 * full responsive image markup. Suitable for chat UI display.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $alt_text      Alt text for accessibility.
	 * @param string $title_text    Title text for tooltip.
	 * @return string HTML img tag.
	 */
	protected function generate_image_html( $attachment_id, $alt_text = '', $title_text = '' ) {
		// Get image URL at large size (suitable for chat display).
		$image_url = wp_get_attachment_image_url( $attachment_id, 'large' );

		if ( ! $image_url ) {
			// Fallback to full size if large doesn't exist.
			$image_url = wp_get_attachment_url( $attachment_id );
		}

		if ( ! $image_url ) {
			return '';
		}

		// Get image metadata for width/height attributes (improves layout stability).
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$width    = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : '';
		$height   = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : '';

		// If we have large size dimensions, use those instead.
		if ( isset( $metadata['sizes']['large'] ) ) {
			$width  = absint( $metadata['sizes']['large']['width'] );
			$height = absint( $metadata['sizes']['large']['height'] );
		}

		// Build IMG tag with proper escaping.
		$html = '<img src="' . esc_url( $image_url ) . '"';

		if ( ! empty( $alt_text ) ) {
			$html .= ' alt="' . esc_attr( $alt_text ) . '"';
		} else {
			$html .= ' alt=""'; // Empty alt for decorative images per accessibility standards.
		}

		if ( ! empty( $title_text ) ) {
			$html .= ' title="' . esc_attr( $title_text ) . '"';
		}

		if ( ! empty( $width ) ) {
			$html .= ' width="' . $width . '"';
		}

		if ( ! empty( $height ) ) {
			$html .= ' height="' . $height . '"';
		}

		// Add CSS class for styling.
		$html .= ' class="wp-mcp-ai-generated-image"';

		// Add loading="lazy" for performance (images below the fold).
		$html .= ' loading="lazy"';

		$html .= ' />';

		return $html;
	}

	/**
	 * Extract appropriate alt text from result data.
	 *
	 * Checks various fields in the result to find suitable alt text.
	 *
	 * @param array $result Tool result array.
	 * @return string Alt text.
	 */
	protected function get_image_alt_text( array $result ) {
		// Priority order for alt text sources.
		$alt_candidates = array(
			'revised_prompt', // OpenAI/Gemini revised prompts.
			'prompt',         // Original prompt.
			'title',          // Image title.
			'file_name',      // Fallback to filename.
		);

		foreach ( $alt_candidates as $key ) {
			if ( ! empty( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				$alt_text = $result[ $key ];

				// Limit alt text length (recommended max is 125 characters).
				// Use substr for precise character-based truncation.
				if ( strlen( $alt_text ) > 125 ) {
					$alt_text = substr( $alt_text, 0, 122 ) . '...';
				}

				return $alt_text;
			}
		}

		return '';
	}
}
