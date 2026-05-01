<?php
/**
 * Tool: suggest_placement.
 *
 * Given a subject and background, suggest top-3 placement bounding boxes and
 * scale factors using a saliency-style heuristic over the background.
 *
 * Cheap path: scan the background for the lowest-saliency (most uniform) regions
 * and prefer those for placement. Falls back to a centered placement when the
 * heuristic confidence is low.
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
 * Suggest placement boxes for a subject on a background.
 */
class WP_MCP_AI_Tool_Suggest_Placement extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'suggest_placement';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Suggest Placement', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Suggest top-3 placement bounding boxes and scale factors for a subject onto a background using a saliency / uniform-region heuristic. Cheap; no AI call required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Read-only capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id'    => $this->harmonization_get_image_input_schema( 'subject (transparent PNG)' )['attachment_id'],
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'background image' )['attachment_id'],
				'target_scale'             => array(
					'type'    => 'number',
					'minimum' => 0.05,
					'maximum' => 1.0,
					'default' => 0.4,
				),
			),
			'required'             => array( 'subject_attachment_id', 'background_attachment_id' ),
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

		$subject = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'subject' );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$bg = $this->harmonization_resolve_input( $arguments['background_attachment_id'], 'background' );
		if ( is_wp_error( $bg ) ) {
			$this->harmonization_cleanup( $subject['file_path'] );
			return $bg;
		}

		$scale = isset( $arguments['target_scale'] ) ? max( 0.05, min( 1.0, (float) $arguments['target_scale'] ) ) : 0.4;

		$bg_size = @getimagesize( $bg['file_path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$su_size = @getimagesize( $subject['file_path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $bg_size ) || ! is_array( $su_size ) ) {
			$this->harmonization_cleanup( $subject['file_path'] );
			$this->harmonization_cleanup( $bg['file_path'] );
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Could not read image dimensions.', 'mcp-ai-wpoos-pro' ) );
		}

		$bg_w     = (int) $bg_size[0];
		$bg_h     = (int) $bg_size[1];
		$su_w     = (int) $su_size[0];
		$su_h     = (int) $su_size[1];
		$su_ratio = $su_w / max( 1, $su_h );

		// Target subject width = scale * bg_w.
		$target_w = (int) round( $scale * $bg_w );
		$target_h = (int) round( $target_w / max( 0.001, $su_ratio ) );

		// Build saliency map from background.
		$candidates = $this->find_low_saliency_regions( $bg['file_path'], $bg_w, $bg_h, $target_w, $target_h );

		$this->harmonization_cleanup( $subject['file_path'] );
		$this->harmonization_cleanup( $bg['file_path'] );

		// If no high-quality candidates, fall back to thirds + center.
		if ( count( $candidates ) < 3 ) {
			$fallbacks = array(
				array(
					'x'          => (int) ( ( $bg_w - $target_w ) / 2 ),
					'y'          => (int) ( $bg_h * 0.55 ),
					'confidence' => 0.4,
				),
				array(
					'x'          => (int) ( $bg_w * 0.6 - $target_w / 2 ),
					'y'          => (int) ( $bg_h * 0.55 ),
					'confidence' => 0.3,
				),
				array(
					'x'          => (int) ( $bg_w * 0.4 - $target_w / 2 ),
					'y'          => (int) ( $bg_h * 0.55 ),
					'confidence' => 0.3,
				),
			);
			foreach ( $fallbacks as $fb ) {
				if ( count( $candidates ) >= 3 ) {
					break;
				}
				$candidates[] = array(
					'x'          => max( 0, min( $bg_w - $target_w, $fb['x'] ) ),
					'y'          => max( 0, min( $bg_h - $target_h, $fb['y'] ) ),
					'w'          => $target_w,
					'h'          => $target_h,
					'confidence' => $fb['confidence'],
					'strategy'   => 'fallback_thirds',
				);
			}
		}

		return array(
			'success'    => true,
			'stage'      => $this->get_slug(),
			'candidates' => array_slice( $candidates, 0, 3 ),
			'scale'      => $scale,
			'text'       => sprintf(
				/* translators: %d: number of candidates */
				__( 'Suggested %d placement candidate(s).', 'mcp-ai-wpoos-pro' ),
				min( 3, count( $candidates ) )
			),
		);
	}

	/**
	 * Find top-N low-saliency regions in a background suitable for subject placement.
	 *
	 * @param string $path    Path to background image.
	 * @param int    $bg_w    Background width.
	 * @param int    $bg_h    Background height.
	 * @param int    $box_w   Target box width.
	 * @param int    $box_h   Target box height.
	 *
	 * @return array Top-3 candidates ordered by confidence.
	 */
	protected function find_low_saliency_regions( $path, $bg_w, $bg_h, $box_w, $box_h ) {
		$grid_w = 8;
		$grid_h = 6;

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return array();
		}
		$type = (int) $info[2];
		switch ( $type ) {
			case IMAGETYPE_PNG:
				$src = imagecreatefrompng( $path );
				break;
			case IMAGETYPE_JPEG:
				$src = imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : false;
				break;
			default:
				$src = false;
		}
		if ( ! $src ) {
			return array();
		}

		$small = imagecreatetruecolor( $grid_w, $grid_h );
		imagecopyresampled( $small, $src, 0, 0, 0, 0, $grid_w, $grid_h, $bg_w, $bg_h );

		// Compute per-cell variance as a saliency proxy.
		$samples = array();
		for ( $cy = 0; $cy < $grid_h; $cy++ ) {
			for ( $cx = 0; $cx < $grid_w; $cx++ ) {
				$c         = imagecolorat( $small, $cx, $cy );
				$lum       = ( ( ( $c >> 16 ) & 0xFF ) + ( ( $c >> 8 ) & 0xFF ) + ( $c & 0xFF ) ) / 3.0;
				$samples[] = array(
					'gx'  => $cx,
					'gy'  => $cy,
					'lum' => $lum,
				);
			}
		}
		imagedestroy( $small );
		imagedestroy( $src );

		// Score each cell by similarity to its neighborhood (low variance = good).
		$scores = array();
		foreach ( $samples as $s ) {
			$neigh = array();
			foreach ( $samples as $t ) {
				if ( abs( $t['gx'] - $s['gx'] ) <= 1 && abs( $t['gy'] - $s['gy'] ) <= 1 ) {
					$neigh[] = $t['lum'];
				}
			}
			$mean = array_sum( $neigh ) / max( 1, count( $neigh ) );
			$var  = 0.0;
			foreach ( $neigh as $v ) {
				$var += ( $v - $mean ) * ( $v - $mean );
			}
			$var      = $var / max( 1, count( $neigh ) );
			$scores[] = array(
				'gx'         => $s['gx'],
				'gy'         => $s['gy'],
				'confidence' => 1.0 / ( 1.0 + sqrt( $var ) / 32.0 ),
			);
		}

		// Bias toward bottom-center of the frame (where products usually sit).
		foreach ( $scores as &$row ) {
			$bias_y            = 1.0 - abs( ( $row['gy'] / max( 1, $grid_h - 1 ) ) - 0.65 );
			$bias_x            = 1.0 - abs( ( $row['gx'] / max( 1, $grid_w - 1 ) ) - 0.5 );
			$row['confidence'] = $row['confidence'] * 0.6 + ( $bias_x * $bias_y ) * 0.4;
		}
		unset( $row );

		usort(
			$scores,
			static function ( $a, $b ) {
				if ( $a['confidence'] === $b['confidence'] ) {
					return 0;
				}
				return ( $a['confidence'] < $b['confidence'] ) ? 1 : -1;
			}
		);

		$cell_w  = $bg_w / $grid_w;
		$cell_h  = $bg_h / $grid_h;
		$results = array();
		$used_xy = array();
		foreach ( $scores as $row ) {
			$cx = (int) ( ( $row['gx'] + 0.5 ) * $cell_w - $box_w / 2 );
			$cy = (int) ( ( $row['gy'] + 0.5 ) * $cell_h - $box_h / 2 );
			$cx = max( 0, min( $bg_w - $box_w, $cx ) );
			$cy = max( 0, min( $bg_h - $box_h, $cy ) );

			// Avoid stacking duplicates in the same vicinity.
			$key = (int) ( $cx / max( 1, $cell_w ) ) . ',' . (int) ( $cy / max( 1, $cell_h ) );
			if ( isset( $used_xy[ $key ] ) ) {
				continue;
			}
			$used_xy[ $key ] = true;

			$results[] = array(
				'x'          => $cx,
				'y'          => $cy,
				'w'          => $box_w,
				'h'          => $box_h,
				'confidence' => round( $row['confidence'], 3 ),
				'strategy'   => 'saliency_grid',
			);
			if ( count( $results ) >= 3 ) {
				break;
			}
		}

		return $results;
	}
}
