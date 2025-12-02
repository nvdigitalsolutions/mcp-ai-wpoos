<?php
/**
 * Tool that converts text to speech using OpenAI's Text-to-Speech API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Provides a tool for generating speech audio via OpenAI.
 */
class WP_MCP_AI_Tool_Generate_OpenAI_Speech implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_MODEL  = 'gpt-4o-mini-tts';
	const DEFAULT_VOICE  = 'alloy';
	const DEFAULT_FORMAT = 'mp3';

	/**
	 * Retrieve the configured defaults for speech generation.
	 *
	 * @return array
	 */
	protected function get_configured_defaults() {
		$defaults = array(
			'model'  => self::DEFAULT_MODEL,
			'voice'  => self::DEFAULT_VOICE,
			'format' => self::DEFAULT_FORMAT,
		);

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return $defaults;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['openai_speech_model'] ) ) {
			$defaults['model'] = sanitize_text_field( $settings['openai_speech_model'] );
		}

		if ( isset( $settings['openai_speech_voice'] ) && '' !== $settings['openai_speech_voice'] ) {
			$voice = sanitize_key( $settings['openai_speech_voice'] );

			if ( '' !== $voice ) {
				$defaults['voice'] = $voice;
			}
		}

		if ( isset( $settings['openai_speech_format'] ) && '' !== $settings['openai_speech_format'] ) {
			$format = sanitize_key( $settings['openai_speech_format'] );
			$format = $this->normalise_audio_format( $format );

			if ( '' !== $format ) {
				$defaults['format'] = $format;
			}
		}

		return $defaults;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_openai_speech';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate OpenAI Speech', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts text to speech using OpenAI and stores the audio in the Media Library.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$defaults = $this->get_configured_defaults();

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'      => array(
					'type'        => 'string',
					'description' => __( 'The text that should be converted to speech.', 'wp-mcp-ai' ),
				),
				'voice'     => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI voice to use (for example, alloy, verse, or shimmer).', 'wp-mcp-ai' ),
					'default'     => $defaults['voice'],
				),
				'format'    => array(
					'type'        => 'string',
					'description' => __( 'Audio format for the generated file.', 'wp-mcp-ai' ),
					'enum'        => array_keys( $this->get_allowed_formats() ),
					'default'     => $defaults['format'],
				),
				'model'     => array(
					'type'        => 'string',
					'description' => __( 'OpenAI speech model to use.', 'wp-mcp-ai' ),
					'default'     => $defaults['model'],
				),
				'speed'     => array(
					'type'        => 'number',
					'description' => __( 'Playback speed multiplier (0.25 – 4).', 'wp-mcp-ai' ),
					'minimum'     => 0.25,
					'maximum'     => 4,
				),
				'file_name' => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved audio attachment.', 'wp-mcp-ai' ),
				),
				'timeout'   => array(
					'type'        => 'integer',
					'description' => __( 'Override the OpenAI request timeout in seconds.', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 300,
				),
			),
			'required'             => array( 'text' ),
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );
		$text      = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';
		$text      = trim( $text );
		$defaults  = $this->get_configured_defaults();

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate speech audio.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate speech audio.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_text', __( 'No text was supplied for the speech request.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$format = isset( $arguments['format'] ) && '' !== $arguments['format'] ? sanitize_key( $arguments['format'] ) : $defaults['format'];
		$format = $this->normalise_audio_format( $format );

		if ( '' === $format ) {
			$format = $defaults['format'];

			if ( '' === $this->normalise_audio_format( $format ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_format', __( 'The requested audio format is not supported.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}
		}

		$voice = isset( $arguments['voice'] ) && '' !== $arguments['voice'] ? sanitize_key( $arguments['voice'] ) : $defaults['voice'];
		if ( '' === $voice ) {
			$voice = $defaults['voice'];
		}

		$model = isset( $arguments['model'] ) && '' !== $arguments['model'] ? sanitize_text_field( $arguments['model'] ) : $defaults['model'];
		if ( '' === $model ) {
			$model = $defaults['model'];
		}

		$options = array(
			'model'  => $model,
			'voice'  => $voice,
			'format' => $format,
		);

		if ( isset( $arguments['speed'] ) && '' !== $arguments['speed'] ) {
			$speed            = floatval( $arguments['speed'] );
			$speed            = max( 0.25, min( 4, $speed ) );
			$options['speed'] = $speed;
		}

		if ( isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ) {
			$timeout = absint( $arguments['timeout'] );
			if ( $timeout >= 5 ) {
				$options['timeout'] = $timeout;
			}
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$speech = $client->generate_speech( $text, $options );
		if ( is_wp_error( $speech ) ) {
			return $speech;
		}

		if ( empty( $speech['audio'] ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_audio', __( 'OpenAI returned an empty audio response.', 'wp-mcp-ai' ) );
		}

		$file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
		$storage   = $this->store_audio_attachment( $speech, $file_name, $text, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$result = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_path'     => $storage['file'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => $storage['mime_type'],
			'bytes'         => $storage['bytes'],
			'format'        => $format,
			'model'         => $speech['model'],
			'voice'         => $speech['voice'],
		);

		if ( isset( $speech['speed'] ) && null !== $speech['speed'] ) {
			$result['speed'] = $speech['speed'];
		}

		if ( ! empty( $speech['content_type'] ) ) {
			$result['content_type'] = $speech['content_type'];
		}

		if ( ! empty( $storage['duration'] ) ) {
			$result['duration'] = $storage['duration'];
		}

		if ( ! empty( $storage['duration_formatted'] ) ) {
			$result['duration_formatted'] = $storage['duration_formatted'];
		}

		if ( ! empty( $storage['title'] ) ) {
			$result['title'] = $storage['title'];
		}

		WP_MCP_AI_Logger::log_event(
			'openai_tts_saved',
			'Stored OpenAI text-to-speech audio as a media attachment.',
			array(
				'attachment_id' => $storage['attachment_id'],
				'format'        => $format,
				'model'         => $speech['model'],
				'voice'         => $speech['voice'],
			)
		);

		/**
		 * Allow third parties to filter the OpenAI speech tool result before it is returned.
		 *
		 * @param array $result    Result array returned by the tool.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_openai_speech_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * Retrieve the allowed audio formats and their associated metadata.
	 *
	 * @return array
	 */
	protected function get_allowed_formats() {
		return array(
			'mp3'  => array(
				'extension' => 'mp3',
				'mime_type' => 'audio/mpeg',
			),
			'aac'  => array(
				'extension' => 'aac',
				'mime_type' => 'audio/aac',
			),
			'flac' => array(
				'extension' => 'flac',
				'mime_type' => 'audio/flac',
			),
			'ogg'  => array(
				'extension' => 'ogg',
				'mime_type' => 'audio/ogg',
			),
			'opus' => array(
				'extension' => 'opus',
				'mime_type' => 'audio/opus',
			),
			'wav'  => array(
				'extension' => 'wav',
				'mime_type' => 'audio/wav',
			),
		);
	}

	/**
	 * Normalise the requested audio format.
	 *
	 * @param string $format Raw format string.
	 * @return string Normalised format or empty string when unsupported.
	 */
	protected function normalise_audio_format( $format ) {
		$format = sanitize_key( $format );

		if ( '' === $format ) {
			$format = self::DEFAULT_FORMAT;
		}

		$allowed = $this->get_allowed_formats();

		return isset( $allowed[ $format ] ) ? $format : '';
	}

	/**
	 * Store the generated audio as a WordPress attachment.
	 *
	 * @param array  $speech    Response payload from the OpenAI client.
	 * @param string $file_name Optional preferred file name.
	 * @param string $text      Original text prompt.
	 * @param int    $user_id   Acting user ID.
	 * @param array  $context   Optional. Execution context containing parent_job_id.
	 * @return array|WP_Error
	 */
	protected function store_audio_attachment( array $speech, $file_name, $text, $user_id, array $context = array() ) {
		$audio  = isset( $speech['audio'] ) ? $speech['audio'] : '';
		$format = isset( $speech['format'] ) ? $this->normalise_audio_format( $speech['format'] ) : self::DEFAULT_FORMAT;
		$meta   = $this->get_allowed_formats();

		if ( '' === $audio || '' === $format || ! isset( $meta[ $format ] ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_storage_error', __( 'Unable to determine the audio format for storage.', 'wp-mcp-ai' ) );
		}

		// Use job_id for filename if available, otherwise use file_name or default.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'openai-speech-%s.%s', $job_id, $meta[ $format ]['extension'] );
		} else {
			$file_stem = $this->normalise_file_stem( $file_name );
			$file_name = sprintf( '%s-%s.%s', $file_stem, gmdate( 'Ymd-His' ), $meta[ $format ]['extension'] );
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $audio );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_upload_failed', __( 'Failed to save the generated audio file.', 'wp-mcp-ai' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_audio_upload_failed', __( 'Failed to write the generated audio file to disk.', 'wp-mcp-ai' ) );
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

			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register the generated audio file as an attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
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

		// Store job_id if available - allows correlation between job IDs and files.
		if ( ! empty( $job_id ) ) {
			update_post_meta( $attachment_id, '_openai_speech_job_id', $job_id );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get local WordPress URL using utility class for SoC compliance.
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
			return 'openai-speech';
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			$stem = 'openai-speech';
		}

		return $stem;
	}

	/**
	 * Determine the MIME type for the saved audio file.
	 *
	 * @param string $file_path      Absolute file path.
	 * @param string $preferred_type Preferred MIME type for the format.
	 * @param array  $speech         Response payload from OpenAI.
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
			return __( 'OpenAI Speech', 'wp-mcp-ai' );
		}

		$excerpt = wp_trim_words( $text, 12, '…' );

		/* translators: %s: Short excerpt of the text used to generate speech. */
		return sprintf( __( 'OpenAI Speech: %s', 'wp-mcp-ai' ), $excerpt );
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
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
