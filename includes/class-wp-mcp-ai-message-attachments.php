<?php
/**
 * Helper for preparing structured chat message segments and attachments.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares structured message segments and collects attachment payloads.
 */
class WP_MCP_AI_Message_Attachments {
	const MAX_ATTACHMENT_BYTES = 5242880; // 5MB default limit per attachment.

	/**
	 * Cached attachment payloads keyed by generated file identifier.
	 *
	 * @var array
	 */
	protected $attachments = array();

	/**
	 * Map of attachment post IDs to generated file identifiers.
	 *
	 * @var array
	 */
	protected $attachment_index = array();

	/**
	 * Retrieve prepared attachment payloads.
	 *
	 * @return array
	 */
	public function get_attachments() {
		return array_values( $this->attachments );
	}

	/**
	 * Prepare a text segment.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	public function prepare_input_text_segment( $text ) {
		$text = wp_kses_post( (string) $text );
		$text = trim( $text );

		return array(
			'type' => 'text',
			'text' => $text,
		);
	}

	/**
	 * Prepare an input image segment using an attachment or remote URL.
	 *
	 * @param array $segment Segment definition.
	 * @return array|WP_Error
	 */
	public function prepare_input_image_segment( array $segment ) {
		$caption = isset( $segment['caption'] ) ? $this->sanitize_caption( $segment['caption'] ) : '';
		$detail  = isset( $segment['detail'] ) ? $this->sanitize_detail( $segment['detail'] ) : '';

		if ( ! empty( $segment['url'] ) ) {
			$url = esc_url_raw( $segment['url'] );
			if ( empty( $url ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_image_url', __( 'Image segment URL is invalid.', 'wp-mcp-ai' ) );
			}

			$prepared = array(
				'type'      => 'input_image',
				'image_url' => array( 'url' => $url ),
			);

			if ( ! empty( $caption ) ) {
				$prepared['caption'] = $caption;
			}

			if ( ! empty( $detail ) ) {
				$prepared['detail'] = $detail;
			}

			return $prepared;
		}

		if ( empty( $segment['attachment_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_image_attachment', __( 'Image segments must include an attachment ID or URL.', 'wp-mcp-ai' ) );
		}

		$prepared_attachment = $this->register_attachment( absint( $segment['attachment_id'] ), 'image' );

		if ( is_wp_error( $prepared_attachment ) ) {
			return $prepared_attachment;
		}

		$prepared = array(
			'type'       => 'input_image',
			'image_file' => array( 'file_id' => $prepared_attachment['file_id'] ),
		);

		$resolved_caption = $caption;

		if ( empty( $resolved_caption ) ) {
			$resolved_caption = $prepared_attachment['caption'];
		}

		if ( empty( $resolved_caption ) ) {
			$resolved_caption = $prepared_attachment['title'];
		}

		if ( ! empty( $resolved_caption ) ) {
			$prepared['caption'] = $resolved_caption;
		}

		if ( ! empty( $detail ) ) {
			$prepared['detail'] = $detail;
		}

		return $prepared;
	}

	/**
	 * Prepare an input file segment from a permitted attachment.
	 *
	 * @param array $segment Segment definition.
	 * @return array|WP_Error
	 */
	public function prepare_input_file_segment( array $segment ) {
		if ( empty( $segment['attachment_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_file_attachment', __( 'File segments must include an attachment ID.', 'wp-mcp-ai' ) );
		}

		$prepared_attachment = $this->register_attachment( absint( $segment['attachment_id'] ), 'file' );

		if ( is_wp_error( $prepared_attachment ) ) {
			return $prepared_attachment;
		}

		$segment_payload = array(
			'type'    => 'input_file',
			'file_id' => $prepared_attachment['file_id'],
		);

		if ( ! empty( $segment['display_name'] ) ) {
			$segment_payload['display_name'] = sanitize_text_field( $segment['display_name'] );
		} elseif ( ! empty( $prepared_attachment['title'] ) ) {
			$segment_payload['display_name'] = $prepared_attachment['title'];
		}

		return $segment_payload;
	}

	/**
	 * Register an attachment for inclusion in the OpenAI payload.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $usage         Usage context (image|file).
	 * @return array|WP_Error
	 */
	protected function register_attachment( $attachment_id, $usage ) {
		if ( isset( $this->attachment_index[ $attachment_id ] ) ) {
			$file_id = $this->attachment_index[ $attachment_id ];
			$entry   = $this->attachments[ $file_id ];

			return array(
				'file_id' => $file_id,
				'title'   => isset( $entry['title'] ) ? $entry['title'] : '',
				'caption' => isset( $entry['caption'] ) ? $entry['caption'] : '',
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'wp_mcp_ai_attachment_missing', __( 'Attachment not found.', 'wp-mcp-ai' ) );
		}

		if ( ! $this->current_user_can_access_attachment( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_attachment_forbidden', __( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_attachment_missing_file', __( 'The attachment file could not be located.', 'wp-mcp-ai' ) );
		}

		$file_size = @filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error( 'wp_mcp_ai_attachment_size_unknown', __( 'Could not determine attachment size.', 'wp-mcp-ai' ) );
		}

		$max_bytes = apply_filters( 'wp_mcp_ai_max_attachment_bytes', self::MAX_ATTACHMENT_BYTES, $attachment_id, $usage );
		if ( $file_size > $max_bytes ) {
			/* translators: %s: maximum bytes allowed for an attachment. */
			return new WP_Error( 'wp_mcp_ai_attachment_too_large', sprintf( __( 'Attachments must be smaller than %s bytes.', 'wp-mcp-ai' ), number_format_i18n( $max_bytes ) ) );
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $this->is_supported_mime_type( $mime_type, $usage ) ) {
			return new WP_Error( 'wp_mcp_ai_attachment_unsupported_mime', __( 'The attachment type is not supported for chat messages.', 'wp-mcp-ai' ) );
		}

		$contents = $this->read_file_contents( $file_path );
		if ( '' === $contents ) {
			return new WP_Error( 'wp_mcp_ai_attachment_unreadable', __( 'Unable to read the attachment data.', 'wp-mcp-ai' ) );
		}

		$file_id = 'wp-attachment-' . $attachment_id;

		$payload = array(
			'id'        => $file_id,
			'filename'  => wp_basename( $file_path ),
			'mime_type' => $mime_type,
			'data'      => base64_encode( $contents ),
			'bytes'     => (int) $file_size,
		);

		$title = get_the_title( $attachment );
		if ( '' !== $title ) {
			$payload['title'] = $title;
		}

		$caption = wp_strip_all_tags( $attachment->post_excerpt );
		if ( '' !== $caption ) {
			$payload['caption'] = $caption;
		}

		$this->attachments[ $file_id ]            = $payload;
		$this->attachment_index[ $attachment_id ] = $file_id;

		return array(
			'file_id' => $file_id,
			'title'   => $title,
			'caption' => $caption,
		);
	}

	/**
	 * Determine if the current user can access an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function current_user_can_access_attachment( $attachment_id ) {
		if ( current_user_can( 'read_post', $attachment_id ) ) {
			return true;
		}

		if ( current_user_can( 'edit_post', $attachment_id ) ) {
			return true;
		}

		$post = get_post( $attachment_id );
		if ( $post && (int) $post->post_author === get_current_user_id() ) {
			return true;
		}

		return apply_filters( 'wp_mcp_ai_can_use_attachment', false, $attachment_id );
	}

	/**
	 * Public helper for checking attachment access permissions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function user_can_access_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$helper = new self();

		return $helper->current_user_can_access_attachment( $attachment_id );
	}

	/**
	 * Validate whether a MIME type is permitted for the usage context.
	 *
	 * @param string $mime_type MIME type string.
	 * @param string $usage     Usage context.
	 * @return bool
	 */
	protected function is_supported_mime_type( $mime_type, $usage ) {
		$mime_type = (string) $mime_type;

		$image_mimes = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/heic',
			'image/heif',
			'image/bmp',
			'image/svg+xml',
		);

		$file_mimes = array(
			'text/plain',
			'text/markdown',
			'text/csv',
			'text/tab-separated-values',
			'application/pdf',
			'application/json',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'application/vnd.ms-word.document.macroEnabled.12',
			'application/vnd.ms-word.template.macroEnabled.12',
		);

		$image_mimes = apply_filters( 'wp_mcp_ai_allowed_image_mimes', $image_mimes );
		$file_mimes  = apply_filters( 'wp_mcp_ai_allowed_file_mimes', $file_mimes );

		if ( 'image' === $usage ) {
			return in_array( $mime_type, $image_mimes, true );
		}

		if ( 'file' === $usage ) {
			return in_array( $mime_type, $file_mimes, true );
		}

		return false;
	}

	/**
	 * Read a file from disk.
	 *
	 * @param string $file_path File path.
	 * @return string
	 */
	protected function read_file_contents( $file_path ) {
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$contents = $wp_filesystem->get_contents( $file_path );
			if ( is_string( $contents ) ) {
				return $contents;
			}
		}

		if ( is_readable( $file_path ) ) {
			return (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return '';
	}

	/**
	 * Sanitise caption text for segments.
	 *
	 * @param string $caption Raw caption text.
	 * @return string
	 */
	protected function sanitize_caption( $caption ) {
		return trim( wp_strip_all_tags( (string) $caption ) );
	}

	/**
	 * Sanitise the optional image detail hint.
	 *
	 * @param string $detail Raw detail string.
	 * @return string
	 */
	protected function sanitize_detail( $detail ) {
		$detail = sanitize_key( $detail );
		if ( in_array( $detail, array( 'low', 'high', 'auto' ), true ) ) {
			return $detail;
		}

		return '';
	}
}
