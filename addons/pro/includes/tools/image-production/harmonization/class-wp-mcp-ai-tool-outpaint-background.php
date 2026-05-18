<?php
/**
 * Tool: outpaint_background.
 *
 * Extends a background's canvas to fit a target aspect ratio without cropping
 * by delegating to the configured AI provider's edit endpoint.
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
 * Outpaint a background image to a new aspect ratio.
 */
class WP_MCP_AI_Tool_Outpaint_Background extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'outpaint_background';
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
		return __( 'Outpaint Background', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extend a background image to a new aspect ratio without cropping the subject by using AI outpainting. Saves a new attachment.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'background image' )['attachment_id'],
				'target_aspect_ratio'      => array(
					'type'    => 'string',
					'enum'    => array( '1:1', '4:5', '16:9', '9:16', '3:2', '2:3' ),
					'default' => '16:9',
				),
				'extend_direction'         => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'horizontal', 'vertical', 'all' ),
					'default' => 'auto',
				),
				'provider'                 => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
				),
			),
			'required'             => array( 'background_attachment_id', 'target_aspect_ratio' ),
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
		$resolved = $this->harmonization_resolve_input( $arguments['background_attachment_id'], 'background' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$path     = $resolved['file_path'];
		$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		$provider = $this->harmonization_detect_provider( $req );
		if ( '' === $provider ) {
			$this->harmonization_cleanup( $path );
			return new WP_Error( 'wp_mcp_ai_no_provider', __( 'No AI provider configured for outpainting.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$target = isset( $arguments['target_aspect_ratio'] ) ? sanitize_text_field( $arguments['target_aspect_ratio'] ) : '16:9';
		$dir    = isset( $arguments['extend_direction'] ) ? sanitize_text_field( $arguments['extend_direction'] ) : 'auto';
		$prompt = sprintf(
			/* translators: 1: aspect ratio, 2: direction */
			__( 'Extend this image to a %1$s aspect ratio (direction: %2$s) using outpainting. Continue the existing scene seamlessly. Preserve the original content; only fill in the new edges with content that matches the existing lighting, perspective, and style.', 'mcp-ai-wpoos-pro' ),
			$target,
			$dir
		);

		$bytes = $this->ai_edit_image( $path, $prompt, $provider );
		$this->harmonization_cleanup( $path );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$out = $this->harmonization_save_bytes_to_temp( $bytes, 'png' );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		$media = $this->harmonization_import_to_media( $out, __( 'Outpainted Background', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array(
				'target_aspect_ratio' => $target,
				'extend_direction'    => $dir,
				'provider'            => $provider,
			)
		);
	}
}
