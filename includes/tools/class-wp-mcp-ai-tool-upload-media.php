<?php
/**
 * Tool: upload_media — Uploads a file into the WordPress media library.
 *
 * Port of mcp-wordpress wp_upload_media tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploads a file into the WordPress media library from one of three sources:
 * an HTTP(S) URL (sideloaded via download_url), an absolute server-side file
 * path (must resolve inside the uploads directory), or base64-encoded content
 * with a filename.
 */
class WP_MCP_AI_Tool_Upload_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum accepted base64 payload size in bytes (25 MB).
	 *
	 * @var int
	 */
	const MAX_BASE64_BYTES = 26214400;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upload_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upload Media', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads a file into the WordPress media library from an HTTP(S) URL, a server-side file path inside the uploads directory, or base64-encoded content. Supports title, alt text, caption, description, and attachment to a post.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Bringing an image, document, or other file into the media library so it can be attached to posts.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Editing existing library items; use update_media. Generating brand-new images; use the image generation tools instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_media', 'update_media', 'search_attachments', 'media_library_optimizer' ),
			'notes'           => __( 'Exactly one source is required: source_url, file_path, or base64 (with filename). File paths must resolve inside the WordPress uploads directory.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_url'  => array(
					'type'        => 'string',
					'description' => __( 'Public HTTP(S) URL of the file to download into the media library.', 'mcp-ai-wpoos' ),
				),
				'file_path'   => array(
					'type'        => 'string',
					'description' => __( 'Absolute server-side path of the file, which must resolve inside the WordPress uploads directory.', 'mcp-ai-wpoos' ),
				),
				'base64'      => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded file content. Requires the filename parameter (and optionally mime_type).', 'mcp-ai-wpoos' ),
				),
				'filename'    => array(
					'type'        => 'string',
					'description' => __( 'Filename to use for base64 uploads (sanitized with sanitize_file_name).', 'mcp-ai-wpoos' ),
				),
				'mime_type'   => array(
					'type'        => 'string',
					'description' => __( 'MIME type for base64 uploads. Optional; WordPress infers it from the filename.', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Title for the media item.', 'mcp-ai-wpoos' ),
				),
				'alt_text'    => array(
					'type'        => 'string',
					'description' => __( 'Alternative text for the media item (accessibility).', 'mcp-ai-wpoos' ),
				),
				'caption'     => array(
					'type'        => 'string',
					'description' => __( 'Caption for the media item.', 'mcp-ai-wpoos' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Description for the media item.', 'mcp-ai-wpoos' ),
				),
				'post_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post to attach this media item to.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'requires-capability',
			'state-changing',
			'reversible',
			'external-api',
			'network-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'photographer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'attachment_id',
			'consumes' => 'post_id',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to upload media.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to upload media.', 'mcp-ai-wpoos' ) );
		}

		$source_url = isset( $arguments['source_url'] ) ? esc_url_raw( $arguments['source_url'] ) : '';
		$file_path  = isset( $arguments['file_path'] ) ? wp_normalize_path( (string) $arguments['file_path'] ) : '';
		$base64     = isset( $arguments['base64'] ) ? (string) $arguments['base64'] : '';

		$source_count = count( array_filter( array( $source_url, $file_path, $base64 ), 'strlen' ) );

		if ( 0 === $source_count ) {
			return new WP_Error( 'wp_mcp_ai_missing_source', __( 'One upload source is required: source_url, file_path, or base64.', 'mcp-ai-wpoos' ) );
		}

		if ( $source_count > 1 ) {
			return new WP_Error( 'wp_mcp_ai_multiple_sources', __( 'Provide exactly one upload source: source_url, file_path, or base64.', 'mcp-ai-wpoos' ) );
		}

		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( $post_id > 0 ) {
			$parent_post = get_post( $post_id );
			if ( ! $parent_post ) {
				return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The post to attach this media to could not be found.', 'mcp-ai-wpoos' ) );
			}
			if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to attach media to that post.', 'mcp-ai-wpoos' ) );
			}
		}

		if ( '' !== $source_url ) {
			return $this->upload_from_url( $source_url, $post_id, $arguments, $acting_user_id );
		}

		if ( '' !== $file_path ) {
			return $this->upload_from_path( $file_path, $post_id, $arguments, $acting_user_id );
		}

		return $this->upload_from_base64( $base64, $arguments, $acting_user_id );
	}

	/**
	 * Sideloads a file from an HTTP(S) URL.
	 *
	 * @param string $source_url     Validated source URL.
	 * @param int    $post_id        Optional parent post ID.
	 * @param array  $arguments      Original tool arguments.
	 * @param int    $acting_user_id Acting user ID.
	 * @return array|WP_Error
	 */
	private function upload_from_url( $source_url, $post_id, $arguments, $acting_user_id ) {
		if ( ! wp_http_validate_url( $source_url ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'The source URL must be a valid http(s) URL.', 'mcp-ai-wpoos' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $source_url, 30 );

		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error( 'wp_mcp_ai_media_download_failed', $tmp_file->get_error_message() );
		}

		$filename = $this->filename_from_url( $source_url );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';

		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error( 'wp_mcp_ai_media_upload_failed', $attachment_id->get_error_message() );
		}

		$this->apply_attachment_fields( $attachment_id, $arguments, $acting_user_id );

		return $this->build_success_response( $attachment_id );
	}

	/**
	 * Imports a file from an absolute server path inside the uploads directory.
	 *
	 * @param string $file_path      Normalized absolute path.
	 * @param int    $post_id        Optional parent post ID.
	 * @param array  $arguments      Original tool arguments.
	 * @param int    $acting_user_id Acting user ID.
	 * @return array|WP_Error
	 */
	private function upload_from_path( $file_path, $post_id, $arguments, $acting_user_id ) {
		$uploads_dir  = wp_get_upload_dir();
		$uploads_base = isset( $uploads_dir['basedir'] ) ? wp_normalize_path( $uploads_dir['basedir'] ) : '';

		if ( '' === $uploads_base ) {
			return new WP_Error( 'wp_mcp_ai_uploads_unavailable', __( 'The WordPress uploads directory is not available.', 'mcp-ai-wpoos' ) );
		}

		$real_uploads = realpath( $uploads_base );
		$real_file    = realpath( $file_path );

		if ( false === $real_uploads ) {
			return new WP_Error( 'wp_mcp_ai_uploads_unavailable', __( 'The WordPress uploads directory does not resolve to a real path.', 'mcp-ai-wpoos' ) );
		}

		if ( false === $real_file || ! is_file( $real_file ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'The provided file path does not point to a readable file.', 'mcp-ai-wpoos' ) );
		}

		$real_file = wp_normalize_path( $real_file );

		// Containment check: the resolved file must live inside the uploads base directory.
		if ( 0 !== strpos( $real_file, $real_uploads . DIRECTORY_SEPARATOR ) && $real_file !== $real_uploads ) {
			do_action(
				'wp_mcp_ai_security_event',
				'upload_media_path_traversal_blocked',
				array(
					'file' => $real_file,
					'base' => $real_uploads,
				)
			);

			return new WP_Error( 'wp_mcp_ai_path_outside_uploads', __( 'The file path must resolve inside the WordPress uploads directory.', 'mcp-ai-wpoos' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = array(
			'name'     => sanitize_file_name( basename( $real_file ) ),
			'tmp_name' => $real_file,
		);

		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';

		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_media_upload_failed', $attachment_id->get_error_message() );
		}

		$this->apply_attachment_fields( $attachment_id, $arguments, $acting_user_id );

		return $this->build_success_response( $attachment_id );
	}

	/**
	 * Creates an attachment from base64-encoded content.
	 *
	 * @param string $base64         Base64 content.
	 * @param array  $arguments      Original tool arguments.
	 * @param int    $acting_user_id Acting user ID.
	 * @return array|WP_Error
	 */
	private function upload_from_base64( $base64, $arguments, $acting_user_id ) {
		$filename = isset( $arguments['filename'] ) ? sanitize_file_name( $arguments['filename'] ) : '';

		if ( '' === $filename ) {
			return new WP_Error( 'wp_mcp_ai_missing_filename', __( 'A filename is required when uploading base64 content.', 'mcp-ai-wpoos' ) );
		}

		if ( strlen( $base64 ) > self::MAX_BASE64_BYTES ) {
			return new WP_Error( 'wp_mcp_ai_media_too_large', __( 'The base64 payload exceeds the 25 MB limit.', 'mcp-ai-wpoos' ) );
		}

		$decoded = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Payload decode for uploads.

		if ( false === $decoded ) {
			return new WP_Error( 'wp_mcp_ai_invalid_base64', __( 'The provided content is not valid base64.', 'mcp-ai-wpoos' ) );
		}

		$mime_type = isset( $arguments['mime_type'] ) ? sanitize_mime_type( $arguments['mime_type'] ) : '';

		if ( '' === $mime_type ) {
			$mime_type = wp_check_filetype( $filename );
			$mime_type = isset( $mime_type['type'] ) ? $mime_type['type'] : '';
		}

		$upload = wp_upload_bits( $filename, null, $decoded );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_media_upload_failed', $upload['error'] );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_args = array(
			'post_mime_type' => '' !== $mime_type ? $mime_type : 'application/octet-stream',
			'post_title'     => isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '',
			'post_excerpt'   => isset( $arguments['caption'] ) ? sanitize_text_field( $arguments['caption'] ) : '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_args, $upload['file'], 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_media_upload_failed', $attachment_id->get_error_message() );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$this->apply_attachment_fields( $attachment_id, $arguments, $acting_user_id );

		return $this->build_success_response( $attachment_id );
	}

	/**
	 * Applies optional title/alt text/caption to a freshly uploaded attachment.
	 *
	 * @param int   $attachment_id  Attachment ID.
	 * @param array $arguments      Original tool arguments.
	 * @param int   $acting_user_id Acting user ID (unused; reserved for capability auditing).
	 */
	private function apply_attachment_fields( $attachment_id, $arguments, $acting_user_id ) {
		$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $title,
				)
			);
		}

		$caption = isset( $arguments['caption'] ) ? sanitize_text_field( $arguments['caption'] ) : '';

		if ( '' !== $caption ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				)
			);
		}

		if ( isset( $arguments['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $arguments['alt_text'] ) );
		}
	}

	/**
	 * Builds the canonical success response for an uploaded attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	private function build_success_response( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		return array(
			'message'       => sprintf(
				/* translators: %d: attachment ID */
				__( 'Media uploaded successfully (attachment ID: %d).', 'mcp-ai-wpoos' ),
				$attachment_id
			),
			'attachment_id' => $attachment_id,
			'title'         => $attachment ? esc_html( $attachment->post_title ) : '',
			'url'           => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
			'mime_type'     => $attachment ? esc_html( $attachment->post_mime_type ) : '',
		);
	}

	/**
	 * Derives a safe filename from a URL.
	 *
	 * @param string $source_url Source URL.
	 * @return string
	 */
	private function filename_from_url( $source_url ) {
		$path     = wp_parse_url( $source_url, PHP_URL_PATH );
		$basename = $path ? basename( $path ) : '';

		if ( '' === $basename ) {
			$basename = 'download';
		}

		return sanitize_file_name( $basename );
	}
}
