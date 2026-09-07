<?php
/**
 * DAG builder page (Wave E-UI-2, sub-cluster 4).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_DAG_Builder`
 * (`includes/admin/class-wp-mcp-ai-admin-dag-builder.php`):
 * byte-identical page surface — the `wp-mcp-ai-dag-builder` page
 * slug (priority 25, `manage_options`), the `mcpAiDagBuilder`
 * localized envelope (ajaxUrl/restUrl/wp_rest nonce/workflowId/
 * version), the per-file asset versions, the workflow list sidebar
 * (New Workflow button, version badges, Edit/Run actions, empty
 * state), the canvas root with the resolved workflow id, and the
 * query-string workflow-id resolution with CPT ownership check.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Managers\`).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerManagers()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - The workflow CPT resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the base
 *    `WP_MCP_AI_Workflow_CPT` monolith / the platform's
 *    `Workflows\WorkflowCpt` standalone (byte-identical `CPT` +
 *    `META_VERSION` constants — the E1 port). The base calls the CPT
 *    class unconditionally; the port degrades `workflow_id` to 0 when
 *    no CPT class resolves (additive guard, documented). The base
 *    duplicates the query-string workflow-id resolution in both the
 *    enqueue and render paths; the port deduplicates it into the
 *    `resolve_workflow_id()` helper (additive, documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The page's own assets (admin-dag-builder.css/js) are copied
 *    byte-identically into the platform asset tree; the base's
 *    filemtime-based versioning resolves through the platform's
 *    per-file asset seam.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * DAG Builder admin page controller.
 *
 * @since 2.0.0
 */
class DagBuilder {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-dag-builder';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu_page' ), 25 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Workflow CPT class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function workflow_cpt_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Workflow_CPT' ) ) {
			return 'WP_MCP_AI_Workflow_CPT';
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Workflows\WorkflowCpt' ) ) {
			return 'NvoosContentGraphAiPlatform\Workflows\WorkflowCpt';
		}

		return null;
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-file mtime).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_version( $relative_path ) {
		$absolute_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/' . \ltrim( $relative_path, '/' );

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Add DAG Builder submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Workflow DAG Builder', 'nvoos-content-graph-ai-platform' ),
			__( 'DAG Builder', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Resolve the workflow id from the query string, verifying CPT
	 * ownership (shared by the enqueue and render paths).
	 *
	 * @return int
	 */
	protected function resolve_workflow_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter for page display; value is cast to absint() and ownership is verified via get_post() check below.
		$workflow_id = isset( $_GET['workflow_id'] ) ? \absint( \wp_unslash( $_GET['workflow_id'] ) ) : 0;

		$cpt_class = self::workflow_cpt_class();
		if ( null === $cpt_class ) {
			return 0;
		}

		if ( $workflow_id > 0 ) {
			$wf_post = \get_post( $workflow_id );
			if ( ! $wf_post || $cpt_class::CPT !== $wf_post->post_type ) {
				$workflow_id = 0;
			}
		}

		return $workflow_id;
	}

	/**
	 * Enqueue page-specific assets.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === \strpos( $hook, 'wp-mcp-ai-dag-builder' ) ) {
			return;
		}

		\wp_enqueue_style(
			'wp-mcp-ai-dag-builder',
			self::asset_url( 'css/admin-dag-builder.css' ),
			array(),
			self::asset_version( 'css/admin-dag-builder.css' )
		);

		\wp_enqueue_script(
			'wp-mcp-ai-dag-builder',
			self::asset_url( 'js/admin-dag-builder.js' ),
			array(),
			self::asset_version( 'js/admin-dag-builder.js' ),
			true
		);

		// Resolve workflow_id from query string; verify it belongs to the right CPT.
		$workflow_id = $this->resolve_workflow_id();

		$cpt_class      = self::workflow_cpt_class();
		$version_string = '';
		if ( $workflow_id > 0 && null !== $cpt_class ) {
			$version_string = (string) \get_post_meta( $workflow_id, $cpt_class::META_VERSION, true );
		}

		\wp_localize_script(
			'wp-mcp-ai-dag-builder',
			'mcpAiDagBuilder',
			array(
				'ajaxUrl'    => \esc_url( \admin_url( 'admin-ajax.php' ) ),
				'restUrl'    => \esc_url( \rest_url( 'mcp-ai/v1/orchestration/workflows' ) ),
				'nonce'      => \wp_create_nonce( 'wp_rest' ),
				'workflowId' => $workflow_id,
				'version'    => \esc_html( $version_string ? $version_string : '1.0.0' ),
			)
		);
	}

	/**
	 * Render the DAG Builder admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		$workflow_id = $this->resolve_workflow_id();

		$cpt_class = self::workflow_cpt_class();
		$workflows = null === $cpt_class ? array() : \get_posts(
			array(
				'post_type'      => $cpt_class::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap mcp-ai-dag-wrap">
			<h1><?php \esc_html_e( 'Workflow DAG Builder', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<div class="mcp-ai-dag-layout">
				<!-- Sidebar: workflow list -->
				<aside class="mcp-ai-dag-sidebar">
					<h2><?php \esc_html_e( 'Workflows', 'nvoos-content-graph-ai-platform' ); ?></h2>

					<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=wp-mcp-ai-dag-builder' ) ); ?>"
						class="button button-primary mcp-ai-dag-new-btn">
						<?php \esc_html_e( 'New Workflow', 'nvoos-content-graph-ai-platform' ); ?>
					</a>

					<?php if ( $workflows ) : ?>
					<ul class="mcp-ai-dag-workflow-list">
						<?php foreach ( $workflows as $wf ) : ?>
						<li class="mcp-ai-dag-workflow-item<?php echo ( (int) $wf->ID === $workflow_id ) ? ' is-active' : ''; ?>">
							<strong><?php echo \esc_html( $wf->post_title ); ?></strong>
							<small>v<?php echo \esc_html( \get_post_meta( $wf->ID, $cpt_class::META_VERSION, true ) ? \get_post_meta( $wf->ID, $cpt_class::META_VERSION, true ) : '1.0.0' ); ?></small>
							<div class="mcp-ai-dag-workflow-actions">
								<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=wp-mcp-ai-dag-builder&workflow_id=' . $wf->ID ) ); ?>"
									class="button button-small">
									<?php \esc_html_e( 'Edit', 'nvoos-content-graph-ai-platform' ); ?>
								</a>
								<button type="button"
										class="button button-small mcp-ai-dag-run-btn"
										data-workflow-id="<?php echo \esc_attr( $wf->ID ); ?>">
									<?php \esc_html_e( 'Run', 'nvoos-content-graph-ai-platform' ); ?>
								</button>
							</div>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="mcp-ai-dag-empty"><?php \esc_html_e( 'No workflows yet. Create one!', 'nvoos-content-graph-ai-platform' ); ?></p>
					<?php endif; ?>
				</aside>

				<!-- Main canvas area -->
				<main class="mcp-ai-dag-main">
					<div id="mcp-ai-dag-builder-root"
						data-workflow-id="<?php echo \esc_attr( (string) $workflow_id ); ?>">
						<p class="mcp-ai-dag-loading"><?php \esc_html_e( 'Loading…', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>
				</main>
			</div><!-- .mcp-ai-dag-layout -->
		</div><!-- .wrap -->
		<?php
	}
}
