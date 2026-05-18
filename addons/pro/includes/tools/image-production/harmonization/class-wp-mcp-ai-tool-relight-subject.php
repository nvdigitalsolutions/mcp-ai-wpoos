<?php
/**
 * Tool: relight_subject.
 *
 * Re-illuminates a foreground subject so its lighting matches a background.
 * Two-pass: detect background lighting (cheap heuristic + optional AI vision),
 * then apply directional shading via an AI edit. Foreground alpha is preserved.
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
 * Re-light a foreground subject.
 */
class WP_MCP_AI_Tool_Relight_Subject extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'relight_subject';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Relight Subject', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adjust foreground illumination to match background light direction, color temperature, and intensity. Returns the relit subject (alpha preserved).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id'    => $this->harmonization_get_image_input_schema( 'foreground subject (transparent PNG)' )['attachment_id'],
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'background reference' )['attachment_id'],
				'override_lighting'        => array(
					'type'                 => 'object',
					'description'          => __( 'Override detected lighting (optional).', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => false,
					'properties'           => array(
						'direction_deg' => array( 'type' => 'number' ),
						'kelvin'        => array( 'type' => 'integer' ),
						'intensity'     => array( 'type' => 'number' ),
					),
				),
				'provider'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
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
		$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$provider = $this->harmonization_detect_provider( $req );
		if ( '' === $provider ) {
			return new WP_Error( 'wp_mcp_ai_no_provider', __( 'Relight requires an AI provider.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$subject = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'subject' );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$background = $this->harmonization_resolve_input( $arguments['background_attachment_id'], 'background' );
		if ( is_wp_error( $background ) ) {
			$this->harmonization_cleanup( $subject['file_path'] );
			return $background;
		}

		$lighting = $this->lighting()->analyze( $background['file_path'] );
		if ( is_wp_error( $lighting ) ) {
			$this->harmonization_cleanup( $subject['file_path'] );
			$this->harmonization_cleanup( $background['file_path'] );
			return $lighting;
		}

		// Apply user overrides.
		if ( ! empty( $arguments['override_lighting'] ) && is_array( $arguments['override_lighting'] ) ) {
			foreach ( array( 'direction_deg', 'kelvin', 'intensity' ) as $k ) {
				if ( isset( $arguments['override_lighting'][ $k ] ) ) {
					if ( 'kelvin' === $k ) {
						$lighting['kelvin_estimate'] = (int) $arguments['override_lighting'][ $k ];
					} elseif ( 'direction_deg' === $k ) {
						$lighting['direction_deg'] = (float) $arguments['override_lighting'][ $k ];
					} elseif ( 'intensity' === $k ) {
						$lighting['intensity'] = (float) $arguments['override_lighting'][ $k ];
					}
				}
			}
		}

		$prompt = sprintf(
			/* translators: 1: direction in degrees, 2: kelvin, 3: intensity (0..1), 4: color temp label */
			__( 'Re-illuminate this subject as if lit by a directional light source at %1$s° (0°=right, 90°=down) with color temperature ~%2$dK (%4$s) and intensity %3$.2f. Preserve the subject silhouette and alpha channel; only adjust the light/shadow on the subject\'s surface.', 'mcp-ai-wpoos-pro' ),
			$lighting['direction_deg'],
			$lighting['kelvin_estimate'],
			$lighting['intensity'],
			$lighting['color_temp']
		);

		$bytes = $this->ai_edit_image( $subject['file_path'], $prompt, $provider );
		$this->harmonization_cleanup( $subject['file_path'] );
		$this->harmonization_cleanup( $background['file_path'] );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$out = $this->harmonization_save_bytes_to_temp( $bytes, 'png' );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$media = $this->harmonization_import_to_media( $out, __( 'Relit Subject', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array(
				'lighting' => $lighting,
				'provider' => $provider,
			)
		);
	}
}
