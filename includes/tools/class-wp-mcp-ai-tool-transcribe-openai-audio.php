<?php
/**
 * Tool that transcribes or translates audio into English text using OpenAI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides a tool for transcribing or translating audio attachments via OpenAI.
 */
class WP_MCP_AI_Tool_Transcribe_OpenAI_Audio implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	use WP_MCP_AI_Tool_Chat_Response;

	const DEFAULT_MODEL   = 'whisper-1';
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
		return __( 'Transcribe Audio', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts an uploaded audio file into text using AI speech-to-text. Supports OpenAI Whisper, Cloudflare Workers AI (Whisper, Deepgram Flux), Hugging Face, and Google Gemini providers.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'           => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID that contains the audio file.', 'mcp-ai-wpoos' ),
				),
				'file_id'                 => $this->get_file_id_parameter_schema(),
				'url'                     => $this->get_url_parameter_schema( 'audio' ),
				'translate'               => array(
					'type'        => 'boolean',
					'description' => __( 'When true, the audio will be translated into English instead of a raw transcription. Translation is only supported with OpenAI provider.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'model'                   => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI model override for the transcription request.', 'mcp-ai-wpoos' ),
					'default'     => self::DEFAULT_MODEL,
				),
				'prompt'                  => array(
					'type'        => 'string',
					'description' => __( 'Optional prompt that provides context for the transcription.', 'mcp-ai-wpoos' ),
				),
				'temperature'             => array(
					'type'        => array( 'number', 'integer', 'string' ),
					'description' => __( 'Optional temperature override between 0 and 1.', 'mcp-ai-wpoos' ),
				),
				'timeout'                 => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional request timeout override in seconds.', 'mcp-ai-wpoos' ),
				),
				'response_format'         => array(
					'type'        => 'string',
					'description' => __( 'Response format: json, verbose_json (default with metadata), text, srt (subtitle), or vtt (subtitle).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'json', 'verbose_json', 'text', 'srt', 'vtt' ),
					'default'     => self::DEFAULT_FORMAT,
				),
				'timestamp_granularities' => array(
					'type'        => 'array',
					'description' => __( 'Timestamp detail level. Provide ["segment"] for paragraph-level timestamps, ["word"] for word-level timestamps, or both.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'word', 'segment' ),
					),
				),
				'language'                => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO language code hint for the transcription.', 'mcp-ai-wpoos' ),
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
		// Resolve attachment ID from attachment_id, file_id, or url.
		$resolved = $this->resolve_attachment_id( $arguments );

		// Handle remote URL case.
		if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_url_not_supported',
				__( 'Remote URLs are not supported for audio transcription. Please upload the audio file to WordPress Media Library first.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$attachment_id = $resolved;

		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_audio_source',
				__( 'You must supply an audio attachment ID, file ID, or URL.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$has_token = ! empty( $context['token_authenticated'] );
		$is_guest  = ! empty( $context['guest_request'] );

		// Allow guest users for audio transcription (chat UI feature).
		// Guests still need to provide valid attachments they can access.
		if ( ! $user_id && ! $has_token && ! $is_guest ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to transcribe audio.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id > 0 && $user_id !== get_current_user_id() ) {
			wp_set_current_user( $user_id );
		}

		if ( $user_id && ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to transcribe audio.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check if assistant is configured to use a non-OpenAI provider.
		$assistant_config = isset( $context['assistant_config'] ) ? $context['assistant_config'] : array();
		$provider         = isset( $assistant_config['provider'] ) ? strtolower( $assistant_config['provider'] ) : 'openai';

		// Audio transcription now supports multiple providers:
		// - OpenAI: whisper-1 (transcription + translation)
		// - Cloudflare: @cf/openai/whisper (transcription only)
		// - Hugging Face: openai/whisper-large-v3 (transcription)
		// - Google/Gemini: Speech-to-Text API (transcription, 125+ languages)
		// The tool will automatically route to the appropriate provider's client.
		if ( 'openai' !== $provider ) {
			// Get OpenAI API key to verify it's configured.
			$settings       = WP_MCP_AI_Admin_Settings::get_settings();
			$openai_api_key = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

			if ( empty( $openai_api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_openai_key',
					sprintf(
						/* translators: %s: provider name */
						__( 'Audio transcription requires OpenAI API. Your assistant uses "%s" provider, but no OpenAI API key is configured. Please add an OpenAI API key in the plugin settings to use audio transcription features.', 'mcp-ai-wpoos' ),
						$provider
					),
					array(
						'status'   => 400,
						'provider' => $provider,
						'actions'  => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Log that we're falling back to OpenAI for audio transcription.
			WP_MCP_AI_Logger::log_event(
				'audio_transcription_provider_fallback',
				sprintf( 'Using OpenAI for audio transcription despite assistant using %s provider.', $provider ),
				array(
					'assistant_id'     => isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
					'primary_provider' => $provider,
					'tool'             => 'transcribe_openai_audio',
				)
			);
		}

		$audio = $this->prepare_audio_attachment( $attachment_id );

		if ( is_wp_error( $audio ) ) {
			return $audio;
		}

		// Get default settings from admin.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Default to transcription (translate=false) instead of translation (translate=true).
		// Translation to English is only available via OpenAI's /v1/audio/translations endpoint.
		// For maximum compatibility, we default to transcription which works in more scenarios.
		$translate = false;
		if ( isset( $arguments['translate'] ) ) {
			$translate = (bool) $arguments['translate'];
		}

		// TODO: Future enhancement - implement provider-aware audio transcription:
		// - For Cloudflare: Use @cf/openai/whisper (transcription only, no translation)
		// - For Ollama: Use local whisper models if available
		// - For OpenAI: Support both transcription and translation
		// This will require creating a provider abstraction layer for audio services.

		// Use argument values if provided, otherwise fall back to admin settings, then constants.
		$default_model           = $this->get_non_empty_setting( $settings, 'openai_transcribe_model', self::DEFAULT_MODEL );
		$default_response_format = $this->get_non_empty_setting( $settings, 'openai_transcribe_response_format', self::DEFAULT_FORMAT );
		$default_language        = $this->get_non_empty_setting( $settings, 'openai_transcribe_language', '' );
		$default_temperature     = $this->get_non_empty_setting( $settings, 'openai_transcribe_temperature', '' );

		$options = array(
			'model'           => isset( $arguments['model'] ) && '' !== $arguments['model'] ? sanitize_text_field( $arguments['model'] ) : $default_model,
			'translate'       => $translate,
			'prompt'          => isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '',
			'response_format' => isset( $arguments['response_format'] ) && '' !== $arguments['response_format'] ? strtolower( sanitize_key( $arguments['response_format'] ) ) : $default_response_format,
			'timeout'         => isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ? absint( $arguments['timeout'] ) : '',
			'language'        => isset( $arguments['language'] ) && '' !== $arguments['language'] ? sanitize_text_field( $arguments['language'] ) : $default_language,
			'filename'        => $audio['file_name'],
			'mime_type'       => $audio['mime_type'],
		);

		// Add timestamp_granularities if provided.
		if ( isset( $arguments['timestamp_granularities'] ) && is_array( $arguments['timestamp_granularities'] ) ) {
			$options['timestamp_granularities'] = $arguments['timestamp_granularities'];
		}

		if ( isset( $arguments['temperature'] ) && '' !== $arguments['temperature'] ) {
			$options['temperature'] = $arguments['temperature'];
		} elseif ( '' !== $default_temperature ) {
			$options['temperature'] = $default_temperature;
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

		// Use provider-specific client based on assistant configuration.
		$client = null;
		switch ( $provider ) {
			case 'cloudflare':
				if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
				}
				$client = new WP_MCP_AI_Cloudflare_Client();
				// Set model to configured Cloudflare STT model if not specified.
				if ( self::DEFAULT_MODEL === $options['model'] ) {
					$settings         = WP_MCP_AI_Admin_Settings::get_settings();
					$configured_model = isset( $settings['cloudflare_audio_model'] ) && '' !== $settings['cloudflare_audio_model']
						? $settings['cloudflare_audio_model']
						: '@cf/openai/whisper';
					$options['model'] = $configured_model;
				}
				break;

			case 'huggingface':
				if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php';
				}
				$client = new WP_MCP_AI_Huggingface_Client();
				// Set model to Hugging Face Whisper if not specified.
				if ( self::DEFAULT_MODEL === $options['model'] ) {
					$options['model'] = 'openai/whisper-large-v3';
				}
				break;

			case 'gemini':
			case 'google':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
				}
				$client = new WP_MCP_AI_Gemini_Client();
				break;

			case 'openai':
			default:
				$client = new WP_MCP_AI_OpenAI_Client();
				break;
		}

		$result = $client->transcribe_audio( $audio['file_path'], $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Normalize response format across providers.
		$text = isset( $result['text'] ) ? $result['text'] : '';

		$message = ! empty( $result['translated'] )
			? __( 'Successfully translated audio to English text.', 'mcp-ai-wpoos' )
			: __( 'Successfully transcribed audio to text.', 'mcp-ai-wpoos' );

		$payload = array(
			'attachment_id'   => $attachment_id,
			'file_name'       => $audio['file_name'],
			'mime_type'       => $audio['mime_type'],
			'file_size'       => $audio['file_size'],
			'model'           => isset( $result['model'] ) ? $result['model'] : $options['model'],
			'text'            => $text,
			'message'         => $message,
			'translated'      => ! empty( $result['translated'] ),
			'response_format' => isset( $result['format'] ) ? $result['format'] : 'json',
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

		if ( isset( $result['words'] ) ) {
			$payload['words'] = $result['words'];
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
				__( 'You must supply an audio attachment ID.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_forbidden',
				__( 'You do not have permission to use the requested attachment.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_missing',
				__( 'Attachment not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_missing_file',
				__( 'The attachment file could not be located.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_size_unknown',
				__( 'Could not determine attachment size.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$max_bytes = apply_filters( 'wp_mcp_ai_audio_transcription_max_bytes', self::MAX_AUDIO_BYTES, $attachment_id );
		if ( $file_size > $max_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_too_large',
				/* translators: %s: maximum file size in bytes */
				sprintf( __( 'Audio attachments must be smaller than %s bytes.', 'mcp-ai-wpoos' ), number_format_i18n( $max_bytes ) ),
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
				__( 'The attachment is not a supported audio format.', 'mcp-ai-wpoos' ),
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

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'journalist', 'writer', 'researcher' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities (but allows guests).
		);
	}

	/**
	 * Get non-empty setting value with fallback.
	 *
	 * Returns the setting value if it exists and is not empty, otherwise returns the fallback.
	 *
	 * @param array  $settings  Settings array.
	 * @param string $key       Setting key.
	 * @param mixed  $fallback  Fallback value if setting is empty or not set.
	 * @return mixed Setting value or fallback.
	 */
	private function get_non_empty_setting( $settings, $key, $fallback = '' ) {
		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $fallback;
	}
}
