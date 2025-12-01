<?php
/**
 * Tool for searching Media Library attachments accessible to the assistant.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches for accessible attachments and returns metadata plus download links.
 */
class WP_MCP_AI_Tool_Search_Attachments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Maximum number of attachments that can be returned in a single call.
	 */
	const MAX_RESULTS = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_attachments';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Knowledge Attachments', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches Media Library files that the current request is allowed to access and returns download URLs with metadata.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Optional keywords that should match the attachment title.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
				'mime_types' => array(
					'type'        => 'array',
					'description' => __( 'List of MIME types that attachments must match.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'offset'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of matching attachments to skip before collecting results.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of attachments to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => self::MAX_RESULTS,
					'default'     => 20,
				),
			),
			'additionalProperties' => false,
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
		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'Attachment search requires the message attachment helper to be available.', 'wp-mcp-ai' )
			);
		}

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit  = $limit > 0 ? min( $limit, self::MAX_RESULTS ) : 20;
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		$search = '';
		if ( isset( $arguments['search'] ) && is_string( $arguments['search'] ) ) {
			$search = sanitize_text_field( $arguments['search'] );
		}

		$mime_types = array();
		if ( ! empty( $arguments['mime_types'] ) && is_array( $arguments['mime_types'] ) ) {
			foreach ( $arguments['mime_types'] as $mime ) {
				if ( ! is_string( $mime ) ) {
					continue;
				}

				$mime = strtolower( trim( $mime ) );
				if ( '' === $mime ) {
					continue;
				}

				$mime_types[] = $mime;
			}

			$mime_types = array_values( array_unique( $mime_types ) );
		}

		$results       = array();
		$skipped       = 0;
		$query_offset  = 0;
		$batch_size    = min( max( $limit * 3, $limit ), 100 );
		$results_count = 0;

		do {
			$query_args = array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => $batch_size,
				'offset'                 => $query_offset,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,  // Performance: Skip counting, we paginate manually.
				'suppress_filters'       => false,
				'update_post_term_cache' => false, // Performance: Attachments don't use taxonomies.
				'update_post_meta_cache' => true,  // Keep meta cache for attachment metadata.
			);

			if ( '' !== $search ) {
				$query_args['s'] = $search;
			}

			if ( ! empty( $mime_types ) ) {
				$query_args['post_mime_type'] = $mime_types;
			}

			$attachments = new WP_Query( $query_args );

			if ( ! $attachments->have_posts() ) {
				break;
			}

			foreach ( $attachments->posts as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				if ( ! $attachment_id ) {
					continue;
				}

				if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
					continue;
				}

				if ( $skipped < $offset ) {
					++$skipped;
					continue;
				}

				$record = $this->format_attachment( $attachment_id );
				if ( empty( $record ) ) {
					continue;
				}

				$results[] = $record;
				++$results_count;

				if ( $results_count >= $limit ) {
					break 2;
				}
			}

			$query_offset += $batch_size;

			if ( $query_offset >= (int) $attachments->found_posts ) {
				break;
			}
		} while ( $results_count < $limit );

		return $results;
	}

	/**
	 * Build the response payload for a permitted attachment.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array
	 */
	protected function format_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return array();
		}

		$file_url      = wp_get_attachment_url( $attachment_id );
		$permalink     = get_attachment_link( $attachment_id );
		$mime_type     = get_post_mime_type( $attachment_id );
		$file_path     = get_attached_file( $attachment_id );
		$filesize      = 0;
		$filesize_text = '';

		if ( $file_path && file_exists( $file_path ) ) {
			$filesize = (int) filesize( $file_path );
			if ( $filesize > 0 ) {
				$filesize_text = size_format( $filesize );
			}
		}

		$payload = array(
			'id'           => $attachment_id,
			'title'        => get_the_title( $attachment ),
			'mime_type'    => $mime_type ? $mime_type : '',
			'description'  => wp_strip_all_tags( $attachment->post_content ),
			'caption'      => wp_strip_all_tags( $attachment->post_excerpt ),
			'download_url' => $file_url ? $file_url : '',
			'permalink'    => $permalink ? $permalink : '',
			'uploaded_at'  => get_post_time( DATE_W3C, true, $attachment ),
			'author_id'    => (int) $attachment->post_author,
		);

		if ( $filesize > 0 ) {
			$payload['filesize_bytes'] = $filesize;
		}

		if ( '' !== $filesize_text ) {
			$payload['filesize_human'] = $filesize_text;
		}

		if ( wp_attachment_is_image( $attachment_id ) ) {
			$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( is_string( $alt_text ) && '' !== $alt_text ) {
				$payload['alt_text'] = $alt_text;
			}
		}

		return $payload;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities to access attachments.
		);
	}
}
