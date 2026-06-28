<?php
/**
 * Shared trait for harmonization tools.
 *
 * Provides input resolution (attachment ID / public URL / `file-xxx` chat upload ID),
 * working-file lifecycle, provider auto-selection, common auth/multisite gating, and
 * response shaping so all 14 harmonization tools share one source of truth.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared functionality for harmonization tools.
 *
 * @since 1.1.0
 */
trait WP_MCP_AI_Tool_Harmonization {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile -- single trait per file.

	/**
	 * Build the standard "image source" parameters schema used by harmonization tools.
	 *
	 * Each tool can drop these keys into its own schema and add its own knobs on top.
	 * The schema follows the OpenAI compatibility rules from CLAUDE.md (no `mixed`
	 * types, arrays declare `items`, unions are expressed via `anyOf`).
	 *
	 * @param string $param_label Optional. Description label for the input (e.g. "subject").
	 *
	 * @return array Associative array keyed by parameter name.
	 */
	protected function harmonization_get_image_input_schema( $param_label = 'image' ) {
		return array(
			'attachment_id' => array(
				'anyOf'       => array(
					array( 'type' => 'integer' ),
					array( 'type' => 'string' ),
				),
				'description' => sprintf(
					/* translators: %s: parameter label */
					__( 'WordPress attachment ID, public image URL (https://...), or chat-upload file_id (e.g. "file-abc123") for the %s.', 'mcp-ai-wpoos-pro' ),
					$param_label
				),
			),
		);
	}

	/**
	 * Authenticate and authorize the calling user the same way `product_actualization`
	 * does. Centralised so every harmonization tool enforces the rules identically.
	 *
	 * @param array $context Tool execution context.
	 *
	 * @return int|WP_Error Resolved user id (0 if token-authenticated) or WP_Error.
	 */
	protected function harmonization_authorize( array $context ) {
		$user_id   = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to use harmonization tools.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to use harmonization tools.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		return $user_id;
	}

	/**
	 * Resolve a flexible image input (attachment ID, public URL, or `file-xxx`)
	 * to a working file path on disk.
	 *
	 * Mirrors the resolution logic used inside `WP_MCP_AI_Pro_Tool_Product_Actualization`
	 * so harmonization tools accept the same input shapes.
	 *
	 * @param mixed  $input        Attachment id (int|numeric string), URL, or file_id.
	 * @param string $input_label  Human label used in error messages.
	 *
	 * @return array|WP_Error {
	 *     @type string $file_path   Absolute path to a working copy of the source.
	 *     @type int    $attachment_id Resolved attachment id, or 0 if input was a URL.
	 *     @type bool   $is_temp     True when caller owns the lifecycle (URL/file_id input).
	 * }
	 */
	protected function harmonization_resolve_input( $input, $input_label = 'image' ) {
		if ( is_string( $input ) && '' !== $input && $this->harmonization_is_valid_http_url( $input ) ) {
			$download = $this->harmonization_download_url_to_temp( $input );
			if ( is_wp_error( $download ) ) {
				return $download;
			}
			return array(
				'file_path'     => $download['file_path'],
				'attachment_id' => 0,
				'is_temp'       => true,
			);
		}

		$attachment_id = 0;
		if ( is_int( $input ) || ( is_string( $input ) && is_numeric( $input ) ) ) {
			$attachment_id = absint( $input );
		} elseif ( is_string( $input ) && '' !== $input ) {
			if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) && defined( 'WP_MCP_AI_PATH' ) ) {
				$helper_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
				if ( file_exists( $helper_file ) ) {
					require_once $helper_file;
				}
			}
			if ( class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
				$helper        = new WP_MCP_AI_Message_Attachments();
				$attachment_id = (int) $helper->get_attachment_id_for_openai_file( $input );
			}
		}

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_input',
				sprintf(
					/* translators: %s: input label */
					__( 'Could not resolve %s input. Provide an attachment ID, public URL, or file_id.', 'mcp-ai-wpoos-pro' ),
					$input_label
				),
				array( 'status' => 400 )
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_attachment',
				sprintf(
					/* translators: %s: input label */
					__( 'The %s attachment file was not found on disk.', 'mcp-ai-wpoos-pro' ),
					$input_label
				),
				array( 'status' => 404 )
			);
		}

		$mime_type = (string) get_post_mime_type( $attachment_id );
		if ( '' !== $mime_type && 0 !== strpos( $mime_type, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				sprintf(
					/* translators: %s: input label */
					__( 'The %s must be an image.', 'mcp-ai-wpoos-pro' ),
					$input_label
				),
				array( 'status' => 400 )
			);
		}

		$copy = $this->harmonization_duplicate_to_temp( $file_path, 'src' );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		return array(
			'file_path'     => $copy,
			'attachment_id' => $attachment_id,
			'is_temp'       => true,
		);
	}

	/**
	 * Validate a string is a syntactically correct public http(s) URL.
	 *
	 * No DNS lookup is performed here; downstream `wp_safe_remote_get()` blocks
	 * private/loopback hosts.
	 *
	 * @param string $url URL candidate.
	 *
	 * @return bool
	 */
	protected function harmonization_is_valid_http_url( $url ) {
		return is_string( $url )
			&& false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& (bool) preg_match( '#^https?://#i', $url );
	}

	/**
	 * Download a remote image to the harmonization temp directory.
	 *
	 * @param string $url Public http(s) URL.
	 *
	 * @return array|WP_Error array with `file_path` key or WP_Error.
	 */
	protected function harmonization_download_url_to_temp( $url ) {
		if ( ! $this->harmonization_is_valid_http_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'Invalid image URL provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_url_download_failed',
				$response->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error(
				'wp_mcp_ai_url_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Image URL returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status
				),
				array( 'status' => 400 )
			);
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$mime_type    = trim( strtok( $content_type, ';' ) );
		if ( '' === $mime_type || 0 !== strpos( $mime_type, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				__( 'URL did not return a supported image type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$ext_map   = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		$extension = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : 'png';
		$body      = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'Image URL returned an empty response.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$dir       = $this->harmonization_temp_dir();
		$file_path = trailingslashit( $dir ) . 'src-url-' . wp_generate_password( 12, false ) . '.' . $extension;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file_path, $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save downloaded image to temporary file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array( 'file_path' => $file_path );
	}

	/**
	 * Duplicate a file into the harmonization temp directory.
	 *
	 * @param string $source_path Source absolute path.
	 * @param string $tag         Short tag included in filename for debugging.
	 *
	 * @return string|WP_Error temp path on success.
	 */
	protected function harmonization_duplicate_to_temp( $source_path, $tag = 'tmp' ) {
		if ( ! file_exists( $source_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Source file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$ext       = pathinfo( $source_path, PATHINFO_EXTENSION );
		$ext       = '' !== $ext ? $ext : 'png';
		$dir       = $this->harmonization_temp_dir();
		$safe_tag  = preg_replace( '/[^a-z0-9\-]/i', '', (string) $tag );
		$safe_tag  = '' !== $safe_tag ? $safe_tag : 'tmp';
		$file_name = $safe_tag . '-' . wp_generate_password( 12, false ) . '.' . $ext;
		$target    = trailingslashit( $dir ) . $file_name;

		if ( ! copy( $source_path, $target ) ) {
			return new WP_Error(
				'wp_mcp_ai_copy_failed',
				__( 'Failed to duplicate working file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $target;
	}

	/**
	 * Resolve the harmonization temp directory, creating it on demand.
	 *
	 * @return string Absolute directory path with no trailing slash.
	 */
	protected function harmonization_temp_dir() {
		$upload_dir = wp_upload_dir();
		$base       = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : sys_get_temp_dir();
		$dir        = trailingslashit( $base ) . 'wp-mcp-ai-temp/harmonization';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Detect the preferred AI provider — same policy as `product_actualization`.
	 *
	 * @param string $requested 'auto', 'gemini', or 'openai'.
	 *
	 * @return string Resolved provider slug ('gemini' or 'openai'), or '' when none configured.
	 */
	protected function harmonization_detect_provider( $requested = 'auto' ) {
		if ( 'gemini' === $requested ) {
			return $this->harmonization_is_gemini_available() ? 'gemini' : '';
		}

		if ( 'openai' === $requested ) {
			return $this->harmonization_is_openai_available() ? 'openai' : '';
		}

		// Auto: prefer Gemini.
		if ( $this->harmonization_is_gemini_available() ) {
			return 'gemini';
		}
		if ( $this->harmonization_is_openai_available() ) {
			return 'openai';
		}

		return '';
	}

	/**
	 * Check if Gemini is configured.
	 *
	 * @return bool
	 */
	protected function harmonization_is_gemini_available() {
		if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return false;
		}
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return ! empty( $settings['gemini_api_key'] );
	}

	/**
	 * Check if OpenAI is configured.
	 *
	 * @return bool
	 */
	protected function harmonization_is_openai_available() {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return false;
		}
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return ! empty( $settings['openai_api_key'] );
	}

	/**
	 * Save raw image bytes to a temp file.
	 *
	 * @param string $bytes     Raw binary image data.
	 * @param string $extension File extension (default 'png').
	 *
	 * @return string|WP_Error Path to file or error.
	 */
	protected function harmonization_save_bytes_to_temp( $bytes, $extension = 'png' ) {
		if ( '' === $bytes ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'Empty image data.', 'mcp-ai-wpoos-pro' )
			);
		}
		$dir       = $this->harmonization_temp_dir();
		$file_name = 'out-' . wp_generate_password( 12, false ) . '.' . $extension;
		$path      = trailingslashit( $dir ) . $file_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $bytes ) ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to write image to temporary file.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $path;
	}

	/**
	 * Import a temp file into the WordPress Media Library and clean up the temp.
	 *
	 * @param string $file_path Absolute path to the image to import.
	 * @param string $title     Attachment post title.
	 * @param int    $user_id   Attachment author user id (0 for unauthenticated/token).
	 *
	 * @return array|WP_Error {
	 *     @type int    $attachment_id
	 *     @type string $url
	 *     @type string $mime_type
	 * }
	 */
	protected function harmonization_import_to_media( $file_path, $title, $user_id = 0 ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Output file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return new WP_Error(
				'wp_mcp_ai_read_failed',
				__( 'Failed to read output file.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$ext       = pathinfo( $file_path, PATHINFO_EXTENSION );
		$ext       = '' !== $ext ? strtolower( $ext ) : 'png';
		$file_name = 'harmonization-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;

		$upload = wp_upload_bits( $file_name, null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', $upload['error'] );
		}
		$uploaded_path = isset( $upload['file'] ) ? $upload['file'] : '';
		if ( '' === $uploaded_path || ! file_exists( $uploaded_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save output file to uploads directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		$mime = ( 'jpg' === $ext || 'jpeg' === $ext ) ? 'image/jpeg' : 'image/' . $ext;

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $uploaded_path );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $uploaded_path );
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'mime_type'     => $mime,
		);
	}

	/**
	 * Cleanup a temp file when caller owns lifecycle.
	 *
	 * @param string $path Path to delete (silent no-op if missing).
	 */
	protected function harmonization_cleanup( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Standard capability flags for harmonization tools (excluding the orchestrator).
	 *
	 * @return array
	 */
	protected function harmonization_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'state-changing',
			'external-api',
			'requires-credentials',
			'network-dependent',
			'consumes-tokens',
			'rate-limited',
			'gpu-accelerated',
			'performance-impact',
		);
	}

	/**
	 * Build a standardized success response shape used across harmonization tools.
	 *
	 * @param array  $media   Array with `attachment_id`, `url`, `mime_type` (from import).
	 * @param string $stage   Stage label (e.g. 'harmonize_color', 'generate_shadow').
	 * @param array  $report  Optional structured report data.
	 *
	 * @return array
	 */
	protected function harmonization_format_response( array $media, $stage, array $report = array() ) {
		$attachment_id = isset( $media['attachment_id'] ) ? (int) $media['attachment_id'] : 0;
		$url           = isset( $media['url'] ) ? (string) $media['url'] : '';

		$response = array(
			'success'       => true,
			'stage'         => sanitize_key( $stage ),
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'mime_type'     => isset( $media['mime_type'] ) ? (string) $media['mime_type'] : '',
			'text'          => sprintf(
				/* translators: 1: stage label, 2: attachment ID */
				__( 'Harmonization stage "%1$s" complete (attachment ID: %2$d).', 'mcp-ai-wpoos-pro' ),
				$stage,
				$attachment_id
			),
		);

		if ( ! empty( $report ) ) {
			$response['report'] = $report;
		}

		if ( $url ) {
			$response['rendered_html'] = sprintf(
				'<img src="%s" alt="%s" style="max-width:100%%;height:auto;" />',
				esc_url( $url ),
				esc_attr( $stage )
			);
		}

		return $response;
	}
}
