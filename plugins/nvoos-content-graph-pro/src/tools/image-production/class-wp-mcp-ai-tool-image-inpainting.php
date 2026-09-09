<?php
/**
 * Image-production tool batch (ecosystem port - Wave F2, image-production toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/image-production/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; the
 * base-owned `WP_MCP_AI_PATH` interface/image-base/trait requires gain exists-check seams resolving from
 * the addon's D8-compat `src/` copies; the import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * Tool for AI-powered image inpainting and editing.
 *
 * Allows editing specific regions of an image using AI, such as:
 * - Removing objects
 * - Adding new elements
 * - Changing backgrounds
 * - Fixing defects
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

/**
 * AI-powered image inpainting for targeted edits.
 */
class WP_MCP_AI_Tool_Image_Inpainting extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'image_inpainting';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Image Inpainting (AI)', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Edit specific regions of an image using AI. Provide a mask to define the area to edit and a prompt describing the desired change.', 'nvoos-content-graph-pro' );
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
					'prompt'     => array(
						'type'        => 'string',
						'description' => __( 'Description of what to generate in the masked area.', 'nvoos-content-graph-pro' ),
					),
					'mask'       => array(
						'type'        => 'object',
						'description' => __( 'Mask defining the area to edit. Provide as attachment_id, url, or base64.', 'nvoos-content-graph-pro' ),
						'properties'  => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'WordPress attachment ID of the mask image.', 'nvoos-content-graph-pro' ),
							),
							'url'           => array(
								'type'        => 'string',
								'description' => __( 'URL of the mask image.', 'nvoos-content-graph-pro' ),
							),
							'base64'        => array(
								'type'        => 'string',
								'description' => __( 'Base64-encoded mask image.', 'nvoos-content-graph-pro' ),
							),
						),
					),
					'size'       => array(
						'type'        => 'string',
						'description' => __( 'Output size: "1024x1024", "512x512", "256x256".', 'nvoos-content-graph-pro' ),
						'enum'        => array( '1024x1024', '512x512', '256x256' ),
						'default'     => '1024x1024',
					),
					'n'          => array(
						'type'        => 'integer',
						'description' => __( 'Number of variations to generate (1-10).', 'nvoos-content-graph-pro' ),
						'minimum'     => 1,
						'maximum'     => 10,
						'default'     => 1,
					),
					'provider'   => array(
						'type'        => 'string',
						'description' => __( 'AI provider: "openai" (DALL-E), "auto".', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'auto', 'openai' ),
						'default'     => 'auto',
					),
					'use_remote' => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing if available.', 'nvoos-content-graph-pro' ),
						'default'     => false,
					),
				)
			),
			'required'             => array( 'prompt' ),
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
			'external-api',
			'requires-credentials',
			'network-dependent',
			'consumes-tokens',
			'rate-limited',
			'gpu-accelerated',
			'performance-impact',
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
				__( 'You do not have permission to edit images.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate prompt.
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Edit prompt is required.', 'nvoos-content-graph-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Load mask image if provided.
		$mask_image = null;
		if ( ! empty( $arguments['mask'] ) ) {
			$mask_image = $this->load_source_image( $arguments['mask'], $user_id );
			if ( is_wp_error( $mask_image ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				return $mask_image;
			}
		}

		// Get provider.
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		if ( 'auto' === $provider ) {
			$provider = 'openai'; // Default to OpenAI.
		}

		// Perform inpainting.
		$result = null;
		switch ( $provider ) {
			case 'openai':
				$result = $this->inpaint_with_openai( $source_image, $mask_image, $prompt, $arguments, $context );
				break;
			default:
				$result = new WP_Error(
					'wp_mcp_ai_invalid_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Unsupported provider: %s', 'nvoos-content-graph-pro' ),
						$provider
					)
				);
		}

		// Clean up temporary images.
		$this->cleanup_source_image( $source_image, $arguments );
		if ( $mask_image ) {
			$this->cleanup_source_image( $mask_image, isset( $arguments['mask'] ) ? $arguments['mask'] : array() );
		}

		return $result;
	}

	/**
	 * Perform inpainting with OpenAI.
	 *
	 * @param WP_Image_Editor      $source_image Source image.
	 * @param WP_Image_Editor|null $mask_image   Mask image (optional).
	 * @param string               $prompt       Edit prompt.
	 * @param array                $arguments    Tool arguments.
	 * @param array                $context      Execution context.
	 * @return array|WP_Error Inpainting results or error.
	 */
	protected function inpaint_with_openai( $source_image, $mask_image, $prompt, $arguments, $context ) {
		// Delegate to existing OpenAI image edit tool.
		$tool_slug = 'edit_openai_image';
		$tool      = wp_mcp_ai_get_tool_instance( $tool_slug );

		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				__( 'OpenAI image edit tool not available.', 'nvoos-content-graph-pro' )
			);
		}

		// Prepare arguments for OpenAI tool.
		$openai_args = array(
			'prompt' => $prompt,
			'n'      => isset( $arguments['n'] ) ? absint( $arguments['n'] ) : 1,
			'size'   => isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '1024x1024',
		);

		// Pass through source image reference.
		if ( isset( $arguments['attachment_id'] ) ) {
			$openai_args['attachment_id'] = $arguments['attachment_id'];
		} elseif ( isset( $arguments['url'] ) ) {
			$openai_args['url'] = $arguments['url'];
		}

		// Pass through mask reference.
		if ( $mask_image && ! empty( $arguments['mask'] ) ) {
			if ( isset( $arguments['mask']['attachment_id'] ) ) {
				$openai_args['mask_attachment_id'] = $arguments['mask']['attachment_id'];
			} elseif ( isset( $arguments['mask']['url'] ) ) {
				$openai_args['mask_url'] = $arguments['mask']['url'];
			}
		}

		return $tool->execute( $openai_args, $context );
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
