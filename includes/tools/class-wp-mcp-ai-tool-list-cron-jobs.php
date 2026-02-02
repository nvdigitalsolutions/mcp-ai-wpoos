<?php
/**
 * Tool for listing WordPress cron jobs.
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
 * Allows users to list all scheduled WordPress cron jobs.
 */
class WP_MCP_AI_Tool_List_Cron_Jobs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_cron_jobs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Cron Jobs', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all WordPress cron jobs that have been scheduled through the plugin.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => (object) array(),
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
				return new WP_Error( 'wp_mcp_ai_cron_disabled', __( 'Cron-based task orchestration is currently disabled. Enable it in Settings → Orchestration Layer → Settings.', 'mcp-ai-wpoos' ) );
			}
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list cron jobs.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Prune stale jobs before listing.
		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		if ( empty( $jobs ) ) {
			return array(
				'jobs'    => array(),
				'count'   => 0,
				'message' => __( 'No cron jobs are currently scheduled.', 'mcp-ai-wpoos' ),
			);
		}

		$formatted_jobs = array();

		foreach ( $jobs as $job ) {
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
				$creator = __( 'System', 'mcp-ai-wpoos' );
			}

			$formatted_job = array(
				'job_id'   => isset( $job['job_id'] ) ? $job['job_id'] : '',
				'hook'     => $hook,
				'schedule' => $schedule,
				'args'     => $args,
				'creator'  => $creator,
			);

			if ( $next_run ) {
				$formatted_job['next_run']           = $next_run;
				$formatted_job['next_run_formatted'] = wp_date( DATE_ATOM, $next_run );
			} else {
				$formatted_job['next_run']           = null;
				$formatted_job['next_run_formatted'] = __( 'Not scheduled', 'mcp-ai-wpoos' );
			}

			if ( isset( $job['created_at'] ) && $job['created_at'] ) {
				$formatted_job['created_at']           = (int) $job['created_at'];
				$formatted_job['created_at_formatted'] = wp_date( DATE_ATOM, (int) $job['created_at'] );
			}

			if ( isset( $job['first_timestamp'] ) && $job['first_timestamp'] ) {
				$formatted_job['first_timestamp'] = (int) $job['first_timestamp'];
			}

			$formatted_jobs[] = $formatted_job;
		}

		return array(
			'jobs'    => $formatted_jobs,
			'count'   => count( $formatted_jobs ),
			'message' => sprintf(
				/* translators: %d: number of cron jobs */
				_n( 'Found %d cron job.', 'Found %d cron jobs.', count( $formatted_jobs ), 'mcp-ai-wpoos' ),
				count( $formatted_jobs )
			),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'workflow_automation',

			'pattern_compatibility' => array( 'hierarchical' ),

			'profession_tags'       => array( 'systems_administrator' ),

			'risk_level'            => 'info',

		);
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
