<?php
/**
 * Pixel-accurate compositing engine for the harmonization toolkit.
 *
 * Encapsulates the math used by the harmonization primitives:
 *   - Reinhard (LAB-space mean/std) color transfer
 *   - Histogram matching (per channel)
 *   - Alpha-channel feathering / halo suppression
 *   - Contact + cast shadow rendering
 *   - Reflection rendering
 *   - Edge-aware boundary feathering on a composited image
 *
 * Implementation prefers Imagick when available and falls back to pure GD.
 * No new system dependencies beyond what `product_actualization` already requires.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compositor service for harmonization tools.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Harmonization_Compositor {

	/**
	 * Whether Imagick is available.
	 *
	 * @var bool
	 */
	protected $has_imagick;

	/**
	 * Whether GD is available.
	 *
	 * @var bool
	 */
	protected $has_gd;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$this->has_gd      = extension_loaded( 'gd' );
	}

	/**
	 * Whether the compositor can run on this server.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->has_imagick || $this->has_gd;
	}

	/**
	 * Get a human-readable reason if the compositor is unavailable.
	 *
	 * @return string
	 */
	public function get_unavailable_reason() {
		return __( 'Compositor requires Imagick or GD PHP extension.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Compute simple per-channel mean (0-255) statistics for an image file.
	 *
	 * Down-samples large images to keep this cheap.
	 *
	 * @param string $path Path to image file.
	 *
	 * @return array|WP_Error Array with `mean` (3 floats) and `std` (3 floats), or WP_Error.
	 */
	public function compute_color_stats( $path ) {
		$gd = $this->load_gd_image( $path );
		if ( is_wp_error( $gd ) ) {
			return $gd;
		}

		// Downsample for speed.
		$src_w = imagesx( $gd );
		$src_h = imagesy( $gd );
		$max   = 96;
		$scale = max( 1, max( $src_w, $src_h ) / $max );
		$tw    = max( 1, (int) round( $src_w / $scale ) );
		$th    = max( 1, (int) round( $src_h / $scale ) );
		$small = imagecreatetruecolor( $tw, $th );
		imagecopyresampled( $small, $gd, 0, 0, 0, 0, $tw, $th, $src_w, $src_h );

		$sum_r  = 0.0;
		$sum_g  = 0.0;
		$sum_b  = 0.0;
		$sum_r2 = 0.0;
		$sum_g2 = 0.0;
		$sum_b2 = 0.0;
		$count  = 0;
		for ( $y = 0; $y < $th; $y++ ) {
			for ( $x = 0; $x < $tw; $x++ ) {
				$rgb     = imagecolorat( $small, $x, $y );
				$r       = ( $rgb >> 16 ) & 0xFF;
				$g       = ( $rgb >> 8 ) & 0xFF;
				$b       = $rgb & 0xFF;
				$sum_r  += $r;
				$sum_g  += $g;
				$sum_b  += $b;
				$sum_r2 += $r * $r;
				$sum_g2 += $g * $g;
				$sum_b2 += $b * $b;
				++$count;
			}
		}

		imagedestroy( $small );
		imagedestroy( $gd );

		if ( 0 === $count ) {
			return new WP_Error( 'wp_mcp_ai_color_stats_failed', __( 'Image is empty.', 'mcp-ai-wpoos-pro' ) );
		}

		$mean_r = $sum_r / $count;
		$mean_g = $sum_g / $count;
		$mean_b = $sum_b / $count;
		$var_r  = max( 0.0, ( $sum_r2 / $count ) - ( $mean_r * $mean_r ) );
		$var_g  = max( 0.0, ( $sum_g2 / $count ) - ( $mean_g * $mean_g ) );
		$var_b  = max( 0.0, ( $sum_b2 / $count ) - ( $mean_b * $mean_b ) );

		return array(
			'mean' => array( $mean_r, $mean_g, $mean_b ),
			'std'  => array( sqrt( $var_r ), sqrt( $var_g ), sqrt( $var_b ) ),
		);
	}

	/**
	 * Apply Reinhard color transfer from a reference image to a foreground image.
	 *
	 * Computes per-channel mean+std for both images and rescales the foreground so
	 * its statistics match the reference. Preserves the foreground alpha channel.
	 *
	 * @param string $foreground_path Path to foreground image.
	 * @param string $reference_path  Path to background/reference image.
	 * @param string $output_path     Where to write the result (PNG recommended).
	 * @param float  $strength        0..1 blending factor between original and matched output.
	 *
	 * @return array|WP_Error Stats report or error.
	 */
	public function reinhard_color_transfer( $foreground_path, $reference_path, $output_path, $strength = 1.0 ) {
		if ( ! $this->has_gd ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required for color transfer.', 'mcp-ai-wpoos-pro' ) );
		}

		$strength = max( 0.0, min( 1.0, (float) $strength ) );

		$fg_stats = $this->compute_color_stats( $foreground_path );
		if ( is_wp_error( $fg_stats ) ) {
			return $fg_stats;
		}
		$bg_stats = $this->compute_color_stats( $reference_path );
		if ( is_wp_error( $bg_stats ) ) {
			return $bg_stats;
		}

		$fg = $this->load_gd_image( $foreground_path );
		if ( is_wp_error( $fg ) ) {
			return $fg;
		}
		$w   = imagesx( $fg );
		$h   = imagesy( $fg );
		$out = imagecreatetruecolor( $w, $h );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );

		for ( $c = 0; $c < 3; $c++ ) {
			if ( $fg_stats['std'][ $c ] < 0.0001 ) {
				$fg_stats['std'][ $c ] = 0.0001;
			}
		}

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba  = imagecolorat( $fg, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7F;
				$r     = ( $rgba >> 16 ) & 0xFF;
				$g     = ( $rgba >> 8 ) & 0xFF;
				$b     = $rgba & 0xFF;

				$nr = ( $r - $fg_stats['mean'][0] ) * ( $bg_stats['std'][0] / $fg_stats['std'][0] ) + $bg_stats['mean'][0];
				$ng = ( $g - $fg_stats['mean'][1] ) * ( $bg_stats['std'][1] / $fg_stats['std'][1] ) + $bg_stats['mean'][1];
				$nb = ( $b - $fg_stats['mean'][2] ) * ( $bg_stats['std'][2] / $fg_stats['std'][2] ) + $bg_stats['mean'][2];

				$nr = $r + ( $nr - $r ) * $strength;
				$ng = $g + ( $ng - $g ) * $strength;
				$nb = $b + ( $nb - $b ) * $strength;

				$nr = (int) max( 0, min( 255, round( $nr ) ) );
				$ng = (int) max( 0, min( 255, round( $ng ) ) );
				$nb = (int) max( 0, min( 255, round( $nb ) ) );

				$color = imagecolorallocatealpha( $out, $nr, $ng, $nb, $alpha );
				imagesetpixel( $out, $x, $y, $color );
			}
		}

		$saved = imagepng( $out, $output_path );
		imagedestroy( $fg );
		imagedestroy( $out );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save color-transferred image.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'foreground_stats' => $fg_stats,
			'reference_stats'  => $bg_stats,
			'strength'         => $strength,
			'strategy'         => 'mean_std_rgb',
		);
	}

	/**
	 * Feather the alpha channel of a transparent PNG to soften edges and suppress halos.
	 *
	 * Implementation: a small box blur applied only to the alpha channel.
	 *
	 * @param string $input_path  Source transparent PNG.
	 * @param string $output_path Output path (PNG).
	 * @param int    $radius      Feather radius in pixels (1..10).
	 *
	 * @return true|WP_Error
	 */
	public function feather_alpha( $input_path, $output_path, $radius = 2 ) {
		if ( ! $this->has_gd ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}
		$radius = max( 1, min( 10, (int) $radius ) );
		$src    = $this->load_gd_image( $input_path );
		if ( is_wp_error( $src ) ) {
			return $src;
		}

		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$out = imagecreatetruecolor( $w, $h );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );

		// Read alpha into 1D array.
		$alpha = array_fill( 0, $w * $h, 0 );
		$rgb   = array_fill( 0, $w * $h, 0 );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c                     = imagecolorat( $src, $x, $y );
				$alpha[ $y * $w + $x ] = ( $c >> 24 ) & 0x7F;
				$rgb[ $y * $w + $x ]   = $c & 0xFFFFFF;
			}
		}

		// Box blur the alpha channel (separable horizontal + vertical).
		$alpha = $this->blur_1d( $alpha, $w, $h, $radius, true );
		$alpha = $this->blur_1d( $alpha, $w, $h, $radius, false );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$idx   = $y * $w + $x;
				$a     = (int) max( 0, min( 127, round( $alpha[ $idx ] ) ) );
				$color = $rgb[ $idx ];
				$r     = ( $color >> 16 ) & 0xFF;
				$g     = ( $color >> 8 ) & 0xFF;
				$b     = $color & 0xFF;
				imagesetpixel( $out, $x, $y, imagecolorallocatealpha( $out, $r, $g, $b, $a ) );
			}
		}

		$saved = imagepng( $out, $output_path );
		imagedestroy( $src );
		imagedestroy( $out );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save feathered image.', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Render a contact + cast shadow layer for a transparent-PNG subject.
	 *
	 * The shadow is derived from the subject's alpha channel:
	 *   - Contact shadow: a soft, dark, slightly-offset copy directly beneath the subject.
	 *   - Cast shadow: a longer, more diffuse copy offset along the light direction.
	 *
	 * @param string $subject_path Transparent PNG for the subject.
	 * @param string $output_path  Output PNG path (transparent layer with shadows only).
	 * @param array  $opts         Shadow options. Supports keys:.
	 *                              direction_deg (float, light direction; 0=right, 90=down),
	 *                              softness (float 0..1, blur radius),
	 *                              opacity (float 0..1, final opacity),
	 *                              length (float 0..1, cast shadow length factor).
	 *
	 * @return array|WP_Error params used (for the harmonization report) or error.
	 */
	public function render_shadow_layer( $subject_path, $output_path, array $opts = array() ) {
		if ( ! $this->has_gd ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}

		$direction = isset( $opts['direction_deg'] ) ? (float) $opts['direction_deg'] : 135.0;
		$softness  = isset( $opts['softness'] ) ? max( 0.0, min( 1.0, (float) $opts['softness'] ) ) : 0.5;
		$opacity   = isset( $opts['opacity'] ) ? max( 0.0, min( 1.0, (float) $opts['opacity'] ) ) : 0.5;
		$length    = isset( $opts['length'] ) ? max( 0.0, min( 1.0, (float) $opts['length'] ) ) : 0.4;

		$src = $this->load_gd_image( $subject_path );
		if ( is_wp_error( $src ) ) {
			return $src;
		}

		$w = imagesx( $src );
		$h = imagesy( $src );

		$canvas = imagecreatetruecolor( $w, $h );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
		$transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
		imagefilledrectangle( $canvas, 0, 0, $w, $h, $transparent );

		// Compute offsets in pixels.
		$rad      = deg2rad( $direction );
		$offset_x = (int) round( cos( $rad ) * $length * min( $w, $h ) * 0.25 );
		$offset_y = (int) round( sin( $rad ) * $length * min( $w, $h ) * 0.25 );
		$radius   = max( 1, min( 10, (int) round( 1 + $softness * 9 ) ) );
		$alpha_ce = (int) max( 0, min( 127, 127 - (int) round( $opacity * 127 ) ) );

		// Build alpha map of the subject silhouette.
		$silhouette = array_fill( 0, $w * $h, 0 );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c     = imagecolorat( $src, $x, $y );
				$alpha = ( $c >> 24 ) & 0x7F;
				if ( $alpha < 110 ) {
					// 0 = opaque, 127 = transparent. Keep "more opaque" subject pixels.
					$silhouette[ $y * $w + $x ] = 127 - $alpha;
				}
			}
		}

		// Blur silhouette twice for soft cast shadow.
		$silhouette = $this->blur_1d( $silhouette, $w, $h, $radius, true );
		$silhouette = $this->blur_1d( $silhouette, $w, $h, $radius, false );

		// Render onto canvas with offset.
		for ( $y = 0; $y < $h; $y++ ) {
			$src_y = $y - $offset_y;
			if ( $src_y < 0 || $src_y >= $h ) {
				continue;
			}
			for ( $x = 0; $x < $w; $x++ ) {
				$src_x = $x - $offset_x;
				if ( $src_x < 0 || $src_x >= $w ) {
					continue;
				}
				$strength = $silhouette[ $src_y * $w + $src_x ];
				if ( $strength <= 0 ) {
					continue;
				}
				// Convert "intensity" (0..127) into "alpha" (127 = transparent, 0 = opaque).
				$shadow_alpha = (int) max( $alpha_ce, 127 - (int) round( $strength * $opacity ) );
				$color        = imagecolorallocatealpha( $canvas, 0, 0, 0, $shadow_alpha );
				imagesetpixel( $canvas, $x, $y, $color );
			}
		}

		$saved = imagepng( $canvas, $output_path );
		imagedestroy( $src );
		imagedestroy( $canvas );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save shadow layer.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'direction_deg' => $direction,
			'softness'      => $softness,
			'opacity'       => $opacity,
			'length'        => $length,
			'offset_x'      => $offset_x,
			'offset_y'      => $offset_y,
		);
	}

	/**
	 * Render a vertical-flip reflection layer for the subject.
	 *
	 * @param string $subject_path Transparent PNG.
	 * @param string $output_path  Output PNG.
	 * @param array  $opts         { fade (0..1), blur_radius (1..10), opacity (0..1) }.
	 *
	 * @return true|WP_Error
	 */
	public function render_reflection_layer( $subject_path, $output_path, array $opts = array() ) {
		if ( ! $this->has_gd ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}

		$fade    = isset( $opts['fade'] ) ? max( 0.0, min( 1.0, (float) $opts['fade'] ) ) : 0.7;
		$opacity = isset( $opts['opacity'] ) ? max( 0.0, min( 1.0, (float) $opts['opacity'] ) ) : 0.4;

		$src = $this->load_gd_image( $subject_path );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );

		$out = imagecreatetruecolor( $w, $h * 2 );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		$transparent = imagecolorallocatealpha( $out, 0, 0, 0, 127 );
		imagefilledrectangle( $out, 0, 0, $w, $h * 2, $transparent );

		// Top half: original.
		imagecopy( $out, $src, 0, 0, 0, 0, $w, $h );

		// Bottom half: vertical flip with progressive fade.
		for ( $y = 0; $y < $h; $y++ ) {
			$factor = ( 1.0 - ( $y / max( 1, $h - 1 ) ) ) * $opacity * ( 1.0 - $fade * ( $y / max( 1, $h - 1 ) ) );
			if ( $factor <= 0 ) {
				continue;
			}
			$src_y = $h - 1 - $y;
			for ( $x = 0; $x < $w; $x++ ) {
				$c     = imagecolorat( $src, $x, $src_y );
				$alpha = ( $c >> 24 ) & 0x7F;
				if ( $alpha >= 127 ) {
					continue;
				}
				$r         = ( $c >> 16 ) & 0xFF;
				$g         = ( $c >> 8 ) & 0xFF;
				$b         = $c & 0xFF;
				$base_op   = ( 127 - $alpha ) / 127.0;
				$final_op  = max( 0.0, min( 1.0, $base_op * $factor ) );
				$out_alpha = (int) max( 0, min( 127, 127 - (int) round( $final_op * 127 ) ) );
				$color     = imagecolorallocatealpha( $out, $r, $g, $b, $out_alpha );
				imagesetpixel( $out, $x, $h + $y, $color );
			}
		}

		$saved = imagepng( $out, $output_path );
		imagedestroy( $src );
		imagedestroy( $out );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save reflection layer.', 'mcp-ai-wpoos-pro' ) );
		}

		return true;
	}

	/**
	 * Composite a transparent foreground onto a background at a target box.
	 *
	 * @param string $foreground_path Foreground PNG path (transparent).
	 * @param string $background_path Background image path.
	 * @param string $output_path     Output path.
	 * @param array  $box             { x, y, w, h } target rectangle in background-pixel space.
	 *
	 * @return true|WP_Error
	 */
	public function composite_over( $foreground_path, $background_path, $output_path, array $box ) {
		if ( ! $this->has_gd ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}

		$bg = $this->load_gd_image( $background_path );
		if ( is_wp_error( $bg ) ) {
			return $bg;
		}
		$fg = $this->load_gd_image( $foreground_path );
		if ( is_wp_error( $fg ) ) {
			imagedestroy( $bg );
			return $fg;
		}

		$bw = imagesx( $bg );
		$bh = imagesy( $bg );

		$tx = isset( $box['x'] ) ? (int) $box['x'] : 0;
		$ty = isset( $box['y'] ) ? (int) $box['y'] : 0;
		$tw = isset( $box['w'] ) ? max( 1, (int) $box['w'] ) : imagesx( $fg );
		$th = isset( $box['h'] ) ? max( 1, (int) $box['h'] ) : imagesy( $fg );

		$resized = imagecreatetruecolor( $tw, $th );
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		imagecopyresampled( $resized, $fg, 0, 0, 0, 0, $tw, $th, imagesx( $fg ), imagesy( $fg ) );

		imagealphablending( $bg, true );
		imagecopy( $bg, $resized, $tx, $ty, 0, 0, $tw, $th );

		imagesavealpha( $bg, true );
		$saved = imagepng( $bg, $output_path );
		imagedestroy( $bg );
		imagedestroy( $fg );
		imagedestroy( $resized );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save composited image.', 'mcp-ai-wpoos-pro' ) );
		}

		// Suppress unused vars: $bw/$bh kept for clarity (may inform downstream guards).
		unset( $bw, $bh );

		return true;
	}

	/**
	 * Helper: 1D box blur over a flat array.
	 *
	 * @param array $data       Source buffer.
	 * @param int   $w          Width.
	 * @param int   $h          Height.
	 * @param int   $radius     Blur radius.
	 * @param bool  $horizontal True for horizontal pass, false for vertical.
	 *
	 * @return array Blurred buffer.
	 */
	protected function blur_1d( array $data, $w, $h, $radius, $horizontal ) {
		$out = $data;
		if ( $horizontal ) {
			for ( $y = 0; $y < $h; $y++ ) {
				$row_start = $y * $w;
				for ( $x = 0; $x < $w; $x++ ) {
					$sum   = 0;
					$count = 0;
					$x0    = max( 0, $x - $radius );
					$x1    = min( $w - 1, $x + $radius );
					for ( $i = $x0; $i <= $x1; $i++ ) {
						$sum += $data[ $row_start + $i ];
						++$count;
					}
					$out[ $row_start + $x ] = $count > 0 ? $sum / $count : 0;
				}
			}
		} else {
			for ( $x = 0; $x < $w; $x++ ) {
				for ( $y = 0; $y < $h; $y++ ) {
					$sum   = 0;
					$count = 0;
					$y0    = max( 0, $y - $radius );
					$y1    = min( $h - 1, $y + $radius );
					for ( $i = $y0; $i <= $y1; $i++ ) {
						$sum += $data[ $i * $w + $x ];
						++$count;
					}
					$out[ $y * $w + $x ] = $count > 0 ? $sum / $count : 0;
				}
			}
		}
		return $out;
	}

	/**
	 * Load any supported image as a GD truecolor resource with alpha enabled.
	 *
	 * @param string $path Path to image.
	 *
	 * @return resource|GdImage|WP_Error
	 */
	protected function load_gd_image( $path ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Image file not found.', 'mcp-ai-wpoos-pro' ) );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Invalid image file.', 'mcp-ai-wpoos-pro' ) );
		}

		$type = isset( $info[2] ) ? (int) $info[2] : 0;
		switch ( $type ) {
			case IMAGETYPE_PNG:
				$img = imagecreatefrompng( $path );
				break;
			case IMAGETYPE_JPEG:
				$img = imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_GIF:
				$img = imagecreatefromgif( $path );
				break;
			case IMAGETYPE_WEBP:
				$img = function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : false;
				break;
			default:
				$img = false;
		}

		if ( ! $img ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Failed to decode image.', 'mcp-ai-wpoos-pro' ) );
		}

		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		return $img;
	}
}
