<?php
/**
 * Provider Image Edit Trait — AI image editing through the provider clients.
 *
 * Shared plumbing for the image-production tools that edit existing images
 * through hosted AI providers (Gemini / OpenAI): an upfront credential
 * gate and a single implementation that returns the raw edited image bytes.
 *
 * Routing is additive per the media-worker architecture rules: the Media
 * Worker sidecar /api/image/edit route is the worker path, the PHP
 * provider clients are the local fallback, and when neither backend can
 * serve the request the caller surfaces an honest wp_mcp_ai_no_provider
 * error instead of claiming success.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Traits
 * @since 1.1.95
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI image editing through the provider clients.
 *
 * @since 1.1.95
 */
trait WP_MCP_AI_Provider_Image_Edit {

	/**
	 * Whether the given provider has credentials configured.
	 *
	 * Prefers the Credential_Resolver (which understands settings, env
	 * vars, and constants); falls back to a plain settings read when the
	 * resolver is unavailable.
	 *
	 * @param string $provider Provider id ('gemini' or 'openai').
	 * @return bool True when credentials are configured.
	 */
	protected function provider_has_credentials( $provider ) {
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) && method_exists( 'WP_MCP_AI_Credential_Resolver', 'has_credentials' ) ) {
			return WP_MCP_AI_Credential_Resolver::has_credentials( $provider );
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return is_array( $settings ) && ! empty( $settings[ $provider . '_api_key' ] );
	}

	/**
	 * Run an AI image edit through the configured provider clients and
	 * return the raw edited image bytes.
	 *
	 * Promoted from WP_MCP_AI_Tool_Harmonization_Base::ai_edit_image()
	 * (which predates this trait and is deliberately left unrefactored),
	 * with an upfront credential gate so the no-key case is an honest
	 * wp_mcp_ai_no_api_key error before any client is constructed.
	 *
	 * @param string $path     Source image path.
	 * @param string $prompt   Edit instruction prompt.
	 * @param string $provider 'gemini' or 'openai'.
	 * @return string|WP_Error Raw image bytes or error.
	 */
	protected function ai_edit_image_bytes( $path, $prompt, $provider ) {
		if ( ! $this->provider_has_credentials( $provider ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_api_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'No %s API key is configured.', 'mcp-ai-wpoos-pro' ),
					strtoupper( $provider )
				)
			);
		}

		if ( 'gemini' === $provider && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$image_data = file_get_contents( $path );
			if ( false === $image_data || '' === $image_data ) {
				return new WP_Error( 'wp_mcp_ai_read_failed', __( 'Failed to read image.', 'mcp-ai-wpoos-pro' ) );
			}
			$mime   = wp_check_filetype( $path );
			$mtype  = isset( $mime['type'] ) && '' !== $mime['type'] ? $mime['type'] : 'image/png';
			$client = new WP_MCP_AI_Gemini_Client();
			$result = $client->edit_image(
				$prompt,
				array(
					'source_image' => array(
						'data'      => base64_encode( $image_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the Gemini image API.
						'mime_type' => $mtype,
					),
					'mime_type'    => 'image/png',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['image'] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'Gemini edit returned empty.', 'mcp-ai-wpoos-pro' ) );
			}
			return $result['image'];
		}

		if ( 'openai' === $provider && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new WP_MCP_AI_OpenAI_Client();
			$result = $client->edit_image( $path, $prompt, array( 'model' => 'gpt-image-2' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( empty( $result['data'][0] ) ) {
				return new WP_Error( 'wp_mcp_ai_empty_result', __( 'OpenAI edit returned empty.', 'mcp-ai-wpoos-pro' ) );
			}
			$first = $result['data'][0];
			if ( ! empty( $first['b64_json'] ) ) {
				$cleaned = str_replace( array( "\r", "\n", ' ' ), '', $first['b64_json'] );
				$decoded = base64_decode( $cleaned, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding provider-returned image bytes is the transport contract, not obfuscation.
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
				$code = (int) wp_remote_retrieve_response_code( $resp );
				if ( 200 !== $code ) {
					return new WP_Error(
						'wp_mcp_ai_image_download_failed',
						sprintf(
							/* translators: %d: HTTP status code */
							__( 'OpenAI image URL returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
							$code
						)
					);
				}
				return (string) wp_remote_retrieve_body( $resp );
			}
			return new WP_Error( 'wp_mcp_ai_empty_result', __( 'OpenAI edit returned no usable image.', 'mcp-ai-wpoos-pro' ) );
		}

		return new WP_Error( 'wp_mcp_ai_no_provider', __( 'No supported AI provider for editing.', 'mcp-ai-wpoos-pro' ) );
	}
}
