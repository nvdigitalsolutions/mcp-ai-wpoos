<?php
/**
 * WordPress-specific media library optimizer tool.
 *
 * Lives in the WordPress adapter because it uses wp_get_image_editor,
 * WP_Query, get_attached_file, update_post_meta, wp_update_post,
 * and the WP_MCP_AI_Batch_Iterator for memory-bounded scans.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;
use WP_MCP_AI_Batch_Iterator;

/**
 * Bulk image compression, format conversion, unused media detection,
 * and lazy loading configuration following modern standards.
 */
class MediaLibraryOptimizerTool extends AbstractTool {

	public function getSlug(): string {
		return 'media_library_optimizer';
	}

	public function getName(): string {
		return 'Media Library Optimizer';
	}

	public function getDescription(): string {
		return 'Bulk image compression, AVIF/WebP conversion, lazy loading, unused media detection, and CDN preparation following modern standards.';
	}

	public function getRequiredCapability(): string {
		return 'upload_files';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'            => array(
					'type'        => 'string',
					'description' => 'Action: analyze, compress, convert, detect_unused, or configure_lazy_loading.',
					'enum'        => array( 'analyze', 'compress', 'convert', 'detect_unused', 'configure_lazy_loading' ),
				),
				'target_format'     => array(
					'type'        => 'string',
					'description' => 'Target format: avif, webp, or auto.',
					'default'     => 'auto',
					'enum'        => array( 'avif', 'webp', 'auto' ),
				),
				'quality'           => array(
					'type'        => 'integer',
					'description' => 'Compression quality (1-100).',
					'default'     => 85,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => 'Images to process.',
					'default'     => 50,
				),
				'max_items'         => array(
					'type'        => 'integer',
					'description' => 'Max total images to scan during analyze/detect_unused.',
					'default'     => 500,
				),
				'age_days'          => array(
					'type'        => 'integer',
					'description' => 'Age in days for unused media detection.',
					'default'     => 180,
				),
				'preserve_original' => array(
					'type'        => 'boolean',
					'description' => 'Keep original files when converting.',
					'default'     => true,
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action            = \sanitize_text_field( $arguments['action'] ?? 'analyze' );
		$target_format     = \sanitize_text_field( $arguments['target_format'] ?? 'auto' );
		$quality           = \max( 1, \min( 100, \absint( $arguments['quality'] ?? 85 ) ) );
		$limit             = \absint( $arguments['limit'] ?? 50 );
		$age_days          = \absint( $arguments['age_days'] ?? 180 );
		$preserve_original = (bool) ( $arguments['preserve_original'] ?? true );
		$max_items         = \absint( $arguments['max_items'] ?? 500 );

		switch ( $action ) {
			case 'analyze':
				return $this->handleAnalyze( $max_items );
			case 'compress':
				return $this->handleCompress( $quality, $limit );
			case 'convert':
				return $this->handleConvert( $target_format, $quality, $limit, $preserve_original );
			case 'detect_unused':
				return $this->handleDetectUnused( $age_days, $max_items );
			case 'configure_lazy_loading':
				return $this->handleLazyLoading();
			default:
				return $this->errors->validationFailed( 'Invalid action.', array( 'action' => array( 'Invalid value.' ) ) );
		}
	}

	// ─── Action handlers ───────────────────────────────────────────────

	private function handleAnalyze( int $max_items ): array {
		$total_images  = 0;
		$total_size    = 0;
		$format_counts = array( 'jpeg' => 0, 'png' => 0, 'gif' => 0, 'webp' => 0, 'avif' => 0, 'other' => 0 );
		$opportunities = array();

		$iterator = new WP_MCP_AI_Batch_Iterator( 'media_library_optimizer_analyze', array( 'max_items' => $max_items ) );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
		);

		foreach ( $iterator->paged_iterate( $query_args ) as $batch ) {
			foreach ( $batch as $image_id ) {
				$file_path = \get_attached_file( $image_id );
				if ( ! $file_path || ! \file_exists( $file_path ) ) {
					continue;
				}

				++$total_images;
				$file_size   = \filesize( $file_path );
				$total_size += $file_size;

				$mime   = \get_post_mime_type( $image_id );
				$format = $this->mimeToFormat( $mime );
				if ( isset( $format_counts[ $format ] ) ) {
					++$format_counts[ $format ];
				} else {
					++$format_counts['other'];
				}

				if ( $file_size > 500000 ) {
					$opportunities[] = array(
						'id'          => $image_id,
						'file'        => \basename( $file_path ),
						'size'        => $file_size,
						'size_human'  => \size_format( $file_size ),
						'format'      => $format,
						'reason'      => 'Large file size',
						'recommended' => 'png' === $format ? 'avif' : 'webp',
					);
				}
			}
		}

		$iterator->complete();

		return $this->success(
			'Analysis complete.',
			array(
				'summary'                     => array(
					'total_images'       => $total_images,
					'total_size_human'   => \size_format( $total_size ),
					'average_size_human' => \size_format( $total_images > 0 ? \round( $total_size / $total_images ) : 0 ),
					'scanned_cap'        => $max_items,
				),
				'format_distribution'         => $format_counts,
				'total_opportunities'         => \count( $opportunities ),
				'optimization_opportunities'  => \array_slice( $opportunities, 0, 20 ),
				'estimated_savings_potential' => $this->estimateSavings( $total_size, $format_counts ),
				'recommendations'             => $this->generateRecommendations( $format_counts, $total_images ),
			)
		);
	}

	private function handleCompress( int $quality, int $limit ): array {
		$images = \get_posts( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'posts_per_page' => $limit,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_wp_mcp_ai_compressed', 'compare' => 'NOT EXISTS' ),
			),
		) );

		$compressed  = 0;
		$failed      = 0;
		$total_saved = 0;
		$details     = array();

		foreach ( $images as $image_id ) {
			$file_path = \get_attached_file( $image_id );
			if ( ! \file_exists( $file_path ) ) {
				++$failed;
				continue;
			}

			$original_size = \filesize( $file_path );
			$result        = $this->compressImage( $file_path, $quality );

			if ( $result['success'] ) {
				$new_size     = \filesize( $file_path );
				$saved        = $original_size - $new_size;
				$total_saved += $saved;

				\update_post_meta( $image_id, '_wp_mcp_ai_compressed', true );
				\update_post_meta( $image_id, '_wp_mcp_ai_compression_quality', $quality );

				$details[] = array(
					'id'            => $image_id,
					'file'          => \basename( $file_path ),
					'original_size' => \size_format( $original_size ),
					'new_size'      => \size_format( $new_size ),
					'saved'         => \size_format( $saved ),
					'reduction'     => \round( ( $saved / $original_size ) * 100, 2 ) . '%',
				);
				++$compressed;
			} else {
				++$failed;
			}
		}

		return $this->success(
			'Compression complete.',
			array(
				'processed'   => $compressed + $failed,
				'compressed'  => $compressed,
				'failed'      => $failed,
				'total_saved' => \size_format( $total_saved ),
				'quality'     => $quality,
				'details'     => $details,
			)
		);
	}

	private function handleConvert( string $target_format, int $quality, int $limit, bool $preserve_original ): array {
		if ( 'auto' === $target_format ) {
			$target_format = \wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ? 'avif' : 'webp';
		}

		$images = \get_posts( array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'posts_per_page' => $limit,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
		) );

		$converted   = 0;
		$failed      = 0;
		$total_saved = 0;
		$details     = array();

		foreach ( $images as $image_id ) {
			$file_path = \get_attached_file( $image_id );
			if ( ! \file_exists( $file_path ) ) {
				++$failed;
				continue;
			}

			$original_size = \filesize( $file_path );
			$convert_result = $this->convertImageFormat( $file_path, $target_format, $quality, $preserve_original );

			if ( $convert_result['success'] ) {
				$new_file     = $convert_result['new_file'];
				$new_size     = \filesize( $new_file );
				$saved        = $original_size - $new_size;
				$total_saved += $saved;

				\update_attached_file( $image_id, $new_file );
				\wp_update_post( array(
					'ID'             => $image_id,
					'post_mime_type' => $convert_result['mime_type'],
				) );

				$details[] = array(
					'id'            => $image_id,
					'original_file' => \basename( $file_path ),
					'new_file'      => \basename( $new_file ),
					'format'        => $target_format,
					'original_size' => \size_format( $original_size ),
					'new_size'      => \size_format( $new_size ),
					'saved'         => \size_format( $saved ),
					'reduction'     => \round( ( $saved / $original_size ) * 100, 2 ) . '%',
				);
				++$converted;
			} else {
				++$failed;
			}
		}

		return $this->success(
			'Conversion complete.',
			array(
				'processed'         => $converted + $failed,
				'converted'         => $converted,
				'failed'            => $failed,
				'target_format'     => $target_format,
				'quality'           => $quality,
				'preserve_original' => $preserve_original,
				'total_saved'       => \size_format( $total_saved ),
				'details'           => $details,
			)
		);
	}

	private function handleDetectUnused( int $age_days, int $max_items ): array {
		global $wpdb;

		$cutoff_date = \gmdate( 'Y-m-d H:i:s', \strtotime( "-{$age_days} days" ) );

		$iterator = new WP_MCP_AI_Batch_Iterator( 'media_library_optimizer_detect_unused', array( 'max_items' => $max_items ) );

		$query_args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'fields'      => 'ids',
			'date_query'  => array( array( 'before' => $cutoff_date ) ),
		);

		$unused     = array();
		$total_size = 0;
		$checked    = 0;

		foreach ( $iterator->paged_iterate( $query_args ) as $batch ) {
			foreach ( $batch as $attachment_id ) {
				++$checked;
				$parent_id = \wp_get_post_parent_id( $attachment_id );
				$url       = \wp_get_attachment_url( $attachment_id );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$used_count = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish'",
					'%' . $wpdb->esc_like( $url ) . '%'
				) );

				if ( 0 === $parent_id && 0 === (int) $used_count ) {
					$file_path = \get_attached_file( $attachment_id );
					$file_size = $file_path && \file_exists( $file_path ) ? \filesize( $file_path ) : 0;
					$total_size += $file_size;

					$unused[] = array(
						'id'            => $attachment_id,
						'title'         => \get_the_title( $attachment_id ),
						'url'           => $url,
						'file'          => $file_path ? \basename( $file_path ) : '',
						'size'          => \size_format( $file_size ),
						'uploaded_date' => \get_the_date( 'Y-m-d', $attachment_id ),
						'age_days'      => \floor( ( \time() - \get_post_time( 'U', false, $attachment_id ) ) / \DAY_IN_SECONDS ),
					);
				}
			}
		}

		$iterator->complete();

		return $this->success(
			'Unused detection complete.',
			array(
				'age_threshold_days' => $age_days,
				'total_checked'      => $checked,
				'unused_count'       => \count( $unused ),
				'unused_total_size'  => \size_format( $total_size ),
				'potential_savings'  => \size_format( $total_size ),
				'unused_media'       => \array_slice( $unused, 0, 100 ),
			)
		);
	}

	private function handleLazyLoading(): array {
		$native = \version_compare( \get_bloginfo( 'version' ), '5.5', '>=' );

		return $this->success(
			$native ? 'WordPress native lazy loading is enabled.' : 'Consider upgrading for native lazy loading.',
			array(
				'wordpress_native' => $native,
				'recommendations'  => array(
					'WordPress 5.5+ includes native lazy loading.',
					'Add loading="lazy" attribute to images automatically.',
					'Test Core Web Vitals after enabling.',
				),
			)
		);
	}

	// ─── Image processing helpers ──────────────────────────────────────

	private function compressImage( string $file_path, int $quality ): array {
		$editor = \wp_get_image_editor( $file_path );
		if ( \is_wp_error( $editor ) ) {
			return array( 'success' => false );
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $file_path );

		return array( 'success' => ! \is_wp_error( $saved ) );
	}

	private function convertImageFormat( string $file_path, string $target_format, int $quality, bool $preserve_original ): array {
		$editor = \wp_get_image_editor( $file_path );
		if ( \is_wp_error( $editor ) ) {
			return array( 'success' => false );
		}

		$editor->set_quality( $quality );

		$path_info = \pathinfo( $file_path );
		$extension = 'avif' === $target_format ? 'avif' : 'webp';
		$new_file  = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;

		$mime_types = array( 'avif' => 'image/avif', 'webp' => 'image/webp' );
		$mime_type  = $mime_types[ $target_format ] ?? 'image/webp';

		$saved = $editor->save( $new_file, $mime_type );
		if ( \is_wp_error( $saved ) ) {
			return array( 'success' => false );
		}

		if ( ! $preserve_original && \file_exists( $file_path ) ) {
			\wp_delete_file( $file_path );
		}

		return array( 'success' => true, 'new_file' => $new_file, 'mime_type' => $mime_type );
	}

	private function mimeToFormat( string $mime_type ): string {
		return array(
			'image/jpeg' => 'jpeg',
			'image/jpg'  => 'jpeg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		)[ $mime_type ] ?? 'other';
	}

	private function estimateSavings( int $total_size, array $format_counts ): array {
		$total = \array_sum( $format_counts );
		if ( 0 === $total ) {
			return array( 'avif_potential' => '0 B', 'webp_potential' => '0 B' );
		}

		$jpeg = $format_counts['jpeg'];
		$png  = $format_counts['png'];
		$avg  = $total_size / $total;

		return array(
			'avif_potential' => \size_format( ( $jpeg * 0.50 + $png * 0.85 ) * $avg ),
			'webp_potential' => \size_format( ( $jpeg * 0.30 + $png * 0.80 ) * $avg ),
		);
	}

	private function generateRecommendations( array $format_counts, int $total_images ): array {
		$recs = array();

		if ( $format_counts['png'] > $total_images * 0.3 ) {
			$recs[] = 'High PNG usage detected. Consider converting to AVIF for 85% size reduction.';
		}
		if ( $format_counts['jpeg'] > $total_images * 0.5 ) {
			$recs[] = 'Many JPEG images detected. WebP conversion can save ~30% file size.';
		}
		if ( $format_counts['gif'] > 0 ) {
			$recs[] = 'GIF images detected. Consider converting to video formats for animations.';
		}
		if ( 0 === $format_counts['avif'] && 0 === $format_counts['webp'] ) {
			$recs[] = 'No modern formats detected. AVIF/WebP conversion highly recommended.';
		}

		$recs[] = 'Enable lazy loading for below-the-fold images.';
		$recs[] = 'Consider CDN for image delivery.';
		$recs[] = 'Regularly audit and remove unused media files.';

		return $recs;
	}
}
