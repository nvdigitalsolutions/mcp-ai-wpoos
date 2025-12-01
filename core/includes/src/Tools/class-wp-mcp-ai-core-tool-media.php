<?php
/**
 * Media Tool - Operations for WordPress media attachments.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WordPress media operations.
 *
 * Provides access to WordPress media library including:
 * - Listing media attachments
 * - Getting single media item
 * - Uploading new media (from URL)
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Core_Tool_Media implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'media';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Media', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query and manage WordPress media library attachments. Supports listing, searching, and uploading media files.', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, upload, search.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'get', 'list', 'upload', 'search' ),
					'default'     => 'list',
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID for get action.', 'wp-mcp-ai-core' ),
				),
				'mime_type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by MIME type (e.g., image, video, application/pdf).', 'wp-mcp-ai-core' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to return. Default: 10. Max: 100.', 'wp-mcp-ai-core' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-core' ),
					'default'     => 1,
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter media.', 'wp-mcp-ai-core' ),
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'URL of media file to upload (for upload action).', 'wp-mcp-ai-core' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Title for the uploaded media.', 'wp-mcp-ai-core' ),
				),
				'alt_text'      => array(
					'type'        => 'string',
					'description' => __( 'Alt text for the uploaded media.', 'wp-mcp-ai-core' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',      // list/get/search operations.
			'write',          // upload operation.
			'local-only',     // No external API calls (except upload from URL).
			'external-api',   // upload action fetches from external URL.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'get':
				return $this->get_media( $arguments );
			case 'list':
				return $this->list_media( $arguments );
			case 'upload':
				return $this->upload_media( $arguments, $context );
			case 'search':
				return $this->search_media( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-core' )
				);
		}
	}

	/**
	 * Get a single media attachment by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_media( $arguments ) {
		if ( empty( $arguments['attachment_id'] ) ) {
			return new WP_Error(
				'missing_attachment_id',
				__( 'Attachment ID is required for get action.', 'wp-mcp-ai-core' )
			);
		}

		$attachment = get_post( absint( $arguments['attachment_id'] ) );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'attachment_not_found',
				__( 'Attachment not found.', 'wp-mcp-ai-core' )
			);
		}

		return $this->format_attachment( $attachment );
	}

	/**
	 * List media attachments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_media( $arguments ) {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10,
			'paged'          => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $arguments['mime_type'] ) ) {
			$query_args['post_mime_type'] = sanitize_text_field( $arguments['mime_type'] );
		}

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$query = new WP_Query( $query_args );

		$attachments = array();
		foreach ( $query->posts as $attachment ) {
			$attachments[] = $this->format_attachment( $attachment );
		}

		return array(
			'media'       => $attachments,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
		);
	}

	/**
	 * Search media attachments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_media( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'wp-mcp-ai-core' )
			);
		}

		return $this->list_media( $arguments );
	}

	/**
	 * Upload media from a URL.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function upload_media( $arguments, $context ) {
		if ( empty( $arguments['url'] ) ) {
			return new WP_Error(
				'missing_url',
				__( 'URL is required for upload action.', 'wp-mcp-ai-core' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to upload files.', 'wp-mcp-ai-core' )
			);
		}

		$url = esc_url_raw( $arguments['url'] );

		// Validate URL.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'wp-mcp-ai-core' )
			);
		}

		// Require media handling functions.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Download file to temp location.
		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Get filename from URL.
		$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( empty( $filename ) ) {
			$filename = 'uploaded-media';
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		// Upload the file.
		$attachment_id = media_handle_sideload( $file_array, 0 );

		// Clean up temp file.
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Update title if provided.
		if ( ! empty( $arguments['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => sanitize_text_field( $arguments['title'] ),
				)
			);
		}

		// Update alt text if provided.
		if ( ! empty( $arguments['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $arguments['alt_text'] ) );
		}

		return $this->format_attachment( get_post( $attachment_id ) );
	}

	/**
	 * Format an attachment for output.
	 *
	 * @param WP_Post $attachment Attachment object.
	 * @return array
	 */
	protected function format_attachment( $attachment ) {
		$metadata = wp_get_attachment_metadata( $attachment->ID );

		$file_path = get_attached_file( $attachment->ID );
		$file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : null;

		return array(
			'id'          => $attachment->ID,
			'title'       => get_the_title( $attachment ),
			'url'         => wp_get_attachment_url( $attachment->ID ),
			'mime_type'   => $attachment->post_mime_type,
			'alt_text'    => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'date'        => $attachment->post_date,
			'width'       => isset( $metadata['width'] ) ? $metadata['width'] : null,
			'height'      => isset( $metadata['height'] ) ? $metadata['height'] : null,
			'file_size'   => $file_size,
			'sizes'       => isset( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : array(),
		);
	}
}
