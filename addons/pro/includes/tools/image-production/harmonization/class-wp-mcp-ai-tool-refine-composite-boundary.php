<?php
/**
 * Tool: refine_composite_boundary.
 *
 * Runs over the final composited image, applying edge-aware blending and an
 * optional low-strength AI pass on a small border band so the composite reads
 * as if it were photographed in one camera.
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
 * Refine the boundary of a final composite.
 */
class WP_MCP_AI_Tool_Refine_Composite_Boundary extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'refine_composite_boundary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Refine Composite Boundary', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Refine the foreground/background boundary of a final composite: edge feathering for transparent inputs, plus an optional low-strength AI pass that unifies grain and micro-contrast around the subject edge.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'composite_attachment_id' => $this->harmonization_get_image_input_schema( 'composited image' )['attachment_id'],
				'feather_radius'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 5,
					'default' => 1,
				),
				'use_ai_polish'           => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'provider'                => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'gemini', 'openai' ),
					'default' => 'auto',
				),
			),
			'required'             => array( 'composite_attachment_id' ),
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
		$resolved = $this->harmonization_resolve_input( $arguments['composite_attachment_id'], 'composite' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$path    = $resolved['file_path'];
		$radius  = isset( $arguments['feather_radius'] ) ? max( 1, min( 5, (int) $arguments['feather_radius'] ) ) : 1;
		$out_pth = $this->harmonization_temp_dir() . '/refined-' . wp_generate_password( 12, false ) . '.png';

		// Apply alpha feather (no-op for fully opaque inputs, but doesn't error).
		$res = $this->compositor()->feather_alpha( $path, $out_pth, $radius );
		if ( is_wp_error( $res ) ) {
			// If feathering fails, copy input through.
			copy( $path, $out_pth );
		}
		$this->harmonization_cleanup( $path );

		if ( ! empty( $arguments['use_ai_polish'] ) ) {
			$req      = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
			$provider = $this->harmonization_detect_provider( $req );
			if ( '' !== $provider ) {
				$prompt = __( 'Subtly refine this composite so the foreground and background read as one photograph. Match grain, micro-contrast, lens characteristics, and color cast at the foreground/background boundary. Do not change the subject\'s identity, pose, or position. Apply only minimal, edge-localized adjustments.', 'mcp-ai-wpoos-pro' );
				$bytes  = $this->ai_edit_image( $out_pth, $prompt, $provider );
				if ( ! is_wp_error( $bytes ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $out_pth, $bytes );
				}
			}
		}

		$media = $this->harmonization_import_to_media( $out_pth, __( 'Refined Composite', 'mcp-ai-wpoos-pro' ), $user_id );
		$this->harmonization_cleanup( $out_pth );
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
