<?php
/**
 * Tool: Instantiate Template
 *
 * Creates a task plan from a template by replacing placeholders with actual values
 *
 * @package MCP_AI_WP_OOS_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantiate Template Tool Class
 */
class WP_MCP_AI_Pro_Tool_Instantiate_Template {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'instantiate_template';
	}

	/**
	 * Get tool definition for AI
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'         => 'instantiate_template',
			'description'  => 'Creates a task plan from a template by replacing placeholders with actual values. This allows reusing proven workflows by just changing the variables.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'template_id'      => array(
						'type'        => 'number',
						'description' => 'ID of the template to instantiate',
					),
					'variables'        => array(
						'type'        => 'object',
						'description' => 'Key-value pairs to replace template placeholders (e.g., {"goal": "Research AI trends", "topic": "Machine Learning", "count": "10"})',
					),
					'config_overrides' => array(
						'type'        => 'object',
						'description' => 'Override default configuration values',
						'properties'  => array(
							'max_iterations' => array(
								'type'        => 'number',
								'description' => 'Max iterations (overrides template default)',
							),
							'token_budget'   => array(
								'type'        => 'number',
								'description' => 'Token budget (overrides template default)',
							),
						),
					),
					'project_id'       => array(
						'type'        => 'number',
						'description' => 'Optional project ID to link task plan to',
					),
				),
				'required'   => array( 'template_id', 'variables' ),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Extract arguments.
		$template_id      = absint( $arguments['template_id'] ?? 0 );
		$variables        = $arguments['variables'] ?? array();
		$config_overrides = $arguments['config_overrides'] ?? array();
		$project_id       = absint( $arguments['project_id'] ?? 0 );

		// Validate template_id.
		if ( ! $template_id ) {
			return array(
				'success' => false,
				'error'   => 'template_id is required',
			);
		}

		// Check if we should use CCT or CPT.
		$use_cct = $this->should_use_cct();

		// Load template.
		if ( $use_cct ) {
			$handler = WP_MCP_AI_Task_Templates_CCT::get_item_handler();
			if ( ! $handler ) {
				return array(
					'success' => false,
					'error'   => 'CCT handler not available',
				);
			}

			$template = $handler->get_item( $template_id );
			if ( ! $template ) {
				return array(
					'success' => false,
					'error'   => 'Template not found',
				);
			}

			$template_name     = $template['template_name'] ?? '';
			$markdown_template = $template['markdown_template'] ?? '';
			$default_config    = json_decode( $template['default_config'] ?? '{}', true );
		} else {
			$template = get_post( $template_id );
			if ( ! $template || 'mcp_task_template' !== $template->post_type ) {
				return array(
					'success' => false,
					'error'   => 'Template not found',
				);
			}

			$template_name     = $template->post_title;
			$markdown_template = $template->post_content;
			$default_config    = get_post_meta( $template_id, 'default_config', true ) ?: array();
		}

		// Replace placeholders.
		$markdown_content = $markdown_template;
		foreach ( $variables as $key => $value ) {
			$placeholder      = '{{' . $key . '}}';
			$markdown_content = str_replace( $placeholder, $value, $markdown_content );
		}

		// Check for unreplaced placeholders.
		preg_match_all( '/\{\{(\w+)\}\}/', $markdown_content, $matches );
		$unreplaced_placeholders = array_unique( $matches[1] ?? array() );

		if ( ! empty( $unreplaced_placeholders ) ) {
			return array(
				'success'                 => false,
				'error'                   => 'Missing required variables: ' . implode( ', ', $unreplaced_placeholders ),
				'unreplaced_placeholders' => $unreplaced_placeholders,
			);
		}

		// Merge configurations.
		$final_config = array_merge( $default_config, $config_overrides );

		// Extract goal from first line or use template name.
		$lines = explode( "\n", $markdown_content );
		$goal  = '';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! empty( $line ) && ! str_starts_with( $line, '#' ) ) {
				$goal = $line;
				break;
			}
			if ( str_starts_with( $line, '#' ) ) {
				$goal = ltrim( $line, '# ' );
				break;
			}
		}
		if ( empty( $goal ) ) {
			$goal = $template_name . ' - Instantiated';
		}

		// Parse tasks to count them.
		$task_count = 0;
		foreach ( explode( "\n", $markdown_content ) as $line ) {
			if ( preg_match( '/^- \[([ x])\] /', $line ) ) {
				++$task_count;
			}
		}

		// Create task plan.
		if ( $use_cct ) {
			$plan_handler = WP_MCP_AI_Task_Plans_CCT::get_item_handler();
			if ( ! $plan_handler ) {
				return array(
					'success' => false,
					'error'   => 'Task Plans CCT handler not available',
				);
			}

			$plan_data = array(
				'goal'             => $goal,
				'markdown_content' => $markdown_content,
				'status'           => 'active',
				'task_count'       => $task_count,
				'completed_count'  => 0,
				'progress'         => 0,
				'tasks_parsed'     => '',
				'template_id'      => $template_id,
				'project_id'       => $project_id,
				'completed_at'     => null,
				'metadata'         => wp_json_encode( array( 'instantiated_from' => $template_id ) ),
			);

			$plan_id = $plan_handler->update_item( null, $plan_data );
		} else {
			$post_data = array(
				'post_title'   => $goal,
				'post_content' => $markdown_content,
				'post_status'  => 'publish',
				'post_type'    => 'mcp_task_plan',
				'post_author'  => get_current_user_id() ?: 1,
			);

			$plan_id = wp_insert_post( $post_data );

			if ( is_wp_error( $plan_id ) ) {
				return array(
					'success' => false,
					'error'   => $plan_id->get_error_message(),
				);
			}

			// Store meta data.
			update_post_meta( $plan_id, 'status', 'active' );
			update_post_meta( $plan_id, 'task_count', $task_count );
			update_post_meta( $plan_id, 'completed_count', 0 );
			update_post_meta( $plan_id, 'progress', 0 );
			update_post_meta( $plan_id, 'template_id', $template_id );
			if ( $project_id ) {
				update_post_meta( $plan_id, 'project_id', $project_id );
			}
		}

		// Increment template usage count.
		if ( $use_cct ) {
			$usage_count = intval( $template['usage_count'] ?? 0 ) + 1;
			$handler->update_item( $template_id, array( 'usage_count' => $usage_count ) );
		} else {
			$usage_count = intval( get_post_meta( $template_id, 'usage_count', true ) ?: 0 ) + 1;
			update_post_meta( $template_id, 'usage_count', $usage_count );
		}

		return array(
			'success'       => true,
			'plan_id'       => $plan_id,
			'template_id'   => $template_id,
			'template_name' => $template_name,
			'goal'          => $goal,
			'task_count'    => $task_count,
			'config'        => $final_config,
			'storage_type'  => $use_cct ? 'cct' : 'cpt',
			'usage_count'   => $usage_count,
			'message'       => 'Task plan created from template successfully. Use this plan_id to start an autonomous session.',
		);
	}

	/**
	 * Check if should use CCT
	 *
	 * @return bool
	 */
	private function should_use_cct() {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Task_Templates_CCT' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_project_settings', array() );
		return ! empty( $settings['use_cct_storage'] );
	}
}
