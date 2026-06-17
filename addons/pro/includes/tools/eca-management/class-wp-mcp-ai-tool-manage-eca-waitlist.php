<?php
/**
 * Tool for managing ECA waitlists.
 *
 * Allows AI assistants to view, reorder, and manage waitlisted students for an ECA.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages waitlisted students for an ECA.
 */
class WP_MCP_AI_Tool_Manage_ECA_Waitlist implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_eca_waitlist';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage ECA Waitlist', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'View, reorder, and manage waitlisted students for an ECA.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'     => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Waitlist action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'promote', 'promote_next', 'reorder', 'remove' ),
				),
				'student_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required for promote, reorder, remove)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'position'   => array(
					'type'        => 'integer',
					'description' => __( 'New waitlist position for the student (used with reorder action)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'eca_id', 'action' ),
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
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage waitlists.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		if ( ! $eca_id || ! $action ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'Both eca_id and action are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify ECA exists.
		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_actions = array( 'list', 'promote', 'promote_next', 'reorder', 'remove' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				__( 'Invalid waitlist action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $eca_enrollments ) ) {
			$eca_enrollments = array();
		}

		// Build ordered waitlist.
		$waitlist = array();
		foreach ( $eca_enrollments as $sid => $enrollment ) {
			if ( isset( $enrollment['enrollment_type'] ) && 'waitlist' === $enrollment['enrollment_type'] ) {
				$waitlist[ $sid ] = $enrollment;
			}
		}

		// Sort by enrollment date to establish natural waitlist order.
		uasort(
			$waitlist,
			function ( $a, $b ) {
				$date_a = isset( $a['enrollment_date'] ) ? $a['enrollment_date'] : '';
				$date_b = isset( $b['enrollment_date'] ) ? $b['enrollment_date'] : '';
				return strcmp( $date_a, $date_b );
			}
		);

		switch ( $action ) {
			case 'list':
				return $this->action_list( $eca, $waitlist );

			case 'promote':
				$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
				if ( ! $student_id ) {
					return new WP_Error(
						'wp_mcp_ai_missing_student',
						__( 'student_id is required for the promote action.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $this->action_promote( $eca_id, $eca, $student_id, $eca_enrollments, $waitlist );

			case 'promote_next':
				return $this->action_promote_next( $eca_id, $eca, $eca_enrollments, $waitlist );

			case 'reorder':
				$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
				$position   = isset( $arguments['position'] ) ? absint( $arguments['position'] ) : 0;
				if ( ! $student_id || ! $position ) {
					return new WP_Error(
						'wp_mcp_ai_missing_params',
						__( 'student_id and position are required for the reorder action.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $this->action_reorder( $eca_id, $eca, $student_id, $position, $eca_enrollments, $waitlist );

			case 'remove':
				$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
				if ( ! $student_id ) {
					return new WP_Error(
						'wp_mcp_ai_missing_student',
						__( 'student_id is required for the remove action.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $this->action_remove( $eca_id, $eca, $student_id, $eca_enrollments, $waitlist );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid waitlist action.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * List waitlisted students.
	 *
	 * @param WP_Post $eca      ECA post object.
	 * @param array   $waitlist Ordered waitlist entries.
	 * @return array Waitlist listing result.
	 */
	private function action_list( $eca, $waitlist ) {
		$list     = array();
		$position = 1;

		foreach ( $waitlist as $sid => $enrollment ) {
			$student = get_post( $sid );
			$list[]  = array(
				'position'        => $position,
				'student_id'      => absint( $sid ),
				'student_name'    => $student ? $student->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
				'enrollment_date' => isset( $enrollment['enrollment_date'] ) ? $enrollment['enrollment_date'] : '',
				'notes'           => isset( $enrollment['notes'] ) ? $enrollment['notes'] : '',
			);
			++$position;
		}

		return array(
			'success'  => true,
			'eca_id'   => $eca->ID,
			'eca_name' => $eca->post_title,
			'total'    => count( $list ),
			'waitlist' => $list,
		);
	}

	/**
	 * Promote a specific student from waitlist to confirmed.
	 *
	 * @param int     $eca_id          ECA post ID.
	 * @param WP_Post $eca             ECA post object.
	 * @param int     $student_id      Student post ID.
	 * @param array   $eca_enrollments All ECA enrollments.
	 * @param array   $waitlist        Ordered waitlist entries.
	 * @return array|WP_Error Promotion result or error.
	 */
	private function action_promote( $eca_id, $eca, $student_id, $eca_enrollments, $waitlist ) {
		if ( ! isset( $waitlist[ $student_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_on_waitlist',
				__( 'Student is not on the waitlist for this ECA.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->promote_student( $eca_id, $eca, $student_id, $eca_enrollments );
	}

	/**
	 * Promote the next student on the waitlist.
	 *
	 * @param int     $eca_id          ECA post ID.
	 * @param WP_Post $eca             ECA post object.
	 * @param array   $eca_enrollments All ECA enrollments.
	 * @param array   $waitlist        Ordered waitlist entries.
	 * @return array|WP_Error Promotion result or error.
	 */
	private function action_promote_next( $eca_id, $eca, $eca_enrollments, $waitlist ) {
		if ( empty( $waitlist ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_waitlist',
				__( 'The waitlist is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		$waitlist_ids = array_keys( $waitlist );
		$next_student = reset( $waitlist_ids );

		return $this->promote_student( $eca_id, $eca, absint( $next_student ), $eca_enrollments );
	}

	/**
	 * Reorder a student on the waitlist.
	 *
	 * @param int     $eca_id          ECA post ID.
	 * @param WP_Post $eca             ECA post object.
	 * @param int     $student_id      Student post ID.
	 * @param int     $position        New position (1-based).
	 * @param array   $eca_enrollments All ECA enrollments.
	 * @param array   $waitlist        Ordered waitlist entries.
	 * @return array|WP_Error Reorder result or error.
	 */
	private function action_reorder( $eca_id, $eca, $student_id, $position, $eca_enrollments, $waitlist ) {
		if ( ! isset( $waitlist[ $student_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_on_waitlist',
				__( 'Student is not on the waitlist for this ECA.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $position > count( $waitlist ) ) {
			$position = count( $waitlist );
		}

		// Remove student from current position.
		$student_entry = $waitlist[ $student_id ];
		unset( $waitlist[ $student_id ] );

		// Re-index to insert at position.
		$waitlist_indexed = array_values( $waitlist );
		$waitlist_keys    = array_keys( $waitlist );
		$new_waitlist     = array();

		$insert_index = $position - 1;

		$idx = 0;
		foreach ( $waitlist_keys as $i => $key ) {
			if ( $idx === $insert_index ) {
				$new_waitlist[ $student_id ] = $student_entry;
			}
			$new_waitlist[ $key ] = $waitlist_indexed[ $i ];
			++$idx;
		}

		// If position is at or past the end.
		if ( ! isset( $new_waitlist[ $student_id ] ) ) {
			$new_waitlist[ $student_id ] = $student_entry;
		}

		// Update waitlist order via enrollment dates to reflect new order.
		$base_time = current_time( 'timestamp' );
		$order     = 0;
		foreach ( $new_waitlist as $sid => $entry ) {
			$entry['enrollment_date'] = gmdate( 'Y-m-d H:i:s', $base_time + $order );
			$eca_enrollments[ $sid ]  = $entry;

			// Update student-side meta as well.
			$student_enrollments = get_post_meta( $sid, '_student_eca_enrollments', true );
			if ( is_array( $student_enrollments ) && isset( $student_enrollments[ $eca_id ] ) ) {
				$student_enrollments[ $eca_id ]['enrollment_date'] = $entry['enrollment_date'];
				update_post_meta( $sid, '_student_eca_enrollments', $student_enrollments );
			}

			++$order;
		}

		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		$student = get_post( $student_id );

		return array(
			'success'      => true,
			'eca_id'       => $eca_id,
			'eca_name'     => $eca->post_title,
			'student_id'   => $student_id,
			'student_name' => $student ? $student->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'new_position' => $position,
			'message'      => sprintf(
				/* translators: 1: student name, 2: position number */
				__( '%1$s moved to waitlist position %2$d.', 'mcp-ai-wpoos-pro' ),
				$student ? $student->post_title : __( 'Student', 'mcp-ai-wpoos-pro' ),
				$position
			),
		);
	}

	/**
	 * Remove a student from the waitlist.
	 *
	 * @param int     $eca_id          ECA post ID.
	 * @param WP_Post $eca             ECA post object.
	 * @param int     $student_id      Student post ID.
	 * @param array   $eca_enrollments All ECA enrollments.
	 * @param array   $waitlist        Ordered waitlist entries.
	 * @return array|WP_Error Removal result or error.
	 */
	private function action_remove( $eca_id, $eca, $student_id, $eca_enrollments, $waitlist ) {
		if ( ! isset( $waitlist[ $student_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_on_waitlist',
				__( 'Student is not on the waitlist for this ECA.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Remove from ECA enrollments.
		unset( $eca_enrollments[ $student_id ] );
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		// Remove from student enrollments.
		$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( is_array( $student_enrollments ) && isset( $student_enrollments[ $eca_id ] ) ) {
			unset( $student_enrollments[ $eca_id ] );
			update_post_meta( $student_id, '_student_eca_enrollments', $student_enrollments );
		}

		$student = get_post( $student_id );

		return array(
			'success'      => true,
			'eca_id'       => $eca_id,
			'eca_name'     => $eca->post_title,
			'student_id'   => $student_id,
			'student_name' => $student ? $student->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'message'      => sprintf(
				/* translators: 1: student name, 2: ECA name */
				__( '%1$s has been removed from the waitlist for %2$s.', 'mcp-ai-wpoos-pro' ),
				$student ? $student->post_title : __( 'Student', 'mcp-ai-wpoos-pro' ),
				$eca->post_title
			),
		);
	}

	/**
	 * Promote a student from waitlist to confirmed enrollment.
	 *
	 * @param int     $eca_id          ECA post ID.
	 * @param WP_Post $eca             ECA post object.
	 * @param int     $student_id      Student post ID.
	 * @param array   $eca_enrollments All ECA enrollments.
	 * @return array Promotion result.
	 */
	private function promote_student( $eca_id, $eca, $student_id, $eca_enrollments ) {
		// Update ECA-side enrollment.
		$eca_enrollments[ $student_id ]['enrollment_type'] = 'confirmed';
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		// Update student-side enrollment.
		$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( is_array( $student_enrollments ) && isset( $student_enrollments[ $eca_id ] ) ) {
			$student_enrollments[ $eca_id ]['enrollment_type'] = 'confirmed';
			update_post_meta( $student_id, '_student_eca_enrollments', $student_enrollments );
		}

		// Increment enrollment count.
		$current_count = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		update_post_meta( $eca_id, '_eca_current_enrollment', $current_count + 1 );

		// Check if now full and update status.
		$max_students = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
		if ( $max_students > 0 && ( $current_count + 1 ) >= $max_students ) {
			update_post_meta( $eca_id, '_eca_status', 'full' );
		}

		$student = get_post( $student_id );

		/**
		 * Fires when a student is promoted from the waitlist.
		 *
		 * @param int $student_id Student post ID.
		 * @param int $eca_id     ECA post ID.
		 */
		do_action( 'wp_mcp_ai_eca_waitlist_promoted', $student_id, $eca_id );

		return array(
			'success'      => true,
			'eca_id'       => $eca_id,
			'eca_name'     => $eca->post_title,
			'student_id'   => $student_id,
			'student_name' => $student ? $student->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'message'      => sprintf(
				/* translators: 1: student name, 2: ECA name */
				__( '%1$s has been promoted from waitlist to confirmed for %2$s.', 'mcp-ai-wpoos-pro' ),
				$student ? $student->post_title : __( 'Student', 'mcp-ai-wpoos-pro' ),
				$eca->post_title
			),
		);
	}
}
