<?php
/**
 * Tool: generate_reflection.
 *
 * Synthesize a ground/surface reflection layer for a transparent subject.
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
 * Generate a reflection layer for a transparent subject.
 */
class WP_MCP_AI_Tool_Generate_Reflection extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_reflection';
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
		return __( 'Generate Reflection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Synthesize a ground/surface reflection layer (vertical flip with progressive fade and opacity) for a subject. Useful when placing on glossy/water/marble surfaces.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id' => $this->harmonization_get_image_input_schema( 'transparent subject PNG' )['attachment_id'],
				'fade'                  => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.7,
				),
				'opacity'               => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.4,
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
		$subject = $this->harmonization_resolve_input( $arguments['subject_attachment_id'], 'subject' );
		if ( is_wp_error( $subject ) ) {
			return $subject;
		}

		$out_path = $this->harmonization_temp_dir() . '/reflection-' . wp_generate_password( 12, false ) . '.png';
		$opts     = array(
			'fade'    => isset( $arguments['fade'] ) ? (float) $arguments['fade'] : 0.7,
			'opacity' => isset( $arguments['opacity'] ) ? (float) $arguments['opacity'] : 0.4,
		);

		$res = $this->compositor()->render_reflection_layer( $subject['file_path'], $out_path, $opts );
		$this->harmonization_cleanup( $subject['file_path'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$media = $this->harmonization_import_to_media( $out_path, __( 'Reflection Layer', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out_path );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response( $media, $this->get_slug(), $opts );
	}
}
