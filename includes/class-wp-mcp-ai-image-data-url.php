<?php
/**
 * Inline image data-URL helper for provider payloads.
 *
 * Some providers (OpenAI Chat Completions/Responses, DeepSeek) accept image
 * references as remote URLs that their own servers must fetch. When the
 * WordPress site sits behind hotlink protection, basic auth, a CDN, or has
 * just written a new upload, that provider-side fetch can fail while the
 * WordPress server can still read the bytes locally. This helper converts an
 * image segment into a base64 data URL using the WordPress server as the
 * fetcher, so the provider receives the image inline instead of a URL.
 *
 * Oversized images are downscaled to a vision-tile-sized dimension cap and
 * opaque PNGs are re-encoded as JPEG before inlining, keeping provider
 * payloads (and vision token costs, which scale with resolution) lean.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Image_Data_Url' ) ) {
	/**
	 * Converts image message segments into base64 data URLs.
	 *
	 * Oversized images are downscaled and (when opaque) re-encoded as JPEG
	 * before inlining so provider payloads stay lean — see optimize_body().
	 */
	class WP_MCP_AI_Image_Data_Url {

		/**
		 * Maximum inline image size in bytes.
		 *
		 * Mirrors the Anthropic client cap; larger images keep their URL
		 * fallback instead of inflating the provider payload.
		 */
		const MAX_INLINE_BYTES = 10485760; // 10 * 1024 * 1024.

		/**
		 * Minimum size in bytes for the inline-optimization pass.
		 *
		 * Re-encoding every image would add an editor round-trip for no gain;
		 * only images above this size are candidates for downscale/JPEG
		 * conversion.
		 */
		const OPTIMIZE_MIN_BYTES = 1048576; // 1MB.

		/**
		 * Longest edge (pixels) allowed for optimized inline images.
		 *
		 * Vision endpoints tile their input at ~2048px, so anything larger is
		 * wasted payload.
		 */
		const OPTIMIZE_MAX_DIMENSION = 2048;

		/**
		 * JPEG output quality for the inline-optimization pass.
		 */
		const OPTIMIZE_JPEG_QUALITY = 82;

		/**
		 * MIME types that may be inlined as data URLs.
		 *
		 * Raster types the OpenAI-compatible vision endpoints accept. SVG and
		 * other formats are deliberately excluded — providers reject them as
		 * inline data, so they keep their URL fallback.
		 */
		const INLINE_MIME_TYPES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

		/**
		 * Convert an image segment into a base64 data URL.
		 *
		 * Reads the local attachment file first (fastest, no HTTP), then falls
		 * back to a server-side download of the segment URL (covers offloaded
		 * media and remote URLs). Returns an empty string when the bytes
		 * cannot be obtained or should not be inlined (failed download,
		 * oversized payload, non-inlineable MIME) so callers can keep the
		 * original URL as a fallback.
		 *
		 * @since 1.1.92
		 *
		 * @param array $segment Image segment of type input_image / image_url.
		 * @return string Data URL (data:image/<mime>;base64,<data>) or empty string.
		 */
		public static function from_segment( array $segment ) {
			$existing_url = '';
			if ( isset( $segment['image_url']['url'] ) && is_string( $segment['image_url']['url'] ) ) {
				$existing_url = $segment['image_url']['url'];
			} elseif ( isset( $segment['image_url'] ) && is_string( $segment['image_url'] ) ) {
				$existing_url = $segment['image_url'];
			} elseif ( isset( $segment['url'] ) && is_string( $segment['url'] ) ) {
				$existing_url = $segment['url'];
			}

			// Already inline: pass through so callers keep their value.
			if ( 0 === stripos( $existing_url, 'data:image/' ) ) {
				return $existing_url;
			}

			$max_bytes = (int) apply_filters( 'wp_mcp_ai_image_inline_max_bytes', self::MAX_INLINE_BYTES );
			$max_bytes = max( 1024, $max_bytes );

			$allowed_mimes = apply_filters( 'wp_mcp_ai_image_inline_mime_types', self::INLINE_MIME_TYPES );
			$allowed_mimes = array_filter( array_map( 'sanitize_mime_type', (array) $allowed_mimes ) );

			$mime_type = '';
			if ( ! empty( $segment['mime_type'] ) ) {
				$mime_type = sanitize_mime_type( (string) wp_unslash( $segment['mime_type'] ) );
			}

			$attachment_id  = isset( $segment['attachment_id'] ) ? absint( $segment['attachment_id'] ) : 0;
			$attachment_url = $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '';

			if ( '' === $mime_type && $attachment_id > 0 ) {
				$post_mime = get_post_mime_type( $attachment_id );
				if ( is_string( $post_mime ) && '' !== $post_mime ) {
					$mime_type = sanitize_mime_type( $post_mime );
				}
			}

			$body = '';

			// Fastest path: read the attachment file straight off disk.
			if ( $attachment_id > 0 ) {
				$file_path = get_attached_file( $attachment_id );

				if ( '' === $mime_type && ! empty( $file_path ) ) {
					$mime_type = sanitize_mime_type( (string) wp_check_filetype( basename( $file_path ) )['type'] );
				}

				if ( ! empty( $file_path ) && file_exists( $file_path ) && self::is_inline_mime( $mime_type, $allowed_mimes ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local attachment file; not a remote URL.
					$read = file_get_contents( $file_path );
					if ( false !== $read ) {
						$body = $read;
					}
				}
			}

			// Fall back to a server-side download of the URL.
			if ( '' === $body ) {
				$url = '' !== $existing_url ? $existing_url : $attachment_url;
				$url = esc_url_raw( $url );

				if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
					return '';
				}

				$response = wp_remote_get(
					$url,
					array(
						'timeout'     => 30,
						'redirection' => 3,
						'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
					)
				);

				if ( is_wp_error( $response ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_error(
							'Image data URL: failed to download image for provider payload.',
							array(
								'url'   => $url,
								'error' => $response->get_error_message(),
							)
						);
					}

					return '';
				}

				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code < 200 || $status_code >= 300 ) {
					return '';
				}

				$content_length = wp_remote_retrieve_header( $response, 'content-length' );
				if ( ! empty( $content_length ) && absint( $content_length ) > $max_bytes ) {
					return '';
				}

				$body = wp_remote_retrieve_body( $response );
				if ( '' === $body ) {
					return '';
				}

				if ( '' === $mime_type ) {
					$content_type = wp_remote_retrieve_header( $response, 'content-type' );
					if ( ! empty( $content_type ) ) {
						$parts     = explode( ';', (string) $content_type );
						$mime_type = sanitize_mime_type( strtolower( trim( $parts[0] ) ) );
					}
				}

				if ( '' === $mime_type ) {
					$path      = wp_parse_url( $url, PHP_URL_PATH );
					$mime_type = sanitize_mime_type( (string) wp_check_filetype( (string) $path )['type'] );
				}
			}

			if ( '' === $body ) {
				return '';
			}

			if ( ! self::is_inline_mime( $mime_type, $allowed_mimes ) ) {
				return '';
			}

			// Shrink oversized payloads (dimension cap + opaque-PNG→JPEG) so
			// the provider request stays lean; falls back to original bytes.
			$optimized = self::optimize_body( $body, $mime_type, $max_bytes );
			if ( '' === $optimized ) {
				return '';
			}
			if ( null !== $optimized ) {
				$body      = $optimized['body'];
				$mime_type = $optimized['mime_type'];
			}

			if ( strlen( $body ) > $max_bytes ) {
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary image data for provider payloads, not obfuscation.
			return 'data:' . $mime_type . ';base64,' . base64_encode( $body );
		}

		/**
		 * Downscale / re-encode image bytes so the inline payload stays lean.
		 *
		 * Applies only when the wp_mcp_ai_image_inline_optimize filter is
		 * enabled, the image is a JPEG/PNG, and it is above the minimum byte
		 * size or beyond the dimension cap (well-compressed images can be
		 * small in bytes yet still oversized for vision endpoints, whose
		 * token costs scale with resolution). Opaque PNGs (no alpha channel,
		 * per the IHDR color type) are re-encoded as JPEG; dimensions above
		 * the cap are scaled down. GIFs stay untouched (animation) and WebP
		 * is already efficient.
		 *
		 * @since 1.1.92
		 *
		 * @param string $body      Raw image bytes.
		 * @param string $mime_type Sanitized MIME type.
		 * @param int    $max_bytes Inline size cap.
		 * @return array|null|string Optimized [body,mime] pair, null when the
		 *                           original bytes should be used unchanged, or
		 *                           '' when the image is over the cap and
		 *                           cannot be optimized.
		 */
		protected static function optimize_body( $body, $mime_type, $max_bytes ) {
			// The admin toggle (Chat Client → Features) supplies the default;
			// the filter lets code override it per request.
			$optimize_default = true;
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();
				if ( isset( $settings['chat_inline_image_optimization'] ) ) {
					$optimize_default = (bool) $settings['chat_inline_image_optimization'];
				}
			}

			if ( ! apply_filters( 'wp_mcp_ai_image_inline_optimize', $optimize_default ) ) {
				return null;
			}

			if ( 'image/jpeg' !== $mime_type && 'image/png' !== $mime_type ) {
				return null;
			}

			$min_bytes = (int) apply_filters( 'wp_mcp_ai_image_inline_optimize_min_bytes', self::OPTIMIZE_MIN_BYTES );
			$min_bytes = max( 1024, $min_bytes );

			$max_dimension = (int) apply_filters( 'wp_mcp_ai_image_inline_max_dimension', self::OPTIMIZE_MAX_DIMENSION );
			$max_dimension = max( 64, $max_dimension );

			$dimensions        = self::image_dimensions( $body, $mime_type );
			$exceeds_dimension = null !== $dimensions && ( $dimensions[0] > $max_dimension || $dimensions[1] > $max_dimension );

			if ( strlen( $body ) <= $min_bytes && ! $exceeds_dimension ) {
				return null;
			}

			$target_mimes = array( $mime_type );
			if ( 'image/png' === $mime_type && self::png_has_no_alpha( $body ) ) {
				// Opaque PNGs re-encode far smaller as JPEG; keep PNG as a
				// fallback candidate for images where JPEG wins on size.
				$target_mimes = array( 'image/jpeg', 'image/png' );
			}

			$jpeg_quality = (int) apply_filters( 'wp_mcp_ai_image_inline_jpeg_quality', self::OPTIMIZE_JPEG_QUALITY );
			$jpeg_quality = min( 100, max( 1, $jpeg_quality ) );

			foreach ( $target_mimes as $candidate_mime ) {
				$optimized = self::reencode_image( $body, $candidate_mime, $max_dimension, $jpeg_quality );
				if ( null !== $optimized && strlen( $optimized ) < strlen( $body ) && strlen( $optimized ) <= $max_bytes ) {
					return array(
						'body'      => $optimized,
						'mime_type' => $candidate_mime,
					);
				}
			}

			return strlen( $body ) > $max_bytes ? '' : null;
		}

		/**
		 * Cheaply read pixel dimensions from an image header.
		 *
		 * PNG dimensions live in the IHDR chunk; JPEG dimensions live in the
		 * first SOF marker. Returns null when the dimensions cannot be read.
		 *
		 * @param string $body      Raw image bytes.
		 * @param string $mime_type Sanitized MIME type.
		 * @return array|null Array of [width, height], or null.
		 */
		protected static function image_dimensions( $body, $mime_type ) {
			if ( 'image/png' === $mime_type ) {
				if ( strlen( $body ) < 24 || "\x89PNG\r\n\x1a\n" !== substr( $body, 0, 8 ) ) {
					return null;
				}

				$dimensions = unpack( 'Nwidth/Nheight', substr( $body, 16, 8 ) );

				return array( (int) $dimensions['width'], (int) $dimensions['height'] );
			}

			if ( 'image/jpeg' === $mime_type ) {
				$length = strlen( $body );
				if ( $length < 4 || "\xFF\xD8" !== substr( $body, 0, 2 ) ) {
					return null;
				}

				// SOF markers carry the frame dimensions right after their
				// precision byte: height (2 bytes) then width (2 bytes).
				$sof_markers = array( 0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF );
				$offset      = 2;

				while ( $offset + 9 <= $length ) {
					if ( "\xFF" !== $body[ $offset ] ) {
						++$offset;
						continue;
					}

					$marker = ord( $body[ $offset + 1 ] );

					if ( in_array( $marker, $sof_markers, true ) ) {
						$dimensions = unpack( 'nheight/nwidth', substr( $body, $offset + 5, 4 ) );

						return array( (int) $dimensions['width'], (int) $dimensions['height'] );
					}

					// Standalone markers without a length field.
					if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD9 ) ) {
						$offset += 2;
						continue;
					}

					if ( $offset + 4 > $length ) {
						break;
					}

					$segment = unpack( 'nlength', substr( $body, $offset + 2, 2 ) );
					$offset += 2 + (int) $segment['length'];
				}

				return null;
			}

			return null;
		}

		/**
		 * Re-encode image bytes through the WordPress image editor.
		 *
		 * @param string $body          Raw image bytes.
		 * @param string $target_mime   Output MIME type.
		 * @param int    $max_dimension Longest edge cap (never upscales).
		 * @param int    $jpeg_quality  JPEG output quality.
		 * @return string|null Optimized bytes, or null when unavailable.
		 */
		protected static function reencode_image( $body, $target_mime, $max_dimension, $jpeg_quality ) {
			if ( ! function_exists( 'wp_get_image_editor' ) || ! function_exists( 'wp_tempnam' ) ) {
				return null;
			}

			$tmp = wp_tempnam( 'wp-mcp-ai-imgopt-' );
			if ( ! $tmp ) {
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing bytes to a temp file for the WP image editor.
			if ( false === file_put_contents( $tmp, $body ) ) {
				wp_delete_file( $tmp );

				return null;
			}

			$editor = wp_get_image_editor( $tmp );
			if ( is_wp_error( $editor ) ) {
				wp_delete_file( $tmp );

				return null;
			}

			$size = $editor->get_size();
			if ( ! is_wp_error( $size ) && ( (int) $size['width'] > $max_dimension || (int) $size['height'] > $max_dimension ) ) {
				$resized = $editor->resize( $max_dimension, $max_dimension );
				if ( is_wp_error( $resized ) ) {
					wp_delete_file( $tmp );

					return null;
				}
			}

			$editor->set_quality( $jpeg_quality );

			$saved = $editor->save( $tmp . '-opt', $target_mime );

			if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
				wp_delete_file( $tmp );

				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a temp file written by the WP image editor.
			$optimized = file_get_contents( $saved['path'] );

			wp_delete_file( $tmp );
			wp_delete_file( $saved['path'] );

			return ( false !== $optimized && '' !== $optimized ) ? $optimized : null;
		}

		/**
		 * Whether a PNG stream declares no alpha channel (IHDR color type).
		 *
		 * Only color types 0 (grayscale) and 2 (truecolor) are provably
		 * opaque from the header alone; palette images (3) may carry a tRNS
		 * chunk and 4/6 have real alpha, so those keep their PNG encoding.
		 *
		 * @param string $body Raw PNG bytes.
		 * @return bool
		 */
		protected static function png_has_no_alpha( $body ) {
			if ( strlen( $body ) < 26 || "\x89PNG\r\n\x1a\n" !== substr( $body, 0, 8 ) ) {
				return false;
			}

			$color_type = ord( $body[25] );

			return 0 === $color_type || 2 === $color_type;
		}

		/**
		 * Whether a MIME type may be inlined as a provider data URL.
		 *
		 * @param string   $mime_type     Sanitized MIME type.
		 * @param string[] $allowed_mimes Allowlisted MIME types.
		 * @return bool
		 */
		protected static function is_inline_mime( $mime_type, array $allowed_mimes ) {
			return '' !== $mime_type && in_array( strtolower( $mime_type ), array_map( 'strtolower', $allowed_mimes ), true );
		}
	}
}
