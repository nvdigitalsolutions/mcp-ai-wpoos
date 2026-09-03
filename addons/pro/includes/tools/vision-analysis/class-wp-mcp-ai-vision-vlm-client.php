<?php
/**
 * Vision Analysis — VLM Client
 *
 * Thin provider adapter that runs counting and label-normalization prompts
 * against chat vision-language models (OpenAI, Anthropic, Gemini) and returns
 * the raw text response. The count breakdown itself is always produced by
 * WP_MCP_AI_Vision_Count_Normalizer — this class never does the math.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-specific VLM calls for the Vision Analysis toolkit.
 *
 * @since 1.1.68
 */
class WP_MCP_AI_Vision_VLM_Client {

	/**
	 * Supported VLM providers.
	 *
	 * @var array<string>
	 */
	const SUPPORTED_PROVIDERS = array( 'openai', 'anthropic', 'gemini' );

	/**
	 * Resolve a requested provider (including "auto") to a concrete one.
	 *
	 * "auto" prefers OpenAI, then Anthropic, then Gemini, based on which API
	 * keys are configured. Returns '' when no VLM provider is configured.
	 *
	 * @param string $requested Provider argument ('auto' or a concrete slug).
	 * @return string Concrete provider slug, or '' when unavailable.
	 */
	public function resolve_provider( $requested ) {
		$requested = sanitize_text_field( $requested );
		if ( in_array( $requested, self::SUPPORTED_PROVIDERS, true ) ) {
			return $this->is_configured( $requested ) ? $requested : '';
		}

		// "auto" or unknown → first configured provider in preference order.
		foreach ( self::SUPPORTED_PROVIDERS as $provider ) {
			if ( $this->is_configured( $provider ) ) {
				return $provider;
			}
		}

		return '';
	}

	/**
	 * Check whether a provider has an API key configured.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function is_configured( $provider ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$key_name = $provider . '_api_key';

		$key = isset( $settings[ $key_name ] ) ? $settings[ $key_name ] : '';
		if ( '' === $key && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			$key = WP_MCP_AI_Credential_Resolver::get_api_key( $provider ) ?? '';
		}

		return '' !== $key;
	}

	/**
	 * Get the default chat model for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public function get_default_model( $provider ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		switch ( $provider ) {
			case 'anthropic':
				if ( ! empty( $settings['anthropic_vision_model'] ) ) {
					return sanitize_text_field( $settings['anthropic_vision_model'] );
				}
				return ! empty( $settings['anthropic_model'] ) ? sanitize_text_field( $settings['anthropic_model'] ) : 'claude-sonnet-5';
			case 'gemini':
				return ! empty( $settings['default_gemini_model'] ) ? sanitize_text_field( $settings['default_gemini_model'] ) : 'gemini-2.5-flash';
			case 'openai':
			default:
				return ! empty( $settings['default_model'] ) ? sanitize_text_field( $settings['default_model'] ) : 'gpt-4o-mini';
		}
	}

	/**
	 * Run a VLM request and return the raw text content.
	 *
	 * @param string $provider     Concrete provider slug.
	 * @param string $prompt       Text prompt.
	 * @param string $image_url    Public image URL (may be empty for data-only sources).
	 * @param string $image_base64 Base64-encoded image bytes (no data URI prefix).
	 * @param string $mime_type    Image MIME type (default image/jpeg).
	 * @param string $model        Model override (empty → provider default).
	 * @param int    $max_tokens   Maximum response tokens.
	 * @param int    $timeout      HTTP timeout in seconds.
	 * @return array{content: string, model: string}|WP_Error
	 */
	public function request( $provider, $prompt, $image_url, $image_base64, $mime_type = 'image/jpeg', $model = '', $max_tokens = 1024, $timeout = 60 ) {
		switch ( $provider ) {
			case 'anthropic':
				return $this->request_anthropic( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout );
			case 'gemini':
				return $this->request_gemini( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout );
			case 'openai':
			default:
				return $this->request_openai( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout );
		}
	}

	/**
	 * Build the object-counting prompt.
	 *
	 * @param array<string> $categories Optional candidate labels.
	 * @return string
	 */
	public function build_counting_prompt( array $categories = array() ) {
		$scope = ! empty( $categories )
			? sprintf(
				/* translators: %s: comma-separated candidate labels */
				__( 'Focus especially on these categories: %s.', 'mcp-ai-wpoos-pro' ),
				implode( ', ', $categories )
			)
			: __( 'Detect every distinct object category visible.', 'mcp-ai-wpoos-pro' );

		return $scope . "\n" .
			__( 'Count the instances of each object category visible in the image. Use short, singular, common-English labels (e.g. "person", "car", "cup").', 'mcp-ai-wpoos-pro' ) . "\n" .
			'Return ONLY a JSON object with these keys: "counts" (array of objects, each with "label" (string), "count" (integer), "confidence" (float 0-1)), "total_items" (integer). ' .
			__( 'Do not include any other text.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Build the label-normalization prompt for hybrid mode.
	 *
	 * The VLM renames mislabeled detector categories; it is explicitly told
	 * NOT to recount, so the detector remains authoritative for counts.
	 *
	 * @param array<string> $labels Detected labels.
	 * @return string
	 */
	public function build_normalization_prompt( array $labels ) {
		return sprintf(
			/* translators: %s: comma-separated detected labels */
			__( 'A detector found these object categories in the image: %s.', 'mcp-ai-wpoos-pro' ),
			implode( ', ', $labels )
		) . "\n" .
			__( 'For each label, return its most accurate canonical name (fix obvious mislabels like "teddy bear" for a toy, but never merge different real categories). Do NOT count objects.', 'mcp-ai-wpoos-pro' ) . "\n" .
			'Return ONLY a JSON object mapping each original label to its corrected label, e.g. {"person":"person","vehicle":"car"}. ' .
			__( 'Do not include any other text.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * OpenAI chat-completions request with a JSON object response format.
	 *
	 * @param string $prompt       Prompt text.
	 * @param string $image_url    Public image URL.
	 * @param string $image_base64 Base64 image bytes.
	 * @param string $mime_type    Image MIME type.
	 * @param string $model        Model override.
	 * @param int    $max_tokens   Maximum response tokens.
	 * @param int    $timeout      HTTP timeout in seconds.
	 * @return array{content: string, model: string}|WP_Error
	 */
	private function request_openai( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout ) {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_openai_unavailable',
				__( 'OpenAI client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$content = array(
			array(
				'type' => 'text',
				'text' => $prompt,
			),
			array(
				'type'      => 'image_url',
				'image_url' => array(
					'url' => $this->image_reference( $image_url, $image_base64, $mime_type ),
				),
			),
		);

		$model_id = '' !== $model ? $model : $this->get_default_model( 'openai' );

		$client   = new WP_MCP_AI_OpenAI_Client();
		$response = $client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			array(
				'model'                 => $model_id,
				'max_completion_tokens' => absint( $max_tokens ),
				'timeout'               => max( 10, absint( $timeout ) ),
				'response_format'       => array( 'type' => 'json_object' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_openai_invalid_response',
				__( 'Invalid response from the OpenAI API.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$content = $response['choices'][0]['message']['content'];
		if ( is_array( $content ) ) {
			$content = implode( "\n", wp_list_pluck( $content, 'text' ) );
		}

		return array(
			'content' => (string) $content,
			'model'   => isset( $response['model'] ) ? sanitize_text_field( $response['model'] ) : $model_id,
		);
	}

	/**
	 * Anthropic messages request.
	 *
	 * @param string $prompt       Prompt text.
	 * @param string $image_url    Public image URL.
	 * @param string $image_base64 Base64 image bytes.
	 * @param string $mime_type    Image MIME type.
	 * @param string $model        Model override.
	 * @param int    $max_tokens   Maximum response tokens.
	 * @param int    $timeout      HTTP timeout in seconds.
	 * @return array{content: string, model: string}|WP_Error
	 */
	private function request_anthropic( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout ) {
		if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_anthropic_unavailable',
				__( 'Anthropic client is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$model_id = '' !== $model ? $model : $this->get_default_model( 'anthropic' );

		$client = new WP_MCP_AI_Anthropic_Client();
		try {
			$response = $client->create_chat_completion(
				array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'      => 'image_url',
								'image_url' => array(
									'url' => $this->image_reference( $image_url, $image_base64, $mime_type ),
								),
							),
							array(
								'type' => 'text',
								'text' => $prompt,
							),
						),
					),
				),
				array(
					'model'      => $model_id,
					'max_tokens' => absint( $max_tokens ),
					'timeout'    => max( 10, absint( $timeout ) ),
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_va_anthropic_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Anthropic API error: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// The plugin's Anthropic client returns an OpenAI-normalized shape.
		$content = '';
		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$raw = $response['choices'][0]['message']['content'];
			if ( is_string( $raw ) ) {
				$content = $raw;
			} elseif ( is_array( $raw ) ) {
				foreach ( $raw as $block ) {
					if ( isset( $block['text'] ) ) {
						$content .= $block['text'];
					}
				}
			}
		}

		if ( '' === trim( $content ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_anthropic_empty_response',
				__( 'Anthropic returned an empty response.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'content' => $content,
			'model'   => $model_id,
		);
	}

	/**
	 * Gemini generateContent request (base64 inline data).
	 *
	 * @param string $prompt       Prompt text.
	 * @param string $image_url    Public image URL (ignored — Gemini needs base64).
	 * @param string $image_base64 Base64 image bytes.
	 * @param string $mime_type    Image MIME type.
	 * @param string $model        Model override.
	 * @param int    $max_tokens   Maximum response tokens.
	 * @param int    $timeout      HTTP timeout in seconds.
	 * @return array{content: string, model: string}|WP_Error
	 */
	private function request_gemini( $prompt, $image_url, $image_base64, $mime_type, $model, $max_tokens, $timeout ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$api_key  = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';

		if ( '' === $api_key && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			$api_key = WP_MCP_AI_Credential_Resolver::get_api_key( 'gemini' ) ?? '';
		}

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_va_gemini_missing_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$model_id = '' !== $model ? $model : $this->get_default_model( 'gemini' );

		$request_body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
						array(
							'inline_data' => array(
								'mime_type' => sanitize_text_field( $mime_type ),
								'data'      => $image_base64,
							),
						),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens'  => absint( $max_tokens ),
				'responseMimeType' => 'application/json',
			),
		);

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model_id ) . ':generateContent?key=' . rawurlencode( $api_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => max( 10, absint( $timeout ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error(
				'wp_mcp_ai_va_gemini_api_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Gemini API returned error code %d.', 'mcp-ai-wpoos-pro' ),
					$response_code
				),
				array( 'status' => $response_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_va_gemini_invalid_response',
				__( 'Invalid response from the Gemini API.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'content' => trim( $body['candidates'][0]['content']['parts'][0]['text'] ),
			'model'   => $model_id,
		);
	}

	/**
	 * Build the image reference for a provider message.
	 *
	 * Prefers the public URL (cheaper for providers that can fetch it);
	 * falls back to a base64 data URI for data-only sources.
	 *
	 * @param string $image_url    Public image URL.
	 * @param string $image_base64 Base64 image bytes.
	 * @param string $mime_type    Image MIME type.
	 * @return string
	 */
	private function image_reference( $image_url, $image_base64, $mime_type ) {
		if ( '' !== $image_url && wp_http_validate_url( $image_url ) ) {
			return esc_url_raw( $image_url );
		}

		return sprintf( 'data:%s;base64,%s', sanitize_text_field( $mime_type ), $image_base64 );
	}
}
