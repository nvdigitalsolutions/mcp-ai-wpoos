<?php
/**
 * Tool for analyzing images using Anthropic Claude's vision capabilities.
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
 * Provides a tool for analyzing images via Anthropic's Claude vision API.
 */
class WP_MCP_AI_Tool_Analyze_Anthropic_Image implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_anthropic_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Image with Anthropic', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes images using Anthropic Claude\'s vision capabilities. Supports detailed image description, object detection, text extraction (OCR), and visual question answering.', 'mcp-ai-wpoos' );
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
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'Anthropic model to use for vision analysis. All Claude 3+ models support vision.', 'mcp-ai-wpoos' ),
					'default'     => $default_model,
				),
				'max_tokens'     => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Maximum tokens for the response. Higher values allow more detailed analysis.', 'mcp-ai-wpoos' ),
					'default'     => 1024,
				),
				'detail'         => array(
					'type'        => 'string',
					'description' => __( 'Detail level for image analysis: auto, high, or low. High provides more detailed analysis.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'auto', 'high', 'low' ),
					'default'     => 'auto',
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

		// Get model.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$model    = isset( $arguments['model'] ) && ! empty( $arguments['model'] )
			? sanitize_text_field( $arguments['model'] )
			: ( isset( $settings['anthropic_vision_model'] ) && ! empty( $settings['anthropic_vision_model'] )
				? $settings['anthropic_vision_model']
				: ( isset( $settings['anthropic_model'] ) ? $settings['anthropic_model'] : 'claude-3-5-sonnet-20241022' ) );

		// Get max tokens - use argument if provided, otherwise fall back to settings, then default.
		$max_tokens = 1024; // Default.

		if ( isset( $settings['anthropic_max_image_tokens'] ) && ! empty( $settings['anthropic_max_image_tokens'] ) ) {
			$max_tokens = absint( $settings['anthropic_max_image_tokens'] );
		}

		// Allow argument to override settings.
		if ( isset( $arguments['max_tokens'] ) && ! empty( $arguments['max_tokens'] ) ) {
			$max_tokens = absint( $arguments['max_tokens'] );
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

		// Execute the vision request.
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
					// Handle array of content blocks.
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

			// Return structured result.
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
