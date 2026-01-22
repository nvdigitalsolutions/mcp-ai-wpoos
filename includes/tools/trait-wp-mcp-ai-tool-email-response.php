<?php
/**
 * Email Response Trait
 *
 * Provides methods for rendering email template previews in assistant responses.
 * Features sandboxed iframe preview, email metadata display, and download options.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for adding email template previews to tool responses.
 *
 * Usage:
 * ```php
 * class My_Email_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Email_Response;
 *
 *     public function execute( $arguments, $context ) {
 *         $result = array(
 *             'html' => '<html>...</html>',
 *             'subject' => 'Newsletter Title',
 *             'preview_text' => 'Preview...',
 *             'template_type' => 'newsletter',
 *             'text' => 'Email generated successfully'
 *         );
 *         return $this->add_email_html_to_response( $result );
 *     }
 * }
 * ```
 *
 * @since 1.2.0
 */
trait WP_MCP_AI_Tool_Email_Response {

	/**
	 * Add email preview HTML to response.
	 *
	 * Generates a sandboxed iframe preview of the email template with metadata display.
	 *
	 * @since 1.2.0
	 *
	 * @param array $result Tool result array with 'html', 'subject', 'preview_text', etc.
	 * @return array Modified result with email preview in 'message' field.
	 */
	protected function add_email_html_to_response( array $result ) {
		if ( empty( $result['html'] ) ) {
			return $result;
		}

		$html          = $result['html'];
		$subject       = isset( $result['subject'] ) ? $result['subject'] : '';
		$preview_text  = isset( $result['preview_text'] ) ? $result['preview_text'] : '';
		$template_type = isset( $result['template_type'] ) ? $result['template_type'] : 'custom';

		// Generate preview HTML.
		$preview_html = $this->generate_email_preview_html( $html, $subject, $preview_text, $template_type );

		// Append to existing message or create new one.
		if ( isset( $result['message'] ) ) {
			$result['message'] .= "\n\n" . $preview_html;
		} else {
			$result['message'] = $preview_html;
		}

		// Preserve text for LLM.
		if ( ! isset( $result['text'] ) ) {
			$result['text'] = sprintf(
				'Email template generated: %s',
				$subject ? $subject : $template_type
			);
		}

		return $result;
	}

	/**
	 * Generate email preview HTML.
	 *
	 * Creates sandboxed iframe with email content and metadata display.
	 *
	 * @since 1.2.0
	 *
	 * @param string $html          Email HTML content.
	 * @param string $subject       Email subject line.
	 * @param string $preview_text  Email preview text.
	 * @param string $template_type Type of template.
	 * @return string HTML preview markup.
	 */
	private function generate_email_preview_html( $html, $subject, $preview_text, $template_type ) {
		// Use htmlspecialchars with ENT_QUOTES for srcdoc attribute
		$html_escaped = htmlspecialchars( $html, ENT_QUOTES, 'UTF-8' );

		// Email metadata.
		$metadata = array();
		if ( $subject ) {
			$metadata[] = '✉️ ' . esc_html( $subject );
		}
		if ( $template_type ) {
			$metadata[] = ucfirst( $template_type ) . ' template';
		}

		$metadata_html = '';
		if ( ! empty( $metadata ) ) {
			$metadata_html = '<p><strong>' . implode( ' • ', $metadata ) . '</strong></p>';
		}

		// Preview text (if provided).
		$preview_html = '';
		if ( $preview_text ) {
			$preview_html = '<p><em>' . esc_html( $preview_text ) . '</em></p>';
		}

		// Create sandboxed iframe for preview.
		$iframe_html = sprintf(
			'<div class="wp-mcp-ai-email-preview" style="border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin: 16px 0; background: #f9f9f9;">
				%s
				%s
				<div style="border: 1px solid #ccc; border-radius: 4px; background: white; overflow: hidden; margin-top: 12px;">
					<iframe 
						srcdoc="%s" 
						style="width: 100%%; height: 600px; border: none; display: block;"
						sandbox="allow-same-origin"
						title="Email Template Preview"
						loading="lazy">
					</iframe>
				</div>
				<p style="margin-top: 12px; font-size: 12px; color: #666;">
					💡 <strong>Preview:</strong> Interactive email template rendered above. Scroll to view full content.
				</p>
			</div>',
			$metadata_html,
			$preview_html,
			$html_escaped
		);

		return $iframe_html;
	}
}
