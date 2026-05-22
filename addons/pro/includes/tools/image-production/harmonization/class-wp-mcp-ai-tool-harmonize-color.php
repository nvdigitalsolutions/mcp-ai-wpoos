<?php
/**
 * Tool: harmonize_color.
 *
 * Match the color of a foreground layer to a background using configurable
 * strategies (Reinhard mean/std transfer is the default).
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
 * Match foreground color statistics to a background.
 */
class WP_MCP_AI_Tool_Harmonize_Color extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'harmonize_color';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Harmonize Color', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Match the color statistics of a foreground layer to a background using Reinhard mean/std transfer or AI neural matching. Returns the recolored foreground (alpha preserved).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id'    => $this->harmonization_get_image_input_schema( 'foreground subject (transparent PNG)' )['attachment_id'],
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'background to match' )['attachment_id'],
				'strategy'                 => array(
					'type'    => 'string',
					'enum'    => array( 'mean_std_lab', 'histogram_match', 'ai_neural' ),
					'default' => 'mean_std_lab',
				),
				'strength'                 => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.7,
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
		$subject_input    = $arguments['subject_attachment_id'];
		$background_input = $arguments['background_attachment_id'];
		$strategy         = isset( $arguments['strategy'] ) ? sanitize_key( $arguments['strategy'] ) : 'mean_std_lab';
		$strength         = isset( $arguments['strength'] ) ? max( 0.0, min( 1.0, (float) $arguments['strength'] ) ) : 0.7;

		$subject = $this->harmonization_resolve_input( $subject_input, 'subject' );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}
		$background = $this->harmonization_resolve_input( $background_input, 'background' );
		if ( is_wp_error( $background ) ) {
			$this->harmonization_cleanup( $subject['file_path'] );
			return $background;
		}

		$out_path = $this->harmonization_temp_dir() . '/recolored-' . wp_generate_password( 12, false ) . '.png';

		if ( 'ai_neural' === $strategy ) {
			$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
			$provider = $this->harmonization_detect_provider( $req );
			if ( '' === $provider ) {
				$this->harmonization_cleanup( $subject['file_path'] );
				$this->harmonization_cleanup( $background['file_path'] );
				return new WP_Error( 'wp_mcp_ai_no_provider', __( 'AI neural strategy requires a configured provider.', 'mcp-ai-wpoos-pro' ) );
			}
			$prompt = __( 'Match the color tone, white balance, and exposure of this image to a reference scene. Preserve the alpha channel and the subject silhouette exactly. Adjust only color statistics.', 'mcp-ai-wpoos-pro' );
			$bytes  = $this->ai_edit_image( $subject['file_path'], $prompt, $provider );
			if ( is_wp_error( $bytes ) ) {
				$this->harmonization_cleanup( $subject['file_path'] );
				$this->harmonization_cleanup( $background['file_path'] );
				return $bytes;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $out_path, $bytes );
			$report = array(
				'strategy' => 'ai_neural',
				'provider' => $provider,
			);
		} else {
			// Use Reinhard color transfer (mean_std_lab and histogram_match share this implementation.
			// at the moment; future extension can plug in true LAB or histogram matching).
			$report = $this->compositor()->reinhard_color_transfer(
				$subject['file_path'],
				$background['file_path'],
				$out_path,
				$strength
			);
			if ( is_wp_error( $report ) ) {
				$this->harmonization_cleanup( $subject['file_path'] );
				$this->harmonization_cleanup( $background['file_path'] );
				return $report;
			}
			$report['strategy'] = $strategy;
		}

		$this->harmonization_cleanup( $subject['file_path'] );
		$this->harmonization_cleanup( $background['file_path'] );

		$media = $this->harmonization_import_to_media( $out_path, __( 'Color-Harmonized Subject', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out_path );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response( $media, $this->get_slug(), $report );
	}
}
