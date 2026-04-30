<?php
/**
 * Tool: generate_scene_background.
 *
 * Generates a standalone background image from a text prompt using the configured
 * AI provider (Gemini preferred, OpenAI fallback). Optionally conditions on a
 * foreground image so the resulting scene has a believable "landing zone".
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-tool-harmonization-base.php';

/**
 * Generate a scene background from a prompt.
 */
class WP_MCP_AI_Tool_Generate_Scene_Background extends WP_MCP_AI_Tool_Harmonization_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_scene_background';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Scene Background', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a standalone background image from a text prompt using AI (Gemini/OpenAI). Optional foreground hint biases the scene to leave room for a subject. Saves to the Media Library.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'background_prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Description of the background scene to generate.', 'mcp-ai-wpoos-pro' ),
				),
				'aspect_ratio'             => array(
					'type'        => 'string',
					'enum'        => array( '1:1', '4:5', '16:9', '9:16', '3:2', '2:3', 'auto' ),
					'description' => __( 'Aspect ratio for the generated background.', 'mcp-ai-wpoos-pro' ),
					'default'     => '1:1',
				),
				'foreground_attachment_id' => array_merge(
					$this->harmonization_get_image_input_schema( 'optional foreground subject (used to bias composition)' )['attachment_id'],
					array()
				),
				'provider'                 => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'gemini', 'openai' ),
					'default'     => 'auto',
					'description' => __( 'AI provider preference.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'background_prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool body.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param int   $user_id   Authorized user id (0 for token auth).
	 *
	 * @return array|WP_Error
	 */
	protected function execute_harmonization( array $arguments, array $context, $user_id ) {
		$prompt = isset( $arguments['background_prompt'] ) ? sanitize_textarea_field( $arguments['background_prompt'] ) : '';
		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'background_prompt is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$aspect = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '1:1';
		$req    = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'auto';

		$provider = $this->harmonization_detect_provider( $req );
		if ( '' === $provider ) {
			return new WP_Error(
				'wp_mcp_ai_no_provider',
				__( 'No AI image provider is configured (need Gemini or OpenAI API key).', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// If a foreground hint is provided, surface it in the prompt for context-aware composition.
		$enhanced_prompt = $prompt;
		if ( ! empty( $arguments['foreground_attachment_id'] ) ) {
			$enhanced_prompt .= ' ' . __( 'Leave a clear, well-lit area roughly in the lower-center of the frame so a subject can be placed there.', 'mcp-ai-wpoos-pro' );
		}

		$bytes = $this->generate_background( $enhanced_prompt, $aspect, $provider );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$temp_path = $this->harmonization_save_bytes_to_temp( $bytes, 'png' );
		if ( is_wp_error( $temp_path ) ) {
			return $temp_path;
		}

		$media = $this->harmonization_import_to_media(
			$temp_path,
			sprintf(
				/* translators: %s: prompt excerpt */
				__( 'Scene Background: %s', 'mcp-ai-wpoos-pro' ),
				wp_trim_words( $prompt, 8, '...' )
			),
			$user_id
		);
		$this->harmonization_cleanup( $temp_path );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return $this->harmonization_format_response(
			$media,
			$this->get_slug(),
			array(
				'provider'     => $provider,
				'aspect_ratio' => $aspect,
				'prompt'       => $prompt,
			)
		);
	}

	/**
	 * Generate raw image bytes using the resolved provider.
	 *
	 * @param string $prompt   Prompt text.
	 * @param string $aspect   Aspect ratio.
	 * @param string $provider 'gemini' or 'openai'.
	 *
	 * @return string|WP_Error Raw PNG bytes.
	 */
	protected function generate_background( $prompt, $aspect, $provider ) {
		if ( 'gemini' === $provider && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$client = new WP_MCP_AI_Gemini_Client();
			$result = $client->generate_image(
				$prompt,
				array(
					'aspect_ratio' => $this->normalise_for_gemini( $aspect ),
					'mime_type'    => 'image/png',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['image'] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'Gemini returned an empty image.', 'mcp-ai-wpoos-pro' ) );
			}
			return $result['image'];
		}

		if ( 'openai' === $provider && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new WP_MCP_AI_OpenAI_Client();
			$result = $client->generate_image(
				$prompt,
				array(
					'size'  => $this->aspect_to_openai_size( $aspect ),
					'model' => 'gpt-image-1',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['data'][0] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'OpenAI returned an empty image.', 'mcp-ai-wpoos-pro' ) );
			}
			$first = $result['data'][0];
			if ( ! empty( $first['b64_json'] ) ) {
				$cleaned = str_replace( array( "\r", "\n", ' ' ), '', $first['b64_json'] );
				$decoded = base64_decode( $cleaned, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $decoded ) {
					return new WP_Error( 'wp_mcp_ai_decode_failed', __( 'Failed to decode OpenAI image.', 'mcp-ai-wpoos-pro' ) );
				}
				return $decoded;
			}
			if ( ! empty( $first['url'] ) ) {
				$resp = wp_safe_remote_get( $first['url'], array( 'timeout' => 60 ) );
				if ( is_wp_error( $resp ) ) {
					return $resp;
				}
				return (string) wp_remote_retrieve_body( $resp );
			}
			return new WP_Error( 'wp_mcp_ai_empty_result', __( 'OpenAI returned no usable image.', 'mcp-ai-wpoos-pro' ) );
		}

		return new WP_Error( 'wp_mcp_ai_no_provider', __( 'No supported provider available.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Normalise aspect ratio for Gemini.
	 *
	 * @param string $aspect Aspect input.
	 *
	 * @return string
	 */
	protected function normalise_for_gemini( $aspect ) {
		$map = array(
			'1:1'  => '1:1',
			'4:5'  => '4:3',
			'16:9' => '16:9',
			'9:16' => '9:16',
			'3:2'  => '4:3',
			'2:3'  => '3:4',
			'auto' => '4:3',
		);
		return isset( $map[ $aspect ] ) ? $map[ $aspect ] : '1:1';
	}

	/**
	 * Convert aspect to OpenAI size string.
	 *
	 * @param string $aspect Aspect input.
	 *
	 * @return string
	 */
	protected function aspect_to_openai_size( $aspect ) {
		$map = array(
			'1:1'  => '1024x1024',
			'4:5'  => '1024x1024',
			'16:9' => '1536x1024',
			'9:16' => '1024x1536',
			'3:2'  => '1536x1024',
			'2:3'  => '1024x1536',
			'auto' => 'auto',
		);
		return isset( $map[ $aspect ] ) ? $map[ $aspect ] : '1024x1024';
	}
}
