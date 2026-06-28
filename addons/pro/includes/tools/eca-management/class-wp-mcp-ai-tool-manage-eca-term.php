<?php
/**
 * Tool for managing academic terms for ECA scheduling.
 *
 * Supports creating, listing, and transitioning between terms. Handles
 * rollover of ECAs between terms including enrollment resets and status updates.
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
 * Manages academic terms for ECA scheduling.
 */
class WP_MCP_AI_Tool_Manage_ECA_Term implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_eca_term';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage ECA Term', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages academic terms for ECA scheduling. Supports creating, listing, and transitioning between terms. Handles rollover of ECAs between terms including enrollment resets and status updates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Term management action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'list', 'get', 'transition', 'close' ),
				),
				'term_name'            => array(
					'type'        => 'string',
					'description' => __( 'Name of the term (required for create, e.g. "Term 1 2025-2026")', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'term_id'              => array(
					'type'        => 'string',
					'description' => __( 'Unique term identifier (required for get, transition, close)', 'mcp-ai-wpoos-pro' ),
				),
				'start_date'           => array(
					'type'        => 'string',
					'description' => __( 'Term start date in YYYY-MM-DD format (used with create)', 'mcp-ai-wpoos-pro' ),
				),
				'end_date'             => array(
					'type'        => 'string',
					'description' => __( 'Term end date in YYYY-MM-DD format (used with create)', 'mcp-ai-wpoos-pro' ),
				),
				'academic_year'        => array(
					'type'        => 'string',
					'description' => __( 'Academic year label (e.g. "2025-2026")', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'rollover_enrollments' => array(
					'type'        => 'boolean',
					'description' => __( 'Carry enrollments to the next term during transition', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'reset_attendance'     => array(
					'type'        => 'boolean',
					'description' => __( 'Reset attendance records on transition', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'action' ),
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
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
			'risk_level'            => 'elevated',
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage ECA terms.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		$valid_actions = array( 'create', 'list', 'get', 'transition', 'close' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				__( 'Invalid action. Must be one of: create, list, get, transition, close.', 'mcp-ai-wpoos-pro' )
			);
		}

		switch ( $action ) {
			case 'create':
				return $this->action_create( $arguments, $current_user_id );

			case 'list':
				return $this->action_list();

			case 'get':
				return $this->action_get( $arguments );

			case 'transition':
				return $this->action_transition( $arguments, $current_user_id );

			case 'close':
				return $this->action_close( $arguments, $current_user_id );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid term action.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Create a new academic term.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function action_create( $arguments, $user_id ) {
		$term_name     = isset( $arguments['term_name'] ) ? sanitize_text_field( $arguments['term_name'] ) : '';
		$start_date    = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date      = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$academic_year = isset( $arguments['academic_year'] ) ? sanitize_text_field( $arguments['academic_year'] ) : '';

		if ( '' === $term_name ) {
			return new WP_Error(
				'wp_mcp_ai_missing_name',
				__( 'Term name is required for the create action.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate date formats if provided.
		if ( $start_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'start_date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $end_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'end_date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( $start_date && $end_date && $start_date >= $end_date ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date_range',
				__( 'start_date must be before end_date.', 'mcp-ai-wpoos-pro' )
			);
		}

		$terms = get_option( 'wp_mcp_ai_eca_terms', array() );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		$term_id = 'term_' . uniqid( '', true );

		$new_term = array(
			'id'            => $term_id,
			'name'          => $term_name,
			'start_date'    => $start_date,
			'end_date'      => $end_date,
			'academic_year' => $academic_year,
			'status'        => 'upcoming',
			'created_at'    => current_time( 'mysql' ),
			'created_by'    => $user_id,
		);

		$terms[] = $new_term;
		update_option( 'wp_mcp_ai_eca_terms', $terms );

		return array(
			'success' => true,
			'action'  => 'create',
			'term'    => $new_term,
			'message' => sprintf(
				/* translators: %s: term name */
				__( 'Academic term "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$term_name
			),
		);
	}

	/**
	 * List all academic terms sorted by start date.
	 *
	 * @return array List result.
	 */
	private function action_list() {
		$terms = get_option( 'wp_mcp_ai_eca_terms', array() );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		// Sort by start_date ascending.
		usort(
			$terms,
			function ( $a, $b ) {
				$date_a = isset( $a['start_date'] ) ? $a['start_date'] : '';
				$date_b = isset( $b['start_date'] ) ? $b['start_date'] : '';
				return strcmp( $date_a, $date_b );
			}
		);

		return array(
			'success' => true,
			'action'  => 'list',
			'total'   => count( $terms ),
			'terms'   => $terms,
		);
	}

	/**
	 * Get a specific term including ECA counts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Term details or error.
	 */
	private function action_get( $arguments ) {
		$term_id = isset( $arguments['term_id'] ) ? sanitize_text_field( $arguments['term_id'] ) : '';

		if ( '' === $term_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_term_id',
				__( 'term_id is required for the get action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$term = $this->find_term( $term_id );
		if ( ! $term ) {
			return new WP_Error(
				'wp_mcp_ai_term_not_found',
				__( 'Term not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Count ECAs associated with this term.
		$eca_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'manage_eca_term', 0, 1000 ) : 1000,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_eca_term_id',
						'value'   => $term_id,
						'compare' => '=',
					),
				),
			)
		);

		$term['eca_count'] = $eca_query->found_posts;

		return array(
			'success' => true,
			'action'  => 'get',
			'term'    => $term,
		);
	}

	/**
	 * Transition to a new term: complete the current term and activate the specified one.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error Transition result or error.
	 */
	private function action_transition( $arguments, $user_id ) {
		$term_id              = isset( $arguments['term_id'] ) ? sanitize_text_field( $arguments['term_id'] ) : '';
		$rollover_enrollments = isset( $arguments['rollover_enrollments'] ) ? (bool) $arguments['rollover_enrollments'] : false;
		$reset_attendance     = isset( $arguments['reset_attendance'] ) ? (bool) $arguments['reset_attendance'] : true;

		if ( '' === $term_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_term_id',
				__( 'term_id is required for the transition action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$terms = get_option( 'wp_mcp_ai_eca_terms', array() );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		$target_index  = null;
		$current_index = null;

		foreach ( $terms as $index => $term ) {
			if ( $term['id'] === $term_id ) {
				$target_index = $index;
			}
			if ( isset( $term['status'] ) && 'active' === $term['status'] ) {
				$current_index = $index;
			}
		}

		if ( null === $target_index ) {
			return new WP_Error(
				'wp_mcp_ai_term_not_found',
				__( 'Target term not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Complete the currently active term.
		if ( null !== $current_index ) {
			$terms[ $current_index ]['status']       = 'completed';
			$terms[ $current_index ]['completed_at'] = current_time( 'mysql' );
			$terms[ $current_index ]['completed_by'] = $user_id;
		}

		// Activate the target term.
		$terms[ $target_index ]['status']       = 'active';
		$terms[ $target_index ]['activated_at'] = current_time( 'mysql' );
		$terms[ $target_index ]['activated_by'] = $user_id;

		update_option( 'wp_mcp_ai_eca_terms', $terms );

		$enrollments_rolled = 0;
		$attendance_reset   = 0;

		// Get all ECA posts for rollover and attendance reset.
		$ecas = get_posts(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'manage_eca_term', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		// Rollover enrollments if requested.
		if ( $rollover_enrollments && ! empty( $ecas ) ) {
			foreach ( $ecas as $eca_id ) {
				$enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
				if ( is_array( $enrollments ) && ! empty( $enrollments ) ) {
					update_post_meta( $eca_id, '_eca_student_enrollments_' . $term_id, $enrollments );
					++$enrollments_rolled;
				}
			}
		}

		// Reset attendance records if requested.
		if ( $reset_attendance && ! empty( $ecas ) ) {
			foreach ( $ecas as $eca_id ) {
				$existing = get_post_meta( $eca_id, '_eca_attendance_log', true );
				if ( ! empty( $existing ) ) {
					delete_post_meta( $eca_id, '_eca_attendance_log' );
					++$attendance_reset;
				}
			}
		}

		return array(
			'success'            => true,
			'action'             => 'transition',
			'previous_term'      => null !== $current_index ? $terms[ $current_index ] : null,
			'active_term'        => $terms[ $target_index ],
			'enrollments_rolled' => $enrollments_rolled,
			'attendance_reset'   => $attendance_reset,
			'message'            => sprintf(
				/* translators: %s: term name */
				__( 'Successfully transitioned to term "%s".', 'mcp-ai-wpoos-pro' ),
				$terms[ $target_index ]['name']
			),
		);
	}

	/**
	 * Close a term by setting its status to completed.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   Current user ID.
	 * @return array|WP_Error Close result or error.
	 */
	private function action_close( $arguments, $user_id ) {
		$term_id = isset( $arguments['term_id'] ) ? sanitize_text_field( $arguments['term_id'] ) : '';

		if ( '' === $term_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_term_id',
				__( 'term_id is required for the close action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$terms = get_option( 'wp_mcp_ai_eca_terms', array() );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}

		$target_index = null;
		foreach ( $terms as $index => $term ) {
			if ( $term['id'] === $term_id ) {
				$target_index = $index;
				break;
			}
		}

		if ( null === $target_index ) {
			return new WP_Error(
				'wp_mcp_ai_term_not_found',
				__( 'Term not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$terms[ $target_index ]['status']       = 'completed';
		$terms[ $target_index ]['completed_at'] = current_time( 'mysql' );
		$terms[ $target_index ]['completed_by'] = $user_id;

		update_option( 'wp_mcp_ai_eca_terms', $terms );

		// Mark ECAs associated with this term as inactive.
		$ecas_deactivated = 0;
		$eca_query        = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'manage_eca_term', 0, 1000 ) : 1000,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_eca_term_id',
						'value'   => $term_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( $eca_query->have_posts() ) {
			foreach ( $eca_query->posts as $eca_id ) {
				update_post_meta( $eca_id, '_eca_status', 'inactive' );
				++$ecas_deactivated;
			}
		}

		return array(
			'success'          => true,
			'action'           => 'close',
			'term'             => $terms[ $target_index ],
			'ecas_deactivated' => $ecas_deactivated,
			'message'          => sprintf(
				/* translators: %s: term name */
				__( 'Term "%s" has been closed.', 'mcp-ai-wpoos-pro' ),
				$terms[ $target_index ]['name']
			),
		);
	}

	/**
	 * Find a term by its ID.
	 *
	 * @param string $term_id Term identifier.
	 * @return array|null Term data or null if not found.
	 */
	private function find_term( $term_id ) {
		$terms = get_option( 'wp_mcp_ai_eca_terms', array() );
		if ( ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( isset( $term['id'] ) && $term['id'] === $term_id ) {
				return $term;
			}
		}

		return null;
	}
}
