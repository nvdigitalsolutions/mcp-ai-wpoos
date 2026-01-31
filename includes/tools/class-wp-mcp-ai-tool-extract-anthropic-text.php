<?php
/**
 * Tool for extracting text from images using Anthropic Claude's vision OCR capabilities.
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
 * Provides a tool for extracting text from images (OCR) via Anthropic's Claude vision API.
 */
class WP_MCP_AI_Tool_Extract_Anthropic_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Minimum tokens required for OCR tasks to ensure sufficient output space.
	 */
	const MIN_OCR_TOKENS = 2048;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'extract_anthropic_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Extract Text from Image (Anthropic OCR)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extracts all visible text from images using Anthropic Claude\'s advanced OCR capabilities. Supports documents, screenshots, handwriting, and complex layouts.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$default_model = isset( $settings['anthropic_vision_model'] ) && ! empty( $settings['anthropic_vision_model'] )
			? $settings['anthropic_vision_model']
			: ( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-3-5-sonnet-20241022' );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'    => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID containing the image to extract text from.', 'mcp-ai-wpoos' ),
				),
				'file_id'          => $this->get_file_id_parameter_schema(),
				'url'              => $this->get_url_parameter_schema( 'image' ),
				'image_url'        => array(
					'type'        => 'string',
					'description' => __( 'Direct URL to the image. Alternative to attachment_id or file_id.', 'mcp-ai-wpoos' ),
				),
				'model'            => array(
					'type'        => 'string',
					'description' => __( 'Anthropic model to use for OCR. Claude 3.5 Sonnet recommended for best accuracy.', 'mcp-ai-wpoos' ),
					'default'     => $default_model,
				),
				'preserve_layout'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true, attempts to preserve the original text layout and formatting.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_metadata' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, includes metadata like text locations, confidence scores, and formatting information.', 'mcp-ai-wpoos' ),
					'default'     => false,
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
				__( 'You must be logged in to use text extraction.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to extract text from images.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Get options.
		$preserve_layout  = isset( $arguments['preserve_layout'] ) ? (bool) $arguments['preserve_layout'] : false;
		$include_metadata = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : false;

		// Build prompt based on options.
		if ( $preserve_layout ) {
			$prompt = 'Extract all text from this image, preserving the original layout, formatting, line breaks, and structure as much as possible. Return only the extracted text.';
		} else {
			$prompt = 'Extract all visible text from this image and return it as plain text. Include all text you can see, even if it\'s small or partially visible. Return only the extracted text without any additional commentary.';
		}

		if ( $include_metadata ) {
			$prompt .= ' Additionally, provide information about text locations, font sizes, and formatting where possible.';
		}

		// Get model.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$model    = isset( $arguments['model'] ) && ! empty( $arguments['model'] )
			? sanitize_text_field( $arguments['model'] )
			: ( isset( $settings['anthropic_vision_model'] ) && ! empty( $settings['anthropic_vision_model'] )
				? $settings['anthropic_vision_model']
				: ( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-3-5-sonnet-20241022' ) );

		// Get max tokens - OCR can produce a lot of text.
		$max_tokens = self::MIN_OCR_TOKENS;
		if ( isset( $settings['anthropic_max_image_tokens'] ) && ! empty( $settings['anthropic_max_image_tokens'] ) ) {
			$max_tokens = absint( $settings['anthropic_max_image_tokens'] );
			if ( $max_tokens < self::MIN_OCR_TOKENS ) {
				$max_tokens = self::MIN_OCR_TOKENS; // Ensure sufficient tokens for OCR.
			}
		}

		// Create Anthropic client.
		if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_class',
				__( 'Anthropic client class not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$client = new WP_MCP_AI_Anthropic_Client();

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

		// Execute the OCR request.
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

			// Extract the text.
			$extracted_text = '';
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$extracted_text = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				if ( is_array( $response['content'] ) ) {
					// Handle array of content blocks.
					foreach ( $response['content'] as $block ) {
						if ( isset( $block['text'] ) ) {
							$extracted_text .= $block['text'];
						}
					}
				} elseif ( is_string( $response['content'] ) ) {
					$extracted_text = $response['content'];
				}
			}

			if ( empty( $extracted_text ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_text_found',
					__( 'No text could be extracted from the image.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			// Return structured result.
			return array(
				'text'              => $extracted_text,
				'model_used'        => $model,
				'image_url'         => $image_url,
				'preserve_layout'   => $preserve_layout,
				'include_metadata'  => $include_metadata,
				'character_count'   => strlen( $extracted_text ),
				'word_count'        => str_word_count( $extracted_text ),
				'metadata'          => array(
					'provider'   => 'anthropic',
					'model'      => $model,
					'max_tokens' => $max_tokens,
					'timestamp'  => current_time( 'mysql' ),
					'ocr_method' => 'claude_vision',
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
