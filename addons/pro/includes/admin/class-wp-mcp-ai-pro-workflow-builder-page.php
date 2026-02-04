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
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
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
		if ( 'nvoos-pro-dashboard_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue the React-based workflow builder.
		$asset_file = WP_MCP_AI_PATH . 'addons/pro/build/workflow-builder/workflow-builder.asset.php';
		
		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;
			
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
		$templates_class = class_exists( 'WP_MCP_AI_Pattern_Workflow_Templates' ) 
			? new WP_MCP_AI_Pattern_Workflow_Templates() 
			: null;

		if ( ! $templates_class ) {
			return array();
		}

		return $templates_class->get_all_templates();
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

// Initialize the pro workflow builder page if pro version is enabled.
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
	new WP_MCP_AI_Pro_Workflow_Builder_Page();
}
