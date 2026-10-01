<?php
/**
 * Tool for syncing a Google Classroom roster into student records.
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
 * Syncs a Google Classroom course roster into `mcp_ai_student` records.
 */
class WP_MCP_AI_Tool_Sync_Classroom_Roster_To_Students implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_classroom_roster_to_students';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync Classroom Roster to Students', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pulls the student roster of a Google Classroom course into WordPress student records, matching by Google user ID with an email fallback. Supports dry-run and MIS-wins conflict handling.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Importing or refreshing the student list for a linked Classroom course, or onboarding a club whose membership lives in Classroom.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Importing course definitions; use sync_classroom_courses_to_ecas. MIS-driven schools; use sync_students_from_isams instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'sync_classroom_courses_to_ecas', 'link_classroom_course_to_eca', 'list_students' ),
			'notes'           => __( 'Run with dry_run first. MIS records win on identity conflicts unless override_mis is set.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'course_id'     => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID. Defaults to the connection\'s default course.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'Preview matches without writing anything (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'override_mis'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true, Classroom name data may update a student that already has an iSAMS ID (default: false, MIS wins).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'connection_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_ECA_Classroom_Helper::feature_enabled();
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return WP_MCP_AI_ECA_Classroom_Helper::unavailable_reason();
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
			'post_type'             => 'mcp_ai_student',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'school_admin', 'it_admin' ),
			'risk_level'            => 'elevated',
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
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'manage_options', $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$resolved['credentials'],
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_ROSTERS_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$course_id = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id(
			$resolved['credentials'],
			isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : ''
		);

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$dry_run      = ! empty( $arguments['dry_run'] );
		$override_mis = ! empty( $arguments['override_mis'] );

		$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function ( $params ) use ( $resolved, $course_id ) {
				return $resolved['client']->list_students( $course_id, $params );
			},
			array( 'pageSize' => 100 )
		);

		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$students  = isset( $pages['items'] ) ? $pages['items'] : array();
		$created   = 0;
		$updated   = 0;
		$skipped   = 0;
		$conflicts = array();
		$rows      = array();

		foreach ( $students as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}

			$google_id = isset( $member['userId'] ) ? sanitize_text_field( $member['userId'] ) : '';
			$profile   = isset( $member['profile'] ) && is_array( $member['profile'] ) ? $member['profile'] : array();
			$full_name = isset( $profile['name']['fullName'] ) ? sanitize_text_field( $profile['name']['fullName'] ) : '';
			$email     = isset( $profile['emailAddress'] ) ? sanitize_email( $profile['emailAddress'] ) : '';

			if ( '' === $google_id && '' === $email ) {
				++$skipped;
				$rows[] = array(
					'status' => 'skipped',
					'reason' => 'no_identity',
					'name'   => $full_name,
				);
				continue;
			}

			$post_id = '' !== $google_id
				? WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( $google_id )
				: 0;

			if ( 0 === $post_id && '' !== $email ) {
				$post_id = WP_MCP_AI_ECA_Classroom_Helper::find_student_by_email( $email );
			}

			if ( 0 === $post_id ) {
				if ( $dry_run ) {
					$rows[] = array(
						'status'    => 'would_create',
						'google_id' => $google_id,
						'email'     => $email,
						'name'      => $full_name,
					);
					continue;
				}

				$name_parts = WP_MCP_AI_ECA_Classroom_Helper::split_full_name( $full_name );

				$post_id = wp_insert_post(
					array(
						'post_type'   => 'mcp_ai_student',
						'post_status' => 'publish',
						'post_title'  => '' !== $full_name ? $full_name : ( '' !== $email ? $email : $google_id ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					$conflicts[] = array(
						'name'  => $full_name,
						'error' => $post_id->get_error_message(),
					);
					continue;
				}

				update_post_meta( $post_id, '_student_first_name', $name_parts['first'] );
				update_post_meta( $post_id, '_student_last_name', $name_parts['last'] );
				update_post_meta( $post_id, '_student_email', $email );
				update_post_meta( $post_id, '_student_google_id', $google_id );
				update_post_meta( $post_id, '_student_eca_enrollments', array() );

				++$created;
				$rows[] = array(
					'status'     => 'created',
					'student_id' => absint( $post_id ),
					'name'       => $full_name,
				);
				continue;
			}

			// Existing record: record the Google identity even when MIS wins.
			if ( $dry_run ) {
				$rows[] = array(
					'status'     => 'would_link',
					'student_id' => absint( $post_id ),
					'name'       => $full_name,
				);
				continue;
			}

			if ( '' !== $google_id ) {
				update_post_meta( $post_id, '_student_google_id', $google_id );
			}

			$has_mis = '' !== (string) get_post_meta( $post_id, '_student_isams_id', true );

			if ( $has_mis && ! $override_mis ) {
				++$skipped;
				$rows[] = array(
					'status'     => 'mis_wins',
					'student_id' => absint( $post_id ),
					'name'       => $full_name,
				);
				continue;
			}

			$changed = false;

			if ( '' !== $full_name && get_post_meta( $post_id, '_student_first_name', true ) === '' ) {
				$name_parts = WP_MCP_AI_ECA_Classroom_Helper::split_full_name( $full_name );
				update_post_meta( $post_id, '_student_first_name', $name_parts['first'] );
				update_post_meta( $post_id, '_student_last_name', $name_parts['last'] );
				$changed = true;
			}

			if ( '' !== $email && get_post_meta( $post_id, '_student_email', true ) === '' ) {
				update_post_meta( $post_id, '_student_email', $email );
				$changed = true;
			}

			if ( $changed ) {
				++$updated;
			}

			$rows[] = array(
				'status'     => $changed ? 'updated' : 'unchanged',
				'student_id' => absint( $post_id ),
				'name'       => $full_name,
			);
		}

		$state = array(
			'course_id'      => $course_id,
			'last_sync'      => time(),
			'students_count' => count( $rows ),
		);
		WP_MCP_AI_ECA_Classroom_Helper::set_sync_state( $connection_id, $state );

		return array(
			'success'   => true,
			'course_id' => $course_id,
			'dry_run'   => $dry_run,
			'created'   => $created,
			'updated'   => $updated,
			'skipped'   => $skipped,
			'conflicts' => $conflicts,
			'students'  => $rows,
			'message'   => sprintf(
				/* translators: 1: created count, 2: updated count, 3: skipped count */
				__( 'Roster sync complete: %1$d created, %2$d updated, %3$d skipped.', 'mcp-ai-wpoos-pro' ),
				$created,
				$updated,
				$skipped
			),
		);
	}
}
