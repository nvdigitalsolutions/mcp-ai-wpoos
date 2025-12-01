<?php
/**
 * Abstract base class for image manipulation tools.
 *
 * Provides common functionality for all image editing tools including:
 * - Loading images from various sources (attachment ID, URL, base64)
 * - Saving edited images as WordPress attachments
 * - WordPress image editor integration
 * - Common sanitization and validation
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for image manipulation tools.
 */
abstract class WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get allowed image MIME types.
	 *
	 * @return array
	 */
	protected function get_allowed_mime_types() {
		return array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
	}

	/**
	 * Load source image from various input formats.
	 *
	 * Supports:
	 * - attachment_id: WordPress attachment ID
	 * - image_url: URL to image file
	 * - image_data: Base64-encoded image data
	 *
	 * @param array $arguments Tool arguments containing image source.
	 * @param int   $user_id   Current user ID for permission checks.
	 * @return WP_Image_Editor|WP_Error Image editor instance or error.
	 */
	protected function load_source_image( array $arguments, $user_id = 0 ) {
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$image_url     = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$image_data    = isset( $arguments['image_data'] ) ? $arguments['image_data'] : '';

		$file_path     = '';
		$is_local_file = false;

		if ( $attachment_id > 0 ) {
			// Load from WordPress attachment.
			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'The specified attachment does not exist.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
			}

			// Check permissions.
			if ( $user_id && ! current_user_can( 'read_post', $attachment_id ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access this attachment.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
			}
		} elseif ( '' !== $image_url ) {
			// Try to resolve URL to attachment ID first.
			// This handles cases where the URL is a WordPress media URL that might have.
			// different scheme (http vs https) or other variations that prevent direct.
			// filesystem access but still refers to a valid local attachment.
			$file_path              = null;
			$resolved_attachment_id = $this->resolve_attachment_id_from_url( $image_url );

			if ( $resolved_attachment_id > 0 ) {
				$resolved_file_path = get_attached_file( $resolved_attachment_id );

				if ( $resolved_file_path && file_exists( $resolved_file_path ) && is_readable( $resolved_file_path ) ) {
					$file_path     = $resolved_file_path;
					$is_local_file = true;
				}
			}

			// Try to use local file path first to avoid HTTP auth issues.
			if ( null === $file_path && $this->is_local_wordpress_url( $image_url ) ) {
				$local_file_path = $this->get_file_path_from_local_url( $image_url );

				if ( $local_file_path && file_exists( $local_file_path ) && is_readable( $local_file_path ) ) {
					$file_path     = $local_file_path;
					$is_local_file = true;
				}
			}

			// If no local file path, download via HTTP.
			if ( null === $file_path ) {
				$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

				if ( is_wp_error( $response ) ) {
					return new WP_Error( 'wp_mcp_ai_download_error', __( 'Failed to download the source image.', 'wp-mcp-ai' ), array( 'error' => $response->get_error_message() ) );
				}

				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code < 200 || $status_code >= 300 ) {
					return new WP_Error( 'wp_mcp_ai_download_error', sprintf( __( 'Failed to download image. HTTP %d', 'wp-mcp-ai' ), $status_code ), array( 'status' => $status_code ) );
				}

				$image_contents = wp_remote_retrieve_body( $response );
				if ( '' === $image_contents ) {
					return new WP_Error( 'wp_mcp_ai_download_error', __( 'Downloaded image is empty.', 'wp-mcp-ai' ) );
				}

				// Create temporary file from downloaded content.
				$file_path = $this->create_temp_file( $image_contents, $image_url );
				if ( is_wp_error( $file_path ) ) {
					return $file_path;
				}
			}
		} elseif ( '' !== $image_data ) {
			// Use base64-encoded data.
			$decoded_data = base64_decode( $image_data, true );

			if ( false === $decoded_data || '' === $decoded_data ) {
				return new WP_Error( 'wp_mcp_ai_invalid_image_data', __( 'The provided image data is not valid base64.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			// Create temporary file.
			$file_path = $this->create_temp_file( $decoded_data );
			if ( is_wp_error( $file_path ) ) {
				return $file_path;
			}
		} else {
			return new WP_Error( 'wp_mcp_ai_missing_source', __( 'Either attachment_id, image_url, or image_data must be provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		// Load image with WordPress image editor.
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			// Clean up temp file if we created one.
			// Don't delete if it's an attachment or a local file from the uploads directory.
			if ( ! $attachment_id && ! $is_local_file ) {
				$this->delete_temp_file( $file_path );
			}
			return $image_editor;
		}

		// Store whether this is a temp file for cleanup later.
		// Don't mark local upload files as temp - only mark truly temporary files.
		if ( ! $attachment_id && ! $is_local_file ) {
			$image_editor->temp_file = $file_path;
		}

		return $image_editor;
	}

	/**
	 * Create a temporary file from image data.
	 *
	 * @param string $data     Image binary data.
	 * @param string $filename Optional filename for extension detection.
	 * @return string|WP_Error Temporary file path or error.
	 */
	protected function create_temp_file( $data, $filename = '' ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_file = wp_tempnam( $filename );

		if ( false === file_put_contents( $temp_file, $data ) ) {
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary image file.', 'wp-mcp-ai' ) );
		}

		return $temp_file;
	}

	/**
	 * Delete a temporary file safely.
	 *
	 * @param string $file_path Path to temporary file.
	 */
	protected function delete_temp_file( $file_path ) {
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	/**
	 * Check if a URL is a local WordPress URL.
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL belongs to this WordPress installation.
	 */
	protected function is_local_wordpress_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return false;
		}

		// Normalize URL to remove scheme differences (http vs https).
		$normalized_url = $this->normalize_url_for_comparison( $url );

		// Get the WordPress upload directory URL.
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';

		if ( '' !== $base_url ) {
			$normalized_base = $this->normalize_url_for_comparison( $base_url );
			if ( 0 === strpos( $normalized_url, $normalized_base ) ) {
				return true;
			}
		}

		// Also check against home_url and site_url as fallback.
		$home_url            = home_url();
		$site_url            = site_url();
		$normalized_home_url = $this->normalize_url_for_comparison( $home_url );
		$normalized_site_url = $this->normalize_url_for_comparison( $site_url );

		if ( 0 === strpos( $normalized_url, $normalized_home_url ) || 0 === strpos( $normalized_url, $normalized_site_url ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize a URL for comparison by removing the scheme.
	 *
	 * This helps match URLs that differ only in http vs https.
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL without scheme.
	 */
	protected function normalize_url_for_comparison( $url ) {
		if ( '' === $url ) {
			return '';
		}

		// Remove http:// or https:// prefix for comparison.
		$url = preg_replace( '#^https?://#i', '', $url );

		return $url;
	}

	/**
	 * Convert a local WordPress URL to a file path.
	 *
	 * @param string $url Local WordPress URL.
	 * @return string|false File path on success, false on failure.
	 */
	protected function get_file_path_from_local_url( $url ) {
		if ( '' === $url ) {
			return false;
		}

		// Get the WordPress upload directory information.
		$upload_dir = wp_upload_dir();
		$base_url   = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		$base_dir   = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';

		if ( '' === $base_url || '' === $base_dir ) {
			return false;
		}

		// Normalize URLs to handle scheme differences (http vs https).
		$normalized_url      = $this->normalize_url_for_comparison( $url );
		$normalized_base_url = $this->normalize_url_for_comparison( $base_url );

		// Check if URL starts with the upload base URL (using normalized comparison).
		if ( 0 === strpos( $normalized_url, $normalized_base_url ) ) {
			// Extract the relative path after the base URL.
			$relative_path = substr( $normalized_url, strlen( $normalized_base_url ) );

			// Build the file path.
			$file_path = $base_dir . $relative_path;

			// Normalize path separators.
			$file_path = wp_normalize_path( $file_path );

			return $file_path;
		}

		// Try using WordPress built-in function as fallback.
		// This handles cases where URL might be in a different format.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			return get_attached_file( $attachment_id );
		}

		return false;
	}

	/**
	 * Resolve an attachment ID from a URL with scheme-agnostic matching.
	 *
	 * WordPress's attachment_url_to_postid() function is scheme-sensitive and will
	 * fail if the URL scheme (http vs https) doesn't match what's stored in the
	 * database. This method provides a fallback that tries both schemes.
	 *
	 * This is particularly important for the agentic workflow where the LLM may
	 * receive URLs with a different scheme than WordPress is configured with.
	 *
	 * @param string $url URL to resolve.
	 * @return int Attachment ID on success, 0 if not found.
	 */
	protected function resolve_attachment_id_from_url( $url ) {
		if ( '' === $url ) {
			return 0;
		}

		// First, try the URL as-is.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}

		// If that failed, try with the opposite scheme.
		// This handles cases where the LLM passes a URL with http but WordPress.
		// is configured with https (or vice versa).
		$alternate_url = '';

		if ( 0 === strpos( $url, 'https://' ) ) {
			// Try http instead.
			$alternate_url = 'http://' . substr( $url, 8 );
		} elseif ( 0 === strpos( $url, 'http://' ) ) {
			// Try https instead.
			$alternate_url = 'https://' . substr( $url, 7 );
		}

		if ( '' !== $alternate_url ) {
			$attachment_id = attachment_url_to_postid( $alternate_url );
			if ( $attachment_id > 0 ) {
				return $attachment_id;
			}
		}

		return 0;
	}


	/**
	 * Save image editor contents as WordPress attachment.
	 *
	 * @param WP_Image_Editor $image_editor Image editor instance.
	 * @param array           $arguments    Tool arguments for naming/metadata.
	 * @param int             $user_id      User ID for attachment author.
	 * @param string          $operation    Operation name for title generation.
	 * @return array|WP_Error Attachment data or error.
	 */
	protected function save_as_attachment( WP_Image_Editor $image_editor, array $arguments, $user_id, $operation ) {
		// Get file name from arguments or generate one.
		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		if ( '' === $file_name ) {
			$source_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
			if ( $source_id ) {
				$source_file = get_attached_file( $source_id );
				if ( $source_file ) {
					$pathinfo  = pathinfo( $source_file );
					$file_name = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
				}
			}
			if ( '' === $file_name ) {
				$file_name = 'image';
			}
		}

		// Generate unique filename.
		$extension = $this->get_extension_from_mime_type( $image_editor->mime_type );
		$file_name = sprintf( '%s-%s-%s.%s', sanitize_title( $file_name ), sanitize_title( $operation ), gmdate( 'Ymd-His' ), $extension );

		// Save to uploads directory.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$saved = $image_editor->save();

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$file_path = isset( $saved['path'] ) ? $saved['path'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_save_error', __( 'Failed to save edited image.', 'wp-mcp-ai' ) );
		}

		// Read file contents to re-upload with proper name.
		$image_data = file_get_contents( $file_path );
		if ( false === $image_data ) {
			wp_delete_file( $file_path );
			return new WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read saved image file.', 'wp-mcp-ai' ) );
		}

		// Upload with proper filename.
		$upload = wp_upload_bits( $file_name, null, $image_data );

		// Delete the temporary saved file.
		wp_delete_file( $file_path );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_error', $upload['error'] );
		}

		$final_file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $final_file_path || ! file_exists( $final_file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_error', __( 'Failed to upload edited image.', 'wp-mcp-ai' ) );
		}

		// Create attachment.
		$title = $this->generate_attachment_title( $operation, $arguments );

		$attachment = array(
			'post_mime_type' => $image_editor->mime_type,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $final_file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $final_file_path );
			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to create attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
		}

		// Generate attachment metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $final_file_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$bytes = file_exists( $final_file_path ) ? filesize( $final_file_path ) : 0;

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $final_file_path,
			'file_name'     => wp_basename( $final_file_path ),
			'url'           => isset( $upload['url'] ) ? $upload['url'] : wp_get_attachment_url( $attachment_id ),
			'mime_type'     => $image_editor->mime_type,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
			'size'          => $image_editor->get_size(),
		);
	}

	/**
	 * Generate attachment title based on operation.
	 *
	 * @param string $operation Operation name.
	 * @param array  $arguments Tool arguments.
	 * @return string
	 */
	protected function generate_attachment_title( $operation, array $arguments ) {
		$source_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

		if ( $source_id ) {
			$source_title = get_the_title( $source_id );
			if ( $source_title ) {
				/* translators: 1: operation name, 2: source title */
				return sprintf( __( '%1$s: %2$s', 'wp-mcp-ai' ), ucfirst( $operation ), $source_title );
			}
		}

		/* translators: %s: operation name */
		return sprintf( __( '%s Image', 'wp-mcp-ai' ), ucfirst( $operation ) );
	}

	/**
	 * Get file extension from MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	protected function get_extension_from_mime_type( $mime_type ) {
		$allowed = $this->get_allowed_mime_types();
		return isset( $allowed[ $mime_type ] ) ? $allowed[ $mime_type ] : 'jpg';
	}

	/**
	 * Build inline content payload for chat display.
	 *
	 * @param array $storage Stored attachment data.
	 * @return array
	 */
	protected function build_inline_content_payload( array $storage ) {
		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return array();
		}

		$file_contents = file_get_contents( $file_path );

		if ( false === $file_contents || '' === $file_contents ) {
			return array();
		}

		$encoded = base64_encode( $file_contents );

		if ( '' === $encoded ) {
			return array();
		}

		$mime_type = isset( $storage['mime_type'] ) ? $storage['mime_type'] : '';

		$content = array(
			'encoding' => 'base64',
			'data'     => $encoded,
		);

		if ( '' !== $mime_type ) {
			$content['mime_type'] = $mime_type;
			$content['data_url']  = sprintf( 'data:%s;base64,%s', $mime_type, $encoded );
		}

		if ( isset( $storage['file_name'] ) && '' !== $storage['file_name'] ) {
			$content['file_name'] = $storage['file_name'];
		}

		if ( isset( $storage['bytes'] ) && $storage['bytes'] ) {
			$content['bytes'] = (int) $storage['bytes'];
		}

		return $content;
	}

	/**
	 * Sanitize tool result for LLM consumption.
	 *
	 * Strips large base64 data to prevent context bloat.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64 content.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			unset( $result['content']['data'] );
			unset( $result['content']['data_url'] );

			if ( empty( $result['content'] ) ) {
				unset( $result['content'] );
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates/modifies media files.
			'local-only',           // Works locally without external APIs.
		);
	}

	/**
	 * Common parameter schema elements for image source.
	 *
	 * @return array
	 */
	protected function get_source_parameters_schema() {
		return array(
			'attachment_id' => array(
				'type'        => 'integer',
				'description' => __( 'WordPress attachment ID of the image to process.', 'wp-mcp-ai' ),
			),
			'image_url'     => array(
				'type'        => 'string',
				'description' => __( 'URL of the image to process (alternative to attachment_id).', 'wp-mcp-ai' ),
			),
			'image_data'    => array(
				'type'        => 'string',
				'description' => __( 'Base64-encoded image data to process (alternative to attachment_id or image_url).', 'wp-mcp-ai' ),
			),
			'file_name'     => array(
				'type'        => 'string',
				'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
			),
		);
	}
}
