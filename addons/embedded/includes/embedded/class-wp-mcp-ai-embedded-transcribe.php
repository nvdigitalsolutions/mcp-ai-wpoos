<?php
/**
 * Embedded Transcribe — Server-Side Speech-to-Text Handler
 *
 * Provides the server-side transcription endpoint for the Gemma 4
 * audio backend. Accepts audio data via REST API, sends it to a
 * configured Gemma 4 server (Ollama, vLLM, or NVIDIA NIM), and
 * returns the transcription.
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Transcribe' ) ) {
	/**
	 * Server-side transcription handler.
	 */
	class WP_MCP_AI_Embedded_Transcribe {

		const MAX_AUDIO_SIZE = 10 * 1024 * 1024; // 10 MB.

		/**
		 * Transcribe audio using Gemma 4 server.
		 *
		 * @param string $audio_data Base64 or data URI audio.
		 * @param array  $options    Options: model, language, unified_mode, prompt.
		 * @return array|WP_Error
		 */
		public function transcribe( $audio_data, $options = array() ) {
			$options = wp_parse_args(
				$options,
				array(
					'model'        => 'gemma4:e4b',
					'language'     => 'en',
					'unified_mode' => false,
					'prompt'       => '',
				)
			);

			$audio_bytes = $this->extract_audio_bytes( $audio_data );
			if ( is_wp_error( $audio_bytes ) ) {
				return $audio_bytes;
			}

			if ( strlen( $audio_bytes ) > self::MAX_AUDIO_SIZE ) {
				return new WP_Error(
					'audio_too_large',
					__( 'Audio data exceeds maximum size.', 'nvoos-embedded' ),
					array( 'status' => 413 )
				);
			}

			$settings = get_option( 'nvoos_embedded_settings', array() );
			$endpoint = isset( $settings['gemma4_audio_endpoint'] )
				? esc_url_raw( $settings['gemma4_audio_endpoint'] )
				: '';

			if ( empty( $endpoint ) ) {
				return new WP_Error(
					'gemma4_not_configured',
					__( 'Gemma 4 audio endpoint not configured.', 'nvoos-embedded' ),
					array( 'status' => 500 )
				);
			}

			$model    = sanitize_text_field( $options['model'] );
			$language = sanitize_text_field( $options['language'] );
			$unified  = ! empty( $options['unified_mode'] );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$audio_b64 = base64_encode( $audio_bytes );

			$prompt_text = $unified
				? __( 'Transcribe and respond to this speech:', 'nvoos-embedded' )
				: sprintf(
					/* translators: %s: language code */
					__( 'Transcribe the speech verbatim in %s:', 'nvoos-embedded' ),
					$language
				);

			$request_body = array(
				'model'    => $model,
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $prompt_text,
							),
							array(
								'type'        => 'input_audio',
								'input_audio' => array(
									'data'   => $audio_b64,
									'format' => 'wav',
								),
							),
						),
					),
				),
			);

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 60,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $request_body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'gemma4_request_failed',
					$response->get_error_message(),
					array( 'status' => 502 )
				);
			}

			$status_code   = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $response_body, true );

			if ( 200 !== $status_code ) {
				$err = isset( $response_data['error']['message'] )
					? $response_data['error']['message']
					: sprintf( 'HTTP %d', $status_code );
				return new WP_Error( 'gemma4_error', $err, array( 'status' => $status_code ) );
			}

			$transcription = '';
			if ( isset( $response_data['choices'][0]['message']['content'] ) ) {
				$transcription = trim( $response_data['choices'][0]['message']['content'] );
			}

			return array(
				'text'             => $transcription,
				'language'         => $language,
				'unified_response' => $unified ? $transcription : null,
			);
		}

		/**
		 * Extract raw bytes from data URI or base64.
		 *
		 * @param string $audio_data Base64-encoded audio string or data URI.
		 * @return string|WP_Error
		 */
		private function extract_audio_bytes( $audio_data ) {
			if ( 0 === strpos( $audio_data, 'data:' ) ) {
				$comma = strpos( $audio_data, ',' );
				if ( false === $comma ) {
					return new WP_Error( 'invalid_data_uri', __( 'Invalid data URI.', 'nvoos-embedded' ) );
				}
				$header  = substr( $audio_data, 0, $comma );
				$payload = substr( $audio_data, $comma + 1 );
				if ( false !== strpos( $header, ';base64' ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					$decoded = base64_decode( $payload, true );
					return false !== $decoded ? $decoded : new WP_Error( 'invalid_base64', __( 'Invalid base64.', 'nvoos-embedded' ) );
				}
				return rawurldecode( $payload );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$decoded = base64_decode( $audio_data, true );
			return false !== $decoded ? $decoded : $audio_data;
		}
	}
}
