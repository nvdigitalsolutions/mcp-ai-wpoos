<?php
/**
 * Tool for getting WP All Import status.
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
 * Gets the status of a WP All Import operation.
 */
class WP_MCP_AI_Tool_Get_All_Import_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_all_import_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WP All Import Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets the status and progress of a WP All Import operation. Requires WP All Import plugin.', 'wp-mcp-ai' );
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
					'description' => __( 'The ID of the import to check status.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to check import status.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check import status.', 'wp-mcp-ai' ) );
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

		// Get import status metadata.
		$processing    = get_post_meta( $import_id, 'processing', true );
		$imported      = get_post_meta( $import_id, 'imported', true );
		$created       = get_post_meta( $import_id, 'created', true );
		$updated       = get_post_meta( $import_id, 'updated', true );
		$skipped       = get_post_meta( $import_id, 'skipped', true );
		$deleted       = get_post_meta( $import_id, 'deleted', true );
		$last_activity = get_post_meta( $import_id, 'registered_on', true );
		$iteration     = get_post_meta( $import_id, 'iteration', true );

		// Determine status.
		$status = 'idle';
		if ( $processing ) {
			$status = 'processing';
		} elseif ( $imported > 0 ) {
			$status = 'completed';
		}

		return array(
			'import_id'     => $import_id,
			'import_name'   => $import->post_title,
			'status'        => $status,
			'processing'    => (bool) $processing,
			'stats'         => array(
				'imported' => absint( $imported ),
				'created'  => absint( $created ),
				'updated'  => absint( $updated ),
				'skipped'  => absint( $skipped ),
				'deleted'  => absint( $deleted ),
			),
			'iteration'     => absint( $iteration ),
			'last_activity' => $last_activity ? gmdate( DATE_W3C, strtotime( $last_activity ) ) : '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires WP All Import plugin.
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'cacheable',           // Results can be cached.
			'requires-capability', // Requires 'manage_options' capability.
		);
	}
}
