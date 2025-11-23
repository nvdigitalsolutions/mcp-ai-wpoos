<?php
/**
 * Tool that analyzes audio content using Google Gemini's multimodal capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for analyzing audio content using Gemini.
 */
class WP_MCP_AI_Tool_Analyze_Audio_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_audio_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Audio Content', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes audio files using Google Gemini to identify music genre, mood, instruments, structure, and other characteristics.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'audio_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL to the audio file to analyze (mp3, wav, aac, flac, ogg).', 'wp-mcp-ai' ),
					'format'      => 'uri',
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the audio file to analyze. Either audio_url or attachment_id must be provided.', 'wp-mcp-ai' ),
				),
				'analysis_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of analysis to perform. Options: "general" (default), "technical", "musical", "transcription".', 'wp-mcp-ai' ),
					'enum'        => array( 'general', 'technical', 'musical', 'transcription' ),
					'default'     => 'general',
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'Gemini model to use for analysis. Default: gemini-2.5-pro.', 'wp-mcp-ai' ),
					'default'     => 'gemini-2.5-pro',
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
			'read-only',
			'external-api',
			'audio',
			'analysis',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		// Validate permissions.
		if ( ! $has_token && ( ! $user_id || ! user_can( $user_id, 'read' ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_permissions',
				__( 'You do not have permission to analyze audio.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Get audio URL or attachment ID.
		$audio_url     = isset( $arguments['audio_url'] ) ? esc_url_raw( $arguments['audio_url'] ) : '';
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$analysis_type = isset( $arguments['analysis_type'] ) ? sanitize_key( $arguments['analysis_type'] ) : 'general';
		$model         = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gemini-2.5-pro';

		// Resolve audio URL from attachment if needed.
		if ( empty( $audio_url ) && $attachment_id > 0 ) {
			$audio_url = wp_get_attachment_url( $attachment_id );

			if ( ! $audio_url ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attachment',
					__( 'Invalid attachment ID provided.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( empty( $audio_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_audio',
				__( 'Either audio_url or attachment_id must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Validate audio file type.
		$valid = $this->validate_audio_file( $audio_url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Upload audio to Gemini File API.
		WP_MCP_AI_Logger::log_event(
			'tool_execute',
			'Analyzing audio content',
			array(
				'tool'       => $this->get_slug(),
				'audio_url'  => $audio_url,
				'type'       => $analysis_type,
			)
		);

		$file_service = new WP_MCP_AI_Gemini_File_Service();
		$file_result  = $file_service->upload_file_from_url( $audio_url );

		if ( is_wp_error( $file_result ) ) {
			return $file_result;
		}

		$file_uri = $file_result['uri'];

		// Build analysis prompt based on type.
		$prompt = $this->build_analysis_prompt( $analysis_type );

		// Prepare Gemini request.
		$messages = array(
			array(
				'role'  => 'user',
				'parts' => array(
					array( 'text' => $prompt ),
					array(
						'file_data' => array(
							'file_uri'  => $file_uri,
							'mime_type' => $file_result['mimeType'],
						),
					),
				),
			),
		);

		// Call Gemini API.
		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.1, // Low temperature for consistent analysis.
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract analysis from response.
		$analysis = '';
		if ( isset( $result['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$analysis = $result['candidates'][0]['content']['parts'][0]['text'];
		}

		if ( empty( $analysis ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_analysis',
				__( 'Audio analysis returned empty results.', 'wp-mcp-ai' )
			);
		}

		// Prepare response.
		$response = array(
			'success'       => true,
			'analysis'      => $analysis,
			'analysis_type' => $analysis_type,
			'audio_url'     => $audio_url,
			'model'         => $model,
		);

		if ( $attachment_id > 0 ) {
			$response['attachment_id'] = $attachment_id;
		}

		WP_MCP_AI_Logger::log_event(
			'tool_success',
			'Audio analysis completed',
			array(
				'tool' => $this->get_slug(),
			)
		);

		return $response;
	}

	/**
	 * Validate audio file type.
	 *
	 * @param string $audio_url Audio URL.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	protected function validate_audio_file( $audio_url ) {
		$extension = strtolower( pathinfo( wp_parse_url( $audio_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		$allowed_extensions = array( 'mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a' );

		if ( ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_audio_format',
				sprintf(
					/* translators: 1: file extension, 2: allowed extensions */
					__( 'Invalid audio format "%1$s". Allowed formats: %2$s', 'wp-mcp-ai' ),
					$extension,
					implode( ', ', $allowed_extensions )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Build analysis prompt based on type.
	 *
	 * @param string $analysis_type Type of analysis.
	 * @return string Analysis prompt.
	 */
	protected function build_analysis_prompt( $analysis_type ) {
		switch ( $analysis_type ) {
			case 'technical':
				return 'Analyze this audio file and provide a technical analysis including: sample rate, bit depth (if determinable), frequency spectrum characteristics, dynamic range, noise floor, and any audio artifacts or quality issues.';

			case 'musical':
				return 'Analyze this music and provide a detailed musical analysis including: genre, subgenre, tempo (BPM), key signature, time signature, chord progressions, instrumentation, arrangement structure, mood and emotional characteristics, vocal style (if vocals present), production quality, and era/decade stylistic influences.';

			case 'transcription':
				return 'Transcribe the audio content. If it contains speech, provide a word-for-word transcription. If it contains music with vocals, transcribe the lyrics. If instrumental, describe the melodic and rhythmic patterns.';

			case 'general':
			default:
				return 'Analyze this audio file and provide a comprehensive overview including: type of content (music, speech, sound effects, etc.), genre/category, duration characteristics, quality assessment, notable features, mood or tone, and any relevant observations about the audio content.';
		}
	}
}
