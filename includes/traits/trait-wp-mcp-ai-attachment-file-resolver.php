<?php
/**
 * Trait for resolving file IDs and URLs to attachment IDs.
 *
 * Provides helper methods for tools to accept WordPress attachment IDs,
 * OpenAI/Gemini file IDs, or URLs, converting them to attachment IDs as needed.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for resolving file IDs and URLs to attachment IDs.
 *
 * This trait provides methods to help tools accept three input formats:
 * - attachment_id: WordPress attachment ID (integer)
 * - file_id: OpenAI/Gemini file identifier (string like 'file-abc123')
 * - url: Direct URL to the file (string)
 *
 * When a file_id is provided, it will be resolved to a WordPress attachment ID.
 * When a url is provided, it will attempt to resolve to a local attachment ID,
 * or can be used directly for remote file access.
 *
 * @since 1.0.0
 */
trait WP_MCP_AI_Attachment_File_Resolver {

	/**
	 * Resolve attachment ID from arguments.
	 *
	 * Accepts attachment_id, file_id, or url from the arguments array and
	 * returns a WordPress attachment ID when possible. Priority order:
	 * 1. attachment_id (direct WordPress attachment ID)
	 * 2. file_id (OpenAI/Gemini file identifier, resolved to attachment)
	 * 3. url (WordPress media URL, resolved to attachment if local)
	 *
	 * @param array  $arguments Tool arguments that may contain attachment_id, file_id, or url.
	 * @param string $param_name Optional parameter name to check. Default 'attachment_id'.
	 * @return int|WP_Error|array Attachment ID on success, WP_Error on failure, or array with 'url' key if URL cannot be resolved to attachment.
	 */
	protected function resolve_attachment_id( array $arguments, $param_name = 'attachment_id' ) {
		// First check for direct attachment_id parameter.
		if ( ! empty( $arguments[ $param_name ] ) ) {
			$attachment_id = absint( $arguments[ $param_name ] );
			if ( $attachment_id > 0 ) {
				// Verify it's actually an attachment.
				if ( 'attachment' === get_post_type( $attachment_id ) ) {
					return $attachment_id;
				}
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					sprintf(
						/* translators: %d: attachment ID */
						__( 'Attachment ID %d does not exist or is not an attachment.', 'mcp-ai-wpoos' ),
						$attachment_id
					),
					array( 'status' => 404 )
				);
			}
		}

		// Check for file_id parameter.
		$file_id_param = str_replace( 'attachment_id', 'file_id', $param_name );
		if ( ! empty( $arguments[ $file_id_param ] ) ) {
			$file_id = sanitize_text_field( $arguments[ $file_id_param ] );
			if ( '' !== $file_id ) {
				return $this->resolve_attachment_id_from_file_id( $file_id );
			}
		}

		// Check for url parameter.
		$url_param = str_replace( 'attachment_id', 'url', $param_name );
		if ( ! empty( $arguments[ $url_param ] ) ) {
			$url = esc_url_raw( $arguments[ $url_param ] );
			if ( '' !== $url ) {
				return $this->resolve_attachment_id_from_url( $url );
			}
		}

		return 0;
	}

	/**
	 * Resolve a WordPress attachment ID from an OpenAI/Gemini file ID.
	 *
	 * Uses the WP_MCP_AI_Message_Attachments helper to look up the attachment
	 * associated with the given file ID.
	 *
	 * @param string $file_id OpenAI/Gemini file identifier (e.g., 'file-abc123').
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function resolve_attachment_id_from_file_id( $file_id ) {
		$file_id = sanitize_text_field( $file_id );

		if ( '' === $file_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_id',
				__( 'File ID cannot be empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Load the message attachments helper.
		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
		$attachment_id      = $attachments_helper->get_attachment_id_for_openai_file( $file_id );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				sprintf(
					/* translators: %s: file ID */
					__( 'No attachment found for file ID: %s', 'mcp-ai-wpoos' ),
					$file_id
				),
				array( 'status' => 404 )
			);
		}

		// Verify the attachment still exists and is valid.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_invalid',
				sprintf(
					/* translators: %s: file ID */
					__( 'File ID %s resolved to an invalid attachment.', 'mcp-ai-wpoos' ),
					$file_id
				),
				array( 'status' => 404 )
			);
		}

		return $attachment_id;
	}

	/**
	 * Resolve a WordPress attachment ID from a URL.
	 *
	 * Attempts to find a WordPress attachment that matches the given URL.
	 * Returns an array with 'url' key if URL cannot be resolved to a local attachment.
	 *
	 * @param string $url File URL.
	 * @return int|array Attachment ID on success, array with 'url' key for remote URLs.
	 */
	protected function resolve_attachment_id_from_url( $url ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'URL cannot be empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Try to find an attachment with this URL.
		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}

		// Try scheme-agnostic matching (http vs https).
		$parsed_url = wp_parse_url( $url );
		if ( isset( $parsed_url['scheme'] ) ) {
			$alternate_scheme = ( 'https' === $parsed_url['scheme'] ) ? 'http' : 'https';
			$alternate_url    = str_replace( $parsed_url['scheme'] . '://', $alternate_scheme . '://', $url );
			$attachment_id    = attachment_url_to_postid( $alternate_url );

			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		// Cannot resolve to local attachment, return URL for remote access.
		return array( 'url' => $url );
	}

	/**
	 * Get file_id schema definition for tool parameters.
	 *
	 * Returns a standard schema definition for the file_id parameter that can
	 * be added to a tool's parameter schema.
	 *
	 * @param string $description Optional custom description. Default describes OpenAI/Gemini file IDs.
	 * @return array Parameter schema definition.
	 */
	protected function get_file_id_parameter_schema( $description = '' ) {
		if ( empty( $description ) ) {
			$description = __( 'OpenAI or Gemini file identifier. Alternative to attachment_id for files already uploaded to the AI provider.', 'mcp-ai-wpoos' );
		}

		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/**
	 * Get url schema definition for tool parameters.
	 *
	 * Returns a standard schema definition for the url parameter that can
	 * be added to a tool's parameter schema.
	 *
	 * @param string $media_type Optional media type (e.g., 'image', 'video', 'audio', 'file'). Default 'file'.
	 * @param string $description Optional custom description. Default describes file URLs.
	 * @return array Parameter schema definition.
	 */
	protected function get_url_parameter_schema( $media_type = 'file', $description = '' ) {
		if ( empty( $description ) ) {
			$description = sprintf(
				/* translators: %s: media type (image, video, audio, file) */
				__( 'URL to the %s. Can be a WordPress media URL or external URL.', 'mcp-ai-wpoos' ),
				$media_type
			);
		}

		return array(
			'type'        => 'string',
			'format'      => 'uri',
			'description' => $description,
		);
	}

	/**
	 * Resolve a provider file ID to a local file path.
	 *
	 * Attempts to find the file locally via a linked WordPress attachment first.
	 * When no attachment is found, downloads the file content from the AI provider
	 * (currently supports OpenAI file IDs starting with "file-") and writes it to
	 * a temporary file.
	 *
	 * Callers are responsible for deleting the temporary file when `is_temp` is true.
	 *
	 * @param string $file_id Provider file identifier (e.g., 'file-Nfe1VozHi3BxjiLwWzRKRC').
	 * @return array|WP_Error {
	 *     Resolved path information on success, WP_Error on failure.
	 *
	 *     @type string $path    Absolute path to the local file.
	 *     @type bool   $is_temp True when the file is a temporary download that should be deleted after use.
	 * }
	 */
	protected function resolve_file_id_to_temp_path( $file_id ) {
		$file_id = sanitize_text_field( $file_id );

		if ( '' === $file_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_id',
				__( 'File ID cannot be empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Step 1: Try to find a linked WordPress attachment.
		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
		$attachment_id      = $attachments_helper->get_attachment_id_for_openai_file( $file_id );

		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				return array(
					'path'    => $file_path,
					'is_temp' => false,
				);
			}
		}

		// Step 2: Download from the AI provider's Files API.
		// OpenAI file IDs start with "file-".
		if ( 0 === strpos( $file_id, 'file-' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
			}

			$client   = new WP_MCP_AI_OpenAI_Client();
			$download = $client->download_file( $file_id );

			if ( is_wp_error( $download ) ) {
				return new WP_Error(
					'wp_mcp_ai_provider_file_download_failed',
					sprintf(
						/* translators: 1: file ID, 2: error message */
						__( 'Could not retrieve file "%1$s" from OpenAI: %2$s', 'mcp-ai-wpoos' ),
						$file_id,
						$download->get_error_message()
					),
					array( 'status' => 502 )
				);
			}

			// Write the file body to a temporary path.
			$body = $download['body'];

			// Guard against excessively large downloads that could exhaust disk space.
			$max_bytes = apply_filters( 'wp_mcp_ai_provider_file_max_bytes', wp_max_upload_size() );
			if ( strlen( $body ) > $max_bytes ) {
				return new WP_Error(
					'wp_mcp_ai_provider_file_too_large',
					sprintf(
						/* translators: 1: file ID, 2: human-readable size limit */
						__( 'Provider file "%1$s" exceeds the maximum allowed download size (%2$s).', 'mcp-ai-wpoos' ),
						$file_id,
						size_format( $max_bytes )
					),
					array( 'status' => 413 )
				);
			}

			$ext      = $this->guess_extension_from_content_type( $download['content_type'] ?? '' );
			$tmp_base = tempnam( sys_get_temp_dir(), 'wp_mcp_ai_pf_' );

			if ( false === $tmp_base ) {
				return new WP_Error(
					'wp_mcp_ai_temp_file_error',
					__( 'Could not create a temporary file for the provider download.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$tmp_path = '' !== $ext ? $tmp_base . $ext : $tmp_base;

			if ( '' !== $ext && false === rename( $tmp_base, $tmp_path ) ) {
				// If rename fails, keep the original name.
				$tmp_path = $tmp_base;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp_path, $body ) ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error(
					'wp_mcp_ai_temp_file_write_error',
					__( 'Could not write provider file content to a temporary file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'path'    => $tmp_path,
				'is_temp' => true,
			);
		}

		// Unknown provider or unsupported file ID format.
		return new WP_Error(
			'wp_mcp_ai_unsupported_file_id',
			sprintf(
				/* translators: %s: file ID */
				__( 'No local attachment found for file ID "%s" and automatic download is not supported for this provider format.', 'mcp-ai-wpoos' ),
				$file_id
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Guess a file extension from a MIME / Content-Type string.
	 *
	 * @param string $content_type HTTP Content-Type header value.
	 * @return string File extension including the leading dot, or empty string when unknown.
	 */
	protected function guess_extension_from_content_type( $content_type ) {
		$map = array(
			'application/pdf'                                                          => '.pdf',
			'text/plain'                                                               => '.txt',
			'text/html'                                                                => '.html',
			'text/csv'                                                                 => '.csv',
			'application/json'                                                         => '.json',
			'application/msword'                                                       => '.doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
			'application/vnd.ms-excel'                                                 => '.xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => '.xlsx',
			'image/jpeg'                                                               => '.jpg',
			'image/png'                                                                => '.png',
			'image/gif'                                                                => '.gif',
			'image/webp'                                                               => '.webp',
		);

		foreach ( $map as $mime => $ext ) {
			if ( false !== strpos( $content_type, $mime ) ) {
				return $ext;
			}
		}

		return '';
	}
}
