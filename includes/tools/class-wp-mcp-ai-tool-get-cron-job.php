<?php
/**
 * Tool for getting details of a specific WordPress cron job.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}

/**
 * Allows users to get details of a specific WordPress cron job.
 */
class WP_MCP_AI_Tool_Get_Cron_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_cron_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Cron Job', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific WordPress cron job by its job ID.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'job_id' => array(
					'type'        => 'string',
					'description' => __( 'The unique identifier of the cron job to retrieve.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'job_id' ),
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
		// Check if cron orchestration is enabled.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Budget_Enforcement_Service' ) ) {
			if ( ! WP_MCP_AI_Orchestration_Budget_Enforcement_Service::is_cron_orchestration_enabled() ) {
				return new WP_Error( 'wp_mcp_ai_cron_disabled', __( 'Cron-based task orchestration is currently disabled. Enable it in Settings → Orchestration Layer → Settings.', 'wp-mcp-ai' ) );
			}
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view cron jobs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$job_id = isset( $arguments['job_id'] ) ? sanitize_text_field( (string) $arguments['job_id'] ) : '';

		if ( '' === $job_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid_job_id', __( 'A valid job ID is required.', 'wp-mcp-ai' ) );
		}

		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'The specified cron job was not found.', 'wp-mcp-ai' ) );
		}

		$hook  = isset( $job['hook'] ) ? (string) $job['hook'] : '';
		$args  = isset( $job['args'] ) ? $job['args'] : array();
		$event = wp_get_scheduled_event( $hook, $args );

		$next_run   = $event ? $event->timestamp : null;
		$schedule   = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
		$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

		$creator = '';
		if ( $created_by > 0 ) {
			$user = get_userdata( $created_by );
			if ( $user ) {
				$creator = $user->display_name;
			}
		}

		if ( '' === $creator ) {
			$creator = __( 'System', 'wp-mcp-ai' );
		}

		$result = array(
			'job_id'   => $job_id,
			'hook'     => $hook,
			'schedule' => $schedule,
			'args'     => $args,
			'creator'  => $creator,
		);

		if ( $next_run ) {
			$result['next_run']           = $next_run;
			$result['next_run_formatted'] = wp_date( DATE_ATOM, $next_run );
			$result['status']             = 'scheduled';
		} else {
			$result['next_run']           = null;
			$result['next_run_formatted'] = __( 'Not scheduled', 'wp-mcp-ai' );
			$result['status']             = 'not_scheduled';
		}

		if ( isset( $job['created_at'] ) && $job['created_at'] ) {
			$result['created_at']           = (int) $job['created_at'];
			$result['created_at_formatted'] = wp_date( DATE_ATOM, (int) $job['created_at'] );
		}

		if ( isset( $job['first_timestamp'] ) && $job['first_timestamp'] ) {
			$result['first_timestamp']           = (int) $job['first_timestamp'];
			$result['first_timestamp_formatted'] = wp_date( DATE_ATOM, (int) $job['first_timestamp'] );
		}

		// Add schedule interval information for recurring jobs.
		if ( 'single' !== $schedule && '' !== $schedule ) {
			$schedules = wp_get_schedules();
			if ( isset( $schedules[ $schedule ] ) ) {
				$result['schedule_display']  = $schedules[ $schedule ]['display'];
				$result['schedule_interval'] = $schedules[ $schedule ]['interval'];
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
