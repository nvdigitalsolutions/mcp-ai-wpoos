<?php
/**
 * File client bridge — raw provider file operations (retrieve, delete,
 * download) for the attachments pipeline.
 *
 * In monolith installs (base plugin present) the bridge delegates to the
 * base plugin's `WP_MCP_AI_OpenAI_Client` so behaviour is byte-identical.
 * In standalone installs the OpenAI client is not yet ported (Wave D2),
 * so each operation returns a descriptive WP_Error; callers already
 * degrade gracefully (delete logs nothing, resolve maps to the
 * unknown-reference error).
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
 * Provider file client bridge.
 *
 * @since 1.1.0
 */
class FileClientBridge {

	/**
	 * Whether the base client is available (monolith install).
	 *
	 * @return bool
	 */
	private function base_available(): bool {
		return defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_OpenAI_Client' );
	}

	/**
	 * Retrieve file metadata from the provider.
	 *
	 * @param string $file_id Provider file identifier.
	 * @return array|\WP_Error File metadata or error.
	 */
	public function retrieve_file( string $file_id ) {
		if ( $this->base_available() ) {
			return ( new \WP_MCP_AI_OpenAI_Client() )->retrieve_file( $file_id );
		}

		return new \WP_Error(
			'wp_mcp_ai_file_api_unavailable',
			__( 'The provider file API is not available in this install.', 'nvoos-content-graph-ai' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Delete a remote provider file.
	 *
	 * @param string $file_id Provider file identifier.
	 * @return bool|\WP_Error True on success or error.
	 */
	public function delete_file( string $file_id ) {
		if ( $this->base_available() ) {
			return ( new \WP_MCP_AI_OpenAI_Client() )->delete_file( $file_id );
		}

		return new \WP_Error(
			'wp_mcp_ai_file_api_unavailable',
			__( 'The provider file API is not available in this install.', 'nvoos-content-graph-ai' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Download a remote provider file.
	 *
	 * @param string $file_id Provider file identifier.
	 * @return array|\WP_Error Download payload (`body`, `filename`,
	 *                         `content_type`) or error.
	 */
	public function download_file( string $file_id ) {
		if ( $this->base_available() ) {
			return ( new \WP_MCP_AI_OpenAI_Client() )->download_file( $file_id );
		}

		return new \WP_Error(
			'wp_mcp_ai_file_api_unavailable',
			__( 'The provider file API is not available in this install.', 'nvoos-content-graph-ai' ),
			array( 'status' => 501 )
		);
	}
}
