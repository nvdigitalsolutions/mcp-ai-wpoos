<?php
/**
 * Tool for managing Google Classroom push-notification registrations.
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
 * Registers, renews, or removes Classroom push registrations and reports
 * watcher health.
 */
class WP_MCP_AI_Tool_Manage_Classroom_Push_Watch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_classroom_push_watch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Classroom Push Watch', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Registers, renews, or removes Google Classroom push-notification registrations for a course and reports the watcher state. Registrations deliver roster and coursework changes to the site webhook within minutes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Enabling near-real-time roster updates for a linked course, or diagnosing why notifications are not arriving.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'One-off imports; use sync_classroom_roster_to_students. The periodic sync remains the fallback for sites without HTTPS or a public domain.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'sync_classroom_roster_to_students', 'sync_classroom_courses_to_ecas', 'link_classroom_course_to_eca' ),
			'notes'           => __( 'Requires the push profile, a configured Pub/Sub topic, and a per-user OAuth grant (domain-wide delegation is not supported for registrations). Registrations auto-renew daily.', 'mcp-ai-wpoos-pro' ),
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
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Watcher action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'register', 'unregister', 'renew', 'status' ),
					'default'     => 'status',
				),
				'course_id'     => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID. Required for register/unregister; defaults to the connection\'s default course.', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'external-api', 'external-write' );
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

		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Classroom push notifications.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $connection_id ) {
			return new WP_Error( 'wp_mcp_ai_connection_required', __( 'A Google Classroom connection ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_ECA_Classroom_Helper::require_foundation();
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php';

		$action    = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'status';
		$course_id = isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : '';

		if ( ! in_array( $action, array( 'register', 'unregister', 'renew', 'status' ), true ) ) {
			$action = 'status';
		}

		if ( 'status' === $action ) {
			$eligibility = WP_MCP_AI_Google_Classroom_Push::is_push_eligible();
			$records     = array();

			foreach ( WP_MCP_AI_Google_Classroom_Push::get_registrations() as $record ) {
				if ( isset( $record['connection_id'] ) && (string) $record['connection_id'] === $connection_id ) {
					$records[] = $record;
				}
			}

			return array(
				'success'       => true,
				'eligible'      => ! is_wp_error( $eligibility ),
				'eligibility'   => is_wp_error( $eligibility ) ? $eligibility->get_error_message() : '',
				'registrations' => $records,
				'endpoint_url'  => WP_MCP_AI_Google_Classroom_Push::get_push_endpoint_url(),
				'topic'         => WP_MCP_AI_Google_Classroom_Push::get_topic_name(),
				'message'       => is_wp_error( $eligibility )
					? $eligibility->get_error_message()
					: __( 'Push notification status returned.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( '' === $course_id ) {
			$credentials = WP_MCP_AI_Google_Classroom_Credentials::resolve( $connection_id );

			if ( is_wp_error( $credentials ) ) {
				return $credentials;
			}

			$course_id = $credentials['default_course_id'];

			if ( '' === $course_id ) {
				return new WP_Error( 'wp_mcp_ai_course_required', __( 'A Google Classroom course ID is required for this action.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$feeds = array( 'COURSE_ROSTER_CHANGES', 'COURSE_WORK_CHANGES' );
		$done  = array();

		foreach ( $feeds as $feed ) {
			if ( 'unregister' === $action ) {
				$result = WP_MCP_AI_Google_Classroom_Push::unregister_for_course( $connection_id, $course_id, $feed );
			} else {
				$result = WP_MCP_AI_Google_Classroom_Push::register_for_course( $connection_id, $course_id, $feed );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$done[] = $feed;
		}

		return array(
			'success'   => true,
			'action'    => $action,
			'course_id' => $course_id,
			'feeds'     => $done,
			'message'   => 'unregister' === $action
				? __( 'Push registrations removed for the course.', 'mcp-ai-wpoos-pro' )
				: __( 'Push registrations created for the course. They renew automatically every day.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
