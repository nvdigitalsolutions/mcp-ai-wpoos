<?php
/**
 * Pro Workflow Builder Admin Page
 *
 * Advanced visual workflow builder with React-based UI.
 * Implements 2026 industry standards for AI workflow tools.
 *
 * @package WP_MCP_AI
 * @subpackage Admin
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Workflow Builder Admin Page Class
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Pro_Workflow_Builder_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-pro-workflow-builder';

	/**
	 * Actual WordPress hook name returned by add_submenu_page().
	 *
	 * Stored during register_page() so enqueue_assets() can compare against the
	 * real hook (which uses sanitize_title(menu_title) as prefix, not the raw
	 * parent slug).
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Cached templates class instance.
	 *
	 * @var WP_MCP_AI_Pattern_Workflow_Templates|null
	 */
	private $templates_instance = null;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		// Register admin menu with priority 26 to ensure parent menu (nvoos-pro-dashboard at priority 25) exists.
		add_action( 'admin_menu', array( $this, 'register_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_save_pro_workflow', array( $this, 'ajax_save_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_load_pro_workflow', array( $this, 'ajax_load_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_delete_pro_workflow', array( $this, 'ajax_delete_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_workflow_templates', array( $this, 'ajax_get_templates' ) );
		add_action( 'wp_ajax_wp_mcp_ai_execute_workflow_node', array( $this, 'ajax_execute_workflow_node' ) );
		add_action( 'wp_ajax_wp_mcp_ai_save_workflow_execution', array( $this, 'ajax_save_workflow_execution' ) );
		add_action( 'wp_ajax_wp_mcp_ai_list_pro_workflows', array( $this, 'ajax_list_workflows' ) );
		add_action( 'wp_ajax_wp_mcp_ai_export_pro_workflow', array( $this, 'ajax_export_workflow' ) );
	}

	/**
	 * Register admin page.
	 *
	 * @since 2.0.0
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Pro Workflow Builder', 'mcp-ai-wpoos' ),
			__( 'Pro Workflows', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True if debug logging is enabled, false otherwise.
	 */
	private function is_debug_logging_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Use the actual hook stored when add_submenu_page() was called so that
		// we match the real WordPress-generated hook suffix (which is derived from
		// sanitize_title(menu_title), not from the raw parent slug).
		if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
			return;
		}

		// Enqueue the React-based workflow builder.
		$asset_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/workflow-builder.asset.php';
		
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;
			
			// Debug logging.
			if ( $this->is_debug_logging_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
				error_log( sprintf( 'Workflow Builder: Enqueuing built assets from %s', $asset_file ) );
			}
			
			wp_enqueue_script(
				'mcp-ai-pro-workflow-builder',
				WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/workflow-builder.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_enqueue_style(
				'mcp-ai-pro-workflow-builder',
				WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/workflow-builder.css',
				array(),
				$asset['version']
			);

			// Enqueue ReactFlow's CSS (compiled from `import 'reactflow/dist/style.css'`)
			// by wp-scripts into a separate style-*.css file. Without this, the canvas
			// area has no layout and nodes/edges do not render correctly.
			$style_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/style-workflow-builder.css';
			if ( file_exists( $style_file ) ) {
				wp_enqueue_style(
					'mcp-ai-pro-workflow-builder-reactflow',
					WP_MCP_AI_URL . 'addons/pro/build/workflow-builder/style-workflow-builder.css',
					array(),
					$asset['version']
				);
			}
		} else {
			// Fallback for development - load from src.
			// Debug logging.
			if ( $this->is_debug_logging_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
				error_log( 'Workflow Builder: Built assets not found, using development fallback' );
			}
			
			wp_enqueue_script(
				'mcp-ai-pro-workflow-builder',
				WP_MCP_AI_URL . 'src/workflow-builder/index.jsx',
				array( 'wp-element', 'wp-i18n' ),
				WP_MCP_AI_VERSION,
				true
			);
		}

		// Localize script with data.
		wp_localize_script(
			'mcp-ai-pro-workflow-builder',
			'mcpAiWorkflowBuilder',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'mcp_ai_pro_workflow_builder' ),
				'workflows'      => $this->get_all_workflows(),
				'templates'      => $this->get_workflow_templates(),
				'availableTools' => $this->get_available_tools(),
				'assistants'     => $this->get_available_assistants(),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 2.0.0
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pro Workflow Builder', 'mcp-ai-wpoos' ); ?></h1>
			<div id="mcp-ai-pro-workflow-builder-root"></div>
		</div>
		<?php
	}

	/**
	 * Get all workflows.
	 *
	 * @since 2.0.0
	 *
	 * @return array Workflows.
	 */
	protected function get_all_workflows() {
		$workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
		return is_array( $workflows ) ? $workflows : array();
	}

	/**
	 * Get workflow templates.
	 *
	 * @since 2.0.0
	 *
	 * @return array Templates.
	 */
	protected function get_workflow_templates() {
		// Cache the templates class instance to avoid repeated instantiation.
		// Check for both the class and the constants it depends on.
		if ( null === $this->templates_instance && 
			class_exists( 'WP_MCP_AI_Pattern_Workflow_Templates' ) && 
			class_exists( 'WP_MCP_AI_Pattern_Constants' ) ) {
			try {
				$this->templates_instance = new WP_MCP_AI_Pattern_Workflow_Templates();
			} catch ( Exception $e ) {
				// Log error if debugging is enabled.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WP_MCP_AI: Failed to instantiate workflow templates: ' . $e->getMessage() );
				}
				return array();
			}
		}

		if ( ! $this->templates_instance ) {
			return array();
		}

		try {
			return $this->templates_instance->get_all_templates();
		} catch ( Exception $e ) {
			// Log error if debugging is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP_MCP_AI: Failed to get workflow templates: ' . $e->getMessage() );
			}
			return array();
		}
	}

	/**
	 * AJAX handler for saving workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_json = isset( $_POST['workflow'] ) ? wp_unslash( $_POST['workflow'] ) : '';
		
		if ( empty( $workflow_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow data required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow = json_decode( $workflow_json, true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid workflow data.', 'mcp-ai-wpoos' ) ) );
		}

		// Validate workflow structure.
		if ( empty( $workflow['name'] ) || empty( $workflow['nodes'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow must have a name and nodes.', 'mcp-ai-wpoos' ) ) );
		}

		// Sanitize workflow data.
		$workflow['name']        = sanitize_text_field( $workflow['name'] );
		$workflow['description'] = isset( $workflow['description'] ) ? sanitize_textarea_field( $workflow['description'] ) : '';
		
		// Generate workflow ID from name.
		$workflow_id = sanitize_key( $workflow['name'] );

		// Get existing workflows.
		$workflows = $this->get_all_workflows();

		// Add/update workflow.
		$workflows[ $workflow_id ] = array(
			'id'          => $workflow_id,
			'name'        => $workflow['name'],
			'description' => $workflow['description'],
			'nodes'       => $workflow['nodes'],
			'edges'       => $workflow['edges'],
			'created_at'  => isset( $workflows[ $workflow_id ]['created_at'] ) ? $workflows[ $workflow_id ]['created_at'] : time(),
			'updated_at'  => time(),
		);

		// Save workflows.
		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		if ( $result ) {
			wp_send_json_success( array(
				'message'  => __( 'Workflow saved successfully.', 'mcp-ai-wpoos' ),
				'workflow' => $workflows[ $workflow_id ],
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for loading workflow.
	 *
	 * @since 2.0.0
	 */
	public function ajax_load_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		wp_send_json_success( array(
			'workflow' => $workflows[ $workflow_id ],
		) );
	}

	/**
	 * AJAX handler for deleting workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_delete_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		unset( $workflows[ $workflow_id ] );

		$result = update_option( 'wp_mcp_ai_pro_workflows', $workflows );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Workflow deleted successfully.', 'mcp-ai-wpoos' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for getting workflow templates.
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_templates() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$templates = $this->get_workflow_templates();

		wp_send_json_success( array(
			'templates' => $templates,
		) );
	}

	/**
	 * AJAX handler for listing all workflows.
	 *
	 * @since 2.0.0
	 */
	public function ajax_list_workflows() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		wp_send_json_success( array(
			'workflows' => array_values( $workflows ),
		) );
	}

	/**
	 * AJAX handler for exporting a workflow as JSON.
	 *
	 * @since 2.0.0
	 */
	public function ajax_export_workflow() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_id = isset( $_POST['workflow_id'] ) ? sanitize_key( $_POST['workflow_id'] ) : '';

		if ( empty( $workflow_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow not found.', 'mcp-ai-wpoos' ) ) );
		}

		wp_send_json_success( array(
			'workflow' => $workflows[ $workflow_id ],
		) );
	}

	/**
	 * AJAX handler for executing a workflow node.
	 *
	 * Dispatches to the appropriate execution method based on node type.
	 *
	 * @since 2.0.0
	 */
	public function ajax_execute_workflow_node() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$node_type = isset( $_POST['node_type'] ) ? sanitize_key( $_POST['node_type'] ) : '';

		if ( empty( $node_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Node type required.', 'mcp-ai-wpoos' ) ) );
		}

		// Parse the execution context (results from previous nodes).
		$context_json = isset( $_POST['context'] ) ? wp_unslash( $_POST['context'] ) : '{}';
		$context      = json_decode( $context_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$context = array();
		}

		switch ( $node_type ) {
			case 'action':
				$result = $this->execute_action_node( $context );
				break;

			case 'tool':
				$result = $this->execute_tool_node( $context );
				break;

			case 'agent':
				$result = $this->execute_agent_node( $context );
				break;

			default:
				wp_send_json_error( array( 'message' => sprintf( __( 'Unsupported node type: %s', 'mcp-ai-wpoos' ), $node_type ) ) );
				return;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Execute an action (slash command) node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_action_node( $context ) {
		$command = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';
		$params  = isset( $_POST['params'] ) ? wp_unslash( $_POST['params'] ) : '{}';

		if ( empty( $command ) ) {
			return new WP_Error( 'missing_command', __( 'Action node missing command.', 'mcp-ai-wpoos' ) );
		}

		$params_array = json_decode( $params, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$params_array = array();
		}

		// Apply context variable substitution.
		$params_array = $this->apply_context_to_params( $params_array, $context );

		/**
		 * Filter to allow action node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result  Execution result, or null to use default.
		 * @param string              $command Slash command.
		 * @param array               $params  Command parameters.
		 * @param array               $context Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_action', null, $command, $params_array, $context );

		if ( null !== $result ) {
			return $result;
		}

		return array(
			'type'    => 'action',
			'command' => $command,
			'params'  => $params_array,
			'status'  => 'completed',
			'message' => sprintf( __( 'Command "%s" queued for execution.', 'mcp-ai-wpoos' ), $command ),
		);
	}

	/**
	 * Execute a tool node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_tool_node( $context ) {
		$tool_name = isset( $_POST['tool_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tool_name'] ) ) : '';
		$args_json = isset( $_POST['tool_arguments'] ) ? wp_unslash( $_POST['tool_arguments'] ) : '{}';

		if ( empty( $tool_name ) ) {
			return new WP_Error( 'missing_tool', __( 'Tool node missing tool_name.', 'mcp-ai-wpoos' ) );
		}

		$arguments = json_decode( $args_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$arguments = array();
		}

		// Apply context variable substitution.
		$arguments = $this->apply_context_to_params( $arguments, $context );

		// Try to execute via the tool registry.
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $tool_name );

			if ( $tool ) {
				try {
					$tool_result = $tool->execute( $arguments, array( 'context' => $context ) );
					return array(
						'type'      => 'tool',
						'tool_name' => $tool_name,
						'arguments' => $arguments,
						'status'    => 'completed',
						'result'    => $tool_result,
					);
				} catch ( Exception $e ) {
					return new WP_Error( 'tool_execution_failed', $e->getMessage() );
				}
			}
		}

		/**
		 * Filter to allow tool node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result    Execution result, or null to use default.
		 * @param string              $tool_name Tool name.
		 * @param array               $arguments Tool arguments.
		 * @param array               $context   Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_tool', null, $tool_name, $arguments, $context );

		if ( null !== $result ) {
			return $result;
		}

		return new WP_Error( 'tool_not_found', sprintf( __( 'Tool "%s" not found.', 'mcp-ai-wpoos' ), $tool_name ) );
	}

	/**
	 * Execute an agent node.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Execution context from previous nodes.
	 * @return array|WP_Error Execution result.
	 */
	private function execute_agent_node( $context ) {
		$agent_id = isset( $_POST['agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_id'] ) ) : 'default';
		$prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', __( 'Agent node missing prompt.', 'mcp-ai-wpoos' ) );
		}

		// Substitute context variables in prompt.
		$prompt = $this->apply_context_to_string( $prompt, $context );

		/**
		 * Filter to allow agent node execution via third-party hooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array|WP_Error|null $result   Execution result, or null to use default.
		 * @param string              $agent_id Agent identifier.
		 * @param string              $prompt   Prompt text.
		 * @param array               $context  Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_execute_agent', null, $agent_id, $prompt, $context );

		if ( null !== $result ) {
			return $result;
		}

		return array(
			'type'     => 'agent',
			'agent_id' => $agent_id,
			'prompt'   => $prompt,
			'status'   => 'completed',
			'message'  => __( 'Agent execution queued.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Substitute context variables in a parameter array.
	 *
	 * Replaces {{key}} or {{nodeId.field}} placeholders with values from context.
	 *
	 * @since 2.0.0
	 *
	 * @param array $params  Parameters array to process.
	 * @param array $context Execution context.
	 * @return array Processed parameters.
	 */
	private function apply_context_to_params( $params, $context ) {
		if ( ! is_array( $params ) || empty( $context ) ) {
			return $params;
		}

		array_walk_recursive( $params, function( &$value ) use ( $context ) {
			if ( is_string( $value ) ) {
				$value = $this->apply_context_to_string( $value, $context );
			}
		} );

		return $params;
	}

	/**
	 * Substitute context variables in a string.
	 *
	 * @since 2.0.0
	 *
	 * @param string $text    Text containing placeholders.
	 * @param array  $context Execution context.
	 * @return string Processed text.
	 */
	private function apply_context_to_string( $text, $context ) {
		if ( ! is_string( $text ) || empty( $context ) ) {
			return $text;
		}

		// Replace {{nodeId.field}} and {{key}} patterns.
		$text = preg_replace_callback(
			'/\{\{([^}]+)\}\}/',
			function( $matches ) use ( $context ) {
				$path  = explode( '.', trim( $matches[1] ) );
				$value = $context;
				foreach ( $path as $key ) {
					if ( is_array( $value ) && isset( $value[ $key ] ) ) {
						$value = $value[ $key ];
					} else {
						return $matches[0]; // Return original placeholder if not found.
					}
				}
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			},
			$text
		);

		return $text;
	}

	/**
	 * AJAX handler for saving workflow execution records.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_workflow_execution() {
		check_ajax_referer( 'mcp_ai_pro_workflow_builder', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$execution_json = isset( $_POST['execution'] ) ? wp_unslash( $_POST['execution'] ) : '';

		if ( empty( $execution_json ) ) {
			wp_send_json_error( array( 'message' => __( 'Execution data required.', 'mcp-ai-wpoos' ) ) );
		}

		$execution = json_decode( $execution_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid execution data.', 'mcp-ai-wpoos' ) ) );
		}

		// Sanitize execution record fields.
		$sanitized = array(
			'id'             => sanitize_text_field( $execution['id'] ?? '' ),
			'workflow_id'    => sanitize_key( $execution['workflowId'] ?? '' ),
			'timestamp'      => absint( $execution['timestamp'] ?? time() ),
			'duration'       => absint( $execution['duration'] ?? 0 ),
			'status'         => sanitize_text_field( $execution['status'] ?? 'unknown' ),
			'node_count'     => absint( $execution['nodeCount'] ?? 0 ),
			'completed_nodes' => absint( $execution['completedNodes'] ?? 0 ),
			'failed_nodes'   => absint( $execution['failedNodes'] ?? 0 ),
		);

		if ( empty( $sanitized['workflow_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow ID required.', 'mcp-ai-wpoos' ) ) );
		}

		// Load existing execution logs.
		$log_key  = 'wp_mcp_ai_workflow_executions_' . $sanitized['workflow_id'];
		$log      = get_option( $log_key, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Prepend the new record.
		array_unshift( $log, $sanitized );

		// Keep only the last 100 executions per workflow.
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, 0, 100 );
		}

		update_option( $log_key, $log );

		wp_send_json_success( array( 'message' => __( 'Execution saved.', 'mcp-ai-wpoos' ) ) );
	}

	/**
	 * Get the list of available MCP tools for the workflow builder.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of tools with name and description.
	 */
	protected function get_available_tools() {
		$tools = array();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return $tools;
		}

		try {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$all      = $registry->get_all_tools();

			foreach ( $all as $slug => $tool ) {
				$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();
				$tools[]    = array(
					'name'        => sanitize_text_field( $slug ),
					'label'       => sanitize_text_field( $definition['name'] ?? $slug ),
					'description' => sanitize_text_field( $definition['description'] ?? '' ),
				);
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP_MCP_AI: Failed to get available tools: ' . $e->getMessage() );
			}
		}

		return $tools;
	}

	/**
	 * Get the list of available AI assistants for the workflow builder.
	 *
	 * @since 2.0.0
	 *
	 * @return array List of assistants with id and name.
	 */
	protected function get_available_assistants() {
		$assistants = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $query->posts as $post ) {
			$assistants[] = array(
				'id'   => $post->ID,
				'name' => get_the_title( $post ),
				'slug' => $post->post_name,
			);
		}

		wp_reset_postdata();

		return $assistants;
	}
}

/**
 * Initialize the pro workflow builder page.
 *
 * Instantiated immediately when file is loaded (during plugins_loaded) to ensure
 * the admin_menu hook registration in the constructor happens before WordPress
 * processes the admin_menu action.
 *
 * Correct WordPress Hook Order:
 * 1. plugins_loaded - Pro plugin loads, includes this file, instantiates class
 * 2. admin_menu (priority 25) - Parent menu 'nvoos-pro-dashboard' registers
 * 3. admin_menu (priority 26) - This class registers its submenu page
 * 4. admin_init - Other initialization (too late for menu registration)
 *
 * @since 2.0.0
 */
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
