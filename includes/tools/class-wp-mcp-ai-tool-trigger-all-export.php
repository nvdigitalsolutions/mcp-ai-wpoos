<?php
/**
 * Tool for triggering WP All Export.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Triggers a WP All Export template to execute.
 */
class WP_MCP_AI_Tool_Trigger_All_Export implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The WP All Export tool is disabled because WP All Export plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'trigger_all_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Trigger WP All Export', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Triggers a WP All Export template to execute and generate export file. Requires WP All Export plugin.', 'wp-mcp-ai' );
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
					'description' => __( 'The ID of the export template to trigger.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to trigger exports.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to trigger exports.', 'wp-mcp-ai' ) );
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

		// Check if PMXE_Export_Record class exists.
		if ( ! class_exists( 'PMXE_Export_Record' ) ) {
			return new WP_Error( 'wp_mcp_ai_class_missing', __( 'PMXE_Export_Record class not found. WP All Export may not be properly installed.', 'wp-mcp-ai' ) );
		}

		// Load and trigger the export using PMXE API.
		try {
			$export_record = new PMXE_Export_Record();
			$export_record->getById( $export_id );

			if ( ! $export_record->id ) {
				return new WP_Error( 'wp_mcp_ai_export_not_found', __( 'Export record not found.', 'wp-mcp-ai' ) );
			}

			// Trigger the export process.
			$export_record->process();
			$export_record->execute();

			$file_path = $export_record->options['current_filepath'] ?? '';
			$file_url  = '';

			if ( $file_path && file_exists( $file_path ) ) {
				$upload_dir = wp_upload_dir();
				$file_url   = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
			}

			return array(
				'success'     => true,
				'message'     => __( 'Export triggered successfully.', 'wp-mcp-ai' ),
				'export_id'   => $export_id,
				'export_name' => $export->post_title,
				'file_path'   => $file_path,
				'file_url'    => $file_url,
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_export_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Export failed: %s', 'wp-mcp-ai' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Export plugin.
			'state-changing',      // Modifies state by generating export files.
			'local-only',          // No external API calls.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
