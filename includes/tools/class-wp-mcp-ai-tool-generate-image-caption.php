<?php
/**
 * Tool for generating captions for images using AI vision capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Generates descriptive captions for images using AI vision models.
 */
class WP_MCP_AI_Tool_Generate_Image_Caption implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_image_caption';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Image Caption', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates detailed captions for images to provide context and enhance content using AI vision capabilities.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the image to analyze.', 'wp-mcp-ai' ),
				),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative to image_url.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID to analyze.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the image to help generate more relevant captions.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user capabilities.
		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate image captions.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Get image source.
		$image_url     = '';
		$image_content = '';

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$image_url     = wp_get_attachment_url( $attachment_id );

			if ( ! $image_url ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					__( 'Invalid attachment ID provided.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
		} elseif ( ! empty( $arguments['image_content'] ) ) {
			$image_content = $arguments['image_content'];
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_image',
				__( 'Either image_url, image_content, or attachment_id must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';

		// Build prompt.
		$user_context = isset( $arguments['context'] ) ? sanitize_text_field( $arguments['context'] ) : '';
		$prompt       = $this->build_prompt( $user_context );

		// Call vision model based on provider and capture metadata.
		$api_response = $this->call_vision_model( $image_url, $image_content, $prompt, $default_provider );

		if ( is_wp_error( $api_response ) ) {
			return $api_response;
		}

		// Extract caption and metadata.
		$caption  = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		$result = array(
			'caption' => $caption,
			'success' => true,
		);

		// Include provider/model/usage metadata for accurate cost tracking.
		if ( $provider ) {
			$result['provider'] = $provider;
		}
		if ( $model ) {
			$result['model'] = $model;
		}
		if ( $usage ) {
			$result['usage'] = $usage;
		}

		return $result;
	}

	/**
	 * Build the prompt for caption generation.
	 *
	 * @param string $user_context Optional user-provided context.
	 * @return string
	 */
	private function build_prompt( $user_context = '' ) {
		$prompt = 'Generate a detailed, engaging caption for this image. The caption should describe what is happening in the image, provide context, and be suitable for use in blog posts or social media. Be descriptive but concise, aiming for 1-2 sentences.';

		if ( ! empty( $user_context ) ) {
			$prompt .= ' Context: ' . $user_context;
		}

		return $prompt;
	}

	/**
	 * Call vision model to analyze image.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param string $provider      AI provider to use.
	 * @return array|WP_Error Response with metadata or error.
	 */
	private function call_vision_model( $image_url, $image_content, $prompt, $provider ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( 'gemini' === $provider ) {
			return $this->call_gemini_vision( $image_url, $image_content, $prompt, $settings );
		} else {
			// Default to OpenAI.
			return $this->call_openai_vision( $image_url, $image_content, $prompt, $settings );
		}
	}

	/**
	 * Call OpenAI vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param array  $settings      Plugin settings.
	 * @return string|WP_Error Caption or error.
	 */
	private function call_openai_vision( $image_url, $image_content, $prompt, $settings ) {
		$api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'OpenAI API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Build message content.
		$content = array(
			array(
				'type' => 'text',
				'text' => $prompt,
			),
		);

		if ( ! empty( $image_url ) ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => $image_url,
				),
			);
		} else {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:image/jpeg;base64,' . $image_content,
				),
			);
		}

		$request_body = array(
			'model'      => 'gpt-4o-mini',
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'max_tokens' => 150,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'OpenAI API returned error code %d.', 'wp-mcp-ai' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from OpenAI API.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Return text with metadata for cost tracking.
		return array(
			'text'     => trim( $body['choices'][0]['message']['content'] ),
			'provider' => 'openai',
			'model'    => isset( $body['model'] ) ? $body['model'] : 'gpt-4o-mini',
			'usage'    => isset( $body['usage'] ) ? array(
				'prompt_tokens'     => isset( $body['usage']['prompt_tokens'] ) ? (int) $body['usage']['prompt_tokens'] : 0,
				'completion_tokens' => isset( $body['usage']['completion_tokens'] ) ? (int) $body['usage']['completion_tokens'] : 0,
				'total_tokens'      => isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : 0,
			) : null,
		);
	}

	/**
	 * Call Gemini vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param array  $settings      Plugin settings.
	 * @return string|WP_Error Caption or error.
	 */
	private function call_gemini_vision( $image_url, $image_content, $prompt, $settings ) {
		$api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// If we have a URL, we need to download the image to get base64.
		if ( ! empty( $image_url ) && empty( $image_content ) ) {
			$image_response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

			if ( is_wp_error( $image_response ) ) {
				return $image_response;
			}

			$image_data    = wp_remote_retrieve_body( $image_response );
			$image_content = base64_encode( $image_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$model        = 'gemini-1.5-flash';
		$request_body = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
						array(
							'inline_data' => array(
								'mime_type' => 'image/jpeg',
								'data'      => $image_content,
							),
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Gemini API returned error code %d.', 'wp-mcp-ai' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Gemini API.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		// Extract usage metadata if available.
		$usage = null;
		if ( isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ) {
			$usage = array(
				'prompt_tokens'     => isset( $body['usageMetadata']['promptTokenCount'] ) ? (int) $body['usageMetadata']['promptTokenCount'] : 0,
				'completion_tokens' => isset( $body['usageMetadata']['candidatesTokenCount'] ) ? (int) $body['usageMetadata']['candidatesTokenCount'] : 0,
				'total_tokens'      => isset( $body['usageMetadata']['totalTokenCount'] ) ? (int) $body['usageMetadata']['totalTokenCount'] : 0,
			);
		}

		// Return text with metadata for cost tracking.
		return array(
			'text'     => trim( $body['candidates'][0]['content']['parts'][0]['text'] ),
			'provider' => 'gemini',
			'model'    => $model,
			'usage'    => $usage,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'consumes-tokens',           // Uses AI model tokens.
			'requires-capability',  // Requires user capabilities.
			'external-api',              // Makes external API calls.
			'network-dependent',         // Requires internet connectivity.
			'requires-credentials',      // Requires API credentials.
			'requires-vision-model',     // Requires vision-capable model.
			'read-only',                 // Only reads data.
			'non-deterministic',         // Results may vary.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'vision', // Requires model capable of processing and understanding images.
		);
	}
}
