<?php
/**
 * tools/eca-management/class-wp-mcp-ai-tool-delete-eca.php (ecosystem port — Wave F5, eca-management tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/eca-management/class-wp-mcp-ai-tool-delete-eca.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes an ECA with safety checks and cascade cleanup.
 */
class WP_MCP_AI_Tool_Delete_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete ECA', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes or cancels an Extra-Curricular Activity. Supports soft delete (cancel) or permanent deletion with cascade cleanup of student enrollments. Requires confirmation when active enrollments exist.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'         => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to delete (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'soft_delete'    => array(
					'type'        => 'boolean',
					'description' => __( 'If true, sets the ECA status to cancelled instead of permanently deleting it. Default false.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'confirm_delete' => array(
					'type'        => 'boolean',
					'description' => __( 'Must be true when the ECA has active enrollments. This confirms you understand students will be unenrolled.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'activities_coordinator' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete ECAs.', 'nvoos-content-graph-pro' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_eca', __( 'Invalid ECA ID.', 'nvoos-content-graph-pro' ) );
		}

		$soft_delete    = ! empty( $arguments['soft_delete'] );
		$confirm_delete = ! empty( $arguments['confirm_delete'] );

		// Check for active enrollments.
		$enrollments        = get_post_meta( $eca_id, '_eca_enrollments', true );
		$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		$has_enrollments    = $current_enrollment > 0 || ( is_array( $enrollments ) && ! empty( $enrollments ) );

		if ( $has_enrollments && ! $confirm_delete ) {
			return new WP_Error(
				'wp_mcp_ai_confirmation_required',
				sprintf(
					/* translators: %d: enrollment count */
					__( 'This ECA has %d active enrollment(s). Set confirm_delete to true to proceed. Students will be unenrolled.', 'nvoos-content-graph-pro' ),
					$current_enrollment
				)
			);
		}

		// Build list of affected students for audit.
		$affected_students = array();
		if ( is_array( $enrollments ) ) {
			foreach ( $enrollments as $enrollment ) {
				if ( isset( $enrollment['student_id'] ) ) {
					$affected_students[] = absint( $enrollment['student_id'] );
				}
			}
		}

		// Cascade: remove enrollment references from students.
		foreach ( $affected_students as $student_id ) {
			$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
			if ( is_array( $student_enrollments ) ) {
				$student_enrollments = array_filter(
					$student_enrollments,
					function ( $e ) use ( $eca_id ) {
						return isset( $e['eca_id'] ) && absint( $e['eca_id'] ) !== $eca_id;
					}
				);
				update_post_meta( $student_id, '_student_eca_enrollments', array_values( $student_enrollments ) );
			}
		}

		if ( $soft_delete ) {
			// Soft delete: set status to cancelled.
			update_post_meta( $eca_id, '_eca_status', 'cancelled' );
			update_post_meta( $eca_id, '_eca_cancelled_at', current_time( 'mysql' ) );
			update_post_meta( $eca_id, '_eca_cancelled_by', $current_user_id );

			/**
			 * Fires after an ECA is soft-deleted (cancelled).
			 *
			 * @param int   $eca_id            ECA post ID.
			 * @param array $affected_students Student IDs that were enrolled.
			 * @param int   $current_user_id   User who performed the action.
			 */
			do_action( 'wp_mcp_ai_eca_deleted', $eca_id, $affected_students, $current_user_id );

			return array(
				'success'           => true,
				'message'           => __( 'ECA cancelled successfully. Students have been unenrolled.', 'nvoos-content-graph-pro' ),
				'eca_id'            => $eca_id,
				'action'            => 'cancelled',
				'students_affected' => count( $affected_students ),
			);
		}

		// Permanent delete.
		$eca_name = $eca->post_title;
		$result   = wp_delete_post( $eca_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete ECA.', 'nvoos-content-graph-pro' ) );
		}

		/**
		 * Fires after an ECA is permanently deleted.
		 *
		 * @param int   $eca_id            ECA post ID.
		 * @param array $affected_students Student IDs that were enrolled.
		 * @param int   $current_user_id   User who performed the action.
		 */
		do_action( 'wp_mcp_ai_eca_deleted', $eca_id, $affected_students, $current_user_id );

		return array(
			'success'           => true,
			'message'           => sprintf(
				/* translators: %s: ECA name */
				__( 'ECA "%s" permanently deleted. Students have been unenrolled.', 'nvoos-content-graph-pro' ),
				$eca_name
			),
			'eca_id'            => $eca_id,
			'action'            => 'deleted',
			'students_affected' => count( $affected_students ),
		);
	}
}
