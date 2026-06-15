<?php
/**
 * Tool for generating project status reports.
 *
 * Compiles a comprehensive status report for a given project including
 * summary, recent completions, upcoming tasks, blockers, risk assessment,
 * and burndown snapshot when sprint data is available.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a project status report.
 */
class WP_MCP_AI_Tool_Generate_Status_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_status_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Status Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a comprehensive project status report. Compiles project summary, recently completed tasks, upcoming tasks, blockers, risk assessment, and burndown snapshot if sprint data is available. Outputs in markdown or HTML format.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the project to generate a report for (required).', 'mcp-ai-wpoos-pro' ),
				),
				'format'     => array(
					'type'        => 'string',
					'description' => __( 'Output format for the report.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'markdown', 'html' ),
					'default'     => 'markdown',
				),
			),
			'required'             => array( 'project_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'toolkit'               => 'project_management',
			'post_type'             => 'mcp_ai_project',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'scrum_master', 'executive' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
		);
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
		return ! empty( $settings['enable_project_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate status reports.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$format     = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'markdown';

		if ( ! in_array( $format, array( 'markdown', 'html' ), true ) ) {
			$format = 'markdown';
		}

		if ( ! $project_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_project_id', __( 'Project ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$project = get_post( $project_id );

		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gather project metadata.
		$project_status   = get_post_meta( $project_id, '_project_status', true );
		$project_status   = $project_status ? $project_status : 'planning';
		$project_start    = get_post_meta( $project_id, '_project_start_date', true );
		$project_start    = $project_start ? $project_start : '';
		$project_end      = get_post_meta( $project_id, '_project_end_date', true );
		$project_end      = $project_end ? $project_end : '';
		$project_assigned = get_post_meta( $project_id, '_project_assigned_to', true );
		$project_assigned = is_array( $project_assigned ) ? $project_assigned : array();

		// Assigned user names.
		$assignee_names = array();
		foreach ( $project_assigned as $uid ) {
			$user = get_user_by( 'id', (int) $uid );
			if ( $user ) {
				$assignee_names[] = $user->display_name;
			}
		}

		// Task counts by status using PM Engine if available.
		$task_counts = array();

		if ( class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			$task_counts = array(
				'total'     => WP_MCP_AI_PM_Engine::count_tasks( $project_id ),
				'completed' => WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'completed' ),
				'in_progress' => WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'in-progress' ),
				'pending'   => WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'pending' ),
				'blocked'   => WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'blocked' ),
			);
		} else {
			$task_counts = $this->count_tasks_manual( $project_id );
		}

		// Completed recently (last 14 days).
		$recently_completed = $this->get_recently_completed( $project_id, 14 );

		// Upcoming tasks (due within 7 days).
		$upcoming_tasks = $this->get_upcoming_tasks( $project_id, 7 );

		// Blocked tasks.
		$blockers = $this->get_blocked_tasks( $project_id );

		// Risk assessment.
		$risk = $this->assess_risk( $project_id, $project_status, $project_end, $task_counts );

		// Burndown snapshot (if sprint data available via PM Engine).
		$burndown = $this->get_burndown_snapshot( $project_id );

		// Build the report.
		if ( 'html' === $format ) {
			$report = $this->build_html_report(
				$project,
				$project_status,
				$project_start,
				$project_end,
				$assignee_names,
				$task_counts,
				$recently_completed,
				$upcoming_tasks,
				$blockers,
				$risk,
				$burndown
			);
		} else {
			$report = $this->build_markdown_report(
				$project,
				$project_status,
				$project_start,
				$project_end,
				$assignee_names,
				$task_counts,
				$recently_completed,
				$upcoming_tasks,
				$blockers,
				$risk,
				$burndown
			);
		}

		return array(
			'success'     => true,
			'project_id'  => $project_id,
			'format'      => $format,
			'report'      => $report,
			'generated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Count tasks manually (fallback when PM Engine not available).
	 *
	 * @param int $project_id Project ID.
	 * @return array
	 */
	private function count_tasks_manual( $project_id ) {
		$statuses = array( 'completed', 'in-progress', 'pending', 'blocked' );
		$counts   = array( 'total' => 0 );

		foreach ( $statuses as $status ) {
			$counts[ $status ] = 0;
		}

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_task_project_id',
						'value' => $project_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$counts['total'] = count( $tasks );

		foreach ( $tasks as $task_id ) {
			$status = get_post_meta( $task_id, '_task_status', true );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		return $counts;
	}

	/**
	 * Get recently completed tasks.
	 *
	 * @param int $project_id Project ID.
	 * @param int $days       Lookback window in days.
	 * @return array
	 */
	private function get_recently_completed( $project_id, $days = 14 ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_task_project_id',
						'value' => $project_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_task_status',
						'value' => 'completed',
					),
				),
			)
		);

		$completed = array();

		foreach ( $tasks as $task ) {
			if ( strtotime( $task->post_modified ) >= strtotime( $cutoff ) ) {
				$completed[] = array(
					'id'           => $task->ID,
					'title'        => $task->post_title,
					'completed_at' => $task->post_modified,
				);
			}
		}

		return $completed;
	}

	/**
	 * Get upcoming tasks due within N days.
	 *
	 * @param int $project_id Project ID.
	 * @param int $days       Lookahead window in days.
	 * @return array
	 */
	private function get_upcoming_tasks( $project_id, $days = 7 ) {
		$cutoff = gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) );
		$today  = gmdate( 'Y-m-d' );

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'meta_value',
				'meta_key'       => '_task_due_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_task_project_id',
						'value' => $project_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => '_task_status',
						'value'   => array( 'pending', 'in-progress' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$upcoming = array();

		foreach ( $tasks as $task ) {
			$due_date = get_post_meta( $task->ID, '_task_due_date', true );

			if ( $due_date && $due_date >= $today && $due_date <= $cutoff ) {
				$assignee_id    = get_post_meta( $task->ID, '_task_assigned_to', true );
				$assignee_name  = '';
				if ( $assignee_id ) {
					$user = get_user_by( 'id', (int) $assignee_id );
					if ( $user ) {
						$assignee_name = $user->display_name;
					}
				}

				$priority_raw = get_post_meta( $task->ID, '_task_priority', true );

				$upcoming[] = array(
					'id'        => $task->ID,
					'title'     => $task->post_title,
					'due_date'  => $due_date,
					'status'    => get_post_meta( $task->ID, '_task_status', true ),
					'priority'  => $priority_raw ? $priority_raw : 'medium',
					'assignee'  => $assignee_name,
				);
			}
		}

		return $upcoming;
	}

	/**
	 * Get blocked tasks for a project.
	 *
	 * @param int $project_id Project ID.
	 * @return array
	 */
	private function get_blocked_tasks( $project_id ) {
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_task_project_id',
						'value' => $project_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_task_status',
						'value' => 'blocked',
					),
				),
			)
		);

		$blockers = array();

		foreach ( $tasks as $task ) {
			$block_reason = get_post_meta( $task->ID, '_task_block_reason', true );
			$assignee_id  = get_post_meta( $task->ID, '_task_assigned_to', true );
			$assignee     = '';

			if ( $assignee_id ) {
				$user = get_user_by( 'id', (int) $assignee_id );
				if ( $user ) {
					$assignee = $user->display_name;
				}
			}

			$blockers[] = array(
				'id'      => $task->ID,
				'title'   => $task->post_title,
				'reason'  => $block_reason ? $block_reason : __( 'No reason provided', 'mcp-ai-wpoos-pro' ),
				'assignee' => $assignee,
			);
		}

		return $blockers;
	}

	/**
	 * Assess project risk.
	 *
	 * @param int    $project_id     Project ID.
	 * @param string $project_status Project status.
	 * @param string $project_end    Project end date.
	 * @param array  $task_counts    Task counts by status.
	 * @return array
	 */
	private function assess_risk( $project_id, $project_status, $project_end, $task_counts ) {
		$risk_level   = 'low';
		$risk_factors = array();

		// Skip risk assessment for completed or cancelled projects.
		if ( in_array( $project_status, array( 'completed', 'cancelled', 'archived' ), true ) ) {
			return array(
				'level'   => 'none',
				'score'   => 0,
				'factors' => array(),
				'summary' => __( 'Project is not active — no risk assessment needed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$score = 0;

		// Factor 1: Blockers.
		if ( $task_counts['blocked'] > 0 ) {
			$score += 30;
			$risk_factors[] = sprintf(
				/* translators: %d: number of blocked tasks */
				__( '%d blocked task(s) are halting progress.', 'mcp-ai-wpoos-pro' ),
				$task_counts['blocked']
			);
		}

		// Factor 2: Completion rate.
		$total = (int) $task_counts['total'];
		if ( $total > 0 ) {
			$completion_pct = ( (int) $task_counts['completed'] / $total ) * 100;
			if ( $completion_pct < 20 ) {
				$score += 15;
				$risk_factors[] = __( 'Low task completion rate (below 20%).', 'mcp-ai-wpoos-pro' );
			}
		}

		// Factor 3: Overdue end date.
		if ( $project_end && 'planning' !== $project_status && 'on-hold' !== $project_status ) {
			$end_timestamp = strtotime( $project_end );
			if ( $end_timestamp && $end_timestamp < time() ) {
				$score        += 25;
				$days_overdue = ceil( ( time() - $end_timestamp ) / DAY_IN_SECONDS );
				$risk_factors[] = sprintf(
					/* translators: %d: number of days overdue */
					__( 'Project end date is %d day(s) in the past.', 'mcp-ai-wpoos-pro' ),
					$days_overdue
				);
			}
		}

		// Factor 4: Project status.
		if ( 'at-risk' === $project_status ) {
			$score += 20;
			$risk_factors[] = __( 'Project is flagged as at-risk.', 'mcp-ai-wpoos-pro' );
		}

		// Factor 5: Stale tasks.
		if ( class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			$stale = WP_MCP_AI_PM_Engine::detect_stale_tasks( 14 );
			$project_stale = array_filter(
				$stale,
				function ( $s ) use ( $project_id ) {
					return isset( $s['project_id'] ) && (int) $s['project_id'] === $project_id;
				}
			);
			if ( count( $project_stale ) > 0 ) {
				$score += 10;
				$risk_factors[] = sprintf(
					/* translators: %d: number of stale tasks */
					__( '%d stale task(s) with no recent activity.', 'mcp-ai-wpoos-pro' ),
					count( $project_stale )
				);
			}
		}

		// Determine risk level.
		if ( $score >= 50 ) {
			$risk_level = 'high';
		} elseif ( $score >= 25 ) {
			$risk_level = 'medium';
		}

		return array(
			'level'   => $risk_level,
			'score'   => $score,
			'factors' => $risk_factors,
			'summary' => $this->get_risk_summary( $risk_level, $risk_factors ),
		);
	}

	/**
	 * Generate a human-readable risk summary.
	 *
	 * @param string $risk_level  Risk level.
	 * @param array  $risk_factors Risk factors.
	 * @return string
	 */
	private function get_risk_summary( $risk_level, $risk_factors ) {
		if ( empty( $risk_factors ) ) {
			return __( 'No significant risk factors identified.', 'mcp-ai-wpoos-pro' );
		}

		switch ( $risk_level ) {
			case 'high':
				$prefix = __( 'High risk: ', 'mcp-ai-wpoos-pro' );
				break;
			case 'medium':
				$prefix = __( 'Moderate risk: ', 'mcp-ai-wpoos-pro' );
				break;
			default:
				$prefix = __( 'Low risk: ', 'mcp-ai-wpoos-pro' );
				break;
		}

		return $prefix . implode( ' ', $risk_factors );
	}

	/**
	 * Get burndown snapshot for the project.
	 *
	 * @param int $project_id Project ID.
	 * @return array|null
	 */
	private function get_burndown_snapshot( $project_id ) {
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return null;
		}

		$total_tasks   = WP_MCP_AI_PM_Engine::count_tasks( $project_id );
		$done_tasks    = WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'completed' );
		$blocked_tasks = WP_MCP_AI_PM_Engine::count_tasks( $project_id, 'blocked' );

		if ( $total_tasks <= 0 ) {
			return array(
				'total'     => 0,
				'done'      => 0,
				'remaining' => 0,
				'blocked'   => 0,
				'pct_done'  => 0,
			);
		}

		return array(
			'total'     => $total_tasks,
			'done'      => $done_tasks,
			'remaining' => $total_tasks - $done_tasks,
			'blocked'   => $blocked_tasks,
			'pct_done'  => round( ( $done_tasks / $total_tasks ) * 100, 1 ),
		);
	}

	/**
	 * Build markdown report.
	 *
	 * @param WP_Post $project          Project post.
	 * @param string  $project_status   Project status.
	 * @param string  $project_start    Start date.
	 * @param string  $project_end      End date.
	 * @param array   $assignee_names   Assignee display names.
	 * @param array   $task_counts      Task count breakdown.
	 * @param array   $recently_completed Recently completed tasks.
	 * @param array   $upcoming_tasks   Upcoming tasks.
	 * @param array   $blockers         Blocked tasks.
	 * @param array   $risk             Risk assessment.
	 * @param array   $burndown         Burndown snapshot.
	 * @return string
	 */
	private function build_markdown_report(
		$project,
		$project_status,
		$project_start,
		$project_end,
		$assignee_names,
		$task_counts,
		$recently_completed,
		$upcoming_tasks,
		$blockers,
		$risk,
		$burndown
	) {
		$report  = '# ' . esc_html__( 'Project Status Report', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$report .= '**' . esc_html__( 'Generated', 'mcp-ai-wpoos-pro' ) . ':** ' . esc_html( current_time( 'Y-m-d H:i:s' ) ) . "\n\n";

		// Project summary.
		$report .= '## ' . esc_html__( 'Project Summary', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$report .= '| ' . esc_html__( 'Field', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html__( 'Value', 'mcp-ai-wpoos-pro' ) . " |\n";
		$report .= '|------|-------|' . "\n";
		$report .= '| ' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( $project->post_title ) . " |\n";
		$report .= '| ' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( ucfirst( $project_status ) ) . " |\n";

		if ( $project_start ) {
			$report .= '| ' . esc_html__( 'Start Date', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( $project_start ) . " |\n";
		}
		if ( $project_end ) {
			$report .= '| ' . esc_html__( 'End Date', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( $project_end ) . " |\n";
		}
		if ( ! empty( $assignee_names ) ) {
			$report .= '| ' . esc_html__( 'Assigned To', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( implode( ', ', $assignee_names ) ) . " |\n";
		}
		if ( ! empty( $project->post_content ) ) {
			$report .= "\n" . esc_html__( '**Description:**', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( wp_strip_all_tags( $project->post_content ) ) . "\n";
		}

		// Task counts.
		$report .= "\n## " . esc_html__( 'Task Overview', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$report .= '| ' . esc_html__( 'Metric', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html__( 'Count', 'mcp-ai-wpoos-pro' ) . " |\n";
		$report .= '|--------|-------|' . "\n";
		$report .= '| ' . esc_html__( 'Total Tasks', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( (string) $task_counts['total'] ) . " |\n";
		$report .= '| ' . esc_html__( 'Completed', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( (string) $task_counts['completed'] ) . " |\n";
		$report .= '| ' . esc_html__( 'In Progress', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( (string) $task_counts['in_progress'] ) . " |\n";
		$report .= '| ' . esc_html__( 'Pending', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( (string) $task_counts['pending'] ) . " |\n";
		$report .= '| ' . esc_html__( 'Blocked', 'mcp-ai-wpoos-pro' ) . ' | ' . esc_html( (string) $task_counts['blocked'] ) . " |\n";

		// Burndown.
		if ( $burndown && $burndown['total'] > 0 ) {
			$report .= "\n## " . esc_html__( 'Burndown Snapshot', 'mcp-ai-wpoos-pro' ) . "\n\n";
			$report .= esc_html(
				sprintf(
					/* translators: 1: done count, 2: total count, 3: percentage, 4: remaining count */
					__( '%1$d of %2$d tasks completed (%3$s%%). %4$d remaining.', 'mcp-ai-wpoos-pro' ),
					$burndown['done'],
					$burndown['total'],
					$burndown['pct_done'],
					$burndown['remaining']
				)
			) . "\n";
			if ( $burndown['blocked'] > 0 ) {
				$report .= esc_html(
					sprintf(
						/* translators: %d: blocked task count */
						__( '%d task(s) blocked.', 'mcp-ai-wpoos-pro' ),
						$burndown['blocked']
					)
				) . "\n";
			}
		}

		// Recently completed.
		$report .= "\n## " . esc_html__( 'Recently Completed', 'mcp-ai-wpoos-pro' ) . "\n\n";
		if ( empty( $recently_completed ) ) {
			$report .= esc_html__( 'No tasks completed in the last 14 days.', 'mcp-ai-wpoos-pro' ) . "\n";
		} else {
			foreach ( $recently_completed as $task ) {
				$report .= '- ' . esc_html( $task['title'] ) . ' (' . esc_html__( 'completed', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( $task['completed_at'] ) . ")\n";
			}
		}

		// Upcoming tasks.
		$report .= "\n## " . esc_html__( 'Upcoming Tasks', 'mcp-ai-wpoos-pro' ) . ' (7 ' . esc_html__( 'days', 'mcp-ai-wpoos-pro' ) . ")\n\n";
		if ( empty( $upcoming_tasks ) ) {
			$report .= esc_html__( 'No tasks due in the next 7 days.', 'mcp-ai-wpoos-pro' ) . "\n";
		} else {
			foreach ( $upcoming_tasks as $task ) {
				$line = '- ' . esc_html( $task['title'] );
				$line .= ' | ' . esc_html__( 'Due', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['due_date'] );
				$line .= ' | ' . esc_html__( 'Priority', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['priority'] );
				if ( $task['assignee'] ) {
					$line .= ' | ' . esc_html__( 'Assignee', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['assignee'] );
				}
				$report .= $line . "\n";
			}
		}

		// Blockers.
		$report .= "\n## " . esc_html__( 'Blockers', 'mcp-ai-wpoos-pro' ) . "\n\n";
		if ( empty( $blockers ) ) {
			$report .= esc_html__( 'No blocked tasks.', 'mcp-ai-wpoos-pro' ) . "\n";
		} else {
			foreach ( $blockers as $blocker ) {
				$line = '- ' . esc_html( $blocker['title'] );
				if ( $blocker['assignee'] ) {
					$line .= ' (' . esc_html( $blocker['assignee'] ) . ')';
				}
				$line .= ' — ' . esc_html( $blocker['reason'] );
				$report .= $line . "\n";
			}
		}

		// Risk assessment.
		$report .= "\n## " . esc_html__( 'Risk Assessment', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$report .= '**' . esc_html__( 'Level', 'mcp-ai-wpoos-pro' ) . ':** ' . esc_html( strtoupper( $risk['level'] ) ) . ' (' . esc_html__( 'Score', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( (string) $risk['score'] ) . ")\n\n";
		$report .= esc_html( $risk['summary'] ) . "\n";

		return $report;
	}

	/**
	 * Build HTML report.
	 *
	 * @param WP_Post $project          Project post.
	 * @param string  $project_status   Project status.
	 * @param string  $project_start    Start date.
	 * @param string  $project_end      End date.
	 * @param array   $assignee_names   Assignee display names.
	 * @param array   $task_counts      Task count breakdown.
	 * @param array   $recently_completed Recently completed tasks.
	 * @param array   $upcoming_tasks   Upcoming tasks.
	 * @param array   $blockers         Blocked tasks.
	 * @param array   $risk             Risk assessment.
	 * @param array   $burndown         Burndown snapshot.
	 * @return string
	 */
	private function build_html_report(
		$project,
		$project_status,
		$project_start,
		$project_end,
		$assignee_names,
		$task_counts,
		$recently_completed,
		$upcoming_tasks,
		$blockers,
		$risk,
		$burndown
	) {
		$report  = '<h1>' . esc_html__( 'Project Status Report', 'mcp-ai-wpoos-pro' ) . '</h1>' . "\n";
		$report .= '<p><strong>' . esc_html__( 'Generated', 'mcp-ai-wpoos-pro' ) . ':</strong> ' . esc_html( current_time( 'Y-m-d H:i:s' ) ) . '</p>' . "\n";

		// Project summary.
		$report .= '<h2>' . esc_html__( 'Project Summary', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
		$report .= '<table border="1" cellpadding="4" cellspacing="0">' . "\n";
		$report .= '<tr><th>' . esc_html__( 'Field', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Value', 'mcp-ai-wpoos-pro' ) . '</th></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( $project->post_title ) . '</td></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( ucfirst( $project_status ) ) . '</td></tr>' . "\n";

		if ( $project_start ) {
			$report .= '<tr><td>' . esc_html__( 'Start Date', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( $project_start ) . '</td></tr>' . "\n";
		}
		if ( $project_end ) {
			$report .= '<tr><td>' . esc_html__( 'End Date', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( $project_end ) . '</td></tr>' . "\n";
		}
		if ( ! empty( $assignee_names ) ) {
			$report .= '<tr><td>' . esc_html__( 'Assigned To', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( implode( ', ', $assignee_names ) ) . '</td></tr>' . "\n";
		}
		$report .= '</table>' . "\n";

		if ( ! empty( $project->post_content ) ) {
			$report .= '<p><strong>' . esc_html__( 'Description:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( wp_strip_all_tags( $project->post_content ) ) . '</p>' . "\n";
		}

		// Task counts.
		$report .= '<h2>' . esc_html__( 'Task Overview', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
		$report .= '<table border="1" cellpadding="4" cellspacing="0">' . "\n";
		$report .= '<tr><th>' . esc_html__( 'Metric', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Count', 'mcp-ai-wpoos-pro' ) . '</th></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Total Tasks', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( (string) $task_counts['total'] ) . '</td></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Completed', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( (string) $task_counts['completed'] ) . '</td></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'In Progress', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( (string) $task_counts['in_progress'] ) . '</td></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Pending', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( (string) $task_counts['pending'] ) . '</td></tr>' . "\n";
		$report .= '<tr><td>' . esc_html__( 'Blocked', 'mcp-ai-wpoos-pro' ) . '</td><td>' . esc_html( (string) $task_counts['blocked'] ) . '</td></tr>' . "\n";
		$report .= '</table>' . "\n";

		// Burndown.
		if ( $burndown && $burndown['total'] > 0 ) {
			$report .= '<h2>' . esc_html__( 'Burndown Snapshot', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
			$report .= '<p>' . esc_html(
				sprintf(
					/* translators: 1: done count, 2: total count, 3: percentage, 4: remaining count */
					__( '%1$d of %2$d tasks completed (%3$s%%). %4$d remaining.', 'mcp-ai-wpoos-pro' ),
					$burndown['done'],
					$burndown['total'],
					$burndown['pct_done'],
					$burndown['remaining']
				)
			) . '</p>' . "\n";
			if ( $burndown['blocked'] > 0 ) {
				$report .= '<p>' . esc_html(
					sprintf(
						/* translators: %d: blocked task count */
						__( '%d task(s) blocked.', 'mcp-ai-wpoos-pro' ),
						$burndown['blocked']
					)
				) . '</p>' . "\n";
			}
		}

		// Recently completed.
		$report .= '<h2>' . esc_html__( 'Recently Completed', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
		if ( empty( $recently_completed ) ) {
			$report .= '<p>' . esc_html__( 'No tasks completed in the last 14 days.', 'mcp-ai-wpoos-pro' ) . '</p>' . "\n";
		} else {
			$report .= '<ul>' . "\n";
			foreach ( $recently_completed as $task ) {
				$report .= '<li>' . esc_html( $task['title'] ) . ' (' . esc_html__( 'completed', 'mcp-ai-wpoos-pro' ) . ' ' . esc_html( $task['completed_at'] ) . ')</li>' . "\n";
			}
			$report .= '</ul>' . "\n";
		}

		// Upcoming tasks.
		$report .= '<h2>' . esc_html__( 'Upcoming Tasks', 'mcp-ai-wpoos-pro' ) . ' (7 ' . esc_html__( 'days', 'mcp-ai-wpoos-pro' ) . ')</h2>' . "\n";
		if ( empty( $upcoming_tasks ) ) {
			$report .= '<p>' . esc_html__( 'No tasks due in the next 7 days.', 'mcp-ai-wpoos-pro' ) . '</p>' . "\n";
		} else {
			$report .= '<ul>' . "\n";
			foreach ( $upcoming_tasks as $task ) {
				$line = '<li>' . esc_html( $task['title'] );
				$line .= ' | ' . esc_html__( 'Due', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['due_date'] );
				$line .= ' | ' . esc_html__( 'Priority', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['priority'] );
				if ( $task['assignee'] ) {
					$line .= ' | ' . esc_html__( 'Assignee', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( $task['assignee'] );
				}
				$report .= $line . '</li>' . "\n";
			}
			$report .= '</ul>' . "\n";
		}

		// Blockers.
		$report .= '<h2>' . esc_html__( 'Blockers', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
		if ( empty( $blockers ) ) {
			$report .= '<p>' . esc_html__( 'No blocked tasks.', 'mcp-ai-wpoos-pro' ) . '</p>' . "\n";
		} else {
			$report .= '<ul>' . "\n";
			foreach ( $blockers as $blocker ) {
				$line = '<li>' . esc_html( $blocker['title'] );
				if ( $blocker['assignee'] ) {
					$line .= ' (' . esc_html( $blocker['assignee'] ) . ')';
				}
				$line .= ' &mdash; ' . esc_html( $blocker['reason'] );
				$report .= $line . '</li>' . "\n";
			}
			$report .= '</ul>' . "\n";
		}

		// Risk assessment.
		$report .= '<h2>' . esc_html__( 'Risk Assessment', 'mcp-ai-wpoos-pro' ) . '</h2>' . "\n";
		$report .= '<p><strong>' . esc_html__( 'Level', 'mcp-ai-wpoos-pro' ) . ':</strong> ' . esc_html( strtoupper( $risk['level'] ) ) . ' (' . esc_html__( 'Score', 'mcp-ai-wpoos-pro' ) . ': ' . esc_html( (string) $risk['score'] ) . ')</p>' . "\n";
		$report .= '<p>' . esc_html( $risk['summary'] ) . '</p>' . "\n";

		return $report;
	}
}
