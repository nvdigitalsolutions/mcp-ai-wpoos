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
class WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Improving a draft text-to-image prompt with keywords, style modifiers, and provider-specific phrasing before generation.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Generating the image itself: use generate_image_ai. Editing an existing image: use edit_openai_image or image_inpainting.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'generate_image_ai', 'generate_image_variations', 'apply_artistic_style' ),
			'notes'           => __( 'Uses gpt-4o-mini. provider targets: general, openai, stability, midjourney.', 'mcp-ai-wpoos-pro' ),
		);
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

		// Use the OpenAI client so custom base URLs, org/project headers, and
		// the credential resolver all apply. The client also normalises the
		// response envelope and surfaces HTTP errors as WP_Error.
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_settings_not_available',
				__( 'The OpenAI client is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		if ( empty( $client->get_api_key() ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'OpenAI API key not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = $client->create_chat_completion(
			array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			array( 'model' => 'gpt-4o-mini' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to optimize prompt.', 'mcp-ai-wpoos-pro' )
			);
		}

		// OpenAI-compatible gateways can return the content as an array of
		// parts; flatten before any string operation to avoid the fatal
		// `trim(): Argument #1 ($string) must be of type string`.
		$optimized_prompt = trim( $this->flatten_response_content( $result['choices'][0]['message']['content'] ) );
		if ( '' === $optimized_prompt ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to optimize prompt.', 'mcp-ai-wpoos-pro' )
			);
		}

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
	 * Flatten a provider message content field into a string.
	 *
	 * OpenAI-compatible providers may return `message.content` as an array of
	 * text parts; string-assuming callers fatal on that shape.
	 *
	 * @param string|array $content Raw content field.
	 * @return string Flattened text.
	 */
	protected function flatten_response_content( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( is_array( $content ) ) {
			$text = '';
			foreach ( $content as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$text .= $part['text'];
				} elseif ( is_string( $part ) ) {
					$text .= $part;
				}
			}
			return $text;
		}

		return '';
	}

	/**
	 * Parse optimization response.
	 *
	 * @param string $response AI response.
	 * @return array Parsed data.
	 */
	protected function parse_optimization_response( $response ) {
		$response = trim( (string) $response );

		// Models frequently wrap the requested JSON in markdown fences;
		// strip them before decoding so fenced payloads actually parse.
		if ( preg_match( '/^```(?:json)?\s*(.+?)\s*```$/s', $response, $matches ) ) {
			$response = $matches[1];
		}

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
