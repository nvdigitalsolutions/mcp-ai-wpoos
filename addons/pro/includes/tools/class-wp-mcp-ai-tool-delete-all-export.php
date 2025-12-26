<?php
/**
 * Pro Tool: Delete WP All Export Template.
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
 * Deletes a WP All Export template.
 */
class WP_MCP_AI_Pro_Tool_Delete_All_Export implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WP All Export is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'PMXE_Plugin' ) || defined( 'PMXE_VERSION' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WP All Export Pro tool is disabled because WP All Export plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_all_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete WP All Export Template', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a WP All Export template and its associated files (Pro feature). Requires WP All Export plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'export_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the export template to delete.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'export_id' ),
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
			return new WP_Error( 'wp_mcp_ai_all_export_missing', __( 'WP All Export is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete exports.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete exports.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['export_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Export ID is required.', 'wp-mcp-ai' ) );
		}

		$export_id = absint( $arguments['export_id'] );

		// Verify export exists.
		$export = get_post( $export_id );
		if ( ! $export || 'pmxe_exports' !== $export->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_export', __( 'Invalid export ID.', 'wp-mcp-ai' ) );
		}

		$export_name = $export->post_title;

		// Clear any scheduled events for this export.
		$hook = get_post_meta( $export_id, 'schedule_hook', true );
		if ( $hook ) {
			$scheduled = wp_next_scheduled( $hook, array( $export_id ) );
			if ( $scheduled ) {
				wp_unschedule_event( $scheduled, $hook, array( $export_id ) );
			}
		}

		// Delete export files if they exist.
		$file_path = get_post_meta( $export_id, 'current_filepath', true );
		if ( $file_path && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		// Delete the export post and all associated meta.
		$deleted = wp_delete_post( $export_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete export template.', 'wp-mcp-ai' ) );
		}

		return array(
			'success'     => true,
			'message'     => __( 'Export template deleted successfully.', 'wp-mcp-ai' ),
			'export_id'   => $export_id,
			'export_name' => $export_name,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Export plugin.
			'state-changing',      // Modifies state by deleting data.
			'destructive',         // Permanently deletes data.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
