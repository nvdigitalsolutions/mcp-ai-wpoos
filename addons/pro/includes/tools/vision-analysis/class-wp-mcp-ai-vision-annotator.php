<?php
/**
 * Vision Analysis — Annotator (Phase 2)
 *
 * Draws labeled bounding boxes onto a copy of the source image using the GD
 * extension (no external font dependency — built-in font 5). Produces a
 * temporary file that the tool uploads to the media library as the
 * "annotated" copy of the analysis.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GD-based bounding-box annotation.
 *
 * @since 1.1.68
 */
class WP_MCP_AI_Vision_Annotator {

	/**
	 * Maximum total boxes drawn per annotation.
	 *
	 * @var int
	 */
	const MAX_TOTAL_BOXES = 200;

	/**
	 * Annotate an image with labeled bounding boxes.
	 *
	 * @param string $source_path Path to the source image.
	 * @param array  $breakdown   Canonical count breakdown (entries carry optional `boxes`).
	 * @param array  $opts        Optional overrides (unused placeholder for future style options).
	 * @return array{path: string, mime_type: string}|WP_Error
	 */
	public static function annotate( $source_path, array $breakdown, array $opts = array() ) {
		unset( $opts ); // Reserved for future style options (line width, palette).

		if ( ! self::gd_available() ) {
			return new WP_Error(
				'wp_mcp_ai_va_gd_missing',
				__( 'Bounding-box annotation requires the PHP GD extension, which is not available on this server.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_source_unreadable',
				__( 'The source image could not be read for annotation.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$mime = wp_check_filetype( $source_path );
		$mime = isset( $mime['type'] ) ? $mime['type'] : '';
		if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_source_not_image',
				__( 'The source file is not a supported image.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 415 )
			);
		}

		$source = self::load_gd_image( $source_path, $mime );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );

		// Always composite onto a white canvas so alpha/transparency in the
		// source does not render as black when saving lossy output.
		$canvas = imagecreatetruecolor( $width, $height );
		if ( false === $canvas ) {
			imagedestroy( $source );
			return new WP_Error( 'wp_mcp_ai_va_canvas_error', __( 'Failed to create the annotation canvas.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$white = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $white );
		imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );
		imagedestroy( $source );

		$drawn = 0;
		foreach ( $breakdown as $entry ) {
			if ( $drawn >= self::MAX_TOTAL_BOXES ) {
				break;
			}

			$label = isset( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '';
			$boxes = isset( $entry['boxes'] ) && is_array( $entry['boxes'] ) ? $entry['boxes'] : array();
			if ( '' === $label || empty( $boxes ) ) {
				continue;
			}

			list( $r, $g, $b ) = self::label_color( $label );
			$line_color        = imagecolorallocate( $canvas, $r, $g, $b );
			$label_background  = imagecolorallocate( $canvas, $r, $g, $b );
			$text_color        = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) > 150
				? imagecolorallocate( $canvas, 0, 0, 0 )
				: imagecolorallocate( $canvas, 255, 255, 255 );

			foreach ( $boxes as $box ) {
				if ( $drawn >= self::MAX_TOTAL_BOXES ) {
					break;
				}

				$pixels = self::box_to_pixels( $box, $width, $height );
				if ( null === $pixels ) {
					continue;
				}

				list( $x1, $y1, $x2, $y2 ) = $pixels;

				// 3-px thick border (GD has no line width).
				for ( $offset = 0; $offset < 3; $offset++ ) {
					imagerectangle( $canvas, $x1 - $offset, $y1 - $offset, $x2 + $offset, $y2 + $offset, $line_color );
				}

				// Label chip above the box (clamped to the canvas).
				$label_text = (string) $label;
				$chip_w     = ( imagefontwidth( 5 ) * strlen( $label_text ) ) + 6;
				$chip_h     = imagefontheight( 5 ) + 4;
				$chip_x     = max( 0, $x1 );
				$chip_y     = max( 0, $y1 - $chip_h );

				if ( $chip_x + $chip_w > $width ) {
					$chip_x = max( 0, $width - $chip_w );
				}

				imagefilledrectangle( $canvas, $chip_x, $chip_y, $chip_x + $chip_w, $chip_y + $chip_h, $label_background );
				imagestring( $canvas, 5, $chip_x + 3, $chip_y + 2, $label_text, $text_color );

				++$drawn;
			}
		}

		// Save as PNG for transparency fidelity; JPEG for opaque sources to
		// keep annotated files small.
		$out_mime = ( 'image/png' === $mime || 'image/webp' === $mime ) ? 'image/png' : 'image/jpeg';
		$out_path = wp_tempnam( 'va-annotated-' );

		if ( ! $out_path ) {
			imagedestroy( $canvas );
			return new WP_Error( 'wp_mcp_ai_va_temp_error', __( 'Failed to create the annotated output file.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		$out_path_with_ext = $out_path . ( 'image/png' === $out_mime ? '.png' : '.jpg' );
		rename( $out_path, $out_path_with_ext ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		$out_path = $out_path_with_ext;

		if ( 'image/png' === $out_mime ) {
			$saved = imagepng( $canvas, $out_path, 6 );
		} else {
			$saved = imagejpeg( $canvas, $out_path, 90 );
		}
		imagedestroy( $canvas );

		if ( ! $saved || ! file_exists( $out_path ) ) {
			wp_delete_file( $out_path );
			return new WP_Error( 'wp_mcp_ai_va_save_error', __( 'Failed to save the annotated image.', 'mcp-ai-wpoos-pro' ), array( 'status' => 500 ) );
		}

		return array(
			'path'      => $out_path,
			'mime_type' => $out_mime,
		);
	}

	/**
	 * Check whether GD with the required functions is available.
	 *
	 * @return bool
	 */
	private static function gd_available() {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagecreatefromjpeg' )
			&& function_exists( 'imagecreatefrompng' );
	}

	/**
	 * Load a GD image resource from a file path.
	 *
	 * @param string $path File path.
	 * @param string $mime Detected MIME type.
	 * @return resource|WP_Error GD image resource or error.
	 */
	private static function load_gd_image( $path, $mime ) {
		$image = null;

		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/jpg':
				$image = imagecreatefromjpeg( $path );
				break;
			case 'image/png':
				$image = imagecreatefrompng( $path );
				break;
			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = imagecreatefromwebp( $path );
				}
				break;
			case 'image/gif':
				if ( function_exists( 'imagecreatefromgif' ) ) {
					$image = imagecreatefromgif( $path );
				}
				break;
		}

		if ( false === $image || null === $image ) {
			return new WP_Error(
				'wp_mcp_ai_va_decode_error',
				__( 'The source image could not be decoded for annotation.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 415 )
			);
		}

		return $image;
	}

	/**
	 * Convert a normalized (0–1) box to pixel coordinates.
	 *
	 * Absolute pixel boxes (>1) are treated as pixels. Degenerate boxes
	 * (zero area) are skipped by returning null.
	 *
	 * @param array $box    Canonical box shape.
	 * @param int   $width  Canvas width in pixels.
	 * @param int   $height Canvas height in pixels.
	 * @return array{0: int, 1: int, 2: int, 3: int}|null
	 */
	private static function box_to_pixels( array $box, $width, $height ) {
		$x = isset( $box['x'] ) ? (float) $box['x'] : 0.0;
		$y = isset( $box['y'] ) ? (float) $box['y'] : 0.0;
		$w = isset( $box['width'] ) ? (float) $box['width'] : 0.0;
		$h = isset( $box['height'] ) ? (float) $box['height'] : 0.0;

		// Normalized space (0–1) → pixels.
		if ( $x <= 1.0 && $y <= 1.0 && $w <= 1.0 && $h <= 1.0 && ( $x > 0.0 || $w > 0.0 ) ) {
			$x = $x * $width;
			$y = $y * $height;
			$w = $w * $width;
			$h = $h * $height;
		}

		if ( $w < 2 || $h < 2 ) {
			return null;
		}

		$x1 = (int) max( 0, min( $width - 1, floor( $x ) ) );
		$y1 = (int) max( 0, min( $height - 1, floor( $y ) ) );
		$x2 = (int) max( 0, min( $width - 1, floor( $x + $w ) ) );
		$y2 = (int) max( 0, min( $height - 1, floor( $y + $h ) ) );

		if ( $x2 <= $x1 || $y2 <= $y1 ) {
			return null;
		}

		return array( $x1, $y1, $x2, $y2 );
	}

	/**
	 * Deterministic per-label color (HSV hue → RGB).
	 *
	 * @param string $label Object label.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function label_color( $label ) {
		$hash = crc32( strtolower( $label ) );
		$hue  = $hash % 360;
		if ( $hue < 0 ) {
			$hue += 360;
		}

		$s = 0.75;
		$v = 0.90;

		$c = $v * $s;
		$x = $c * ( 1 - abs( fmod( $hue / 60.0, 2 ) - 1 ) );
		$m = $v - $c;

		if ( $hue < 60 ) {
			list( $r, $g, $b ) = array( $c, $x, 0.0 );
		} elseif ( $hue < 120 ) {
			list( $r, $g, $b ) = array( $x, $c, 0.0 );
		} elseif ( $hue < 180 ) {
			list( $r, $g, $b ) = array( 0.0, $c, $x );
		} elseif ( $hue < 240 ) {
			list( $r, $g, $b ) = array( 0.0, $x, $c );
		} elseif ( $hue < 300 ) {
			list( $r, $g, $b ) = array( $x, 0.0, $c );
		} else {
			list( $r, $g, $b ) = array( $c, 0.0, $x );
		}

		return array(
			(int) round( ( $r + $m ) * 255 ),
			(int) round( ( $g + $m ) * 255 ),
			(int) round( ( $b + $m ) * 255 ),
		);
	}
}
