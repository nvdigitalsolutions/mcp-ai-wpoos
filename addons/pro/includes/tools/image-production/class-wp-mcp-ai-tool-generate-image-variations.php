<?php
/**
 * Tool for creating variations of existing images.
 *
 * Uses AI to generate similar but distinct versions of an input image.
 * Supports OpenAI DALL-E variations API.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Generate variations of existing images using AI.
 */
class WP_MCP_AI_Tool_Generate_Image_Variations extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_image_variations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Image Variations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create variations of an existing image using AI. The variations will be similar to the original but with creative differences.', 'mcp-ai-wpoos-pro' );
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
					'n'           => array(
						'type'        => 'integer',
						'description' => __( 'Number of variations to generate (1-10).', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 1,
						'maximum'     => 10,
						'default'     => 1,
					),
					'size'        => array(
						'type'        => 'string',
						'description' => __( 'Size for the generated variations: "1024x1024", "512x512", "256x256".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( '1024x1024', '512x512', '256x256' ),
						'default'     => '1024x1024',
					),
					'provider'    => array(
						'type'        => 'string',
						'description' => __( 'AI provider: "openai" (DALL-E), "auto".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'auto', 'openai' ),
						'default'     => 'auto',
					),
					'use_remote'  => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote GPU processing if available.', 'mcp-ai-wpoos-pro' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate image variations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get provider.
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';
		if ( 'auto' === $provider ) {
			$provider = 'openai'; // Default to OpenAI for variations.
		}

		// Generate variations.
		$result = null;
		switch ( $provider ) {
			case 'openai':
				$result = $this->generate_with_openai( $source_image, $arguments, $context );
				break;
			default:
				$result = new WP_Error(
					'wp_mcp_ai_invalid_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Unsupported provider: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		return $result;
	}

	/**
	 * Generate variations with OpenAI.
	 *
	 * @param WP_Image_Editor $source_image Source image editor instance.
	 * @param array           $arguments    Tool arguments.
	 * @param array           $context      Execution context.
	 * @return array|WP_Error Generation results or error.
	 */
	protected function generate_with_openai( $source_image, $arguments, $context ) {
		// Delegate to existing OpenAI variation tool.
		$tool_slug = 'create_image_variation';
		$tool      = wp_mcp_ai_get_tool_instance( $tool_slug );

		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				__( 'OpenAI image variation tool not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare arguments for OpenAI tool.
		$openai_args = array(
			'n'    => isset( $arguments['n'] ) ? absint( $arguments['n'] ) : 1,
			'size' => isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '1024x1024',
		);

		// Pass through source image reference.
		if ( isset( $arguments['attachment_id'] ) ) {
			$openai_args['attachment_id'] = $arguments['attachment_id'];
		} elseif ( isset( $arguments['url'] ) ) {
			$openai_args['url'] = $arguments['url'];
		} elseif ( isset( $arguments['base64'] ) ) {
			$openai_args['base64'] = $arguments['base64'];
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
