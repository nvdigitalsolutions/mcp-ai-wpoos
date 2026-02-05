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
	}

	/**
	 * Register admin page.
	 *
	 * @since 2.0.0
	 */
	public function register_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Pro Workflow Builder', 'mcp-ai-wpoos' ),
			__( 'Pro Workflows', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Hook format: nvoos-pro-dashboard_page_{PAGE_SLUG}
		// Also check via $_GET for additional safety (following pattern from Orchestration Dashboard).
		$is_workflow_page = ( 'nvoos-pro-dashboard_page_' . self::PAGE_SLUG === $hook ) ||
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
			( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] );

		// Debug logging for troubleshooting asset enqueue issues.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
			error_log( sprintf( 'Workflow Builder: Hook=%s, GET page=%s, Is workflow page=%s', $hook, isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'not set', $is_workflow_page ? 'YES' : 'NO' ) );
		}

		if ( ! $is_workflow_page ) {
			return;
		}

		// Enqueue the React-based workflow builder.
		$asset_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/workflow-builder.asset.php';
		
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;
			
			// Debug logging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
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
		} else {
			// Fallback for development - load from src.
			// Debug logging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
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
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mcp_ai_pro_workflow_builder' ),
				'workflows' => $this->get_all_workflows(),
				'templates' => $this->get_workflow_templates(),
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
}

/**
 * Initialize the pro workflow builder page after all dependencies are loaded.
 *
 * This function is hooked to 'admin_init' (priority 10) to ensure all required
 * classes (WP_MCP_AI_Pattern_Workflow_Templates, WP_MCP_AI_Pattern_Constants)
 * are loaded before instantiation.
 *
 * The admin_menu hook (priority 26) is registered in the constructor, which will
 * fire properly since WordPress triggers admin_menu after admin_init.
 *
 * WordPress Hook Order:
 * 1. plugins_loaded (priority 15) - Pro plugin loads, includes this file
 * 2. plugins_loaded (priority 20) - Toolkit Enhancement loads Pattern classes
 * 3. admin_init (priority 10) - This function runs, instantiates the class
 * 4. admin_menu (priority 26) - Class registers its menu page
 *
 * @since 2.0.0
 */
function wp_mcp_ai_pro_init_workflow_builder_page() {
	if ( ! is_admin() || ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) ) {
		return;
	}
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
add_action( 'admin_init', 'wp_mcp_ai_pro_init_workflow_builder_page', 10 );
