<?php
/**
 * Media URL utilities for ensuring local WordPress URLs.
 *
 * This utility class provides methods for retrieving local WordPress media URLs
 * instead of external CDN/offloaded URLs. This is important for ensuring that
 * the chat client shows local WordPress URLs even when media offloading plugins
 * (like WP Offload Media) are active.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media URL utility class.
 *
 * Provides SoC-compliant helper methods for URL handling that can be used
 * across tools and services without duplication.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Media_URL_Utils {

	/**
	 * Get the local WordPress URL for an uploaded file.
	 *
	 * This method prefers the URL from wp_upload_bits() over wp_get_attachment_url()
	 * to ensure we always return the local WordPress upload directory URL, not an
	 * external URL from media offloading plugins (OneDrive, S3, etc.).
	 *
	 * Use this when you want to ensure the chat client displays the local WordPress
	 * media URL, regardless of whether media offloading is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param array $upload        Upload result from wp_upload_bits() containing 'url', 'file', 'error'.
	 * @param int   $attachment_id Optional. Attachment ID to fall back to if upload URL not available.
	 * @return string Local WordPress media URL, or empty string if not available.
	 */
	public static function get_local_upload_url( $upload, $attachment_id = 0 ) {
		// Prefer the upload URL as it's always the local WordPress URL.
		if ( isset( $upload['url'] ) && '' !== $upload['url'] ) {
			return $upload['url'];
		}

		// Fallback to wp_get_attachment_url if upload URL not available.
		// Note: This may return an external URL if offloading plugins are active.
		if ( $attachment_id > 0 ) {
			$url = wp_get_attachment_url( $attachment_id );
			return $url ? $url : '';
		}

		return '';
	}

	/**
	 * Build an attachment result array with local WordPress URLs.
	 *
	 * This method creates a standardized result array for tools/services that
	 * save files to the media library, ensuring local URLs are used.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $upload        Upload result from wp_upload_bits().
	 * @return array Array with 'attachment_id' and 'url' keys.
	 */
	public static function build_attachment_result( $attachment_id, $upload ) {
		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => self::get_local_upload_url( $upload, $attachment_id ),
		);
	}
}
