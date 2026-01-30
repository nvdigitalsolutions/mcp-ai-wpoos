<?php
/**
 * Tool for tracking document versions in the Regulatory Registration system.
 *
 * Allows AI assistants to track version history of documents.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks document version history.
 */
class WP_MCP_AI_Tool_Track_Document_Version implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'track_document_version';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Track Document Version', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Tracks version history for a document, allowing AI to retrieve version history or create new versions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Document ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action: get_history or create_version (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'get_history', 'create_version' ),
				),
				'new_version'  => array(
					'type'        => 'string',
					'description' => __( 'New version number (required when action=create_version)', 'mcp-ai-wpoos-pro' ),
				),
				'file_url'     => array(
					'type'        => 'string',
					'description' => __( 'New file URL (required when action=create_version)', 'mcp-ai-wpoos-pro' ),
				),
				'change_notes' => array(
					'type'        => 'string',
					'description' => __( 'Notes about changes in this version (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'document_id', 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'database-write',       // May write version history.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['document_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['action'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Action is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$document_id = absint( $arguments['document_id'] );
		$action      = sanitize_text_field( $arguments['action'] );

		// Verify document exists.
		$document = get_post( $document_id );
		if ( ! $document || 'mcp_ai_reg_document' !== $document->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Document not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( 'get_history' === $action ) {
			return $this->get_version_history( $document_id, $document );
		} elseif ( 'create_version' === $action ) {
			return $this->create_new_version( $document_id, $document, $arguments );
		}

		return array(
			'success' => false,
			'error'   => __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get version history for a document.
	 *
	 * @param int     $document_id Document ID.
	 * @param WP_Post $document Document post.
	 * @return array Result array.
	 */
	private function get_version_history( $document_id, $document ) {
		// Get current version.
		$current_version  = get_post_meta( $document_id, 'version', true );
		$current_file_url = get_post_meta( $document_id, 'file_url', true );

		// Get version history from meta.
		$version_history = get_post_meta( $document_id, 'version_history', true );
		if ( ! is_array( $version_history ) ) {
			$version_history = array();
		}

		// Add current version to history if not already there.
		$current_version_in_history = false;
		foreach ( $version_history as $version ) {
			if ( $version['version'] === $current_version ) {
				$current_version_in_history = true;
				break;
			}
		}

		if ( ! $current_version_in_history ) {
			$version_history[] = array(
				'version'    => $current_version,
				'file_url'   => $current_file_url,
				'created_at' => $document->post_modified,
				'notes'      => '',
			);
		}

		// Sort by version (latest first).
		usort(
			$version_history,
			function ( $a, $b ) {
				return version_compare( $b['version'], $a['version'] );
			}
		);

		return array(
			'success'         => true,
			'document_id'     => $document_id,
			'current_version' => $current_version,
			'total_versions'  => count( $version_history ),
			'version_history' => $version_history,
		);
	}

	/**
	 * Create a new version of the document.
	 *
	 * @param int     $document_id Document ID.
	 * @param WP_Post $document Document post.
	 * @param array   $arguments Tool arguments.
	 * @return array Result array.
	 */
	private function create_new_version( $document_id, $document, $arguments ) {
		// Validate required arguments for creating version.
		if ( empty( $arguments['new_version'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'New version number is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( empty( $arguments['file_url'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'File URL is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$new_version  = sanitize_text_field( $arguments['new_version'] );
		$new_file_url = esc_url_raw( $arguments['file_url'] );
		$change_notes = ! empty( $arguments['change_notes'] ) ? sanitize_textarea_field( $arguments['change_notes'] ) : '';

		// Get current version info.
		$old_version  = get_post_meta( $document_id, 'version', true );
		$old_file_url = get_post_meta( $document_id, 'file_url', true );

		// Get existing version history.
		$version_history = get_post_meta( $document_id, 'version_history', true );
		if ( ! is_array( $version_history ) ) {
			$version_history = array();
		}

		// Add old version to history.
		$version_history[] = array(
			'version'    => $old_version,
			'file_url'   => $old_file_url,
			'created_at' => $document->post_modified,
			'notes'      => '',
		);

		// Update document with new version.
		update_post_meta( $document_id, 'version', $new_version );
		update_post_meta( $document_id, 'file_url', $new_file_url );
		update_post_meta( $document_id, 'version_history', $version_history );

		// Add change notes to current version in history.
		$version_history[] = array(
			'version'    => $new_version,
			'file_url'   => $new_file_url,
			'created_at' => current_time( 'mysql' ),
			'notes'      => $change_notes,
		);

		// Update post modified time.
		wp_update_post(
			array(
				'ID'            => $document_id,
				'post_modified' => current_time( 'mysql' ),
			)
		);

		return array(
			'success'      => true,
			'document_id'  => $document_id,
			'old_version'  => $old_version,
			'new_version'  => $new_version,
			'new_file_url' => $new_file_url,
			'message'      => sprintf(
				/* translators: 1: old version, 2: new version */
				__( 'Document version updated from %1$s to %2$s.', 'mcp-ai-wpoos-pro' ),
				$old_version,
				$new_version
			),
		);
	}
}
