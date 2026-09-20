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
class WP_MCP_AI_Tool_Generate_Reflection extends WP_MCP_AI_Tool_Harmonization_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_reflection';
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
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Use to synthesize a ground reflection layer (vertical flip with fade) for a transparent subject on glossy, water, or marble surfaces.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Use generate_shadow for contact and cast shadows, or harmonize_image_into_background to composite the subject into a scene.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'generate_shadow', 'harmonize_image_into_background', 'analyze_scene_lighting' ),
			'notes'           => __( 'Output is a transparent PNG layer; tune fade and opacity (0-1) to match surface glossiness.', 'mcp-ai-wpoos-pro' ),
		);
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
