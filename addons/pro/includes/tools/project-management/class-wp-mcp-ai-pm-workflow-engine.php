<?php
/**
 * Project Management Toolkit Workflow Engine
 *
 * Simple trigger/condition/action engine that evaluates rules stored as
 * post meta on mcp_ai_pm_workflow_rule CPT posts and executes the
 * resulting actions.  Supports task status transitions, project status
 * changes, assignment updates, and notification dispatching.
 *
 * Dry-run simulation is available for testing rules against historical
 * entities before activation.
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
 * PM workflow engine.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_PM_Workflow_Engine {

	/**
	 * Evaluate all active rules for a given trigger type and entity.
	 *
	 * Queries published mcp_ai_pm_workflow_rule posts whose trigger type
	 * and active flag match, then checks each rule's conditions against
	 * the entity.  When conditions are met, the rule's actions are
	 * executed and a wp_mcp_ai_pm_workflow_trigger action fires.
	 *
	 * @param string $trigger_type Trigger identifier (e.g. 'task.status_changed').
	 * @param int    $entity_id    Post ID of the triggering entity.
	 * @param array  $context      Optional extra context for conditions.
	 * @return int[] IDs of rules that fired.
	 */
	public static function evaluate_rules( $trigger_type, $entity_id, $context = array() ) {
		$rules = get_posts(
			array(
				'post_type'      => 'mcp_ai_pm_workflow_rule',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'meta_query'     => array(
					array(
						'key'   => '_pm_wf_trigger_type',
						'value' => $trigger_type,
					),
					array(
						'key'   => '_pm_wf_active',
						'value' => '1',
					),
				),
			)
		);

		$fired = array();
		foreach ( $rules as $rule ) {
			$conditions_raw = get_post_meta( $rule->ID, '_pm_wf_conditions', true );
			$conditions     = json_decode( $conditions_raw ? $conditions_raw : '[]', true );
			$actions_raw    = get_post_meta( $rule->ID, '_pm_wf_actions', true );
			$actions        = json_decode( $actions_raw ? $actions_raw : '[]', true );

			if ( self::check_conditions( $conditions, $entity_id, $context ) ) {
				self::execute_actions( $actions, $entity_id, $context );
				$fired[] = $rule->ID;

				/**
				 * Fires after a workflow rule is triggered.
				 *
				 * @param int    $rule_id      The workflow rule post ID.
				 * @param string $trigger_type The trigger type that fired.
				 * @param int    $entity_id    The entity post ID.
				 * @param array  $context      Additional context.
				 */
				do_action( 'wp_mcp_ai_pm_workflow_trigger', $rule->ID, $trigger_type, $entity_id, $context );
			}
		}

		return $fired;
	}

	/**
	 * Evaluate conditions against an entity.
	 *
	 * An empty conditions array matches everything (default-allow).
	 * Supported operators: equals, not_equals, in.
	 *
	 * @param array $conditions List of condition dicts.
	 * @param int   $entity_id  Post ID to evaluate against.
	 * @param array $context    Extra context.
	 * @return bool True if all conditions pass.
	 */
	private static function check_conditions( $conditions, $entity_id, $context ) {
		if ( empty( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $cond ) {
			$field    = isset( $cond['field'] ) ? $cond['field'] : '';
			$operator = isset( $cond['operator'] ) ? $cond['operator'] : 'equals';
			$value    = isset( $cond['value'] ) ? $cond['value'] : '';

			$actual = '';
			if ( 'project_category' === $field ) {
				$terms  = wp_get_post_terms( $entity_id, 'mcp_ai_project_category', array( 'fields' => 'slugs' ) );
				$actual = isset( $terms[0] ) ? $terms[0] : '';
			} elseif ( 'task_priority' === $field ) {
				$actual = (string) get_post_meta( $entity_id, '_task_priority', true );
			} elseif ( 'task_status' === $field ) {
				$actual = (string) get_post_meta( $entity_id, '_task_status', true );
			} elseif ( 'project_status' === $field ) {
				$actual = (string) get_post_meta( $entity_id, '_project_status', true );
			} elseif ( 'assignee' === $field ) {
				$actual = (string) get_post_meta( $entity_id, '_task_assigned_to', true );
			}

			switch ( $operator ) {
				case 'equals':
					if ( $actual !== $value ) {
						return false;
					}
					break;
				case 'not_equals':
					if ( $actual === $value ) {
						return false;
					}
					break;
				case 'in':
					if ( ! in_array( $actual, (array) $value, true ) ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Execute actions for a matched rule.
	 *
	 * Supported action types: update_task_status, update_project_status,
	 * send_notification, assign_task.
	 *
	 * @param array $actions   List of action dicts.
	 * @param int   $entity_id Post ID to act upon.
	 * @param array $context   Extra context.
	 */
	private static function execute_actions( $actions, $entity_id, $context ) {
		foreach ( $actions as $action ) {
			$type   = isset( $action['type'] ) ? $action['type'] : '';
			$params = isset( $action['params'] ) ? $action['params'] : array();

			switch ( $type ) {
				case 'update_task_status':
					if ( ! empty( $params['status'] ) ) {
						update_post_meta( $entity_id, '_task_status', sanitize_key( $params['status'] ) );
					}
					break;

				case 'update_project_status':
					if ( ! empty( $params['status'] ) ) {
						update_post_meta( $entity_id, '_project_status', sanitize_key( $params['status'] ) );
					}
					break;

				case 'send_notification':
					// Notification handled by WP_MCP_AI_PM_Notification_Manager hooks.
					// The entity update above will fire the relevant actions that
					// the notification manager listens to.
					break;

				case 'assign_task':
					if ( ! empty( $params['user_id'] ) ) {
						update_post_meta( $entity_id, '_task_assigned_to', absint( $params['user_id'] ) );
					}
					break;
			}
		}
	}

	/**
	 * Simulate a rule against historical entities (dry-run).
	 *
	 * Runs the rule's conditions against existing posts without
	 * executing any actions.  Useful for testing before activation.
	 *
	 * @param int $rule_id Workflow rule post ID.
	 * @param int $limit   Maximum entities to scan.
	 * @return array|WP_Error Simulation result, or WP_Error on failure.
	 */
	public static function simulate_rule( $rule_id, $limit = 50 ) {
		$rule = get_post( $rule_id );
		if ( ! $rule || 'mcp_ai_pm_workflow_rule' !== $rule->post_type ) {
			return new WP_Error( 'not_found', __( 'Workflow rule not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$trigger_type = get_post_meta( $rule_id, '_pm_wf_trigger_type', true );
		$conditions_raw = get_post_meta( $rule_id, '_pm_wf_conditions', true );
		$conditions     = json_decode( $conditions_raw ? $conditions_raw : '[]', true );

		// Determine which CPT to query based on trigger.
		$post_type = 'mcp_ai_task';
		if ( strpos( $trigger_type, 'project.' ) === 0 ) {
			$post_type = 'mcp_ai_project';
		}

		$entities = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
			)
		);

		$matches = array();
		foreach ( $entities as $entity ) {
			if ( self::check_conditions( $conditions, $entity->ID, array() ) ) {
				$matches[] = array(
					'id'    => $entity->ID,
					'title' => $entity->post_title,
					'type'  => $post_type,
				);
			}
		}

		return array(
			'rule_id'       => $rule_id,
			'rule_title'    => $rule->post_title,
			'trigger_type'  => $trigger_type,
			'total_scanned' => count( $entities ),
			'total_matches' => count( $matches ),
			'matches'       => $matches,
		);
	}
}
