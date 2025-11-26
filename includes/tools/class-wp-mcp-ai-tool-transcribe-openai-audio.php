<?php
/**
 * Tool that transcribes or translates audio into English text using OpenAI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';

/**
 * Provides a tool for transcribing or translating audio attachments via OpenAI.
 */
class WP_MCP_AI_Tool_Transcribe_OpenAI_Audio implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_MODEL   = 'gpt-4o-mini-transcribe';
	const DEFAULT_FORMAT  = 'verbose_json';
	const MAX_AUDIO_BYTES = 26214400; // 25MB default limit.

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'transcribe_openai_audio';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Transcribe OpenAI Audio', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts an uploaded audio file into English text using OpenAI transcription or translation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'   => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID that contains the audio file.', 'wp-mcp-ai' ),
				),
				'translate'       => array(
					'type'        => 'boolean',
					'description' => __( 'When true the audio will be translated into English instead of a raw transcription.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'model'           => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI model override for the transcription request.', 'wp-mcp-ai' ),
					'default'     => self::DEFAULT_MODEL,
				),
				'prompt'          => array(
					'type'        => 'string',
					'description' => __( 'Optional prompt that provides context for the transcription.', 'wp-mcp-ai' ),
				),
				'temperature'     => array(
					'type'        => array( 'number', 'integer', 'string' ),
					'description' => __( 'Optional temperature override between 0 and 1.', 'wp-mcp-ai' ),
				),
				'timeout'         => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional request timeout override in seconds.', 'wp-mcp-ai' ),
				),
				'response_format' => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI response format (json or verbose_json).', 'wp-mcp-ai' ),
					'enum'        => array( 'json', 'verbose_json' ),
					'default'     => self::DEFAULT_FORMAT,
				),
				'language'        => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO language code hint for the transcription.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'attachment_id' ),
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
		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_audio_attachment', __( 'You must supply an audio attachment ID.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to transcribe audio.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id > 0 && $user_id !== get_current_user_id() ) {
			wp_set_current_user( $user_id );
		}

		if ( $user_id && ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to transcribe audio.', 'wp-mcp-ai' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$audio = $this->prepare_audio_attachment( $attachment_id );

		if ( is_wp_error( $audio ) ) {
			return $audio;
		}

		$translate = true;
		if ( isset( $arguments['translate'] ) ) {
			$translate = (bool) $arguments['translate'];
		}

		$options = array(
			'model'           => isset( $arguments['model'] ) && '' !== $arguments['model'] ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL,
			'translate'       => $translate,
			'prompt'          => isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '',
			'response_format' => isset( $arguments['response_format'] ) && '' !== $arguments['response_format'] ? strtolower( sanitize_key( $arguments['response_format'] ) ) : self::DEFAULT_FORMAT,
			'timeout'         => isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ? absint( $arguments['timeout'] ) : '',
			'language'        => isset( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : '',
			'filename'        => $audio['file_name'],
			'mime_type'       => $audio['mime_type'],
		);

		if ( isset( $arguments['temperature'] ) && '' !== $arguments['temperature'] ) {
			$options['temperature'] = $arguments['temperature'];
		}

		if ( '' === $options['model'] ) {
			$options['model'] = self::DEFAULT_MODEL;
		}

		if ( '' === $options['response_format'] ) {
			$options['response_format'] = self::DEFAULT_FORMAT;
		}

		if ( '' === $options['prompt'] ) {
			unset( $options['prompt'] );
		}

		if ( '' === $options['timeout'] ) {
			unset( $options['timeout'] );
		}

		if ( '' === $options['language'] ) {
			unset( $options['language'] );
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->transcribe_audio( $audio['file_path'], $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = array(
			'attachment_id'   => $attachment_id,
			'file_name'       => $audio['file_name'],
			'mime_type'       => $audio['mime_type'],
			'file_size'       => $audio['file_size'],
			'model'           => $result['model'],
			'text'            => $result['text'],
			'translated'      => ! empty( $result['translated'] ),
			'response_format' => $result['format'],
		);

		if ( isset( $result['language'] ) ) {
			$payload['language'] = $result['language'];
		}

		if ( isset( $result['duration'] ) ) {
			$payload['duration'] = $result['duration'];
		}

		if ( isset( $result['segments'] ) ) {
			$payload['segments'] = $result['segments'];
		}

		return apply_filters( 'wp_mcp_ai_transcribe_openai_audio_result', $payload, $arguments, $context );
	}

	/**
	 * Prepare an audio attachment for transcription.
	 *
	 * @param int $attachment_id Attachment identifier.
	 * @return array|WP_Error
	 */
	protected function prepare_audio_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_audio_attachment',
				__( 'You must supply an audio attachment ID.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_forbidden',
				__( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_missing',
				__( 'Attachment not found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_missing_file',
				__( 'The attachment file could not be located.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_size_unknown',
				__( 'Could not determine attachment size.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		$max_bytes = apply_filters( 'wp_mcp_ai_audio_transcription_max_bytes', self::MAX_AUDIO_BYTES, $attachment_id );
		if ( $file_size > $max_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_too_large',
				/* translators: %s: maximum file size in bytes */
				sprintf( __( 'Audio attachments must be smaller than %s bytes.', 'wp-mcp-ai' ), number_format_i18n( $max_bytes ) ),
				array( 'status' => 413 )
			);
		}

		$mime_type = strtolower( (string) get_post_mime_type( $attachment_id ) );
		$allowed   = $this->get_allowed_audio_mime_types( $attachment_id );

		if ( '' === $mime_type || ! in_array( $mime_type, $allowed, true ) ) {
			$detected_mime = '';
			$file_info     = wp_check_filetype_and_ext( $file_path, wp_basename( $file_path ), wp_get_mime_types() );

			if ( ! empty( $file_info['type'] ) ) {
				$detected_mime = strtolower( $file_info['type'] );
			}

			if ( '' === $detected_mime ) {
				$filetype = wp_check_filetype( wp_basename( $file_path ), wp_get_mime_types() );

				if ( $filetype && ! empty( $filetype['type'] ) ) {
					$detected_mime = strtolower( $filetype['type'] );
				}
			}

			if ( '' !== $detected_mime && in_array( $detected_mime, $allowed, true ) ) {
				$mime_type = $detected_mime;
			}
		}

		if ( '' === $mime_type || ! in_array( $mime_type, $allowed, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_unsupported_mime',
				__( 'The attachment is not a supported audio format.', 'wp-mcp-ai' ),
				array( 'status' => 415 )
			);
		}

		return array(
			'file_path' => $file_path,
			'file_size' => (int) $file_size,
			'mime_type' => strtolower( $mime_type ),
			'file_name' => wp_basename( $file_path ),
		);
	}

	/**
	 * Retrieve allowed audio MIME types for transcription.
	 *
	 * @param int $attachment_id Attachment identifier.
	 * @return array
	 */
	protected function get_allowed_audio_mime_types( $attachment_id ) {
		// List of MIME types supported by OpenAI Whisper API for transcription/translation.
		// Note: Some formats have multiple MIME type variants (e.g., audio/mpeg and audio/mp3)
		// to ensure compatibility across different systems and file uploads.
		$mimes = array(
			'audio/mpeg',      // MP3 (standard MIME type).
			'audio/mp3',       // MP3 (alternate MIME type).
			'audio/wav',       // WAV.
			'audio/x-wav',     // WAV (alternate).
			'audio/webm',      // WebM.
			'video/webm',      // WebM (video container with audio).
			'audio/ogg',       // OGG.
			'audio/m4a',       // M4A.
			'audio/x-m4a',     // M4A (alternate).
			'audio/flac',      // FLAC.
			'audio/x-flac',    // FLAC (alternate).
			'audio/mp4',       // MP4 audio.
			'audio/mpga',      // MPEG audio (OpenAI-specified variant).
			'audio/x-mpga',    // MPEG audio (alternate).
			'audio/opus',      // Opus.
			'audio/amr',       // AMR (Adaptive Multi-Rate).
			'audio/x-mpeg',    // MPEG (alternate).
			'audio/x-mp3',     // MP3 (alternate).
			'video/mp4',       // MP4 video container with audio.
		);

		/**
		 * Filter the list of audio MIME types permitted for transcription.
		 *
		 * @param array $mimes          Allowed MIME types.
		 * @param int   $attachment_id  Attachment identifier.
		 */
		$mimes = apply_filters( 'wp_mcp_ai_audio_transcription_allowed_mimes', $mimes, $attachment_id );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strtolower', (array) $mimes )
				)
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
