<?php
/**
 * Tool for reading WordPress image metadata.
 *
 * The first rung of the non-LLM image identification ladder: alt text,
 * title, caption, description, EXIF/IPTC, dimensions, and filename —
 * everything WordPress already knows about an image, for free.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool that aggregates all WordPress-side metadata
 * for an image attachment without any external calls.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Get_Image_Metadata implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_image_metadata';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Image Metadata', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads all metadata WordPress holds about an image: alt text, title, caption, description, EXIF/IPTC fields, dimensions, MIME type, filename, and URL. Free and local — always check this before analyzing image pixels.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Answering "what is this image?" when the image is a WordPress attachment — metadata often answers the question outright (alt text, caption, EXIF title/credit).', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Images with empty metadata, or questions about visual content itself; follow with identify_image or detect_image_content.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'find_similar_media', 'identify_image', 'search_attachments', 'get_media' ),
			'notes'           => __( 'Pure WordPress — no external API calls. URL-only inputs return the fields derivable without a local attachment.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the image.', 'mcp-ai-wpoos' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image. Local media URLs resolve to the full attachment metadata; external URLs return URL-derivable fields only.', 'mcp-ai-wpoos' ),
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
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_get_image_metadata_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_get_image_metadata_forbidden',
				__( 'You do not have permission to use Get Image Metadata.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$attachment_id = 0;

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments, 'attachment_id' );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			if ( is_int( $resolved ) && $resolved > 0 ) {
				$attachment_id = $resolved;
			}
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$url           = esc_url_raw( $arguments['image_url'] );
			$attachment_id = attachment_url_to_postid( $url );
		}

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			$metadata = $this->collect_attachment_metadata( $attachment_id );
		} else {
			$url = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';

			if ( empty( $url ) ) {
				return new WP_Error(
					'wp_mcp_ai_get_image_metadata_missing_input',
					__( 'One of attachment_id or image_url must be provided.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$metadata = $this->collect_url_metadata( $url );
		}

		return $this->format_success_response(
			__( 'Image metadata retrieved from WordPress.', 'mcp-ai-wpoos' ),
			$metadata
		);
	}

	/**
	 * Collect the full metadata set for a local attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Metadata.
	 */
	private function collect_attachment_metadata( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		$file_path  = get_attached_file( $attachment_id );

		$size_info = array(
			'width'    => 0,
			'height'   => 0,
			'mime'     => '',
			'channels' => 0,
			'bits'     => 0,
		);

		if ( $file_path && file_exists( $file_path ) && function_exists( 'wp_getimagesize' ) ) {
			$raw = wp_getimagesize( $file_path );

			if ( is_array( $raw ) ) {
				$size_info = array(
					'width'    => isset( $raw[0] ) ? absint( $raw[0] ) : 0,
					'height'   => isset( $raw[1] ) ? absint( $raw[1] ) : 0,
					'mime'     => isset( $raw['mime'] ) ? sanitize_text_field( $raw['mime'] ) : '',
					'channels' => isset( $raw['channels'] ) ? absint( $raw['channels'] ) : 0,
					'bits'     => isset( $raw['bits'] ) ? absint( $raw['bits'] ) : 0,
				);
			}
		}

		$exif = array();
		if ( $file_path && file_exists( $file_path ) && function_exists( 'wp_read_image_metadata' ) ) {
			$raw_exif = wp_read_image_metadata( $file_path );

			if ( is_array( $raw_exif ) ) {
				foreach ( $raw_exif as $key => $value ) {
					$exif[ $key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		$attachment_meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'attachment_id' => $attachment_id,
			'alt_text'      => sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'title'         => sanitize_text_field( $attachment ? $attachment->post_title : '' ),
			'caption'       => sanitize_text_field( $attachment ? $attachment->post_excerpt : '' ),
			'description'   => sanitize_text_field( $attachment ? $attachment->post_content : '' ),
			'filename'      => sanitize_text_field( $attachment_meta && isset( $attachment_meta['file'] ) ? basename( $attachment_meta['file'] ) : '' ),
			'url'           => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
			'mime_type'     => sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ),
			'dimensions'    => $size_info,
			'filesize'      => $file_path && file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
			'exif'          => $exif,
			'created_at'    => $attachment ? sanitize_text_field( $attachment->post_date_gmt ) : '',
			'modified_at'   => $attachment ? sanitize_text_field( $attachment->post_modified_gmt ) : '',
		);
	}

	/**
	 * Collect URL-derivable metadata for external images.
	 *
	 * @param string $url Image URL.
	 * @return array Metadata.
	 */
	private function collect_url_metadata( $url ) {
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $path ? basename( $path ) : '';

		return array(
			'attachment_id' => 0,
			'alt_text'      => '',
			'title'         => '',
			'caption'       => '',
			'description'   => '',
			'filename'      => sanitize_text_field( $filename ),
			'url'           => $url,
			'mime_type'     => sanitize_text_field( (string) wp_check_filetype( $filename )['type'] ),
			'dimensions'    => array(
				'width'    => 0,
				'height'   => 0,
				'mime'     => '',
				'channels' => 0,
				'bits'     => 0,
			),
			'filesize'      => 0,
			'exif'          => array(),
			'created_at'    => '',
			'modified_at'   => '',
			'note'          => __( 'External URL — only URL-derivable fields are available. Upload the image to the media library for full metadata.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'local-only',           // No external API calls.
			'cacheable',            // Deterministic for a given attachment.
		);
	}
}
