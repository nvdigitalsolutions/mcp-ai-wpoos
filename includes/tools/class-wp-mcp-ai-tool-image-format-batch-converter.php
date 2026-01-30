<?php
/**
 * Image Format Batch Converter Tool
 *
 * Batch convert images to AVIF/WebP/JPEG XL with responsive srcset generation,
 * modern format fallback chains, and Art Direction support.
 *
 * Based on 2026 image standards from:
 * - Chrome 127+ JPEG XL support
 * - Safari 18+ AVIF support  
 * - Web.dev responsive images guide
 * - WordPress 6.8+ modern image format support
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
 * Image Format Batch Converter Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Image_Format_Batch_Converter {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'image_format_batch_converter';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                 => __( 'Image Format Batch Converter', 'mcp-ai-wpoos' ),
			'description'          => __( 'Batch convert images to AVIF/WebP/JPEG XL with responsive srcset generation, automatic fallback chains, and Art Direction support for 2026 standards.', 'mcp-ai-wpoos' ),
			'category'             => 'media',
			'required_capability'  => 'upload_files',
			'parameters'           => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action: convert_batch, generate_srcset, create_picture_element, or validate_support', 'mcp-ai-wpoos' ),
					'required'    => true,
					'enum'        => array( 'convert_batch', 'generate_srcset', 'create_picture_element', 'validate_support' ),
				),
				'target_formats'       => array(
					'type'        => 'array',
					'description' => __( 'Target formats in priority order: avif, webp, jxl (JPEG XL)', 'mcp-ai-wpoos' ),
					'default'     => array( 'avif', 'webp' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'avif', 'webp', 'jxl' ),
					),
				),
				'quality'              => array(
					'type'        => 'integer',
					'description' => __( 'Conversion quality (1-100, default: 85)', 'mcp-ai-wpoos' ),
					'default'     => 85,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'image_ids'            => array(
					'type'        => 'array',
					'description' => __( 'Specific image IDs to convert (optional, processes all if empty)', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'generate_sizes'       => array(
					'type'        => 'array',
					'description' => __( 'Responsive sizes to generate (widths in pixels)', 'mcp-ai-wpoos' ),
					'default'     => array( 320, 640, 768, 1024, 1280, 1920, 2560 ),
					'items'       => array( 'type' => 'integer' ),
				),
				'art_direction'        => array(
					'type'        => 'boolean',
					'description' => __( 'Enable Art Direction (different crops for mobile/desktop)', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'preserve_original'    => array(
					'type'        => 'boolean',
					'description' => __( 'Keep original files when converting', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'limit'                => array(
					'type'        => 'integer',
					'description' => __( 'Number of images to process per batch (default: 25)', 'mcp-ai-wpoos' ),
					'default'     => 25,
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
		$start_time = microtime( true );

		// Validate parameters.
		$action            = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'convert_batch';
		$target_formats    = isset( $arguments['target_formats'] ) && is_array( $arguments['target_formats'] ) ? array_map( 'sanitize_text_field', $arguments['target_formats'] ) : array( 'avif', 'webp' );
		$quality           = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;
		$image_ids         = isset( $arguments['image_ids'] ) && is_array( $arguments['image_ids'] ) ? array_map( 'absint', $arguments['image_ids'] ) : array();
		$generate_sizes    = isset( $arguments['generate_sizes'] ) && is_array( $arguments['generate_sizes'] ) ? array_map( 'absint', $arguments['generate_sizes'] ) : array( 320, 640, 768, 1024, 1280, 1920, 2560 );
		$art_direction     = isset( $arguments['art_direction'] ) ? (bool) $arguments['art_direction'] : false;
		$preserve_original = isset( $arguments['preserve_original'] ) ? (bool) $arguments['preserve_original'] : true;
		$limit             = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;

		// Validate quality.
		$quality = max( 1, min( 100, $quality ) );

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

		// Route to action handler.
		switch ( $action ) {
			case 'convert_batch':
				$result = $this->handle_convert_batch( $target_formats, $quality, $image_ids, $generate_sizes, $preserve_original, $limit );
				break;

			case 'generate_srcset':
				$result = $this->handle_generate_srcset( $image_ids, $generate_sizes );
				break;

			case 'create_picture_element':
				$result = $this->handle_create_picture_element( $image_ids, $target_formats, $art_direction );
				break;

			case 'validate_support':
				$result = $this->handle_validate_support();
				break;

			default:
				$result = array(
					'success' => false,
					'error'   => __( 'Invalid action specified', 'mcp-ai-wpoos' ),
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle convert batch action
	 *
	 * @since 1.0.0
	 * @param array $target_formats    Target formats.
	 * @param int   $quality           Conversion quality.
	 * @param array $image_ids         Specific image IDs.
	 * @param array $generate_sizes    Responsive sizes.
	 * @param bool  $preserve_original Keep originals.
	 * @param int   $limit             Batch limit.
	 * @return array Conversion result.
	 */
	private function handle_convert_batch( $target_formats, $quality, $image_ids, $generate_sizes, $preserve_original, $limit ) {
		// Get images to convert.
		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
			'posts_per_page' => $limit,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
		);

		if ( ! empty( $image_ids ) ) {
			$query_args['post__in'] = $image_ids;
			$query_args['posts_per_page'] = -1;
		}

		$images = get_posts( $query_args );

		$converted     = 0;
		$failed        = 0;
		$total_saved   = 0;
		$details       = array();
		$format_stats  = array();

		foreach ( $images as $image_id ) {
			$file_path = get_attached_file( $image_id );
			if ( ! file_exists( $file_path ) ) {
				$failed++;
				continue;
			}

			$original_size = filesize( $file_path );
			$format_results = array();

			// Convert to each target format.
			foreach ( $target_formats as $format ) {
				$convert_result = $this->convert_to_format( $file_path, $format, $quality, $preserve_original );

				if ( $convert_result['success'] ) {
					$format_results[ $format ] = $convert_result;

					// Generate responsive sizes.
					if ( ! empty( $generate_sizes ) ) {
						$this->generate_responsive_sizes( $convert_result['new_file'], $generate_sizes, $quality );
					}

					// Track format statistics.
					if ( ! isset( $format_stats[ $format ] ) ) {
						$format_stats[ $format ] = array(
							'count'       => 0,
							'total_saved' => 0,
						);
					}
					$format_stats[ $format ]['count']++;
					$format_stats[ $format ]['total_saved'] += ( $original_size - filesize( $convert_result['new_file'] ) );
				}
			}

			if ( ! empty( $format_results ) ) {
				$best_format = $this->get_best_format( $format_results );
				$saved = $original_size - filesize( $format_results[ $best_format ]['new_file'] );
				$total_saved += $saved;

				// Update metadata.
				update_post_meta( $image_id, '_wp_mcp_ai_converted_formats', array_keys( $format_results ) );
				update_post_meta( $image_id, '_wp_mcp_ai_best_format', $best_format );

				$details[] = array(
					'id'               => $image_id,
					'original_file'    => basename( $file_path ),
					'formats'          => array_keys( $format_results ),
					'best_format'      => $best_format,
					'original_size'    => size_format( $original_size ),
					'best_format_size' => size_format( filesize( $format_results[ $best_format ]['new_file'] ) ),
					'saved'            => size_format( $saved ),
					'reduction'        => round( ( $saved / $original_size ) * 100, 2 ) . '%',
					'srcset_generated' => ! empty( $generate_sizes ),
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
			'target_formats'    => $target_formats,
			'quality'           => $quality,
			'total_saved'       => size_format( $total_saved ),
			'format_stats'      => $format_stats,
			'details'           => $details,
			'recommendations'   => $this->get_conversion_recommendations( $format_stats, $converted ),
		);
	}

	/**
	 * Handle generate srcset action
	 *
	 * @since 1.0.0
	 * @param array $image_ids      Image IDs.
	 * @param array $generate_sizes Responsive sizes.
	 * @return array Srcset generation result.
	 */
	private function handle_generate_srcset( $image_ids, $generate_sizes ) {
		if ( empty( $image_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No image IDs provided', 'mcp-ai-wpoos' ),
			);
		}

		$results = array();

		foreach ( $image_ids as $image_id ) {
			$file_path = get_attached_file( $image_id );
			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			// Generate srcset for image.
			$srcset = $this->build_srcset( $image_id, $generate_sizes );

			$results[] = array(
				'id'     => $image_id,
				'srcset' => $srcset,
				'sizes'  => $this->build_sizes_attribute( $generate_sizes ),
				'html'   => $this->build_responsive_image_html( $image_id, $srcset ),
			);
		}

		return array(
			'success' => true,
			'count'   => count( $results ),
			'results' => $results,
		);
	}

	/**
	 * Handle create picture element action
	 *
	 * @since 1.0.0
	 * @param array $image_ids      Image IDs.
	 * @param array $target_formats Target formats.
	 * @param bool  $art_direction  Enable art direction.
	 * @return array Picture element result.
	 */
	private function handle_create_picture_element( $image_ids, $target_formats, $art_direction ) {
		if ( empty( $image_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No image IDs provided', 'mcp-ai-wpoos' ),
			);
		}

		$results = array();

		foreach ( $image_ids as $image_id ) {
			$picture_html = $this->build_picture_element( $image_id, $target_formats, $art_direction );

			$results[] = array(
				'id'              => $image_id,
				'picture_html'    => $picture_html,
				'formats'         => $target_formats,
				'art_direction'   => $art_direction,
			);
		}

		return array(
			'success'        => true,
			'count'          => count( $results ),
			'results'        => $results,
			'implementation' => array(
				'description' => __( 'Use the <picture> element for modern image delivery', 'mcp-ai-wpoos' ),
				'benefits'    => array(
					__( 'Automatic format fallback for browser support', 'mcp-ai-wpoos' ),
					__( 'Art Direction for mobile/desktop optimization', 'mcp-ai-wpoos' ),
					__( 'Better Core Web Vitals (LCP, CLS)', 'mcp-ai-wpoos' ),
				),
			),
		);
	}

	/**
	 * Handle validate support action
	 *
	 * @since 1.0.0
	 * @return array Support validation result.
	 */
	private function handle_validate_support() {
		$formats = array( 'avif', 'webp', 'jxl' );
		$support = array();

		foreach ( $formats as $format ) {
			$mime_type = $this->get_mime_type( $format );
			$support[ $format ] = array(
				'mime_type'        => $mime_type,
				'wordpress_editor' => wp_image_editor_supports( array( 'mime_type' => $mime_type ) ),
				'php_support'      => $this->check_php_support( $format ),
				'recommended'      => $this->is_format_recommended( $format ),
			);
		}

		return array(
			'success'         => true,
			'format_support'  => $support,
			'recommendations' => array(
				__( 'AVIF provides best compression (85% smaller than PNG, 50% smaller than JPEG)', 'mcp-ai-wpoos' ),
				__( 'WebP is universally supported as fallback (99%+ browser coverage)', 'mcp-ai-wpoos' ),
				__( 'JPEG XL offers superior quality but limited browser support (Chrome 127+)', 'mcp-ai-wpoos' ),
				__( 'Always include JPEG/PNG fallback for older browsers', 'mcp-ai-wpoos' ),
			),
			'best_practice'   => array(
				'format_chain' => array( 'avif', 'webp', 'jpeg' ),
				'explanation'  => __( 'Serve AVIF first, fallback to WebP, then JPEG for maximum compatibility and performance', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Convert image to target format
	 *
	 * @since 1.0.0
	 * @param string $file_path         Source file path.
	 * @param string $format            Target format.
	 * @param int    $quality           Conversion quality.
	 * @param bool   $preserve_original Keep original.
	 * @return array Conversion result.
	 */
	private function convert_to_format( $file_path, $format, $quality, $preserve_original ) {
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			return array( 'success' => false );
		}

		$image_editor->set_quality( $quality );

		// Determine new file path.
		$path_info = pathinfo( $file_path );
		$extension = $this->get_extension( $format );
		$new_file  = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;

		$mime_type = $this->get_mime_type( $format );

		$saved = $image_editor->save( $new_file, $mime_type );

		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false );
		}

		return array(
			'success'   => true,
			'new_file'  => $new_file,
			'mime_type' => $mime_type,
			'format'    => $format,
		);
	}

	/**
	 * Generate responsive image sizes
	 *
	 * @since 1.0.0
	 * @param string $file_path Source file.
	 * @param array  $sizes     Width sizes.
	 * @param int    $quality   Quality.
	 * @return array Generated files.
	 */
	private function generate_responsive_sizes( $file_path, $sizes, $quality ) {
		$generated = array();
		$image_editor = wp_get_image_editor( $file_path );

		if ( is_wp_error( $image_editor ) ) {
			return $generated;
		}

		$path_info = pathinfo( $file_path );

		foreach ( $sizes as $width ) {
			$image_editor->resize( $width, null, false );
			$image_editor->set_quality( $quality );

			$new_file = $path_info['dirname'] . '/' . $path_info['filename'] . '-' . $width . 'w.' . $path_info['extension'];
			$saved = $image_editor->save( $new_file );

			if ( ! is_wp_error( $saved ) ) {
				$generated[] = array(
					'width' => $width,
					'file'  => $new_file,
				);
			}

			// Reset for next size.
			$image_editor = wp_get_image_editor( $file_path );
		}

		return $generated;
	}

	/**
	 * Build srcset attribute
	 *
	 * @since 1.0.0
	 * @param int   $image_id Image ID.
	 * @param array $sizes    Sizes.
	 * @return string Srcset attribute value.
	 */
	private function build_srcset( $image_id, $sizes ) {
		$file_path = get_attached_file( $image_id );
		$path_info = pathinfo( $file_path );
		$upload_dir = wp_upload_dir();
		$srcset_parts = array();

		foreach ( $sizes as $width ) {
			$filename = $path_info['filename'] . '-' . $width . 'w.' . $path_info['extension'];
			$file = $path_info['dirname'] . '/' . $filename;

			if ( file_exists( $file ) ) {
				$url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file );
				$srcset_parts[] = esc_url( $url ) . ' ' . $width . 'w';
			}
		}

		return implode( ', ', $srcset_parts );
	}

	/**
	 * Build sizes attribute
	 *
	 * @since 1.0.0
	 * @param array $sizes Width sizes.
	 * @return string Sizes attribute.
	 */
	private function build_sizes_attribute( $sizes ) {
		// Default responsive sizes.
		return '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw';
	}

	/**
	 * Build responsive image HTML
	 *
	 * @since 1.0.0
	 * @param int    $image_id Image ID.
	 * @param string $srcset   Srcset value.
	 * @return string Image HTML.
	 */
	private function build_responsive_image_html( $image_id, $srcset ) {
		$src = wp_get_attachment_image_url( $image_id, 'full' );
		$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		return sprintf(
			'<img src="%s" srcset="%s" sizes="%s" alt="%s" loading="lazy" decoding="async">',
			esc_url( $src ),
			esc_attr( $srcset ),
			esc_attr( $this->build_sizes_attribute( array() ) ),
			esc_attr( $alt )
		);
	}

	/**
	 * Build picture element HTML
	 *
	 * @since 1.0.0
	 * @param int   $image_id       Image ID.
	 * @param array $target_formats Formats.
	 * @param bool  $art_direction  Art direction.
	 * @return string Picture HTML.
	 */
	private function build_picture_element( $image_id, $target_formats, $art_direction ) {
		$sources = array();
		$file_path = get_attached_file( $image_id );
		$path_info = pathinfo( $file_path );
		$upload_dir = wp_upload_dir();

		foreach ( $target_formats as $format ) {
			$extension = $this->get_extension( $format );
			$format_file = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;

			if ( file_exists( $format_file ) ) {
				$url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $format_file );
				$mime_type = $this->get_mime_type( $format );

				$media_query = $art_direction ? ' media="(min-width: 768px)"' : '';
				$sources[] = sprintf(
					'<source srcset="%s" type="%s"%s>',
					esc_url( $url ),
					esc_attr( $mime_type ),
					$media_query
				);
			}
		}

		$src = wp_get_attachment_image_url( $image_id, 'full' );
		$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		$picture = '<picture>';
		$picture .= implode( "\n", $sources );
		$picture .= sprintf(
			'<img src="%s" alt="%s" loading="lazy" decoding="async">',
			esc_url( $src ),
			esc_attr( $alt )
		);
		$picture .= '</picture>';

		return $picture;
	}

	/**
	 * Get best format from results
	 *
	 * @since 1.0.0
	 * @param array $format_results Format conversion results.
	 * @return string Best format.
	 */
	private function get_best_format( $format_results ) {
		$smallest = null;
		$smallest_size = PHP_INT_MAX;

		foreach ( $format_results as $format => $result ) {
			$size = filesize( $result['new_file'] );
			if ( $size < $smallest_size ) {
				$smallest_size = $size;
				$smallest = $format;
			}
		}

		return $smallest;
	}

	/**
	 * Get file extension for format
	 *
	 * @since 1.0.0
	 * @param string $format Format name.
	 * @return string File extension.
	 */
	private function get_extension( $format ) {
		$extensions = array(
			'avif' => 'avif',
			'webp' => 'webp',
			'jxl'  => 'jxl',
		);

		return isset( $extensions[ $format ] ) ? $extensions[ $format ] : 'webp';
	}

	/**
	 * Get MIME type for format
	 *
	 * @since 1.0.0
	 * @param string $format Format name.
	 * @return string MIME type.
	 */
	private function get_mime_type( $format ) {
		$mime_types = array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
			'jxl'  => 'image/jxl',
		);

		return isset( $mime_types[ $format ] ) ? $mime_types[ $format ] : 'image/webp';
	}

	/**
	 * Check PHP support for format
	 *
	 * @since 1.0.0
	 * @param string $format Format name.
	 * @return bool True if supported.
	 */
	private function check_php_support( $format ) {
		switch ( $format ) {
			case 'avif':
				return function_exists( 'imageavif' );
			case 'webp':
				return function_exists( 'imagewebp' );
			case 'jxl':
				return false; // PHP doesn't have native JPEG XL support yet.
			default:
				return false;
		}
	}

	/**
	 * Check if format is recommended
	 *
	 * @since 1.0.0
	 * @param string $format Format name.
	 * @return bool True if recommended.
	 */
	private function is_format_recommended( $format ) {
		return in_array( $format, array( 'avif', 'webp' ), true );
	}

	/**
	 * Get conversion recommendations
	 *
	 * @since 1.0.0
	 * @param array $format_stats Format statistics.
	 * @param int   $converted    Converted count.
	 * @return array Recommendations.
	 */
	private function get_conversion_recommendations( $format_stats, $converted ) {
		$recommendations = array();

		if ( $converted > 0 ) {
			$recommendations[] = __( 'Successfully converted images to modern formats.', 'mcp-ai-wpoos' );
		}

		if ( isset( $format_stats['avif'] ) ) {
			$recommendations[] = sprintf(
				/* translators: %s: number of AVIF conversions */
				__( 'Generated %s AVIF images (best compression).', 'mcp-ai-wpoos' ),
				$format_stats['avif']['count']
			);
		}

		$recommendations[] = __( 'Use <picture> element for format fallback chains.', 'mcp-ai-wpoos' );
		$recommendations[] = __( 'Enable lazy loading for below-the-fold images.', 'mcp-ai-wpoos' );
		$recommendations[] = __( 'Monitor Core Web Vitals (LCP) after deployment.', 'mcp-ai-wpoos' );

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
