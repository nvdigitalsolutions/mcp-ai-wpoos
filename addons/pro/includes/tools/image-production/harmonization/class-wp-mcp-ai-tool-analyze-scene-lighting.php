<?php
/**
 * Tool: analyze_scene_lighting.
 *
 * Vision-helper that returns structured light-direction/color/intensity/contrast
 * estimates for any image. Used internally by other harmonization tools and
 * exposed for advanced users / agentic workflows.
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
 * Analyze the lighting of a scene image.
 */
class WP_MCP_AI_Tool_Analyze_Scene_Lighting extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_scene_lighting';
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
		return __( 'Analyze Scene Lighting', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Estimate the light direction, color temperature, intensity, and contrast of a scene image. Heuristic-first; can escalate to AI vision when confidence is low.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Lighter capability flags — read-only / cacheable.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'       => $this->harmonization_get_image_input_schema( 'image' )['attachment_id'],
				'allow_ai_escalation' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'attachment_id' ),
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
		$resolved = $this->harmonization_resolve_input( $arguments['attachment_id'], 'image' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$result = $this->lighting()->analyze(
			$resolved['file_path'],
			array( 'allow_ai_escalation' => ! empty( $arguments['allow_ai_escalation'] ) )
		);
		$this->harmonization_cleanup( $resolved['file_path'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'  => true,
			'stage'    => $this->get_slug(),
			'lighting' => $result,
			'text'     => sprintf(
				/* translators: 1: direction, 2: color temp, 3: intensity */
				__( 'Detected lighting: direction %1$s°, color %2$s, intensity %3$.2f.', 'mcp-ai-wpoos-pro' ),
				$result['direction_deg'],
				$result['color_temp'],
				$result['intensity']
			),
		);
	}
}
