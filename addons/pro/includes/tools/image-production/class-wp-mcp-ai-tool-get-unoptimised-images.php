<?php
/**
 * Tool: get_unoptimised_images
 *
 * Retrieves images that may need optimization (large file size, not webp, etc.).
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
 * Get unoptimised images tool.
 */
class WP_MCP_AI_Tool_Get_Unoptimised_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Get Unoptimised Images tool requires the Image Production Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_unoptimised_images';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Unoptimised Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves images that may need optimization (large file size, not webp, etc.).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'min_size_kb' => array(
					'type'        => 'integer',
					'description' => __( 'Minimum file size in KB to consider. Default: 100.', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
				),
				'mime_type'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by MIME type prefix (e.g. "image/jpeg"). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'date_from'   => array(
					'type'        => 'string',
					'description' => __( 'Filter images uploaded on or after this date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'date_to'     => array(
					'type'        => 'string',
					'description' => __( 'Filter images uploaded on or before this date (YYYY-MM-DD). Optional.', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'       => array(
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
			'performance-impact',
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
	 * Check if a file is already in WebP format.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if WebP version exists.
	 */
	private function has_webp_version( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		$dir      = dirname( $file );
		$basename = pathinfo( $file, PATHINFO_FILENAME );
		$webp     = $dir . '/' . $basename . '.webp';

		return file_exists( $webp );
	}

	/**
	 * Check if an image has been marked as optimized.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if marked as optimized.
	 */
	private function is_marked_optimized( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, '_wp_mcp_ai_optimized', true );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Unoptimised image data.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$min_size_kb = isset( $arguments['min_size_kb'] ) ? absint( $arguments['min_size_kb'] ) : 100;
		$mime_type   = isset( $arguments['mime_type'] ) ? sanitize_text_field( $arguments['mime_type'] ) : '';
		$date_from   = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to     = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;

		$min_size_kb = max( 1, $min_size_kb );
		$limit       = max( 1, min( 500, $limit ) );

		$min_size_bytes = $min_size_kb * 1024;

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

		$attachments = get_posts( $query_args );

		$images = array();
		foreach ( $attachments as $attachment ) {
			$file_path = get_attached_file( $attachment->ID );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			$file_size = filesize( $file_path );

			// Skip images already smaller than threshold.
			if ( $file_size < $min_size_bytes ) {
				continue;
			}

			// Determine optimization needs.
			$reasons = array();

			if ( $file_size >= $min_size_bytes ) {
				$reasons[] = 'large_file';
			}

			$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'webp', 'avif' ), true ) && ! $this->has_webp_version( $attachment->ID ) ) {
				$reasons[] = 'missing_webp';
			}

			if ( $this->is_marked_optimized( $attachment->ID ) ) {
				$reasons[] = 'previously_optimized_still_large';
			}

			if ( empty( $reasons ) ) {
				continue;
			}

			$images[] = array(
				'id'              => $attachment->ID,
				'title'           => esc_html( $attachment->post_title ),
				'file'            => esc_html( basename( $file_path ) ),
				'mime_type'       => esc_html( $attachment->post_mime_type ),
				'extension'       => esc_html( $ext ),
				'file_size'       => size_format( $file_size ),
				'file_size_bytes' => $file_size,
				'upload_date'     => esc_html( $attachment->post_date ),
				'url'             => esc_url( wp_get_attachment_url( $attachment->ID ) ),
				'reasons'         => $reasons,
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of unoptimised images found */
				__( 'Found %d unoptimised image(s).', 'mcp-ai-wpoos-pro' ),
				count( $images )
			),
			'count'   => count( $images ),
			'images'  => $images,
			'filters' => array(
				'min_size_kb' => $min_size_kb,
				'mime_type'   => $mime_type,
				'date_from'   => $date_from,
				'date_to'     => $date_to,
				'limit'       => $limit,
			),
		);
	}
}
