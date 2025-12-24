<?php
/**
 * Tool for triggering WP All Import.
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
 * Triggers a WP All Import template to execute.
 */
class WP_MCP_AI_Tool_Trigger_All_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The WP All Import tool is disabled because WP All Import plugin is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'trigger_all_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Trigger WP All Import', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Triggers a WP All Import template to execute and import data. Requires WP All Import plugin.', 'wp-mcp-ai' );
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
					'description' => __( 'The ID of the import template to trigger.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to trigger imports.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to trigger imports.', 'wp-mcp-ai' ) );
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

		// Check if PMXI_Import_Record class exists.
		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			return new WP_Error( 'wp_mcp_ai_class_missing', __( 'PMXI_Import_Record class not found. WP All Import may not be properly installed.', 'wp-mcp-ai' ) );
		}

		// Check if import is already processing.
		$processing = get_post_meta( $import_id, 'processing', true );
		if ( $processing ) {
			return new WP_Error( 'wp_mcp_ai_import_processing', __( 'Import is already processing. Please wait for it to complete.', 'wp-mcp-ai' ) );
		}

		// Load and trigger the import using PMXI API.
		try {
			$import_record = new PMXI_Import_Record();
			$import_record->getById( $import_id );

			if ( ! $import_record->id ) {
				return new WP_Error( 'wp_mcp_ai_import_not_found', __( 'Import record not found.', 'wp-mcp-ai' ) );
			}

			// Get import key for cron URL.
			$import_key = $import_record->import_key ?? '';
			
			// Use the cron URL method to trigger import (recommended approach).
			if ( $import_key ) {
				$trigger_url = add_query_arg(
					array(
						'import_key' => $import_key,
						'import_id'  => $import_id,
						'action'     => 'trigger',
					),
					home_url()
				);

				// Trigger via HTTP request.
				$response = wp_remote_get(
					$trigger_url,
					array(
						'timeout'   => 1,
						'blocking'  => false,
						'sslverify' => false,
					)
				);

				if ( is_wp_error( $response ) ) {
					return new WP_Error(
						'wp_mcp_ai_import_trigger_failed',
						sprintf(
							/* translators: %s: error message */
							__( 'Failed to trigger import: %s', 'wp-mcp-ai' ),
							$response->get_error_message()
						)
					);
				}

				return array(
					'success'     => true,
					'message'     => __( 'Import triggered successfully. Processing in background.', 'wp-mcp-ai' ),
					'import_id'   => $import_id,
					'import_name' => $import->post_title,
					'status'      => 'processing',
				);
			}

			return new WP_Error( 'wp_mcp_ai_no_import_key', __( 'Import key not found. Cannot trigger import.', 'wp-mcp-ai' ) );

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_import_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Import failed: %s', 'wp-mcp-ai' ),
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
			'requires-plugin',     // Requires WP All Import plugin.
			'state-changing',      // Modifies state by importing data.
			'local-only',          // No external API calls (uses internal HTTP).
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
