<?php
/**
 * Workflow Slash Command
 *
 * Execute and manage custom automation workflows.
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
 * Workflow Command Class
 *
 * Executes YAML-defined workflows with support for:
 * - Sequential step execution
 * - Parallel step execution (NEW in 1.2.1)
 * - Conditional branching (NEW in 1.2.1)
 * - Loop control with exit conditions (NEW in 1.2.1)
 * - Step dependencies (DAG) (NEW in 1.2.1)
 * - Context passing between steps
 * - Integration with existing commands
 * - Error handling and recovery
 *
 * @since 1.2.0
 */
class SlashCommandWorkflow {
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
			return new \WP_Error(
				'insufficient_capability',
				__( 'You do not have permission to execute workflows.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse arguments.
		$workflow_name = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : null;

		// Parse flags.
		$action    = isset( $flags['action'] ) ? sanitize_text_field( $flags['action'] ) : 'run';
		$dry_run   = isset( $flags['dry-run'] ) || isset( $flags['n'] );
		$list_only = isset( $flags['list'] ) || isset( $flags['l'] );
		$show_def  = isset( $flags['show'] ) || isset( $flags['s'] );
		$visualize = isset( $flags['visualize'] ) || isset( $flags['v'] );
		$export    = isset( $flags['export'] ) || isset( $flags['e'] );
		$import    = isset( $flags['import'] ) || isset( $flags['i'] );

		// List workflows.
		if ( $list_only || 'list' === $action ) {
			return $this->list_workflows();
		}

		// Export workflow to YAML.
		if ( $export && $workflow_name ) {
			$workflow = $this->load_workflow( $workflow_name );
			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
			return $this->export_workflow( $workflow, $workflow_name );
		}

		// Import workflow from YAML.
		if ( $import ) {
			$yaml_file = isset( $flags['file'] ) ? sanitize_text_field( $flags['file'] ) : null;
			return $this->import_workflow( $yaml_file, $workflow_name );
		}

		// Show workflow definition.
		if ( $show_def && $workflow_name ) {
			return $this->show_workflow( $workflow_name );
		}

		// Visualize workflow structure.
		if ( $visualize && $workflow_name ) {
			$workflow = $this->load_workflow( $workflow_name );
			if ( is_wp_error( $workflow ) ) {
				return $workflow;
			}
			return $this->visualize_workflow( $workflow );
		}

		// Execute workflow.
		if ( ! $workflow_name ) {
			return new \WP_Error(
				'missing_workflow_name',
				__( 'Please specify a workflow name. Use --list to see available workflows.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Load workflow definition.
		$workflow = $this->load_workflow( $workflow_name );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		// Validate user has required capabilities for all workflow steps.
		$capability_check = $this->validate_workflow_capabilities( $workflow, $user_id );
		if ( is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		// Execute workflow with dependency resolution.
		$result = $this->execute_workflow_with_dependencies( $workflow, $dry_run, $context );

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
			$output .= '**Trigger:** ';
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
			$output  .= sprintf(
				"%d. **%s**\n",
				$step_num,
				esc_html( $step['task'] )
			);

			if ( ! empty( $step['params'] ) ) {
				$output .= '   - Parameters: `' . wp_json_encode( $step['params'] ) . "`\n";
			}

			$output .= "\n";
		}

		return $output;
	}

	/**
	 * Visualize workflow structure
	 *
	 * Creates an ASCII visualization of the workflow showing:
	 * - Sequential steps
	 * - Parallel execution blocks
	 * - Conditional branches
	 * - Loop structures
	 * - Step dependencies (DAG)
	 *
	 * @param array $workflow Workflow definition.
	 * @return string Formatted visualization.
	 */
	private function visualize_workflow( $workflow ) {
		$output = sprintf( "## Workflow Visualization: %s\n\n", esc_html( $workflow['name'] ) );

		if ( ! empty( $workflow['description'] ) ) {
			$output .= sprintf( "**Description:** %s\n\n", esc_html( $workflow['description'] ) );
		}

		// Check if workflow has dependencies (DAG).
		if ( $this->has_step_dependencies( $workflow['steps'] ) ) {
			$output .= $this->visualize_dag( $workflow['steps'] );
		} else {
			$output .= $this->visualize_sequential( $workflow['steps'] );
		}

		$output .= "\n**Legend:**\n";
		$output .= "- `→` Sequential flow\n";
		$output .= "- `⇉` Parallel execution\n";
		$output .= "- `↻` Loop\n";
		$output .= "- `⚡` Conditional branch\n";
		$output .= "- `⊕` Merge point (DAG)\n";

		return $output;
	}

	/**
	 * Visualize workflow as sequential diagram
	 *
	 * @param array $steps Workflow steps.
	 * @param int   $indent Indentation level.
	 * @return string Visualization.
	 */
	private function visualize_sequential( $steps, $indent = 0 ) {
		$output = '';
		$prefix = str_repeat( '  ', $indent );

		foreach ( $steps as $index => $step ) {
			$step_num = $index + 1;

			// Parallel block.
			if ( isset( $step['parallel'] ) && is_array( $step['parallel'] ) ) {
				$output .= $prefix . sprintf( "%d. [Parallel Block ⇉]\n", $step_num );
				foreach ( $step['parallel'] as $sub_index => $sub_step ) {
					$task_name = isset( $sub_step['name'] ) ? $sub_step['name'] : $sub_step['task'];
					$output   .= $prefix . sprintf( "   ├─ %s\n", esc_html( $task_name ) );
				}
				continue;
			}

			// Conditional block.
			if ( isset( $step['condition'] ) ) {
				$output .= $prefix . sprintf( "%d. [Conditional ⚡]: %s\n", $step_num, esc_html( $step['condition'] ) );

				if ( isset( $step['then'] ) ) {
					$output    .= $prefix . "   ├─ THEN:\n";
					$then_steps = is_array( $step['then'] ) ? $step['then'] : array( $step['then'] );
					foreach ( $then_steps as $then_step ) {
						$task_name = isset( $then_step['name'] ) ? $then_step['name'] : $then_step['task'];
						$output   .= $prefix . sprintf( "   │  └─ %s\n", esc_html( $task_name ) );
					}
				}

				if ( isset( $step['else'] ) ) {
					$output    .= $prefix . "   └─ ELSE:\n";
					$else_steps = is_array( $step['else'] ) ? $step['else'] : array( $step['else'] );
					foreach ( $else_steps as $else_step ) {
						$task_name = isset( $else_step['name'] ) ? $else_step['name'] : $else_step['task'];
						$output   .= $prefix . sprintf( "      └─ %s\n", esc_html( $task_name ) );
					}
				}
				continue;
			}

			// Loop block.
			if ( isset( $step['repeat_until'] ) || isset( $step['repeat'] ) ) {
				$condition = isset( $step['repeat_until'] ) ? $step['repeat_until'] : 'max iterations';
				$output   .= $prefix . sprintf( "%d. [Loop ↻]: until %s\n", $step_num, esc_html( $condition ) );

				if ( isset( $step['steps'] ) && is_array( $step['steps'] ) ) {
					foreach ( $step['steps'] as $loop_step ) {
						$task_name = isset( $loop_step['name'] ) ? $loop_step['name'] : $loop_step['task'];
						$output   .= $prefix . sprintf( "   ↻─ %s\n", esc_html( $task_name ) );
					}
				}
				continue;
			}

			// Regular step.
			$task_name = isset( $step['name'] ) ? $step['name'] : ( isset( $step['task'] ) ? $step['task'] : 'unknown' );
			$output   .= $prefix . sprintf( "%d. %s →\n", $step_num, esc_html( $task_name ) );
		}

		return $output;
	}

	/**
	 * Visualize workflow as DAG (Directed Acyclic Graph)
	 *
	 * @param array $steps Workflow steps with dependencies.
	 * @return string Visualization.
	 */
	private function visualize_dag( $steps ) {
		$output = "**DAG Structure:**\n\n```\n";

		// Build dependency map.
		$dep_map      = array();
		$reverse_deps = array(); // Track what depends on each step.

		foreach ( $steps as $step ) {
			$name             = $step['name'];
			$deps             = isset( $step['depends_on'] ) ? (array) $step['depends_on'] : array();
			$dep_map[ $name ] = $deps;

			// Build reverse dependencies.
			foreach ( $deps as $dep ) {
				if ( ! isset( $reverse_deps[ $dep ] ) ) {
					$reverse_deps[ $dep ] = array();
				}
				$reverse_deps[ $dep ][] = $name;
			}
		}

		// Find root nodes (no dependencies).
		$roots = array();
		foreach ( $dep_map as $name => $deps ) {
			if ( empty( $deps ) ) {
				$roots[] = $name;
			}
		}

		// Visualize layers.
		$visited = array();
		$layer   = 0;

		$current_layer = $roots;

		while ( ! empty( $current_layer ) ) {
			$output .= sprintf( "Layer %d:\n", $layer );

			foreach ( $current_layer as $node ) {
				$dependents = isset( $reverse_deps[ $node ] ) ? $reverse_deps[ $node ] : array();
				$output    .= sprintf( '  %s', $node );

				if ( ! empty( $dependents ) ) {
					$output .= sprintf( ' → [%s]', implode( ', ', $dependents ) );
				}

				$output          .= "\n";
				$visited[ $node ] = true;
			}

			// Find next layer (nodes whose dependencies are all visited).
			$next_layer = array();
			foreach ( $dep_map as $name => $deps ) {
				if ( isset( $visited[ $name ] ) ) {
					continue;
				}

				$all_deps_visited = true;
				foreach ( $deps as $dep ) {
					if ( ! isset( $visited[ $dep ] ) ) {
						$all_deps_visited = false;
						break;
					}
				}

				if ( $all_deps_visited ) {
					$next_layer[] = $name;
				}
			}

			$current_layer = $next_layer;
			++$layer;

			if ( $layer > 20 ) { // Safety limit.
				break;
			}
		}

		$output .= "```\n\n";

		return $output;
	}

	/**
	 * Export workflow to YAML format
	 *
	 * Creates a YAML file of the workflow definition that can be shared,
	 * versioned, or imported into other sites.
	 *
	 * @param array  $workflow      Workflow definition.
	 * @param string $workflow_name Workflow name.
	 * @return string|WP_Error Export result message or error.
	 */
	private function export_workflow( $workflow, $workflow_name ) {
		// Create export directory if it doesn't exist.
		$upload_dir  = wp_upload_dir();
		$export_dir  = $upload_dir['basedir'] . '/mcp-ai/workflows/exports/';
		$export_file = $export_dir . sanitize_file_name( $workflow_name ) . '-' . gmdate( 'Y-m-d-His' ) . '.yml';

		// Create directory.
		if ( ! file_exists( $export_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- mkdir() used to create a plugin-managed directory; WP_Filesystem is not available in this CLI/cron context.
			wp_mkdir_p( $export_dir );
		}

		// Add metadata to export.
		$export_data = array(
			'metadata' => array(
				'exported_at'    => gmdate( 'Y-m-d H:i:s' ),
				'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0',
				'export_format'  => '1.0',
			),
			'workflow' => $workflow,
		);

		// Export to YAML.
		if ( function_exists( 'yaml_emit' ) ) {
			$yaml_content = yaml_emit( $export_data, YAML_UTF8_ENCODING );
		} else {
			// Fallback to JSON format if YAML not available.
			$yaml_content = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			$export_file  = str_replace( '.yml', '.json', $export_file );
		}

		// Save file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		$result = file_put_contents( $export_file, $yaml_content );

		if ( false === $result ) {
			return new \WP_Error(
				'export_failed',
				__( 'Failed to export workflow. Check file permissions.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$output  = "## Workflow Exported Successfully\n\n";
		$output .= sprintf( "**Workflow:** %s\n", esc_html( $workflow['name'] ) );
		$output .= sprintf( "**File:** `%s`\n", esc_html( basename( $export_file ) ) );
		$output .= sprintf( "**Location:** `%s`\n", esc_html( $export_file ) );
		$output .= sprintf( "**Size:** %s\n", size_format( filesize( $export_file ) ) );
		$output .= sprintf( "**Format:** %s\n\n", function_exists( 'yaml_emit' ) ? 'YAML' : 'JSON' );

		$output .= "### Import Command\n\n";
		$output .= sprintf( "```bash\n/workflow %s --import --file=%s\n```\n", esc_html( $workflow_name ), esc_html( basename( $export_file ) ) );

		return $output;
	}

	/**
	 * Import workflow from YAML/JSON file
	 *
	 * Loads a workflow definition from an exported file and saves it
	 * as a custom workflow.
	 *
	 * @param string $yaml_file     Filename to import.
	 * @param string $workflow_name Workflow name to save as.
	 * @return string|WP_Error Import result message or error.
	 */
	private function import_workflow( $yaml_file, $workflow_name ) {
		if ( empty( $yaml_file ) ) {
			return new \WP_Error(
				'missing_file',
				__( 'Please specify a file to import using --file=filename.yml', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $workflow_name ) ) {
			return new \WP_Error(
				'missing_name',
				__( 'Please specify a workflow name: /workflow <name> --import --file=<file>', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Locate file in exports directory.
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/mcp-ai/workflows/exports/';
		$file_path  = $export_dir . sanitize_file_name( $yaml_file );

		// Check if file exists.
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error(
				'file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'File not found: %s', 'nvoos-content-graph-ai-platform' ),
					$file_path
				)
			);
		}

		// Read file content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin or temp file; WP_Filesystem is not available in this REST/cron/tool execution context.
		$file_content = file_get_contents( $file_path );

		if ( false === $file_content ) {
			return new \WP_Error(
				'read_failed',
				__( 'Failed to read workflow file.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Parse content based on extension.
		$extension = pathinfo( $file_path, PATHINFO_EXTENSION );

		if ( 'yml' === $extension || 'yaml' === $extension ) {
			if ( ! function_exists( 'yaml_parse' ) ) {
				return new \WP_Error(
					'yaml_not_supported',
					__( 'YAML parsing not available. Please use JSON format or install YAML PHP extension.', 'nvoos-content-graph-ai-platform' )
				);
			}
			$import_data = yaml_parse( $file_content );
		} elseif ( 'json' === $extension ) {
			$import_data = json_decode( $file_content, true );
		} else {
			return new \WP_Error(
				'invalid_format',
				__( 'Invalid file format. Use .yml, .yaml, or .json files.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $import_data ) || ! is_array( $import_data ) ) {
			return new \WP_Error(
				'parse_failed',
				__( 'Failed to parse workflow file.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Extract workflow definition.
		$workflow = isset( $import_data['workflow'] ) ? $import_data['workflow'] : $import_data;

		// Validate workflow structure.
		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return new \WP_Error(
				'invalid_workflow',
				__( 'Invalid workflow structure. Missing or invalid steps array.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Save as custom workflow.
		$custom_dir  = $upload_dir['basedir'] . '/mcp-ai/workflows/';
		$custom_file = $custom_dir . sanitize_file_name( $workflow_name ) . '.yml';

		// Create directory.
		if ( ! file_exists( $custom_dir ) ) {
			wp_mkdir_p( $custom_dir );
		}

		// Save workflow.
		if ( function_exists( 'yaml_emit' ) ) {
			$save_content = yaml_emit( $workflow, YAML_UTF8_ENCODING );
		} else {
			$save_content = wp_json_encode( $workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			$custom_file  = str_replace( '.yml', '.json', $custom_file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		$result = file_put_contents( $custom_file, $save_content );

		if ( false === $result ) {
			return new \WP_Error(
				'save_failed',
				__( 'Failed to save imported workflow.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$output  = "## Workflow Imported Successfully\n\n";
		$output .= sprintf( "**Workflow Name:** %s\n", esc_html( $workflow_name ) );
		$output .= sprintf( "**Source File:** `%s`\n", esc_html( basename( $file_path ) ) );
		$output .= sprintf( "**Saved As:** `%s`\n", esc_html( basename( $custom_file ) ) );
		$output .= sprintf( "**Steps:** %d\n\n", count( $workflow['steps'] ) );

		if ( isset( $import_data['metadata'] ) ) {
			$output .= "### Import Metadata\n\n";
			$output .= sprintf( "- **Exported:** %s\n", esc_html( $import_data['metadata']['exported_at'] ?? 'Unknown' ) );
			$output .= sprintf( "- **Plugin Version:** %s\n", esc_html( $import_data['metadata']['plugin_version'] ?? 'Unknown' ) );
			$output .= sprintf( "- **Format:** %s\n\n", esc_html( $import_data['metadata']['export_format'] ?? 'Unknown' ) );
		}

		$output .= "### Usage\n\n";
		$output .= sprintf( "```bash\n/workflow %s\n```\n", esc_html( $workflow_name ) );

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

		// Load orchestrator workflows (built-in multi-step workflows managed by the orchestrator).
		if ( function_exists( 'wp_mcp_ai_get_workflow_orchestrator' ) ) {
			$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
			if ( $orchestrator ) {
				foreach ( $orchestrator->get_workflows() as $slug => $info ) {
					if ( ! isset( $workflows[ $slug ] ) ) {
						$full               = $orchestrator->get_workflow( $slug );
						$workflows[ $slug ] = array(
							'title'       => $info['name'],
							'description' => $info['description'],
							'steps'       => isset( $full['steps'] ) ? $full['steps'] : array(),
							'source'      => 'orchestrator',
						);
					}
				}
			}
		}

		// Load custom workflows from uploads directory.
		$upload_dir   = wp_upload_dir();
		$workflow_dir = $upload_dir['basedir'] . '/mcp-ai/workflows';

		if ( is_dir( $workflow_dir ) ) {
			$files = glob( $workflow_dir . '/*.{yml,yaml}', GLOB_BRACE );

			foreach ( $files as $file ) {
				$name = basename( $file, '.yml' );
				$name = basename( $name, '.yaml' );

				try {
					$content  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
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

		// Check orchestrator workflows (e.g. ai_tool_setup, content_pipeline).
		if ( function_exists( 'wp_mcp_ai_get_workflow_orchestrator' ) ) {
			$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
			if ( $orchestrator ) {
				$orchestrator_workflow = $orchestrator->get_workflow( $workflow_name );
				if ( $orchestrator_workflow ) {
					// Normalise step format: the orchestrator uses 'command' while this
					// slash command expects 'task'. Rename the key so show_workflow() and
					// visualize_workflow() work without further changes.
					if ( ! empty( $orchestrator_workflow['steps'] ) && is_array( $orchestrator_workflow['steps'] ) ) {
						foreach ( $orchestrator_workflow['steps'] as &$step ) {
							if ( isset( $step['command'] ) && ! isset( $step['task'] ) ) {
								$step['task'] = $step['command'];
								unset( $step['command'] );
							}
						}
					}
					return $orchestrator_workflow;
				}
			}
		}

		// Load from custom workflows.
		$upload_dir   = wp_upload_dir();
		$workflow_dir = $upload_dir['basedir'] . '/mcp-ai/workflows';
		$file_yml     = $workflow_dir . '/' . $workflow_name . '.yml';
		$file_yaml    = $workflow_dir . '/' . $workflow_name . '.yaml';

		$file = file_exists( $file_yml ) ? $file_yml : ( file_exists( $file_yaml ) ? $file_yaml : null );

		if ( ! $file ) {
			return new \WP_Error(
				'workflow_not_found',
				sprintf(
					/* translators: %s: workflow name */
					__( 'Workflow "%s" not found. Use --list to see available workflows.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $workflow_name )
				)
			);
		}

		try {
			$content  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			$workflow = yaml_parse( $content );

			if ( ! $workflow ) {
				return new \WP_Error(
					'workflow_parse_error',
					__( 'Failed to parse workflow file.', 'nvoos-content-graph-ai-platform' )
				);
			}

			// Validate workflow.
			$validation = $this->validate_workflow( $workflow );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			return $workflow;
		} catch ( Exception $e ) {
			return new \WP_Error(
				'workflow_load_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Error loading workflow: %s', 'nvoos-content-graph-ai-platform' ),
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
			return new \WP_Error(
				'workflow_validation_error',
				__( 'Workflow must have a "name" field.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return new \WP_Error(
				'workflow_validation_error',
				__( 'Workflow must have a "steps" array.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Validate each step.
		foreach ( $workflow['steps'] as $index => $step ) {
			if ( empty( $step['task'] ) ) {
				return new \WP_Error(
					'workflow_validation_error',
					sprintf(
						/* translators: %d: step number */
						__( 'Step %d is missing "task" field.', 'nvoos-content-graph-ai-platform' ),
						$index + 1
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate user has required capabilities for all workflow steps
	 *
	 * @param array $workflow Workflow definition.
	 * @param int   $user_id  User ID to check capabilities for.
	 * @return true|WP_Error True if user has all required capabilities, error otherwise.
	 */
	private function validate_workflow_capabilities( $workflow, $user_id ) {
		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			return true;
		}

		$missing_capabilities = array();

		foreach ( $workflow['steps'] as $step ) {
			if ( empty( $step['task'] ) ) {
				continue;
			}

			$required_capability = $this->get_task_required_capability( $step['task'] );

			if ( $required_capability && ! user_can( $user_id, $required_capability ) ) {
				$missing_capabilities[ $step['task'] ] = $required_capability;
			}
		}

		if ( ! empty( $missing_capabilities ) ) {
			$task_list = array();
			foreach ( $missing_capabilities as $task => $capability ) {
				$task_list[] = sprintf( '%s (requires %s)', $task, $capability );
			}

			return new \WP_Error(
				'insufficient_workflow_permissions',
				sprintf(
					/* translators: %s: list of tasks and required capabilities */
					__( 'You do not have sufficient permissions to execute this workflow. The following tasks require higher privileges: %s', 'nvoos-content-graph-ai-platform' ),
					implode( ', ', $task_list )
				)
			);
		}

		return true;
	}

	/**
	 * Get required capability for a task
	 *
	 * Maps workflow task names to their required WordPress capabilities.
	 *
	 * Task Naming Convention:
	 * - Primary task names use kebab-case (e.g., 'optimize-perf', 'clean-content')
	 * - Descriptive aliases use snake_case (e.g., 'check_performance', 'publish_post')
	 * This allows both slash-command-style and function-style naming.
	 *
	 * @param string $task Task name.
	 * @return string|null Required capability or null if none required.
	 */
	private function get_task_required_capability( $task ) {
		// Map tasks to their required capabilities based on the slash command they call.
		$task_capabilities = array(
			'next-task'         => 'edit_posts',
			'check_drafts'      => 'edit_posts',
			'audit_drafts'      => 'edit_posts',
			'ship'              => 'publish_posts',
			'publish_post'      => 'publish_posts',
			'clean-content'     => 'edit_posts',
			'check_content'     => 'edit_posts',
			'optimize-perf'     => 'manage_options',
			'check_performance' => 'manage_options',
			'sync-docs'         => 'edit_posts',
			'check_docs'        => 'edit_posts',
			'notify_admin'      => 'edit_posts',
			'send_email'        => 'edit_posts',
			'wait'              => null,
			'sleep'             => null,
		);

		return isset( $task_capabilities[ $task ] ) ? $task_capabilities[ $task ] : null;
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
		$workflow_start_time = microtime( true );

		$results = array(
			'steps_completed' => 0,
			'steps_failed'    => 0,
			'step_results'    => array(),
			'context'         => array(),
			'metrics'         => array(
				'start_time'      => $workflow_start_time,
				'total_duration'  => 0,
				'steps_executed'  => 0,
				'parallel_blocks' => 0,
				'loop_iterations' => 0,
			),
		);

		// Execute each step sequentially or in parallel.
		foreach ( $workflow['steps'] as $index => $step ) {
			$step_num = $index + 1;

			// Check if this is a conditional execution block.
			if ( isset( $step['condition'] ) ) {
				$condition_met = $this->evaluate_condition( $step['condition'], $results['context'] );

				// Execute 'then' branch if condition is true.
				if ( $condition_met && isset( $step['then'] ) ) {
					$branch_steps  = is_array( $step['then'] ) ? $step['then'] : array( $step['then'] );
					$branch_result = $this->execute_branch_steps(
						$branch_steps,
						$results['context'],
						$context,
						$dry_run,
						$step_num . '.then'
					);

					// Merge branch results.
					$results['steps_completed'] += $branch_result['completed'];
					$results['steps_failed']    += $branch_result['failed'];
					$results['step_results']     = array_merge( $results['step_results'], $branch_result['step_results'] );
					$results['context']          = array_merge( $results['context'], $branch_result['context'] );

					// Record condition evaluation.
					$results['step_results'][] = array(
						'step'    => $step_num,
						'task'    => 'conditional',
						'status'  => 'completed',
						'message' => sprintf(
							/* translators: %s: condition expression */
							__( 'Condition met: %s (executed then branch)', 'nvoos-content-graph-ai-platform' ),
							$step['condition']
						),
					);
				} elseif ( ! $condition_met && isset( $step['else'] ) ) {
					$branch_steps  = is_array( $step['else'] ) ? $step['else'] : array( $step['else'] );
					$branch_result = $this->execute_branch_steps(
						$branch_steps,
						$results['context'],
						$context,
						$dry_run,
						$step_num . '.else'
					);

					// Merge branch results.
					$results['steps_completed'] += $branch_result['completed'];
					$results['steps_failed']    += $branch_result['failed'];
					$results['step_results']     = array_merge( $results['step_results'], $branch_result['step_results'] );
					$results['context']          = array_merge( $results['context'], $branch_result['context'] );

					// Record condition evaluation.
					$results['step_results'][] = array(
						'step'    => $step_num,
						'task'    => 'conditional',
						'status'  => 'completed',
						'message' => sprintf(
							/* translators: %s: condition expression */
							__( 'Condition not met: %s (executed else branch)', 'nvoos-content-graph-ai-platform' ),
							$step['condition']
						),
					);
				} else {
					$results['step_results'][] = array(
						'step'    => $step_num,
						'task'    => 'conditional',
						'status'  => 'skipped',
						'message' => sprintf(
							/* translators: %s: condition expression */
							__( 'Condition %1$s: %2$s (no branch available)', 'nvoos-content-graph-ai-platform' ),
							$condition_met ? 'met' : 'not met',
							$step['condition']
						),
					);
				}

				continue;
			}

			// Check if this is a parallel execution block.
			if ( isset( $step['parallel'] ) && is_array( $step['parallel'] ) ) {
				$parallel_result = $this->execute_parallel_steps(
					$step['parallel'],
					$results['context'],
					$context,
					$dry_run,
					$step_num,
					isset( $step['continue_on_error'] ) ? $step['continue_on_error'] : false
				);

				// Merge parallel results into main results.
				$results['steps_completed'] += $parallel_result['completed'];
				$results['steps_failed']    += $parallel_result['failed'];
				$results['step_results']     = array_merge( $results['step_results'], $parallel_result['step_results'] );

				// Merge context updates.
				$results['context'] = array_merge( $results['context'], $parallel_result['context'] );

				// Track parallel execution metrics.
				++$results['metrics']['parallel_blocks'];

				// Stop on failure unless continue_on_error is set.
				if ( $parallel_result['failed'] > 0 && empty( $step['continue_on_error'] ) ) {
					break;
				}

				continue;
			}

			// Check if this is a loop execution block.
			if ( isset( $step['repeat_until'] ) || isset( $step['repeat'] ) ) {
				$loop_condition = isset( $step['repeat_until'] ) ? $step['repeat_until'] : null;
				$max_iterations = isset( $step['max_iterations'] ) ? absint( $step['max_iterations'] ) : 10;
				$loop_steps     = isset( $step['steps'] ) ? $step['steps'] : array();

				if ( empty( $loop_steps ) ) {
					$results['step_results'][] = array(
						'step'   => $step_num,
						'task'   => 'loop',
						'status' => 'failed',
						'error'  => __( 'Loop block has no steps defined', 'nvoos-content-graph-ai-platform' ),
					);
					continue;
				}

				$loop_result = $this->execute_loop_steps(
					$loop_steps,
					$loop_condition,
					$max_iterations,
					$results['context'],
					$context,
					$dry_run,
					$step_num
				);

				// Merge loop results.
				$results['steps_completed'] += $loop_result['completed'];
				$results['steps_failed']    += $loop_result['failed'];
				$results['step_results']     = array_merge( $results['step_results'], $loop_result['step_results'] );
				$results['context']          = array_merge( $results['context'], $loop_result['context'] );

				// Track loop execution metrics.
				$results['metrics']['loop_iterations'] += $loop_result['iterations'];

				// Add loop summary.
				$results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => 'loop',
					'status'  => $loop_result['failed'] > 0 ? 'completed-with-errors' : 'completed',
					'message' => sprintf(
						/* translators: 1: iterations count, 2: max iterations */
						__( 'Loop completed: %1$d of %2$d iterations', 'nvoos-content-graph-ai-platform' ),
						$loop_result['iterations'],
						$max_iterations
					),
				);

				continue;
			}

			if ( $dry_run ) {
				$results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => isset( $step['task'] ) ? $step['task'] : 'unknown',
					'status'  => 'skipped',
					'message' => __( 'Dry run - step not executed', 'nvoos-content-graph-ai-platform' ),
				);
				continue;
			}

			// Handle named steps with dependencies.
			$step_name = isset( $step['name'] ) ? $step['name'] : null;

			// Execute step.
			$step_result = $this->execute_step( $step, $results['context'], $context );

			if ( is_wp_error( $step_result ) ) {
				++$results['steps_failed'];
				$results['step_results'][] = array(
					'step'   => $step_num,
					'task'   => $step['task'],
					'status' => 'failed',
					'error'  => $step_result->get_error_message(),
					'name'   => $step_name,
				);

				// Stop on failure unless continue_on_error is set.
				if ( empty( $step['continue_on_error'] ) ) {
					break;
				}
			} else {
				++$results['steps_completed'];
				$results['step_results'][] = array(
					'step'   => $step_num,
					'task'   => $step['task'],
					'status' => 'completed',
					'result' => $step_result,
					'name'   => $step_name,
				);

				// Update context with step results.
				// Support both output_var and step name as context keys.
				if ( isset( $step['output_var'] ) && is_array( $step_result ) ) {
					$results['context'][ $step['output_var'] ] = $step_result;
				}
				if ( $step_name && is_array( $step_result ) ) {
					$results['context'][ $step_name ] = $step_result;
				}
			}
		}

		// Calculate final metrics.
		$results['metrics']['total_duration'] = round( microtime( true ) - $workflow_start_time, 2 );
		$results['metrics']['steps_executed'] = $results['steps_completed'] + $results['steps_failed'];

		return $results;
	}

	/**
	 * Check if workflow has named steps with dependencies
	 *
	 * @param array $steps Workflow steps.
	 * @return bool True if steps have dependencies.
	 */
	private function has_step_dependencies( $steps ) {
		foreach ( $steps as $step ) {
			if ( isset( $step['depends_on'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve step dependencies using topological sort
	 *
	 * Converts array of steps with dependencies into execution order.
	 * Uses Kahn's algorithm for topological sorting.
	 *
	 * @param array $steps Array of step definitions with optional depends_on.
	 * @return array|WP_Error Sorted steps or error if circular dependency detected.
	 */
	private function resolve_step_dependencies( $steps ) {
		// Build dependency graph.
		$graph        = array(); // step_name => [dependencies].
		$in_degree    = array(); // step_name => count of dependencies.
		$step_by_name = array(); // step_name => step definition.

		foreach ( $steps as $step ) {
			$step_name = isset( $step['name'] ) ? $step['name'] : null;

			if ( ! $step_name ) {
				return new \WP_Error(
					'workflow_dag_error',
					__( 'All steps must have a "name" field when using depends_on.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$step_by_name[ $step_name ] = $step;
			$dependencies               = isset( $step['depends_on'] ) ? (array) $step['depends_on'] : array();
			$graph[ $step_name ]        = $dependencies;
			$in_degree[ $step_name ]    = count( $dependencies );
		}

		// Topological sort using Kahn's algorithm.
		$sorted = array();
		$queue  = array();

		// Find all nodes with no dependencies.
		foreach ( $in_degree as $name => $degree ) {
			if ( 0 === $degree ) {
				$queue[] = $name;
			}
		}

		while ( ! empty( $queue ) ) {
			$current  = array_shift( $queue );
			$sorted[] = $step_by_name[ $current ];

			// Reduce in-degree for dependent steps.
			foreach ( $graph as $name => $dependencies ) {
				if ( in_array( $current, $dependencies, true ) ) {
					--$in_degree[ $name ];
					if ( 0 === $in_degree[ $name ] ) {
						$queue[] = $name;
					}
				}
			}
		}

		// Check for circular dependencies.
		if ( count( $sorted ) !== count( $steps ) ) {
			return new \WP_Error(
				'workflow_circular_dependency',
				__( 'Circular dependency detected in workflow steps.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $sorted;
	}

	/**
	 * Execute workflow with dependency resolution
	 *
	 * If workflow has named steps with depends_on, resolve execution order first.
	 *
	 * @param array $workflow Workflow definition.
	 * @param bool  $dry_run  Dry run mode.
	 * @param array $context  Execution context.
	 * @return array Execution results.
	 */
	private function execute_workflow_with_dependencies( $workflow, $dry_run, $context ) {
		// Check if workflow uses dependencies.
		if ( ! $this->has_step_dependencies( $workflow['steps'] ) ) {
			return $this->execute_workflow( $workflow, $dry_run, $context );
		}

		// Resolve dependencies.
		$sorted_steps = $this->resolve_step_dependencies( $workflow['steps'] );

		if ( is_wp_error( $sorted_steps ) ) {
			return array(
				'steps_completed' => 0,
				'steps_failed'    => 1,
				'step_results'    => array(
					array(
						'step'   => 0,
						'task'   => 'dependency-resolution',
						'status' => 'failed',
						'error'  => $sorted_steps->get_error_message(),
					),
				),
				'context'         => array(),
			);
		}

		// Execute with sorted steps.
		$workflow['steps'] = $sorted_steps;
		return $this->execute_workflow( $workflow, $dry_run, $context );
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
		$task   = $step['task'];
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
				return new \WP_Error(
					'unknown_task',
					sprintf(
						/* translators: %s: task name */
						__( 'Unknown task: %s', 'nvoos-content-graph-ai-platform' ),
						$task
					)
				);
		}
	}

	/**
	 * Execute multiple steps in parallel
	 *
	 * Note: Due to PHP's synchronous nature, this executes steps concurrently
	 * using a simulated approach. For true async execution, consider using
	 * WordPress cron or external task queues.
	 *
	 * @param array $steps            Array of step definitions to execute in parallel.
	 * @param array $workflow_context Workflow context.
	 * @param array $execution_context Execution context.
	 * @param bool  $dry_run          Dry run mode.
	 * @param int   $base_step_num    Base step number for reporting.
	 * @param bool  $continue_on_error Continue even if steps fail.
	 * @return array Parallel execution results.
	 */
	private function execute_parallel_steps( $steps, $workflow_context, $execution_context, $dry_run, $base_step_num, $continue_on_error ) {
		$parallel_results = array(
			'completed'    => 0,
			'failed'       => 0,
			'step_results' => array(),
			'context'      => array(),
		);

		// Track start time for timeout enforcement.
		$start_time = microtime( true );

		// Execute each step in the parallel block.
		foreach ( $steps as $sub_index => $step ) {
			$step_num     = $base_step_num . '.' . ( $sub_index + 1 );
			$step_name    = isset( $step['name'] ) ? $step['name'] : ( isset( $step['task'] ) ? $step['task'] : 'parallel-' . $sub_index );
			$step_timeout = isset( $step['timeout'] ) ? absint( $step['timeout'] ) : 60; // Default 60s.

			if ( $dry_run ) {
				$parallel_results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => $step_name,
					'status'  => 'skipped',
					'message' => __( 'Dry run - parallel step not executed', 'nvoos-content-graph-ai-platform' ),
				);
				continue;
			}

			// Check if we've exceeded overall timeout (cumulative for all parallel steps).
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed > 300 ) { // 5 minute hard limit for parallel block.
				++$parallel_results['failed'];
				$parallel_results['step_results'][] = array(
					'step'   => $step_num,
					'task'   => $step_name,
					'status' => 'failed',
					'error'  => __( 'Parallel execution timeout exceeded (5 minutes)', 'nvoos-content-graph-ai-platform' ),
				);
				break;
			}

			// Execute the step with timeout awareness.
			$step_start    = microtime( true );
			$step_result   = $this->execute_step( $step, $workflow_context, $execution_context );
			$step_duration = microtime( true ) - $step_start;

			// Check for timeout (soft limit - step already executed).
			$timed_out = $step_duration > $step_timeout;

			if ( is_wp_error( $step_result ) ) {
				++$parallel_results['failed'];
				$parallel_results['step_results'][] = array(
					'step'     => $step_num,
					'task'     => $step_name,
					'status'   => 'failed',
					'error'    => $step_result->get_error_message(),
					'duration' => round( $step_duration, 2 ),
				);

				// Stop parallel execution on first failure unless continue_on_error.
				if ( ! $continue_on_error ) {
					break;
				}
			} else {
				++$parallel_results['completed'];
				$parallel_results['step_results'][] = array(
					'step'     => $step_num,
					'task'     => $step_name,
					'status'   => $timed_out ? 'completed-timeout' : 'completed',
					'result'   => $step_result,
					'duration' => round( $step_duration, 2 ),
					'warning'  => $timed_out ? sprintf(
						/* translators: 1: step timeout, 2: actual duration */
						__( 'Step exceeded timeout (%1$ds) - took %2$ds', 'nvoos-content-graph-ai-platform' ),
						$step_timeout,
						round( $step_duration, 2 )
					) : null,
				);

				// Update context with step results.
				if ( isset( $step['output_var'] ) && is_array( $step_result ) ) {
					$parallel_results['context'][ $step['output_var'] ] = $step_result;
				}
			}
		}

		return $parallel_results;
	}

	/**
	 * Execute steps in a conditional branch
	 *
	 * @param array  $steps            Array of step definitions for the branch.
	 * @param array  $workflow_context Workflow context.
	 * @param array  $execution_context Execution context.
	 * @param bool   $dry_run          Dry run mode.
	 * @param string $branch_prefix    Prefix for step numbering (e.g., "1.then").
	 * @return array Branch execution results.
	 */
	private function execute_branch_steps( $steps, $workflow_context, $execution_context, $dry_run, $branch_prefix ) {
		$branch_results = array(
			'completed'    => 0,
			'failed'       => 0,
			'step_results' => array(),
			'context'      => array(),
		);

		// Execute each step in the branch.
		foreach ( $steps as $sub_index => $step ) {
			$step_num = $branch_prefix . '.' . ( $sub_index + 1 );

			if ( $dry_run ) {
				$branch_results['step_results'][] = array(
					'step'    => $step_num,
					'task'    => isset( $step['task'] ) ? $step['task'] : 'branch-step',
					'status'  => 'skipped',
					'message' => __( 'Dry run - branch step not executed', 'nvoos-content-graph-ai-platform' ),
				);
				continue;
			}

			// Execute the step.
			$step_result = $this->execute_step( $step, $workflow_context, $execution_context );

			if ( is_wp_error( $step_result ) ) {
				++$branch_results['failed'];
				$branch_results['step_results'][] = array(
					'step'   => $step_num,
					'task'   => $step['task'],
					'status' => 'failed',
					'error'  => $step_result->get_error_message(),
				);

				// Stop on failure unless continue_on_error is set.
				if ( empty( $step['continue_on_error'] ) ) {
					break;
				}
			} else {
				++$branch_results['completed'];
				$branch_results['step_results'][] = array(
					'step'   => $step_num,
					'task'   => $step['task'],
					'status' => 'completed',
					'result' => $step_result,
				);

				// Update context with step results.
				if ( isset( $step['output_var'] ) && is_array( $step_result ) ) {
					$branch_results['context'][ $step['output_var'] ] = $step_result;
				}
			}
		}

		return $branch_results;
	}

	/**
	 * Execute steps in a loop with exit condition
	 *
	 * Supports repeat_until conditions for autonomous workflows.
	 * Implements intelligent exit detection:
	 * - Exits when condition is met (if specified)
	 * - Exits when max_iterations is reached
	 * - Exits on critical errors (unless continue_on_error is set)
	 *
	 * @param array  $steps            Array of step definitions for the loop.
	 * @param string $exit_condition   Exit condition expression (optional).
	 * @param int    $max_iterations   Maximum loop iterations.
	 * @param array  $workflow_context Workflow context.
	 * @param array  $execution_context Execution context.
	 * @param bool   $dry_run          Dry run mode.
	 * @param int    $base_step_num    Base step number for reporting.
	 * @return array Loop execution results.
	 */
	private function execute_loop_steps( $steps, $exit_condition, $max_iterations, $workflow_context, $execution_context, $dry_run, $base_step_num ) {
		$loop_results = array(
			'completed'    => 0,
			'failed'       => 0,
			'step_results' => array(),
			'context'      => $workflow_context, // Preserve context across iterations.
			'iterations'   => 0,
		);

		// Execute loop iterations.
		for ( $iteration = 1; $iteration <= $max_iterations; $iteration++ ) {
			$loop_results['iterations'] = $iteration;

			// Execute each step in this iteration.
			foreach ( $steps as $sub_index => $step ) {
				$step_num = sprintf( '%d.loop.%d.%d', $base_step_num, $iteration, $sub_index + 1 );

				if ( $dry_run ) {
					$loop_results['step_results'][] = array(
						'step'    => $step_num,
						'task'    => isset( $step['task'] ) ? $step['task'] : 'loop-step',
						'status'  => 'skipped',
						'message' => __( 'Dry run - loop step not executed', 'nvoos-content-graph-ai-platform' ),
					);
					continue;
				}

				// Execute the step.
				$step_result = $this->execute_step( $step, $loop_results['context'], $execution_context );

				if ( is_wp_error( $step_result ) ) {
					++$loop_results['failed'];
					$loop_results['step_results'][] = array(
						'step'   => $step_num,
						'task'   => $step['task'],
						'status' => 'failed',
						'error'  => $step_result->get_error_message(),
					);

					// Stop loop on critical error unless continue_on_error is set.
					if ( empty( $step['continue_on_error'] ) ) {
						return $loop_results;
					}
				} else {
					++$loop_results['completed'];
					$loop_results['step_results'][] = array(
						'step'   => $step_num,
						'task'   => $step['task'],
						'status' => 'completed',
						'result' => $step_result,
					);

					// Update context with step results.
					if ( isset( $step['output_var'] ) && is_array( $step_result ) ) {
						$loop_results['context'][ $step['output_var'] ] = $step_result;
					}
				}
			}

			// Check exit condition after each iteration.
			if ( $exit_condition && $this->evaluate_condition( $exit_condition, $loop_results['context'] ) ) {
				$loop_results['step_results'][] = array(
					'step'    => sprintf( '%d.loop.%d.exit', $base_step_num, $iteration ),
					'task'    => 'exit-condition',
					'status'  => 'completed',
					'message' => sprintf(
						/* translators: 1: exit condition, 2: iteration number */
						__( 'Exit condition met: %1$s (after %2$d iterations)', 'nvoos-content-graph-ai-platform' ),
						$exit_condition,
						$iteration
					),
				);
				break;
			}

			// Check if we've reached max iterations.
			if ( $iteration >= $max_iterations ) {
				$loop_results['step_results'][] = array(
					'step'    => sprintf( '%d.loop.%d.limit', $base_step_num, $iteration ),
					'task'    => 'iteration-limit',
					'status'  => 'completed',
					'message' => sprintf(
						/* translators: %d: max iterations */
						__( 'Maximum iterations reached: %d', 'nvoos-content-graph-ai-platform' ),
						$max_iterations
					),
				);
			}
		}

		return $loop_results;
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
			return new \WP_Error(
				'no_command_handler',
				__( 'Command handler not available.', 'nvoos-content-graph-ai-platform' )
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
			$subject     = isset( $params['subject'] ) ? $params['subject'] : __( 'Workflow Notification', 'nvoos-content-graph-ai-platform' );

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
			'message' => __( 'Unsupported notification channel', 'nvoos-content-graph-ai-platform' ),
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
			'daily-review'        => array(
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
			'publish-ready'       => array(
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
			'site-health'         => array(
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
			'parallel-checks'     => array(
				'name'        => 'Parallel Site Checks',
				'description' => 'Run multiple site checks concurrently for faster execution',
				'steps'       => array(
					array(
						'parallel'          => array(
							array(
								'task'       => 'clean-content',
								'name'       => 'content-check',
								'timeout'    => 30,
								'params'     => array(
									'limit'   => 5,
									'dry-run' => true,
								),
								'output_var' => 'content_result',
							),
							array(
								'task'       => 'optimize-perf',
								'name'       => 'perf-check',
								'timeout'    => 30,
								'params'     => array(
									'phases'  => '1,2',
									'dry-run' => true,
								),
								'output_var' => 'perf_result',
							),
							array(
								'task'       => 'sync-docs',
								'name'       => 'docs-check',
								'timeout'    => 30,
								'params'     => array(
									'type'    => 'posts',
									'dry-run' => true,
								),
								'output_var' => 'docs_result',
							),
						),
						'continue_on_error' => true,
					),
					array(
						'task'   => 'notify_admin',
						'params' => array(
							'subject' => 'Parallel Checks Complete',
							'message' => 'All parallel site checks have completed.',
						),
					),
				),
			),
			'conditional-publish' => array(
				'name'        => 'Conditional Content Publishing',
				'description' => 'Check draft count and conditionally publish or notify admin',
				'steps'       => array(
					array(
						'task'       => 'next-task',
						'params'     => array(
							'type'  => 'drafts',
							'limit' => 10,
						),
						'output_var' => 'draft_check',
					),
					array(
						'condition' => '{{draft_check}} > 3',
						'then'      => array(
							array(
								'task'   => 'ship',
								'params' => array(
									'limit'   => 5,
									'publish' => true,
								),
							),
						),
						'else'      => array(
							array(
								'task'   => 'notify_admin',
								'params' => array(
									'subject' => 'Low Draft Count',
									'message' => 'Only a few drafts are ready. Consider creating more content.',
								),
							),
						),
					),
				),
			),
			'autonomous-audit'    => array(
				'name'        => 'Autonomous Content Audit Loop',
				'description' => 'Continuously audit content until quality score is acceptable',
				'steps'       => array(
					array(
						'repeat_until'   => '{{quality_score}} >= 8',
						'max_iterations' => 5,
						'steps'          => array(
							array(
								'task'       => 'clean-content',
								'params'     => array(
									'limit'   => 3,
									'dry-run' => true,
								),
								'output_var' => 'content_result',
							),
							array(
								'task'       => 'next-task',
								'params'     => array(
									'type'    => 'drafts',
									'limit'   => 3,
									'dry-run' => true,
								),
								'output_var' => 'draft_result',
							),
							array(
								'task'   => 'wait',
								'params' => array( 'seconds' => 2 ),
							),
						),
					),
					array(
						'task'   => 'notify_admin',
						'params' => array(
							'subject' => 'Autonomous Audit Complete',
							'message' => 'Content audit loop has finished with quality score: {{quality_score}}',
						),
					),
				),
			),
			'dependency-workflow' => array(
				'name'        => 'Complex Workflow with Dependencies',
				'description' => 'Demonstrates step dependencies (DAG) with named steps',
				'steps'       => array(
					array(
						'name'   => 'analyze',
						'task'   => 'next-task',
						'params' => array(
							'type'    => 'drafts',
							'limit'   => 5,
							'dry-run' => true,
						),
					),
					array(
						'name'       => 'process_content',
						'task'       => 'clean-content',
						'depends_on' => array( 'analyze' ),
						'params'     => array(
							'limit'   => 5,
							'dry-run' => true,
						),
					),
					array(
						'name'       => 'check_perf',
						'task'       => 'optimize-perf',
						'depends_on' => array( 'analyze' ),
						'params'     => array(
							'phases'  => '1,2',
							'dry-run' => true,
						),
					),
					array(
						'name'       => 'finalize',
						'task'       => 'notify_admin',
						'depends_on' => array( 'process_content', 'check_perf' ),
						'params'     => array(
							'subject' => 'Workflow Complete',
							'message' => 'All tasks completed: content processed and performance checked.',
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

		// Add performance metrics if available.
		if ( isset( $results['metrics'] ) && ! empty( $results['metrics']['total_duration'] ) ) {
			$metrics = $results['metrics'];
			$output .= "**Performance Metrics:**\n";
			$output .= sprintf( "- Total Duration: %ss\n", $metrics['total_duration'] );
			$output .= sprintf( "- Steps Executed: %d\n", $metrics['steps_executed'] );

			if ( $metrics['parallel_blocks'] > 0 ) {
				$output .= sprintf( "- Parallel Blocks: %d\n", $metrics['parallel_blocks'] );
			}

			if ( $metrics['loop_iterations'] > 0 ) {
				$output .= sprintf( "- Loop Iterations: %d\n", $metrics['loop_iterations'] );
			}

			$output .= "\n";
		}

		$output .= "### Step Results\n\n";

		foreach ( $results['step_results'] as $step_result ) {
			$status_icon = array(
				'completed'             => '✅',
				'completed-timeout'     => '⚠️',
				'completed-with-errors' => '⚠️',
				'failed'                => '❌',
				'skipped'               => '⏭️',
			);

			$icon = isset( $status_icon[ $step_result['status'] ] ) ? $status_icon[ $step_result['status'] ] : '❓';

			$output .= sprintf(
				"%s **Step %s:** %s (%s)\n",
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

			if ( isset( $step_result['warning'] ) ) {
				$output .= sprintf( "   - Warning: %s\n", esc_html( $step_result['warning'] ) );
			}

			if ( isset( $step_result['duration'] ) ) {
				$output .= sprintf( "   - Duration: %ss\n", $step_result['duration'] );
			}

			$output .= "\n";
		}

		if ( $dry_run ) {
			$output .= "\n**Note:** This was a dry run. No changes were made.\n";
		}

		// Add Chart.js visualization option if metrics are available.
		if ( isset( $results['metrics'] ) && ! empty( $results['metrics']['total_duration'] ) ) {
			$output .= "\n---\n\n";
			$output .= "**💡 Tip:** Visualize these metrics with interactive Chart.js charts using the `visualize_workflow_metrics` tool.\n\n";
			$output .= "**Example:**\n";
			$output .= "```json\n";
			$output .= "{\n";
			$output .= "  \"tool\": \"visualize_workflow_metrics\",\n";
			$output .= "  \"arguments\": {\n";
			$output .= "    \"workflow_results\": <these_results>,\n";
			$output .= "    \"chart_type\": \"all\",\n";
			$output .= "    \"save_attachment\": true\n";
			$output .= "  }\n";
			$output .= "}\n";
			$output .= "```\n";
		}

		return $output;
	}

	/**
	 * Evaluate conditional expression
	 *
	 * Supports simple comparison operators:
	 * - == (equals)
	 * - != (not equals)
	 * - > (greater than)
	 * - >= (greater than or equal)
	 * - < (less than)
	 * - <= (less than or equal)
	 * - contains (string contains)
	 * - empty (is empty)
	 * - not_empty (is not empty)
	 *
	 * @param string $condition Condition expression (e.g., "{{var}} > 5").
	 * @param array  $context   Workflow context for variable replacement.
	 * @return bool Whether condition evaluates to true.
	 */
	private function evaluate_condition( $condition, $context ) {
		// Replace context variables.
		$condition = $this->replace_context_vars( $condition, $context );

		// Check for empty/not_empty special cases.
		if ( preg_match( '/^\s*empty\s*$/i', $condition ) ) {
			return true; // Always true if literal "empty".
		}
		if ( preg_match( '/^\s*not_empty\s*$/i', $condition ) ) {
			return true; // Always true if literal "not_empty".
		}

		// Parse comparison operators.
		$operators = array(
			'>=',
			'<=',
			'==',
			'!=',
			'>',
			'<',
			'contains',
			'empty',
			'not_empty',
		);

		foreach ( $operators as $operator ) {
			if ( false !== stripos( $condition, $operator ) ) {
				$parts = array_map( 'trim', explode( $operator, $condition, 2 ) );

				if ( count( $parts ) !== 2 ) {
					continue;
				}

				list( $left, $right ) = $parts;

				// Handle special operators.
				switch ( strtolower( $operator ) ) {
					case 'contains':
						return false !== stripos( $left, $right );

					case 'empty':
						return empty( $left );

					case 'not_empty':
						return ! empty( $left );
				}

				// Numeric comparison.
				if ( is_numeric( $left ) && is_numeric( $right ) ) {
					switch ( $operator ) {
						case '>':
							return (float) $left > (float) $right;
						case '>=':
							return (float) $left >= (float) $right;
						case '<':
							return (float) $left < (float) $right;
						case '<=':
							return (float) $left <= (float) $right;
						case '==':
							return (float) $left == (float) $right; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison, Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose float comparison for workflow expressions.
						case '!=':
							return (float) $left != (float) $right; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison, Universal.Operators.StrictComparisons.LooseNotEqual -- Intentional loose float comparison for workflow expressions.
					}
				}

				// String comparison.
				switch ( $operator ) {
					case '==':
						return $left === $right;
					case '!=':
						return $left !== $right;
				}
			}
		}

		// If no operator found, treat as boolean evaluation.
		return ! empty( $condition ) && 'false' !== strtolower( $condition ) && '0' !== $condition;
	}
}
