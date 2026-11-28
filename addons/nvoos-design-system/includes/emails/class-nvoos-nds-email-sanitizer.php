<?php
/**
 * NV oOS Design System — Email HTML safety validator.
 *
 * Email templates legitimately contain `<style>` blocks, VML and conditional
 * comments, so a destructive KSES pass would break Outlook rendering. This
 * validator instead rejects active content (scripts, frames, event handlers,
 * script URLs) before a template is stored. The preview is additionally
 * rendered in a sandboxed iframe.
 *
 * @package NV_oOS_Design_System
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates email template HTML before storage.
 *
 * @since 1.0.0
 */
class NV_oOS_Design_System_Email_Sanitizer {

	/**
	 * Maximum accepted template size in bytes (1 MB).
	 *
	 * @var int
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Validate template HTML.
	 *
	 * @param string $html Template HTML.
	 * @return true|\WP_Error True when safe, WP_Error otherwise.
	 */
	public static function validate( $html ) {
		if ( ! is_string( $html ) ) {
			return new WP_Error(
				'nds_email_invalid_html',
				__( 'Email template content must be a string.', 'nvoos-design-system' )
			);
		}

		if ( strlen( $html ) > self::MAX_BYTES ) {
			return new WP_Error(
				'nds_email_html_too_large',
				__( 'Email template content exceeds the 1 MB limit.', 'nvoos-design-system' )
			);
		}

		$patterns = array(
			// Active elements. Email clients strip these; a stored draft must
			// never carry them into an admin preview either.
			'/<\s*script\b/i',
			'/<\s*iframe\b/i',
			'/<\s*object\b/i',
			'/<\s*embed\b/i',
			'/<\s*form\b/i',
			'/<\s*base\b/i',
			'/<\s*link\b/i',
			// Meta refresh navigation.
			'/<meta[^>]+http-equiv\s*=\s*["\']?\s*refresh/i',
			// Inline event handlers (`content=` is not matched: it needs a
			// preceding whitespace before `on`).
			'/\son[a-z]+\s*=/i',
			// Script-bearing URLs.
			'/(?:href|src)\s*=\s*["\']?\s*(?:javascript|vbscript|data\s*:\s*text\/html)/i',
			// Legacy IE active CSS.
			'/expression\s*\(/i',
			'/behavior\s*:/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				return new WP_Error(
					'nds_email_unsafe_html',
					__( 'Email template contains active content (scripts, frames, forms or event handlers), which is not allowed.', 'nvoos-design-system' )
				);
			}
		}

		return true;
	}

	/**
	 * Whether template HTML is safe to store.
	 *
	 * @param string $html Template HTML.
	 * @return bool
	 */
	public static function is_safe( $html ) {
		return true === self::validate( $html );
	}
}
