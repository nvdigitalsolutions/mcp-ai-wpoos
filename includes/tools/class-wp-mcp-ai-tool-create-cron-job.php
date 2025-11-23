<?php
/**
 * Tool for scheduling WordPress cron events.
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
 * Allows privileged users to create WP-Cron jobs.
 */
class WP_MCP_AI_Tool_Create_Cron_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_cron_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Cron Job', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules a WordPress cron event for a given hook, schedule, and arguments.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$schedules      = wp_get_schedules();
		$schedule_slugs = array_keys( $schedules );

		sort( $schedule_slugs );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'hook'      => array(
					'type'        => 'string',
					'description' => __( 'The action hook to schedule.', 'wp-mcp-ai' ),
					'minLength'   => 1,
				),
				'timestamp' => array(
					'type'        => 'integer',
					'description' => __( 'Unix timestamp for when the event should first run. Defaults to one minute from now.', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'schedule'  => array(
					'type'        => 'string',
					'description' => __( 'Recurrence schedule slug. Use "single" for a one-off event.', 'wp-mcp-ai' ),
					'enum'        => array_merge( array( 'single' ), $schedule_slugs ),
				),
				'args'      => array(
					'type'        => 'array',
					'description' => __( 'Optional positional arguments passed to the action when it runs.', 'wp-mcp-ai' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'number' ),
							array( 'type' => 'boolean' ),
							array( 'type' => 'null' ),
							array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
							array(
								'type'  => 'array',
								'items' => array(
									'anyOf' => array(
										array( 'type' => 'string' ),
										array( 'type' => 'number' ),
										array( 'type' => 'boolean' ),
										array( 'type' => 'null' ),
										array(
											'type' => 'object',
											'additionalProperties' => true,
										),
									),
								),
							),
						),
					),
					'default'     => array(),
				),
			),
			'required'             => array( 'hook' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create cron jobs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$hook = isset( $arguments['hook'] ) ? sanitize_text_field( (string) $arguments['hook'] ) : '';

		if ( '' === $hook ) {
			return new WP_Error( 'wp_mcp_ai_invalid_hook', __( 'A valid hook name is required to schedule a cron job.', 'wp-mcp-ai' ) );
		}

		$timestamp = isset( $arguments['timestamp'] ) ? (int) $arguments['timestamp'] : 0;

		if ( $timestamp <= 0 ) {
			$timestamp = time() + MINUTE_IN_SECONDS;
		}

		$current_time = time();
		if ( $timestamp < $current_time ) {
			return new WP_Error( 'wp_mcp_ai_past_timestamp', __( 'The requested start time is in the past. Please choose a future timestamp.', 'wp-mcp-ai' ) );
		}

		$schedule = isset( $arguments['schedule'] ) ? sanitize_key( $arguments['schedule'] ) : 'single';

		if ( empty( $schedule ) ) {
			$schedule = 'single';
		}

		$available_schedules = wp_get_schedules();
		if ( 'single' !== $schedule && ! isset( $available_schedules[ $schedule ] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_schedule', __( 'The provided schedule is not registered.', 'wp-mcp-ai' ) );
		}

		$args = array();
		if ( isset( $arguments['args'] ) ) {
			if ( ! is_array( $arguments['args'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_args', __( 'The args parameter must be an array.', 'wp-mcp-ai' ) );
			}

			$args = $arguments['args'];
		}

		$args = WP_MCP_AI_Cron_Manager::normalise_args( $args );

		$existing_timestamp = wp_next_scheduled( $hook, $args );
		if ( false !== $existing_timestamp ) {
			return new WP_Error(
				'wp_mcp_ai_event_exists',
				sprintf(
					/* translators: %s - next scheduled datetime */
					__( 'An event with the same hook and arguments is already scheduled for %s.', 'wp-mcp-ai' ),
					wp_date( DATE_ATOM, $existing_timestamp )
				)
			);
		}

		if ( 'single' === $schedule ) {
			$scheduled = wp_schedule_single_event( $timestamp, $hook, $args );
		} else {
			$scheduled = wp_schedule_event( $timestamp, $schedule, $hook, $args );
		}

		if ( false === $scheduled ) {
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule the cron event. Please try again.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Cron_Manager::record_job( $hook, $args, $schedule, $timestamp, $user_id );

		// Trigger WordPress cron immediately to ensure the event runs.
		// WordPress cron is virtual and only runs on page loads by default.
		spawn_cron();

		return array(
			'hook'          => $hook,
			'schedule'      => $schedule,
			'timestamp'     => $timestamp,
			'scheduled_for' => wp_date( DATE_ATOM, $timestamp ),
			'args'          => $args,
			'message'       => __( 'Cron event scheduled successfully.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Creates scheduled tasks.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'manage_options' capability.
			'state-changing',       // Modifies scheduled tasks.
			'async',                // Scheduled tasks run asynchronously.
			'deferred-result',      // Result available later, not immediately.
			'requires-polling',     // May need to poll for completion status.
			'may-timeout',          // Should not wait for cron execution in same request.
			'background-only',      // Cron execution happens in background.
		);
	}
}
