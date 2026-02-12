<?php
/**
 * Tool for managing care plans.
 *
 * Create, track, and manage comprehensive care plans with goals, tasks,
 * and progress monitoring. Supports chronic disease management and collaborative care.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages care plans for comprehensive health management.
 */
class WP_MCP_AI_Tool_Manage_Care_Plan implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_care_plan';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Care Plan', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create and manage comprehensive care plans with health goals, care tasks, progress tracking, and collaborative care coordination. Supports chronic disease management, post-acute care, and wellness programs. Integrates with medical records, checkups, and medications for holistic care management.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'update', 'get', 'list', 'add_goal', 'update_goal', 'add_task', 'complete_task' ),
				),
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'care_plan_id'     => array(
					'type'        => 'string',
					'description' => __( 'Care plan ID (required for update/get actions)', 'mcp-ai-wpoos-pro' ),
				),
				'plan_title'       => array(
					'type'        => 'string',
					'description' => __( 'Care plan title (required for create)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'plan_description' => array(
					'type'        => 'string',
					'description' => __( 'Care plan description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'plan_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of care plan (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'chronic_disease', 'post_acute', 'preventive', 'wellness', 'rehabilitation', 'palliative', 'custom' ),
				),
				'start_date'       => array(
					'type'        => 'string',
					'description' => __( 'Plan start date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'target_end_date'  => array(
					'type'        => 'string',
					'description' => __( 'Target end date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'goal_title'       => array(
					'type'        => 'string',
					'description' => __( 'Goal title (for add_goal action)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'goal_description' => array(
					'type'        => 'string',
					'description' => __( 'Goal description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'goal_target_date' => array(
					'type'        => 'string',
					'description' => __( 'Goal target date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'goal_id'          => array(
					'type'        => 'string',
					'description' => __( 'Goal ID (for update_goal action)', 'mcp-ai-wpoos-pro' ),
				),
				'goal_status'      => array(
					'type'        => 'string',
					'description' => __( 'Goal status (for update_goal action)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'achieved', 'revised', 'cancelled' ),
				),
				'task_title'       => array(
					'type'        => 'string',
					'description' => __( 'Task title (for add_task action)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'task_description' => array(
					'type'        => 'string',
					'description' => __( 'Task description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'task_due_date'    => array(
					'type'        => 'string',
					'description' => __( 'Task due date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'task_id'          => array(
					'type'        => 'string',
					'description' => __( 'Task ID (for complete_task action)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action', 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'database-write', 'care-coordination' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage care plans.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $action ) {
			return new WP_Error( 'wp_mcp_ai_missing_action', __( 'Action is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Execute based on action.
		switch ( $action ) {
			case 'create':
				return $this->create_care_plan( $arguments, $member_id, $current_user_id );

			case 'update':
				return $this->update_care_plan( $arguments, $member_id, $current_user_id );

			case 'get':
				$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
				return $this->get_care_plan( $member_id, $care_plan_id );

			case 'list':
				return $this->list_care_plans( $member_id );

			case 'add_goal':
				return $this->add_goal_to_plan( $arguments, $member_id, $current_user_id );

			case 'update_goal':
				return $this->update_goal( $arguments, $member_id, $current_user_id );

			case 'add_task':
				return $this->add_task_to_plan( $arguments, $member_id, $current_user_id );

			case 'complete_task':
				return $this->complete_task( $arguments, $member_id, $current_user_id );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Create a new care plan.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function create_care_plan( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create care plans.', 'mcp-ai-wpoos-pro' ) );
		}

		$plan_title = isset( $arguments['plan_title'] ) ? sanitize_text_field( $arguments['plan_title'] ) : '';
		if ( ! $plan_title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Care plan title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = 'cp_' . time() . '_' . wp_rand( 1000, 9999 );
		$care_plan = array(
			'id'               => $care_plan_id,
			'member_id'        => $member_id,
			'title'            => $plan_title,
			'description'      => isset( $arguments['plan_description'] ) ? wp_kses_post( $arguments['plan_description'] ) : '',
			'type'             => isset( $arguments['plan_type'] ) ? sanitize_text_field( $arguments['plan_type'] ) : 'custom',
			'start_date'       => isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : current_time( 'Y-m-d' ),
			'target_end_date'  => isset( $arguments['target_end_date'] ) ? sanitize_text_field( $arguments['target_end_date'] ) : '',
			'status'           => 'active',
			'goals'            => array(),
			'tasks'            => array(),
			'created_at'       => current_time( 'mysql' ),
			'created_by'       => $current_user_id,
			'last_updated'     => current_time( 'mysql' ),
		);

		// Store care plan.
		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );
		$plans[ $care_plan_id ] = $care_plan;
		update_option( $plans_key, $plans );

		return array(
			'success'      => true,
			'message'      => __( 'Care plan created successfully.', 'mcp-ai-wpoos-pro' ),
			'care_plan_id' => $care_plan_id,
			'member_id'    => $member_id,
			'care_plan'    => $care_plan,
		);
	}

	/**
	 * Update an existing care plan.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function update_care_plan( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update care plans.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
		if ( ! $care_plan_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_care_plan_id', __( 'Care plan ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update fields.
		if ( isset( $arguments['plan_title'] ) ) {
			$plans[ $care_plan_id ]['title'] = sanitize_text_field( $arguments['plan_title'] );
		}
		if ( isset( $arguments['plan_description'] ) ) {
			$plans[ $care_plan_id ]['description'] = wp_kses_post( $arguments['plan_description'] );
		}
		if ( isset( $arguments['target_end_date'] ) ) {
			$plans[ $care_plan_id ]['target_end_date'] = sanitize_text_field( $arguments['target_end_date'] );
		}

		$plans[ $care_plan_id ]['last_updated'] = current_time( 'mysql' );
		update_option( $plans_key, $plans );

		return array(
			'success'      => true,
			'message'      => __( 'Care plan updated successfully.', 'mcp-ai-wpoos-pro' ),
			'care_plan_id' => $care_plan_id,
			'member_id'    => $member_id,
			'care_plan'    => $plans[ $care_plan_id ],
		);
	}

	/**
	 * Get a specific care plan.
	 *
	 * @param int    $member_id     Member ID.
	 * @param string $care_plan_id Care plan ID.
	 * @return array|WP_Error Care plan or error.
	 */
	private function get_care_plan( $member_id, $care_plan_id ) {
		if ( ! $care_plan_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_care_plan_id', __( 'Care plan ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'care_plan_id' => $care_plan_id,
			'care_plan'    => $plans[ $care_plan_id ],
		);
	}

	/**
	 * List all care plans for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array List of care plans.
	 */
	private function list_care_plans( $member_id ) {
		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		return array(
			'success'    => true,
			'member_id'  => $member_id,
			'count'      => count( $plans ),
			'care_plans' => array_values( $plans ),
		);
	}

	/**
	 * Add a goal to a care plan.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function add_goal_to_plan( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add goals.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
		$goal_title = isset( $arguments['goal_title'] ) ? sanitize_text_field( $arguments['goal_title'] ) : '';

		if ( ! $care_plan_id || ! $goal_title ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Care plan ID and goal title are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$goal_id = 'goal_' . time() . '_' . wp_rand( 100, 999 );
		$goal = array(
			'id'          => $goal_id,
			'title'       => $goal_title,
			'description' => isset( $arguments['goal_description'] ) ? wp_kses_post( $arguments['goal_description'] ) : '',
			'target_date' => isset( $arguments['goal_target_date'] ) ? sanitize_text_field( $arguments['goal_target_date'] ) : '',
			'status'      => 'active',
			'created_at'  => current_time( 'mysql' ),
		);

		$plans[ $care_plan_id ]['goals'][] = $goal;
		$plans[ $care_plan_id ]['last_updated'] = current_time( 'mysql' );
		update_option( $plans_key, $plans );

		return array(
			'success'      => true,
			'message'      => __( 'Goal added to care plan successfully.', 'mcp-ai-wpoos-pro' ),
			'care_plan_id' => $care_plan_id,
			'goal_id'      => $goal_id,
			'goal'         => $goal,
		);
	}

	/**
	 * Update a goal.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function update_goal( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update goals.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
		$goal_id = isset( $arguments['goal_id'] ) ? sanitize_text_field( $arguments['goal_id'] ) : '';

		if ( ! $care_plan_id || ! $goal_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Care plan ID and goal ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Find and update goal.
		$found = false;
		foreach ( $plans[ $care_plan_id ]['goals'] as &$goal ) {
			if ( $goal['id'] === $goal_id ) {
				if ( isset( $arguments['goal_status'] ) ) {
					$goal['status'] = sanitize_text_field( $arguments['goal_status'] );
				}
				if ( isset( $arguments['goal_title'] ) ) {
					$goal['title'] = sanitize_text_field( $arguments['goal_title'] );
				}
				$goal['updated_at'] = current_time( 'mysql' );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'wp_mcp_ai_goal_not_found', __( 'Goal not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans[ $care_plan_id ]['last_updated'] = current_time( 'mysql' );
		update_option( $plans_key, $plans );

		return array(
			'success' => true,
			'message' => __( 'Goal updated successfully.', 'mcp-ai-wpoos-pro' ),
			'goal'    => $goal,
		);
	}

	/**
	 * Add a task to a care plan.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function add_task_to_plan( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
		$task_title = isset( $arguments['task_title'] ) ? sanitize_text_field( $arguments['task_title'] ) : '';

		if ( ! $care_plan_id || ! $task_title ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Care plan ID and task title are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$task_id = 'task_' . time() . '_' . wp_rand( 100, 999 );
		$task = array(
			'id'          => $task_id,
			'title'       => $task_title,
			'description' => isset( $arguments['task_description'] ) ? wp_kses_post( $arguments['task_description'] ) : '',
			'due_date'    => isset( $arguments['task_due_date'] ) ? sanitize_text_field( $arguments['task_due_date'] ) : '',
			'status'      => 'pending',
			'created_at'  => current_time( 'mysql' ),
		);

		$plans[ $care_plan_id ]['tasks'][] = $task;
		$plans[ $care_plan_id ]['last_updated'] = current_time( 'mysql' );
		update_option( $plans_key, $plans );

		return array(
			'success'      => true,
			'message'      => __( 'Task added to care plan successfully.', 'mcp-ai-wpoos-pro' ),
			'care_plan_id' => $care_plan_id,
			'task_id'      => $task_id,
			'task'         => $task,
		);
	}

	/**
	 * Complete a task.
	 *
	 * @param array $arguments      Tool arguments.
	 * @param int   $member_id      Member ID.
	 * @param int   $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function complete_task( $arguments, $member_id, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to complete tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		$care_plan_id = isset( $arguments['care_plan_id'] ) ? sanitize_text_field( $arguments['care_plan_id'] ) : '';
		$task_id = isset( $arguments['task_id'] ) ? sanitize_text_field( $arguments['task_id'] ) : '';

		if ( ! $care_plan_id || ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Care plan ID and task ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans_key = 'wp_mcp_ai_care_plans_' . $member_id;
		$plans = get_option( $plans_key, array() );

		if ( ! isset( $plans[ $care_plan_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_care_plan_not_found', __( 'Care plan not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Find and complete task.
		$found = false;
		foreach ( $plans[ $care_plan_id ]['tasks'] as &$task ) {
			if ( $task['id'] === $task_id ) {
				$task['status'] = 'completed';
				$task['completed_at'] = current_time( 'mysql' );
				$task['completed_by'] = $current_user_id;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'wp_mcp_ai_task_not_found', __( 'Task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$plans[ $care_plan_id ]['last_updated'] = current_time( 'mysql' );
		update_option( $plans_key, $plans );

		return array(
			'success' => true,
			'message' => __( 'Task completed successfully.', 'mcp-ai-wpoos-pro' ),
			'task'    => $task,
		);
	}
}
