<?php
/**
 * Tool for polling a background deep_research job's status.
 *
 * Companion to the deep_research tool: background runs (run_mode=background)
 * execute via WordPress cron, and their progress is stored in a job
 * transient. This tool exposes that state so assistants can poll for
 * completion without re-running the research.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Status Tool
 *
 * Reads the job transient written by WP_MCP_AI_Tool_Deep_Research for a
 * background job ID and reports its lifecycle state (scheduled, running,
 * completed, failed) plus timing and size metadata.
 */
class WP_MCP_AI_Tool_Research_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Status', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Polls the status of a background deep_research job by job ID. Reports whether the job is scheduled, running, completed, or failed, with timing and progress metadata. Use with run_mode=background deep_research; fetch the finished report with research_result.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Polling a background deep_research job to see whether it finished (and how complete it is).', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Reading the finished report — use research_result; cancelling a pending job — use research_cancel.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'deep_research', 'research_result', 'research_cancel' ),
			'notes'           => __( 'Job records live for 24 hours after scheduling.', 'mcp-ai-wpoos' ),
		);
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
					'description' => __( 'The job ID returned by deep_research when started with run_mode=background.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'job_id' ),
			'additionalProperties' => false,
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
			'toolkit'               => 'research_discovery',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'researcher', 'analyst', 'journalist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads job state.
			'requires-capability', // Checks the user's capability before running.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check research job status.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}

		// Validate required arguments.
		if ( empty( $arguments['job_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_job_id',
				__( 'A research job ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$job_id = sanitize_text_field( $arguments['job_id'] );
		$job    = get_transient( 'wp_mcp_ai_research_job_' . $job_id );

		if ( ! is_array( $job ) ) {
			return new WP_Error(
				'wp_mcp_ai_research_job_not_found',
				sprintf(
					/* translators: %s: job ID */
					__( 'No background research job found for ID %s. Jobs expire 24 hours after scheduling.', 'mcp-ai-wpoos' ),
					$job_id
				),
				array( 'status' => 404 )
			);
		}

		$response = array(
			'success'      => true,
			'job_id'       => $job_id,
			'topic'        => isset( $job['topic'] ) ? $job['topic'] : '',
			'depth'        => isset( $job['depth'] ) ? $job['depth'] : '',
			'status'       => isset( $job['status'] ) ? $job['status'] : 'unknown',
			'progress'     => isset( $job['progress'] ) ? $job['progress'] : ( isset( $job['status'] ) ? $job['status'] : 'unknown' ),
			'created_at'   => isset( $job['created_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $job['created_at'] ) ) : null,
			'started_at'   => isset( $job['started_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $job['started_at'] ) ) : null,
			'completed_at' => isset( $job['completed_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $job['completed_at'] ) ) : null,
		);

		if ( 'failed' === $response['status'] && ! empty( $job['error'] ) ) {
			$response['error'] = $job['error'];
		}

		if ( 'completed' === $response['status'] ) {
			$response['partial'] = ! empty( $job['partial'] );
			if ( ! empty( $job['warnings'] ) ) {
				$response['warnings'] = $job['warnings'];
			}
			if ( isset( $job['result']['word_count'] ) ) {
				$response['result_word_count'] = absint( $job['result']['word_count'] );
			}
			if ( isset( $job['result']['status'] ) ) {
				$response['result_status'] = $job['result']['status'];
			}
		}

		$response['message'] = $this->build_message( $response );

		return $response;
	}

	/**
	 * Build a human-readable status message for the chat client.
	 *
	 * @param array $response Response array.
	 * @return string Message.
	 */
	protected function build_message( array $response ) {
		switch ( $response['status'] ) {
			case 'scheduled':
				return sprintf(
					/* translators: %s: topic */
					__( 'Research on "%s" is scheduled and waiting to run.', 'mcp-ai-wpoos' ),
					$response['topic']
				);
			case 'running':
				return sprintf(
					/* translators: %s: topic */
					__( 'Research on "%s" is currently running.', 'mcp-ai-wpoos' ),
					$response['topic']
				);
			case 'completed':
				$status_note = $response['partial']
					? __( ' The report is marked partial — check its warnings.', 'mcp-ai-wpoos' )
					: '';
				return sprintf(
					/* translators: 1: topic, 2: word count, 3: partial note */
					__( 'Research on "%1$s" completed (%2$d words).%3$s', 'mcp-ai-wpoos' ),
					$response['topic'],
					$response['result_word_count'] ?? 0,
					$status_note
				);
			case 'failed':
				return sprintf(
					/* translators: 1: topic, 2: error */
					__( 'Research on "%1$s" failed: %2$s', 'mcp-ai-wpoos' ),
					$response['topic'],
					$response['error'] ?? __( 'unknown error', 'mcp-ai-wpoos' )
				);
			default:
				return sprintf(
					/* translators: 1: job ID, 2: status */
					__( 'Research job %1$s has status "%2$s".', 'mcp-ai-wpoos' ),
					$response['job_id'],
					$response['status']
				);
		}
	}
}
