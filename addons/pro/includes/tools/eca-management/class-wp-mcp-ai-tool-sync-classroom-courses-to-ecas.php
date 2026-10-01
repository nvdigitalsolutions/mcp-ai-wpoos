<?php
/**
 * Tool for syncing Google Classroom courses into ECA records.
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
 * Maps Google Classroom courses to `mcp_ai_eca` records.
 */
class WP_MCP_AI_Tool_Sync_Classroom_Courses_To_ECAs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_classroom_courses_to_ecas';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync Classroom Courses to ECAs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Maps Google Classroom courses to ECA records: links existing ECAs by stored course ID and optionally creates an ECA for unlinked courses. Supports dry-run preview.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Bulk-linking Classroom courses to the ECA toolkit, or importing newly provisioned courses as ECAs.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Linking a single pair explicitly; use link_classroom_course_to_eca. Importing rosters; use sync_classroom_roster_to_students.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'link_classroom_course_to_eca', 'sync_classroom_roster_to_students', 'list_ecas' ),
			'notes'           => __( 'Run with dry_run first; ECA-specific fields (venue, cost, capacity) are never overwritten from Classroom.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'   => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'description' => __( 'Preview the mapping without writing anything (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'create_missing'  => array(
					'type'        => 'boolean',
					'description' => __( 'Create an ECA record for unlinked courses (default: true).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Update the title of linked ECAs when blank (default: true).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'page_size'       => array(
					'type'        => 'integer',
					'description' => __( 'Courses per page (default: 50, max: 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
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
			'post_type'             => 'mcp_ai_eca',
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
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_COURSES_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$dry_run         = ! empty( $arguments['dry_run'] );
		$create_missing  = isset( $arguments['create_missing'] ) ? (bool) $arguments['create_missing'] : true;
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : true;
		$page_size       = isset( $arguments['page_size'] ) ? min( 100, max( 1, absint( $arguments['page_size'] ) ) ) : 50;

		$pages = WP_MCP_AI_Google_Classroom_Client::paginate(
			static function ( $params ) use ( $resolved ) {
				return $resolved['client']->list_courses( $params );
			},
			array(
				'pageSize'     => $page_size,
				'courseStates' => array( 'ACTIVE' ),
			)
		);

		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$courses = isset( $pages['items'] ) ? $pages['items'] : array();
		$linked  = 0;
		$created = 0;
		$rows    = array();

		foreach ( $courses as $course ) {
			if ( ! is_array( $course ) ) {
				continue;
			}

			$course_id = isset( $course['id'] ) ? sanitize_text_field( $course['id'] ) : '';
			$name      = isset( $course['name'] ) ? sanitize_text_field( $course['name'] ) : '';
			$section   = isset( $course['section'] ) ? sanitize_text_field( $course['section'] ) : '';

			if ( '' === $course_id ) {
				continue;
			}

			$post_id = WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $course_id );

			if ( 0 !== $post_id ) {
				if ( ! $dry_run ) {
					if ( $update_existing && '' === trim( (string) get_the_title( $post_id ) ) ) {
						wp_update_post(
							array(
								'ID'         => $post_id,
								'post_title' => '' !== $name ? $name : 'ECA ' . $course_id,
							)
						);
					}

					update_post_meta( $post_id, '_eca_classroom_sync', 'roster-in' );
				}

				++$linked;
				$rows[] = array(
					'status'    => 'linked',
					'eca_id'    => absint( $post_id ),
					'course_id' => $course_id,
					'name'      => $name,
				);
				continue;
			}

			if ( $dry_run ) {
				$rows[] = array(
					'status'    => 'would_create',
					'course_id' => $course_id,
					'name'      => $name,
				);
				continue;
			}

			if ( ! $create_missing ) {
				$rows[] = array(
					'status'    => 'unlinked',
					'course_id' => $course_id,
					'name'      => $name,
				);
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_eca',
					'post_status' => 'publish',
					'post_title'  => '' !== $name ? $name : 'ECA ' . $course_id,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$rows[] = array(
					'status'    => 'error',
					'course_id' => $course_id,
					'error'     => $post_id->get_error_message(),
				);
				continue;
			}

			update_post_meta( $post_id, '_eca_google_course_id', $course_id );
			update_post_meta( $post_id, '_eca_classroom_sync', 'roster-in' );

			if ( '' !== $section ) {
				update_post_meta( $post_id, '_eca_section', $section );
			}

			++$created;
			$rows[] = array(
				'status'    => 'created',
				'eca_id'    => absint( $post_id ),
				'course_id' => $course_id,
				'name'      => $name,
			);
		}

		$state = array(
			'last_sync'    => time(),
			'courses_seen' => count( $rows ),
		);
		WP_MCP_AI_ECA_Classroom_Helper::set_sync_state( $connection_id, $state );

		return array(
			'success' => true,
			'dry_run' => $dry_run,
			'linked'  => $linked,
			'created' => $created,
			'courses' => $rows,
			'message' => sprintf(
				/* translators: 1: linked count, 2: created count */
				__( 'Course sync complete: %1$d linked, %2$d created.', 'mcp-ai-wpoos-pro' ),
				$linked,
				$created
			),
		);
	}
}
