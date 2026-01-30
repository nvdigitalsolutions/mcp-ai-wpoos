<?php
/**
 * Media Library Optimizer Tool
 *
 * Bulk image compression, format conversion (AVIF/WebP), lazy loading
 * configuration, unused media detection, and CDN preparation.
 *
 * Based on 2026 image optimization standards from:
 * - Google PageSpeed Insights recommendations
 * - Bluehost image optimization guide
 * - WordPress.org performance best practices
 * - WisdmLabs image SEO standards
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Library Optimizer Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Media_Library_Optimizer {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'media_library_optimizer';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                 => __( 'Media Library Optimizer', 'mcp-ai-wpoos' ),
			'description'          => __( 'Bulk image compression, AVIF/WebP conversion, lazy loading, unused media detection, and CDN preparation following 2026 standards.', 'mcp-ai-wpoos' ),
			'category'             => 'media',
			'required_capability'  => 'upload_files',
			'parameters'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action: analyze, compress, convert, detect_unused, or configure_lazy_loading', 'mcp-ai-wpoos' ),
					'required'    => true,
					'enum'        => array( 'analyze', 'compress', 'convert', 'detect_unused', 'configure_lazy_loading' ),
				),
				'target_format'    => array(
					'type'        => 'string',
					'description' => __( 'Target format for conversion: avif, webp, or auto', 'mcp-ai-wpoos' ),
					'default'     => 'auto',
					'enum'        => array( 'avif', 'webp', 'auto' ),
				),
				'quality'          => array(
					'type'        => 'integer',
					'description' => __( 'Compression quality (1-100, default: 85)', 'mcp-ai-wpoos' ),
					'default'     => 85,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Number of images to process (default: 50)', 'mcp-ai-wpoos' ),
					'default'     => 50,
				),
				'age_days'         => array(
					'type'        => 'integer',
					'description' => __( 'Age in days for unused media detection (default: 180)', 'mcp-ai-wpoos' ),
					'default'     => 180,
				),
				'preserve_original' => array(
					'type'        => 'boolean',
					'description' => __( 'Keep original files when converting', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.0.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate parameters.
		$action            = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'analyze';
		$target_format     = isset( $arguments['target_format'] ) ? sanitize_text_field( $arguments['target_format'] ) : 'auto';
		$quality           = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;
		$limit             = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$age_days          = isset( $arguments['age_days'] ) ? absint( $arguments['age_days'] ) : 180;
		$preserve_original = isset( $arguments['preserve_original'] ) ? (bool) $arguments['preserve_original'] : true;

		// Validate quality.
		$quality = max( 1, min( 100, $quality ) );

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

		// Route to action handler.
		switch ( $action ) {
			case 'analyze':
				$result = $this->handle_analyze();
				break;

			case 'compress':
				$result = $this->handle_compress( $quality, $limit );
				break;

			case 'convert':
				$result = $this->handle_convert( $target_format, $quality, $limit, $preserve_original );
				break;

			case 'detect_unused':
				$result = $this->handle_detect_unused( $age_days );
				break;

			case 'configure_lazy_loading':
				$result = $this->handle_lazy_loading();
				break;

			default:
				$result = array(
					'success' => false,
					'error'   => __( 'Invalid action specified', 'mcp-ai-wpoos' ),
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle analyze action
	 *
	 * @since 1.0.0
	 * @return array Analysis result.
	 */
	private function handle_analyze() {
		// Get all images.
		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
			)
		);

		$total_images  = count( $images );
		$total_size    = 0;
		$format_counts = array(
			'jpeg' => 0,
			'png'  => 0,
			'gif'  => 0,
			'webp' => 0,
			'avif' => 0,
			'other' => 0,
		);

		$optimization_opportunities = array();

		foreach ( $images as $image_id ) {
			$file_path = get_attached_file( $image_id );
			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			$file_size = filesize( $file_path );
			$total_size += $file_size;

			// Determine format.
			$mime_type = get_post_mime_type( $image_id );
			$format    = $this->mime_to_format( $mime_type );
			if ( isset( $format_counts[ $format ] ) ) {
				$format_counts[ $format ]++;
			} else {
				$format_counts['other']++;
			}

			// Check for optimization opportunities.
			if ( $file_size > 500000 ) { // >500KB.
				$optimization_opportunities[] = array(
					'id'          => $image_id,
					'file'        => basename( $file_path ),
					'size'        => $file_size,
					'size_human'  => size_format( $file_size ),
					'format'      => $format,
					'reason'      => __( 'Large file size', 'mcp-ai-wpoos' ),
					'recommended' => $format === 'png' ? 'avif' : 'webp',
				);
			}

			// Check if AVIF/WebP conversion beneficial.
			if ( in_array( $format, array( 'jpeg', 'png' ), true ) && $file_size > 100000 ) {
				// Already added above, but could add specific recommendation.
				continue;
			}
		}

		return array(
			'success'                     => true,
			'summary'                     => array(
				'total_images'                => $total_images,
				'total_size'                  => $total_size,
				'total_size_human'            => size_format( $total_size ),
				'average_size'                => $total_images > 0 ? round( $total_size / $total_images ) : 0,
				'average_size_human'          => size_format( $total_images > 0 ? round( $total_size / $total_images ) : 0 ),
			),
			'format_distribution'         => $format_counts,
			'optimization_opportunities'  => array_slice( $optimization_opportunities, 0, 20 ), // Top 20.
			'total_opportunities'         => count( $optimization_opportunities ),
			'estimated_savings_potential' => $this->estimate_savings( $total_size, $format_counts ),
			'recommendations'             => $this->generate_optimization_recommendations( $format_counts, $total_images ),
		);
	}

	/**
	 * Handle compress action
	 *
	 * @since 1.0.0
	 * @param int $quality Compression quality.
	 * @param int $limit   Number to process.
	 * @return array Compression result.
	 */
	private function handle_compress( $quality, $limit ) {
		// Get images to compress.
		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => $limit,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_wp_mcp_ai_compressed',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$compressed    = 0;
		$failed        = 0;
		$total_saved   = 0;
		$details       = array();

		foreach ( $images as $image_id ) {
			$file_path = get_attached_file( $image_id );
			if ( ! file_exists( $file_path ) ) {
				$failed++;
				continue;
			}

			$original_size = filesize( $file_path );

			// Compress image.
			$compressed_result = $this->compress_image( $file_path, $quality );

			if ( $compressed_result['success'] ) {
				$new_size = filesize( $file_path );
				$saved    = $original_size - $new_size;
				$total_saved += $saved;

				update_post_meta( $image_id, '_wp_mcp_ai_compressed', true );
				update_post_meta( $image_id, '_wp_mcp_ai_compression_quality', $quality );

				$details[] = array(
					'id'            => $image_id,
					'file'          => basename( $file_path ),
					'original_size' => size_format( $original_size ),
					'new_size'      => size_format( $new_size ),
					'saved'         => size_format( $saved ),
					'reduction'     => round( ( $saved / $original_size ) * 100, 2 ) . '%',
				);

				$compressed++;
			} else {
				$failed++;
			}
		}

		return array(
			'success'     => true,
			'processed'   => $compressed + $failed,
			'compressed'  => $compressed,
			'failed'      => $failed,
			'total_saved' => size_format( $total_saved ),
			'quality'     => $quality,
			'details'     => $details,
		);
	}

	/**
	 * Handle convert action
	 *
	 * @since 1.0.0
	 * @param string $target_format     Target format.
	 * @param int    $quality           Conversion quality.
	 * @param int    $limit             Number to process.
	 * @param bool   $preserve_original Keep originals.
	 * @return array Conversion result.
	 */
	private function handle_convert( $target_format, $quality, $limit, $preserve_original ) {
		// Determine best format.
		if ( 'auto' === $target_format ) {
			// AVIF is best (85% smaller than PNG, 50% smaller than WebP).
			// But check for browser support via WordPress.
			$target_format = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ? 'avif' : 'webp';
		}

		// Get images to convert.
		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => $limit,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
			)
		);

		$converted  = 0;
		$failed     = 0;
		$total_saved = 0;
		$details    = array();

		foreach ( $images as $image_id ) {
			$file_path = get_attached_file( $image_id );
			if ( ! file_exists( $file_path ) ) {
				$failed++;
				continue;
			}

			$original_size = filesize( $file_path );

			// Convert image.
			$convert_result = $this->convert_image_format( $file_path, $target_format, $quality, $preserve_original );

			if ( $convert_result['success'] ) {
				$new_file = $convert_result['new_file'];
				$new_size = filesize( $new_file );
				$saved    = $original_size - $new_size;
				$total_saved += $saved;

				// Update attachment.
				update_attached_file( $image_id, $new_file );
				wp_update_post(
					array(
						'ID'             => $image_id,
						'post_mime_type' => $convert_result['mime_type'],
					)
				);

				$details[] = array(
					'id'            => $image_id,
					'original_file' => basename( $file_path ),
					'new_file'      => basename( $new_file ),
					'format'        => $target_format,
					'original_size' => size_format( $original_size ),
					'new_size'      => size_format( $new_size ),
					'saved'         => size_format( $saved ),
					'reduction'     => round( ( $saved / $original_size ) * 100, 2 ) . '%',
				);

				$converted++;
			} else {
				$failed++;
			}
		}

		return array(
			'success'           => true,
			'processed'         => $converted + $failed,
			'converted'         => $converted,
			'failed'            => $failed,
			'target_format'     => $target_format,
			'quality'           => $quality,
			'preserve_original' => $preserve_original,
			'total_saved'       => size_format( $total_saved ),
			'details'           => $details,
		);
	}

	/**
	 * Handle detect unused action
	 *
	 * @since 1.0.0
	 * @param int $age_days Age threshold.
	 * @return array Unused detection result.
	 */
	private function handle_detect_unused( $age_days ) {
		global $wpdb;

		// Get all attachments older than age threshold.
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$age_days} days" ) );

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => $cutoff_date,
					),
				),
			)
		);

		$unused         = array();
		$total_size     = 0;

		foreach ( $attachments as $attachment_id ) {
			// Check if attached to any post.
			$parent_id = wp_get_post_parent_id( $attachment_id );

			// Check if used in content.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$used_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish'",
					'%' . $wpdb->esc_like( wp_get_attachment_url( $attachment_id ) ) . '%'
				)
			);

			if ( 0 === $parent_id && 0 === (int) $used_count ) {
				$file_path = get_attached_file( $attachment_id );
				$file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;

				$unused[] = array(
					'id'              => $attachment_id,
					'title'           => get_the_title( $attachment_id ),
					'url'             => wp_get_attachment_url( $attachment_id ),
					'file'            => basename( $file_path ),
					'size'            => size_format( $file_size ),
					'uploaded_date'   => get_the_date( 'Y-m-d', $attachment_id ),
					'age_days'        => floor( ( time() - get_post_time( 'U', false, $attachment_id ) ) / DAY_IN_SECONDS ),
				);

				$total_size += $file_size;
			}
		}

		return array(
			'success'              => true,
			'age_threshold_days'   => $age_days,
			'total_checked'        => count( $attachments ),
			'unused_count'         => count( $unused ),
			'unused_total_size'    => size_format( $total_size ),
			'potential_savings'    => size_format( $total_size ),
			'unused_media'         => array_slice( $unused, 0, 100 ), // First 100.
			'recommendations'      => array(
				__( 'Review unused media before deletion', 'mcp-ai-wpoos' ),
				__( 'Consider backup before bulk deletion', 'mcp-ai-wpoos' ),
				__( 'Some media may be used in widgets or theme templates', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Handle lazy loading configuration
	 *
	 * @since 1.0.0
	 * @return array Lazy loading result.
	 */
	private function handle_lazy_loading() {
		// WordPress 5.5+ has native lazy loading.
		$wp_lazy_loading = version_compare( get_bloginfo( 'version' ), '5.5', '>=' );

		return array(
			'success'               => true,
			'wordpress_native'      => $wp_lazy_loading,
			'status'                => $wp_lazy_loading
				? __( 'WordPress native lazy loading is enabled', 'mcp-ai-wpoos' )
				: __( 'Consider upgrading WordPress for native lazy loading', 'mcp-ai-wpoos' ),
			'recommendations'       => array(
				__( 'WordPress 5.5+ includes native lazy loading', 'mcp-ai-wpoos' ),
				__( 'Add loading="lazy" attribute to images automatically', 'mcp-ai-wpoos' ),
				__( 'Consider plugins like Lazy Load by WP Rocket for advanced features', 'mcp-ai-wpoos' ),
				__( 'Test Core Web Vitals after enabling', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Compress image
	 *
	 * @since 1.0.0
	 * @param string $file_path File path.
	 * @param int    $quality   Quality level.
	 * @return array Compression result.
	 */
	private function compress_image( $file_path, $quality ) {
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			return array( 'success' => false );
		}

		$image_editor->set_quality( $quality );
		$saved = $image_editor->save( $file_path );

		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false );
		}

		return array( 'success' => true );
	}

	/**
	 * Convert image format
	 *
	 * @since 1.0.0
	 * @param string $file_path         File path.
	 * @param string $target_format     Target format.
	 * @param int    $quality           Quality level.
	 * @param bool   $preserve_original Keep original.
	 * @return array Conversion result.
	 */
	private function convert_image_format( $file_path, $target_format, $quality, $preserve_original ) {
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			return array( 'success' => false );
		}

		$image_editor->set_quality( $quality );

		// Determine new file path.
		$path_info = pathinfo( $file_path );
		$extension = $target_format === 'avif' ? 'avif' : 'webp';
		$new_file  = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;

		// Set output format.
		$mime_types = array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
		);
		$mime_type  = isset( $mime_types[ $target_format ] ) ? $mime_types[ $target_format ] : 'image/webp';

		$saved = $image_editor->save( $new_file, $mime_type );

		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false );
		}

		// Delete original if not preserving.
		if ( ! $preserve_original && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		return array(
			'success'   => true,
			'new_file'  => $new_file,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Convert MIME type to format
	 *
	 * @since 1.0.0
	 * @param string $mime_type MIME type.
	 * @return string Format name.
	 */
	private function mime_to_format( $mime_type ) {
		$formats = array(
			'image/jpeg' => 'jpeg',
			'image/jpg'  => 'jpeg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);

		return isset( $formats[ $mime_type ] ) ? $formats[ $mime_type ] : 'other';
	}

	/**
	 * Estimate savings potential
	 *
	 * @since 1.0.0
	 * @param int   $total_size    Total size.
	 * @param array $format_counts Format distribution.
	 * @return array Savings estimate.
	 */
	private function estimate_savings( $total_size, $format_counts ) {
		// AVIF: ~85% smaller than PNG, ~50% smaller than JPEG.
		// WebP: ~30% smaller than JPEG, ~80% smaller than PNG.

		$jpeg_count = $format_counts['jpeg'];
		$png_count  = $format_counts['png'];

		$estimated_avif_savings = ( $jpeg_count * 0.50 + $png_count * 0.85 ) * ( $total_size / array_sum( $format_counts ) );
		$estimated_webp_savings = ( $jpeg_count * 0.30 + $png_count * 0.80 ) * ( $total_size / array_sum( $format_counts ) );

		return array(
			'avif_potential' => size_format( $estimated_avif_savings ),
			'webp_potential' => size_format( $estimated_webp_savings ),
		);
	}

	/**
	 * Generate optimization recommendations
	 *
	 * @since 1.0.0
	 * @param array $format_counts Format counts.
	 * @param int   $total_images  Total images.
	 * @return array Recommendations.
	 */
	private function generate_optimization_recommendations( $format_counts, $total_images ) {
		$recommendations = array();

		if ( $format_counts['png'] > $total_images * 0.3 ) {
			$recommendations[] = __( 'High PNG usage detected. Consider converting to AVIF for 85% size reduction.', 'mcp-ai-wpoos' );
		}

		if ( $format_counts['jpeg'] > $total_images * 0.5 ) {
			$recommendations[] = __( 'Many JPEG images detected. WebP conversion can save ~30% file size.', 'mcp-ai-wpoos' );
		}

		if ( $format_counts['gif'] > 0 ) {
			$recommendations[] = __( 'GIF images detected. Consider converting to video formats (MP4, WebM) for animations.', 'mcp-ai-wpoos' );
		}

		if ( $format_counts['avif'] === 0 && $format_counts['webp'] === 0 ) {
			$recommendations[] = __( 'No modern formats detected. AVIF/WebP conversion highly recommended.', 'mcp-ai-wpoos' );
		}

		$recommendations[] = __( 'Enable lazy loading for images below the fold (WordPress 5.5+).', 'mcp-ai-wpoos' );
		$recommendations[] = __( 'Consider CDN for image delivery to reduce server load.', 'mcp-ai-wpoos' );
		$recommendations[] = __( 'Regularly audit and remove unused media files.', 'mcp-ai-wpoos' );

		return $recommendations;
	}

	/**
	 * Check if tool has privacy data
	 *
	 * @since 1.0.0
	 * @return bool False - no privacy data.
	 */
	public function has_privacy_data() {
		return false;
	}
}
