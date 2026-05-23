<?php
/**
 * Lighting analysis service for the harmonization toolkit.
 *
 * Estimates light direction, color temperature, and intensity from a background
 * image using cheap heuristics. The result is exposed to the LLM via the
 * harmonization "report" so users can see what the toolkit detected.
 *
 * Heuristic-first by design: we only escalate to a paid AI vision call when
 * confidence is below a threshold (and a vision-capable client is configured).
 *
 * Result is filterable through `wp_mcp_ai_harmonization_lighting`.
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
 * Lighting analyzer.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Lighting_Analyzer {

	/**
	 * Confidence threshold below which we may escalate to AI.
	 *
	 * @var float
	 */
	protected $low_confidence_threshold = 0.35;

	/**
	 * Sample grid size used for brightness scanning.
	 *
	 * @var int
	 */
	protected $sample_grid = 16;

	/**
	 * Analyze the lighting of an image file.
	 *
	 * @param string $image_path Absolute path to the image.
	 * @param array  $opts       Optional analysis settings. Supports.
	 *                           'allow_ai_escalation' (bool) to escalate to AI
	 *                           vision when heuristic confidence is low.
	 *
	 * @return array|WP_Error Result with keys:
	 *                        direction_deg (float, 0=right/90=down/180=left/270=up),
	 *                        intensity (float 0..1), contrast (float 0..1),
	 *                        color_temp ('warm'|'neutral'|'cool'),
	 *                        kelvin_estimate (int), confidence (float 0..1),
	 *                        strategy (string label).
	 */
	public function analyze( $image_path, array $opts = array() ) {
		if ( ! extension_loaded( 'gd' ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_gd',
				__( 'GD extension required for lighting analysis.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $this->heuristic_analyze( $image_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Optional AI escalation hook (kept lightweight - just a filter,.
		// callers can wire in a Gemini/OpenAI vision call if they want it).
		$allow = ! empty( $opts['allow_ai_escalation'] );
		if ( $allow && $result['confidence'] < $this->low_confidence_threshold ) {
			/**
			 * Filter to allow callers to refine a low-confidence lighting estimate
			 * with an AI vision call. Receives the heuristic result and the image
			 * path; should return either a refined array (same shape) or the
			 * original input.
			 *
			 * @param array  $result     Heuristic result.
			 * @param string $image_path Path to image.
			 */
			$result = apply_filters( 'wp_mcp_ai_harmonization_lighting_escalation', $result, $image_path );
		}

		/**
		 * Filter the final lighting analysis result.
		 *
		 * @param array  $result     Lighting analysis result.
		 * @param string $image_path Path to image.
		 */
		$result = apply_filters( 'wp_mcp_ai_harmonization_lighting', $result, $image_path );

		return $result;
	}

	/**
	 * Pure-heuristic lighting analysis.
	 *
	 * @param string $image_path Path to image.
	 *
	 * @return array|WP_Error
	 */
	protected function heuristic_analyze( $image_path ) {
		$img = $this->load_image( $image_path );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		$src_w = imagesx( $img );
		$src_h = imagesy( $img );

		// Down-sample to a small grid for fast analysis.
		$grid_w = $this->sample_grid;
		$grid_h = max( 1, (int) round( $this->sample_grid * ( $src_h / max( 1, $src_w ) ) ) );
		$small  = imagecreatetruecolor( $grid_w, $grid_h );
		imagecopyresampled( $small, $img, 0, 0, 0, 0, $grid_w, $grid_h, $src_w, $src_h );

		$brightness = array();
		$sum_r      = 0.0;
		$sum_g      = 0.0;
		$sum_b      = 0.0;
		$bright_max = 0.0;
		$bright_min = 1.0;
		$bright_x   = 0.0;
		$bright_y   = 0.0;
		$bright_w   = 0.0;

		for ( $y = 0; $y < $grid_h; $y++ ) {
			$row = array();
			for ( $x = 0; $x < $grid_w; $x++ ) {
				$c = imagecolorat( $small, $x, $y );
				$r = ( $c >> 16 ) & 0xFF;
				$g = ( $c >> 8 ) & 0xFF;
				$b = $c & 0xFF;
				// Rec. 601 luma.
				$lum    = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255.0;
				$row[]  = $lum;
				$sum_r += $r;
				$sum_g += $g;
				$sum_b += $b;
				if ( $lum > $bright_max ) {
					$bright_max = $lum;
				}
				if ( $lum < $bright_min ) {
					$bright_min = $lum;
				}
				// Weighted centroid of bright pixels (above 0.6).
				if ( $lum > 0.6 ) {
					$weight    = $lum;
					$bright_x += $x * $weight;
					$bright_y += $y * $weight;
					$bright_w += $weight;
				}
				$brightness[ $y * $grid_w + $x ] = $lum;
			}
		}

		imagedestroy( $small );
		imagedestroy( $img );

		$total_pixels = $grid_w * $grid_h;
		$mean_lum     = array_sum( $brightness ) / max( 1, $total_pixels );
		$contrast     = max( 0.0, $bright_max - $bright_min );

		// Determine direction from brightest centroid relative to center.
		if ( $bright_w > 0 ) {
			$cx        = $bright_x / $bright_w;
			$cy        = $bright_y / $bright_w;
			$dx        = $cx - ( $grid_w / 2.0 );
			$dy        = $cy - ( $grid_h / 2.0 );
			$direction = ( 0.0 === $dx && 0.0 === $dy ) ? 135.0 : rad2deg( atan2( $dy, $dx ) );
			if ( $direction < 0 ) {
				$direction += 360.0;
			}
			$confidence = min( 1.0, $bright_w / max( 1.0, $total_pixels * 0.05 ) );
		} else {
			$direction  = 135.0; // Default: upper-left light source casting toward lower-right.
			$confidence = 0.1;
		}

		// Color temperature: ratio of red+green vs blue.
		$mean_r = $sum_r / max( 1, $total_pixels );
		$mean_g = $sum_g / max( 1, $total_pixels );
		$mean_b = $sum_b / max( 1, $total_pixels );
		$denom  = max( 1.0, $mean_b );
		$ratio  = ( $mean_r + 0.5 * $mean_g ) / $denom;

		if ( $ratio > 1.6 ) {
			$color_temp = 'warm';
			$kelvin     = 3200;
		} elseif ( $ratio < 1.05 ) {
			$color_temp = 'cool';
			$kelvin     = 6500;
		} else {
			$color_temp = 'neutral';
			$kelvin     = 5000;
		}

		return array(
			'direction_deg'   => round( $direction, 1 ),
			'intensity'       => round( $mean_lum, 3 ),
			'contrast'        => round( $contrast, 3 ),
			'color_temp'      => $color_temp,
			'kelvin_estimate' => $kelvin,
			'confidence'      => round( $confidence, 3 ),
			'strategy'        => 'heuristic_brightness_centroid',
		);
	}

	/**
	 * Load any supported image as a GD resource.
	 *
	 * @param string $path Path to image.
	 *
	 * @return resource|GdImage|WP_Error
	 */
	protected function load_image( $path ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Image file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_image',
				__( 'Invalid image file.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = isset( $info[2] ) ? (int) $info[2] : 0;
		switch ( $type ) {
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $path );
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					return imagecreatefromwebp( $path );
				}
				return new WP_Error( 'wp_mcp_ai_unsupported_format', __( 'WebP not supported on this server.', 'mcp-ai-wpoos-pro' ) );
			default:
				return new WP_Error( 'wp_mcp_ai_unsupported_format', __( 'Unsupported image format.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
