<?php
/**
 * Tool for optimizing text-to-image prompts.
 *
 * Uses AI to enhance and optimize user prompts for better image generation results.
 * Provides suggestions for improved descriptions, keywords, and style modifiers.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Optimize text prompts for better AI image generation.
 */
class WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'text_to_image_prompt_optimizer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Text-to-Image Prompt Optimizer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Optimize and enhance text prompts for AI image generation. Returns improved prompts with better descriptions, keywords, and style modifiers.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'       => array(
					'type'        => 'string',
					'description' => __( 'The original text prompt to optimize.', 'mcp-ai-wpoos-pro' ),
				),
				'style'        => array(
					'type'        => 'string',
					'description' => __( 'Desired style: "realistic", "artistic", "abstract", "cartoon", "photographic".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'realistic', 'artistic', 'abstract', 'cartoon', 'photographic', 'cinematic' ),
				),
				'provider'     => array(
					'type'        => 'string',
					'description' => __( 'Target AI provider: "openai", "stability", "midjourney", "general".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'general', 'openai', 'stability', 'midjourney' ),
					'default'     => 'general',
				),
				'enhance_mode' => array(
					'type'        => 'string',
					'description' => __( 'Enhancement mode: "simple" (minor improvements), "detailed" (comprehensive), "creative" (artistic expansion).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'simple', 'detailed', 'creative' ),
					'default'     => 'detailed',
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
			'read-only',
			'external-api',
			'requires-credentials',
			'network-dependent',
			'consumes-tokens',
			'rate-limited',
			'idempotent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate prompt.
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Prompt text is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get parameters.
		$style        = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : '';
		$provider     = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'general';
		$enhance_mode = isset( $arguments['enhance_mode'] ) ? sanitize_text_field( $arguments['enhance_mode'] ) : 'detailed';

		// Build optimization prompt.
		$system_prompt = $this->build_system_prompt( $provider, $enhance_mode );
		$user_prompt   = $this->build_user_prompt( $prompt, $style );

		// Use OpenAI API to optimize the prompt.
		$api_key = wp_mcp_ai_get_api_key( 'openai_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'OpenAI API key not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'    => 'gpt-4o-mini',
						'messages' => array(
							array(
								'role'    => 'system',
								'content' => $system_prompt,
							),
							array(
								'role'    => 'user',
								'content' => $user_prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to optimize prompt.', 'mcp-ai-wpoos-pro' )
			);
		}

		$optimized_prompt = trim( $data['choices'][0]['message']['content'] );

		// Parse the response to extract structured data.
		$result = $this->parse_optimization_response( $optimized_prompt );

		return array(
			'success'          => true,
			'original_prompt'  => $prompt,
			'optimized_prompt' => $result['prompt'],
			'suggestions'      => $result['suggestions'],
			'keywords'         => $result['keywords'],
			'improvements'     => $result['improvements'],
		);
	}

	/**
	 * Build system prompt for prompt optimization.
	 *
	 * @param string $provider     Target provider.
	 * @param string $enhance_mode Enhancement mode.
	 * @return string System prompt.
	 */
	protected function build_system_prompt( $provider, $enhance_mode ) {
		$base_prompt = 'You are an expert at optimizing text prompts for AI image generation. ';

		switch ( $provider ) {
			case 'openai':
				$base_prompt .= 'Focus on prompts for DALL-E, which works best with natural language descriptions. ';
				break;
			case 'stability':
				$base_prompt .= 'Focus on prompts for Stable Diffusion, which benefits from detailed keywords and style modifiers. ';
				break;
			case 'midjourney':
				$base_prompt .= 'Focus on prompts for Midjourney, using parameter syntax like --ar, --stylize, --quality. ';
				break;
			default:
				$base_prompt .= 'Optimize for general AI image generation systems. ';
		}

		$base_prompt .= 'Provide an enhanced prompt that is clear, descriptive, and likely to produce better results. ';
		$base_prompt .= 'Return a JSON object with: "prompt" (optimized text), "suggestions" (array of tips), "keywords" (array), "improvements" (array of changes made).';

		return $base_prompt;
	}

	/**
	 * Build user prompt for optimization.
	 *
	 * @param string $prompt Original prompt.
	 * @param string $style  Desired style.
	 * @return string User prompt.
	 */
	protected function build_user_prompt( $prompt, $style ) {
		$user_prompt = 'Optimize this image generation prompt: "' . $prompt . '"';

		if ( ! empty( $style ) ) {
			$user_prompt .= "\nDesired style: " . $style;
		}

		return $user_prompt;
	}

	/**
	 * Parse optimization response.
	 *
	 * @param string $response AI response.
	 * @return array Parsed data.
	 */
	protected function parse_optimization_response( $response ) {
		// Try to parse as JSON.
		$data = json_decode( $response, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			return array(
				'prompt'       => isset( $data['prompt'] ) ? $data['prompt'] : $response,
				'suggestions'  => isset( $data['suggestions'] ) ? (array) $data['suggestions'] : array(),
				'keywords'     => isset( $data['keywords'] ) ? (array) $data['keywords'] : array(),
				'improvements' => isset( $data['improvements'] ) ? (array) $data['improvements'] : array(),
			);
		}

		// Fallback: return response as optimized prompt.
		return array(
			'prompt'       => $response,
			'suggestions'  => array(),
			'keywords'     => array(),
			'improvements' => array(),
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
