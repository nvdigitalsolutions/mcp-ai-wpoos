<?php
/**
 * Project Management Toolkit Shared Engine
 *
 * Cross-cutting helpers for the Project Management toolkit:
 *
 *  - Toolkit settings (wp_mcp_ai_pm_toolkit_settings) resolution.
 *  - Portfolio health scoring with weighted factor decomposition.
 *  - Project / task lifecycle management and counting.
 *  - Pipeline stage and probability lookups.
 *  - Round-robin assignee routing.
 *  - Deadline tracking (tasks + events).
 *  - Stale task detection and resource utilisation analysis.
 *
 * Mirrors WP_MCP_AI_CRM_Engine in the CRM toolkit.
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
 * Shared PM engine.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_PM_Engine {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_pm_toolkit_settings';

	/**
	 * Cached toolkit settings.
	 *
	 * @var array|null
	 */
	private static $settings_cache = null;

	/*
	---------------------------------------------------------------------
	 * Toolkit settings
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get resolved toolkit settings.
	 *
	 * Reads wp_mcp_ai_pm_toolkit_settings from options, merges with
	 * defaults, and applies the wp_mcp_ai_pm_toolkit_settings filter.
	 * Result is cached per-request; call flush_settings_cache() to
	 * invalidate.
	 *
	 * @return array
	 */
	public static function get_toolkit_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$defaults = array(
			'default_project_status'   => 'planning',
			'default_task_priority'    => 'medium',
			'estimation_method'        => 'story_points',
			'burndown_basis'           => 'tasks',
			'sprint_duration_days'     => 14,
			'working_days'             => array( 1, 2, 3, 4, 5 ),
			'portfolio_health_weights' => array(
				'schedule_variance'    => 0.25,
				'task_completion_rate' => 0.25,
				'blocker_count'        => 0.20,
				'overdue_task_ratio'   => 0.15,
				'resource_utilization' => 0.15,
			),
			'risk_thresholds'          => array(
				'stale_task_days'       => 14,
				'overdue_warning_days'  => 3,
				'overdue_critical_days' => 7,
				'utilization_high_pct'  => 90,
				'utilization_low_pct'   => 30,
			),
			'notifications'            => array(
				'due_date_reminder_days' => array( 7, 3, 1 ),
				'assignment_notify'      => true,
				'status_change_notify'   => true,
				'blocker_alert'          => true,
				'daily_digest'           => true,
			),
			'integrations'             => array(
				'calendar_provider'     => '',
				'google_calendar_oauth' => '',
				'slack_webhook_url'     => '',
			),
		);

		$stored = get_option( self::SETTINGS_OPTION, array() );
		$merged = wp_parse_args( $stored, $defaults );

		/**
		 * Filter the resolved PM toolkit settings.
		 *
		 * @param array $merged Settings after merging stored values with defaults.
		 */
		$filtered             = apply_filters( 'wp_mcp_ai_pm_toolkit_settings', $merged );
		self::$settings_cache = is_array( $filtered ) ? $filtered : $merged;

		return self::$settings_cache;
	}

	/**
	 * Flush the in-memory settings cache.
	 */
	public static function flush_settings_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Get valid project statuses.
	 *
	 * @return string[]
	 */
	public static function get_project_statuses() {
		$statuses = array( 'idea', 'planning', 'active', 'at-risk', 'on-hold', 'completed', 'cancelled', 'archived' );

		/**
		 * Filter the recognised project statuses.
		 *
		 * @param string[] $statuses Default status slugs.
		 */
		return apply_filters( 'wp_mcp_ai_pm_project_statuses', $statuses );
	}

	/**
	 * Check whether a project status slug is valid.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function is_valid_project_status( $status ) {
		return in_array( sanitize_key( $status ), self::get_project_statuses(), true );
	}

	/**
	 * Get valid task priorities.
	 *
	 * @return string[]
	 */
	public static function get_task_priorities() {
		$priorities = array( 'lowest', 'low', 'medium', 'high', 'highest', 'critical' );

		/**
		 * Filter the recognised task priorities.
		 *
		 * @param string[] $priorities Default priority slugs.
		 */
		return apply_filters( 'wp_mcp_ai_pm_task_priorities', $priorities );
	}

	/**
	 * Check whether a task priority slug is valid.
	 *
	 * @param string $priority Priority slug.
	 * @return bool
	 */
	public static function is_valid_task_priority( $priority ) {
		return in_array( sanitize_key( $priority ), self::get_task_priorities(), true );
	}

	/*
	---------------------------------------------------------------------
	 * Portfolio health
	 * ------------------------------------------------------------------
	 */

	/**
	 * Calculate portfolio health score (0–100) across all active projects.
	 *
	 * Weights are read from the toolkit settings portfolio_health_weights
	 * block. The score is a weighted average of schedule variance,
	 * task completion rate, blocker penalty, overdue ratio, and a
	 * resource-health proxy.
	 *
	 * @return array Keys: score, total_projects, at_risk_count,
	 *               total_open_tasks, total_overdue_tasks,
	 *               total_blocked_tasks, schedule_variance,
	 *               completion_rate.
	 */
	public static function calculate_portfolio_health() {
		$settings = self::get_toolkit_settings();
		$weights  = $settings['portfolio_health_weights'];

		// Get all active projects.
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_project_status',
						'value'   => array( 'completed', 'cancelled', 'archived' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		if ( empty( $projects ) ) {
			return array(
				'score'               => 100,
				'total_projects'      => 0,
				'at_risk_count'       => 0,
				'total_open_tasks'    => 0,
				'total_overdue_tasks' => 0,
				'total_blocked_tasks' => 0,
				'schedule_variance'   => 100,
				'completion_rate'     => 100,
			);
		}

		$total_projects          = count( $projects );
		$at_risk_count           = 0;
		$total_overdue_tasks     = 0;
		$total_open_tasks        = 0;
		$total_blocked_tasks     = 0;
		$schedule_variance_total = 0;

		foreach ( $projects as $project ) {
			$status = get_post_meta( $project->ID, '_project_status', true );

			if ( 'at-risk' === $status ) {
				++$at_risk_count;
			}

			// Count tasks per project.
			$tasks = get_posts(
				array(
					'post_type'      => 'mcp_ai_task',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_key'       => '_task_project_id',
					'meta_value'     => $project->ID,
				)
			);

			foreach ( $tasks as $task ) {
				$task_status = get_post_meta( $task->ID, '_task_status', true );
				if ( ! in_array( $task_status, array( 'completed', 'cancelled' ), true ) ) {
					++$total_open_tasks;
				}
				if ( 'blocked' === $task_status ) {
					++$total_blocked_tasks;
				}

				$due_date = get_post_meta( $task->ID, '_task_due_date', true );
				if ( $due_date && strtotime( $due_date ) < time() && 'completed' !== $task_status ) {
					++$total_overdue_tasks;
				}
			}

			// Schedule variance: compare end_date to today.
			$end_date = get_post_meta( $project->ID, '_project_end_date', true );
			if ( $end_date ) {
				$start_date               = get_post_meta( $project->ID, '_project_start_date', true );
				$start_ts                 = $start_date ? strtotime( $start_date ) : strtotime( $project->post_date );
				$end_ts                   = strtotime( $end_date );
				$total_days               = max( 1, ( $end_ts - $start_ts ) / DAY_IN_SECONDS );
				$remaining_days           = max( 0, ( $end_ts - time() ) / DAY_IN_SECONDS );
				$schedule_variance_total += ( $remaining_days / $total_days );
			}
		}

		$schedule_variance = $total_projects > 0
			? min( 1.0, $schedule_variance_total / $total_projects )
			: 1.0;
		$completion_rate   = $total_open_tasks > 0
			? 1.0 - ( $total_overdue_tasks / $total_open_tasks )
			: 1.0;
		$blocker_penalty   = $total_open_tasks > 0
			? 1.0 - min( 1.0, $total_blocked_tasks / max( 1, $total_open_tasks ) )
			: 1.0;
		$overdue_ratio     = $total_open_tasks > 0
			? 1.0 - ( $total_overdue_tasks / $total_open_tasks )
			: 1.0;
		$resource_health   = 1.0 - ( $at_risk_count / max( 1, $total_projects ) );

		$score = (
			( $schedule_variance * $weights['schedule_variance'] * 100 ) +
			( $completion_rate * $weights['task_completion_rate'] * 100 ) +
			( $blocker_penalty * $weights['blocker_count'] * 100 ) +
			( $overdue_ratio * $weights['overdue_task_ratio'] * 100 ) +
			( $resource_health * $weights['resource_utilization'] * 100 )
		);

		$result = array(
			'score'               => round( min( 100, max( 0, $score ) ), 1 ),
			'total_projects'      => $total_projects,
			'at_risk_count'       => $at_risk_count,
			'total_open_tasks'    => $total_open_tasks,
			'total_overdue_tasks' => $total_overdue_tasks,
			'total_blocked_tasks' => $total_blocked_tasks,
			'schedule_variance'   => round( $schedule_variance * 100, 1 ),
			'completion_rate'     => round( $completion_rate * 100, 1 ),
		);

		/**
		 * Fires after portfolio health is calculated.
		 *
		 * @param array $result Health score and decomposition.
		 */
		do_action( 'wp_mcp_ai_pm_portfolio_health_calculated', $result );

		return $result;
	}

	/*
	---------------------------------------------------------------------
	 * Counting helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Count tasks, optionally filtered by project and/or status.
	 *
	 * @param int    $project_id Project post ID (0 = all).
	 * @param string $status     Task status slug ('' = all).
	 * @return int
	 */
	public static function count_tasks( $project_id = 0, $status = '' ) {
		$args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$meta_query = array();
		if ( $project_id ) {
			$meta_query[] = array(
				'key'   => '_task_project_id',
				'value' => absint( $project_id ),
			);
		}
		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_task_status',
				'value' => sanitize_key( $status ),
			);
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$tasks = get_posts( $args );
		return count( $tasks );
	}

	/**
	 * Count projects, optionally filtered by status.
	 *
	 * @param string $status Project status slug ('' = all).
	 * @return int
	 */
	public static function count_projects( $status = '' ) {
		$args = array(
			'post_type'      => 'mcp_ai_project',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		if ( $status ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_project_status',
					'value' => sanitize_key( $status ),
				),
			);
		}

		$projects = get_posts( $args );
		return count( $projects );
	}

	/*
	---------------------------------------------------------------------
	 * Pipeline stage helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get all project pipeline stages.
	 *
	 * Delegates to WP_MCP_AI_PM_Pipeline_Stages when available.
	 *
	 * @return array<string,array>
	 */
	public static function get_pipeline_stages() {
		if ( class_exists( 'WP_MCP_AI_PM_Pipeline_Stages' ) ) {
			return WP_MCP_AI_PM_Pipeline_Stages::get_stages();
		}
		return array();
	}

	/**
	 * Get the win/likelihood probability for a pipeline stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return float 0.0–1.0
	 */
	public static function stage_probability( $stage_id ) {
		if ( class_exists( 'WP_MCP_AI_PM_Pipeline_Stages' ) ) {
			return WP_MCP_AI_PM_Pipeline_Stages::probability( $stage_id );
		}
		return 0.0;
	}

	/*
	---------------------------------------------------------------------
	 * Assignment routing
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get the next assignee using round-robin from a pool.
	 *
	 * @param int[] $assignee_pool Array of user IDs. Falls back to
	 *                             toolkit settings assignee_pool.
	 * @return int User ID, or 0 if no pool available.
	 */
	public static function get_next_assignee( $assignee_pool = array() ) {
		if ( empty( $assignee_pool ) ) {
			$settings      = self::get_toolkit_settings();
			$assignee_pool = isset( $settings['assignee_pool'] ) ? $settings['assignee_pool'] : array();
		}
		if ( empty( $assignee_pool ) ) {
			return 0;
		}

		$last_assignee = (int) get_option( 'wp_mcp_ai_pm_last_assignee_index', -1 );
		$next_index    = ( $last_assignee + 1 ) % count( $assignee_pool );
		update_option( 'wp_mcp_ai_pm_last_assignee_index', $next_index );

		return absint( $assignee_pool[ $next_index ] );
	}

	/*
	---------------------------------------------------------------------
	 * Deadline tracking
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get upcoming deadlines (tasks + events) within N days.
	 *
	 * @param int $days  Look-ahead window in days.
	 * @param int $limit Maximum results.
	 * @return array[] Sorted by due date ascending.
	 */
	public static function get_upcoming_deadlines( $days = 7, $limit = 20 ) {
		$deadlines = array();
		$cutoff    = time() + ( $days * DAY_IN_SECONDS );
		$now       = time();

		// Tasks with due dates.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_query'     => array(
					array(
						'key'     => '_task_due_date',
						'value'   => array( gmdate( 'Y-m-d', $now ), gmdate( 'Y-m-d', $cutoff ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => '_task_due_date',
				'order'          => 'ASC',
			)
		);

		foreach ( $tasks as $task ) {
			$due_date      = get_post_meta( $task->ID, '_task_due_date', true );
			$status        = get_post_meta( $task->ID, '_task_status', true );
			$priority      = get_post_meta( $task->ID, '_task_priority', true );
			$project_id    = get_post_meta( $task->ID, '_task_project_id', true );
			$project_title = $project_id ? get_the_title( $project_id ) : '';

			$deadlines[] = array(
				'id'            => $task->ID,
				'title'         => $task->post_title,
				'type'          => 'task',
				'due_date'      => $due_date,
				'status'        => $status,
				'priority'      => $priority,
				'project_id'    => absint( $project_id ),
				'project_title' => $project_title,
				'days_until'    => $due_date
					? ceil( ( strtotime( $due_date ) - $now ) / DAY_IN_SECONDS )
					: null,
			);
		}

		// Events with dates.
		$events = get_posts(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, $limit - count( $deadlines ) ),
				'meta_query'     => array(
					array(
						'key'     => '_event_date',
						'value'   => array( gmdate( 'Y-m-d', $now ), gmdate( 'Y-m-d', $cutoff ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
				'orderby'        => 'meta_value',
				'meta_key'       => '_event_date',
				'order'          => 'ASC',
			)
		);

		foreach ( $events as $event ) {
			$event_date = get_post_meta( $event->ID, '_event_date', true );
			$event_time = get_post_meta( $event->ID, '_event_time', true );

			$deadlines[] = array(
				'id'         => $event->ID,
				'title'      => $event->post_title,
				'type'       => 'event',
				'due_date'   => $event_date,
				'time'       => $event_time,
				'days_until' => $event_date
					? ceil( ( strtotime( $event_date ) - $now ) / DAY_IN_SECONDS )
					: null,
			);
		}

		// Sort by due date.
		usort(
			$deadlines,
			function ( $a, $b ) {
				return strtotime( $a['due_date'] ?? '9999-12-31' )
					- strtotime( $b['due_date'] ?? '9999-12-31' );
			}
		);

		return array_slice( $deadlines, 0, $limit );
	}

	/*
	---------------------------------------------------------------------
	 * Stale / risk detection
	 * ------------------------------------------------------------------
	 */

	/**
	 * Detect stale tasks — open tasks not modified in N days.
	 *
	 * @param int $days Staleness threshold in days.
	 * @return array[]
	 */
	public static function detect_stale_tasks( $days = 14 ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'date_query'     => array(
					array(
						'column' => 'post_modified',
						'before' => $cutoff,
					),
				),
				'meta_query'     => array(
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		$stale = array();
		foreach ( $tasks as $task ) {
			$stale[] = array(
				'id'         => $task->ID,
				'title'      => $task->post_title,
				'status'     => get_post_meta( $task->ID, '_task_status', true ),
				'modified'   => $task->post_modified,
				'days_stale' => ceil( ( time() - strtotime( $task->post_modified ) ) / DAY_IN_SECONDS ),
				'project_id' => absint( get_post_meta( $task->ID, '_task_project_id', true ) ),
			);
		}

		return $stale;
	}

	/*
	---------------------------------------------------------------------
	 * Resource utilisation
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get resource utilisation per assignee.
	 *
	 * Computes each assignee's open-task count as a percentage of the
	 * average across all assignees, then labels over/under-allocated
	 * against the toolkit risk thresholds.
	 *
	 * @return array[]
	 */
	public static function get_resource_utilization() {
		$settings = self::get_toolkit_settings();
		$high_pct = $settings['risk_thresholds']['utilization_high_pct'];
		$low_pct  = $settings['risk_thresholds']['utilization_low_pct'];

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		$assignee_tasks = array();
		foreach ( $tasks as $task_id ) {
			$assignee_id = absint( get_post_meta( $task_id, '_task_assigned_to', true ) );
			if ( ! $assignee_id ) {
				$assignee_id = absint( get_post( $task_id )->post_author );
			}
			if ( ! isset( $assignee_tasks[ $assignee_id ] ) ) {
				$assignee_tasks[ $assignee_id ] = 0;
			}
			++$assignee_tasks[ $assignee_id ];
		}

		$result      = array();
		$total_tasks = count( $tasks );
		$avg_tasks   = $total_tasks > 0
			? $total_tasks / max( 1, count( $assignee_tasks ) )
			: 0;

		foreach ( $assignee_tasks as $user_id => $count ) {
			$user   = get_user_by( 'id', $user_id );
			$pct    = $avg_tasks > 0 ? round( ( $count / $avg_tasks ) * 100, 1 ) : 0;
			$status = 'normal';
			if ( $pct >= $high_pct ) {
				$status = 'over_allocated';
			} elseif ( $pct <= $low_pct ) {
				$status = 'under_allocated';
			}

			$result[] = array(
				'user_id'         => $user_id,
				'display_name'    => $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
				'task_count'      => $count,
				'utilization_pct' => $pct,
				'status'          => $status,
			);
		}

		return $result;
	}
}
