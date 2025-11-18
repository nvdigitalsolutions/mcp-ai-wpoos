<?php
/**
 * Tool that generates image prompts using LM Studio and optionally creates images.
 *
 * This tool uses LM Studio with models like google/gemma-3-12b to enhance image prompts,
 * then optionally chains to actual image generation tools (OpenAI or Gemini).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-llm-sanitizer-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-lm-studio-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for using LM Studio to enhance image prompts and optionally generate images.
 */
class WP_MCP_AI_Tool_Generate_LM_Studio_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_lm_studio_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate LM Studio Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uses LM Studio (with models like Gemma) to enhance image prompts and optionally create images via OpenAI or Gemini.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'               => array(
					'type'        => 'string',
					'description' => __( 'The basic idea or concept for the image.', 'wp-mcp-ai' ),
				),
				'enhance_prompt'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to use LM Studio to enhance the prompt before image generation.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'generate_image'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to actually generate the image after enhancing the prompt.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'image_provider'       => array(
					'type'        => 'string',
					'description' => __( 'Which provider to use for actual image generation.', 'wp-mcp-ai' ),
					'enum'        => array( 'openai', 'gemini', 'auto' ),
					'default'     => 'auto',
				),
				'model'                => array(
					'type'        => 'string',
					'description' => __( 'LM Studio model to use for prompt enhancement (e.g., google/gemma-3-12b).', 'wp-mcp-ai' ),
				),
				'style_guidance'       => array(
					'type'        => 'string',
					'description' => __( 'Optional style guidance for the prompt enhancement (e.g., "photorealistic", "artistic", "technical diagram").', 'wp-mcp-ai' ),
				),
				'file_name'            => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved image attachment.', 'wp-mcp-ai' ),
				),
				'timeout'              => array(
					'type'        => 'integer',
					'description' => __( 'Override the LM Studio request timeout in seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'generate_lm_studio_image', 'wp-mcp-ai' ),
				'payload' => __( 'generate_lm_studio_image', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Enhance prompt with local model', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_lm_studio_image` tool to enhance an image prompt using the local LM Studio model (like Gemma) before generating the actual image.', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use this tool.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use this tool.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error( 'wp_mcp_ai_missing_prompt', __( 'No prompt was supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$enhance_prompt  = isset( $arguments['enhance_prompt'] ) ? (bool) $arguments['enhance_prompt'] : true;
		$generate_image  = isset( $arguments['generate_image'] ) ? (bool) $arguments['generate_image'] : true;
		$image_provider  = isset( $arguments['image_provider'] ) ? sanitize_key( $arguments['image_provider'] ) : 'auto';
		$style_guidance  = isset( $arguments['style_guidance'] ) ? sanitize_text_field( $arguments['style_guidance'] ) : '';
		$file_name       = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';
		$timeout         = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 0;
		$model           = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';

		$result = array(
			'original_prompt' => $prompt,
		);

		// Step 1: Enhance prompt using LM Studio if requested.
		if ( $enhance_prompt ) {
			$enhanced = $this->enhance_prompt_with_lm_studio( $prompt, $style_guidance, $model, $timeout );

			if ( is_wp_error( $enhanced ) ) {
				return $enhanced;
			}

			$result['enhanced_prompt'] = $enhanced['enhanced_prompt'];
			$result['enhancement_model'] = isset( $enhanced['model'] ) ? $enhanced['model'] : '';
			$result['lm_studio_used'] = true;

			// Use enhanced prompt for image generation.
			$prompt = $enhanced['enhanced_prompt'];
		} else {
			$result['enhanced_prompt'] = $prompt;
			$result['lm_studio_used'] = false;
		}

		// Step 2: Generate image if requested.
		if ( $generate_image ) {
			$image_result = $this->generate_image_with_provider( $prompt, $image_provider, $file_name, $user_id, $context );

			if ( is_wp_error( $image_result ) ) {
				// Return partial result with enhanced prompt even if image generation fails.
				$result['image_error'] = $image_result->get_error_message();
				$result['image_generated'] = false;
			} else {
				// Merge image generation results.
				$result = array_merge( $result, $image_result );
				$result['image_generated'] = true;
			}
		} else {
			$result['image_generated'] = false;
		}

		/**
		 * Allow third parties to filter the LM Studio image generation result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_lm_studio_image_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Enhance an image prompt using LM Studio.
	 *
	 * @param string $prompt         Original prompt.
	 * @param string $style_guidance Style guidance.
	 * @param string $model          LM Studio model to use.
	 * @param int    $timeout        Request timeout.
	 * @return array|WP_Error Enhanced prompt or error.
	 */
	protected function enhance_prompt_with_lm_studio( $prompt, $style_guidance = '', $model = '', $timeout = 0 ) {
		$client = new WP_MCP_AI_LM_Studio_Client();

		// Build enhancement instruction.
		$instruction = $this->build_enhancement_instruction( $prompt, $style_guidance );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => __( 'You are an expert at creating detailed, effective prompts for AI image generation. When given a basic idea, you enhance it with specific visual details, lighting, composition, and style guidance to produce better image results.', 'wp-mcp-ai' ),
			),
			array(
				'role'    => 'user',
				'content' => $instruction,
			),
		);

		$options = array();

		if ( '' !== $model ) {
			$options['model'] = $model;
		}

		if ( $timeout > 0 ) {
			$options['timeout'] = max( 5, min( 300, $timeout ) );
		}

		WP_MCP_AI_Logger::log_event( 'lm_studio_image_prompt_enhancement', 'Enhancing image prompt with LM Studio.', array( 'original_prompt' => $prompt ) );

		$response = $client->create_chat_completion( $messages, $options );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio prompt enhancement failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$enhanced_prompt = '';

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$content = $response['choices'][0]['message']['content'];

			// Handle both array and string content formats.
			if ( is_array( $content ) ) {
				foreach ( $content as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) ) {
						$enhanced_prompt .= $part['text'];
					} elseif ( is_string( $part ) ) {
						$enhanced_prompt .= $part;
					}
				}
			} else {
				$enhanced_prompt = (string) $content;
			}
		}

		$enhanced_prompt = trim( $enhanced_prompt );

		if ( '' === $enhanced_prompt ) {
			return new WP_Error( 'wp_mcp_ai_empty_enhancement', __( 'LM Studio returned an empty enhanced prompt.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event( 'lm_studio_image_prompt_enhancement', 'Prompt enhancement completed.', array( 'enhanced_prompt' => $enhanced_prompt ) );

		return array(
			'enhanced_prompt' => $enhanced_prompt,
			'model'           => isset( $response['model'] ) ? $response['model'] : '',
			'provider'        => 'lm_studio',
		);
	}

	/**
	 * Build the enhancement instruction for LM Studio.
	 *
	 * @param string $prompt         Original prompt.
	 * @param string $style_guidance Style guidance.
	 * @return string Enhancement instruction.
	 */
	protected function build_enhancement_instruction( $prompt, $style_guidance = '' ) {
		$instruction = sprintf(
			/* translators: %s: Original image prompt */
			__( 'Enhance this image generation prompt to be more detailed and effective: "%s"', 'wp-mcp-ai' ),
			$prompt
		);

		if ( '' !== $style_guidance ) {
			$instruction .= "\n\n" . sprintf(
				/* translators: %s: Style guidance */
				__( 'Style guidance: %s', 'wp-mcp-ai' ),
				$style_guidance
			);
		}

		$instruction .= "\n\n" . __( 'Provide only the enhanced prompt without any additional explanation or commentary. Focus on specific visual details, lighting, composition, and artistic style.', 'wp-mcp-ai' );

		return $instruction;
	}

	/**
	 * Generate an image using the specified provider.
	 *
	 * @param string $prompt         Enhanced prompt.
	 * @param string $image_provider Provider to use (openai, gemini, or auto).
	 * @param string $file_name      File name for the image.
	 * @param int    $user_id        User ID.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Image generation result or error.
	 */
	protected function generate_image_with_provider( $prompt, $image_provider, $file_name, $user_id, $context ) {
		// Determine which provider to use.
		$provider = $this->resolve_image_provider( $image_provider );

		if ( 'openai' === $provider ) {
			return $this->generate_with_openai( $prompt, $file_name, $user_id, $context );
		} elseif ( 'gemini' === $provider ) {
			return $this->generate_with_gemini( $prompt, $file_name, $user_id, $context );
		}

		return new WP_Error( 'wp_mcp_ai_no_image_provider', __( 'No suitable image generation provider is available.', 'wp-mcp-ai' ) );
	}

	/**
	 * Resolve which image provider to use.
	 *
	 * @param string $preference Provider preference (openai, gemini, or auto).
	 * @return string Provider to use.
	 */
	protected function resolve_image_provider( $preference ) {
		if ( 'openai' === $preference || 'gemini' === $preference ) {
			return $preference;
		}

		// Auto mode: check settings to determine which provider is available.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Prefer OpenAI if available.
			if ( ! empty( $settings['openai_api_key'] ) ) {
				return 'openai';
			}

			// Fall back to Gemini.
			if ( ! empty( $settings['gemini_api_key'] ) ) {
				return 'gemini';
			}
		}

		// Default to OpenAI.
		return 'openai';
	}

	/**
	 * Generate image using OpenAI.
	 *
	 * @param string $prompt    Enhanced prompt.
	 * @param string $file_name File name.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Image generation result or error.
	 */
	protected function generate_with_openai( $prompt, $file_name, $user_id, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php';
		}

		$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();

		$args = array(
			'prompt' => $prompt,
		);

		if ( '' !== $file_name ) {
			$args['file_name'] = $file_name;
		}

		$result = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add provider info.
		$result['image_provider'] = 'openai';

		return $result;
	}

	/**
	 * Generate image using Gemini.
	 *
	 * @param string $prompt    Enhanced prompt.
	 * @param string $file_name File name.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Image generation result or error.
	 */
	protected function generate_with_gemini( $prompt, $file_name, $user_id, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Gemini_Image' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php';
		}

		$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();

		$args = array(
			'prompt' => $prompt,
		);

		if ( '' !== $file_name ) {
			$args['file_name'] = $file_name;
		}

		$result = $tool->execute( $args, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add provider info.
		$result['image_provider'] = 'gemini';

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',    // Requires LM Studio + image provider credentials.
			'requires-capability',     // Requires user capabilities.
			'write',                   // Creates media files when generating images.
			'async',                   // May take significant time.
			'rate-limited',            // Subject to rate limits.
			'requires-model',          // Requires LM Studio model specification.
			'consumes-tokens',         // Uses AI credits/tokens.
			'model-dependent',         // Output quality varies by model.
			'local-ai-compatible',     // Works with local LM Studio.
		);
	}

	/**
	 * Sanitize tool result before passing to LLM.
	 *
	 * Strips large base64-encoded image data to prevent bloating the LLM context.
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip base64 content to reduce token usage.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			unset( $result['content']['data'] );
			unset( $result['content']['data_url'] );

			if ( empty( $result['content'] ) ) {
				unset( $result['content'] );
			}
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'original_prompt',
			'enhanced_prompt',
			'enhancement_model',
			'lm_studio_used',
			'image_generated',
			'image_provider',
			'attachment_id',
			'url',
			'download_url',
			'file_name',
			'mime_type',
			'bytes',
			'title',
			'model',
			'provider',
			'usage',
			'image_error',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}
}
