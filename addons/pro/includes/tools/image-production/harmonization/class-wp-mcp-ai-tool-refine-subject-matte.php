<?php
/**
 * Tool: refine_subject_matte.
 *
 * Cleans up the alpha channel of a transparent PNG: edge feathering, halo
 * suppression, and (when AI is available) optional micro-edge polish.
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
 * Refine the alpha matte of a transparent subject.
 */
class WP_MCP_AI_Tool_Refine_Subject_Matte extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'refine_subject_matte';
	}

	/**
	 * {\@inheritdoc}
	 *
	 * @return string WordPress capability string.
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Refine Subject Matte', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Clean up the alpha channel of a transparent PNG: edge feathering, halo/fringe suppression, and optional AI polish for hair/fur edges. Non-destructive — saves a new attachment.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id' => $this->harmonization_get_image_input_schema( 'transparent subject PNG' )['attachment_id'],
				'feather_radius'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
					'default' => 2,
				),
				'use_ai_polish'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'provider'              => array(
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
		$resolved = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'subject' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$path   = $resolved['file_path'];
		$radius = isset( $arguments['feather_radius'] ) ? (int) $arguments['feather_radius'] : 2;

		$out = $this->harmonization_save_bytes_to_temp( '_placeholder_', 'png' );
		if ( is_wp_error( $out ) ) {
			$this->harmonization_cleanup( $path );
			return $out;
		}
		// We need an empty path; remove the placeholder and write directly.
		wp_delete_file( $out );

		$result = $this->compositor()->feather_alpha( $path, $out, $radius );
		$this->harmonization_cleanup( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $arguments['use_ai_polish'] ) ) {
			$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
			$provider = $this->harmonization_detect_provider( $req );
			if ( '' !== $provider ) {
				$prompt = __( 'Refine the edges of this subject so hair, fur, and fine details are anti-aliased cleanly against the transparent background. Suppress any colored fringe or halo. Do not change the subject itself; only improve the alpha edge.', 'mcp-ai-wpoos-pro' );
				$bytes  = $this->ai_edit_image( $out, $prompt, $provider );
				if ( ! is_wp_error( $bytes ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $out, $bytes );
				}
			}
		}

		$media = $this->harmonization_import_to_media( $out, __( 'Refined Subject Matte', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array(
				'feather_radius' => $radius,
				'ai_polish'      => ! empty( $arguments['use_ai_polish'] ),
			)
		);
	}
}
