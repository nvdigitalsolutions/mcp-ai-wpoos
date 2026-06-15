<?php
/**
 * Admin Page: Workflow DAG Builder.
 *
 * Registers the "Workflow DAG Builder" submenu page and enqueues the
 * canvas-based graph editor assets. Provides a sidebar listing all
 * mcp_ai_workflow posts and a root container for the JS editor.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DAG Builder admin page controller.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Admin_DAG_Builder {

	/**
	 * Admin menu page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor — wires up hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add DAG Builder submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Workflow DAG Builder', 'mcp-ai-wpoos' ),
			__( 'DAG Builder', 'mcp-ai-wpoos' ),
			'manage_options',
			'wp-mcp-ai-dag-builder',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page-specific assets.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'wp-mcp-ai-dag-builder' ) ) {
			return;
		}

		$css_path = WP_MCP_AI_PATH . 'assets/css/admin-dag-builder.css';
		$js_path  = WP_MCP_AI_PATH . 'assets/js/admin-dag-builder.js';
		$version  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WP_MCP_AI_VERSION;

		wp_enqueue_style(
			'wp-mcp-ai-dag-builder',
			WP_MCP_AI_URL . 'assets/css/admin-dag-builder.css',
			array(),
			$version
		);

		$js_version = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WP_MCP_AI_VERSION;

		wp_enqueue_script(
			'wp-mcp-ai-dag-builder',
			WP_MCP_AI_URL . 'assets/js/admin-dag-builder.js',
			array(),
			$js_version,
			true
		);

		// Resolve workflow_id from query string; verify it belongs to the right CPT.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter for page display; value is cast to absint() and ownership is verified via get_post() check below.
		$workflow_id = isset( $_GET['workflow_id'] ) ? absint( wp_unslash( $_GET['workflow_id'] ) ) : 0;
		if ( $workflow_id > 0 ) {
			$wf_post = get_post( $workflow_id );
			if ( ! $wf_post || WP_MCP_AI_Workflow_CPT::CPT !== $wf_post->post_type ) {
				$workflow_id = 0;
			}
		}

		$version_string = '';
		if ( $workflow_id > 0 ) {
			$version_string = (string) get_post_meta( $workflow_id, WP_MCP_AI_Workflow_CPT::META_VERSION, true );
		}

		wp_localize_script(
			'wp-mcp-ai-dag-builder',
			'mcpAiDagBuilder',
			array(
				'ajaxUrl'    => esc_url( admin_url( 'admin-ajax.php' ) ),
				'restUrl'    => esc_url( rest_url( 'mcp-ai/v1/orchestration/workflows' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'workflowId' => $workflow_id,
				'version'    => esc_html( $version_string ? $version_string : '1.0.0' ),
			)
		);
	}

	/**
	 * Render the DAG Builder admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter for page display; value is cast to absint() and ownership is verified via get_post() check below.
		$workflow_id = isset( $_GET['workflow_id'] ) ? absint( wp_unslash( $_GET['workflow_id'] ) ) : 0;
		if ( $workflow_id > 0 ) {
			$wf_check = get_post( $workflow_id );
			if ( ! $wf_check || WP_MCP_AI_Workflow_CPT::CPT !== $wf_check->post_type ) {
				$workflow_id = 0;
			}
		}

		$workflows = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Workflow_CPT::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap mcp-ai-dag-wrap">
			<h1><?php esc_html_e( 'Workflow DAG Builder', 'mcp-ai-wpoos' ); ?></h1>

			<div class="mcp-ai-dag-layout">
				<!-- Sidebar: workflow list -->
				<aside class="mcp-ai-dag-sidebar">
					<h2><?php esc_html_e( 'Workflows', 'mcp-ai-wpoos' ); ?></h2>

					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dag-builder' ) ); ?>"
						class="button button-primary mcp-ai-dag-new-btn">
						<?php esc_html_e( 'New Workflow', 'mcp-ai-wpoos' ); ?>
					</a>

					<?php if ( $workflows ) : ?>
					<ul class="mcp-ai-dag-workflow-list">
						<?php foreach ( $workflows as $wf ) : ?>
						<li class="mcp-ai-dag-workflow-item<?php echo ( $wf->ID === $workflow_id ) ? ' is-active' : ''; ?>">
							<strong><?php echo esc_html( $wf->post_title ); ?></strong>
							<small>v<?php echo esc_html( get_post_meta( $wf->ID, WP_MCP_AI_Workflow_CPT::META_VERSION, true ) ? get_post_meta( $wf->ID, WP_MCP_AI_Workflow_CPT::META_VERSION, true ) : '1.0.0' ); ?></small>
							<div class="mcp-ai-dag-workflow-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dag-builder&workflow_id=' . $wf->ID ) ); ?>"
									class="button button-small">
									<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
								</a>
								<button type="button"
										class="button button-small mcp-ai-dag-run-btn"
										data-workflow-id="<?php echo esc_attr( $wf->ID ); ?>">
									<?php esc_html_e( 'Run', 'mcp-ai-wpoos' ); ?>
								</button>
							</div>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="mcp-ai-dag-empty"><?php esc_html_e( 'No workflows yet. Create one!', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</aside>

				<!-- Main canvas area -->
				<main class="mcp-ai-dag-main">
					<div id="mcp-ai-dag-builder-root"
						data-workflow-id="<?php echo esc_attr( $workflow_id ); ?>">
						<p class="mcp-ai-dag-loading"><?php esc_html_e( 'Loading…', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</main>
			</div><!-- .mcp-ai-dag-layout -->
		</div><!-- .wrap -->
		<?php
	}
}
