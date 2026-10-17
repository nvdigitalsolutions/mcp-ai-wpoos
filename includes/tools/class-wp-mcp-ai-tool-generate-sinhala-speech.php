<?php
/**
 * Tool that converts Sinhala text to speech using Sinhala-native TTS providers.
 *
 * The plugin's existing `generate_openai_speech` tool is English-first: OpenAI's
 * `/audio/speech` voices are "optimized for English" and its supported-languages
 * list omits Sinhala, and Google Cloud TTS offers no `si-LK` voice. This tool
 * exists to close that gap by targeting providers that natively synthesise
 * Sinhala (සිංහල), so that a teacher assistant can deliver correct, audible
 * Sinhala to students whose spoken Sinhala is still developing.
 *
 * Supported providers (configured via the `sinhala_speech_provider` option):
 * - `speechgen`    — SpeechGen REST API (https://speechgen.io) `tts-sinhala-sri-lanka`.
 *                    Accepts `si-LK` text and returns audio. PRIMARY.
 * - `huggingface`  — Meta MMS `facebook/mms-tts-sin` via the Hugging Face
 *                    Inference API. Uses the plugin's existing
 *                    `WP_MCP_AI_Huggingface_Client`. Open-source fallback (quality
 *                    is lower / more robotic than commercial neural voices).
 * - `custom`       — a generic JSON webhook for self-hosted Coqui / VITS / a local
 *                    Sri Lankan TTS endpoint configured by the site operator.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-audio-response.php';

/**
 * Provides a tool for generating Sinhala speech audio via Sinhala-native TTS providers.
 */
class WP_MCP_AI_Tool_Generate_Sinhala_Speech implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Audio_Response;

	const DEFAULT_PROVIDER = 'speechgen';
	const DEFAULT_FORMAT   = 'mp3';
	const DEFAULT_VOICE    = 'si-LK';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_sinhala_speech';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Sinhala Speech', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts Sinhala text to spoken audio using a Sinhala-native TTS provider (SpeechGen si-LK, Hugging Face facebook/mms-tts-sin, or a custom endpoint) and stores the audio in the Media Library. Use this instead of generate_openai_speech whenever the text is Sinhala, because OpenAI and Google TTS voices do not correctly pronounce Sinhala.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Turning Sinhala (සිංහල) text into spoken audio — the default path for a Sinhala-language teacher assistant.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'For English or other Latin-script language audio use generate_openai_speech; for transcribing audio use transcribe_openai_audio.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'generate_openai_speech', 'transcribe_openai_audio', 'generate_music' ),
			'notes'           => __( 'Native Sinhala output requires a configured Sinhala TTS provider (see sinhala_speech_provider setting). Commercial voices (SpeechGen) sound natural; the MMS fallback is more robotic.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'      => array(
					'type'        => 'string',
					'description' => __( 'The Sinhala text to convert to speech.', 'mcp-ai-wpoos' ),
				),
				'provider'  => array(
					'type'        => 'string',
					'description' => __( 'Optional TTS provider override: speechgen, huggingface, or custom. Defaults to the configured sinhala_speech_provider setting.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'speechgen', 'huggingface', 'custom' ),
					'default'     => self::DEFAULT_PROVIDER,
				),
				'voice'     => array(
					'type'        => 'string',
					'description' => __( 'Optional voice code. SpeechGen uses a BCP-47 code such as si-LK; the MMS Sinhala model is single-voice.', 'mcp-ai-wpoos' ),
					'default'     => self::DEFAULT_VOICE,
				),
				'format'    => array(
					'type'        => 'string',
					'description' => __( 'Audio format for the generated file (mp3, wav, ogg).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'mp3', 'wav', 'ogg' ),
					'default'     => self::DEFAULT_FORMAT,
				),
				'speed'     => array(
					'type'        => 'number',
					'description' => __( 'Playback speed multiplier (0.25 – 4). Supported by SpeechGen and custom providers when they accept a rate.', 'mcp-ai-wpoos' ),
					'minimum'     => 0.25,
					'maximum'     => 4,
				),
				'file_name' => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved audio attachment.', 'mcp-ai-wpoos' ),
				),
				'timeout'   => array(
					'type'        => 'integer',
					'description' => __( 'Override the provider request timeout in seconds.', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'text' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$is_guest  = ! empty( $context['guest_request'] );

		// Two-gate rule gate 1: sanitise every argument at entry.
		$text     = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';
		$text     = trim( $text );
		$provider = isset( $arguments['provider'] ) ? sanitize_key( $arguments['provider'] ) : $this->get_configured_provider();
		$voice    = isset( $arguments['voice'] ) ? sanitize_text_field( $arguments['voice'] ) : self::DEFAULT_VOICE;
		$format   = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : self::DEFAULT_FORMAT;
		$format   = $this->normalise_audio_format( $format );

		if ( ! in_array( $provider, array( 'speechgen', 'huggingface', 'custom' ), true ) ) {
			$provider = $this->get_configured_provider();
		}

		if ( ! $user_id && ! $has_token && ! $is_guest ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate speech audio.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate speech audio.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_text', __( 'No text was supplied for the speech request.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		if ( '' === $format ) {
			$format = self::DEFAULT_FORMAT;
		}

		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 30;
		if ( $timeout < 5 ) {
			$timeout = 30;
		}

		$options = array(
			'provider' => $provider,
			'voice'    => $voice,
			'format'   => $format,
			'timeout'  => $timeout,
		);

		if ( isset( $arguments['speed'] ) && '' !== $arguments['speed'] ) {
			$options['speed'] = max( 0.25, min( 4, floatval( $arguments['speed'] ) ) );
		}

		$speech = $this->generate( $text, $options, $context );

		if ( is_wp_error( $speech ) ) {
			return $speech;
		}

		if ( empty( $speech['audio'] ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_audio', __( 'The provider returned an empty audio response.', 'mcp-ai-wpoos' ) );
		}

		$file_name = isset( $arguments['file_name'] ) ? sanitize_file_name( (string) $arguments['file_name'] ) : '';
		$storage   = $this->store_audio_attachment( $speech, $file_name, $text, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: 1: provider name, 2: audio format */
			__( 'Successfully generated Sinhala speech audio using provider "%1$s" in %2$s format.', 'mcp-ai-wpoos' ),
			$provider,
			strtoupper( $format )
		);

		$result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_path'     => $storage['file'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'format'        => $format,
			'provider'      => $provider,
			'voice'         => $voice,
			'text'          => $message,
			'message'       => $message,
		);

		if ( isset( $storage['duration'] ) && null !== $storage['duration'] ) {
			$result['duration'] = $storage['duration'];
		}

		if ( isset( $storage['duration_formatted'] ) && '' !== $storage['duration_formatted'] ) {
			$result['duration_formatted'] = $storage['duration_formatted'];
		}

		if ( isset( $storage['title'] ) && '' !== $storage['title'] ) {
			$result['title'] = $storage['title'];
		}

		WP_MCP_AI_Logger::log_event(
			'sinhala_tts_saved',
			'Stored Sinhala text-to-speech audio as a media attachment.',
			array(
				'attachment_id' => $storage['attachment_id'],
				'provider'      => $provider,
				'format'        => $format,
			)
		);

		/**
		 * Allow third parties to filter the Sinhala speech tool result before it is returned.
		 *
		 * @param array  $result    Result array returned by the tool.
		 * @param array  $arguments Arguments supplied to the tool.
		 * @param array  $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_sinhala_speech_result', $result, $arguments, $context );

		return $this->add_audio_html_to_response( $result );
	}

	/**
	 * Retrieve the configured Sinhala TTS provider from plugin settings.
	 *
	 * @return string Provider slug (speechgen, huggingface, or custom).
	 */
	protected function get_configured_provider() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return self::DEFAULT_PROVIDER;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$provider = isset( $settings['sinhala_speech_provider'] ) ? sanitize_key( $settings['sinhala_speech_provider'] ) : '';

		if ( '' === $provider || ! in_array( $provider, array( 'speechgen', 'huggingface', 'custom' ), true ) ) {
			return self::DEFAULT_PROVIDER;
		}

		return $provider;
	}

	/**
	 * Dispatch to the configured Sinhala TTS provider.
	 *
	 * @param string $text    Sinhala text to synthesise.
	 * @param array  $options Provider, voice, format, speed, timeout.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Normalised array with 'audio', 'format', 'provider' keys, or WP_Error.
	 */
	protected function generate( $text, array $options, array $context = array() ) {
		switch ( $options['provider'] ) {
			case 'huggingface':
				return $this->generate_via_huggingface( $text, $options );
			case 'custom':
				return $this->generate_via_custom( $text, $options );
			case 'speechgen':
			default:
				return $this->generate_via_speechgen( $text, $options );
		}
	}

	/**
	 * Generate Sinhala speech via the SpeechGen REST API (si-LK native voice).
	 *
	 * @param string $text    Sinhala text.
	 * @param array  $options Voice, format, speed, timeout.
	 * @return array|WP_Error Normalised array or error.
	 */
	protected function generate_via_speechgen( $text, array $options ) {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$token    = isset( $settings['sinhala_speechgen_token'] ) ? sanitize_text_field( $settings['sinhala_speechgen_token'] ) : '';
		$endpoint = isset( $settings['sinhala_speechgen_endpoint'] ) && '' !== $settings['sinhala_speechgen_endpoint']
			? esc_url_raw( $settings['sinhala_speechgen_endpoint'] )
			: 'https://speechgen.io/index.php';

		if ( '' === $token ) {
			return new WP_Error(
				'wp_mcp_ai_missing_sinhala_token',
				__( 'No Sinhala TTS token is configured. Add a SpeechGen API token in the NV oOS settings.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$body = wp_json_encode(
			array(
				'token' => $token,
				'text'  => $text,
				'voice' => isset( $options['voice'] ) ? $options['voice'] : self::DEFAULT_VOICE,
			)
		);

		$args = array(
			'body'      => $body,
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'timeout'   => $options['timeout'],
			'sslverify' => true,
		);

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_sinhala_tts_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'SpeechGen returned HTTP status %d.', 'mcp-ai-wpoos' ), $code ),
				array( 'status' => $code )
			);
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			// Some SpeechGen endpoints return raw audio directly on a redirect or a binary body.
			if ( '' !== $raw && ctype_print( $raw ) === false ) {
				return array(
					'audio'    => $raw,
					'format'   => $options['format'],
					'provider' => 'speechgen',
				);
			}

			return new WP_Error( 'wp_mcp_ai_sinhala_tts_invalid_response', __( 'SpeechGen returned an unrecognised response.', 'mcp-ai-wpoos' ) );
		}

		// Normalise both common response shapes: {audio: base64} and {data: ..., file: url}.
		$audio = '';
		if ( ! empty( $data['audio'] ) && is_string( $data['audio'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- legitimate decode of a TTS provider's base64 audio payload.
			$audio = base64_decode( $data['audio'], true );
		} elseif ( ! empty( $data['data'] ) && is_string( $data['data'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- legitimate decode of a TTS provider's base64 audio payload.
			$audio = base64_decode( $data['data'], true );
		} elseif ( ! empty( $data['file'] ) && is_string( $data['file'] ) && filter_var( $data['file'], FILTER_VALIDATE_URL ) ) {
			$fetched = wp_remote_get(
				$data['file'],
				array(
					'timeout'   => $options['timeout'],
					'sslverify' => true,
				)
			);
			if ( ! is_wp_error( $fetched ) ) {
				$audio = wp_remote_retrieve_body( $fetched );
			}
		}

		if ( '' === $audio ) {
			return new WP_Error( 'wp_mcp_ai_sinhala_tts_empty', __( 'SpeechGen returned no audio data.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'audio'    => $audio,
			'format'   => $options['format'],
			'provider' => 'speechgen',
		);
	}

	/**
	 * Generate Sinhala speech via the Meta MMS Sinhala model on Hugging Face.
	 *
	 * Reuses the plugin's existing Hugging Face client, which accepts any model
	 * string — here `facebook/mms-tts-sin`.
	 *
	 * @param string $text    Sinhala text.
	 * @param array  $options Format, timeout.
	 * @return array|WP_Error Normalised array or error.
	 */
	protected function generate_via_huggingface( $text, array $options ) {
		if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-huggingface-client.php';
		}

		$client = new WP_MCP_AI_Huggingface_Client();

		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$model    = isset( $settings['sinhala_huggingface_model'] ) && '' !== $settings['sinhala_huggingface_model']
			? sanitize_text_field( $settings['sinhala_huggingface_model'] )
			: 'facebook/mms-tts-sin';

		$speech = $client->generate_speech(
			$text,
			array(
				'model'   => $model,
				'timeout' => $options['timeout'],
			)
		);

		if ( is_wp_error( $speech ) ) {
			return $speech;
		}

		if ( empty( $speech['audio'] ) ) {
			return new WP_Error( 'wp_mcp_ai_sinhala_tts_empty', __( 'Hugging Face returned no audio data.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'audio'    => $speech['audio'],
			'format'   => isset( $speech['format'] ) && '' !== $speech['format'] ? $speech['format'] : $options['format'],
			'provider' => 'huggingface',
		);
	}

	/**
	 * Generate Sinhala speech via a custom JSON webhook (self-hosted / local endpoint).
	 *
	 * The endpoint is configured via the `sinhala_custom_endpoint` setting and is
	 * expected to accept `{"text": "...", "voice": "..."}` and return either
	 * `{"audio": "<base64>"}` or raw audio bytes.
	 *
	 * @param string $text    Sinhala text.
	 * @param array  $options Voice, format, speed, timeout.
	 * @return array|WP_Error Normalised array or error.
	 */
	protected function generate_via_custom( $text, array $options ) {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$endpoint = isset( $settings['sinhala_custom_endpoint'] ) ? esc_url_raw( $settings['sinhala_custom_endpoint'] ) : '';

		if ( '' === $endpoint ) {
			return new WP_Error(
				'wp_mcp_ai_missing_custom_endpoint',
				__( 'No custom Sinhala TTS endpoint is configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$body = wp_json_encode(
			array(
				'text'  => $text,
				'voice' => isset( $options['voice'] ) ? $options['voice'] : self::DEFAULT_VOICE,
			)
		);

		$args = array(
			'body'      => $body,
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'timeout'   => $options['timeout'],
			'sslverify' => true,
		);

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_sinhala_tts_http_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Custom TTS endpoint returned HTTP status %d.', 'mcp-ai-wpoos' ), $code ),
				array( 'status' => $code )
			);
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$audio = '';
		if ( is_array( $data ) && ! empty( $data['audio'] ) && is_string( $data['audio'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- legitimate decode of a custom endpoint's base64 audio payload.
			$audio = base64_decode( $data['audio'], true );
		} elseif ( '' !== $raw && false === ctype_print( substr( $raw, 0, 64 ) ) ) {
			$audio = $raw;
		}

		if ( '' === $audio ) {
			return new WP_Error( 'wp_mcp_ai_sinhala_tts_empty', __( 'Custom endpoint returned no audio data.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'audio'    => $audio,
			'format'   => $options['format'],
			'provider' => 'custom',
		);
	}

	/**
	 * Retrieve the allowed audio formats and their associated metadata.
	 *
	 * @return array
	 */
	protected function get_allowed_formats() {
		return array(
			'mp3' => array(
				'extension' => 'mp3',
				'mime_type' => 'audio/mpeg',
			),
			'wav' => array(
				'extension' => 'wav',
				'mime_type' => 'audio/wav',
			),
			'ogg' => array(
				'extension' => 'ogg',
				'mime_type' => 'audio/ogg',
			),
		);
	}

	/**
	 * Normalise an audio format slug to a supported value.
	 *
	 * @param string $format Raw format slug.
	 * @return string Supported format or empty string.
	 */
	protected function normalise_audio_format( $format ) {
		$format = sanitize_key( (string) $format );
		$meta   = $this->get_allowed_formats();

		return isset( $meta[ $format ] ) ? $format : '';
	}

	/**
	 * Store generated audio bytes as a WordPress media attachment.
	 *
	 * @param array  $speech    Normalised result with 'audio', 'format', 'provider'.
	 * @param string $file_name Optional base file name.
	 * @param string $text      Source text.
	 * @param int    $user_id   Author user ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Storage result or error.
	 */
	protected function store_audio_attachment( array $speech, $file_name, $text, $user_id, array $context = array() ) {
		$audio  = isset( $speech['audio'] ) ? $speech['audio'] : '';
		$format = isset( $speech['format'] ) ? $this->normalise_audio_format( $speech['format'] ) : self::DEFAULT_FORMAT;
		$meta   = $this->get_allowed_formats();

		if ( '' === $audio || '' === $format || ! isset( $meta[ $format ] ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_storage_error', __( 'Unable to determine the audio format for storage.', 'mcp-ai-wpoos' ) );
		}

		$file_stem = $this->normalise_file_stem( $file_name );
		$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $meta[ $format ]['extension'] );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $audio );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_upload_failed', __( 'Failed to save the generated audio file.', 'mcp-ai-wpoos' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_upload_failed', __( 'Failed to write the generated audio file to disk.', 'mcp-ai-wpoos' ) );
		}

		$mime_type = $this->determine_mime_type( $file_path, $meta[ $format ]['mime_type'], $speech );
		$title     = $this->generate_attachment_title( $text );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			$this->delete_file_safely( $file_path );

			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated audio file as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		} else {
			$metadata = array();
		}

		$bytes     = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id'      => (int) $attachment_id,
			'file'               => $file_path,
			'file_name'          => wp_basename( $file_path ),
			'url'                => $local_url,
			'mime_type'          => $mime_type,
			'bytes'              => $bytes ? (int) $bytes : 0,
			'duration'           => isset( $metadata['length'] ) ? floatval( $metadata['length'] ) : null,
			'duration_formatted' => isset( $metadata['length_formatted'] ) ? $metadata['length_formatted'] : '',
			'title'              => $title,
		);
	}

	/**
	 * Normalise a file stem used for generated attachments.
	 *
	 * @param string $file_name Raw file name input.
	 * @return string
	 */
	protected function normalise_file_stem( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return 'sinhala-speech';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			$stem = 'sinhala-speech';
		}

		return $stem;
	}

	/**
	 * Determine the MIME type for the saved audio file.
	 *
	 * @param string $file_path      Absolute file path.
	 * @param string $preferred_type Preferred MIME type for the format.
	 * @param array  $speech         Response payload from the provider.
	 * @return string
	 */
	protected function determine_mime_type( $file_path, $preferred_type, array $speech ) {
		$file_info = wp_check_filetype( wp_basename( $file_path ), null );

		if ( ! empty( $file_info['type'] ) ) {
			return $file_info['type'];
		}

		if ( ! empty( $speech['content_type'] ) ) {
			$content_type = sanitize_text_field( $speech['content_type'] );
			if ( '' !== $content_type ) {
				return $content_type;
			}
		}

		if ( ! empty( $preferred_type ) ) {
			return $preferred_type;
		}

		return 'audio/mpeg';
	}

	/**
	 * Generate a human readable attachment title using the source text.
	 *
	 * @param string $text Original text prompt.
	 * @return string
	 */
	protected function generate_attachment_title( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return __( 'Sinhala Speech', 'mcp-ai-wpoos' );
		}

		$excerpt = wp_trim_words( $text, 12, '…' );

		/* translators: %s: Short excerpt of the text used to generate speech. */
		return sprintf( __( 'Sinhala Speech: %s', 'mcp-ai-wpoos' ), $excerpt );
	}

	/**
	 * Delete a generated file from disk safely when an error occurs.
	 *
	 * @param string $file_path Absolute file path.
	 */
	protected function delete_file_safely( $file_path ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_delete_file( $file_path );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'external-api',        // Calls a Sinhala TTS provider API.
			'requires-capability', // Requires user capabilities.
		);
	}
}
