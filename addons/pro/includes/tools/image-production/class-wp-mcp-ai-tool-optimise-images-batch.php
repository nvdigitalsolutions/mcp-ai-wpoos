<?php
/**
 * Tool: optimise_images_batch
 *
 * Optimizes a batch of images (compress, convert to webp, strip metadata).
 * Supports dry_run mode.
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
 * Optimise images batch tool.
 */
class WP_MCP_AI_Tool_Optimise_Images_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Optimise Images Batch tool requires the Image Production Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'optimise_images_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Optimise Images Batch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Optimizes a batch of images (compress, convert to webp, strip metadata). Supports dry_run mode for preview without modifying.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Array of attachment IDs to optimize.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'quality'         => array(
					'type'        => 'integer',
					'description' => __( 'Output quality (1-100). 82 is a good balance. Default: 82.', 'mcp-ai-wpoos-pro' ),
					'default'     => 82,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'convert_to_webp' => array(
					'type'        => 'boolean',
					'description' => __( 'Convert JPEG/PNG images to WebP format. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'strip_metadata'  => array(
					'type'        => 'boolean',
					'description' => __( 'Strip EXIF and other metadata from images. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be optimized without applying. Default: true for safety.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'attachment_ids' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'requires-capability',
			'idempotent',
			'performance-impact',
			'local-only',
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
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'content_manager', 'administrator' ),
			'risk_level'            => 'action',
		);
	}

	/**
	 * Check if PHP GD extension is available.
	 *
	 * @return bool
	 */
	private function has_gd_library() {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Calculate potential size savings for a given file.
	 *
	 * @param string $file_path File path.
	 * @param int    $quality   Target quality.
	 * @param bool   $to_webp   Whether conversion to WebP is planned.
	 * @return array Savings estimates.
	 */
	private function estimate_savings( $file_path, $quality, $to_webp ) {
		$current_size = filesize( $file_path );
		$ext          = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Rough estimation based on format and quality.
		$estimated_size = $current_size;

		if ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) {
			// JPEG quality reduction.
			$estimated_size = (int) ( $current_size * ( $quality / 100 ) );
			if ( $to_webp ) {
				// WebP typically 25-35% smaller than JPEG at same quality.
				$estimated_size = (int) ( $estimated_size * 0.7 );
			}
		} elseif ( 'png' === $ext ) {
			if ( $to_webp ) {
				// PNG to WebP can be very significant.
				$estimated_size = (int) ( $current_size * 0.3 );
			}
		} elseif ( 'webp' === $ext ) {
			// WebP re-encode at lower quality.
			$estimated_size = (int) ( $current_size * ( $quality / 100 ) );
		} else {
			$estimated_size = $current_size;
		}

		$saved = max( 0, $current_size - $estimated_size );

		return array(
			'current_bytes'       => $current_size,
			'current_formatted'   => size_format( $current_size ),
			'estimated_bytes'     => $estimated_size,
			'estimated_formatted' => size_format( $estimated_size ),
			'savings_bytes'       => $saved,
			'savings_formatted'   => size_format( $saved ),
			'savings_percent'     => $current_size > 0 ? round( ( $saved / $current_size ) * 100, 1 ) : 0,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Optimization results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$attachment_ids  = isset( $arguments['attachment_ids'] ) ? (array) $arguments['attachment_ids'] : array();
		$quality         = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 82;
		$convert_to_webp = isset( $arguments['convert_to_webp'] ) ? (bool) $arguments['convert_to_webp'] : true;
		$strip_metadata  = isset( $arguments['strip_metadata'] ) ? (bool) $arguments['strip_metadata'] : true;
		$dry_run         = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		$quality = max( 1, min( 100, $quality ) );

		// Sanitize attachment IDs.
		$attachment_ids = array_map( 'absint', $attachment_ids );
		$attachment_ids = array_filter(
			$attachment_ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $attachment_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No valid attachment IDs provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check GD availability if not dry run and WebP conversion requested.
		if ( ! $dry_run && $convert_to_webp && ! $this->has_gd_library() ) {
			return array(
				'success' => false,
				'error'   => __( 'PHP GD extension is required for WebP conversion. Please enable the GD extension on your server.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$results       = array();
		$processed     = 0;
		$skipped       = 0;
		$errors        = array();
		$total_savings = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$post = get_post( $attachment_id );

			if ( ! $post || 'attachment' !== $post->post_type ) {
				$errors[] = array(
					'id'    => $attachment_id,
					'error' => __( 'Attachment not found or invalid post type.', 'mcp-ai-wpoos-pro' ),
				);
				++$skipped;
				continue;
			}

			$file_path = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				$errors[] = array(
					'id'    => $attachment_id,
					'error' => __( 'File does not exist on disk.', 'mcp-ai-wpoos-pro' ),
				);
				++$skipped;
				continue;
			}

			$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

			// Estimate savings.
			$savings = $this->estimate_savings( $file_path, $quality, $convert_to_webp );

			$entry = array(
				'id'        => $attachment_id,
				'title'     => esc_html( $post->post_title ),
				'file'      => esc_html( basename( $file_path ) ),
				'mime_type' => esc_html( $post->post_mime_type ),
				'dry_run'   => $dry_run,
				'savings'   => $savings,
				'actions'   => array(),
			);

			if ( $dry_run ) {
				// Preview mode - list planned actions.
				if ( $convert_to_webp && ! in_array( $ext, array( 'webp', 'avif' ), true ) ) {
					$entry['actions'][] = sprintf(
						/* translators: %s: target format */
						__( 'Would convert to %s', 'mcp-ai-wpoos-pro' ),
						'WebP'
					);
				}
				if ( $quality < 100 ) {
					$entry['actions'][] = sprintf(
						/* translators: %d: quality percentage */
						__( 'Would compress to %d%% quality', 'mcp-ai-wpoos-pro' ),
						$quality
					);
				}
				if ( $strip_metadata ) {
					$entry['actions'][] = __( 'Would strip EXIF/metadata', 'mcp-ai-wpoos-pro' );
				}
				$entry['status'] = 'preview';
				++$processed;
				$total_savings += $savings['savings_bytes'];
			} else {
				// Actually optimize.
				$result = $this->optimize_image( $attachment_id, $file_path, $quality, $convert_to_webp, $strip_metadata );

				if ( is_wp_error( $result ) ) {
					$entry['status'] = 'error';
					$entry['error']  = $result->get_error_message();
					$errors[]        = $entry;
					++$skipped;
				} else {
					$entry['status']         = 'optimized';
					$entry['result']         = $result;
					$entry['actual_savings'] = $result['savings'];
					++$processed;
					$total_savings += $result['savings']['savings_bytes'];

					// Mark as optimized.
					update_post_meta( $attachment_id, '_wp_mcp_ai_optimized', current_time( 'mysql' ) );
					update_post_meta( $attachment_id, '_wp_mcp_ai_optimization_quality', $quality );

					/**
					 * Fires after an image is optimized.
					 *
					 * @since 2.9.0
					 *
					 * @param int   $attachment_id The attachment post ID.
					 * @param array $result        Optimization result data.
					 */
					do_action( 'wp_mcp_ai_image_after_optimization', $attachment_id, $result );
				}
			}

			$results[] = $entry;
		}

		return array(
			'success'       => true,
			'message'       => $dry_run
				? sprintf(
					/* translators: 1: number of images previewed, 2: estimated savings */
					__( 'Previewed optimization for %1$d image(s). Estimated savings: %2$s.', 'mcp-ai-wpoos-pro' ),
					$processed,
					size_format( $total_savings )
				)
				: sprintf(
					/* translators: 1: number of images optimized, 2: actual savings */
					__( 'Optimized %1$d image(s). Total savings: %2$s.', 'mcp-ai-wpoos-pro' ),
					$processed,
					size_format( $total_savings )
				),
			'dry_run'       => $dry_run,
			'total'         => count( $attachment_ids ),
			'processed'     => $processed,
			'skipped'       => $skipped,
			'errors_count'  => count( $errors ),
			'total_savings' => size_format( $total_savings ),
			'configuration' => array(
				'quality'         => $quality,
				'convert_to_webp' => $convert_to_webp,
				'strip_metadata'  => $strip_metadata,
			),
			'results'       => $results,
		);
	}

	/**
	 * Optimize a single image file.
	 *
	 * @param int    $attachment_id  Attachment ID.
	 * @param string $file_path      Path to the image file.
	 * @param int    $quality        Output quality (1-100).
	 * @param bool   $convert_to_webp Whether to convert to WebP.
	 * @param bool   $strip_metadata Whether to strip EXIF data.
	 * @return array|WP_Error Optimization result or error.
	 */
	private function optimize_image( $attachment_id, $file_path, $quality, $convert_to_webp, $strip_metadata ) {
		if ( ! $this->has_gd_library() ) {
			return new WP_Error(
				'wp_mcp_ai_no_gd',
				__( 'PHP GD extension is not available. Cannot optimize images.', 'mcp-ai-wpoos-pro' )
			);
		}

		$before_size = filesize( $file_path );
		$mime_type   = get_post_mime_type( $attachment_id );
		$ext         = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Strip metadata from JPEG files.
		if ( $strip_metadata && in_array( $mime_type, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			if ( function_exists( 'exif_read_data' ) ) {
				// Use a temporary approach: re-save the image via GD to strip EXIF.
				$img = imagecreatefromjpeg( $file_path );
				if ( $img ) {
					imagejpeg( $img, $file_path, $quality );
					imagedestroy( $img );
				}
			}
		}

		// Compress the image (re-save at target quality).
		$source = null;
		switch ( $mime_type ) {
			case 'image/jpeg':
			case 'image/jpg':
				$source = imagecreatefromjpeg( $file_path );
				break;
			case 'image/png':
				$source = imagecreatefrompng( $file_path );
				break;
			case 'image/gif':
				$source = imagecreatefromgif( $file_path );
				break;
			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$source = imagecreatefromwebp( $file_path );
				}
				break;
		}

		if ( $source ) {
			// Save compressed version.
			switch ( $mime_type ) {
				case 'image/jpeg':
				case 'image/jpg':
					imagejpeg( $source, $file_path, $quality );
					break;
				case 'image/png':
					// PNG compression level (0-9, 9 is maximum).
					$png_compression = (int) round( ( 100 - $quality ) / 100 * 9 );
					imagepng( $source, $file_path, $png_compression );
					break;
				case 'image/webp':
					if ( function_exists( 'imagewebp' ) ) {
						imagewebp( $source, $file_path, $quality );
					}
					break;
			}
			imagedestroy( $source );

			// Convert to WebP if requested (creates additional file alongside original).
			if ( $convert_to_webp && ! in_array( $ext, array( 'webp', 'avif' ), true ) && function_exists( 'imagewebp' ) ) {
				$webp_path = pathinfo( $file_path, PATHINFO_DIRNAME ) . '/' . pathinfo( $file_path, PATHINFO_FILENAME ) . '.webp';
				$source2   = null;

				switch ( $mime_type ) {
					case 'image/jpeg':
					case 'image/jpg':
						$source2 = imagecreatefromjpeg( $file_path );
						break;
					case 'image/png':
						$source2 = imagecreatefrompng( $file_path );
						break;
				}

				if ( $source2 ) {
					imagewebp( $source2, $webp_path, $quality );
					imagedestroy( $source2 );

					// Register the WebP file as a new attachment.
					$webp_attachment_id = wp_insert_attachment(
						array(
							'post_title'     => pathinfo( $webp_path, PATHINFO_FILENAME ),
							'post_mime_type' => 'image/webp',
							'post_status'    => 'inherit',
						),
						$webp_path,
						0
					);

					if ( ! is_wp_error( $webp_attachment_id ) ) {
						require_once ABSPATH . 'wp-admin/includes/image.php';
						$attach_data = wp_generate_attachment_metadata( $webp_attachment_id, $webp_path );
						wp_update_attachment_metadata( $webp_attachment_id, $attach_data );

						// Link WebP as an alternative source for the original.
						update_post_meta( $attachment_id, '_wp_mcp_ai_webp_version', $webp_attachment_id );
						update_post_meta( $webp_attachment_id, '_wp_mcp_ai_original_id', $attachment_id );
					}
				}
			}

			// Regenerate thumbnail metadata.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $attach_data );
		}

		clearstatcache( true, $file_path );
		$after_size = filesize( $file_path );
		$saved      = $before_size - $after_size;

		return array(
			'original_size'     => size_format( $before_size ),
			'optimized_size'    => size_format( $after_size ),
			'savings'           => array(
				'current_bytes'       => $before_size,
				'current_formatted'   => size_format( $before_size ),
				'estimated_bytes'     => $after_size,
				'estimated_formatted' => size_format( $after_size ),
				'savings_bytes'       => max( 0, $saved ),
				'savings_formatted'   => size_format( max( 0, $saved ) ),
				'savings_percent'     => $before_size > 0 ? round( ( max( 0, $saved ) / $before_size ) * 100, 1 ) : 0,
			),
			'quality_applied'   => $quality,
			'webp_converted'    => $convert_to_webp,
			'metadata_stripped' => $strip_metadata,
		);
	}
}
