<?php
/**
 * Tool that generates audio effects and sound effects using Gemini Lyria.
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
 * Provides a tool for generating audio effects and sound effects.
 */
class WP_MCP_AI_Tool_Generate_Audio_Effects implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_audio_effects';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Audio Effects', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates sound effects and audio elements using Google Gemini Lyria. Perfect for creating ambient sounds, nature sounds, mechanical sounds, and other audio effects.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'description'     => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the audio effect or sound. Examples: "Rain falling on metal roof", "Ocean waves crashing on beach", "Futuristic sci-fi door opening sound", "Birds chirping in forest morning".', 'wp-mcp-ai' ),
				),
				'duration'        => array(
					'type'        => 'integer',
					'description' => __( 'Duration of the audio effect in seconds (5-60). Default is 10 seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 10,
				),
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Title for the audio effect. If not provided, will be auto-generated.', 'wp-mcp-ai' ),
				),
				'api_provider'    => array(
					'type'        => 'string',
					'description' => __( 'Third-party API provider. Options: "segmind", "aimlapi". Default: "segmind".', 'wp-mcp-ai' ),
					'enum'        => array( 'segmind', 'aimlapi' ),
					'default'     => 'segmind',
				),
			),
			'required'             => array( 'description' ),
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Validate user permissions.
		if ( ! $has_token && ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_permissions',
				__( 'You do not have permission to generate audio effects.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Extract and validate arguments.
		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$duration     = isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 10;
		$title        = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$api_provider = isset( $arguments['api_provider'] ) ? sanitize_key( $arguments['api_provider'] ) : 'segmind';

		$description = trim( $description );

		if ( empty( $description ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_description',
				__( 'Audio effect description is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Validate duration (shorter for sound effects).
		if ( $duration > 60 ) {
			$duration = 60;
		}

		// Auto-generate title if not provided.
		if ( empty( $title ) ) {
			$title = 'SFX: ' . substr( $description, 0, 40 );
		}

		// Build prompt optimized for sound effects.
		$prompt = $this->build_sound_effect_prompt( $description );

		// Initialize music service.
		$music_service = new WP_MCP_AI_Gemini_Music_Service();

		// Service options.
		$service_options = array(
			'duration'     => $duration,
			'api_provider' => $api_provider,
			'timeout'      => 90,
		);

		// Generate audio.
		WP_MCP_AI_Logger::log_event(
			'tool_execute',
			'Generating audio effect',
			array(
				'tool'        => $this->get_slug(),
				'description' => $description,
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
			$attachment_id = $music_service->save_to_media_library(
				$result['audio_url'],
				$title,
				$description,
				$format
			);

			if ( is_wp_error( $attachment_id ) ) {
				return array(
					'success'   => true,
					'audio_url' => $result['audio_url'],
					'description' => $description,
					'duration'  => isset( $result['duration'] ) ? $result['duration'] : $duration,
					'format'    => $format,
					'warning'   => __( 'Audio effect generated but could not be saved to media library.', 'wp-mcp-ai' ),
				);
			}
		}

		// Get attachment URL.
		$audio_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : ( $result['audio_url'] ?? '' );

		// Prepare response.
		$response = array(
			'success'       => true,
			'attachment_id' => $attachment_id,
			'audio_url'     => $audio_url,
			'title'         => $title,
			'description'   => $description,
			'duration'      => isset( $result['duration'] ) ? $result['duration'] : $duration,
			'format'        => isset( $result['format'] ) ? $result['format'] : 'mp3',
		);

		WP_MCP_AI_Logger::log_event(
			'tool_success',
			'Audio effect generated successfully',
			array(
				'tool'          => $this->get_slug(),
				'attachment_id' => $attachment_id,
			)
		);

		return $response;
	}

	/**
	 * Build optimized prompt for sound effect generation.
	 *
	 * @param string $description User description.
	 * @return string Optimized prompt.
	 */
	protected function build_sound_effect_prompt( $description ) {
		// Enhance prompt for better sound effect generation.
		return sprintf(
			'High-quality audio effect: %s. Clear, realistic sound design.',
			$description
		);
	}
}
