<?php
/**
 * Workflow Slash Command
 *
 * Execute and manage custom automation workflows.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow Command Class
 *
 * Executes YAML-defined workflows with support for:
 * - Sequential step execution
 * - Context passing between steps
 * - Integration with existing commands
 * - Error handling and recovery
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Slash_Command_Workflow {

	/**
	 * Execute workflow command
	 *
	 * @param array $args    Positional arguments (workflow name).
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|WP_Error Command result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check permissions.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to execute workflows.', 'mcp-ai-wpoos' )
			);
		}

		// Parse arguments.
		$workflow_name = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : null;

		// Parse flags.
		$action    = isset( $flags['action'] ) ? sanitize_text_field( $flags['action'] ) : 'run';
		$dry_run   = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$list_only = isset( $flags['list'] ) || isset( $flags['l'] );
		$show_def  = isset( $flags['show'] ) || isset( $flags['s'] );

		// List workflows.
		if ( $list_only || 'list' === $action ) {
			return $this->list_workflows();
		}

		// Show workflow definition.
		if ( $show_def && $workflow_name ) {
			return $this->show_workflow( $workflow_name );
		}

		// Execute workflow.
		if ( ! $workflow_name ) {
			return new WP_Error(
				'missing_workflow_name',
				__( 'Please specify a workflow name. Use --list to see available workflows.', 'mcp-ai-wpoos' )
			);
		}

		// Load workflow definition.
		$workflow = $this->load_workflow( $workflow_name );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		// Execute workflow.
		$result = $this->execute_workflow( $workflow, $dry_run, $context );

		return $this->format_response( $workflow_name, $result, $dry_run );
	}

	/**
	 * List available workflows
	 *
	 * @return string Formatted workflow list.
	 */
	private function list_workflows() {
		$workflows = $this->discover_workflows();

		if ( empty( $workflows ) ) {
			return "## Available Workflows\n\nNo workflows found.\n\n**Tip:** Create workflow files in `/wp-content/uploads/mcp-ai/workflows/` or use built-in templates.";
		}

		$output = "## Available Workflows\n\n";

		foreach ( $workflows as $name => $info ) {
			$output .= sprintf(
				"### %s\n\n**Description:** %s\n\n**Usage:** `/workflow %s`\n\n",
				esc_html( $info['title'] ),
				esc_html( $info['description'] ),
				esc_html( $name )
			);

			if ( ! empty( $info['steps'] ) ) {
				$output .= sprintf( "**Steps:** %d\n\n", count( $info['steps'] ) );
			}
		}

		return $output;
	}

	/**
	 * Show workflow definition
	 *
	 * @param string $workflow_name Workflow name.
	 * @return string|WP_Error Workflow definition or error.
	 */
	private function show_workflow( $workflow_name ) {
		$workflow = $this->load_workflow( $workflow_name );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		$output = sprintf( "## Workflow: %s\n\n", esc_html( $workflow['name'] ) );

		if ( ! empty( $workflow['description'] ) ) {
			$output .= sprintf( "**Description:** %s\n\n", esc_html( $workflow['description'] ) );
		}

		if ( ! empty( $workflow['trigger'] ) ) {
			$output .= "**Trigger:** ";
			if ( isset( $workflow['trigger']['schedule'] ) ) {
				$output .= sprintf( "Schedule: %s\n", esc_html( $workflow['trigger']['schedule'] ) );
			} elseif ( isset( $workflow['trigger']['event'] ) ) {
				$output .= sprintf( "Event: %s\n", esc_html( $workflow['trigger']['event'] ) );
			}
			$output .= "\n";
		}

		$output .= "**Steps:**\n\n";

		foreach ( $workflow['steps'] as $index => $step ) {
			$step_num = $index + 1;
			$output .= sprintf(
				"%d. **%s**\n",
				$step_num,
				esc_html( $step['task'] )
			);

			if ( ! empty( $step['params'] ) ) {
				$output .= "   - Parameters: `" . wp_json_encode( $step['params'] ) . "`\n";
			}

			$output .= "\n";
		}

		return $output;
	}

	/**
	 * Discover available workflows
	 *
	 * @return array Workflow list.
	 */
	private function discover_workflows() {
		$workflows = array();

		// Load built-in templates.
		$templates = $this->get_builtin_templates();
		foreach ( $templates as $name => $template ) {
			$workflows[ $name ] = array(
				'title'       => $template['name'],
				'description' => $template['description'],
				'steps'       => isset( $template['steps'] ) ? $template['steps'] : array(),
				'source'      => 'builtin',
			);
		}

		// Load custom workflows from uploads directory.
		$upload_dir = wp_upload_dir();
		$workflow_dir = $upload_dir['basedir'] . '/mcp-ai/workflows';

		if ( is_dir( $workflow_dir ) ) {
			$files = glob( $workflow_dir . '/*.{yml,yaml}', GLOB_BRACE );

			foreach ( $files as $file ) {
				$name = basename( $file, '.yml' );
				$name = basename( $name, '.yaml' );

				try {
					$content = file_get_contents( $file );
					$workflow = yaml_parse( $content );

					if ( $workflow && isset( $workflow['name'] ) ) {
						$workflows[ $name ] = array(
							'title'       => $workflow['name'],
							'description' => isset( $workflow['description'] ) ? $workflow['description'] : '',
							'steps'       => isset( $workflow['steps'] ) ? $workflow['steps'] : array(),
							'source'      => 'custom',
						);
					}
				} catch ( Exception $e ) {
					// Skip invalid files.
					continue;
				}
			}
		}

		return $workflows;
	}

	/**
	 * Load workflow definition
	 *
	 * @param string $workflow_name Workflow name.
	 * @return array|WP_Error Workflow definition or error.
	 */
	private function load_workflow( $workflow_name ) {
		// Check built-in templates first.
		$templates = $this->get_builtin_templates();

		if ( isset( $templates[ $workflow_name ] ) ) {
			return $templates[ $workflow_name ];
		}

		// Load from custom workflows.
		$upload_dir = wp_upload_dir();
		$workflow_dir = $upload_dir['basedir'] . '/mcp-ai/workflows';
		$file_yml = $workflow_dir . '/' . $workflow_name . '.yml';
		$file_yaml = $workflow_dir . '/' . $workflow_name . '.yaml';

		$file = file_exists( $file_yml ) ? $file_yml : ( file_exists( $file_yaml ) ? $file_yaml : null );

		if ( ! $file ) {
			return new WP_Error(
				'workflow_not_found',
				sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" not found. Use --list to see available workflows.', 'mcp-ai-wpoos' ),
					esc_html( $workflow_name )
				)
			);
		}

		try {
			$content = file_get_contents( $file );
			$workflow = yaml_parse( $content );

			if ( ! $workflow ) {
				return new WP_Error(
					'workflow_parse_error',
					__( 'Failed to parse workflow file.', 'mcp-ai-wpoos' )
				);
			}

			// Validate workflow.
			$validation = $this->validate_workflow( $workflow );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			return $workflow;
		} catch ( Exception $e ) {
			return new WP_Error(
				'workflow_load_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Error loading workflow: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Validate workflow definition
	 *
	 * @param array $workflow Workflow definition.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	private function validate_workflow( $workflow ) {
		// Check required fields.
		if ( empty( $workflow['name'] ) ) {
			return new WP_Error(
				'workflow_validation_error',
				__( 'Workflow must have a "name" field.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return new WP_Error(
				'workflow_validation_error',
				__( 'Workflow must have a "steps" array.', 'mcp-ai-wpoos' )
			);
		}

		// Validate each step.
		foreach ( $workflow['steps'] as $index => $step ) {
			if ( empty( $step['task'] ) ) {
				return new WP_Error(
					'workflow_validation_error',
					sprintf(
						/* translators: %d: step number */
						__( 'Step %d is missing "task" field.', 'mcp-ai-wpoos' ),
						$index + 1
					)
				);
			}
		}

		return true;
	}

	/**
	 * Execute workflow
	 *
	 * @param array $workflow Workflow definition.
	 * @param bool  $dry_run  Dry run mode.
	 * @param array $context  Execution context.
	 * @return array Execution results.
	 */
	private function execute_workflow( $workflow, $dry_run, $context ) {
		$results = array(
			'steps_completed' => 0,
			'steps_failed'    => 0,
			'step_results'    => array(),
			'context'         => array(),
		);

		// Execute each step sequentially.
		foreach ( $workflow['steps'] as $index => $step ) {
			$step_num = $index + 1;

			if ( $dry_run ) {
				$results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => $step['task'],
					'status'  => 'skipped',
					'message' => __( 'Dry run - step not executed', 'mcp-ai-wpoos' ),
				);
				continue;
			}

			// Execute step.
			$step_result = $this->execute_step( $step, $results['context'], $context );

			if ( is_wp_error( $step_result ) ) {
				$results['steps_failed']++;
				$results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => $step['task'],
					'status'  => 'failed',
					'error'   => $step_result->get_error_message(),
				);

				// Stop on failure unless continue_on_error is set.
				if ( empty( $step['continue_on_error'] ) ) {
					break;
				}
			} else {
				$results['steps_completed']++;
				$results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => $step['task'],
					'status'  => 'completed',
					'result'  => $step_result,
				);

				// Update context with step results.
				if ( isset( $step['output_var'] ) && is_array( $step_result ) ) {
					$results['context'][ $step['output_var'] ] = $step_result;
				}
			}
		}

		return $results;
	}

	/**
	 * Execute a single workflow step
	 *
	 * @param array $step            Step definition.
	 * @param array $workflow_context Workflow context.
	 * @param array $execution_context Execution context.
	 * @return mixed|WP_Error Step result or error.
	 */
	private function execute_step( $step, $workflow_context, $execution_context ) {
		$task = $step['task'];
		$params = isset( $step['params'] ) ? $step['params'] : array();

		// Replace context variables in params.
		$params = $this->replace_context_vars( $params, $workflow_context );

		// Map task to slash command or tool.
		switch ( $task ) {
			case 'next-task':
			case 'check_drafts':
			case 'audit_drafts':
				return $this->execute_command( 'next-task', $params, $execution_context );

			case 'ship':
			case 'publish_post':
				return $this->execute_command( 'ship', $params, $execution_context );

			case 'clean-content':
			case 'check_content':
				return $this->execute_command( 'clean-content', $params, $execution_context );

			case 'optimize-perf':
			case 'check_performance':
				return $this->execute_command( 'optimize-perf', $params, $execution_context );

			case 'sync-docs':
			case 'check_docs':
				return $this->execute_command( 'sync-docs', $params, $execution_context );

			case 'notify_admin':
			case 'send_email':
				return $this->send_notification( $params );

			case 'wait':
			case 'sleep':
				$seconds = isset( $params['seconds'] ) ? absint( $params['seconds'] ) : 1;
				sleep( min( $seconds, 30 ) ); // Max 30 seconds.
				return array( 'waited' => $seconds );

			default:
				return new WP_Error(
					'unknown_task',
					sprintf(
						/* translators: %s: task name */
						__( 'Unknown task: %s', 'mcp-ai-wpoos' ),
						$task
					)
				);
		}
	}

	/**
	 * Execute a slash command
	 *
	 * @param string $command Command name.
	 * @param array  $params  Command parameters.
	 * @param array  $context Execution context.
	 * @return mixed|WP_Error Command result or error.
	 */
	private function execute_command( $command, $params, $context ) {
		// Get command handler.
		$handler = wp_mcp_ai_get_slash_command_handler();

		if ( ! $handler ) {
			return new WP_Error(
				'no_command_handler',
				__( 'Command handler not available.', 'mcp-ai-wpoos' )
			);
		}

		// Build command string.
		$command_str = '/' . $command;
		foreach ( $params as $key => $value ) {
			if ( is_bool( $value ) ) {
				if ( $value ) {
					$command_str .= ' --' . $key;
				}
			} else {
				$command_str .= ' --' . $key . '=' . $value;
			}
		}

		// Execute command.
		return $handler->execute( $command_str, $context );
	}

	/**
	 * Send notification
	 *
	 * @param array $params Notification parameters.
	 * @return array Notification result.
	 */
	private function send_notification( $params ) {
		$channel = isset( $params['channel'] ) ? $params['channel'] : 'email';
		$message = isset( $params['message'] ) ? $params['message'] : '';

		if ( 'email' === $channel ) {
			$admin_email = get_option( 'admin_email' );
			$subject = isset( $params['subject'] ) ? $params['subject'] : __( 'Workflow Notification', 'mcp-ai-wpoos' );

			$sent = wp_mail( $admin_email, $subject, $message );

			return array(
				'sent'    => $sent,
				'channel' => 'email',
				'to'      => $admin_email,
			);
		}

		return array(
			'sent'    => false,
			'channel' => $channel,
			'message' => __( 'Unsupported notification channel', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Replace context variables in parameters
	 *
	 * @param mixed $value   Value to process.
	 * @param array $context Workflow context.
	 * @return mixed Processed value.
	 */
	private function replace_context_vars( $value, $context ) {
		if ( is_string( $value ) ) {
			// Replace {{variable}} with context value.
			preg_match_all( '/\{\{([^}]+)\}\}/', $value, $matches );

			foreach ( $matches[1] as $var_name ) {
				$var_name = trim( $var_name );
				if ( isset( $context[ $var_name ] ) ) {
					$value = str_replace( '{{' . $var_name . '}}', $context[ $var_name ], $value );
				}
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->replace_context_vars( $item, $context );
			}
		}

		return $value;
	}

	/**
	 * Get built-in workflow templates
	 *
	 * @return array Template definitions.
	 */
	private function get_builtin_templates() {
		return array(
			'daily-review' => array(
				'name'        => 'Daily Content Review',
				'description' => 'Review draft posts and perform basic checks',
				'steps'       => array(
					array(
						'task'   => 'next-task',
						'params' => array(
							'type'    => 'drafts',
							'limit'   => 5,
							'dry-run' => true,
						),
					),
					array(
						'task'   => 'clean-content',
						'params' => array(
							'limit'   => 5,
							'dry-run' => true,
						),
					),
				),
			),
			'publish-ready' => array(
				'name'        => 'Check and Publish Ready Posts',
				'description' => 'Find draft posts ready to publish and ship them',
				'steps'       => array(
					array(
						'task'   => 'next-task',
						'params' => array(
							'type'  => 'drafts',
							'limit' => 3,
						),
					),
					array(
						'task'   => 'ship',
						'params' => array(
							'publish' => true,
						),
					),
				),
			),
			'site-health' => array(
				'name'        => 'Site Health Check',
				'description' => 'Comprehensive site health and performance check',
				'steps'       => array(
					array(
						'task'   => 'optimize-perf',
						'params' => array(
							'phases'  => '1,2,3,5',
							'dry-run' => true,
						),
					),
					array(
						'task'   => 'clean-content',
						'params' => array(
							'phase'   => 1,
							'limit'   => 10,
							'dry-run' => true,
						),
					),
					array(
						'task'   => 'sync-docs',
						'params' => array(
							'type'    => 'posts',
							'dry-run' => true,
						),
					),
				),
			),
		);
	}

	/**
	 * Format command response
	 *
	 * @param string $workflow_name Workflow name.
	 * @param array  $results       Execution results.
	 * @param bool   $dry_run       Dry run mode.
	 * @return string Formatted response.
	 */
	private function format_response( $workflow_name, $results, $dry_run ) {
		$output = sprintf( "## Workflow: %s\n\n", esc_html( $workflow_name ) );

		$total_steps = count( $results['step_results'] );

		$output .= sprintf(
			"**Summary:**\n- Total steps: %d\n- Completed: %d\n- Failed: %d\n\n",
			$total_steps,
			$results['steps_completed'],
			$results['steps_failed']
		);

		$output .= "### Step Results\n\n";

		foreach ( $results['step_results'] as $step_result ) {
			$status_icon = array(
				'completed' => '✅',
				'failed'    => '❌',
				'skipped'   => '⏭️',
			);

			$icon = isset( $status_icon[ $step_result['status'] ] ) ? $status_icon[ $step_result['status'] ] : '❓';

			$output .= sprintf(
				"%s **Step %d:** %s (%s)\n",
				$icon,
				$step_result['step'],
				esc_html( $step_result['task'] ),
				esc_html( $step_result['status'] )
			);

			if ( isset( $step_result['error'] ) ) {
				$output .= sprintf( "   - Error: %s\n", esc_html( $step_result['error'] ) );
			} elseif ( isset( $step_result['message'] ) ) {
				$output .= sprintf( "   - %s\n", esc_html( $step_result['message'] ) );
			}

			$output .= "\n";
		}

		if ( $dry_run ) {
			$output .= "\n**Note:** This was a dry run. No changes were made.\n";
		}

		return $output;
	}
}
