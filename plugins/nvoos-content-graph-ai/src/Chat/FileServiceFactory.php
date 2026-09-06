<?php
/**
 * File Service Factory for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/services/class-wp-mcp-ai-file-service-factory.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Provider detection, support probing, and upload flow
 * are byte-identical.
 *
 * Decoupling (documented, additive): the factory returns the CG-AI
 * `OpenAiFileService` / `GeminiFileService` ports instead of the base
 * classes. In monolith installs the attachment pipeline's bridges keep
 * delegating to the base factory directly; this class is what standalone
 * installs use (closes the Wave D1e tracked gap).
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Service Factory class.
 *
 * @since 1.1.0
 */
class FileServiceFactory {

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
				return new OpenAiFileService();

			case 'gemini':
			case 'google':
				return new GeminiFileService();

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
	 * @return array|\WP_Error Upload result or error.
	 */
	public static function upload_file( $file_path, $mime_type, $provider, $options = array() ) {
		$file_service = self::get_file_service( $provider );

		if ( null === $file_service ) {
			return new \WP_Error(
				'wp_mcp_ai_unsupported_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'File uploads are not supported for provider: %s', 'nvoos-content-graph-ai' ),
					$provider
				),
				array( 'status' => 400 )
			);
		}

		// Prepare options based on provider.
		$upload_options = $options;

		// OpenAI-specific options.
		if ( 'openai' === $provider ) {
			// Check if it's already cached first.
			$attachment_id = isset( $options['attachment_id'] ) ? absint( $options['attachment_id'] ) : 0;
			$purpose       = isset( $options['purpose'] ) ? $options['purpose'] : 'assistants';

			if ( $attachment_id > 0 ) {
				$cached = $file_service->get_cached_file( '', $attachment_id, $purpose );
				if ( $cached && isset( $cached['file_id'] ) ) {
					return $cached;
				}
			}

			// Upload to OpenAI.
			$result = $file_service->upload_file(
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

		return new \WP_Error(
			'wp_mcp_ai_provider_not_implemented',
			sprintf(
				/* translators: %s: provider name */
				__( 'File upload not yet implemented for provider: %s', 'nvoos-content-graph-ai' ),
				$provider
			),
			array( 'status' => 501 )
		);
	}
}
