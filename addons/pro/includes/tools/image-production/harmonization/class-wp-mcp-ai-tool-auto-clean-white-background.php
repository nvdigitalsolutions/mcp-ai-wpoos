<?php
/**
 * Tool: auto_clean_white_background.
 *
 * Converts a "white-background product photo" into a clean transparent PNG.
 * Tuned specifically for the catalog/white-cyc case — distinct from the
 * generic `remove_image_background` tool.
 *
 * Algorithm: pixels close to white (above per-channel threshold) become
 * transparent; near-white pixels are alpha-blended for soft anti-aliased edges.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-tool-harmonization-base.php';

/**
 * Convert white-background product photos to transparent PNG.
 */
class WP_MCP_AI_Tool_Auto_Clean_White_Background extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'auto_clean_white_background';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Auto-Clean White Background', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert a white-background product photo into a clean transparent PNG with smart edge anti-aliasing. Optimized for catalog / white-cyc product shots.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id' => $this->harmonization_get_image_input_schema( 'product photo' )['attachment_id'],
				'threshold'             => array(
					'type'    => 'integer',
					'minimum' => 200,
					'maximum' => 255,
					'default' => 245,
				),
				'feather_radius'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 5,
					'default' => 1,
				),
			),
			'required'             => array( 'subject_attachment_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool body.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   Authorized user id (0 for token auth).
	 *
	 * @return array|WP_Error
	 */
	protected function execute_harmonization( array $arguments, array $context, $user_id ) {
		if ( ! extension_loaded( 'gd' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}
		$resolved = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'product' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$path      = $resolved['file_path'];
		$threshold = isset( $arguments['threshold'] ) ? max( 200, min( 255, (int) $arguments['threshold'] ) ) : 245;
		$feather   = isset( $arguments['feather_radius'] ) ? max( 0, min( 5, (int) $arguments['feather_radius'] ) ) : 1;

		$src = $this->load_image( $path );
		if ( is_wp_error( $src ) ) {
			$this->harmonization_cleanup( $path );
			return $src;
		}
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$out = imagecreatetruecolor( $w, $h );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );

		$matte_count   = 0;
		$total_pixels  = $w * $h;
		$soft_band_min = max( 200, $threshold - 20 );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgb = imagecolorat( $src, $x, $y );
				$r   = ( $rgb >> 16 ) & 0xFF;
				$g   = ( $rgb >> 8 ) & 0xFF;
				$b   = $rgb & 0xFF;
				$min = min( $r, $g, $b );

				if ( $min >= $threshold ) {
					$alpha = 127; // Fully transparent.
				} elseif ( $min >= $soft_band_min ) {
					// Linear ramp inside the soft band.
					$t     = ( $min - $soft_band_min ) / max( 1, $threshold - $soft_band_min );
					$alpha = (int) round( $t * 127 );
				} else {
					$alpha = 0;
					++$matte_count;
				}

				imagesetpixel( $out, $x, $y, imagecolorallocatealpha( $out, $r, $g, $b, $alpha ) );
			}
		}
		imagedestroy( $src );

		// Optional feather using the compositor.
		$tmp_path = $this->harmonization_temp_dir() . '/clean-' . wp_generate_password( 8, false ) . '.png';
		imagepng( $out, $tmp_path );
		imagedestroy( $out );

		if ( $feather > 0 ) {
			$feathered = $this->harmonization_temp_dir() . '/feathered-' . wp_generate_password( 8, false ) . '.png';
			$res       = $this->compositor()->feather_alpha( $tmp_path, $feathered, $feather );
			$this->harmonization_cleanup( $tmp_path );
			if ( is_wp_error( $res ) ) {
				$this->harmonization_cleanup( $path );
				return $res;
			}
			$tmp_path = $feathered;
		}

		$this->harmonization_cleanup( $path );

		$media = $this->harmonization_import_to_media( $tmp_path, __( 'Cleaned Product (transparent)', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $tmp_path );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$matte_quality = 0.0;
		if ( $total_pixels > 0 ) {
			$matte_quality = round( min( 1.0, max( 0.0, $matte_count / max( 1, $total_pixels * 0.05 ) ) ), 3 );
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array(
				'threshold'      => $threshold,
				'feather_radius' => $feather,
				'matte_quality'  => $matte_quality,
			)
		);
	}

	/**
	 * Load an image file as a GD resource.
	 *
	 * @param string $path Path to image.
	 *
	 * @return resource|GdImage|WP_Error
	 */
	protected function load_image( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Invalid image file.', 'mcp-ai-wpoos-pro' ) );
		}
		switch ( (int) $info[2] ) {
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $path );
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : new WP_Error( 'wp_mcp_ai_unsupported_format', 'WebP unavailable' );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			default:
				return new WP_Error( 'wp_mcp_ai_unsupported_format', __( 'Unsupported image format.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
