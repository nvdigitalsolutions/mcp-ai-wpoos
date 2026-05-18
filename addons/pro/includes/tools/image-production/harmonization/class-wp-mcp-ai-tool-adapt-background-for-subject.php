<?php
/**
 * Tool: adapt_background_for_subject.
 *
 * Modifies an existing background so a foreground will read clearly. Supports
 * deterministic operations (blur, brightness/contrast retargeting, vignette,
 * desaturation) and an optional AI inpaint pass to clear a "landing zone".
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
 * Adapt an existing background for legibility.
 */
class WP_MCP_AI_Tool_Adapt_Background_For_Subject extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'adapt_background_for_subject';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Adapt Background for Subject', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adjust an existing background image so a foreground subject reads clearly: targeted blur, brightness/contrast retargeting, vignette, desaturation, or AI inpaint of a landing zone. Non-destructive — saves a new attachment.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'background image' )['attachment_id'],
				'operations'               => array(
					'type'        => 'array',
					'description' => __( 'Operations to apply in order. Each is an object with a "type" key and operation-specific options.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => array(
							'type'      => array(
								'type' => 'string',
								'enum' => array( 'blur', 'brightness', 'contrast', 'vignette', 'desaturate', 'ai_inpaint_zone' ),
							),
							'amount'    => array( 'type' => 'number' ),
							'zone_hint' => array( 'type' => 'string' ),
						),
					),
				),
				'provider'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
				),
			),
			'required'             => array( 'background_attachment_id' ),
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
		$bg_input = isset( $arguments['background_attachment_id'] ) ? $arguments['background_attachment_id'] : '';
		$resolved = $this->harmonization_resolve_input( $bg_input, 'background' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$bg_path = $resolved['file_path'];

		$ops = isset( $arguments['operations'] ) && is_array( $arguments['operations'] ) ? $arguments['operations'] : array(
			array(
				'type'   => 'blur',
				'amount' => 0.4,
			),
			array(
				'type'   => 'brightness',
				'amount' => -0.05,
			),
		);

		$applied = array();
		foreach ( $ops as $op ) {
			if ( ! is_array( $op ) || empty( $op['type'] ) ) {
				continue;
			}
			$type = sanitize_key( $op['type'] );
			$amt  = isset( $op['amount'] ) ? (float) $op['amount'] : 0.5;

			$result = $this->apply_operation( $bg_path, $type, $amt, $op, $arguments );
			if ( is_wp_error( $result ) ) {
				$this->harmonization_cleanup( $bg_path );
				return $result;
			}
			$applied[] = array(
				'type'   => $type,
				'amount' => $amt,
			);
		}

		$media = $this->harmonization_import_to_media(
			$bg_path,
			__( 'Adapted Background', 'mcp-ai-wpoos-pro' ),
			$user_id
		);
		$this->harmonization_cleanup( $bg_path );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array( 'operations_applied' => $applied )
		);
	}

	/**
	 * Apply a single operation in-place to the working file.
	 *
	 * @param string $path      Working file path.
	 * @param string $type      Operation type.
	 * @param float  $amount    Operation amount.
	 * @param array  $op        Full operation array.
	 * @param array  $arguments Tool arguments (used for AI inpaint).
	 *
	 * @return true|WP_Error
	 */
	protected function apply_operation( $path, $type, $amount, array $op, array $arguments ) {
		if ( in_array( $type, array( 'blur', 'brightness', 'contrast', 'vignette', 'desaturate' ), true ) ) {
			return $this->apply_gd_operation( $path, $type, $amount );
		}

		if ( 'ai_inpaint_zone' === $type ) {
			return $this->apply_ai_inpaint_zone( $path, isset( $op['zone_hint'] ) ? (string) $op['zone_hint'] : '', $arguments );
		}

		return new WP_Error( 'wp_mcp_ai_unknown_operation', __( 'Unknown operation type.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Apply deterministic GD operations.
	 *
	 * @param string $path   Path.
	 * @param string $type   Operation type.
	 * @param float  $amount Amount.
	 *
	 * @return true|WP_Error
	 */
	protected function apply_gd_operation( $path, $type, $amount ) {
		if ( ! extension_loaded( 'gd' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD extension required.', 'mcp-ai-wpoos-pro' ) );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Invalid image.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( (int) $info[2] ) {
			case IMAGETYPE_PNG:
				$img = imagecreatefrompng( $path );
				break;
			case IMAGETYPE_JPEG:
				$img = imagecreatefromjpeg( $path );
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

		switch ( $type ) {
			case 'blur':
				$passes = max( 1, min( 10, (int) round( $amount * 10 ) ) );
				for ( $i = 0; $i < $passes; $i++ ) {
					imagefilter( $img, IMG_FILTER_GAUSSIAN_BLUR );
				}
				break;
			case 'brightness':
				imagefilter( $img, IMG_FILTER_BRIGHTNESS, (int) round( $amount * 100 ) );
				break;
			case 'contrast':
				// Note: GD contrast is inverted (-100 = increase contrast).
				imagefilter( $img, IMG_FILTER_CONTRAST, (int) round( -$amount * 100 ) );
				break;
			case 'desaturate':
				imagefilter( $img, IMG_FILTER_GRAYSCALE );
				break;
			case 'vignette':
				$this->apply_vignette( $img, max( 0.0, min( 1.0, $amount ) ) );
				break;
		}

		$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$saved = false;
		if ( 'jpg' === $ext || 'jpeg' === $ext ) {
			$saved = imagejpeg( $img, $path, 92 );
		} elseif ( 'webp' === $ext && function_exists( 'imagewebp' ) ) {
			$saved = imagewebp( $img, $path, 92 );
		} else {
			imagesavealpha( $img, true );
			$saved = imagepng( $img, $path );
		}
		imagedestroy( $img );

		if ( ! $saved ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save adapted image.', 'mcp-ai-wpoos-pro' ) );
		}
		return true;
	}

	/**
	 * Apply a circular darkening vignette in place.
	 *
	 * @param resource|GdImage $img    Image resource.
	 * @param float            $amount 0..1 strength.
	 */
	protected function apply_vignette( $img, $amount ) {
		$w        = imagesx( $img );
		$h        = imagesy( $img );
		$cx       = $w / 2.0;
		$cy       = $h / 2.0;
		$max_dist = sqrt( $cx * $cx + $cy * $cy );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$dx     = $x - $cx;
				$dy     = $y - $cy;
				$dist   = sqrt( $dx * $dx + $dy * $dy );
				$factor = max( 0.0, ( $dist / $max_dist ) - 0.4 ) / 0.6;
				$dim    = 1.0 - ( $factor * $amount );
				if ( $dim >= 0.999 ) {
					continue;
				}
				$c = imagecolorat( $img, $x, $y );
				$r = (int) ( ( ( $c >> 16 ) & 0xFF ) * $dim );
				$g = (int) ( ( ( $c >> 8 ) & 0xFF ) * $dim );
				$b = (int) ( ( $c & 0xFF ) * $dim );
				$a = ( $c >> 24 ) & 0x7F;
				imagesetpixel( $img, $x, $y, imagecolorallocatealpha( $img, $r, $g, $b, $a ) );
			}
		}
	}

	/**
	 * Use AI to inpaint a clear landing zone described by a hint.
	 *
	 * @param string $path      Working file path.
	 * @param string $zone_hint Description of the zone to clear (e.g. "lower-center kitchen counter").
	 * @param array  $arguments Tool arguments.
	 *
	 * @return true|WP_Error
	 */
	protected function apply_ai_inpaint_zone( $path, $zone_hint, array $arguments ) {
		$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$provider = $this->harmonization_detect_provider( $req );
		if ( '' === $provider ) {
			return new WP_Error( 'wp_mcp_ai_no_provider', __( 'No AI provider configured for inpainting.', 'mcp-ai-wpoos-pro' ) );
		}

		$prompt = sprintf(
			/* translators: %s: zone hint */
			__( 'Subtly declutter this image so the area described as "%s" is clear, well-lit, and free of distracting details. Preserve overall composition, palette, and lighting; only quiet the busy elements in that zone.', 'mcp-ai-wpoos-pro' ),
			$zone_hint
		);

		$bytes = $this->ai_edit_image( $path, $prompt, $provider );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $path, $bytes ) ) {
			return new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save AI-inpainted image.', 'mcp-ai-wpoos-pro' ) );
		}
		return true;
	}
}
