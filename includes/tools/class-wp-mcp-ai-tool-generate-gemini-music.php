<?php
/**
 * Tool that generates music using Google's Lyria model via third-party APIs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-music-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for generating music via Google Gemini Lyria.
 */
class WP_MCP_AI_Tool_Generate_Gemini_Music implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_gemini_music';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Gemini Music', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates instrumental music using Google Gemini Lyria model. Describe the desired music style, mood, genre, and instrumentation in the prompt.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'           => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the desired music. Include genre, mood, tempo, instrumentation, and style. Examples: "Upbeat jazz with piano and saxophone, sunny afternoon vibe", "Dark ambient electronic music with deep bass, mysterious atmosphere". Note: Use weighted_prompts for combining multiple styles.', 'wp-mcp-ai' ),
				),
				'weighted_prompts' => array(
					'type'        => 'array',
					'description' => __( 'Array of prompts with weights for combining multiple music styles. Each item should have "text" and "weight" (0-1). Example: [{"text": "Harmonica", "weight": 0.3}, {"text": "Afrobeat", "weight": 0.7}]. If provided, overrides the "prompt" parameter.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'text'   => array(
								'type'        => 'string',
								'description' => __( 'Music style or genre description.', 'wp-mcp-ai' ),
							),
							'weight' => array(
								'type'        => 'number',
								'description' => __( 'Weight for this style (0-1). Higher weight means more influence.', 'wp-mcp-ai' ),
								'minimum'     => 0,
								'maximum'     => 1,
							),
						),
						'required'   => array( 'text', 'weight' ),
					),
				),
				'negative_prompt'  => array(
					'type'        => 'string',
					'description' => __( 'Optional. Elements to exclude from the music. Example: "No vocals, no drums".', 'wp-mcp-ai' ),
				),
				'duration'         => array(
					'type'        => 'integer',
					'description' => __( 'Duration of the music in seconds (5-120). Default is 30 seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 120,
					'default'     => 30,
				),
				'bpm'              => array(
					'type'        => 'integer',
					'description' => __( 'Beats per minute (40-200). Controls the tempo of the music. Default is 120.', 'wp-mcp-ai' ),
					'minimum'     => 40,
					'maximum'     => 200,
					'default'     => 120,
				),
				'density'          => array(
					'type'        => 'number',
					'description' => __( 'Note density (0-1). Controls how busy the music is. 0 = sparse, 1 = dense. Default is 0.5.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 1,
					'default'     => 0.5,
				),
				'mode'             => array(
					'type'        => 'string',
					'description' => __( 'Generation mode. "quality" for best quality (slower), "speed" for faster generation, "balanced" for middle ground. Default: "balanced".', 'wp-mcp-ai' ),
					'enum'        => array( 'quality', 'speed', 'balanced' ),
					'default'     => 'balanced',
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Title for the generated music track. If not provided, will be auto-generated from the prompt.', 'wp-mcp-ai' ),
				),
				'seed'             => array(
					'type'        => 'integer',
					'description' => __( 'Optional random seed for reproducible generation.', 'wp-mcp-ai' ),
				),
				'api_provider'     => array(
					'type'        => 'string',
					'description' => __( 'Third-party API provider to use. Options: "segmind", "aimlapi". Default: "segmind".', 'wp-mcp-ai' ),
					'enum'        => array( 'segmind', 'aimlapi' ),
					'default'     => 'segmind',
				),
				'timeout'          => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds. Default is 120.', 'wp-mcp-ai' ),
					'minimum'     => 30,
					'maximum'     => 300,
					'default'     => 120,
				),
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
			'external-api',
			'media-generation',
			'audio',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	/**
	 * Execute the tool.
	 *
	 * SoC: Tool handles validation and permission checks, delegates to service for business logic.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Validate user permissions.
		if ( ! $has_token && ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_permissions',
				__( 'You do not have permission to generate music.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Extract and validate arguments.
		$prompt           = isset( $arguments['prompt'] ) ? $arguments['prompt'] : '';
		$weighted_prompts = isset( $arguments['weighted_prompts'] ) ? $arguments['weighted_prompts'] : array();
		$title            = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		// Require either prompt or weighted_prompts.
		if ( empty( $prompt ) && empty( $weighted_prompts ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_prompt',
				__( 'Music generation requires either "prompt" or "weighted_prompts".', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Auto-generate title if not provided.
		if ( empty( $title ) ) {
			if ( ! empty( $weighted_prompts ) && is_array( $weighted_prompts ) ) {
				// Use first weighted prompt text for title.
				$title = isset( $weighted_prompts[0]['text'] ) ? substr( $weighted_prompts[0]['text'], 0, 40 ) : 'Generated Music';
			} else {
				$title = substr( $prompt, 0, 40 );
			}
			$title .= ' - ' . gmdate( 'Y-m-d H:i' );
		}

		// Prepare service options (pass through all relevant arguments).
		$service_options = array(
			'weighted_prompts' => $weighted_prompts,
			'negative_prompt'  => isset( $arguments['negative_prompt'] ) ? $arguments['negative_prompt'] : '',
			'duration'         => isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 30,
			'bpm'              => isset( $arguments['bpm'] ) ? absint( $arguments['bpm'] ) : 120,
			'density'          => isset( $arguments['density'] ) ? floatval( $arguments['density'] ) : 0.5,
			'mode'             => isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'balanced',
			'seed'             => isset( $arguments['seed'] ) ? absint( $arguments['seed'] ) : null,
			'api_provider'     => isset( $arguments['api_provider'] ) ? sanitize_key( $arguments['api_provider'] ) : 'segmind',
			'timeout'          => isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 120,
		);

		// Initialize music service.
		$music_service = new WP_MCP_AI_Gemini_Music_Service();

		// Generate music (service handles business logic).
		WP_MCP_AI_Logger::log_event(
			'tool_execute',
			'Generating music with Gemini Lyria',
			array(
				'tool'             => $this->get_slug(),
				'has_weighted'     => ! empty( $weighted_prompts ),
				'bpm'              => $service_options['bpm'],
				'density'          => $service_options['density'],
			)
		);

		$result = $music_service->generate_music( $prompt, $service_options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save to media library.
		$attachment_id = null;

		if ( ! empty( $result['audio_url'] ) ) {
			$format        = isset( $result['format'] ) ? $result['format'] : 'mp3';
			$final_prompt  = isset( $result['prompt'] ) ? $result['prompt'] : $prompt;
			$attachment_id = $music_service->save_to_media_library(
				$result['audio_url'],
				$title,
				$final_prompt,
				$format
			);

			if ( is_wp_error( $attachment_id ) ) {
				// Return the music URL even if upload failed.
				WP_MCP_AI_Logger::log_event(
					'tool_warning',
					'Music generated but upload failed',
					array( 'error' => $attachment_id->get_error_message() )
				);

				return array(
					'success'   => true,
					'audio_url' => $result['audio_url'],
					'prompt'    => $final_prompt,
					'duration'  => isset( $result['duration'] ) ? $result['duration'] : $service_options['duration'],
					'format'    => $format,
					'bpm'       => $service_options['bpm'],
					'density'   => $service_options['density'],
					'mode'      => $service_options['mode'],
					'warning'   => __( 'Music generated successfully but could not be saved to media library.', 'wp-mcp-ai' ),
				);
			}
		}

		// Get attachment URL.
		$audio_url    = $attachment_id ? wp_get_attachment_url( $attachment_id ) : ( $result['audio_url'] ?? '' );
		$final_prompt = isset( $result['prompt'] ) ? $result['prompt'] : $prompt;

		// Prepare response.
		$response = array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			'audio_url'     => $audio_url,
			'title'         => $title,
			'prompt'        => $final_prompt,
			'duration'      => isset( $result['duration'] ) ? $result['duration'] : $service_options['duration'],
			'format'        => isset( $result['format'] ) ? $result['format'] : 'mp3',
			'bpm'           => $service_options['bpm'],
			'density'       => $service_options['density'],
			'mode'          => $service_options['mode'],
		);

		if ( ! empty( $weighted_prompts ) ) {
			$response['weighted_prompts'] = $weighted_prompts;
		}

		WP_MCP_AI_Logger::log_event(
			'tool_success',
			'Music generated successfully',
			array(
				'tool'          => $this->get_slug(),
				'attachment_id' => $attachment_id,
			)
		);

		return $response;
	}

	/**
	 * Generate a title from the prompt (removed build_final_prompt - now in service).
	 *
	 * @param string $prompt Music generation prompt.
	 * @return string Generated title.
	 */
	protected function generate_title_from_prompt( $prompt ) {
		// Take first 50 characters and clean up.
		$title = substr( $prompt, 0, 50 );

		// Remove special characters.
		$title = preg_replace( '/[^a-zA-Z0-9\s-]/', '', $title );

		// Add timestamp for uniqueness.
		$title = trim( $title ) . ' - ' . gmdate( 'Y-m-d H:i' );

		return $title;
	}
}
