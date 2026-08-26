<?php
/**
 * Algorave Tool — Generate Music via AI
 *
 * Generates full music tracks using external AI music generation APIs
 * such as Google Lyria (Gemini) or Replicate.
 *
 * @package NV_oOS_Algorave_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-powered music generation via external APIs.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Tool_Generate_Music_AI implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'algorave_generate_music_ai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Music (AI)', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a complete music track using an external AI music generation API (Google Lyria, Replicate, etc.). Provide a text prompt describing the desired music and receive an audio file. Use this when the user wants AI to compose and render a full audio track, not just generate pattern code.', 'nvoos-algorave-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'   => array(
					'type'        => 'string',
					'description' => __( 'Text description of the desired music (e.g. "a 30-second ambient techno loop with deep bass and ethereal pads").', 'nvoos-algorave-ai' ),
					'minLength'   => 1,
					'maxLength'   => 2000,
				),
				'duration' => array(
					'type'        => 'integer',
					'description' => __( 'Desired duration in seconds (max 300).', 'nvoos-algorave-ai' ),
					'default'     => 30,
					'minimum'     => 5,
					'maximum'     => 300,
				),
				'provider' => array(
					'type'        => 'string',
					'description' => __( 'AI provider to use. Leave empty to use the configured default.', 'nvoos-algorave-ai' ),
					'enum'        => array( '', 'lyria', 'replicate' ),
					'default'     => '',
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$prompt   = sanitize_text_field( $arguments['prompt'] ?? '' );
		$duration = isset( $arguments['duration'] ) ? max( 5, min( 300, absint( $arguments['duration'] ) ) ) : 30;
		$provider = sanitize_text_field( $arguments['provider'] ?? '' );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'tool_error',
				__( 'A text prompt describing the desired music is required.', 'nvoos-algorave-ai' )
			);
		}

		// Determine provider.
		$settings = NV_oOS_Algorave::get_settings();
		if ( empty( $provider ) ) {
			$provider = $settings['ai_provider'] ?? '';
		}

		if ( empty( $provider ) ) {
			return new WP_Error(
				'tool_error',
				__( 'No AI music provider configured. Set one in Algorave Settings → AI Music Generation, or specify the "provider" parameter.', 'nvoos-algorave-ai' )
			);
		}

		// Get API key.
		$api_key = $settings['ai_api_key'] ?? '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'tool_error',
				__( 'No API key configured for the AI music provider. Set one in Algorave Settings → AI Music Generation.', 'nvoos-algorave-ai' )
			);
		}

		// Dispatch to the appropriate provider.
		switch ( $provider ) {
			case 'lyria':
				return $this->generate_with_lyria( $prompt, $duration, $api_key );

			case 'replicate':
				return $this->generate_with_replicate( $prompt, $duration, $api_key );

			default:
				return new WP_Error(
					'tool_error',
					sprintf(
						/* translators: %s: provider name */
						__( 'Unsupported AI music provider: %s', 'nvoos-algorave-ai' ),
						$provider
					)
				);
		}
	}

	/**
	 * Generate music via Google Lyria (Gemini API).
	 *
	 * @param string $prompt   Text prompt.
	 * @param int    $duration Duration in seconds.
	 * @param string $api_key  API key.
	 * @return array Result array.
	 */
	private function generate_with_lyria( $prompt, $duration, $api_key ) {
		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/lyria-realtime:generateContent?key=' . rawurlencode( $api_key ),
			array(
				'timeout' => 120,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'contents'         => array(
							array(
								'parts' => array(
									array( 'text' => $prompt ),
								),
							),
						),
						'generationConfig' => array(
							'responseModalities' => array( 'audio' ),
							'speechConfig'       => array(
								'voiceConfig' => array(
									'prebuiltVoiceConfig' => array(
										'voiceName' => 'Leda',
									),
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tool_error',
				$response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'tool_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'Lyria API returned status %1$d: %2$s', 'nvoos-algorave-ai' ),
					$code,
					wp_trim_words( $body, 50 )
				)
			);
		}

		return array(
			'success'  => true,
			'provider' => 'lyria',
			'prompt'   => $prompt,
			'duration' => $duration,
			'response' => json_decode( $body, true ),
			'message'  => __( 'Music generated successfully via Google Lyria. The audio data is included in the response.', 'nvoos-algorave-ai' ),
		);
	}

	/**
	 * Generate music via Replicate API.
	 *
	 * @param string $prompt   Text prompt.
	 * @param int    $duration Duration in seconds.
	 * @param string $api_key  API key.
	 * @return array Result array.
	 */
	private function generate_with_replicate( $prompt, $duration, $api_key ) {
		$response = wp_remote_post(
			'https://api.replicate.com/v1/predictions',
			array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'version' => 'b05b1dff1d8c386be1d05e7e70d0bf76102c36cc1c5f14c56c7523eecfb7c647',
						'input'   => array(
							'prompt'                 => $prompt,
							'duration'               => $duration,
							'output_format'          => 'wav',
							'normalization_strategy' => 'peak',
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tool_error',
				$response->get_error_message()
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'tool_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'Replicate API returned status %1$d: %2$s', 'nvoos-algorave-ai' ),
					$code,
					wp_trim_words( $body, 50 )
				)
			);
		}

		$data = json_decode( $body, true );

		return array(
			'success'       => true,
			'provider'      => 'replicate',
			'prompt'        => $prompt,
			'duration'      => $duration,
			'prediction_id' => $data['id'] ?? '',
			'status'        => $data['status'] ?? 'starting',
			'message'       => __( 'Music generation started on Replicate. The prediction is processing — check the status with the prediction ID.', 'nvoos-algorave-ai' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'requires-credentials', 'external-api', 'network-dependent', 'async', 'consumes-tokens', 'non-deterministic' );
	}
}
