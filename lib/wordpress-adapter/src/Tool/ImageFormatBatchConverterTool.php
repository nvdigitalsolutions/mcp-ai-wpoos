<?php
/**
 * WordPress-specific tool for batch image format conversion.
 *
 * Lives in the WordPress adapter because it uses wp_get_image_editor,
 * get_attached_file, WP_Query, update_post_meta, and WordPress upload paths.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Batch converts images to AVIF/WebP/JPEG XL with responsive srcset generation,
 * modern format fallback chains, and Art Direction support.
 */
class ImageFormatBatchConverterTool extends AbstractTool {

	public function getSlug(): string {
		return 'image_format_batch_converter';
	}

	public function getName(): string {
		return 'Image Format Batch Converter';
	}

	public function getDescription(): string {
		return 'Batch convert images to AVIF/WebP/JPEG XL with responsive srcset generation, automatic fallback chains, and Art Direction support for modern image standards.';
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
					'description' => 'Action: convert_batch, generate_srcset, create_picture_element, or validate_support.',
					'enum'        => array( 'convert_batch', 'generate_srcset', 'create_picture_element', 'validate_support' ),
				),
				'target_formats'    => array(
					'type'        => 'array',
					'description' => 'Target formats in priority order: avif, webp, jxl.',
					'default'     => array( 'avif', 'webp' ),
					'items'       => array( 'type' => 'string', 'enum' => array( 'avif', 'webp', 'jxl' ) ),
				),
				'quality'           => array(
					'type'        => 'integer',
					'description' => 'Conversion quality (1-100).',
					'default'     => 85,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'image_ids'         => array(
					'type'        => 'array',
					'description' => 'Specific image IDs to convert.',
					'items'       => array( 'type' => 'integer' ),
				),
				'generate_sizes'    => array(
					'type'        => 'array',
					'description' => 'Responsive sizes to generate (widths in px).',
					'default'     => array( 320, 640, 768, 1024, 1280, 1920, 2560 ),
					'items'       => array( 'type' => 'integer' ),
				),
				'art_direction'     => array(
					'type'        => 'boolean',
					'description' => 'Enable Art Direction (different crops for mobile/desktop).',
					'default'     => false,
				),
				'preserve_original' => array(
					'type'        => 'boolean',
					'description' => 'Keep original files when converting.',
					'default'     => true,
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => 'Images per batch.',
					'default'     => 25,
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$action            = \sanitize_text_field( $arguments['action'] ?? 'convert_batch' );
		$target_formats    = isset( $arguments['target_formats'] ) && \is_array( $arguments['target_formats'] )
			? \array_map( 'sanitize_text_field', $arguments['target_formats'] )
			: array( 'avif', 'webp' );
		$quality           = \max( 1, \min( 100, \absint( $arguments['quality'] ?? 85 ) ) );
		$image_ids         = isset( $arguments['image_ids'] ) && \is_array( $arguments['image_ids'] )
			? \array_map( 'absint', $arguments['image_ids'] )
			: array();
		$generate_sizes    = isset( $arguments['generate_sizes'] ) && \is_array( $arguments['generate_sizes'] )
			? \array_map( 'absint', $arguments['generate_sizes'] )
			: array( 320, 640, 768, 1024, 1280, 1920, 2560 );
		$art_direction     = (bool) ( $arguments['art_direction'] ?? false );
		$preserve_original = (bool) ( $arguments['preserve_original'] ?? true );
		$limit             = \absint( $arguments['limit'] ?? 25 );

		switch ( $action ) {
			case 'convert_batch':
				return $this->handleConvertBatch( $target_formats, $quality, $image_ids, $generate_sizes, $preserve_original, $limit );
			case 'generate_srcset':
				return $this->handleGenerateSrcset( $image_ids, $generate_sizes );
			case 'create_picture_element':
				return $this->handleCreatePictureElement( $image_ids, $target_formats, $art_direction );
			case 'validate_support':
				return $this->handleValidateSupport();
			default:
				return $this->errors->validationFailed( 'Invalid action specified.', array( 'action' => array( 'Invalid value.' ) ) );
		}
	}

	private function handleConvertBatch(
		array $target_formats,
		int $quality,
		array $image_ids,
		array $generate_sizes,
		bool $preserve_original,
		int $limit
	): mixed {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
			'posts_per_page' => $limit,
			'post_status'    => 'inherit',
			'fields'         => 'ids',
		);

		if ( ! empty( $image_ids ) ) {
			$query_args['post__in']       = \array_slice( $image_ids, 0, 500 );
			$query_args['posts_per_page'] = \count( $query_args['post__in'] );
		}

		$images = \get_posts( $query_args );

		$converted    = 0;
		$failed       = 0;
		$total_saved  = 0;
		$details      = array();
		$format_stats = array();

		foreach ( $images as $image_id ) {
			$file_path = \get_attached_file( $image_id );
			if ( ! \file_exists( $file_path ) ) {
				++$failed;
				continue;
			}

			$original_size  = \filesize( $file_path );
			$format_results = array();

			foreach ( $target_formats as $format ) {
				$convert_result = $this->convertToFormat( $file_path, $format, $quality );

				if ( $convert_result['success'] ) {
					$format_results[ $format ] = $convert_result;

					if ( ! empty( $generate_sizes ) ) {
						$this->generateResponsiveSizes( $convert_result['new_file'], $generate_sizes, $quality );
					}

					if ( ! isset( $format_stats[ $format ] ) ) {
						$format_stats[ $format ] = array( 'count' => 0, 'total_saved' => 0 );
					}
					++$format_stats[ $format ]['count'];
					$format_stats[ $format ]['total_saved'] += ( $original_size - \filesize( $convert_result['new_file'] ) );
				}
			}

			if ( ! empty( $format_results ) ) {
				$best = $this->getBestFormat( $format_results );
				$saved = $original_size - \filesize( $format_results[ $best ]['new_file'] );
				$total_saved += $saved;

				\update_post_meta( $image_id, '_wp_mcp_ai_converted_formats', \array_keys( $format_results ) );
				\update_post_meta( $image_id, '_wp_mcp_ai_best_format', $best );

				$details[] = array(
					'id'               => $image_id,
					'original_file'    => \basename( $file_path ),
					'formats'          => \array_keys( $format_results ),
					'best_format'      => $best,
					'original_size'    => \size_format( $original_size ),
					'best_format_size' => \size_format( \filesize( $format_results[ $best ]['new_file'] ) ),
					'saved'            => \size_format( $saved ),
					'reduction'        => \round( ( $saved / $original_size ) * 100, 2 ) . '%',
					'srcset_generated' => ! empty( $generate_sizes ),
				);
				++$converted;
			} else {
				++$failed;
			}
		}

		return $this->success(
			'Batch conversion complete.',
			array(
				'processed'       => $converted + $failed,
				'converted'       => $converted,
				'failed'          => $failed,
				'target_formats'  => $target_formats,
				'quality'         => $quality,
				'total_saved'     => \size_format( $total_saved ),
				'format_stats'    => $format_stats,
				'details'         => $details,
			)
		);
	}

	private function handleGenerateSrcset( array $image_ids, array $generate_sizes ): mixed {
		if ( empty( $image_ids ) ) {
			return $this->errors->validationFailed( 'No image IDs provided.', array( 'image_ids' => array( 'Required.' ) ) );
		}

		$results = array();
		foreach ( $image_ids as $image_id ) {
			$file_path = \get_attached_file( $image_id );
			if ( ! \file_exists( $file_path ) ) {
				continue;
			}

			$srcset     = $this->buildSrcset( $image_id, $generate_sizes );
			$results[]  = array(
				'id'     => $image_id,
				'srcset' => $srcset,
				'sizes'  => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
				'html'   => $this->buildResponsiveImageHtml( $image_id, $srcset ),
			);
		}

		return $this->success( 'Srcset generated.', array( 'count' => \count( $results ), 'results' => $results ) );
	}

	private function handleCreatePictureElement( array $image_ids, array $target_formats, bool $art_direction ): mixed {
		if ( empty( $image_ids ) ) {
			return $this->errors->validationFailed( 'No image IDs provided.', array( 'image_ids' => array( 'Required.' ) ) );
		}

		$results = array();
		foreach ( $image_ids as $image_id ) {
			$results[] = array(
				'id'            => $image_id,
				'picture_html'  => $this->buildPictureElement( $image_id, $target_formats, $art_direction ),
				'formats'       => $target_formats,
				'art_direction' => $art_direction,
			);
		}

		return $this->success(
			'Picture elements created.',
			array(
				'count'          => \count( $results ),
				'results'        => $results,
			)
		);
	}

	private function handleValidateSupport(): array {
		$formats = array( 'avif', 'webp', 'jxl' );
		$support = array();

		foreach ( $formats as $format ) {
			$mime             = $this->getMimeType( $format );
			$support[ $format ] = array(
				'mime_type'        => $mime,
				'wordpress_editor' => \wp_image_editor_supports( array( 'mime_type' => $mime ) ),
				'php_support'      => $this->checkPhpSupport( $format ),
				'recommended'      => \in_array( $format, array( 'avif', 'webp' ), true ),
			);
		}

		return $this->success(
			'Format support validated.',
			array(
				'format_support'  => $support,
				'best_practice'   => array(
					'format_chain' => array( 'avif', 'webp', 'jpeg' ),
				),
			)
		);
	}

	// ─── Image processing helpers ──────────────────────────────────────

	private function convertToFormat( string $file_path, string $format, int $quality ): array {
		$image_editor = \wp_get_image_editor( $file_path );
		if ( \is_wp_error( $image_editor ) ) {
			return array( 'success' => false );
		}

		$image_editor->set_quality( $quality );

		$path_info = \pathinfo( $file_path );
		$extension = $this->getExtension( $format );
		$new_file  = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;
		$mime_type = $this->getMimeType( $format );

		$saved = $image_editor->save( $new_file, $mime_type );
		if ( \is_wp_error( $saved ) ) {
			return array( 'success' => false );
		}

		return array( 'success' => true, 'new_file' => $new_file, 'mime_type' => $mime_type, 'format' => $format );
	}

	private function generateResponsiveSizes( string $file_path, array $sizes, int $quality ): array {
		$generated = array();
		$path_info = \pathinfo( $file_path );

		foreach ( $sizes as $width ) {
			$editor = \wp_get_image_editor( $file_path );
			if ( \is_wp_error( $editor ) ) {
				continue;
			}

			$editor->resize( $width, null, false );
			$editor->set_quality( $quality );

			$new_file = $path_info['dirname'] . '/' . $path_info['filename'] . '-' . $width . 'w.' . $path_info['extension'];
			$saved    = $editor->save( $new_file );

			if ( ! \is_wp_error( $saved ) ) {
				$generated[] = array( 'width' => $width, 'file' => $new_file );
			}
		}

		return $generated;
	}

	private function buildSrcset( int $image_id, array $sizes ): string {
		$file_path    = \get_attached_file( $image_id );
		$path_info    = \pathinfo( $file_path );
		$upload_dir   = \wp_upload_dir();
		$srcset_parts = array();

		foreach ( $sizes as $width ) {
			$filename = $path_info['filename'] . '-' . $width . 'w.' . $path_info['extension'];
			$file     = $path_info['dirname'] . '/' . $filename;

			if ( \file_exists( $file ) ) {
				$url            = \str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file );
				$srcset_parts[] = \esc_url( $url ) . ' ' . $width . 'w';
			}
		}

		return \implode( ', ', $srcset_parts );
	}

	private function buildResponsiveImageHtml( int $image_id, string $srcset ): string {
		$src = \wp_get_attachment_image_url( $image_id, 'full' );
		$alt = \get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		return \sprintf(
			'<img src="%s" srcset="%s" sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw" alt="%s" loading="lazy" decoding="async">',
			\esc_url( $src ),
			\esc_attr( $srcset ),
			\esc_attr( $alt )
		);
	}

	private function buildPictureElement( int $image_id, array $target_formats, bool $art_direction ): string {
		$file_path  = \get_attached_file( $image_id );
		$path_info  = \pathinfo( $file_path );
		$upload_dir = \wp_upload_dir();
		$sources    = array();

		foreach ( $target_formats as $format ) {
			$extension   = $this->getExtension( $format );
			$format_file = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $extension;

			if ( \file_exists( $format_file ) ) {
				$url         = \str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $format_file );
				$mime        = $this->getMimeType( $format );
				$media_query = $art_direction ? ' media="(min-width: 768px)"' : '';
				$sources[]   = \sprintf( '<source srcset="%s" type="%s"%s>', \esc_url( $url ), \esc_attr( $mime ), $media_query );
			}
		}

		$src = \wp_get_attachment_image_url( $image_id, 'full' );
		$alt = \get_post_meta( $image_id, '_wp_attachment_image_alt', true );

		return '<picture>' . \implode( "\n", $sources )
			. \sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', \esc_url( $src ), \esc_attr( $alt ) )
			. '</picture>';
	}

	private function getBestFormat( array $format_results ): string {
		$smallest      = '';
		$smallest_size = \PHP_INT_MAX;

		foreach ( $format_results as $format => $result ) {
			$size = \filesize( $result['new_file'] );
			if ( $size < $smallest_size ) {
				$smallest_size = $size;
				$smallest      = $format;
			}
		}

		return $smallest;
	}

	private function getExtension( string $format ): string {
		return array(
			'avif' => 'avif',
			'webp' => 'webp',
			'jxl'  => 'jxl',
		)[ $format ] ?? 'webp';
	}

	private function getMimeType( string $format ): string {
		return array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
			'jxl'  => 'image/jxl',
		)[ $format ] ?? 'image/webp';
	}

	private function checkPhpSupport( string $format ): bool {
		return match ( $format ) {
			'avif' => \function_exists( 'imageavif' ),
			'webp' => \function_exists( 'imagewebp' ),
			'jxl'  => false,
			default => false,
		};
	}
}
