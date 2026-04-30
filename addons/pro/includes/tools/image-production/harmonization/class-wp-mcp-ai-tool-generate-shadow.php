<?php
/**
 * Tool: generate_shadow.
 *
 * Render a physically plausible contact + cast shadow layer for a transparent
 * subject given a light direction.
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
 * Generate a shadow layer for a transparent subject.
 */
class WP_MCP_AI_Tool_Generate_Shadow extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_shadow';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Shadow', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a physically plausible contact + cast shadow layer (transparent PNG) for a subject given a light direction. Auto-detects direction from a background if one is provided.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject_attachment_id'    => $this->harmonization_get_image_input_schema( 'transparent subject PNG' )['attachment_id'],
				'background_attachment_id' => $this->harmonization_get_image_input_schema( 'optional background to detect light direction' )['attachment_id'],
				'direction_deg'            => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 360,
				),
				'softness'                 => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.5,
				),
				'opacity'                  => array(
					'type'    => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'default' => 0.5,
				),
				'length'                   => array(
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

		$direction = isset( $arguments['direction_deg'] ) ? (float) $arguments['direction_deg'] : null;
		$lighting  = null;

		if ( null === $direction && ! empty( $arguments['background_attachment_id'] ) ) {
			$bg = $this->harmonization_resolve_input( $arguments['background_attachment_id'], 'background' );
			if ( is_wp_error( $bg ) ) {
				$this->harmonization_cleanup( $subject['file_path'] );
				return $bg;
			}
			$lighting = $this->lighting()->analyze( $bg['file_path'] );
			$this->harmonization_cleanup( $bg['file_path'] );
			if ( ! is_wp_error( $lighting ) ) {
				$direction = $lighting['direction_deg'];
			}
		}
		if ( null === $direction ) {
			$direction = 135.0;
		}

		$out_path = $this->harmonization_temp_dir() . '/shadow-' . wp_generate_password( 12, false ) . '.png';
		$opts     = array(
			'direction_deg' => $direction,
			'softness'      => isset( $arguments['softness'] ) ? (float) $arguments['softness'] : 0.5,
			'opacity'       => isset( $arguments['opacity'] ) ? (float) $arguments['opacity'] : 0.5,
			'length'        => isset( $arguments['length'] ) ? (float) $arguments['length'] : 0.4,
		);

		$report = $this->compositor()->render_shadow_layer( $subject['file_path'], $out_path, $opts );
		$this->harmonization_cleanup( $subject['file_path'] );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$media = $this->harmonization_import_to_media( $out_path, __( 'Shadow Layer', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out_path );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$response_report = $report;
		if ( is_array( $lighting ) ) {
			$response_report['detected_lighting'] = $lighting;
		}

		return $this->harmonization_format_response( $media, $this->get_slug(), $response_report );
	}
}
