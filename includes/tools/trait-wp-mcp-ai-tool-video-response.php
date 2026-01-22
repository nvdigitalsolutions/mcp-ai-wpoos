<?php
/**
 * Trait for adding rendered video HTML to video generation tool responses.
 *
 * This trait provides a standardized way to include WordPress VIDEO tags in tool
 * responses, ensuring users can see and play newly created videos directly in the chat UI.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Video_Response
 *
 * Provides helper methods to add rendered video HTML to tool responses.
 * This ensures that video generation tools return not just URLs but actual
 * playable VIDEO tags that users can watch in the chat interface.
 *
 * Usage:
 * ```php
 * class My_Video_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Video_Response;
 *
 *     public function execute( $arguments, $context ) {
 *         // Generate video and get attachment_id, url, etc.
 *         $result = array(
 *             'attachment_id' => $attachment_id,
 *             'url' => $url,
 *             'text' => 'Video generated successfully',
 *         );
 *
 *         // Add rendered video HTML to response
 *         return $this->add_video_html_to_response( $result );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Video_Response {

	/**
	 * Add rendered video HTML to a tool response.
	 *
	 * This method takes a video generation result and adds a rendered VIDEO tag
	 * to the 'message' field, making the video immediately playable in the chat UI.
	 *
	 * @param array $result Tool result containing attachment_id or url and optionally text/message.
	 * @return array Modified result with video HTML added to message field.
	 */
	protected function add_video_html_to_response( array $result ) {
		// Check if we have either attachment_id or direct URL.
		$video_url = '';
		
		if ( ! empty( $result['attachment_id'] ) ) {
			$attachment_id = absint( $result['attachment_id'] );
			
			// Verify attachment exists and is video.
			if ( wp_attachment_is( 'video', $attachment_id ) ) {
				$video_url = wp_get_attachment_url( $attachment_id );
			}
		} elseif ( ! empty( $result['url'] ) ) {
			// Use direct URL (for temporary videos or external sources).
			$video_url = esc_url( $result['url'] );
		}

		if ( empty( $video_url ) ) {
			return $result;
		}

		// Get video metadata.
		$title = isset( $result['title'] ) ? $result['title'] : '';
		if ( empty( $title ) && ! empty( $result['attachment_id'] ) ) {
			$title = get_the_title( $result['attachment_id'] );
		}
		if ( empty( $title ) && ! empty( $result['prompt'] ) ) {
			$title = $result['prompt'];
		}

		// Generate the video HTML.
		$video_html = $this->generate_video_html( $video_url, $title, $result );

		// Get existing text message.
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered video.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $video_html : $video_html;

		return $result;
	}

	/**
	 * Generate clean, optimized video HTML tag.
	 *
	 * Creates a video player with standard controls and accessibility features.
	 *
	 * @param string $video_url Video file URL.
	 * @param string $title     Title/description for accessibility.
	 * @param array  $result    Full result array (for extracting metadata).
	 * @return string HTML video tag.
	 */
	protected function generate_video_html( $video_url, $title = '', array $result = array() ) {
		if ( empty( $video_url ) ) {
			return '';
		}

		// Get dimensions if available.
		$width  = isset( $result['width'] ) ? absint( $result['width'] ) : 640;
		$height = isset( $result['height'] ) ? absint( $result['height'] ) : 360;

		// If we have attachment metadata, use those dimensions.
		if ( ! empty( $result['attachment_id'] ) ) {
			$metadata = wp_get_attachment_metadata( $result['attachment_id'] );
			if ( ! empty( $metadata['width'] ) ) {
				$width = absint( $metadata['width'] );
			}
			if ( ! empty( $metadata['height'] ) ) {
				$height = absint( $metadata['height'] );
			}
		}

		// Get poster image if available.
		$poster = '';
		if ( ! empty( $result['thumbnail_url'] ) ) {
			$poster = esc_url( $result['thumbnail_url'] );
		} elseif ( ! empty( $result['attachment_id'] ) ) {
			$thumbnail_id = get_post_thumbnail_id( $result['attachment_id'] );
			if ( $thumbnail_id ) {
				$poster = wp_get_attachment_image_url( $thumbnail_id, 'large' );
			}
		}

		// Build video tag.
		$html = '<video';
		
		if ( ! empty( $width ) ) {
			$html .= ' width="' . $width . '"';
		}
		
		if ( ! empty( $height ) ) {
			$html .= ' height="' . $height . '"';
		}
		
		// Add controls for playback.
		$html .= ' controls';
		
		// Add preload metadata for faster initial display.
		$html .= ' preload="metadata"';
		
		// Add poster if available.
		if ( ! empty( $poster ) ) {
			$html .= ' poster="' . esc_url( $poster ) . '"';
		}
		
		// Add CSS class for styling.
		$html .= ' class="wp-mcp-ai-generated-video"';
		
		// Add title for accessibility.
		if ( ! empty( $title ) ) {
			$html .= ' title="' . esc_attr( $title ) . '"';
		}
		
		$html .= '>';
		
		// Add source element with proper MIME type.
		$mime_type = isset( $result['mime_type'] ) ? $result['mime_type'] : 'video/mp4';
		$html .= '<source src="' . esc_url( $video_url ) . '" type="' . esc_attr( $mime_type ) . '">';
		
		// Fallback content for browsers that don't support video tag.
		$html .= '<p>' . __( 'Your browser does not support the video tag.', 'mcp-ai-wpoos' ) . ' ';
		$html .= '<a href="' . esc_url( $video_url ) . '">' . __( 'Download video', 'mcp-ai-wpoos' ) . '</a></p>';
		
		$html .= '</video>';

		return $html;
	}
}
