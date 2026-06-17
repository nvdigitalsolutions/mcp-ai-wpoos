<?php
/**
 * Tool: apply_watermark_batch
 *
 * Applies watermark to a batch of images. Supports dry_run mode.
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
 * Apply watermark batch tool.
 */
class WP_MCP_AI_Tool_Apply_Watermark_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'The Apply Watermark Batch tool requires the Image Production Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_watermark_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Watermark Batch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Applies watermark to a batch of images. Supports dry_run mode for preview without applying.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_ids'     => array(
					'type'        => 'array',
					'description' => __( 'Array of attachment IDs to watermark.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'watermark_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of watermark: text or logo.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'text', 'logo' ),
					'default'     => 'text',
				),
				'watermark_text'     => array(
					'type'        => 'string',
					'description' => __( 'Text to use for text watermark. Defaults to site name.', 'mcp-ai-wpoos-pro' ),
				),
				'watermark_position' => array(
					'type'        => 'string',
					'description' => __( 'Position of the watermark on the image.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'bottom-right', 'center', 'tiled' ),
					'default'     => 'bottom-right',
				),
				'opacity'            => array(
					'type'        => 'integer',
					'description' => __( 'Watermark opacity (0-100). Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'dry_run'            => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview what would be watermarked without applying. Default: true for safety.', 'mcp-ai-wpoos-pro' ),
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
	 * Get the site logo attachment ID if available.
	 *
	 * @return int|null Attachment ID or null.
	 */
	private function get_site_logo_id() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			return absint( $custom_logo_id );
		}
		return null;
	}

	/**
	 * Get the default watermark text (site name).
	 *
	 * @return string
	 */
	private function get_default_watermark_text() {
		return get_bloginfo( 'name' );
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
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Watermark results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Parse arguments with sanitization.
		$attachment_ids     = isset( $arguments['attachment_ids'] ) ? (array) $arguments['attachment_ids'] : array();
		$watermark_type     = isset( $arguments['watermark_type'] ) ? sanitize_text_field( $arguments['watermark_type'] ) : 'text';
		$watermark_text     = isset( $arguments['watermark_text'] ) ? sanitize_text_field( $arguments['watermark_text'] ) : '';
		$watermark_position = isset( $arguments['watermark_position'] ) ? sanitize_text_field( $arguments['watermark_position'] ) : 'bottom-right';
		$opacity            = isset( $arguments['opacity'] ) ? absint( $arguments['opacity'] ) : 50;
		$dry_run            = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Validate values.
		if ( ! in_array( $watermark_type, array( 'text', 'logo' ), true ) ) {
			$watermark_type = 'text';
		}
		if ( ! in_array( $watermark_position, array( 'bottom-right', 'center', 'tiled' ), true ) ) {
			$watermark_position = 'bottom-right';
		}
		$opacity = max( 0, min( 100, $opacity ) );

		// Set default watermark text.
		if ( '' === $watermark_text && 'text' === $watermark_type ) {
			$watermark_text = $this->get_default_watermark_text();
		}

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

		// Check GD availability if not dry run.
		if ( ! $dry_run && ! $this->has_gd_library() ) {
			return array(
				'success' => false,
				'error'   => __( 'PHP GD extension is required for watermarking. Please enable the GD extension on your server.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check logo if needed.
		$logo_id = null;
		if ( 'logo' === $watermark_type ) {
			$logo_id = $this->get_site_logo_id();
			if ( ! $logo_id && ! $dry_run ) {
				return array(
					'success' => false,
					'error'   => __( 'No site logo found. Please set a custom logo in Appearance → Customize → Site Identity.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		$results   = array();
		$processed = 0;
		$skipped   = 0;
		$errors    = array();

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

			$entry = array(
				'id'        => $attachment_id,
				'title'     => esc_html( $post->post_title ),
				'file'      => esc_html( basename( $file_path ) ),
				'mime_type' => esc_html( $post->post_mime_type ),
				'watermark' => array(
					'type'     => $watermark_type,
					'position' => $watermark_position,
					'opacity'  => $opacity,
				),
				'dry_run'   => $dry_run,
			);

			if ( 'text' === $watermark_type ) {
				$entry['watermark']['text'] = $watermark_text;
			}

			if ( $dry_run ) {
				// Preview mode.
				$entry['status'] = 'preview';
				$entry['action'] = sprintf(
					/* translators: 1: watermark type, 2: position */
					__( 'Would apply %1$s watermark at %2$s.', 'mcp-ai-wpoos-pro' ),
					$watermark_type,
					$watermark_position
				);
				++$processed;
			} else {
				// Apply watermark.
				$result = $this->apply_watermark( $attachment_id, $file_path, $watermark_type, $watermark_text, $logo_id, $watermark_position, $opacity );

				if ( is_wp_error( $result ) ) {
					$entry['status'] = 'error';
					$entry['error']  = $result->get_error_message();
					$errors[]        = $entry;
					++$skipped;
				} else {
					$entry['status'] = 'applied';
					// Mark as watermarked.
					update_post_meta( $attachment_id, '_is_watermarked', current_time( 'mysql' ) );
					update_post_meta( $attachment_id, '_watermark_type', $watermark_type );
					update_post_meta( $attachment_id, '_watermark_position', $watermark_position );
					++$processed;

					/**
					 * Fires after a watermark is applied to an attachment.
					 *
					 * @since 2.9.0
					 *
					 * @param int    $attachment_id      The attachment post ID.
					 * @param string $watermark_type     The watermark type (text or logo).
					 * @param string $watermark_position The watermark position.
					 */
					do_action( 'wp_mcp_ai_image_after_watermark_applied', $attachment_id, $watermark_type, $watermark_position );
				}
			}

			$results[] = $entry;
		}

		return array(
			'success'      => true,
			'message'      => $dry_run
				? sprintf(
					/* translators: %d: number of images previewed */
					__( 'Previewed watermark for %d image(s). No changes were applied.', 'mcp-ai-wpoos-pro' ),
					$processed
				)
				: sprintf(
					/* translators: %d: number of images watermarked */
					__( 'Applied watermark to %d image(s).', 'mcp-ai-wpoos-pro' ),
					$processed
				),
			'dry_run'      => $dry_run,
			'total'        => count( $attachment_ids ),
			'processed'    => $processed,
			'skipped'      => $skipped,
			'errors_count' => count( $errors ),
			'watermark'    => array(
				'type'     => $watermark_type,
				'position' => $watermark_position,
				'opacity'  => $opacity,
			),
			'results'      => $results,
		);
	}

	/**
	 * Apply watermark to an image file.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $file_path     Path to the image file.
	 * @param string   $type          Watermark type (text or logo).
	 * @param string   $text          Watermark text (text type only).
	 * @param int|null $logo_id       Logo attachment ID (logo type only).
	 * @param string   $position      Watermark position.
	 * @param int      $opacity       Opacity (0-100).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function apply_watermark( $attachment_id, $file_path, $type, $text, $logo_id, $position, $opacity ) {
		if ( ! $this->has_gd_library() ) {
			return new WP_Error(
				'wp_mcp_ai_no_gd',
				__( 'PHP GD extension is not available. Cannot apply watermark.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Create image resource from file.
		$mime_type = get_post_mime_type( $attachment_id );
		$source    = null;

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

		if ( ! $source ) {
			return new WP_Error(
				'wp_mcp_ai_unsupported_format',
				sprintf(
					/* translators: %s: MIME type */
					__( 'Unsupported image format: %s.', 'mcp-ai-wpoos-pro' ),
					$mime_type
				)
			);
		}

		$width  = imagesx( $source );
		$height = imagesy( $source );

		// Calculate opacity as alpha (0-127, where 0 is fully opaque).
		$alpha = (int) round( 127 * ( 1 - $opacity / 100 ) );

		/**
		 * Filter: allow custom watermark rendering logic.
		 *
		 * If a filter returns a non-null value, the built-in GD rendering
		 * is bypassed entirely. Return `true` to indicate success or
		 * `WP_Error` to indicate failure.
		 *
		 * @since 2.9.0
		 *
		 * @param null       $rendered Filter return value.
		 * @param resource   $source   GD image resource.
		 * @param int        $width    Image width.
		 * @param int        $height   Image height.
		 * @param array      $args     Watermark arguments.
		 * @return true|WP_Error|null
		 */
		$custom_render = apply_filters(
			'wp_mcp_ai_image_watermark_render',
			null,
			$source,
			$width,
			$height,
			array(
				'type'     => $type,
				'text'     => $text,
				'logo_id'  => $logo_id,
				'position' => $position,
				'opacity'  => $opacity,
				'alpha'    => $alpha,
			)
		);

		if ( null !== $custom_render ) {
			if ( is_wp_error( $custom_render ) ) {
				imagedestroy( $source );
				return $custom_render;
			}
			// Save and return.
			return $this->save_watermarked_image( $source, $file_path, $mime_type );
		}

		// Built-in rendering for text watermark.
		if ( 'text' === $type ) {
			$font_size = (int) max( 10, min( $width, $height ) * 0.04 );
			$font      = 5; // GD built-in font.

			$text_box = imagettfbbox( $font_size, 0, $font, $text );
			if ( false === $text_box ) {
				// Fallback: use built-in font sizing.
				$text_width  = imagefontwidth( $font ) * strlen( $text );
				$text_height = imagefontheight( $font );
			} else {
				$text_width  = abs( $text_box[2] - $text_box[0] ) + 10;
				$text_height = abs( $text_box[7] - $text_box[1] ) + 10;
			}

			// Calculate position.
			switch ( $position ) {
				case 'center':
					$x = (int) ( ( $width - $text_width ) / 2 );
					$y = (int) ( ( $height - $text_height ) / 2 );
					break;
				case 'tiled':
					// Tiled mode - draw watermark multiple times.
					$color = imagecolorallocatealpha( $source, 255, 255, 255, $alpha );
					for ( $ty = 0; $ty < $height; $ty += $text_height + 40 ) {
						for ( $tx = 0; $tx < $width; $tx += $text_width + 80 ) {
							imagestring( $source, $font, $tx, $ty, $text, $color );
						}
					}
					return $this->save_watermarked_image( $source, $file_path, $mime_type );
				case 'bottom-right':
				default:
					$x = $width - $text_width - 20;
					$y = $height - $text_height - 20;
					break;
			}

			$color = imagecolorallocatealpha( $source, 255, 255, 255, $alpha );
			imagestring( $source, $font, $x, $y, $text, $color );
		}

		// Built-in rendering for logo watermark.
		if ( 'logo' === $type && $logo_id ) {
			$logo_path = get_attached_file( $logo_id );
			if ( $logo_path && file_exists( $logo_path ) ) {
				$logo      = null;
				$logo_mime = get_post_mime_type( $logo_id );

				switch ( $logo_mime ) {
					case 'image/png':
						$logo = imagecreatefrompng( $logo_path );
						break;
					case 'image/jpeg':
						$logo = imagecreatefromjpeg( $logo_path );
						break;
				}

				if ( $logo ) {
					$logo_width  = imagesx( $logo );
					$logo_height = imagesy( $logo );

					// Scale logo to max 20% of image width.
					$max_logo_width = (int) ( $width * 0.2 );
					if ( $logo_width > $max_logo_width ) {
						$ratio           = $max_logo_width / $logo_width;
						$new_logo_width  = $max_logo_width;
						$new_logo_height = (int) ( $logo_height * $ratio );
						$scaled          = imagecreatetruecolor( $new_logo_width, $new_logo_height );
						imagealphablending( $scaled, false );
						imagesavealpha( $scaled, true );
						imagecopyresampled( $scaled, $logo, 0, 0, 0, 0, $new_logo_width, $new_logo_height, $logo_width, $logo_height );
						imagedestroy( $logo );
						$logo        = $scaled;
						$logo_width  = $new_logo_width;
						$logo_height = $new_logo_height;
					}

					// Calculate position for logo.
					switch ( $position ) {
						case 'center':
							$x = (int) ( ( $width - $logo_width ) / 2 );
							$y = (int) ( ( $height - $logo_height ) / 2 );
							break;
						case 'tiled':
							for ( $ty = 0; $ty < $height; $ty += $logo_height + 40 ) {
								for ( $tx = 0; $tx < $width; $tx += $logo_width + 80 ) {
									imagecopymerge( $source, $logo, $tx, $ty, 0, 0, $logo_width, $logo_height, $opacity );
								}
							}
							imagedestroy( $logo );
							return $this->save_watermarked_image( $source, $file_path, $mime_type );
						case 'bottom-right':
						default:
							$x = $width - $logo_width - 20;
							$y = $height - $logo_height - 20;
							break;
					}

					// Merge logo with opacity.
					imagecopymerge( $source, $logo, $x, $y, 0, 0, $logo_width, $logo_height, $opacity );
					imagedestroy( $logo );
				}
			}
		}

		return $this->save_watermarked_image( $source, $file_path, $mime_type );
	}

	/**
	 * Save the watermarked image to disk and regenerate thumbnails.
	 *
	 * @param resource $source    GD image resource.
	 * @param string   $file_path Original file path.
	 * @param string   $mime_type Image MIME type.
	 * @return true|WP_Error
	 */
	private function save_watermarked_image( $source, $file_path, $mime_type ) {
		// Save over the original file.
		switch ( $mime_type ) {
			case 'image/jpeg':
			case 'image/jpg':
				imagejpeg( $source, $file_path, 90 );
				break;
			case 'image/png':
				imagepng( $source, $file_path, 9 );
				break;
			case 'image/gif':
				imagegif( $source, $file_path );
				break;
			case 'image/webp':
				if ( function_exists( 'imagewebp' ) ) {
					imagewebp( $source, $file_path, 90 );
				}
				break;
		}

		imagedestroy( $source );

		return true;
	}
}
