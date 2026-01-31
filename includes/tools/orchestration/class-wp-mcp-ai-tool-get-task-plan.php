<?php
/**
 * Tool: Get Task Plan
 *
 * Retrieves a task plan with current status.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Task Plan Tool
 */
class WP_MCP_AI_Tool_Get_Task_Plan {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_task_plan';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'get_task_plan',
			'description'         => 'Retrieve a task plan with current completion status. Use this to check progress during autonomous workflows.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'plan_id'         => array(
						'type'        => 'integer',
						'description' => 'Task plan ID',
					),
					'include_history' => array(
						'type'        => 'boolean',
						'description' => 'Include edit history (default: false)',
						'default'     => false,
					),
				),
				'required'   => array( 'plan_id' ),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		// Validate arguments.
		if ( empty( $arguments['plan_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: plan_id',
			);
		}

		$plan_id = intval( $arguments['plan_id'] );

		// Get plan from storage.
		$plan = $this->get_plan( $plan_id );

		if ( ! $plan ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Task plan #%d not found', $plan_id ),
			);
		}

		// Parse tasks from markdown.
		$parsed = $this->parse_markdown_tasks( $plan['markdown_content'] );

		// Build response.
		$response = array(
			'success'         => true,
			'plan_id'         => $plan_id,
			'plan_name'       => $plan['plan_name'],
			'goal'            => $plan['goal'],
			'task_count'      => $parsed['total'],
			'completed_count' => $parsed['completed'],
			'progress'        => $parsed['progress'],
			'status'          => $plan['status'],
			'tasks'           => $parsed['tasks'],
			'markdown'        => $plan['markdown_content'],
			'created_at'      => $plan['created_at'] ?? null,
			'updated_at'      => $plan['updated_at'] ?? null,
			'completed_at'    => $plan['completed_at'] ?? null,
		);

		// Add project link if available.
		if ( ! empty( $plan['project_id'] ) ) {
			$response['project_id'] = $plan['project_id'];
		}

		return $response;
	}

	/**
	 * Get task plan
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	private function get_plan( $plan_id ) {
		// Try CCT first.
		if ( $this->should_use_cct() ) {
			$handler = $this->get_cct_handler();
			if ( $handler ) {
				$item = $handler->get_item( $plan_id );
				if ( $item ) {
					return $item;
				}
			}
		}

		// Fallback to CPT.
		$post = get_post( $plan_id );
		if ( ! $post || 'mcp_task_plan' !== $post->post_type ) {
			return null;
		}

		return array(
			'plan_name'        => $post->post_title,
			'goal'             => get_post_meta( $post->ID, '_goal', true ),
			'markdown_content' => $post->post_content,
			'task_count'       => get_post_meta( $post->ID, '_task_count', true ),
			'completed_count'  => get_post_meta( $post->ID, '_completed_count', true ),
			'progress'         => get_post_meta( $post->ID, '_progress', true ),
			'status'           => get_post_meta( $post->ID, '_status', true ),
			'project_id'       => get_post_meta( $post->ID, '_project_id', true ),
			'created_at'       => $post->post_date,
			'updated_at'       => $post->post_modified,
			'completed_at'     => get_post_meta( $post->ID, '_completed_at', true ),
		);
	}

	/**
	 * Parse markdown tasks
	 *
	 * @param string $markdown Markdown content.
	 * @return array
	 */
	private function parse_markdown_tasks( $markdown ) {
		$tasks = array();
		$lines = explode( "\n", $markdown );

		foreach ( $lines as $index => $line ) {
			if ( preg_match( '/^- \[([ x])\] (.+)$/', $line, $matches ) ) {
				$text     = $matches[2];
				$priority = null;

				// Extract priority if present.
				if ( preg_match( '/\(Priority: (high|medium|low)\)/i', $text, $priority_match ) ) {
					$priority = strtolower( $priority_match[1] );
					$text     = trim( str_replace( $priority_match[0], '', $text ) );
				}

				$tasks[] = array(
					'index'     => count( $tasks ),
					'completed' => 'x' === $matches[1],
					'text'      => $text,
					'priority'  => $priority,
				);
			}
		}

		$total     = count( $tasks );
		$completed = count(
			array_filter(
				$tasks,
				function ( $task ) {
					return $task['completed'];
				}
			)
		);
		$progress  = $total > 0 ? ( $completed / $total ) * 100 : 0;

		return array(
			'tasks'     => $tasks,
			'total'     => $total,
			'completed' => $completed,
			'progress'  => round( $progress, 1 ),
		);
	}

	/**
	 * Check if we should use CCT
	 *
	 * @return bool
	 */
	private function should_use_cct() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}

	/**
	 * Get CCT handler
	 *
	 * @return object|null
	 */
	private function get_cct_handler() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return null;
		}

		$module = jet_engine()->modules->get_module( 'custom-content-types' );
		if ( ! $module ) {
			return null;
		}

		$instance = $module->manager->get_content_types( 'mcp_task_plans' );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}
}
