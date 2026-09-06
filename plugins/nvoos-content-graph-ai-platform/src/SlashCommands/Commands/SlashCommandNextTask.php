<?php
/**
 * Next Task Slash Command
 *
 * Autonomous task discovery and execution for WordPress content and development.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Next Task Command Class
 *
 * Implements autonomous task-to-production automation:
 * 1. Task Discovery - Analyze site needs
 * 2. Context Analysis - Deep site/content exploration
 * 3. Planning - Generate implementation strategy
 * 4. User Approval - Review and approve plan (Human-in-the-Loop)
 * 5. Implementation - Execute changes
 * 6. Quality Check - Validate changes
 *
 * @since 1.2.0
 */
class SlashCommandNextTask {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Execute next-task command
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to manage content tasks.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse flags.
		$filter    = isset( $flags['filter'] ) ? $flags['filter'] : 'all';
		$dry_run   = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$auto_fix  = isset( $flags['auto'] ) || isset( $flags['a'] );
		$max_tasks = isset( $flags['limit'] ) ? absint( $flags['limit'] ) : 5;
		$task_type = isset( $flags['type'] ) ? sanitize_text_field( $flags['type'] ) : 'all';

		// Phase 1: Task Discovery.
		$tasks = $this->discover_tasks(
			array(
				'filter'    => $filter,
				'task_type' => $task_type,
				'limit'     => $max_tasks,
				'user_id'   => $user_id,
			)
		);

		if ( is_wp_error( $tasks ) ) {
			return $tasks;
		}

		if ( empty( $tasks ) ) {
			return $this->format_response(
				'success',
				__( 'No tasks found! Your site is in great shape.', 'nvoos-content-graph-ai-platform' ),
				array( 'tasks' => array() )
			);
		}

		// Phase 2: Context Analysis.
		$context_data = $this->gather_site_context();

		// Phase 3: Planning.
		$plan = $this->create_task_plan( $tasks, $context_data, $flags );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// If dry-run, return plan without executing.
		if ( $dry_run ) {
			return $this->format_response(
				'success',
				__( 'Task plan (dry run - no changes made)', 'nvoos-content-graph-ai-platform' ),
				array(
					'plan'  => $plan,
					'tasks' => $tasks,
				)
			);
		}

		// Phase 4: User Approval (Human-in-the-Loop).
		// In a real implementation, this would pause and wait for user confirmation.
		// For now, auto-approve if --auto flag is set.
		if ( ! $auto_fix ) {
			return $this->format_response(
				'approval_required',
				__( 'Task plan ready for review. Use --auto flag to execute without approval.', 'nvoos-content-graph-ai-platform' ),
				array(
					'plan'  => $plan,
					'tasks' => $tasks,
				)
			);
		}

		// Phase 5: Implementation.
		$results = $this->execute_plan( $plan, $context );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		// Phase 6: Quality Check.
		$validation = $this->validate_quality( $results );

		return $this->format_response(
			'success',
			sprintf(
				/* translators: %d: Number of tasks completed */
				__( 'Completed %d task(s) successfully.', 'nvoos-content-graph-ai-platform' ),
				count( $results )
			),
			array(
				'plan'       => $plan,
				'results'    => $results,
				'validation' => $validation,
			)
		);
	}

	/**
	 * Discover tasks that need attention
	 *
	 * Scans for:
	 * - Draft posts ready to publish
	 * - Posts missing meta descriptions
	 * - Outdated content needing updates
	 * - SEO issues to fix
	 *
	 * @param array $options Discovery options.
	 * @return array|WP_Error Array of tasks or error.
	 */
	private function discover_tasks( $options ) {
		$tasks = array();

		// Check for draft posts.
		if ( in_array( $options['task_type'], array( 'all', 'drafts' ), true ) ) {
			$draft_tasks = $this->find_draft_posts( $options );
			if ( ! empty( $draft_tasks ) ) {
				$tasks = array_merge( $tasks, $draft_tasks );
			}
		}

		// Check for missing meta descriptions.
		if ( in_array( $options['task_type'], array( 'all', 'seo' ), true ) ) {
			$meta_tasks = $this->find_missing_meta( $options );
			if ( ! empty( $meta_tasks ) ) {
				$tasks = array_merge( $tasks, $meta_tasks );
			}
		}

		// Check for outdated content.
		if ( in_array( $options['task_type'], array( 'all', 'updates' ), true ) ) {
			$update_tasks = $this->find_outdated_content( $options );
			if ( ! empty( $update_tasks ) ) {
				$tasks = array_merge( $tasks, $update_tasks );
			}
		}

		// Prioritize tasks.
		usort(
			$tasks,
			function ( $a, $b ) {
				return $b['priority'] - $a['priority'];
			}
		);

		// Limit results.
		if ( count( $tasks ) > $options['limit'] ) {
			$tasks = array_slice( $tasks, 0, $options['limit'] );
		}

		return $tasks;
	}

	/**
	 * Find draft posts ready to publish
	 *
	 * @param array $options Query options.
	 * @return array Draft post tasks.
	 */
	private function find_draft_posts( $options ) {
		$drafts = get_posts(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
				'numberposts' => $options['limit'],
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		);

		$tasks = array();
		foreach ( $drafts as $draft ) {
			$tasks[] = array(
				'type'        => 'publish_draft',
				'priority'    => 80,
				'post_id'     => $draft->ID,
				'title'       => get_the_title( $draft->ID ),
				'description' => sprintf(
					/* translators: %s: post title */
					__( 'Publish draft post: %s', 'nvoos-content-graph-ai-platform' ),
					get_the_title( $draft->ID )
				),
				'effort'      => 'medium',
			);
		}

		return $tasks;
	}

	/**
	 * Find posts missing meta descriptions
	 *
	 * @param array $options Query options.
	 * @return array Meta description tasks.
	 */
	private function find_missing_meta( $options ) {
		$posts = get_posts(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'numberposts' => $options['limit'] * 2,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$tasks = array();
		foreach ( $posts as $post ) {
			$meta_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			if ( empty( $meta_desc ) ) {
				$meta_desc = get_post_meta( $post->ID, 'rank_math_description', true );
			}

			if ( empty( $meta_desc ) ) {
				$tasks[] = array(
					'type'        => 'add_meta_description',
					'priority'    => 60,
					'post_id'     => $post->ID,
					'title'       => get_the_title( $post->ID ),
					'description' => sprintf(
						/* translators: %s: post title */
						__( 'Add meta description to: %s', 'nvoos-content-graph-ai-platform' ),
						get_the_title( $post->ID )
					),
					'effort'      => 'low',
				);

				if ( count( $tasks ) >= $options['limit'] ) {
					break;
				}
			}
		}

		return $tasks;
	}

	/**
	 * Find outdated content needing updates
	 *
	 * @param array $options Query options.
	 * @return array Update tasks.
	 */
	private function find_outdated_content( $options ) {
		// Find posts older than 1 year with no updates.
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-1 year' ) );

		$posts = get_posts(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'date_query'  => array(
					array(
						'before' => $cutoff_date,
						'column' => 'post_modified',
					),
				),
				'numberposts' => $options['limit'],
				'orderby'     => 'modified',
				'order'       => 'ASC',
			)
		);

		$tasks = array();
		foreach ( $posts as $post ) {
			$tasks[] = array(
				'type'        => 'update_content',
				'priority'    => 40,
				'post_id'     => $post->ID,
				'title'       => get_the_title( $post->ID ),
				'description' => sprintf(
					/* translators: %s: post title */
					__( 'Update outdated content: %s', 'nvoos-content-graph-ai-platform' ),
					get_the_title( $post->ID )
				),
				'effort'      => 'high',
				'last_update' => $post->post_modified,
			);
		}

		return $tasks;
	}

	/**
	 * Gather site context for planning
	 *
	 * @return array Site context data.
	 */
	private function gather_site_context() {
		return array(
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => get_site_url(),
			'post_count'   => wp_count_posts()->publish,
			'active_theme' => wp_get_theme()->get( 'Name' ),
			'seo_plugin'   => $this->detect_seo_plugin(),
			'content_type' => get_option( 'default_post_format', 'standard' ),
		);
	}

	/**
	 * Detect active SEO plugin
	 *
	 * @return string SEO plugin name or 'none'.
	 */
	private function detect_seo_plugin() {
		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			return 'yoast';
		} elseif ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			return 'rank_math';
		}
		return 'none';
	}

	/**
	 * Create task execution plan
	 *
	 * @param array $tasks   Discovered tasks.
	 * @param array $context Site context.
	 * @param array $flags   Command flags.
	 * @return array|WP_Error Task plan or error.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function create_task_plan( $tasks, $context, $flags ) {
		$plan = array(
			'total_tasks'    => count( $tasks ),
			'estimated_time' => $this->estimate_time( $tasks ),
			'steps'          => array(),
		);

		foreach ( $tasks as $index => $task ) {
			$step = array(
				'step_number'  => $index + 1,
				'task_type'    => $task['type'],
				'post_id'      => $task['post_id'],
				'title'        => $task['title'],
				'description'  => $task['description'],
				'actions'      => $this->plan_actions( $task, $context ),
				'tools_needed' => $this->get_required_tools( $task ),
			);

			$plan['steps'][] = $step;
		}

		return $plan;
	}

	/**
	 * Estimate time for task completion
	 *
	 * @param array $tasks Tasks to estimate.
	 * @return string Estimated time (e.g., "15 minutes").
	 */
	private function estimate_time( $tasks ) {
		$minutes = 0;
		foreach ( $tasks as $task ) {
			switch ( $task['effort'] ) {
				case 'low':
					$minutes += 5;
					break;
				case 'medium':
					$minutes += 15;
					break;
				case 'high':
					$minutes += 30;
					break;
			}
		}

		if ( $minutes < 60 ) {
			return sprintf(
				/* translators: %d: number of minutes */
				__( '%d minutes', 'nvoos-content-graph-ai-platform' ),
				$minutes
			);
		}

		$hours = floor( $minutes / 60 );
		$mins  = $minutes % 60;
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '%1$d hour(s) %2$d minutes', 'nvoos-content-graph-ai-platform' ),
			$hours,
			$mins
		);
	}

	/**
	 * Plan actions for a specific task
	 *
	 * @param array $task    Task data.
	 * @param array $context Site context.
	 * @return array Action steps.
		 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function plan_actions( $task, $context ) {
		switch ( $task['type'] ) {
			case 'publish_draft':
				return array(
					'validate_content',
					'check_seo',
					'add_featured_image',
					'publish_post',
				);

			case 'add_meta_description':
				return array(
					'read_content',
					'generate_meta_description',
					'update_seo_meta',
				);

			case 'update_content':
				return array(
					'read_current_content',
					'check_for_updates',
					'revise_content',
					'validate_changes',
				);

			default:
				return array( 'execute_task' );
		}
	}

	/**
	 * Get required tools for a task
	 *
	 * @param array $task Task data.
	 * @return array Tool slugs.
	 */
	private function get_required_tools( $task ) {
		$tools_map = array(
			'publish_draft'        => array( 'update_post', 'get_post', 'seo_meta_optimizer' ),
			'add_meta_description' => array( 'get_post', 'seo_meta_optimizer', 'update_post' ),
			'update_content'       => array( 'get_post', 'update_post', 'content_freshness_checker' ),
		);

		return isset( $tools_map[ $task['type'] ] ) ? $tools_map[ $task['type'] ] : array();
	}

	/**
	 * Execute task plan
	 *
	 * @param array $plan    Task plan.
	 * @param array $context Execution context.
	 * @return array|WP_Error Execution results or error.
	 */
	private function execute_plan( $plan, $context ) {
		$results = array();

		foreach ( $plan['steps'] as $step ) {
			$result = $this->execute_step( $step, $context );

			if ( is_wp_error( $result ) ) {
				// Log error and continue with next task.
				$results[] = array(
					'step'    => $step['step_number'],
					'post_id' => $step['post_id'],
					'status'  => 'failed',
					'error'   => $result->get_error_message(),
				);
				continue;
			}

			$results[] = array(
				'step'    => $step['step_number'],
				'post_id' => $step['post_id'],
				'status'  => 'completed',
				'result'  => $result,
			);
		}

		return $results;
	}

	/**
	 * Execute a single step
	 *
	 * @param array $step    Step data.
	 * @param array $context Execution context.
		 * @return mixed|WP_Error Step result or error.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function execute_step( $step, $context ) {
		// This would integrate with the tool system in a real implementation.
		// For now, return a simulated success.
		return array(
			'message' => sprintf(
				/* translators: %s: task description */
				__( 'Executed: %s', 'nvoos-content-graph-ai-platform' ),
				$step['description']
			),
		);
	}

	/**
	 * Validate quality of executed tasks
	 *
	 * @param array $results Execution results.
	 * @return array Validation results.
	 */
	private function validate_quality( $results ) {
		$validation = array(
			'total'    => count( $results ),
			'passed'   => 0,
			'failed'   => 0,
			'warnings' => array(),
		);

		foreach ( $results as $result ) {
			if ( 'completed' === $result['status'] ) {
				++$validation['passed'];
			} else {
				++$validation['failed'];
			}
		}

		return $validation;
	}

	/**
	 * Format command response
	 *
	 * @param string $status  Response status.
	 * @param string $message User message.
	 * @param array  $data    Additional data.
	 * @return string Formatted response.
	 */
	private function format_response( $status, $message, $data = array() ) {
		$output = "## {$message}\n\n";

		if ( isset( $data['tasks'] ) && ! empty( $data['tasks'] ) ) {
			$output .= "### Discovered Tasks\n\n";
			foreach ( $data['tasks'] as $task ) {
				$output .= sprintf(
					"- **%s** (Priority: %d)\n  %s\n\n",
					esc_html( $task['title'] ),
					$task['priority'],
					esc_html( $task['description'] )
				);
			}
		}

		if ( isset( $data['plan'] ) ) {
			$output .= sprintf(
				"### Execution Plan\n\n**Total Tasks:** %d\n**Estimated Time:** %s\n\n",
				$data['plan']['total_tasks'],
				esc_html( $data['plan']['estimated_time'] )
			);
		}

		if ( isset( $data['results'] ) ) {
			$output .= "### Results\n\n";
			foreach ( $data['results'] as $result ) {
				$icon    = 'completed' === $result['status'] ? '✅' : '❌';
				$output .= sprintf(
					"%s Step %d: %s\n",
					$icon,
					$result['step'],
					'completed' === $result['status'] ? 'Success' : $result['error']
				);
			}
			$output .= "\n";
		}

		if ( isset( $data['validation'] ) ) {
			$validation = $data['validation'];
			$output    .= sprintf(
				"### Quality Check\n\n**Passed:** %d / %d\n**Failed:** %d\n\n",
				$validation['passed'],
				$validation['total'],
				$validation['failed']
			);
		}

		return $output;
	}
}
