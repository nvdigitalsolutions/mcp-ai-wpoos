<?php
/**
 * NV oOS LibreChat — Speech Service
 *
 * Speech-to-text (STT) and text-to-speech (TTS) proxy service.
 * Passes through to OpenAI, Azure, or ElevenLabs APIs.
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Speech service.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_Speech {

	/**
	 * Transcribe audio to text using the configured STT provider.
	 *
	 * @param string $audio_path   Path to the audio file.
	 * @param string $audio_format MIME type or extension (e.g. 'webm', 'mp3', 'wav').
	 * @return array|WP_Error Array with 'text' key, or WP_Error on failure.
	 */
	public static function transcribe( $audio_path, $audio_format ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$provider = ! empty( $settings['speech_stt_provider'] ) ? $settings['speech_stt_provider'] : 'openai';
		$api_key  = self::get_openai_api_key();

		if ( 'openai' !== $provider ) {
			return new WP_Error(
				'nvoos_librechat_stt_unsupported',
				__( 'Only OpenAI Whisper is currently supported for speech-to-text.', 'nvoos-librechat' )
			);
		}

		if ( ! $api_key ) {
			return new WP_Error(
				'nvoos_librechat_stt_no_key',
				__( 'No OpenAI API key configured. Set it in NV oOS → Settings.', 'nvoos-librechat' )
			);
		}

		if ( ! file_exists( $audio_path ) || ! is_readable( $audio_path ) ) {
			return new WP_Error(
				'nvoos_librechat_stt_file_error',
				__( 'Audio file not found or not readable.', 'nvoos-librechat' )
			);
		}

		// Map MIME types to file extensions Whisper understands.
		$ext_map = array(
			'audio/webm' => 'webm',
			'audio/mp4'  => 'mp4',
			'audio/mpeg' => 'mp3',
			'audio/wav'  => 'wav',
			'audio/ogg'  => 'ogg',
			'audio/flac' => 'flac',
		);

		$extension = isset( $ext_map[ $audio_format ] ) ? $ext_map[ $audio_format ] : 'webm';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local audio file, not a remote URL.
		$file_contents = file_get_contents( $audio_path );

		if ( false === $file_contents ) {
			return new WP_Error(
				'nvoos_librechat_stt_read_error',
				__( 'Failed to read audio file.', 'nvoos-librechat' )
			);
		}

		$filename = 'audio.' . $extension;

		$boundary = wp_generate_password( 24, false );
		$body     = '';

		// Build multipart form data.
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
		$body .= 'whisper-1' . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			'https://api.openai.com/v1/audio/transcriptions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Transcription failed.', 'nvoos-librechat' );

			return new WP_Error(
				'nvoos_librechat_stt_api_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		return array(
			'text' => isset( $data['text'] ) ? sanitize_textarea_field( $data['text'] ) : '',
		);
	}

	/**
	 * Synthesize text to speech audio bytes.
	 *
	 * @param string $text  Text to convert to speech.
	 * @param string $voice Voice model name.
	 * @return string|WP_Error Raw audio bytes or WP_Error.
	 */
	public static function synthesize( $text, $voice = 'alloy' ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$provider = ! empty( $settings['speech_tts_provider'] ) ? $settings['speech_tts_provider'] : 'openai';

		if ( '' === $provider ) {
			return new WP_Error(
				'nvoos_librechat_tts_disabled',
				__( 'Text-to-speech is not configured.', 'nvoos-librechat' )
			);
		}

		if ( 'openai' === $provider ) {
			return self::synthesize_openai( $text, $voice );
		}

		if ( 'elevenlabs' === $provider ) {
			return self::synthesize_elevenlabs( $text, $voice );
		}

		return new WP_Error(
			'nvoos_librechat_tts_unsupported',
			sprintf(
				/* translators: %s: provider name */
				__( 'Unsupported TTS provider: %s', 'nvoos-librechat' ),
				$provider
			)
		);
	}

	/**
	 * Synthesize via OpenAI TTS.
	 *
	 * @param string $text  Text to convert.
	 * @param string $voice Voice name.
	 * @return string|WP_Error Audio bytes or error.
	 */
	protected static function synthesize_openai( $text, $voice ) {
		$api_key = self::get_openai_api_key();

		if ( ! $api_key ) {
			return new WP_Error(
				'nvoos_librechat_tts_no_key',
				__( 'No OpenAI API key configured.', 'nvoos-librechat' )
			);
		}

		$allowed_voices = array( 'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'ash', 'coral', 'sage' );
		if ( ! in_array( $voice, $allowed_voices, true ) ) {
			$voice = 'alloy';
		}

		// Truncate to 4096 chars (OpenAI limit).
		$text = mb_substr( $text, 0, 4096 );

		$response = wp_remote_post(
			'https://api.openai.com/v1/audio/speech',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => 'tts-1',
						'input'           => $text,
						'voice'           => $voice,
						'response_format' => 'mp3',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			$msg  = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'TTS synthesis failed.', 'nvoos-librechat' );

			return new WP_Error(
				'nvoos_librechat_tts_api_error',
				$msg,
				array( 'status' => $status_code )
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Synthesize via ElevenLabs TTS.
	 *
	 * @param string $text  Text to convert.
	 * @param string $voice Voice ID or name.
	 * @return string|WP_Error Audio bytes or error.
	 */
	protected static function synthesize_elevenlabs( $text, $voice ) {
		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$api_key  = self::get_openai_api_key(); // Reuse OpenAI key slot or check for dedicated key.

		/**
		 * Filter the ElevenLabs API key.
		 *
		 * @param string $api_key API key.
		 */
		$api_key = apply_filters( 'nvoos_librechat_elevenlabs_api_key', $api_key );

		if ( ! $api_key ) {
			return new WP_Error(
				'nvoos_librechat_tts_no_key',
				__( 'No ElevenLabs API key configured.', 'nvoos-librechat' )
			);
		}

		$voice_id = apply_filters( 'nvoos_librechat_elevenlabs_voice_id', '21m00Tcm4TlvDq8ikWAM', $voice );

		$response = wp_remote_post(
			'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice_id ),
			array(
				'timeout' => 30,
				'headers' => array(
					'xi-api-key'   => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode(
					array(
						'text'     => $text,
						'model_id' => 'eleven_turbo_v2_5',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'nvoos_librechat_tts_api_error',
				__( 'ElevenLabs TTS synthesis failed.', 'nvoos-librechat' ),
				array( 'status' => $status_code )
			);
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Retrieve the OpenAI API key from NV oOS settings.
	 *
	 * @return string API key or empty string.
	 */
	protected static function get_openai_api_key() {
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';
		}

		return '';
	}
}
