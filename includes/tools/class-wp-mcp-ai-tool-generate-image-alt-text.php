<?php
/**
 * Tool for generating alt text for images using AI vision capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';

/**
 * Generates descriptive alt text for images using AI vision models.
 */
class WP_MCP_AI_Tool_Generate_Image_Alt_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_image_alt_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Image Alt Text', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates descriptive alt text for images to improve accessibility and SEO using AI vision capabilities.', 'mcp-ai-wpoos' );
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
					'description' => __( 'URL of the image to analyze.', 'mcp-ai-wpoos' ),
				),
				'url'           => $this->get_url_parameter_schema( 'image', __( 'URL of the image to analyze. Alternative to image_url.', 'mcp-ai-wpoos' ) ),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative to image_url.', 'mcp-ai-wpoos' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID to analyze.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'file_id'       => $this->get_file_id_parameter_schema(),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about the image to help generate more relevant alt text.', 'mcp-ai-wpoos' ),
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
				__( 'You do not have permission to generate image alt text.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Get image source.
		$image_url     = '';
		$image_content = '';
		$attachment_id = 0;

		// Try to resolve from attachment_id, file_id, or url first.
		if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) || ! empty( $arguments['url'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments );

			// Handle remote URL case.
			if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
				$image_url = $resolved['url'];
			} elseif ( is_wp_error( $resolved ) ) {
				return $resolved;
			} elseif ( $resolved > 0 ) {
				$attachment_id = $resolved;
				$image_url     = wp_get_attachment_url( $attachment_id );

				if ( ! $image_url ) {
					return new WP_Error(
						'wp_mcp_ai_invalid_attachment',
						__( 'Could not get URL for attachment.', 'mcp-ai-wpoos' ),
						array( 'status' => 400 )
					);
				}
			}
		}

		// Fallback to legacy image_url parameter.
		if ( '' === $image_url && ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
		}

		// Fallback to image_content.
		if ( '' === $image_url && ! empty( $arguments['image_content'] ) ) {
			$image_content = $arguments['image_content'];
		}

		// Validate we have an image source.
		if ( '' === $image_url && '' === $image_content ) {
			return new WP_Error(
				'wp_mcp_ai_missing_image',
				__( 'You must provide image_url, url, attachment_id, file_id, or image_content.', 'mcp-ai-wpoos' ),
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

		// Extract alt text and metadata.
		$alt_text = is_array( $api_response ) && isset( $api_response['text'] ) ? $api_response['text'] : $api_response;
		$usage    = is_array( $api_response ) && isset( $api_response['usage'] ) ? $api_response['usage'] : null;
		$model    = is_array( $api_response ) && isset( $api_response['model'] ) ? $api_response['model'] : '';
		$provider = is_array( $api_response ) && isset( $api_response['provider'] ) ? $api_response['provider'] : $default_provider;

		$result = array(
			'alt_text' => $alt_text,
			'success'  => true,
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
	 * Build the prompt for alt text generation.
	 *
	 * @param string $user_context Optional user-provided context.
	 * @return string
	 */
	private function build_prompt( $user_context = '' ) {
		$prompt = 'Generate concise, descriptive alt text for this image. The alt text should be helpful for accessibility purposes and SEO. Describe what you see in the image clearly and objectively. Keep it under 125 characters if possible.';

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
		} elseif ( 'anthropic' === $provider ) {
			return $this->call_anthropic_vision( $image_url, $image_content, $prompt, $settings );
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
	 * @return string|WP_Error Alt text or error.
	 */
	private function call_openai_vision( $image_url, $image_content, $prompt, $settings ) {
		$api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'OpenAI API key is not configured.', 'mcp-ai-wpoos' ),
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
			'max_tokens' => 100,
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
					__( 'OpenAI API returned error code %d.', 'mcp-ai-wpoos' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from OpenAI API.', 'mcp-ai-wpoos' ),
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
	 * @return string|WP_Error Alt text or error.
	 */
	private function call_gemini_vision( $image_url, $image_content, $prompt, $settings ) {
		$api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
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
					__( 'Gemini API returned error code %d.', 'mcp-ai-wpoos' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Gemini API.', 'mcp-ai-wpoos' ),
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
	 * Call Anthropic vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param array  $settings      Plugin settings.
	 * @return array|WP_Error Alt text with metadata or error.
	 */
	private function call_anthropic_vision( $image_url, $image_content, $prompt, $settings ) {
		if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_class',
				__( 'Anthropic client class not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$client = new WP_MCP_AI_Anthropic_Client();

		// Get model from settings.
		$model = isset( $settings['anthropic_vision_model'] ) && ! empty( $settings['anthropic_vision_model'] )
			? $settings['anthropic_vision_model']
			: ( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-3-5-sonnet-20241022' );

		// Build messages with image content.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => $image_url,
						),
					),
					array(
						'type' => 'text',
						'text' => $prompt,
					),
				),
			),
		);

		try {
			$response = $client->create_chat_completion(
				$messages,
				array(
					'model'      => $model,
					'max_tokens' => 150, // Alt text should be concise.
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract the alt text.
			$alt_text = '';
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$alt_text = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				if ( is_array( $response['content'] ) ) {
					foreach ( $response['content'] as $block ) {
						if ( isset( $block['text'] ) ) {
							$alt_text .= $block['text'];
						}
					}
				} elseif ( is_string( $response['content'] ) ) {
					$alt_text = $response['content'];
				}
			}

			if ( empty( $alt_text ) ) {
				return new WP_Error(
					'wp_mcp_ai_empty_response',
					__( 'Anthropic returned an empty response.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// Extract usage metadata if available.
			$usage = null;
			if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
				$usage = array(
					'prompt_tokens'     => isset( $response['usage']['input_tokens'] ) ? (int) $response['usage']['input_tokens'] : 0,
					'completion_tokens' => isset( $response['usage']['output_tokens'] ) ? (int) $response['usage']['output_tokens'] : 0,
					'total_tokens'      => ( isset( $response['usage']['input_tokens'] ) ? (int) $response['usage']['input_tokens'] : 0 ) + ( isset( $response['usage']['output_tokens'] ) ? (int) $response['usage']['output_tokens'] : 0 ),
				);
			}

			// Return text with metadata for cost tracking.
			return array(
				'text'     => trim( $alt_text ),
				'provider' => 'anthropic',
				'model'    => $model,
				'usage'    => $usage,
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_anthropic_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Anthropic API error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'sequential', 'orchestrator' ),

			'profession_tags'       => array( 'seo_specialist', 'content_creator' ),

			'risk_level'            => 'info',

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
