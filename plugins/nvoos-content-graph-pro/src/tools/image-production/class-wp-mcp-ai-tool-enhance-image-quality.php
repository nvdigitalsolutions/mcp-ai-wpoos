<?php
/**
 * Image-production tool batch (ecosystem port - Wave 1, image-production
 * sidecar cluster, issue #6877).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/image-production/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; the
 * base-owned `WP_MCP_AI_PATH` interface/image-base/trait requires gain exists-check seams resolving from
 * the addon's D8-compat `src/` copies; the usage-guidance block and interface port with this cluster
 * (guidance-sweep ride-along, PR #6740 precedent).
 *
 * Tool for image quality enhancement via Sharp.
 *
 * Enhances image quality including sharpness, saturation, contrast, and
 * denoising with real Sharp processing — the local bundled Sharp runtime
 * (Node.js subprocess) or the Media Worker sidecar /api/image/enhance
 * route. When neither backend is available the tool returns an honest
 * wp_mcp_ai_sharp_unavailable error instead of claiming success.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Tool_Image_Base' ) ) {
	$nvoos_content_graph_pro_image_base = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/class-wp-mcp-ai-tool-image-base.php';
	if ( file_exists( $nvoos_content_graph_pro_image_base ) ) {
		require_once $nvoos_content_graph_pro_image_base;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Sharp_Image_Processing' ) ) {
	$nvoos_content_graph_pro_sharp_image_processing = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/traits/trait-wp-mcp-ai-sharp-image-processing.php';
	if ( file_exists( $nvoos_content_graph_pro_sharp_image_processing ) ) {
		require_once $nvoos_content_graph_pro_sharp_image_processing;
	}
}

/**
 * Enhance image quality with real Sharp processing.
 */
class WP_MCP_AI_Tool_Enhance_Image_Quality extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Sharp_Image_Processing;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enhance_image_quality';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enhance Image Quality', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enhance image quality with real Sharp processing. Improves sharpness, saturation, contrast, and reduces noise via the local Sharp runtime or the Media Worker sidecar.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Use to improve sharpness, saturation, contrast, and reduce noise or artifacts on an existing image with real Sharp enhancement.', 'nvoos-content-graph-pro' ),
			'when_not_to_use' => __( 'Use upscale_image_ai to increase resolution, or compress_image to reduce file size without visual changes.', 'nvoos-content-graph-pro' ),
			'related_tools'   => array( 'upscale_image_ai', 'compress_image', 'colorize_image' ),
			'notes'           => __( 'Enhancements: sharpness, color (saturation), contrast, denoise, auto; strength is 0-1. Requires local Sharp or a Media Worker sidecar — without either the tool errors honestly.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'enhancements' => array(
						'type'        => 'array',
						'description' => __( 'Enhancements to apply: "sharpness", "color", "contrast", "denoise", "auto".', 'nvoos-content-graph-pro' ),
						'items'       => array(
							'type' => 'string',
							'enum' => array( 'sharpness', 'color', 'contrast', 'denoise', 'auto' ),
						),
						'default'     => array( 'auto' ),
					),
					'strength'     => array(
						'type'        => 'number',
						'description' => __( 'Enhancement strength (0-1). Default is 0.5 for moderate enhancement.', 'nvoos-content-graph-pro' ),
						'minimum'     => 0,
						'maximum'     => 1,
						'default'     => 0.5,
					),
					'use_remote'   => array(
						'type'        => 'boolean',
						'description' => __( 'Prefer the Media Worker sidecar over the local Sharp subprocess.', 'nvoos-content-graph-pro' ),
						'default'     => false,
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'external-dependency',
			'performance-impact',
			'idempotent',
		);
	}

	/**
	 * Map the enhancements enum onto worker/sharp-process fields.
	 *
	 * 'auto' expands to all four operations. Multipliers are derived from
	 * the 0-1 master strength: saturation = 1 + 0.5*strength, contrast =
	 * 1 + 0.4*strength, numeric sharpen = strength. Denoise is a boolean.
	 *
	 * @param array $enhancements Enhancement slugs.
	 * @param float $strength     Master strength 0-1.
	 * @return array Processing fields for the enhance engines.
	 */
	protected function map_enhancements_to_fields( array $enhancements, $strength ) {
		if ( in_array( 'auto', $enhancements, true ) ) {
			$enhancements = array( 'sharpness', 'color', 'contrast', 'denoise' );
		}

		$fields = array();
		foreach ( $enhancements as $enhancement ) {
			switch ( $enhancement ) {
				case 'sharpness':
					if ( $strength > 0 ) {
						$fields['sharpen'] = $strength;
					}
					break;
				case 'color':
					$fields['saturation'] = 1 + 0.5 * $strength;
					break;
				case 'contrast':
					$fields['contrast'] = 1 + 0.4 * $strength;
					break;
				case 'denoise':
					$fields['denoise'] = true;
					break;
			}
		}

		return $fields;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to enhance images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get enhancements.
		$enhancements = isset( $arguments['enhancements'] ) ? (array) $arguments['enhancements'] : array( 'auto' );
		$strength     = isset( $arguments['strength'] ) ? floatval( $arguments['strength'] ) : 0.5;
		$strength     = max( 0, min( 1, $strength ) );

		// Map onto the engine fields and reject empty requests.
		$fields = $this->map_enhancements_to_fields( $enhancements, $strength );
		if ( empty( $fields ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'No enhancement operations requested. Pass at least one enhancement or "auto".', 'nvoos-content-graph-pro' )
			);
		}

		// Determine the processing backends.
		$sharp_available   = $this->is_local_sharp_available();
		$sidecar_supported = $this->is_sidecar_upload_supported();

		if ( ! $sharp_available && ! $sidecar_supported ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_sharp_unavailable',
				__( 'Image enhancement requires local Sharp (Node.js) or a Media Worker sidecar, and neither is available. Install Sharp via "npm install --include=optional" in the addons/pro directory, or configure the Media Worker sidecar in Settings → Media Worker.', 'nvoos-content-graph-pro' )
			);
		}

		// Build the operation parameters.
		$source_path = isset( $source_image->source_file_path ) && is_string( $source_image->source_file_path )
			? $source_image->source_file_path
			: '';
		$source_ext  = $source_path ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) pathinfo( $source_path, PATHINFO_EXTENSION ) ) : '';
		if ( '' === $source_ext ) {
			$source_ext = 'jpg';
		}

		$params = array_merge(
			array(
				'source'    => $source_path,
				'operation' => 'enhance',
				'format'    => $source_ext,
			),
			$fields
		);

		// Process: use_remote prefers the sidecar; the default path prefers
		// the local Sharp subprocess, with the other backend as fallback.
		$use_remote = ! empty( $arguments['use_remote'] );
		$engine     = '';

		if ( $use_remote && $sidecar_supported ) {
			$result = $this->process_image_via_sidecar( '/api/image/enhance', $source_path, $fields, $source_ext );
			$engine = 'sidecar';
		} elseif ( $sharp_available ) {
			$result = $this->process_image_with_sharp( $params );
			$engine = 'local_sharp';
		} elseif ( $sidecar_supported ) {
			$result = $this->process_image_via_sidecar( '/api/image/enhance', $source_path, $fields, $source_ext );
			$engine = 'sidecar';
		} else {
			$result = array( 'error' => __( 'No processing backend available.', 'nvoos-content-graph-pro' ) );
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		if ( ! $result || isset( $result['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_sharp_process_failed',
				isset( $result['error'] ) ? $result['error'] : __( 'Image enhancement failed.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $result['output_path'] ) || ! file_exists( $result['output_path'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_sharp_process_failed',
				__( 'Image enhancement produced no output file.', 'nvoos-content-graph-pro' )
			);
		}

		// Land the processed file in the media library.
		$parent_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		/* translators: %s: engine name */
		$title         = sprintf( __( 'Enhanced Image (%s)', 'nvoos-content-graph-pro' ), 'sidecar' === $engine ? __( 'Worker', 'nvoos-content-graph-pro' ) : __( 'Sharp', 'nvoos-content-graph-pro' ) );
		$attachment_id = $this->upload_processed_image( $result['output_path'], $parent_id, $title );
		wp_delete_file( $result['output_path'] );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_error',
				__( 'Failed to create attachment for the enhanced image.', 'nvoos-content-graph-pro' )
			);
		}

		$response = $this->format_attachment_response( $attachment_id, $arguments );

		$response['text'] = sprintf(
			/* translators: %s: processing engine */
			__( 'Image enhanced successfully with %s.', 'nvoos-content-graph-pro' ),
			'sidecar' === $engine ? __( 'the Media Worker sidecar', 'nvoos-content-graph-pro' ) : __( 'local Sharp', 'nvoos-content-graph-pro' )
		);
		$response['engine']         = $engine;
		$response['enhancements']   = array_keys( $fields );
		$response['strength']       = $strength;
		$response['original_size']  = isset( $result['original_size'] ) ? $result['original_size'] : null;
		$response['optimized_size'] = isset( $result['optimized_size'] ) ? $result['optimized_size'] : null;
		$response['dimensions']     = isset( $result['dimensions'] ) ? $result['dimensions'] : null;

		return $response;
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
