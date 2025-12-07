<?php
/**
 * File Service Factory
 *
 * Creates and returns the appropriate file service based on the AI provider.
 * Maintains separation of concerns by abstracting provider-specific file handling.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Service Factory class
 *
 * Responsible for:
 * - Detecting AI provider from model name
 * - Creating appropriate file service instance
 * - Providing provider-agnostic file operations interface
 *
 * @since 1.0.0
 */
class WP_MCP_AI_File_Service_Factory {

	/**
	 * Get file service for a specific AI provider.
	 *
	 * @param string $provider Provider name ('openai', 'gemini', 'anthropic', etc.).
	 * @return object|null File service instance or null if provider not supported.
	 */
	public static function get_file_service( $provider ) {
		$provider = strtolower( sanitize_key( $provider ) );

		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_File_Service' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-openai-file-service.php';
				}
				return new WP_MCP_AI_OpenAI_File_Service();

			case 'gemini':
			case 'google':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_File_Service' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';
				}
				return new WP_MCP_AI_Gemini_File_Service();

			default:
				return null;
		}
	}

	/**
	 * Detect provider from model name.
	 *
	 * @param string $model Model identifier (e.g., 'gpt-4', 'gemini-pro').
	 * @return string Provider name ('openai', 'gemini', 'anthropic', 'unknown').
	 */
	public static function detect_provider_from_model( $model ) {
		$model = strtolower( sanitize_text_field( $model ) );

		// OpenAI models.
		if ( strpos( $model, 'gpt-' ) === 0 || strpos( $model, 'o1-' ) === 0 || strpos( $model, 'davinci' ) !== false ) {
			return 'openai';
		}

		// Gemini models.
		if ( strpos( $model, 'gemini' ) !== false || strpos( $model, 'palm' ) !== false ) {
			return 'gemini';
		}

		// Anthropic models.
		if ( strpos( $model, 'claude' ) !== false ) {
			return 'anthropic';
		}

		// LM Studio / Ollama (local models).
		if ( strpos( $model, 'lm-studio' ) !== false || strpos( $model, 'ollama' ) !== false ) {
			return 'local';
		}

		return 'unknown';
	}

	/**
	 * Get file service based on model name.
	 *
	 * @param string $model Model identifier.
	 * @return object|null File service instance or null if not supported.
	 */
	public static function get_file_service_for_model( $model ) {
		$provider = self::detect_provider_from_model( $model );
		return self::get_file_service( $provider );
	}

	/**
	 * Check if a provider supports file attachments.
	 *
	 * @param string $provider Provider name.
	 * @return bool True if provider supports file attachments.
	 */
	public static function provider_supports_files( $provider ) {
		$provider = strtolower( sanitize_key( $provider ) );

		$supported_providers = array( 'openai', 'gemini', 'google' );
		return in_array( $provider, $supported_providers, true );
	}

	/**
	 * Check if a model supports file attachments.
	 *
	 * @param string $model Model identifier.
	 * @return bool True if model supports file attachments.
	 */
	public static function model_supports_files( $model ) {
		$provider = self::detect_provider_from_model( $model );
		return self::provider_supports_files( $provider );
	}

	/**
	 * Upload a file using the appropriate provider's file service.
	 *
	 * @param string $file_path    Local file path.
	 * @param string $mime_type    File MIME type.
	 * @param string $provider     Provider name.
	 * @param array  $options      Additional options (display_name, purpose, etc.).
	 * @return array|WP_Error Upload result or error.
	 */
	public static function upload_file( $file_path, $mime_type, $provider, $options = array() ) {
		$file_service = self::get_file_service( $provider );

		if ( null === $file_service ) {
			return new WP_Error(
				'wp_mcp_ai_unsupported_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'File uploads are not supported for provider: %s', 'wp-mcp-ai' ),
					$provider
				),
				array( 'status' => 400 )
			);
		}

		// Prepare options based on provider.
		$upload_options = $options;

		// OpenAI-specific options.
		if ( 'openai' === $provider ) {
			// OpenAI File Service expects different method signature.
			// Check if it's already cached first.
			$attachment_id = isset( $options['attachment_id'] ) ? absint( $options['attachment_id'] ) : 0;
			$purpose       = isset( $options['purpose'] ) ? $options['purpose'] : 'assistants';

			if ( $attachment_id > 0 ) {
				$cached = $file_service->get_cached_file( '', $attachment_id, $purpose );
				if ( $cached && isset( $cached['file_id'] ) ) {
					return $cached;
				}
			}

			// Upload to OpenAI using the OpenAI client.
			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
			}

			$client = new WP_MCP_AI_OpenAI_Client();
			$result = $client->upload_file(
				$file_path,
				array(
					'purpose'   => $purpose,
					'filename'  => isset( $options['filename'] ) ? $options['filename'] : wp_basename( $file_path ),
					'mime_type' => $mime_type,
				)
			);

			// Track in cache if successful.
			if ( ! is_wp_error( $result ) && isset( $result['id'] ) && $attachment_id > 0 ) {
				$file_service->track_uploaded_file(
					$result['id'],
					$purpose,
					wp_basename( $file_path ),
					'',
					$attachment_id
				);
			}

			return $result;
		}

		// Gemini-specific options.
		if ( 'gemini' === $provider || 'google' === $provider ) {
			$display_name = isset( $options['display_name'] ) ? $options['display_name'] : wp_basename( $file_path );
			return $file_service->upload_file( $file_path, $mime_type, $display_name );
		}

		return new WP_Error(
			'wp_mcp_ai_provider_not_implemented',
			sprintf(
				/* translators: %s: provider name */
				__( 'File upload not yet implemented for provider: %s', 'wp-mcp-ai' ),
				$provider
			),
			array( 'status' => 501 )
		);
	}
}
