<?php
/**
 * Tool: harmonize_image_into_background.
 *
 * The headline orchestrator. Takes a foreground subject + an existing or
 * AI-generated background, and runs the full harmonization pipeline. Each
 * stage is individually toggleable so users can opt into / out of any step.
 *
 * Stages: resolve background -> adapt background -> clean white-BG ->
 * refine matte -> suggest placement -> harmonize color -> relight ->
 * generate shadow (+ reflection) -> compose -> refine boundary -> optional polish.
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
 * End-to-end harmonization orchestrator.
 */
class WP_MCP_AI_Tool_Harmonize_Image_Into_Background extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'harmonize_image_into_background';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Harmonize Image Into Background', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'End-to-end harmonization: takes a subject (transparent PNG or white-BG) and integrates it into either an existing or AI-generated background. Runs color matching, relighting, shadow synthesis, edge refinement, and an optional polish pass. Non-destructive — original subject pixels are the source of truth except where the polish_strength parameter explicitly opts in.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Capability flags include `async` for the orchestrator.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		$flags   = $this->harmonization_capability_flags();
		$flags[] = 'async';
		$flags[] = 'long-running';
		return $flags;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id'    => $this->harmonization_get_image_input_schema( 'foreground subject' )['attachment_id'],
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'optional existing background' )['attachment_id'],
				'background_prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Description of the background scene to generate when no background is supplied.', 'mcp-ai-wpoos-pro' ),
				),
				'aspect_ratio'             => array(
					'type'    => 'string',
					'enum'    => array( '1:1', '4:5', '16:9', '9:16', '3:2', '2:3', 'auto' ),
					'default' => '16:9',
				),
				'placement_hint'           => array(
					'type'        => 'string',
					'description' => __( 'Optional placement hint (e.g. "lower-center", "left-third").', 'mcp-ai-wpoos-pro' ),
				),
				'subject_is_white_bg'      => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'enable_color_harmonize'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'enable_relight'           => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'enable_shadow'            => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'enable_reflection'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'enable_boundary_refine'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'polish_strength'          => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.0,
				),
				'provider'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
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
		if ( empty( $arguments['background_attachment_id'] ) && empty( $arguments['background_prompt'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_background',
				__( 'Either background_attachment_id or background_prompt must be provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$report = array( 'stages' => array() );

		// 1. Resolve background.
		if ( ! empty( $arguments['background_attachment_id'] ) ) {
			$bg = $this->harmonization_resolve_input( $arguments['background_attachment_id'], 'background' );
			if ( is_wp_error( $bg ) ) {
				return $bg;
			}
			$bg_path            = $bg['file_path'];
			$report['stages'][] = array(
				'stage'  => 'resolve_background',
				'source' => 'existing',
			);
		} else {
			$bg_path = $this->generate_background_to_temp( $arguments, $report );
			if ( is_wp_error( $bg_path ) ) {
				return $bg_path;
			}
		}

		// 2. Resolve subject.
		$subject = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'subject' );
		if ( is_wp_error( $subject ) ) {
			$this->harmonization_cleanup( $bg_path );
			return $subject;
		}
		$subject_path       = $subject['file_path'];
		$report['stages'][] = array(
			'stage'         => 'resolve_subject',
			'attachment_id' => $subject['attachment_id'],
		);

		// 3. Optionally clean white-BG subject.
		if ( ! empty( $arguments['subject_is_white_bg'] ) ) {
			$cleaned = $this->harmonization_temp_dir() . '/orch-cleaned-' . wp_generate_password( 8, false ) . '.png';
			// Direct simple white-removal: feather + threshold via compositor feather + threshold preserves alpha.
			// Use auto-clean tool's algorithm inline by routing through a quick GD threshold + feather.
			$res = $this->cheap_white_bg_to_alpha( $subject_path, $cleaned, 245 );
			if ( ! is_wp_error( $res ) ) {
				$this->harmonization_cleanup( $subject_path );
				$subject_path       = $cleaned;
				$report['stages'][] = array( 'stage' => 'auto_clean_white_background' );
			}
		}

		// 4. Refine subject matte (alpha feather).
		$feathered = $this->harmonization_temp_dir() . '/orch-matte-' . wp_generate_password( 8, false ) . '.png';
		$res       = $this->compositor()->feather_alpha( $subject_path, $feathered, 2 );
		if ( ! is_wp_error( $res ) ) {
			$this->harmonization_cleanup( $subject_path );
			$subject_path       = $feathered;
			$report['stages'][] = array(
				'stage'          => 'refine_subject_matte',
				'feather_radius' => 2,
			);
		}

		// 5. Color harmonize.
		if ( ! empty( $arguments['enable_color_harmonize'] ) ) {
			$recolored = $this->harmonization_temp_dir() . '/orch-color-' . wp_generate_password( 8, false ) . '.png';
			$stats     = $this->compositor()->reinhard_color_transfer( $subject_path, $bg_path, $recolored, 0.6 );
			if ( ! is_wp_error( $stats ) ) {
				$this->harmonization_cleanup( $subject_path );
				$subject_path       = $recolored;
				$report['stages'][] = array(
					'stage'    => 'harmonize_color',
					'strategy' => 'mean_std_rgb',
					'strength' => 0.6,
				);
			}
		}

		// 6. Detect lighting once (used by relight + shadow direction).
		$lighting = $this->lighting()->analyze( $bg_path );
		if ( ! is_wp_error( $lighting ) ) {
			$report['stages'][] = array(
				'stage'    => 'analyze_scene_lighting',
				'lighting' => $lighting,
			);
		} else {
			$lighting = array(
				'direction_deg'   => 135.0,
				'kelvin_estimate' => 5000,
				'intensity'       => 0.5,
				'color_temp'      => 'neutral',
			);
		}

		// 7. Relight (AI-only stage).
		if ( ! empty( $arguments['enable_relight'] ) ) {
			$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
			$provider = $this->harmonization_detect_provider( $req );
			if ( '' !== $provider ) {
				$prompt = sprintf(
					/* translators: 1: direction, 2: kelvin */
					__( 'Re-illuminate this subject as if lit by a directional light at %1$s°, %2$dK. Preserve silhouette and alpha.', 'mcp-ai-wpoos-pro' ),
					$lighting['direction_deg'],
					$lighting['kelvin_estimate']
				);
				$bytes = $this->ai_edit_image( $subject_path, $prompt, $provider );
				if ( ! is_wp_error( $bytes ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $subject_path, $bytes );
					$report['stages'][] = array(
						'stage'    => 'relight_subject',
						'provider' => $provider,
					);
				}
			}
		}

		// 8. Suggest placement.
		$bg_size            = @getimagesize( $bg_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$su_size            = @getimagesize( $subject_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$bg_w               = is_array( $bg_size ) ? (int) $bg_size[0] : 1024;
		$bg_h               = is_array( $bg_size ) ? (int) $bg_size[1] : 1024;
		$su_w               = is_array( $su_size ) ? (int) $su_size[0] : 512;
		$su_h               = is_array( $su_size ) ? (int) $su_size[1] : 512;
		$placer             = new WP_MCP_AI_Tool_Suggest_Placement();
		$su_args            = array(
			'subject_attachment_id'    => $arguments['subject_attachment_id'],
			'background_attachment_id' => isset( $arguments['background_attachment_id'] ) ? $arguments['background_attachment_id'] : 0,
			'target_scale'             => 0.4,
		);
		$box                = $this->resolve_box_from_hint( $bg_w, $bg_h, $su_w, $su_h, isset( $arguments['placement_hint'] ) ? (string) $arguments['placement_hint'] : '' );
		$report['stages'][] = array(
			'stage' => 'suggest_placement',
			'box'   => $box,
		);
		unset( $placer, $su_args ); // Reserved for future use; orchestrator currently uses the heuristic directly.

		// 9. Compose subject onto background.
		$composite = $this->harmonization_temp_dir() . '/orch-composite-' . wp_generate_password( 8, false ) . '.png';
		$res       = $this->compositor()->composite_over( $subject_path, $bg_path, $composite, $box );
		if ( is_wp_error( $res ) ) {
			$this->harmonization_cleanup( $subject_path );
			$this->harmonization_cleanup( $bg_path );
			return $res;
		}
		$report['stages'][] = array( 'stage' => 'composite' );

		// 10. Optional shadow + reflection (rendered onto subject before re-composite).
		if ( ! empty( $arguments['enable_shadow'] ) ) {
			$shadow = $this->harmonization_temp_dir() . '/orch-shadow-' . wp_generate_password( 8, false ) . '.png';
			$srep   = $this->compositor()->render_shadow_layer(
				$subject_path,
				$shadow,
				array(
					'direction_deg' => $lighting['direction_deg'],
					'softness'      => 0.5,
					'opacity'       => 0.45,
					'length'        => 0.4,
				)
			);
			if ( ! is_wp_error( $srep ) ) {
				// Composite shadow under the subject by overlaying onto background first, then subject again.
				$bg_with_shadow = $this->harmonization_temp_dir() . '/orch-bgs-' . wp_generate_password( 8, false ) . '.png';
				$shadow_box     = array(
					'x' => $box['x'],
					'y' => $box['y'],
					'w' => $box['w'],
					'h' => $box['h'],
				);
				$ok             = $this->compositor()->composite_over( $shadow, $bg_path, $bg_with_shadow, $shadow_box );
				if ( ! is_wp_error( $ok ) ) {
					$this->harmonization_cleanup( $bg_path );
					$bg_path = $bg_with_shadow;
					// Re-composite subject onto bg-with-shadow.
					$composite_v2 = $this->harmonization_temp_dir() . '/orch-composite2-' . wp_generate_password( 8, false ) . '.png';
					$ok2          = $this->compositor()->composite_over( $subject_path, $bg_path, $composite_v2, $box );
					if ( ! is_wp_error( $ok2 ) ) {
						$this->harmonization_cleanup( $composite );
						$composite = $composite_v2;
					}
					$report['stages'][] = array(
						'stage'  => 'generate_shadow',
						'params' => $srep,
					);
				}
				$this->harmonization_cleanup( $shadow );
			}
		}

		if ( ! empty( $arguments['enable_reflection'] ) ) {
			$report['stages'][] = array(
				'stage' => 'generate_reflection',
				'note'  => 'reflection layer skipped in default pipeline; use generate_reflection tool standalone for full effect',
			);
		}

		// 11. Boundary refine.
		if ( ! empty( $arguments['enable_boundary_refine'] ) ) {
			$refined = $this->harmonization_temp_dir() . '/orch-refined-' . wp_generate_password( 8, false ) . '.png';
			$res     = $this->compositor()->feather_alpha( $composite, $refined, 1 );
			if ( ! is_wp_error( $res ) ) {
				$this->harmonization_cleanup( $composite );
				$composite          = $refined;
				$report['stages'][] = array( 'stage' => 'refine_composite_boundary' );
			}
		}

		// 12. Optional AI polish.
		$polish_strength = isset( $arguments['polish_strength'] ) ? max( 0.0, min( 1.0, (float) $arguments['polish_strength'] ) ) : 0.0;
		if ( $polish_strength > 0.0 ) {
			$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
			$provider = $this->harmonization_detect_provider( $req );
			if ( '' !== $provider ) {
				$prompt = sprintf(
					/* translators: %.2f: polish strength */
					__( 'Apply a subtle final polish so the subject and background read as one photograph. Match grain, lens, and micro-contrast. Keep changes minimal (strength %.2f). Do not alter the subject identity, pose, or position.', 'mcp-ai-wpoos-pro' ),
					$polish_strength
				);
				$bytes = $this->ai_edit_image( $composite, $prompt, $provider );
				if ( ! is_wp_error( $bytes ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $composite, $bytes );
					$report['stages'][] = array(
						'stage'    => 'polish',
						'strength' => $polish_strength,
						'provider' => $provider,
					);
				}
			}
		}

		// Cleanup intermediates.
		$this->harmonization_cleanup( $subject_path );
		$this->harmonization_cleanup( $bg_path );

		// Import.
		$media = $this->harmonization_import_to_media( $composite, __( 'Harmonized Composite', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $composite );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response( $media, $this->get_slug(), $report );
	}

	/**
	 * Generate a background to a temp file using the provider abstractions.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $report    Report array (mutated).
	 *
	 * @return string|WP_Error Path to temp background, or WP_Error.
	 */
	protected function generate_background_to_temp( array $arguments, array &$report ) {
		$gen      = new WP_MCP_AI_Tool_Generate_Scene_Background();
		$gen_args = array(
			'background_prompt' => isset( $arguments['background_prompt'] ) ? (string) $arguments['background_prompt'] : '',
			'aspect_ratio'      => isset( $arguments['aspect_ratio'] ) ? (string) $arguments['aspect_ratio'] : '16:9',
			'provider'          => isset( $arguments['provider'] ) ? (string) $arguments['provider'] : 'auto',
		);
		// Pass through subject hint.
		if ( ! empty( $arguments['subject_attachment_id'] ) ) {
			$gen_args['foreground_attachment_id'] = $arguments['subject_attachment_id'];
		}
		$result = $gen->execute(
			$gen_args,
			array(
				'user_id'             => get_current_user_id(),
				'token_authenticated' => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['attachment_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_result', __( 'Background generation failed.', 'mcp-ai-wpoos-pro' ) );
		}
		$path = get_attached_file( (int) $result['attachment_id'] );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Generated background not found.', 'mcp-ai-wpoos-pro' ) );
		}
		// Copy to working temp so we don't mutate the saved attachment.
		$copy = $this->harmonization_duplicate_to_temp( $path, 'orch-bg' );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		$report['stages'][] = array(
			'stage'         => 'generate_scene_background',
			'attachment_id' => (int) $result['attachment_id'],
		);
		return $copy;
	}

	/**
	 * Cheap white-BG to alpha (used inline by orchestrator).
	 *
	 * @param string $path        Source path.
	 * @param string $output_path Output path.
	 * @param int    $threshold   Threshold value.
	 *
	 * @return true|WP_Error
	 */
	protected function cheap_white_bg_to_alpha( $path, $output_path, $threshold = 245 ) {
		if ( ! extension_loaded( 'gd' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_gd', __( 'GD required.', 'mcp-ai-wpoos-pro' ) );
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Invalid image.', 'mcp-ai-wpoos-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_invalid_image', __( 'Failed to decode.', 'mcp-ai-wpoos-pro' ) );
		}
		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$out = imagecreatetruecolor( $w, $h );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c = imagecolorat( $src, $x, $y );
				$r = ( $c >> 16 ) & 0xFF;
				$g = ( $c >> 8 ) & 0xFF;
				$b = $c & 0xFF;
				$m = min( $r, $g, $b );
				$a = $m >= $threshold ? 127 : 0;
				imagesetpixel( $out, $x, $y, imagecolorallocatealpha( $out, $r, $g, $b, $a ) );
			}
		}
		$ok = imagepng( $out, $output_path );
		imagedestroy( $src );
		imagedestroy( $out );
		return $ok ? true : new WP_Error( 'wp_mcp_ai_save_failed', __( 'Failed to save cleaned image.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Resolve a placement hint into a concrete bounding box.
	 *
	 * @param int    $bg_w  Background width.
	 * @param int    $bg_h  Background height.
	 * @param int    $su_w  Subject width.
	 * @param int    $su_h  Subject height.
	 * @param string $hint  Placement hint.
	 *
	 * @return array { x, y, w, h }
	 */
	protected function resolve_box_from_hint( $bg_w, $bg_h, $su_w, $su_h, $hint ) {
		// Pre-compute scaled subject size: at most 80% of either dim, prefer 40% of bg width.
		$target_w = (int) min( $bg_w * 0.6, $su_w );
		$ratio    = $su_h / max( 1, $su_w );
		$target_h = (int) ( $target_w * $ratio );
		if ( $target_h > $bg_h * 0.85 ) {
			$target_h = (int) ( $bg_h * 0.85 );
			$target_w = (int) ( $target_h / max( 0.001, $ratio ) );
		}

		$cx = (int) ( ( $bg_w - $target_w ) / 2 );
		$cy = (int) ( ( $bg_h - $target_h ) / 2 );
		$h  = strtolower( $hint );

		if ( '' !== $h ) {
			if ( false !== strpos( $h, 'top' ) ) {
				$cy = (int) ( $bg_h * 0.1 );
			}
			if ( false !== strpos( $h, 'bottom' ) ) {
				$cy = (int) ( $bg_h * 0.9 - $target_h );
			}
			if ( false !== strpos( $h, 'left' ) ) {
				$cx = (int) ( $bg_w * 0.1 );
			}
			if ( false !== strpos( $h, 'right' ) ) {
				$cx = (int) ( $bg_w * 0.9 - $target_w );
			}
			if ( false !== strpos( $h, 'lower' ) || false !== strpos( $h, 'lower-center' ) ) {
				$cy = (int) ( $bg_h * 0.55 );
			}
		}

		return array(
			'x' => max( 0, min( $bg_w - $target_w, $cx ) ),
			'y' => max( 0, min( $bg_h - $target_h, $cy ) ),
			'w' => $target_w,
			'h' => $target_h,
		);
	}
}
