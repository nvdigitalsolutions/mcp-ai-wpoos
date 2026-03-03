<?php
/**
 * Workflow Editor Admin Page
 *
 * Provides UI for managing and creating workflows.
 *
 * @package WP_MCP_AI
 * @subpackage Admin
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow Editor Admin Page Class
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Workflow_Editor_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-workflow-editor';

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_save_workflow', array( $this, 'ajax_save_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_delete_workflow', array( $this, 'ajax_delete_workflow' ) );
		add_action( 'wp_ajax_wp_mcp_ai_test_workflow', array( $this, 'ajax_test_workflow' ) );
	}

	/**
	 * Register admin page.
	 *
	 * @since 1.3.0
	 */
	public function register_page() {
		add_submenu_page(
			'wp-mcp-ai-settings',
			__( 'Workflow Editor', 'mcp-ai-wpoos' ),
			__( 'Workflows', 'mcp-ai-wpoos' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @since 1.3.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );

		wp_enqueue_style(
			'mcp-ai-workflow-editor',
			WP_MCP_AI_URL . 'assets/css/workflow-editor.css',
			array( 'wp-components' ),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'mcp-ai-workflow-editor',
			WP_MCP_AI_URL . 'assets/js/workflow-editor.js',
			array( 'wp-element', 'wp-components', 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'mcp-ai-workflow-editor',
			'mcpAiWorkflowEditor',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mcp_ai_workflow_editor' ),
				'workflows' => $this->get_all_workflows(),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.3.0
	 */
	public function render_page() {
		?>
		<div class="wrap mcp-ai-workflow-editor">
			<h1><?php esc_html_e( 'Workflow Editor', 'mcp-ai-wpoos' ); ?></h1>
			
			<div class="mcp-ai-workflow-container">
				<div class="mcp-ai-workflow-sidebar">
					<h2><?php esc_html_e( 'Workflows', 'mcp-ai-wpoos' ); ?></h2>
					<button type="button" class="button button-primary" id="mcp-ai-new-workflow">
						<?php esc_html_e( 'New Workflow', 'mcp-ai-wpoos' ); ?>
					</button>
					<div id="mcp-ai-workflow-list">
						<?php $this->render_workflow_list(); ?>
					</div>
				</div>

				<div class="mcp-ai-workflow-editor-main">
					<div id="mcp-ai-workflow-editor-content">
						<div class="mcp-ai-welcome-message">
							<h2><?php esc_html_e( 'Welcome to Workflow Editor', 'mcp-ai-wpoos' ); ?></h2>
							<p><?php esc_html_e( 'Create and manage automated command workflows. Select a workflow from the left or create a new one.', 'mcp-ai-wpoos' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render workflow list.
	 *
	 * @since 1.3.0
	 */
	protected function render_workflow_list() {
		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( ! $orchestrator ) {
			echo '<p>' . esc_html__( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$workflows = $orchestrator->get_workflows();

		if ( empty( $workflows ) ) {
			echo '<p class="mcp-ai-no-workflows">' . esc_html__( 'No workflows yet.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		echo '<ul class="mcp-ai-workflow-items">';
		foreach ( $workflows as $slug => $workflow ) {
			printf(
				'<li class="mcp-ai-workflow-item" data-workflow="%s">
					<div class="workflow-title">%s</div>
					<div class="workflow-meta">%d steps</div>
					<div class="workflow-actions">
						<button class="button workflow-edit" data-workflow="%s">%s</button>
						<button class="button workflow-test" data-workflow="%s">%s</button>
						<button class="button workflow-delete" data-workflow="%s">%s</button>
					</div>
				</li>',
				esc_attr( $slug ),
				esc_html( $workflow['name'] ),
				absint( $workflow['steps'] ),
				esc_attr( $slug ),
				esc_html__( 'Edit', 'mcp-ai-wpoos' ),
				esc_attr( $slug ),
				esc_html__( 'Test', 'mcp-ai-wpoos' ),
				esc_attr( $slug ),
				esc_html__( 'Delete', 'mcp-ai-wpoos' )
			);
		}
		echo '</ul>';
	}

	/**
	 * Get all workflows.
	 *
	 * @since 1.3.0
	 *
	 * @return array Workflows.
	 */
	protected function get_all_workflows() {
		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( ! $orchestrator ) {
			return array();
		}

		$workflows = array();
		foreach ( $orchestrator->get_workflows() as $slug => $workflow ) {
			$full_workflow = $orchestrator->get_workflow( $slug );
			if ( $full_workflow ) {
				$workflows[ $slug ] = $full_workflow;
			}
		}

		return $workflows;
	}

	/**
	 * AJAX handler for saving workflows.
	 *
	 * @since 1.3.0
	 */
	public function ajax_save_workflow() {
		check_ajax_referer( 'mcp_ai_workflow_editor', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
		$steps       = isset( $_POST['steps'] ) ? json_decode( stripslashes( $_POST['steps'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$steps       = is_array( $steps ) ? wp_mcp_ai_sanitize_recursive( $steps ) : array();

		if ( empty( $name ) || empty( $steps ) ) {
			wp_send_json_error( array( 'message' => __( 'Name and steps are required.', 'mcp-ai-wpoos' ) ) );
		}

		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( ! $orchestrator ) {
			wp_send_json_error( array( 'message' => __( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ) ) );
		}

		$definition = array(
			'name'        => $name,
			'description' => $description,
			'steps'       => $steps,
		);

		$result = $orchestrator->create_workflow( $name, $definition );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message'  => __( 'Workflow saved successfully.', 'mcp-ai-wpoos' ),
					'workflow' => array(
						'slug'  => sanitize_key( $name ),
						'name'  => $name,
						'steps' => count( $steps ),
					),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for deleting workflows.
	 *
	 * @since 1.3.0
	 */
	public function ajax_delete_workflow() {
		check_ajax_referer( 'mcp_ai_workflow_editor', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_slug = isset( $_POST['workflow'] ) ? sanitize_key( $_POST['workflow'] ) : '';

		if ( empty( $workflow_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow slug required.', 'mcp-ai-wpoos' ) ) );
		}

		$orchestrator = wp_mcp_ai_get_workflow_orchestrator();
		if ( ! $orchestrator ) {
			wp_send_json_error( array( 'message' => __( 'Workflow orchestrator not available.', 'mcp-ai-wpoos' ) ) );
		}

		$result = $orchestrator->delete_workflow( $workflow_slug );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Workflow deleted successfully.', 'mcp-ai-wpoos' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete workflow.', 'mcp-ai-wpoos' ) ) );
		}
	}

	/**
	 * AJAX handler for testing workflows.
	 *
	 * @since 1.3.0
	 */
	public function ajax_test_workflow() {
		check_ajax_referer( 'mcp_ai_workflow_editor', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		$workflow_slug = isset( $_POST['workflow'] ) ? sanitize_key( $_POST['workflow'] ) : '';
		$params        = isset( $_POST['params'] ) ? json_decode( stripslashes( $_POST['params'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$params        = is_array( $params ) ? wp_mcp_ai_sanitize_recursive( $params ) : array();

		if ( empty( $workflow_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Workflow slug required.', 'mcp-ai-wpoos' ) ) );
		}

		$result = wp_mcp_ai_execute_workflow( $workflow_slug, $params );

		if ( isset( $result['success'] ) && $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Workflow test completed successfully.', 'mcp-ai-wpoos' ),
					'result'  => $result,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => isset( $result['message'] ) ? $result['message'] : __( 'Workflow test failed.', 'mcp-ai-wpoos' ),
					'result'  => $result,
				)
			);
		}
	}
}

// Initialize the workflow editor page.
new WP_MCP_AI_Workflow_Editor_Page();
