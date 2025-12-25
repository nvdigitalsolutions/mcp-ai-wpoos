<?php
/**
 * Pro Tool: Delete WP All Import Template.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Deletes a WP All Import template.
 */
class WP_MCP_AI_Pro_Tool_Delete_All_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WP All Import is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'PMXI_Plugin' ) || defined( 'PMXI_VERSION' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Import Pro tool is disabled because WP All Import plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_all_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete WP All Import Template', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a WP All Import template and its associated files (Pro feature). Requires WP All Import plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'import_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the import template to delete.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'import_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_all_import_missing', __( 'WP All Import is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete imports.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete imports.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['import_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Import ID is required.', 'wp-mcp-ai' ) );
		}

		$import_id = absint( $arguments['import_id'] );

		// Verify import exists.
		$import = get_post( $import_id );
		if ( ! $import || 'import' !== $import->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_import', __( 'Invalid import ID.', 'wp-mcp-ai' ) );
		}

		$import_name = $import->post_title;

		// Clear any scheduled events for this import.
		$hook = get_post_meta( $import_id, 'schedule_hook', true );
		if ( $hook ) {
			$scheduled = wp_next_scheduled( $hook, array( $import_id ) );
			if ( $scheduled ) {
				wp_unschedule_event( $scheduled, $hook, array( $import_id ) );
			}
		}

		// Delete import files if they exist.
		$upload_dir = wp_upload_dir();
		$import_dir = $upload_dir['basedir'] . '/wpallimport/files/' . $import_id;
		if ( is_dir( $import_dir ) ) {
			$this->delete_directory( $import_dir );
		}

		// Delete the import post and all associated meta.
		$deleted = wp_delete_post( $import_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete import template.', 'wp-mcp-ai' ) );
		}

		return array(
			'success'     => true,
			'message'     => __( 'Import template deleted successfully.', 'wp-mcp-ai' ),
			'import_id'   => $import_id,
			'import_name' => $import_name,
		);
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->delete_directory( $path ) : wp_delete_file( $path );
		}
		rmdir( $dir );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Import plugin.
			'state-changing',      // Modifies state by deleting data.
			'destructive',         // Permanently deletes data.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
