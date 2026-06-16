<?php
/**
 * Tool: get_unwatermarked_images
 *
 * Retrieves images that have not been watermarked (no `_is_watermarked` meta).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get unwatermarked images tool.
 */
class WP_MCP_AI_Tool_Get_Unwatermarked_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_image_production_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Get Unwatermarked Images tool requires the Image Production Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_unwatermarked_images';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Unwatermarked Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves images that have not been watermarked (no `_is_watermarked` meta).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'mime_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by MIME type prefix (e.g. "image/jpeg"). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'date_from' => array(
					'type'        => 'string',
					'description' => __( 'Filter images uploaded on or after this date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'Filter images uploaded on or before this date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results. Default: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'cacheable',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'image_production',
			'post_type'             => 'attachment',
			'pattern_compatibility' => array( 'orchestrator', 'sequential', 'standalone' ),
			'profession_tags'       => array( 'content_manager', 'administrator' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Unwatermarked image data.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$mime_type = isset( $arguments['mime_type'] ) ? sanitize_text_field( $arguments['mime_type'] ) : '';
		$date_from = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to   = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;
		$limit     = max( 1, min( 500, $limit ) );

		// Build query args.
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'post_mime_type' => 'image',
		);

		// Apply date filters.
		if ( '' !== $date_from || '' !== $date_to ) {
			$date_query = array();

			if ( '' !== $date_from ) {
				$date_query['after'] = $date_from;
			}
			if ( '' !== $date_to ) {
				$date_query['before'] = $date_to . ' 23:59:59';
			}

			$date_query['inclusive']  = true;
			$query_args['date_query'] = array( $date_query );
		}

		// Apply MIME type filter.
		if ( '' !== $mime_type ) {
			$query_args['post_mime_type'] = $mime_type;
		}

		// Use meta query to find images without watermark mark.
		$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_is_watermarked',
				'compare' => 'NOT EXISTS',
			),
		);

		$attachments = get_posts( $query_args );

		$images = array();
		foreach ( $attachments as $attachment ) {
			$file_path = get_attached_file( $attachment->ID );

			$images[] = array(
				'id'          => $attachment->ID,
				'title'       => esc_html( $attachment->post_title ),
				'file'        => $file_path ? esc_html( basename( $file_path ) ) : '',
				'mime_type'   => esc_html( $attachment->post_mime_type ),
				'upload_date' => esc_html( $attachment->post_date ),
				'url'         => esc_url( wp_get_attachment_url( $attachment->ID ) ),
				'file_size'   => $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : '',
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of unwatermarked images found */
				__( 'Found %d unwatermarked image(s).', 'mcp-ai-wpoos-pro' ),
				count( $images )
			),
			'count'   => count( $images ),
			'images'  => $images,
			'filters' => array(
				'mime_type' => $mime_type,
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'limit'     => $limit,
			),
		);
	}
}
