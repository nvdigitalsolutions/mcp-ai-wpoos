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
 * Tool for high-quality image upscaling via Sharp.
 *
 * Upscales images 2x, 4x, or 8x with the local bundled Sharp runtime
 * (Node.js subprocess) or the Media Worker sidecar /api/image/upscale
 * route, both using the lanczos3 kernel. The response honestly reports
 * upscale_method: 'lanczos3' until an AI super-resolution engine lands
 * (Wave 3). When neither backend is available the tool attempts the
 * standard WordPress image scaling degrade; because WP image editors
 * cannot upscale, that path surfaces an honest wp_mcp_ai_sharp_unavailable
 * error instead of a fake success.
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
 * Upscale images with real high-quality Sharp scaling.
 */
class WP_MCP_AI_Tool_Upscale_Image_AI extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Sharp_Image_Processing;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upscale_image_ai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upscale Image (AI)', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Upscale images 2x, 4x, or 8x with real high-quality lanczos3 scaling via local Sharp or the Media Worker sidecar, preserving detail.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Increasing image resolution by 2x, 4x, or 8x with high-quality lanczos3 scaling while preserving detail.', 'nvoos-content-graph-pro' ),
			'when_not_to_use' => __( 'Plain dimension resize: use resize_image_smart. Shrinking for the web: use optimize_for_web.', 'nvoos-content-graph-pro' ),
			'related_tools'   => array( 'resize_image_smart', 'enhance_image_quality', 'optimize_image_sharp' ),
			'notes'           => __( 'Uses real lanczos3 scaling; upscale_method reports honestly. AI super-resolution models (real-esrgan, esrgan, anime) are planned for a future release — the model argument is accepted for compatibility but does not change the engine yet.', 'nvoos-content-graph-pro' ),
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
					'scale_factor' => array(
						'type'        => 'number',
						'description' => __( 'Upscaling factor: 2, 4, or 8.', 'nvoos-content-graph-pro' ),
						'enum'        => array( 2, 4, 8 ),
						'default'     => 2,
					),
					'model'        => array(
						'type'        => 'string',
						'description' => __( 'Super-resolution model preference: "real-esrgan" (general), "esrgan" (photos), "anime" (illustrations). Accepted for compatibility; all models currently use lanczos3 scaling until the AI engine lands.', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'real-esrgan', 'esrgan', 'anime' ),
						'default'     => 'real-esrgan',
					),
					'denoise'      => array(
						'type'        => 'number',
						'description' => __( 'Denoising strength (0-1). Accepted for compatibility; lanczos3 scaling does not denoise. Wired when the AI super-resolution engine lands.', 'nvoos-content-graph-pro' ),
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
			'cpu-intensive',
		);
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
				__( 'You do not have permission to upscale images.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get scale factor.
		$scale_factor = isset( $arguments['scale_factor'] ) ? absint( $arguments['scale_factor'] ) : 2;
		if ( ! in_array( $scale_factor, array( 2, 4, 8 ), true ) ) {
			$scale_factor = 2;
		}

		// Get model preference (accepted for compatibility until Wave 3).
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'real-esrgan';

		// Get denoise strength (accepted for compatibility until Wave 3).
		$denoise = isset( $arguments['denoise'] ) ? floatval( $arguments['denoise'] ) : 0.5;

		// Determine the processing backends.
		$sharp_available   = $this->is_local_sharp_available();
		$sidecar_supported = $this->is_sidecar_upload_supported();

		$source_path = isset( $source_image->source_file_path ) && is_string( $source_image->source_file_path )
			? $source_image->source_file_path
			: '';
		$source_ext  = $source_path ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) pathinfo( $source_path, PATHINFO_EXTENSION ) ) : '';
		if ( '' === $source_ext ) {
			$source_ext = 'jpg';
		}

		// Reject inputs whose upscaled dimensions would breach the shared cap.
		$size = $source_image->get_size();
		if ( isset( $size['width'], $size['height'] )
			&& ( $size['width'] * $scale_factor > self::MAX_UPSCALE_DIMENSION || $size['height'] * $scale_factor > self::MAX_UPSCALE_DIMENSION ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_dimension_cap',
				sprintf(
					/* translators: %d: maximum dimension in pixels */
					__( 'Upscaled dimensions would exceed the %dpx cap. Choose a smaller source or scale factor.', 'nvoos-content-graph-pro' ),
					self::MAX_UPSCALE_DIMENSION
				)
			);
		}

		$result = null;
		$engine = '';

		$use_remote = ! empty( $arguments['use_remote'] );

		if ( $use_remote && $sidecar_supported ) {
			$result = $this->process_image_via_sidecar(
				'/api/image/upscale',
				$source_path,
				array( 'factor' => $scale_factor ),
				$source_ext
			);
			$engine = 'sidecar';
		} elseif ( $sharp_available ) {
			$result = $this->process_image_with_sharp(
				array(
					'source'    => $source_path,
					'operation' => 'upscale',
					'format'    => $source_ext,
					'factor'    => $scale_factor,
				)
			);
			$engine = 'local_sharp';
		} elseif ( $sidecar_supported ) {
			$result = $this->process_image_via_sidecar(
				'/api/image/upscale',
				$source_path,
				array( 'factor' => $scale_factor ),
				$source_ext
			);
			$engine = 'sidecar';
		}

		if ( $result && ! isset( $result['error'] ) && ! empty( $result['output_path'] ) && file_exists( $result['output_path'] ) ) {
			$this->cleanup_source_image( $source_image, $arguments );

			// Land the processed file in the media library.
			$parent_id     = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
			$attachment_id = $this->upload_processed_image( $result['output_path'], $parent_id, __( 'Upscaled Image', 'nvoos-content-graph-pro' ) );
			wp_delete_file( $result['output_path'] );

			if ( ! $attachment_id ) {
				return new WP_Error(
					'wp_mcp_ai_attachment_error',
					__( 'Failed to create attachment for the upscaled image.', 'nvoos-content-graph-pro' )
				);
			}

			$response = $this->format_attachment_response( $attachment_id, $arguments );

			$response['text'] = sprintf(
				/* translators: %s: processing engine */
				__( 'Image upscaled successfully with %s.', 'nvoos-content-graph-pro' ),
				'sidecar' === $engine ? __( 'the Media Worker sidecar', 'nvoos-content-graph-pro' ) : __( 'local Sharp', 'nvoos-content-graph-pro' )
			);
			$response['engine']          = $engine;
			$response['scale_factor']    = $scale_factor;
			$response['upscale_method']  = 'lanczos3';
			$response['requested_model'] = $model;
			$response['original_size']   = isset( $result['original_size'] ) ? $result['original_size'] : null;
			$response['optimized_size']  = isset( $result['optimized_size'] ) ? $result['optimized_size'] : null;
			$response['dimensions']      = isset( $result['dimensions'] ) ? $result['dimensions'] : null;

			if ( $denoise > 0 ) {
				$response['denoise_applied'] = false;
			}

			return $response;
		}

		// Honest no-backend degrade: standard WordPress image scaling, clearly
		// labelled — never branded as AI super-resolution.
		if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['error'] ) ) ) {
			$error_message = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : __( 'No processing backend available.', 'nvoos-content-graph-pro' );
		} else {
			$error_message = __( 'No processing backend available.', 'nvoos-content-graph-pro' );
		}

		// Run the standard WP image editor resize as the documented degrade.
		$new_size = array(
			'width'  => isset( $size['width'] ) ? $size['width'] * $scale_factor : 0,
			'height' => isset( $size['height'] ) ? $size['height'] * $scale_factor : 0,
		);

		$resize = $source_image->resize( $new_size['width'], $new_size['height'], false );
		if ( is_wp_error( $resize ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return new WP_Error(
				'wp_mcp_ai_sharp_unavailable',
				sprintf(
					/* translators: %s: underlying editor error message */
					__( 'No upscaling backend is available: standard WordPress scaling cannot upscale images (%s). Install local Sharp (Node.js) or configure a Media Worker sidecar for real upscaling.', 'nvoos-content-graph-pro' ),
					$resize->get_error_message()
				)
			);
		}

		$saved = $this->save_as_attachment( $source_image, $arguments, $user_id, 'upscale' );

		$this->cleanup_source_image( $source_image, $arguments );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$response = $this->format_image_response( $saved, $arguments );

		$response['text']            = __( 'No Sharp runtime or Media Worker sidecar was available; applied standard WordPress image scaling (not AI super-resolution).', 'nvoos-content-graph-pro' );
		$response['engine']          = 'wp_image_editor';
		$response['scale_factor']    = $scale_factor;
		$response['upscale_method']  = 'standard';
		$response['requested_model'] = $model;
		$response['backend_error']   = $error_message;

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
