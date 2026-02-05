<?php
/**
 * Trait for adding rendered audio HTML to audio generation tool responses.
 *
 * This trait provides a standardized way to include WordPress AUDIO tags in tool
 * responses, ensuring users can listen to newly created audio directly in the chat UI.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Audio_Response
 *
 * Provides helper methods to add rendered audio HTML to tool responses.
 * This ensures that audio generation tools (speech, music) return not just URLs
 * but actual playable AUDIO tags that users can listen to in the chat interface.
 *
 * Usage:
 * ```php
 * class My_Audio_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Audio_Response;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         // Generate audio and get attachment_id, url, etc.
 *         $result = array(
 *             'attachment_id' => $attachment_id,
 *             'url' => $url,
 *             'text' => 'Audio generated successfully',
 *         );
 *
 *         // Add rendered audio HTML to response
 *         return $this->add_audio_html_to_response( $result );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Audio_Response {

	/**
	 * Add rendered audio HTML to a tool response.
	 *
	 * This method takes an audio generation result and adds a rendered AUDIO tag
	 * to the 'message' field, making the audio immediately playable in the chat UI.
	 *
	 * @param array $result Tool result containing attachment_id or url and optionally text/message.
	 * @return array Modified result with audio HTML added to message field.
	 */
	protected function add_audio_html_to_response( array $result ) {
		// Check if we have either attachment_id or direct URL.
		$audio_url = '';

		if ( ! empty( $result['attachment_id'] ) ) {
			$attachment_id = absint( $result['attachment_id'] );

			// Verify attachment exists and is audio.
			if ( wp_attachment_is( 'audio', $attachment_id ) ) {
				$audio_url = wp_get_attachment_url( $attachment_id );
			}
		} elseif ( ! empty( $result['url'] ) ) {
			// Use direct URL (for temporary audio or external sources).
			$audio_url = esc_url( $result['url'] );
		}

		if ( empty( $audio_url ) ) {
			return $result;
		}

		// Get audio metadata.
		$title = isset( $result['title'] ) ? $result['title'] : '';
		if ( empty( $title ) && ! empty( $result['attachment_id'] ) ) {
			$title = get_the_title( $result['attachment_id'] );
		}
		if ( empty( $title ) && ! empty( $result['prompt'] ) ) {
			$title = $result['prompt'];
		}
		if ( empty( $title ) && ! empty( $result['text'] ) && is_string( $result['text'] ) ) {
			// For TTS, the input text is often short and descriptive.
			$title = $result['text'];
		}

		// Generate the audio HTML.
		$audio_html = $this->generate_audio_html( $audio_url, $title, $result );

		// Get existing text message.
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered audio.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $audio_html : $audio_html;

		return $result;
	}

	/**
	 * Generate clean, optimized audio HTML tag.
	 *
	 * Creates an audio player with standard controls and accessibility features.
	 *
	 * @param string $audio_url Audio file URL.
	 * @param string $title     Title/description for accessibility.
	 * @param array  $result    Full result array (for extracting metadata).
	 * @return string HTML audio tag.
	 */
	protected function generate_audio_html( $audio_url, $title = '', array $result = array() ) {
		if ( empty( $audio_url ) ) {
			return '';
		}

		// Build audio tag.
		$html = '<audio';

		// Add controls for playback.
		$html .= ' controls';

		// Add preload metadata for faster initial display.
		$html .= ' preload="metadata"';

		// Add CSS class for styling.
		$html .= ' class="wp-mcp-ai-generated-audio"';

		// Add title for accessibility and display.
		if ( ! empty( $title ) ) {
			$truncated_title = strlen( $title ) > 100 ? substr( $title, 0, 97 ) . '...' : $title;
			$html           .= ' title="' . esc_attr( $truncated_title ) . '"';
		}

		$html .= '>';

		// Add source element with proper MIME type.
		$mime_type = isset( $result['mime_type'] ) ? $result['mime_type'] : 'audio/mpeg';
		$html     .= '<source src="' . esc_url( $audio_url ) . '" type="' . esc_attr( $mime_type ) . '">';

		// Fallback content for browsers that don't support audio tag.
		$html .= '<p>' . __( 'Your browser does not support the audio tag.', 'mcp-ai-wpoos' ) . ' ';
		$html .= '<a href="' . esc_url( $audio_url ) . '">' . __( 'Download audio', 'mcp-ai-wpoos' ) . '</a></p>';

		$html .= '</audio>';

		// Add optional metadata display (voice, model, format).
		$metadata_parts = array();

		if ( ! empty( $result['voice'] ) ) {
			/* translators: %s: Voice name */
			$metadata_parts[] = sprintf( __( 'Voice: %s', 'mcp-ai-wpoos' ), esc_html( $result['voice'] ) );
		}

		if ( ! empty( $result['model'] ) ) {
			/* translators: %s: Model name */
			$metadata_parts[] = sprintf( __( 'Model: %s', 'mcp-ai-wpoos' ), esc_html( $result['model'] ) );
		}

		if ( ! empty( $result['format'] ) ) {
			/* translators: %s: Audio format */
			$metadata_parts[] = sprintf( __( 'Format: %s', 'mcp-ai-wpoos' ), strtoupper( esc_html( $result['format'] ) ) );
		}

		if ( ! empty( $metadata_parts ) ) {
			$html .= '<p class="wp-mcp-ai-audio-metadata" style="font-size: 0.9em; color: #666; margin-top: 0.5em;">';
			$html .= implode( ' | ', $metadata_parts );
			$html .= '</p>';
		}

		return $html;
	}
}
