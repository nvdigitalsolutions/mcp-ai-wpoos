<?php
/**
 * WP_MCP_AI_Tool_Track_Document_Version (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Track Document Version', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Tracks version history for a document, allowing AI to retrieve version history or create new versions.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Document ID (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action: get_history or create_version (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'get_history', 'create_version' ),
				),
				'new_version'  => array(
					'type'        => 'string',
					'description' => __( 'New version number (required when action=create_version)', 'nvoos-content-graph-pro' ),
				),
				'file_url'     => array(
					'type'        => 'string',
					'description' => __( 'New file URL (required when action=create_version)', 'nvoos-content-graph-pro' ),
				),
				'change_notes' => array(
					'type'        => 'string',
					'description' => __( 'Notes about changes in this version (optional)', 'nvoos-content-graph-pro' ),
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
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['document_id'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Document ID is required.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $arguments['action'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Action is required.', 'nvoos-content-graph-pro' )
			);
		}

		$document_id = absint( $arguments['document_id'] );
		$action      = sanitize_text_field( $arguments['action'] );

		// Verify document exists.
		$document = get_post( $document_id );
		if ( ! $document || 'mcp_ai_reg_document' !== $document->post_type ) {
			return new WP_Error(
				'tool_error',
				__( 'Document not found.', 'nvoos-content-graph-pro' )
			);
		}

		if ( 'get_history' === $action ) {
			return $this->get_version_history( $document_id, $document );
		} elseif ( 'create_version' === $action ) {
			return $this->create_new_version( $document_id, $document, $arguments );
		}

		return new WP_Error(
			'tool_error',
			__( 'Invalid action specified.', 'nvoos-content-graph-pro' )
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
			return new WP_Error(
				'tool_error',
				__( 'New version number is required.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $arguments['file_url'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'File URL is required.', 'nvoos-content-graph-pro' )
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
				__( 'Document version updated from %1$s to %2$s.', 'nvoos-content-graph-pro' ),
				$old_version,
				$new_version
			),
		);
	}
}
