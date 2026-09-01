<?php
/**
 * File service bridge — provider file-API operations for the attachments
 * pipeline (upload, provider detection, capability probing).
 *
 * In monolith installs (base plugin present) the bridge delegates to the
 * base plugin's `WP_MCP_AI_File_Service_Factory` so behaviour is
 * byte-identical. In standalone installs the remote file APIs are not yet
 * ported (Wave D2 — provider clients), so the bridge reports
 * `provider_supports_files() === false`, which routes every attachment
 * through the pipeline's `local-` reference path (the same path the base
 * uses for providers without a File API) and returns a descriptive
 * WP_Error from `upload_file()`.
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider file-service bridge.
 *
 * @since 1.1.0
 */
class FileServiceBridge {

	/**
	 * Detect the provider for a model identifier.
	 *
	 * @param string $model Model identifier.
	 * @return string Provider slug, or 'unknown'.
	 */
	public function detect_provider_from_model( string $model ): string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_File_Service_Factory' ) ) {
			return \WP_MCP_AI_File_Service_Factory::detect_provider_from_model( $model );
		}

		return 'unknown';
	}

	/**
	 * Whether the provider has a remote File API available.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function provider_supports_files( string $provider ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_File_Service_Factory' ) ) {
			return \WP_MCP_AI_File_Service_Factory::provider_supports_files( $provider );
		}

		// Standalone: provider file clients are not ported yet (Wave D2).
		// Returning false routes attachments through the local-reference path.
		return false;
	}

	/**
	 * Upload a file to the provider's File API.
	 *
	 * @param string $file_path Local file path.
	 * @param string $mime_type File MIME type.
	 * @param string $provider  Provider slug.
	 * @param array  $options   Upload options (purpose, filename, …).
	 * @return array|\WP_Error Upload result or error.
	 */
	public function upload_file( string $file_path, string $mime_type, string $provider, array $options = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_File_Service_Factory' ) ) {
			return \WP_MCP_AI_File_Service_Factory::upload_file( $file_path, $mime_type, $provider, $options );
		}

		return new \WP_Error(
			'wp_mcp_ai_file_api_unavailable',
			__( 'Remote file uploads are unavailable: the provider file API is not available in this install.', 'nvoos-content-graph-ai' ),
			array( 'status' => 501 )
		);
	}
}
