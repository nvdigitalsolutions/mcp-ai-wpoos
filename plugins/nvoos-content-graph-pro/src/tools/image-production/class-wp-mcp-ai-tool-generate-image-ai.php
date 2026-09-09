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
 * Tool for AI-powered image generation from text prompts.
 *
 * Supports multiple AI providers:
 * - OpenAI DALL-E (dall-e-3, dall-e-2)
 * - Stable Diffusion via Stability AI
 * - Midjourney (when available)
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
 * Generate images from text prompts using various AI providers.
 */
class WP_MCP_AI_Tool_Generate_Image_AI extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_image_ai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Image (AI)', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate high-quality images from text prompts using AI providers like DALL-E, Stable Diffusion, or Midjourney.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'      => array(
					'type'        => 'string',
					'description' => __( 'Text description of the image to generate. Be specific and descriptive.', 'nvoos-content-graph-pro' ),
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'AI provider to use: "openai" (DALL-E), "stability" (Stable Diffusion), "auto" (best available).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'auto', 'openai', 'stability' ),
					'default'     => 'auto',
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'Specific model: "dall-e-3", "dall-e-2", "stable-diffusion-xl", etc.', 'nvoos-content-graph-pro' ),
				),
				'size'        => array(
					'type'        => 'string',
					'description' => __( 'Image dimensions: "1024x1024", "1792x1024", "1024x1792", etc.', 'nvoos-content-graph-pro' ),
					'default'     => '1024x1024',
				),
				'quality'     => array(
					'type'        => 'string',
					'description' => __( 'Image quality: "standard", "hd" (DALL-E 3 only).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'standard', 'hd' ),
					'default'     => 'standard',
				),
				'style'       => array(
					'type'        => 'string',
					'description' => __( 'Style preset: "vivid" (dramatic), "natural" (realistic).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'vivid', 'natural' ),
					'default'     => 'vivid',
				),
				'n'           => array(
					'type'        => 'integer',
					'description' => __( 'Number of images to generate (1-10, some providers limit this).', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 1,
				),
				'use_remote'  => array(
					'type'        => 'boolean',
					'description' => __( 'Use remote GPU processing for faster generation (if available).', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'save_prompt' => array(
					'type'        => 'boolean',
					'description' => __( 'Save the prompt in image metadata.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
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
				__( 'You do not have permission to generate images.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate prompt.
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Image prompt is required.', 'nvoos-content-graph-pro' )
			);
		}

		// Get provider.
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		if ( 'auto' === $provider ) {
			$provider = $this->select_best_provider();
		}

		// Provider-specific generation.
		$result = null;
		switch ( $provider ) {
			case 'openai':
				$result = $this->generate_with_openai( $prompt, $arguments, $context );
				break;
			case 'stability':
				$result = $this->generate_with_stability( $prompt, $arguments, $context );
				break;
			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Unsupported provider: %s', 'nvoos-content-graph-pro' ),
						$provider
					)
				);
		}

		return $result;
	}

	/**
	 * Select the best available provider.
	 *
	 * @return string Provider name.
	 */
	protected function select_best_provider() {
		// Check for OpenAI API key.
		if ( wp_mcp_ai_get_api_key( 'openai_api_key' ) ) {
			return 'openai';
		}

		// Check for Stability AI API key.
		if ( wp_mcp_ai_get_api_key( 'stability_api_key' ) ) {
			return 'stability';
		}

		// Default to OpenAI.
		return 'openai';
	}

	/**
	 * Generate image with OpenAI DALL-E.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Generation results or error.
	 */
	protected function generate_with_openai( $prompt, $arguments, $context ) {
		// Delegate to existing OpenAI image tool.
		$tool_slug = 'generate_openai_image';
		$tool      = wp_mcp_ai_get_tool_instance( $tool_slug );

		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				__( 'OpenAI image generation tool not available.', 'nvoos-content-graph-pro' )
			);
		}

		$openai_args = array(
			'prompt'  => $prompt,
			'model'   => isset( $arguments['model'] ) ? $arguments['model'] : 'dall-e-3',
			'size'    => isset( $arguments['size'] ) ? $arguments['size'] : '1024x1024',
			'quality' => isset( $arguments['quality'] ) ? $arguments['quality'] : 'standard',
			'style'   => isset( $arguments['style'] ) ? $arguments['style'] : 'vivid',
			'n'       => isset( $arguments['n'] ) ? absint( $arguments['n'] ) : 1,
		);

		return $tool->execute( $openai_args, $context );
	}

	/**
	 * Generate image with Stability AI.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Generation results or error.
	 */
	protected function generate_with_stability( $prompt, $arguments, $context ) {
		$api_key = wp_mcp_ai_get_api_key( 'stability_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'Stability AI API key not configured.', 'nvoos-content-graph-pro' )
			);
		}

		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'stable-diffusion-xl-1024-v1-0';
		$size  = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '1024x1024';

		// Parse size.
		list( $width, $height ) = explode( 'x', $size );
		$width                  = absint( $width );
		$height                 = absint( $height );

		// Make API request.
		$response = wp_remote_post(
			'https://api.stability.ai/v1/generation/' . $model . '/text-to-image',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'text_prompts' => array(
							array(
								'text'   => $prompt,
								'weight' => 1,
							),
						),
						'cfg_scale'    => 7,
						'height'       => $height,
						'width'        => $width,
						'samples'      => isset( $arguments['n'] ) ? absint( $arguments['n'] ) : 1,
						'steps'        => 30,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['artifacts'] ) || empty( $data['artifacts'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to generate image with Stability AI.', 'nvoos-content-graph-pro' )
			);
		}

		// Save images as attachments.
		$results = array();
		foreach ( $data['artifacts'] as $artifact ) {
			$image_data = base64_decode( $artifact['base64'] );
			$upload     = wp_upload_bits( 'stability-' . time() . '.png', null, $image_data );

			if ( $upload['error'] ) {
				continue;
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => 'image/png',
					'post_title'     => sanitize_text_field( $prompt ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				),
				$upload['file']
			);

			if ( ! is_wp_error( $attachment_id ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
				wp_update_attachment_metadata( $attachment_id, $attach_data );

				// Save prompt in metadata.
				if ( ! empty( $arguments['save_prompt'] ) ) {
					update_post_meta( $attachment_id, '_wp_mcp_ai_generation_prompt', $prompt );
				}

				$results[] = $this->format_attachment_response( $attachment_id );
			}
		}

		return array(
			'success' => true,
			'images'  => $results,
			'count'   => count( $results ),
		);
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
