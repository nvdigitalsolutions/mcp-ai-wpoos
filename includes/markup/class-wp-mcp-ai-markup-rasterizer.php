<?php
/**
 * Rasterizer that converts a validated W3C Web Annotation document into
 * the artifact shapes that downstream tools expect:
 *
 *  - RGBA PNG mask (alpha=0 inside region, alpha=255 outside) for
 *    OpenAI / Gemini / Stability inpainting compatibility.
 *  - Position vector ({from:{x,y}, to:{x,y}, normalized:bool}).
 *  - Crop rect ({x,y,width,height}).
 *  - PDF redaction rect list (page-keyed).
 *
 * Mask generation requires GD or Imagick (already required for many
 * other plugin features). When neither is available the rasterizer
 * falls back to returning the structured shapes only — callers can
 * then choose whether to proceed without a mask attachment.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_Rasterizer
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Rasterizer {

	/**
	 * Rasterize a cleaned annotation into mode-specific artifacts.
	 *
	 * @param WP_MCP_AI_Markup_Request $request    Source request.
	 * @param array                    $annotation Cleaned annotation (validator output).
	 * @return array Array of artifacts keyed by name.
	 */
	public function rasterize( WP_MCP_AI_Markup_Request $request, array $annotation ) {
		$artifacts = array();
		$shapes    = $this->extract_shapes( $annotation );

		switch ( $request->get_mode() ) {
			case WP_MCP_AI_Markup_Request::MODE_MASK:
				$mask = $this->build_mask( $request, $shapes );
				if ( ! is_wp_error( $mask ) ) {
					$artifacts['mask_attachment_id'] = $mask;
				}
				$artifacts['shapes'] = $shapes;
				break;
			case WP_MCP_AI_Markup_Request::MODE_REGION:
				$artifacts['region_rect'] = $this->shapes_to_bounding_rect( $shapes, $request );
				$artifacts['shapes']      = $shapes;
				break;
			case WP_MCP_AI_Markup_Request::MODE_CROP:
				$artifacts['crop_rect'] = $this->shapes_to_bounding_rect( $shapes, $request );
				$artifacts['shapes']    = $shapes;
				break;
			case WP_MCP_AI_Markup_Request::MODE_POSITION:
				$artifacts['position_vector'] = $this->shapes_to_position_vector( $shapes );
				break;
			case WP_MCP_AI_Markup_Request::MODE_REDACT:
				$artifacts['redaction_rects'] = $this->shapes_to_pdf_rects( $shapes );
				break;
			case WP_MCP_AI_Markup_Request::MODE_TEXT_RANGE:
				$artifacts['text_ranges'] = $this->extract_text_ranges( $annotation );
				break;
			case WP_MCP_AI_Markup_Request::MODE_ANNOTATE:
			default:
				$artifacts['shapes']   = $shapes;
				$artifacts['comments'] = $this->extract_comments( $annotation );
				break;
		}

		/**
		 * Filter the rasterized artifacts before they are handed to the tool.
		 *
		 * @param array                    $artifacts  Mode-specific artifacts.
		 * @param array                    $annotation Cleaned annotation.
		 * @param WP_MCP_AI_Markup_Request $request    Source request.
		 */
		return apply_filters( 'wp_mcp_ai_markup_rasterized_artifacts', $artifacts, $annotation, $request );
	}

	/**
	 * Pull shape descriptors out of body items.
	 *
	 * @param array $annotation Cleaned annotation.
	 * @return array
	 */
	private function extract_shapes( array $annotation ) {
		$shapes = array();
		$body   = isset( $annotation['body'] ) ? $annotation['body'] : array();
		if ( ! is_array( $body ) ) {
			return $shapes;
		}
		foreach ( $body as $item ) {
			if ( is_array( $item ) && isset( $item['shape'] ) && is_array( $item['shape'] ) ) {
				$shapes[] = $item['shape'];
			}
		}
		return $shapes;
	}

	/**
	 * Pull comment text out of body items.
	 *
	 * @param array $annotation Cleaned annotation.
	 * @return array
	 */
	private function extract_comments( array $annotation ) {
		$comments = array();
		$body     = isset( $annotation['body'] ) ? $annotation['body'] : array();
		if ( ! is_array( $body ) ) {
			return $comments;
		}
		foreach ( $body as $item ) {
			if ( is_array( $item ) && isset( $item['type'] ) && 'TextualBody' === $item['type'] && isset( $item['value'] ) ) {
				$comments[] = (string) $item['value'];
			}
		}
		return $comments;
	}

	/**
	 * Pull TextQuoteSelector / TextPositionSelector pairs out of the target.
	 *
	 * @param array $annotation Cleaned annotation.
	 * @return array
	 */
	private function extract_text_ranges( array $annotation ) {
		$ranges  = array();
		$targets = isset( $annotation['target'] ) ? $annotation['target'] : array();
		if ( ! is_array( $targets ) ) {
			return $ranges;
		}
		// Normalize to a list.
		if ( isset( $targets['source'] ) || isset( $targets['selector'] ) ) {
			$targets = array( $targets );
		}
		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}
			$selectors = isset( $target['selector'] ) ? $target['selector'] : array();
			if ( isset( $selectors['type'] ) ) {
				$selectors = array( $selectors );
			}
			$range = array();
			foreach ( $selectors as $sel ) {
				if ( ! is_array( $sel ) ) {
					continue;
				}
				if ( 'TextQuoteSelector' === $sel['type'] ) {
					$range['quote'] = array(
						'exact'  => isset( $sel['exact'] ) ? $sel['exact'] : '',
						'prefix' => isset( $sel['prefix'] ) ? $sel['prefix'] : '',
						'suffix' => isset( $sel['suffix'] ) ? $sel['suffix'] : '',
					);
				} elseif ( 'TextPositionSelector' === $sel['type'] ) {
					$range['position'] = array(
						'start' => isset( $sel['start'] ) ? (int) $sel['start'] : 0,
						'end'   => isset( $sel['end'] ) ? (int) $sel['end'] : 0,
					);
				}
			}
			if ( ! empty( $range ) ) {
				$ranges[] = $range;
			}
		}
		return $ranges;
	}

	/**
	 * Compute a bounding rect over a set of shapes.
	 *
	 * @param array                    $shapes  Shape descriptors.
	 * @param WP_MCP_AI_Markup_Request $request Source request (for image dims).
	 * @return array {x, y, width, height} or empty array if no points.
	 */
	private function shapes_to_bounding_rect( array $shapes, WP_MCP_AI_Markup_Request $request ) {
		$min_x = PHP_FLOAT_MAX;
		$min_y = PHP_FLOAT_MAX;
		$max_x = -PHP_FLOAT_MAX;
		$max_y = -PHP_FLOAT_MAX;
		$found = false;
		foreach ( $shapes as $shape ) {
			$points = isset( $shape['points'] ) ? $shape['points'] : array();
			foreach ( $points as $pt ) {
				$x = isset( $pt['x'] ) ? (float) $pt['x'] : null;
				$y = isset( $pt['y'] ) ? (float) $pt['y'] : null;
				if ( null === $x || null === $y ) {
					continue;
				}
				$min_x = min( $min_x, $x );
				$min_y = min( $min_y, $y );
				$max_x = max( $max_x, $x );
				$max_y = max( $max_y, $y );
				$found = true;
			}
		}
		if ( ! $found ) {
			return array();
		}
		$target = $request->get_target();
		$width  = isset( $target['width'] ) ? (int) $target['width'] : 0;
		$height = isset( $target['height'] ) ? (int) $target['height'] : 0;
		return array(
			'x'          => $min_x,
			'y'          => $min_y,
			'width'      => max( 0.0, $max_x - $min_x ),
			'height'     => max( 0.0, $max_y - $min_y ),
			'normalized' => ( $width > 0 && $height > 0 && $max_x <= 1.0 && $max_y <= 1.0 ),
		);
	}

	/**
	 * Convert the first arrow-shaped body to a position vector.
	 *
	 * @param array $shapes Shape descriptors.
	 * @return array
	 */
	private function shapes_to_position_vector( array $shapes ) {
		foreach ( $shapes as $shape ) {
			if ( isset( $shape['kind'] ) && 'arrow' === $shape['kind'] ) {
				$points = isset( $shape['points'] ) ? $shape['points'] : array();
				if ( count( $points ) >= 2 ) {
					$from = $points[0];
					$to   = $points[ count( $points ) - 1 ];
					return array(
						'from'       => array(
							'x' => isset( $from['x'] ) ? (float) $from['x'] : 0.0,
							'y' => isset( $from['y'] ) ? (float) $from['y'] : 0.0,
						),
						'to'         => array(
							'x' => isset( $to['x'] ) ? (float) $to['x'] : 0.0,
							'y' => isset( $to['y'] ) ? (float) $to['y'] : 0.0,
						),
						'normalized' => true,
					);
				}
			}
		}
		return array();
	}

	/**
	 * Convert shape descriptors to a flat list of PDF rect rows.
	 *
	 * @param array $shapes Shape descriptors.
	 * @return array
	 */
	private function shapes_to_pdf_rects( array $shapes ) {
		$rects = array();
		foreach ( $shapes as $shape ) {
			$kind = isset( $shape['kind'] ) ? $shape['kind'] : '';
			if ( 'rect' !== $kind ) {
				continue;
			}
			$points = isset( $shape['points'] ) ? $shape['points'] : array();
			if ( count( $points ) < 2 ) {
				continue;
			}
			$x1      = isset( $points[0]['x'] ) ? (float) $points[0]['x'] : 0.0;
			$y1      = isset( $points[0]['y'] ) ? (float) $points[0]['y'] : 0.0;
			$x2      = isset( $points[1]['x'] ) ? (float) $points[1]['x'] : 0.0;
			$y2      = isset( $points[1]['y'] ) ? (float) $points[1]['y'] : 0.0;
			$rects[] = array(
				'page'   => isset( $shape['page'] ) ? max( 1, (int) $shape['page'] ) : 1,
				'x'      => min( $x1, $x2 ),
				'y'      => min( $y1, $y2 ),
				'width'  => abs( $x2 - $x1 ),
				'height' => abs( $y2 - $y1 ),
			);
		}
		return $rects;
	}

	/**
	 * Build an RGBA PNG mask from polygon/rect shapes and persist it as
	 * a private WordPress attachment. Returns the new attachment ID.
	 *
	 * Convention: alpha=0 inside the marked region (the area to edit),
	 * alpha=255 outside. This matches OpenAI / Gemini / Stability APIs.
	 *
	 * @param WP_MCP_AI_Markup_Request $request Source request.
	 * @param array                    $shapes  Shape descriptors.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function build_mask( WP_MCP_AI_Markup_Request $request, array $shapes ) {
		$target = $request->get_target();
		$width  = isset( $target['width'] ) ? (int) $target['width'] : 0;
		$height = isset( $target['height'] ) ? (int) $target['height'] : 0;
		if ( $width <= 0 || $height <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_markup_no_dimensions', __( 'Cannot rasterize mask without target dimensions.', 'mcp-ai-wpoos' ) );
		}
		if ( $width > WP_MCP_AI_Markup_Validator::MAX_MASK_DIMENSION || $height > WP_MCP_AI_Markup_Validator::MAX_MASK_DIMENSION ) {
			return new WP_Error( 'wp_mcp_ai_markup_dimensions_too_large', __( 'Target dimensions exceed maximum mask size.', 'mcp-ai-wpoos' ) );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'wp_mcp_ai_markup_no_gd', __( 'GD extension is required to rasterize masks.', 'mcp-ai-wpoos' ) );
		}

		$image = imagecreatetruecolor( $width, $height );
		if ( ! $image ) {
			return new WP_Error( 'wp_mcp_ai_markup_image_failed', __( 'Could not create mask image.', 'mcp-ai-wpoos' ) );
		}

		// Enable alpha and start opaque white.
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		$opaque = imagecolorallocatealpha( $image, 255, 255, 255, 0 );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, $opaque );

		// Cut the marked region out (alpha=127 in GD = fully transparent).
		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
		foreach ( $shapes as $shape ) {
			$kind   = isset( $shape['kind'] ) ? $shape['kind'] : '';
			$points = isset( $shape['points'] ) ? $shape['points'] : array();
			if ( empty( $points ) ) {
				continue;
			}
			if ( 'rect' === $kind && count( $points ) >= 2 ) {
				$x1 = isset( $points[0]['x'] ) ? (int) round( $points[0]['x'] ) : 0;
				$y1 = isset( $points[0]['y'] ) ? (int) round( $points[0]['y'] ) : 0;
				$x2 = isset( $points[1]['x'] ) ? (int) round( $points[1]['x'] ) : 0;
				$y2 = isset( $points[1]['y'] ) ? (int) round( $points[1]['y'] ) : 0;
				imagefilledrectangle(
					$image,
					min( $x1, $x2 ),
					min( $y1, $y2 ),
					max( $x1, $x2 ),
					max( $y1, $y2 ),
					$transparent
				);
			} elseif ( 'polygon' === $kind || 'freehand' === $kind ) {
				$flat = array();
				foreach ( $points as $pt ) {
					$flat[] = isset( $pt['x'] ) ? (int) round( $pt['x'] ) : 0;
					$flat[] = isset( $pt['y'] ) ? (int) round( $pt['y'] ) : 0;
				}
				if ( count( $flat ) >= 6 ) {
					imagefilledpolygon( $image, $flat, $transparent );
				}
			} elseif ( 'circle' === $kind && count( $points ) >= 1 ) {
				$cx     = isset( $points[0]['x'] ) ? (int) round( $points[0]['x'] ) : 0;
				$cy     = isset( $points[0]['y'] ) ? (int) round( $points[0]['y'] ) : 0;
				$radius = isset( $shape['radius'] ) ? (int) round( $shape['radius'] ) : 0;
				if ( $radius > 0 ) {
					imagefilledellipse( $image, $cx, $cy, $radius * 2, $radius * 2, $transparent );
				}
			}
		}

		// Capture PNG bytes via output buffer.
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		if ( empty( $png ) ) {
			return new WP_Error( 'wp_mcp_ai_markup_png_failed', __( 'Could not encode mask PNG.', 'mcp-ai-wpoos' ) );
		}

		// Store under uploads in a plugin-private subdirectory with hardening.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_markup_upload_dir', $upload_dir['error'] );
		}
		$private_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-markup';
		if ( ! file_exists( $private_dir ) ) {
			wp_mkdir_p( $private_dir );
			// Hardening: prevent listing and execution.
			$htaccess = $private_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// Best-effort hardening: ignore failures (e.g. read-only host).
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required for .htaccess hardening; WP_Filesystem not available in this execution context.
				@file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
			}
			$index = $private_dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required for directory hardening; WP_Filesystem not available in this execution context.
				@file_put_contents( $index, "<?php // Silence is golden.\n" );
			}
		}

		$filename = 'mask-' . $request->get_request_id() . '.png';
		$path     = $private_dir . '/' . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation required for PNG write; WP_Filesystem not available in this execution context.
		$bytes = file_put_contents( $path, $png );
		if ( false === $bytes ) {
			return new WP_Error( 'wp_mcp_ai_markup_write_failed', __( 'Could not write mask PNG.', 'mcp-ai-wpoos' ) );
		}

		// Insert as a private attachment owned by the requesting user.
		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => sprintf( 'NV oOS markup mask %s', $request->get_request_id() ),
			'post_status'    => 'private',
			'post_author'    => $request->get_user_id(),
		);
		$attach_id  = wp_insert_attachment( $attachment, $path );
		if ( is_wp_error( $attach_id ) || 0 === $attach_id ) {
			return new WP_Error( 'wp_mcp_ai_markup_insert_failed', __( 'Could not register mask as attachment.', 'mcp-ai-wpoos' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attach_id, $path );
		wp_update_attachment_metadata( $attach_id, $metadata );

		// Tag the attachment so cleanup cron knows it's a markup artifact.
		update_post_meta( $attach_id, '_wp_mcp_ai_markup_request_id', $request->get_request_id() );
		update_post_meta( $attach_id, '_wp_mcp_ai_markup_tool_slug', $request->get_tool_slug() );

		return (int) $attach_id;
	}
}
