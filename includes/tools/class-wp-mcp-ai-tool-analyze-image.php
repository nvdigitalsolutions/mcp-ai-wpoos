<?php
/**
 * Tool for analyzing images using multiple AI vision providers.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-anthropic-client.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides a tool for analyzing images via multiple AI vision providers.
 * Supports OpenAI, Anthropic, and Gemini.
 */
class WP_MCP_AI_Tool_Analyze_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes images using AI vision capabilities from OpenAI, Anthropic, or Gemini. Supports detailed image description, object detection, text extraction (OCR), and visual question answering.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$default_provider = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID containing the image to analyze.', 'mcp-ai-wpoos' ),
				),
				'file_id'        => $this->get_file_id_parameter_schema(),
				'url'            => $this->get_url_parameter_schema( 'image' ),
				'image_url'      => array(
					'type'        => 'string',
					'description' => __( 'Direct URL to the image. Alternative to attachment_id or file_id.', 'mcp-ai-wpoos' ),
				),
				'prompt'         => array(
					'type'        => 'string',
					'description' => __( 'Question or instruction for analyzing the image. For example: "Describe this image in detail", "What objects are visible?", "Extract all text from this image".', 'mcp-ai-wpoos' ),
					'default'     => 'Describe this image in detail, including objects, people, text, colors, and composition.',
				),
				'provider'       => array(
					'type'        => 'string',
					'description' => __( 'AI provider to use: openai, anthropic, or gemini. Defaults to your configured default provider.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'openai', 'anthropic', 'gemini' ),
					'default'     => $default_provider,
				),
				'max_tokens'     => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Maximum tokens for the response. Higher values allow more detailed analysis.', 'mcp-ai-wpoos' ),
					'default'     => 1024,
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
		// Resolve attachment ID from various input formats.
		$resolved = $this->resolve_attachment_id( $arguments );

		// Handle remote URL case.
		$image_url     = '';
		$image_content = '';

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
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_image',
				__( 'You must provide an image via attachment_id, file_id, url, or image_url.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		// Check user capabilities.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be logged in to use image analysis.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to analyze images.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Get prompt.
		$prompt = isset( $arguments['prompt'] ) && ! empty( $arguments['prompt'] )
			? sanitize_text_field( $arguments['prompt'] )
			: 'Describe this image in detail, including objects, people, text, colors, and composition.';

		// Get provider.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$provider = isset( $arguments['provider'] ) && ! empty( $arguments['provider'] )
			? sanitize_text_field( $arguments['provider'] )
			: ( isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai' );

		// Get max tokens.
		$max_tokens = isset( $arguments['max_tokens'] ) && ! empty( $arguments['max_tokens'] )
			? absint( $arguments['max_tokens'] )
			: 1024;

		// Call the appropriate provider.
		$response = $this->call_vision_provider( $image_url, $image_content, $prompt, $provider, $max_tokens, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Return structured result.
		return $response;
	}

	/**
	 * Call vision provider.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param string $provider      AI provider to use.
	 * @param int    $max_tokens    Maximum tokens for response.
	 * @param array  $settings      Plugin settings.
	 * @return array|WP_Error Response with metadata or error.
	 */
	private function call_vision_provider( $image_url, $image_content, $prompt, $provider, $max_tokens, $settings ) {
		switch ( $provider ) {
			case 'anthropic':
				return $this->call_anthropic_vision( $image_url, $image_content, $prompt, $max_tokens, $settings );
			case 'gemini':
				return $this->call_gemini_vision( $image_url, $image_content, $prompt, $max_tokens, $settings );
			case 'openai':
			default:
				return $this->call_openai_vision( $image_url, $image_content, $prompt, $max_tokens, $settings );
		}
	}

	/**
	 * Call OpenAI vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param int    $max_tokens    Maximum tokens for response.
	 * @param array  $settings      Plugin settings.
	 * @return array|WP_Error Response or error.
	 */
	private function call_openai_vision( $image_url, $image_content, $prompt, $max_tokens, $settings ) {
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
		} elseif ( ! empty( $image_content ) ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => 'data:image/jpeg;base64,' . $image_content,
				),
			);
		}

		$model        = isset( $settings['default_model'] ) ? $settings['default_model'] : 'gpt-4o-mini';
		$request_body = array(
			'model'      => $model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'max_tokens' => $max_tokens,
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

		return array(
			'analysis'   => trim( $body['choices'][0]['message']['content'] ),
			'model_used' => isset( $body['model'] ) ? $body['model'] : $model,
			'image_url'  => $image_url,
			'prompt'     => $prompt,
			'metadata'   => array(
				'provider'   => 'openai',
				'model'      => isset( $body['model'] ) ? $body['model'] : $model,
				'max_tokens' => $max_tokens,
				'usage'      => isset( $body['usage'] ) ? $body['usage'] : null,
				'timestamp'  => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Call Anthropic vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param int    $max_tokens    Maximum tokens for response.
	 * @param array  $settings      Plugin settings.
	 * @return array|WP_Error Response or error.
	 */
	private function call_anthropic_vision( $image_url, $image_content, $prompt, $max_tokens, $settings ) {
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

		// Use settings for max_tokens if configured.
		if ( isset( $settings['anthropic_max_image_tokens'] ) && ! empty( $settings['anthropic_max_image_tokens'] ) ) {
			$configured_tokens = absint( $settings['anthropic_max_image_tokens'] );
			if ( $configured_tokens > 0 ) {
				$max_tokens = $configured_tokens;
			}
		}

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
					'max_tokens' => $max_tokens,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract the analysis text.
			$analysis = '';
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$analysis = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				if ( is_array( $response['content'] ) ) {
					foreach ( $response['content'] as $block ) {
						if ( isset( $block['text'] ) ) {
							$analysis .= $block['text'];
						}
					}
				} elseif ( is_string( $response['content'] ) ) {
					$analysis = $response['content'];
				}
			}

			if ( empty( $analysis ) ) {
				return new WP_Error(
					'wp_mcp_ai_empty_response',
					__( 'Anthropic returned an empty response.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			return array(
				'analysis'   => $analysis,
				'model_used' => $model,
				'image_url'  => $image_url,
				'prompt'     => $prompt,
				'metadata'   => array(
					'provider'   => 'anthropic',
					'model'      => $model,
					'max_tokens' => $max_tokens,
					'timestamp'  => current_time( 'mysql' ),
				),
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
	 * Call Gemini vision model.
	 *
	 * @param string $image_url     Image URL.
	 * @param string $image_content Base64 image content.
	 * @param string $prompt        Prompt for the model.
	 * @param int    $max_tokens    Maximum tokens for response.
	 * @param array  $settings      Plugin settings.
	 * @return array|WP_Error Response or error.
	 */
	private function call_gemini_vision( $image_url, $image_content, $prompt, $max_tokens, $settings ) {
		$api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Gemini requires base64 content, not URLs.
		if ( ! empty( $image_url ) && empty( $image_content ) ) {
			// Fetch image and convert to base64.
			$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_image_fetch_error',
					sprintf(
						/* translators: %d: HTTP response code */
						__( 'Failed to fetch image, HTTP code %d.', 'mcp-ai-wpoos' ),
						$response_code
					),
					array( 'status' => $response_code )
				);
			}

			$image_content = base64_encode( wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$model        = isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : 'gemini-1.5-flash';
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
			'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
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

		return array(
			'analysis'   => trim( $body['candidates'][0]['content']['parts'][0]['text'] ),
			'model_used' => $model,
			'image_url'  => $image_url,
			'prompt'     => $prompt,
			'metadata'   => array(
				'provider'   => 'gemini',
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'timestamp'  => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_authentication() {
		return true;
	}
}
