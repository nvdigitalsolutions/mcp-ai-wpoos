<?php
/**
 * Validated Upload Trait — Safe file uploads for tool-generated content.
 *
 * Provides a validated alternative to raw wp_upload_bits() calls. Validates
 * MIME types, blocks dangerous extensions, and sanitizes SVGs before storage.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'WP_MCP_AI_Trait_Validated_Upload' ) ) {
	/**
	 * Provides safe file upload methods for tools that generate content.
	 *
	 * Usage in a tool:
	 *   use WP_MCP_AI_Trait_Validated_Upload;
	 *
	 *   // Then replace:
	 *   //   $upload = wp_upload_bits( $name, null, $data );
	 *   // with:
	 *   //   $upload = $this->validated_upload_bits( $name, null, $data );
	 */
	trait WP_MCP_AI_Trait_Validated_Upload {

		/**
		 * Extensions that are always blocked regardless of content.
		 *
		 * @var array<string>
		 */
		private $blocked_extensions = array(
			'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
			'phar', 'pht', 'phps', 'shtml', 'sht', 'cgi', 'pl', 'py',
			'asp', 'aspx', 'jsp', 'jspx', 'exe', 'com', 'bat', 'cmd',
			'sh', 'bash', 'zsh', 'fish', 'dll', 'so', 'msi', 'vbs',
		);

		/**
		 * Upload file bits with MIME validation and extension blocking.
		 *
		 * @param string      $name     Desired filename.
		 * @param string|null $mime_type Expected MIME type (null to auto-detect).
		 * @param string      $bits     Raw file content.
		 * @return array|WP_Error Upload result array or WP_Error.
		 */
		protected function validated_upload_bits( $name, $mime_type, $bits ) {
			// Require WordPress file functions.
			if ( ! function_exists( 'wp_upload_bits' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			// Block empty files.
			if ( empty( $bits ) ) {
				return new WP_Error(
					'empty_file',
					__( 'File content is empty.', 'mcp-ai-wpoos' )
				);
			}

			// Validate MIME type.
			$mime_check = $this->validate_mime( $bits, $name, $mime_type );
			if ( is_wp_error( $mime_check ) ) {
				return $mime_check;
			}

			// Block dangerous extensions.
			$ext_check = $this->validate_extension( $name );
			if ( is_wp_error( $ext_check ) ) {
				return $ext_check;
			}

			// If SVG, sanitize before upload.
			if ( $this->is_svg( $name, $mime_type ) ) {
				$bits = $this->sanitize_svg_content( $bits );
				if ( is_wp_error( $bits ) ) {
					return $bits;
				}
			}

			return wp_upload_bits( $name, null, $bits );
		}

		/**
		 * Validate file MIME type against content and expected type.
		 *
		 * @param string      $bits     Raw file content.
		 * @param string      $name     Filename.
		 * @param string|null $expected Expected MIME type, or null.
		 * @return true|WP_Error
		 */
		private function validate_mime( $bits, $name, $expected ) {
			// Use WordPress core MIME detection.
			$wp_filetype = wp_check_filetype_and_ext( $bits, $name );

			// If WordPress can't determine the type, use fileinfo.
			if ( ! $wp_filetype['type'] && function_exists( 'finfo_open' ) ) {
				$finfo    = finfo_open( FILEINFO_MIME_TYPE );
				$detected = finfo_buffer( $finfo, $bits );
				finfo_close( $finfo );

				if ( $detected && 'application/octet-stream' !== $detected ) {
					$wp_filetype['type'] = $detected;
				}
			}

			// If we still can't determine the type and one was expected, use the expected.
			if ( ! $wp_filetype['type'] && ! empty( $expected ) ) {
				return true; // Trust the expected type since we couldn't detect.
			}

			// If neither detected nor expected, fail.
			if ( ! $wp_filetype['type'] ) {
				return new WP_Error(
					'unknown_mime_type',
					__( 'Could not determine file type.', 'mcp-ai-wpoos' )
				);
			}

			// If an expected type was given, verify it matches.
			if ( ! empty( $expected ) ) {
				$expected_base = explode( '/', $expected, 2 )[0];
				$detected_base = explode( '/', $wp_filetype['type'], 2 )[0];

				if ( $expected_base !== $detected_base ) {
					return new WP_Error(
						'mime_type_mismatch',
						sprintf(
							/* translators: 1=expected, 2=detected */
							__( 'File type mismatch: expected %1$s, detected %2$s.', 'mcp-ai-wpoos' ),
							esc_html( $expected ),
							esc_html( $wp_filetype['type'] )
						)
					);
				}
			}

			return true;
		}

		/**
		 * Check that the file extension is not in the blocked list.
		 *
		 * @param string $name Filename.
		 * @return true|WP_Error
		 */
		private function validate_extension( $name ) {
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( empty( $ext ) ) {
				return new WP_Error(
					'missing_extension',
					__( 'File has no extension.', 'mcp-ai-wpoos' )
				);
			}

			if ( in_array( $ext, $this->blocked_extensions, true ) ) {
				return new WP_Error(
					'blocked_extension',
					sprintf(
						/* translators: %s: file extension */
						__( 'File extension ".%s" is not allowed for security reasons.', 'mcp-ai-wpoos' ),
						esc_html( $ext )
					)
				);
			}

			/**
			 * Filter: allow plugins to add or remove blocked extensions.
			 *
			 * @param array  $blocked_extensions Current blocked list.
			 * @param string $name               Filename being checked.
			 */
			$blocked = apply_filters( 'wp_mcp_ai_validated_upload_blocked_extensions', $this->blocked_extensions, $name );

			if ( in_array( $ext, $blocked, true ) ) {
				return new WP_Error(
					'blocked_extension',
					sprintf(
						/* translators: %s: file extension */
						__( 'File extension ".%s" is not allowed for security reasons.', 'mcp-ai-wpoos' ),
						esc_html( $ext )
					)
				);
			}

			return true;
		}

		/**
		 * Determine if a file is an SVG.
		 *
		 * @param string      $name      Filename.
		 * @param string|null $mime_type MIME type.
		 * @return bool
		 */
		private function is_svg( $name, $mime_type ) {
			if ( 'image/svg+xml' === $mime_type ) {
				return true;
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			return 'svg' === $ext;
		}

		/**
		 * Sanitize SVG content by stripping dangerous elements and attributes.
		 *
		 * Removes <script> tags, event handler attributes (onload, onclick, etc.),
		 * <foreignObject> elements, and other potentially dangerous SVG features.
		 *
		 * @param string $svg_content Raw SVG XML.
		 * @return string|WP_Error Sanitized SVG or error if content is invalid.
		 */
		protected function sanitize_svg_content( $svg_content ) {
			if ( empty( trim( $svg_content ) ) ) {
				return new WP_Error(
					'empty_svg',
					__( 'SVG content is empty.', 'mcp-ai-wpoos' )
				);
			}

			// Verify it looks like SVG.
			if ( false === stripos( $svg_content, '<svg' ) ) {
				return new WP_Error(
					'invalid_svg',
					__( 'Content does not appear to be valid SVG.', 'mcp-ai-wpoos' )
				);
			}

			$sanitized = $svg_content;

			// Remove XML processing instructions.
			$sanitized = preg_replace( '/<\?xml[^?]*\?>/i', '', $sanitized );

			// Remove DOCTYPE declarations.
			$sanitized = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $sanitized );

			// Remove CDATA sections that may contain script.
			$sanitized = preg_replace( '/<!\[CDATA\[.*?\]\]>/is', '', $sanitized );

			// Remove <script> blocks.
			$sanitized = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $sanitized );

			// Remove event handler attributes (onload, onclick, onerror, etc.).
			$sanitized = preg_replace( '/\bon\w+\s*=\s*"[^"]*"/i', '', $sanitized );
			$sanitized = preg_replace( '/\bon\w+\s*=\s*\'[^\']*\'/i', '', $sanitized );

			// Remove <foreignObject> which can embed HTML (including scripts).
			$sanitized = preg_replace( '/<foreignObject[^>]*>.*?<\/foreignObject>/is', '', $sanitized );

			// Remove xlink:href attributes pointing to javascript: URIs.
			$sanitized = preg_replace(
				'/(?:xlink:)?href\s*=\s*["\']javascript:[^"\']*["\']/i',
				'href=""',
				$sanitized
			);

			// Remove use of the <use> element which can reference external resources.
			// We keep <use> but strip external references (xlink:href starting with http/file).
			$sanitized = preg_replace(
				'/(?:xlink:)?href\s*=\s*["\'](?:https?|file):[^"\']*["\']/i',
				'href=""',
				$sanitized
			);

			return $sanitized;
		}
	}
}
